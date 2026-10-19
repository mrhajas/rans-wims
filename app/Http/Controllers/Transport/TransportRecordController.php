<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarkTransportRecordPaidRequest;
use App\Http\Requests\TransportRecordRequest;
use App\Http\Resources\TransportRecordCollection;
use App\Http\Resources\TransportRecordResource;
use App\Models\Asset;
use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\TransportLocation;
use App\Models\TransportRecord;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\AppNotification;
use App\Support\NumberToWords;
use App\Support\PaymentVoucherNumbers;
use App\Traits\HasActivityHistory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;
use Tecdiary\Laravel\Attachments\Attachment;

class TransportRecordController extends Controller
{
    use HasActivityHistory;

    private const VOUCHER_TYPE = 'Rans Transportation';

    /**
     * The base Authorizable::$abilities map auto-checks edit/update/destroy
     * against a permission-only ability, which pre-empts authorizeMutation()'s
     * owner-or-permission logic below — a trip's own creator would get a
     * 403 before that method ever runs if they lack the broader
     * update/delete-transport-records permission. Drop just those three
     * keys so authorizeMutation() actually governs them as intended —
     * identical fix to SuperAdjustmentController's own constructor.
     */
    public function __construct()
    {
        $this->setAbilities([
            'index' => 'read',
            'show' => 'read',
            'create' => 'create',
            'store' => 'create',
        ]);
    }

    // Trip #/Date/Amount/Payment Status/Verified sort on their own
    // transport_records column directly; Vehicle/Driver need a join since
    // the table only stores asset_id/driver_contact_id — sorting by those
    // FK ids would order by insertion order, not by what's actually shown
    // in the column. Destination isn't sortable: destination_label is a
    // free-form snapshot, not a single canonical column across the five
    // possible destination kinds.
    private const SORTABLE_COLUMNS = [
        'trip_number' => 'transport_records.trip_number',
        'trip_date' => 'transport_records.trip_date',
        'vehicle' => 'assets.registration_number',
        'driver' => 'contacts.name',
        'amount' => 'transport_records.amount',
        'payment_status' => 'transport_records.payment_status',
        'verified' => 'transport_records.verified_at',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-transport-records'), 403);

        $filters = $request->only('search', 'asset_id', 'driver_contact_id', 'warehouse_id', 'destination_type', 'payment_status', 'date_from', 'date_to');
        $sortKey = $request->query('sort');
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $query = TransportRecord::query()
            ->select('transport_records.*')
            ->with(['asset:id,name,registration_number', 'driver:id,name,phone', 'warehouse:id,name', 'paymentVoucher:id,pv_number,status', 'transfer:id,reference,deleted_at'])
            ->filter($filters);

        if (isset(self::SORTABLE_COLUMNS[$sortKey])) {
            if ($sortKey === 'vehicle') {
                $query->leftJoin('assets', 'assets.id', '=', 'transport_records.asset_id');
            } elseif ($sortKey === 'driver') {
                $query->leftJoin('contacts', 'contacts.id', '=', 'transport_records.driver_contact_id');
            }
            $query->orderBy(self::SORTABLE_COLUMNS[$sortKey], $direction)->orderByDesc('transport_records.id');
        } else {
            $query->orderByDesc('transport_records.trip_date')->orderByDesc('transport_records.id');
        }

        $records = $query->paginate(20)->withQueryString();

        return Inertia::render('TransportManagement/Records/Index', [
            'filters' => array_merge($filters, ['sort' => $sortKey, 'direction' => $direction]),
            'records' => new TransportRecordCollection($records),
            'destinationTypes' => TransportRecord::DESTINATION_TYPES,
            'paymentStatuses' => TransportRecord::PAYMENT_STATUSES,
            'vehicles' => $this->vehicleOptions(),
            'drivers' => $this->driverOptions(),
            'canCreate' => $request->user()->can('create-transport-records'),
            'canCreatePaymentVoucher' => $request->user()->can('create-payment-voucher-from-transport'),
            'canBulkVerify' => $request->user()->can('verify-transport-records'),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('create-transport-records'), 403);

        return Inertia::render('TransportManagement/Records/Form', [
            ...$this->formOptions(),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(TransportRecordRequest $request)
    {
        abort_unless($request->user()->can('create-transport-records'), 403);

        $record = $this->persist(new TransportRecord(), $request);

        $this->notifyTransportEvent('transport-trip-created', __('Trip Logged'), __(':trip logged for :destination.', ['trip' => $record->trip_number, 'destination' => $record->destination_label]), route('transport-records.show', $record->id), $record);

        return redirect()->route('transport-records.show', $record->id)->with('message', __('Transport Record created.'));
    }

    public function show(Request $request, TransportRecord $transport_record)
    {
        abort_unless($request->user()->can('read-transport-records'), 403);

        $transport_record->load([
            'asset:id,name,registration_number,manufacturer,model',
            'driver:id,name,phone',
            'warehouse:id,name',
            'departureWarehouse:id,name',
            'departureContact:id,name,phone,category',
            'departureTransportLocation:id,name,location_type',
            'destinationContact:id,name,phone,category',
            'transportLocation:id,name,location_type',
            'paymentVoucher:id,pv_number,status',
            'transfer:id,reference,deleted_at',
            'creator:id,name',
            'paidBy:id,name',
            'amountChangedBy:id,name',
            'verifiedBy:id,name',
            'attachments',
        ]);
        $transport_record->activity = $this->activityHistory(TransportRecord::class, $transport_record->id);

        return Inertia::render('TransportManagement/Records/Show', [
            'record' => new TransportRecordResource($transport_record),
        ]);
    }

    public function edit(Request $request, TransportRecord $transport_record)
    {
        $this->authorizeMutation($request, $transport_record, 'update-transport-records');
        $transport_record->load('attachments');

        return Inertia::render('TransportManagement/Records/Form', [
            ...$this->formOptions(),
            'record' => new TransportRecordResource($transport_record),
        ]);
    }

    public function update(TransportRecordRequest $request, TransportRecord $transport_record)
    {
        $this->authorizeMutation($request, $transport_record, 'update-transport-records');

        // Locked and re-checked inside a transaction — without this, a
        // createPaymentVoucher()/cancel() committing in the gap between the
        // guards below and the write could leave a trip that's both edited
        // here AND linked to a voucher (or cancelled), the exact race
        // markPaid()'s own lockForUpdate() comment describes.
        $record = DB::transaction(function () use ($request, $transport_record) {
            $locked = TransportRecord::whereKey($transport_record->id)->lockForUpdate()->firstOrFail();

            abort_if($locked->payment_status !== TransportRecord::STATUS_UNPAID, 422, __('A trip that has moved past Unpaid cannot be edited here, a Partially Paid or Paid trip (with no linked voucher) can still be corrected, but only via the password-confirmed correction flow.'));
            // payment_status only moves off Unpaid once the voucher itself
            // leaves Draft (UpdateTransportRecordsFromVoucher) — while a voucher
            // is still Draft, the check above alone lets a trip linked to it be
            // edited, silently orphaning payment_voucher_items.amount /
            // payment_vouchers.total_amount / amount_in_words from the trip's
            // new amount, or leaving the voucher's particulars line describing
            // the old destination.
            abort_if($locked->payment_voucher_id, 422, __('This trip is linked to a Payment Voucher and cannot be edited directly.'));

            return $this->persist($locked, $request);
        });

        $this->notifyTransportEvent('transport-trip-updated', __('Trip Edited'), __(':trip was edited by :user.', ['trip' => $record->trip_number, 'user' => $request->user()->name]), route('transport-records.show', $record->id), $record);

        return redirect()->route('transport-records.show', $record->id)->with('message', __('Transport Record updated.'));
    }

    /**
     * Corrects a trip that already has money against it (Partially Paid or
     * Paid) — update() above refuses that outright, so this is the only
     * way in, and the route additionally requires a fresh password
     * confirmation (Fortify's password.confirm middleware, see routes/
     * web.php) on top of the ordinary update-transport-records permission,
     * mirroring TransportVehicleController::updateOpeningBalance()'s exact
     * pattern for the same reason: this can retroactively change a
     * financial record. A voucher-linked trip is still excluded even
     * here — its payment already followed the voucher's own amount
     * through Payment Voucher's approval chain, so correcting the trip
     * afterward wouldn't undo or match what was actually disbursed; that
     * trip's amount can only be corrected before a voucher exists for it,
     * back on the ordinary Unpaid/update() path.
     */
    public function updatePaid(TransportRecordRequest $request, TransportRecord $transport_record)
    {
        $this->authorizeMutation($request, $transport_record, 'update-transport-records');

        $record = DB::transaction(function () use ($request, $transport_record) {
            $locked = TransportRecord::whereKey($transport_record->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                in_array($locked->payment_status, [TransportRecord::STATUS_PARTIALLY_PAID, TransportRecord::STATUS_PAID], true),
                422,
                __('Only a Partially Paid or Paid trip can be corrected here, an Unpaid trip should be edited normally.')
            );
            abort_if($locked->payment_voucher_id, 422, __('This trip was paid through a Payment Voucher and cannot be edited, its amount already went through that voucher\'s own approval chain.'));

            $statusBefore = $locked->payment_status;
            $amountBefore = (float) $locked->amount;

            $record = $this->persist($locked, $request);

            log_activity(__('Trip amount corrected after payment (password-confirmed).'), [
                'trip_number' => $record->trip_number,
                'amount_before' => $amountBefore,
                'amount_after' => (float) $record->amount,
                'payment_status_before' => $statusBefore,
                'payment_status_after' => $record->payment_status,
            ], $record, 'Transport Record');

            return $record;
        });

        $this->notifyTransportEvent('transport-trip-updated', __('Trip Edited'), __(':trip was edited by :user.', ['trip' => $record->trip_number, 'user' => $request->user()->name]), route('transport-records.show', $record->id), $record);

        return redirect()->route('transport-records.show', $record->id)->with('message', __('Transport Record updated.'));
    }

    public function destroy(Request $request, TransportRecord $transport_record)
    {
        $this->authorizeMutation($request, $transport_record, 'delete-transport-records');

        $tripNumber = $transport_record->trip_number;
        $deletedTrip = clone $transport_record;

        DB::transaction(function () use ($request, $transport_record) {
            $locked = TransportRecord::whereKey($transport_record->id)->lockForUpdate()->firstOrFail();

            abort_if($locked->payment_status !== TransportRecord::STATUS_UNPAID, 422, __('A trip that has moved past Unpaid cannot be deleted.'));
            abort_if($locked->payment_voucher_id, 422, __('This trip is linked to a Payment Voucher and cannot be deleted, it would leave the voucher pointing at a missing trip.'));

            $locked->deleted_by = $request->user()->id;
            $locked->save();
            $locked->delete();

            log_activity(__('Transport Record deleted.'), ['trip_number' => $locked->trip_number], $locked, 'Transport Record');
        });

        $this->notifyTransportEvent('transport-trip-deleted', __('Trip Deleted'), __(':trip was deleted by :user.', ['trip' => $tripNumber, 'user' => $request->user()->name]), route('transport-records.index'), $deletedTrip);

        return redirect()->route('transport-records.index')->with('message', __('Transport Record deleted.'));
    }

    /**
     * Voids a trip without deleting its record — unlike destroy(), this
     * keeps the row (and its full history) for audit purposes, just marks
     * it excluded from outstanding/report totals. Only reachable from
     * Unpaid with no linked voucher — once money has moved (Paid/Partially
     * Paid) or a voucher already exists, the trip must be resolved through
     * that path instead of silently voided.
     */
    public function cancel(Request $request, TransportRecord $transport_record)
    {
        $this->authorizeMutation($request, $transport_record, 'delete-transport-records');

        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $reason = $data['reason'] ?? null;

        DB::transaction(function () use ($transport_record, $reason) {
            $locked = TransportRecord::whereKey($transport_record->id)->lockForUpdate()->firstOrFail();

            abort_if($locked->payment_voucher_id, 422, __('This trip is linked to a Payment Voucher and cannot be cancelled directly.'));
            abort_if($locked->payment_status !== TransportRecord::STATUS_UNPAID, 422, __('Only an Unpaid trip can be cancelled.'));

            $locked->update([
                'payment_status' => TransportRecord::STATUS_CANCELLED,
                'notes' => trim(($locked->notes ? $locked->notes . "\n\n" : '') . __('Cancelled') . ($reason ? ': ' . $reason : '.')),
            ]);

            log_activity(__('Transport Record cancelled.'), ['trip_number' => $locked->trip_number, 'reason' => $reason], $locked, 'Transport Record');
        });

        $this->notifyTransportEvent('transport-trip-cancelled', __('Trip Cancelled'), __(':trip was cancelled.', ['trip' => $transport_record->trip_number]), route('transport-records.show', $transport_record->id), $transport_record);

        return back()->with('message', __('Transport Record cancelled.'));
    }

    /**
     * Verification is a precondition, not a formality: an unverified trip
     * can't be marked paid or added to a Payment Voucher (see markPaid()
     * and createPaymentVoucher() below), and TransportDashboardController /
     * TransportReportController both exclude unverified trips from every
     * money total — exactly the same exclusion already applied to
     * Cancelled trips. Gated by 'verify-transport-records', a permission
     * granted to Super Admin only by default but assignable to any other
     * role through the ordinary Roles & Permissions screen — never
     * hardcoded to a role check.
     */
    public function verify(Request $request, TransportRecord $transport_record)
    {
        abort_unless($request->user()->can('verify-transport-records'), 403);
        abort_if($transport_record->verified_at, 422, __('This trip has already been verified.'));
        abort_if($transport_record->payment_status === TransportRecord::STATUS_CANCELLED, 422, __('A cancelled trip cannot be verified.'));
        // Signatories: refuse when an administrator made a signature required and it is missing (inert until switched on).
        if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('transport_record', $transport_record, ['verified_at' => now(), 'verified_by_user_id' => $request->user()->id], __('verified'))) {
            return back()->with('error', $refusal);
        }

        $transport_record->update([
            'verified_at' => now(),
            'verified_by_user_id' => $request->user()->id,
        ]);

        log_activity(__('Transport Record verified.'), ['trip_number' => $transport_record->trip_number], $transport_record, 'Transport Record');

        $this->notifyTransportEvent('transport-trip-verified', __('Trip Verified'), __(':trip was verified and is now eligible for payment.', ['trip' => $transport_record->trip_number]), route('transport-records.show', $transport_record->id), $transport_record);

        return back()->with('message', __('Transport Record verified.'));
    }

    /**
     * Verifies several trips in one action — gated by the same
     * 'verify-transport-records' permission as the single verify() above
     * (never hardcoded to a role), plus a re-entered password on top,
     * since bulk-approving payment-eligibility for many trips at once is a
     * more consequential action than one at a time. Uses Laravel's
     * built-in current_password rule, the same convention already used for
     * every other password-gated action in this app (see BackupController).
     */
    public function bulkVerify(Request $request)
    {
        abort_unless($request->user()->can('verify-transport-records'), 403);

        $data = $request->validate([
            'transport_record_ids' => 'required|array|min:1',
            'transport_record_ids.*' => 'required|integer|exists:transport_records,id',
            'password' => 'required|current_password',
        ]);

        $user = $request->user();
        $verifiedCount = 0;

        DB::transaction(function () use ($data, $user, &$verifiedCount) {
            $trips = TransportRecord::whereIn('id', $data['transport_record_ids'])->lockForUpdate()->get();
            abort_if($trips->count() !== count(array_unique($data['transport_record_ids'])), 422, __('One or more selected trips could not be found.'));

            foreach ($trips as $trip) {
                abort_if($trip->verified_at, 422, __('Trip :trip has already been verified.', ['trip' => $trip->trip_number]));
                abort_if($trip->payment_status === TransportRecord::STATUS_CANCELLED, 422, __('Trip :trip is cancelled and cannot be verified.', ['trip' => $trip->trip_number]));
                // Signatories: the same guard as the single verify (inert until an administrator makes a signature required and blocking).
                if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('transport_record', $trip, ['verified_at' => now(), 'verified_by_user_id' => $user->id], __('verified'))) {
                    abort(422, $trip->trip_number . ': ' . $refusal);
                }
            }

            foreach ($trips as $trip) {
                $trip->update(['verified_at' => now(), 'verified_by_user_id' => $user->id]);
                log_activity(__('Transport Record verified.'), ['trip_number' => $trip->trip_number, 'bulk' => true], $trip, 'Transport Record');
                $verifiedCount++;
            }
        });

        $this->notifyTransportEvent('transport-trip-verified', __('Trips Verified'), __(':count trip(s) were verified in bulk and are now eligible for payment.', ['count' => $verifiedCount]), route('transport-records.index'));

        return back()->with('message', __(':count trip(s) verified.', ['count' => $verifiedCount]));
    }

    /** Shared create/update persistence — computes destination_label server-side and enforces the amount-change audit trail. */
    private function persist(TransportRecord $record, TransportRecordRequest $request): TransportRecord
    {
        $data = $request->validated();
        $user = $request->user();
        $isNew = ! $record->exists;

        $defaultAmount = $isNew ? (float) transport_settings()['transport_default_price'] : (float) $record->default_amount;
        // "amount" in the form is the price of ONE trip; the record keeps the TOTAL in `amount` (so balances, vouchers and
        // reports are unchanged) and the unit price + number of trips beside it.
        $submittedAmount = (float) $data['amount'];
        $tripsCount = (int) ($data['trips_count'] ?? ($isNew ? 1 : ($record->trips_count ?: 1)));
        $totalAmount = round($submittedAmount * $tripsCount, 2);
        $previousUnit = $isNew ? 0.0 : $record->unit_price;
        // Whether the record should END this save flagged as overridden —
        // independent of whether THIS save is what introduced the override,
        // so reverting back to the default is its own distinct outcome
        // rather than falling out of the same flag (see below).
        $isOverriddenNow = abs($submittedAmount - $defaultAmount) > 0.001;

        // A reason is only required when this save newly introduces or
        // changes an override — not when re-saving an already-overridden
        // amount unchanged, and not when reverting back to the default
        // (there's nothing to justify there).
        $requiresNewReason = $isOverriddenNow && ($isNew || abs($submittedAmount - $previousUnit) > 0.001);

        if ($requiresNewReason && empty($data['amount_change_reason'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount_change_reason' => __('A reason is required when changing the amount from the default.'),
            ]);
        }

        if (! $isNew && $totalAmount < (float) $record->paid_amount - 0.001) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'trips_count' => __('The total (:total) cannot be less than what is already paid (:paid).', ['total' => number_format($totalAmount, 2), 'paid' => number_format((float) $record->paid_amount, 2)]),
            ]);
        }

        if (! $isNew && abs($submittedAmount - $previousUnit) > 0.001) {
            abort_unless($user->can('override-transport-amount'), 403, __('You are not permitted to change the transport amount.'));
        }
        if ($isNew && $isOverriddenNow) {
            abort_unless($user->can('override-transport-amount'), 403, __('You are not permitted to set an amount other than the default.'));
        }

        $destinationLabel = $this->resolveDestinationLabel($data);
        $departureType = $data['departure_type'] ?? null;
        $departureLabel = $this->resolveDepartureLabel($data);
        // Captured before fill() overwrites it — the audit log's "from"
        // value must be what the trip actually cost before THIS save, not
        // the immutable default_amount snapshot (a trip edited
        // 75,000 -> 90,000 -> 110,000 was logging the second change as
        // "from 75000 to 110000" instead of "from 90000 to 110000").
        $previousAmount = $previousUnit;

        $record->fill([
            'trip_date' => $data['trip_date'],
            'trip_time' => $data['trip_time'] ?? null,
            'asset_id' => $data['asset_id'],
            'driver_contact_id' => $data['driver_contact_id'] ?? null,
            'departure_type' => $departureType,
            'departure_warehouse_id' => in_array($departureType, TransportRecord::WAREHOUSE_DESTINATION_TYPES, true) ? $data['departure_warehouse_id'] : null,
            'departure_warehouse_ids' => $departureType === TransportRecord::DESTINATION_MIXED ? array_values(array_unique(array_map('intval', $data['departure_warehouse_ids'] ?? []))) : null,
            'departure_contact_id' => in_array($departureType, TransportRecord::CONTACT_DESTINATION_TYPES, true) ? $data['departure_contact_id'] : null,
            'departure_transport_location_id' => in_array($departureType, TransportRecord::LOCATION_DESTINATION_TYPES, true) ? $data['departure_transport_location_id'] : null,
            'departure_label' => $departureLabel,
            'destination_type' => $data['destination_type'],
            'warehouse_id' => in_array($data['destination_type'], TransportRecord::WAREHOUSE_DESTINATION_TYPES, true) ? $data['warehouse_id'] : null,
            'destination_warehouse_ids' => $data['destination_type'] === TransportRecord::DESTINATION_MIXED ? array_values(array_unique(array_map('intval', $data['destination_warehouse_ids'] ?? []))) : null,
            'destination_contact_id' => in_array($data['destination_type'], TransportRecord::CONTACT_DESTINATION_TYPES, true) ? $data['destination_contact_id'] : null,
            'transport_location_id' => in_array($data['destination_type'], TransportRecord::LOCATION_DESTINATION_TYPES, true) ? $data['transport_location_id'] : null,
            'destination_label' => $destinationLabel,
            'departure_location' => $data['departure_location'] ?? null,
            'destination_location' => $data['destination_location'] ?? null,
            'delivery_note_ref' => $data['delivery_note_ref'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'is_campus' => (bool) ($data['is_campus'] ?? false),
            'trips_count' => $tripsCount,
            'unit_amount' => $submittedAmount,
            'amount' => $totalAmount,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($isNew) {
            $record->trip_number = $this->nextTripNumber();
            $record->default_amount = $defaultAmount;
            $record->payment_status = TransportRecord::STATUS_UNPAID;
            $record->created_by_user_id = $user->id;
        }

        if ($isOverriddenNow) {
            $record->amount_overridden = true;
            if ($requiresNewReason) {
                $record->amount_change_reason = $data['amount_change_reason'];
                $record->amount_changed_by_user_id = $user->id;
                $record->amount_changed_at = now();
            }
            // else: resubmitting an already-overridden amount unchanged — leave the existing reason/metadata as-is.
        } elseif ($record->amount_overridden) {
            // Reverted back to the default amount — clear the stale override flag and reason rather than leaving
            // them stuck forever (a plain `if ($amountChanged)` above used to never run this branch at all).
            $record->amount_overridden = false;
            $record->amount_change_reason = null;
            $record->amount_changed_by_user_id = null;
            $record->amount_changed_at = null;
        }

        // Only reachable via updatePaid() — update() above refuses any
        // record that isn't Unpaid before persist() is ever called. Paid/
        // Partially Paid must be recomputed from paid_amount against the
        // JUST-CHANGED amount (never left at its old value) so, for
        // example, raising a fully Paid trip's amount correctly drops it
        // back to Partially Paid instead of silently staying "Paid" while
        // outstanding_amount is now > 0.
        if (! $isNew && $record->payment_status !== TransportRecord::STATUS_UNPAID) {
            $record->payment_status = $record->recomputedPaymentStatus();
        }

        $record->save();

        if ($request->hasFile('attachments')) {
            $record->saveAttachments($request->file('attachments'));
        }

        log_activity(
            $isNew ? __('Transport Record created.') : __('Transport Record updated.'),
            ['trip_number' => $record->trip_number, 'amount' => $record->amount, 'trips' => $record->trips_count, 'destination' => $destinationLabel],
            $record,
            'Transport Record'
        );

        if ($requiresNewReason && ! $isNew) {
            log_activity(__('Transport Record amount changed.'), [
                'trip_number' => $record->trip_number,
                'from' => $previousAmount,
                'to' => $submittedAmount,
                'reason' => $data['amount_change_reason'],
            ], $record, 'Transport Record');
        } elseif (! $isOverriddenNow && $record->wasChanged('amount_overridden') && ! $isNew) {
            log_activity(__('Transport Record amount reverted to default.'), [
                'trip_number' => $record->trip_number,
                'amount' => $submittedAmount,
            ], $record, 'Transport Record');
        }

        return $record->refresh();
    }

    private function resolveDestinationLabel(array $data): ?string
    {
        return match (true) {
            $data['destination_type'] === TransportRecord::DESTINATION_MIXED =>
                $this->mixedLabel($data['destination_warehouse_ids'] ?? []),
            in_array($data['destination_type'], TransportRecord::WAREHOUSE_DESTINATION_TYPES, true) =>
                optional(Warehouse::find($data['warehouse_id'] ?? null))->name,
            in_array($data['destination_type'], TransportRecord::CONTACT_DESTINATION_TYPES, true) =>
                optional(Contact::find($data['destination_contact_id'] ?? null))->name,
            default => optional(TransportLocation::find($data['transport_location_id'] ?? null))->name,
        };
    }

    /** "Main A, Main B, Main C" in the order the warehouses were chosen. */
    private function mixedLabel(array $ids): ?string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $names = Warehouse::whereIn('id', $ids)->pluck('name', 'id');

        return collect($ids)->map(fn ($id) => $names[$id] ?? null)->filter()->implode(', ') ?: null;
    }

    private function resolveDepartureLabel(array $data): ?string
    {
        $type = $data['departure_type'] ?? null;

        return match (true) {
            ! $type => null,
            $type === TransportRecord::DESTINATION_MIXED =>
                $this->mixedLabel($data['departure_warehouse_ids'] ?? []),
            in_array($type, TransportRecord::WAREHOUSE_DESTINATION_TYPES, true) =>
                optional(Warehouse::find($data['departure_warehouse_id'] ?? null))->name,
            in_array($type, TransportRecord::CONTACT_DESTINATION_TYPES, true) =>
                optional(Contact::find($data['departure_contact_id'] ?? null))->name,
            default => optional(TransportLocation::find($data['departure_transport_location_id'] ?? null))->name,
        };
    }

    private function authorizeMutation(Request $request, TransportRecord $record, string $permission): void
    {
        $user = $request->user();
        $isOwner = (int) $record->created_by_user_id === (int) $user->id;
        abort_unless($isOwner || $user->can($permission), 403);
    }

    /**
     * Records payment directly, without a Payment Voucher — for small
     * ad-hoc trips paid in cash. Blocked once a voucher is linked (that
     * path settles payment instead, via
     * PaymentVoucherController::recordPayment()'s Transport sync block) so
     * the two payment paths can never fight over the same trip.
     */
    public function markPaid(MarkTransportRecordPaidRequest $request, TransportRecord $transport_record)
    {
        abort_unless($request->user()->can('mark-paid-transport-records'), 403);
        abort_if($transport_record->payment_voucher_id, 422, __('This trip is linked to a Payment Voucher, record payment there instead.'));
        abort_if($transport_record->payment_status === TransportRecord::STATUS_CANCELLED, 422, __('A cancelled trip cannot be marked as paid.'));
        abort_if(! $transport_record->verified_at, 422, __('This trip must be verified before it can be marked paid.'));

        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $transport_record) {
            $locked = TransportRecord::whereKey($transport_record->id)->lockForUpdate()->firstOrFail();

            // Re-checked against the freshly-locked row, not the one read
            // before this transaction opened — cancel() has no lock of its
            // own, but its plain update() still blocks on this row's lock
            // once held, so re-reading here (rather than trusting the
            // pre-transaction $transport_record) is what actually closes
            // the race: a cancel() that commits in the gap between the
            // guards above and this lockForUpdate() is caught here instead
            // of silently recording a payment against a cancelled trip.
            abort_if($locked->payment_voucher_id, 422, __('This trip is linked to a Payment Voucher, record payment there instead.'));
            abort_if($locked->payment_status === TransportRecord::STATUS_CANCELLED, 422, __('A cancelled trip cannot be marked as paid.'));

            $newPaidAmount = (float) $locked->paid_amount + (float) $data['paid_amount'];
            abort_if($newPaidAmount > (float) $locked->amount + 0.001, 422, __('Paid amount cannot exceed the trip amount.'));
            // Signatories: the payment that completes the trip is its last step, so a required and blocking signature must exist first.
            if ($newPaidAmount >= (float) $locked->amount - 0.001 && ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('transport_record', $locked, [
                'payment_status' => TransportRecord::STATUS_PAID, 'paid_amount' => $newPaidAmount, 'payment_date' => $data['payment_date'], 'paid_by_user_id' => $request->user()->id, 'paid_at_hint' => now(),
            ], __('marked as paid')))) {
                abort(422, $refusal);
            }

            $locked->update([
                'paid_amount' => $newPaidAmount,
                'payment_date' => $data['payment_date'],
                'paid_by_user_id' => $request->user()->id,
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_notes' => $data['payment_notes'] ?? null,
                'payment_status' => $newPaidAmount >= (float) $locked->amount - 0.001
                    ? TransportRecord::STATUS_PAID
                    : TransportRecord::STATUS_PARTIALLY_PAID,
            ]);

            log_activity(__('Transport Record payment recorded.'), [
                'trip_number' => $locked->trip_number,
                'paid_amount' => $data['paid_amount'],
                'payment_reference' => $data['payment_reference'] ?? null,
            ], $locked, 'Transport Record');
        });

        $this->notifyTransportEvent('transport-trip-marked-paid', __('Payment Recorded'), __('A payment of :amount was recorded for :trip.', ['amount' => number_format((float) $data['paid_amount'], 2), 'trip' => $transport_record->trip_number]), route('transport-records.show', $transport_record->id), $transport_record);

        return back()->with('message', __('Payment recorded.'));
    }

    /**
     * Combines multiple unpaid trips into one Payment Voucher — modeled
     * directly on LabourController::createPaymentVoucher() (row-lock +
     * duplicate-voucher guard inside the transaction, server-computed
     * amounts only, log_activity per trip). Unlike Labour's one-voucher-
     * per-line pattern, this creates a single voucher covering every
     * selected trip (payment_voucher_items.transport_record_id per line),
     * since a voucher has exactly one payee — every selected trip must
     * share the same driver.
     */
    public function createPaymentVoucher(Request $request)
    {
        abort_unless($request->user()->can('create-payment-voucher-from-transport'), 403);

        $data = $request->validate([
            'transport_record_ids' => 'required|array|min:1',
            'transport_record_ids.*' => 'required|integer|exists:transport_records,id',
        ]);
        $user = $request->user();

        // numbering lock first, then the transaction (see PaymentVoucherNumbers)
        $voucher = PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($data, $user) {
            $trips = TransportRecord::whereIn('id', $data['transport_record_ids'])->lockForUpdate()->get();
            abort_if($trips->count() !== count(array_unique($data['transport_record_ids'])), 422, __('One or more selected trips could not be found.'));

            foreach ($trips as $trip) {
                abort_unless($user->can('createPaymentVoucher', $trip), 403, __('Trip :trip already has a Payment Voucher, or is cancelled.', ['trip' => $trip->trip_number]));
                abort_unless($trip->verified_at, 422, __('Trip :trip must be verified before it can be added to a Payment Voucher.', ['trip' => $trip->trip_number]));
            }

            $driverIds = $trips->pluck('driver_contact_id')->unique();
            abort_if($driverIds->count() !== 1 || $driverIds->first() === null, 422, __('All selected trips must share the same driver to be combined into one Payment Voucher.'));
            $driver = Contact::findOrFail($driverIds->first());

            $warehouseIds = $trips->pluck('warehouse_id')->unique();
            $sharedWarehouseId = $warehouseIds->count() === 1 ? $warehouseIds->first() : null;

            // Outstanding, not the trip's full amount — a trip only reaches
            // here with payment_voucher_id already null and not Cancelled
            // (TransportRecordPolicy::createPaymentVoucher()), which does
            // NOT require payment_status === Unpaid, so a trip that already
            // carries a partial payment (e.g. its previous voucher was
            // deleted after a partial payment was recorded against it) must
            // only be re-billed for what's actually still owed, never its
            // full original amount again.
            $totalAmount = round((float) $trips->sum(fn (TransportRecord $trip) => $trip->outstanding_amount), 2);

            $voucher = PaymentVoucherNumbers::createVoucher([
                // Its own distinct type — not the general 'Transport / Fuel'
                // type used by manually-created vouchers elsewhere in the
                // app (PaymentVoucherController::transportFuelReport()) —
                // so the two stay independently filterable/reportable.
                'type' => self::VOUCHER_TYPE,
                'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL,
                'payee_contact_id' => $driver->id,
                'payee_name' => $driver->name,
                'warehouse_id' => $sharedWarehouseId,
                'voucher_date' => now()->toDateString(),
                'total_amount' => $totalAmount,
                'amount_in_words' => NumberToWords::convert($totalAmount),
                'status' => PaymentVoucher::STATUS_DRAFT,
                'prepared_by_user_id' => $user->id,
                // the trips this voucher pays, kept on the voucher so editing its lines never loses them
                'extra_attributes' => ['transport_record_ids' => $trips->pluck('id')->map(fn ($id) => (int) $id)->values()->all()],
            ]);

            // Registers the type in the same growable bank the standard
            // Payment Voucher form's Type autocomplete reads from
            // (PaymentVoucherController::typesBank()/rememberType()) — this
            // controller creates the voucher directly rather than going
            // through PaymentVoucherController::store(), which is the only
            // place that normally does this.
            \App\Models\PaymentVoucherType::firstOrCreate(['text' => self::VOUCHER_TYPE]);

            foreach ($trips as $index => $trip) {
                $voucher->items()->create([
                    'line_number' => $index + 1,
                    'transport_record_id' => $trip->id,
                    'particulars' => PaymentVoucher::particularsText('Trip ' . $trip->trip_number . ' - ' . $trip->destination_label . ($trip->trips_count > 1 ? ' (' . $trip->trips_count . ' trips)' : '')),
                    'amount' => $trip->outstanding_amount,
                ]);

                $trip->update(['payment_voucher_id' => $voucher->id]);

                log_activity(__('Payment Voucher created from Transport Record.'), ['trip_number' => $trip->trip_number, 'pv_number' => $voucher->pv_number], $trip, 'Transport Record');
            }

            log_activity(__('Payment Voucher created from Transport Records.'), ['pv_number' => $voucher->pv_number, 'trip_count' => $trips->count()], $voucher, 'Payment Voucher');

            return $voucher;
        }));

        if ($voucher->prepared_by_user_id) {
            User::find($voucher->prepared_by_user_id)?->notify(new AppNotification([
                'module' => 'transport',
                'type' => 'transport_payment_voucher_created',
                'title' => 'Payment Voucher Created from Transport Records',
                'message' => $voucher->pv_number . ' was created and is awaiting submission.',
                'reference' => $voucher->pv_number,
                'url' => route('payment-vouchers.show', $voucher->id),
                'created_at' => now()->toDateTimeString(),
            ]));
        }

        $this->notifyTransportEvent('transport-payment-voucher-created', __('Payment Voucher Created'), __(':pv was created from :count trip(s).', ['pv' => $voucher->pv_number, 'count' => $voucher->items()->count()]), route('payment-vouchers.show', $voucher->id));

        return redirect()->route('payment-vouchers.show', $voucher->id)->with('message', __('Payment Voucher created from selected trips.'));
    }

    public function print(Request $request, TransportRecord $transport_record)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);

        return view('transport-record-print', $this->printData($transport_record));
    }

    public function pdf(Request $request, TransportRecord $transport_record)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('transport-record-print', $this->printData($transport_record) + ['isPdf' => true])
            ->setPaper('a4')
            ->download('trip-' . $transport_record->trip_number . '.pdf');
    }

    private function printData(TransportRecord $record): array
    {
        $record->load([
            'asset:id,name,registration_number,manufacturer,model,asset_number',
            'driver:id,name,phone',
            'warehouse:id,name',
            'departureWarehouse:id,name',
            'departureContact:id,name,phone,category',
            'departureTransportLocation:id,name,location_type',
            'destinationContact:id,name,phone,category',
            'transportLocation:id,name,location_type',
            'paymentVoucher:id,pv_number,status',
            'creator:id,name',
            'paidBy:id,name',
            'verifiedBy:id,name',
            'transfer:id,reference,deleted_at',
        ]);

        return [
            'record' => $record,
            'isPdf' => false,
            'companyName' => optional(auth()->user())->account->name ?? 'RANS Company Limited',
            'qr' => \App\Support\PrintQrCode::dataUri(route('transport-records.show', $record->id)),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('transport_record', $record),
        ];
    }

    public function printList(Request $request)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);

        return view('reports.transport-pdf', $this->listPayload($request, false));
    }

    public function pdfList(Request $request)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);

        return Pdf::loadView('reports.transport-pdf', $this->listPayload($request, true))
            ->setPaper('a4', 'landscape')
            ->download('transport-records-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->can('export-transport-records'), 403);

        $filters = $request->only('search', 'asset_id', 'driver_contact_id', 'warehouse_id', 'destination_type', 'payment_status', 'date_from', 'date_to');
        $rows = TransportRecord::with(['asset:id,name,registration_number', 'driver:id,name'])
            ->filter($filters)->orderByDesc('trip_date')->limit(10000)->get();

        return (new FastExcel($rows))->download('transport-records-' . now()->format('Ymd-His') . '.xlsx', function (TransportRecord $r) {
            return [
                'Trip Number' => $r->trip_number,
                'Date' => optional($r->trip_date)->format('Y-m-d'),
                'Vehicle' => $r->asset?->registration_number ?? $r->asset?->name,
                'Driver' => $r->driver?->name,
                'Departure Type' => $r->departure_type,
                'Departure' => $r->departure_label,
                'Destination Type' => $r->destination_type,
                'Destination' => $r->destination_label,
                'Trips' => $r->trips_count,
                'Amount per trip' => $r->unit_price,
                'Amount' => $r->amount,
                'Paid' => $r->paid_amount,
                'Outstanding' => $r->outstanding_amount,
                'Payment Status' => $r->payment_status,
            ];
        });
    }

    private function listPayload(Request $request, bool $isPdf): array
    {
        $filters = $request->only('search', 'asset_id', 'driver_contact_id', 'warehouse_id', 'destination_type', 'payment_status', 'date_from', 'date_to');
        $records = TransportRecord::with(['asset:id,name,registration_number', 'driver:id,name'])
            ->filter($filters)->orderByDesc('trip_date')->orderByDesc('id')->limit(2000)->get();

        // Excludes Cancelled AND not-yet-verified trips, matching every other
        // money aggregate in this module (TransportReportController::totals(),
        // TransportDashboardController, TransportVehicleController::show(),
        // TransportTimelyReportController) — this one used to forget the
        // verified_at check, so the Records List PDF total could disagree
        // with the same filter's totals shown everywhere else.
        $totalsQuery = TransportRecord::filter($filters)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->whereNotNull('verified_at');

        return [
            'isPdf' => $isPdf,
            'companyName' => 'RANS Company Limited',
            'reportTypeLabel' => 'Records List',
            'groupColumnLabel' => 'Trip #',
            'filters' => array_merge($filters, ['report_type' => 'records']),
            'rows' => $records,
            'isGrouped' => false,
            'totals' => [
                'total_trips' => (int) (clone $totalsQuery)->sum('trips_count'),
                'total_amount' => (float) (clone $totalsQuery)->sum('amount'),
                'total_paid' => (float) (clone $totalsQuery)->sum('paid_amount'),
                'total_outstanding' => (float) (clone $totalsQuery)->sum(DB::raw('amount - paid_amount')),
            ],
            'generatedBy' => optional($request->user())->name ?? 'N/A',
            'generatedAt' => $generatedAt = now(),
            // Signatories: the person who generates the report signs as Prepared By (nothing is stored; inert unless a document setup exists)
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('transport_report', \App\Services\Signatories\Documents\TransportReportDocument::record($request->user(), $generatedAt)),
            'autoPrint' => ! $isPdf,
        ];
    }

    public function downloadAttachment(Request $request, TransportRecord $transport_record, Attachment $attachment)
    {
        abort_unless($request->user()->can('read-transport-records'), 403);
        abort_unless((int) $attachment->model_id === (int) $transport_record->id && $attachment->model_type === TransportRecord::class, 404);

        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function destroyAttachment(Request $request, TransportRecord $transport_record, Attachment $attachment)
    {
        $this->authorizeMutation($request, $transport_record, 'update-transport-records');
        abort_unless((int) $attachment->model_id === (int) $transport_record->id && $attachment->model_type === TransportRecord::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity(__('Transport Record attachment removed.'), ['trip_number' => $transport_record->trip_number, 'title' => $attachment->title], $transport_record, 'Transport Record');

        return back()->with('message', __('Attachment removed.'));
    }

    private function formOptions(): array
    {
        return [
            'vehicles' => $this->vehicleOptions(),
            'drivers' => $this->driverOptions(),
            'projectWarehouses' => Warehouse::ofAccount()->active()->visible()->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])->orderBy('name')->get(['id', 'name']),
            'mainWarehouses' => Warehouse::ofAccount()->active()->mainWarehouses()->orderBy('name')->get(['id', 'name']),
            'suppliers' => Contact::where('category', 'Suppliers')->orderBy('name')->get(['id', 'name', 'phone']),
            'clients' => Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name', 'phone']),
            'transportLocationsByType' => TransportLocation::active()->orderBy('name')->get(['id', 'name', 'location_type'])->groupBy('location_type'),
            'destinationTypes' => TransportRecord::DESTINATION_TYPES,
            'defaultAmount' => (float) transport_settings()['transport_default_price'],
            'amountChangeReasonSuggestions' => $this->amountChangeReasonSuggestions(),
        ];
    }

    private const AMOUNT_CHANGE_REASON_STARTERS = [
        'Extra distance beyond the standard route',
        'Heavier load required a larger vehicle',
        'Urgent / after-hours trip surcharge',
        'Return trip combined with the outbound leg',
        'Fuel price adjustment',
        'Toll or road fees included',
        'Waiting time charges',
        'Bad road conditions / detour required',
        'Multiple stops on this trip',
        'Client-negotiated rate',
    ];

    /**
     * Curated starters merged with every distinct reason already typed on a
     * real trip — same self-expanding-catalog pattern as the Vehicle
     * Register's Make/Model/Color fields, so a reason someone types once is
     * offered to the next person instead of being re-typed from scratch.
     */
    private function amountChangeReasonSuggestions(): array
    {
        $existing = TransportRecord::whereNotNull('amount_change_reason')->distinct()->pluck('amount_change_reason');

        return collect(self::AMOUNT_CHANGE_REASON_STARTERS)->merge($existing)->unique()->values()->all();
    }

    /**
     * Hands the event to the Transport Routes Notification page (/transport/workflow): that page decides who is told (bell, SMS, email)
     * and with which words (see App\Services\TransportNotifier).
     */
    private function notifyTransportEvent(string $functionKey, string $title, string $message, string $url, ?TransportRecord $record = null): void
    {
        \App\Services\TransportNotifier::notify($functionKey, $title, $message, $url, $record, $record?->created_by_user_id, $record ? array_filter([
            __('Trip') => $record->trip_number, __('From') => $record->departure_label, __('To') => $record->destination_label,
            __('Amount') => number_format((float) $record->amount, 2),
        ]) : []);
    }

    private function vehicleOptions(): array
    {
        // operating_personnel_contact_id rides along so Records/Form.vue can
        // default a new trip's driver to the vehicle's own assigned driver
        // the moment a vehicle is picked — the user can still change it per
        // trip, this only sets the starting point.
        return Asset::vehicles()->orderBy('name')->get(['id', 'name', 'registration_number', 'operating_personnel_contact_id'])->values()->all();
    }

    private function driverOptions(): array
    {
        return Contact::where('category', 'Drivers')->orderBy('name')->get(['id', 'name', 'phone'])->values()->all();
    }

    private function nextTripNumber(): string
    {
        return TransportRecord::nextTripNumber();
    }
}
