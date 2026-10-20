<?php

namespace App\Http\Controllers;

use App\Events\LabourStatusChanged;
use App\Http\Resources\LabourListCollection;
use App\Http\Requests\LabourListStatusRequest;
use App\Http\Requests\LabourListStoreRequest;
use App\Http\Requests\LabourListUpdateRequest;
use App\Models\Contact;
use App\Models\LabourList;
use App\Models\LabourListItem;
use App\Models\LabourWorkItem;
use App\Policies\LabourPolicy;
use App\Models\Staff;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\PaymentVoucherNumbers;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class LabourController extends Controller
{
    /** External-personnel labourers are always one of these existing Contact categories — matches App\Models\Contact::CATEGORIES exactly. */
    public const LABOUR_PERSONNEL_CATEGORIES = ['Workers', 'SubContractors', 'Foreman'];

    /**
     * Index-page sortable columns, whitelisted so ?sort= can never be used
     * to order by an arbitrary/non-existent column — same convention as
     * PaymentVoucherController::SORTABLE_COLUMNS. 'reference'/'period_start'/
     * 'total_amount'/'status' are plain labour_lists columns; 'approved_amount'
     * and 'priority' are per-item aggregates with no column of their own on
     * labour_lists, so those two resolve to a correlated subquery instead of
     * a bare column name (see the closures below).
     */
    private const SORTABLE_COLUMNS = ['reference', 'period_start', 'total_amount', 'status', 'approved_amount', 'priority'];

    public function __construct()
    {
        // Every action below has its own explicit authorize() call against
        // LabourPolicy — opting out of the legacy Authorizable auto-gate
        // avoids a second, out-of-sync copy of the same check, exactly like
        // MaterialRequestController.
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', LabourList::class);

        $filters = $request->all('search', 'warehouse_id', 'status', 'date_from', 'date_to', 'priority');

        $query = LabourList::query()->with(['warehouse:id,name', 'createdBy:id,name', 'items:id,labour_list_id,amount,approved_amount,priority']);

        if (\App\Policies\LabourPolicy::isSiteEngineerOnly($user)) {
            $query->where('created_by_user_id', $user->id);
        }

        // Stats are computed from an unfiltered clone BEFORE ->filter()
        // mutates $query — otherwise clicking a stat card (which applies a
        // status filter) would leave the other three cards permanently
        // stuck at 0, since a clone taken afterwards inherits the same
        // status constraint.
        $statsQuery = clone $query;

        $sort = in_array($request->query('sort'), self::SORTABLE_COLUMNS, true) ? $request->query('sort') : null;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'approved_amount') {
            // No column of its own — the true "current" amount per item is
            // approved_amount if Super Admin revised it, else the original
            // amount; summed per list via a correlated subquery so sorting
            // still happens in the DB (paginate() needs the sort applied
            // before slicing, so this can't be done in PHP afterwards).
            $query->orderBy(
                LabourListItem::selectRaw('COALESCE(SUM(COALESCE(approved_amount, amount)), 0)')
                    ->whereColumn('labour_list_id', 'labour_lists.id'),
                $direction
            );
        } elseif ($sort === 'priority') {
            // Same idea, ranked by the WORST (highest-severity) priority
            // among the list's items — matches what the Priority column
            // itself displays (see the transform below).
            $query->orderBy(
                LabourListItem::selectRaw('MAX(' . LabourListItem::priorityRankSql() . ')')
                    ->whereColumn('labour_list_id', 'labour_lists.id'),
                $direction
            );
        } elseif ($sort) {
            $query->orderBy($sort, $direction);
        }

        $labourLists = $query->filter($filters)->orderByDesc('id')->paginate()->withQueryString();

        $labourLists->getCollection()->transform(function (LabourList $labourList) {
            $worstPriority = $labourList->items->isEmpty()
                ? null
                : LabourListItem::priorities()[$labourList->items->max(fn (LabourListItem $i) => LabourListItem::priorityRank($i->priority))];
            $priorityCounts = $labourList->items->countBy('priority');

            return [
                'id' => $labourList->id,
                'reference' => $labourList->reference,
                'warehouse_name' => optional($labourList->warehouse)->name,
                'period_start' => optional($labourList->period_start)->format('Y-m-d'),
                'period_end' => optional($labourList->period_end)->format('Y-m-d'),
                'status' => $labourList->status,
                'payment_status' => $labourList->paymentRollupStatus(),
                'total_amount' => $labourList->total_amount,
                'approved_amount' => in_array($labourList->status, [LabourList::STATUS_APPROVED, LabourList::STATUS_PAYMENT_VOUCHER_CREATED], true)
                    ? (float) $labourList->items->sum(fn (LabourListItem $i) => (float) ($i->approved_amount ?? $i->amount))
                    : null,
                'priority' => $worstPriority,
                'priority_breakdown' => $priorityCounts,
                'created_by_name' => optional($labourList->createdBy)->name,
                'created_at' => optional($labourList->created_at)->format('Y-m-d H:i'),
                'description' => $labourList->description,
                'item_count' => $labourList->items->count(),
            ];
        });

        return Inertia::render('Labour/Index', [
            'filters' => array_merge($filters, ['sort' => $sort, 'direction' => $direction]),
            'statuses' => LabourList::statuses(),
            'priorities' => LabourListItem::priorities(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status']),
            'canCreate' => $user->can('create', LabourList::class),
            'stats' => [
                'awaiting_pm' => (clone $statsQuery)->where('status', LabourList::STATUS_SUBMITTED)->count(),
                'awaiting_super_admin' => (clone $statsQuery)->where('status', LabourList::STATUS_CLEARED)->count(),
                'approved_for_payment' => (clone $statsQuery)->where('status', LabourList::STATUS_APPROVED)->count(),
                'payment_voucher_created' => (clone $statsQuery)->where('status', LabourList::STATUS_PAYMENT_VOUCHER_CREATED)->count(),
            ],
            'labourLists' => new LabourListCollection($labourLists),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', LabourList::class);
        $user = $request->user();
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && ! $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']);

        $warehouses = $isSiteEngineer
            ? $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id', 'warehouses.name', 'warehouses.status'])
            : Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status']);

        return Inertia::render('Labour/Form', [
            'edit' => null,
            'warehouses' => $warehouses,
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::query()->whereIn('category', self::LABOUR_PERSONNEL_CATEGORIES)->orderBy('name')->get(['id', 'name', 'category']),
            'workItems' => $this->workItemsBank(),
            'priorities' => LabourListItem::priorities(),
            'reference' => $this->nextReference(),
        ]);
    }

    public function store(LabourListStoreRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        $labourList = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $labourList = DB::transaction(function () use ($data, $user) {
                    $list = LabourList::create([
                        'reference' => $this->nextReference(),
                        'warehouse_id' => $data['warehouse_id'],
                        'period_start' => $data['period_start'],
                        'period_end' => $data['period_end'],
                        'status' => LabourList::STATUS_DRAFT,
                        'description' => $data['description'] ?? null,
                        'created_by_user_id' => $user->id,
                    ]);

                    $this->syncItems($list, $data['items']);

                    return $list;
                });

                break;
            } catch (QueryException $e) {
                $isDuplicateReference = (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'labour_lists_reference_unique');
                if (! $isDuplicateReference || $attempt === 2) {
                    throw $e;
                }
            }
        }

        if (! $labourList) {
            abort(500, 'Unable to generate a unique labour list reference.');
        }

        $labourList->saveAttachments($request->file('attachments'), 'labour-list');

        log_activity(__('Labour List created.'), ['reference' => $labourList->reference], $labourList, 'Labour List');

        \App\Services\WorkflowEvent::fire('labour', 'created', $labourList, $user, 'Labour List Created', $labourList->reference . ' was created by ' . $user->name . '.', ['owner_user_id' => (int) $labourList->created_by_user_id, 'url' => route('labour.show', $labourList->id)]);

        return redirect()->route('labour.show', $labourList->id)->with('message', __('Labour List created.'));
    }

    public function show(Request $request, LabourList $labour)
    {
        $this->authorize('view', $labour);

        $labour->load([
            'warehouse:id,name',
            'createdBy:id,name',
            'submittedBy:id,name',
            'clearedBy:id,name',
            'approvedBy:id,name',
            'items.contact:id,name',
            'items.staff:id,name',
            'items.agreement:id,reference,agreed_amount',
            'items.paymentVoucher:id,pv_number,status,paid_at,labour_list_item_id',
            'attachments',
            'batch:id,batch_number',
        ]);

        $user = $request->user();

        return Inertia::render('Labour/Show', [
            'labourList' => $this->presentDetail($labour),
            'can' => [
                'update' => $user->can('update', $labour),
                'delete' => $user->can('delete', $labour),
                'submit' => $user->can('submit', $labour),
                'clear' => $user->can('changeStatus', [$labour, LabourList::STATUS_CLEARED]),
                'approve' => $user->can('changeStatus', [$labour, LabourList::STATUS_APPROVED]),
                'return' => $user->can('changeStatus', [$labour, LabourList::STATUS_RETURNED]),
                'createPaymentVoucher' => $user->can('createPaymentVoucher', $labour),
                'print' => $user->can('print', $labour),
            ],
        ]);
    }

    public function edit(Request $request, LabourList $labour)
    {
        $this->authorize('update', $labour);
        $labour->load(['items', 'attachments']);

        $user = $request->user();
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && ! $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']);

        $warehouses = $isSiteEngineer
            ? $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id', 'warehouses.name', 'warehouses.status'])
            : Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status']);

        if ($labour->warehouse_id && ! $warehouses->contains('id', $labour->warehouse_id)) {
            $warehouses = $warehouses->concat(Warehouse::whereKey($labour->warehouse_id)->get(['id', 'name', 'status']))->unique('id')->sortBy('name')->values();
        }

        return Inertia::render('Labour/Form', [
            'edit' => $this->presentDetail($labour),
            'warehouses' => $warehouses,
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::query()->whereIn('category', self::LABOUR_PERSONNEL_CATEGORIES)->orderBy('name')->get(['id', 'name', 'category']),
            'workItems' => $this->workItemsBank(),
            'priorities' => LabourListItem::priorities(),
            'reference' => $labour->reference,
        ]);
    }

    public function update(LabourListUpdateRequest $request, LabourList $labour)
    {
        $data = $request->validated();
        $wasReturned = $labour->status === LabourList::STATUS_RETURNED;

        DB::transaction(function () use ($data, $labour, $wasReturned) {
            $labour->update([
                'warehouse_id' => $data['warehouse_id'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'description' => $data['description'] ?? null,
                'status' => $wasReturned ? LabourList::STATUS_DRAFT : $labour->status,
            ]);

            $this->syncItems($labour, $data['items']);
        });

        $labour->saveAttachments($request->file('attachments'), 'labour-list');

        log_activity(__('Labour List updated.'), ['reference' => $labour->reference], $labour, 'Labour List');

        \App\Services\WorkflowEvent::fire('labour', 'updated', $labour->fresh(), $request->user(), 'Labour List Edited', $labour->reference . ' was edited by ' . $request->user()->name . '.', ['owner_user_id' => (int) $labour->created_by_user_id, 'url' => route('labour.show', $labour->id)]);

        return redirect()->route('labour.show', $labour->id)->with('message', __('Labour List updated.'));
    }

    public function destroy(Request $request, LabourList $labour)
    {
        $this->authorize('delete', $labour);

        $reference = $labour->reference;
        $labour->delete();

        log_activity(__('Labour List deleted.'), ['reference' => $reference], $labour, 'Labour List');

        \App\Services\WorkflowEvent::fire('labour', 'deleted', $labour, $request->user(), 'Labour List Deleted', $reference . ' was deleted by ' . $request->user()->name . '.', ['owner_user_id' => (int) $labour->created_by_user_id, 'url' => route('labour.index')]);

        return redirect()->route('labour.index')->with('message', __('Labour List deleted.'));
    }

    public function submit(Request $request, LabourList $labour)
    {
        $this->authorize('submit', $labour);
        $user = $request->user();

        $labour->update([
            'status' => LabourList::STATUS_SUBMITTED,
            'submitted_by_user_id' => $user->id,
            'submitted_at' => now(),
            'review_remarks' => null,
        ]);

        log_activity(__('Labour List submitted for PM review.'), ['reference' => $labour->reference], $labour, 'Labour List');
        event(new LabourStatusChanged($labour->fresh(), (string) $user->name));

        return back()->with('message', __('Labour List submitted for review.'));
    }

    public function updateStatus(LabourListStatusRequest $request, LabourList $labour)
    {
        $targetStatus = $request->input('status');
        $this->authorize('changeStatus', [$labour, $targetStatus]);
        $data = $request->validated();
        $user = $request->user();

        // One transaction, so the Signatories finalisation guard below can undo the amount revisions and the approval together.
        DB::transaction(function () use ($labour, $targetStatus, $data, $user) {
            if ($targetStatus === LabourList::STATUS_APPROVED && ! empty($data['items'])) {
                $this->applyAmountRevisions($labour, $data['items'], $user);
            }

            $update = ['status' => $targetStatus, 'review_remarks' => $data['remarks'] ?? null];

            if ($targetStatus === LabourList::STATUS_CLEARED) {
                $update['cleared_by_user_id'] = $user->id;
                $update['cleared_at'] = now();
            }

            if ($targetStatus === LabourList::STATUS_APPROVED) {
                $update['approved_by_user_id'] = $user->id;
                $update['approved_at'] = now();
            }

            $labour->update($update);

            // Signatories finalisation guard: approval is the final step; a required signature set to block it stops the approval.
            if ($targetStatus === LabourList::STATUS_APPROVED) {
                $blockers = app(\App\Services\Signatories\SignatureResolver::class)->blockers('labour_list', $labour->fresh());
                abort_if($blockers !== [], 422, 'This labour list cannot be approved yet. Missing signatures: ' . collect($blockers)->map(fn ($b) => $b['role_label'] . ' (' . $b['detail'] . ')')->implode('; '));
            }
        });

        log_activity(__('Labour List status changed to :status.', ['status' => $targetStatus]), ['reference' => $labour->reference, 'remarks' => $data['remarks'] ?? null], $labour, 'Labour List');
        event(new LabourStatusChanged($labour->fresh(), (string) $user->name));

        return back()->with('message', __('Labour List status updated.'));
    }

    /**
     * Super Admin revising a Site Engineer's requested amount before
     * approval — never overwrites `amount` (the original request), only
     * ever sets `approved_amount` alongside it, and fully audits the change.
     * A revised amount is capped at its agreement's live outstanding
     * balance, with the same Super Admin/Admin override latitude every other
     * Labour check already gives them.
     */
    protected function applyAmountRevisions(LabourList $labour, array $itemRevisions, User $user): void
    {
        $items = $labour->items()->whereIn('id', collect($itemRevisions)->pluck('id'))->get()->keyBy('id');

        DB::transaction(function () use ($itemRevisions, $items, $user) {
            foreach ($itemRevisions as $revision) {
                if (! isset($revision['approved_amount'])) {
                    continue;
                }

                $item = $items->get($revision['id']);
                if (! $item) {
                    continue;
                }

                $newAmount = (float) $revision['approved_amount'];
                if ($newAmount == (float) $item->amount) {
                    continue; // no actual revision — leave approved_amount null
                }

                if ($item->workforce_agreement_id) {
                    $agreement = \App\Models\WorkforceAgreement::whereKey($item->workforce_agreement_id)->lockForUpdate()->first();
                    if ($agreement && ! $user->hasRole(['Super Admin', 'Admin'])) {
                        // This list is already in the approval pipeline, so its own line is part of
                        // committedUnpaidAmount(); add it back so the cap is "available to this line",
                        // not just "outstanding" (which ignores other in-flight lists on the same agreement).
                        $capForThisLine = $agreement->availableBalance() + (float) ($item->approved_amount ?? $item->amount);
                        abort_if($newAmount > $capForThisLine, 422, "Approved amount for {$item->payee_name} exceeds the agreement's outstanding balance.");
                    }
                }

                $before = (float) $item->amount;
                $item->update(['approved_amount' => $newAmount, 'revision_reason' => $revision['revision_reason'] ?? null]);

                log_activity(
                    __('Labour amount revised for :name.', ['name' => $item->payee_name]),
                    ['requested_amount' => $before, 'approved_amount' => $newAmount, 'reason' => $revision['revision_reason'] ?? null],
                    $item,
                    'Labour Amount Revision'
                );
            }
        });
    }

    /**
     * The one place Labour and Payment Voucher actually meet. Deliberately
     * NOT reusing PaymentVoucherBatchController::store()'s validation path —
     * that path expects a human-typed rows[] array and would let a client
     * payload override amounts. Everything here is built from already-
     * validated, server-persisted LabourList/LabourListItem records only.
     */
    public function createPaymentVoucher(Request $request, LabourList $labour)
    {
        $this->authorize('createPaymentVoucher', $labour);
        $user = $request->user();

        // numbering lock first, then the transaction (see PaymentVoucherNumbers)
        $created = PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($labour, $user) {
            // Re-fetch with a row lock inside the transaction — closes the
            // double-click/concurrent-request race that would otherwise let
            // two simultaneous requests both pass the authorize() check
            // above and each create their own batch+vouchers.
            $locked = LabourList::whereKey($labour->id)->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === LabourList::STATUS_APPROVED, 422, 'Labour List is not approved for payment.');
            abort_if(! empty($locked->payment_voucher_batch_id), 422, 'A Payment Voucher has already been created from this Labour List.');

            $items = $locked->items()->get();
            abort_if($items->isEmpty(), 422, 'This Labour List has no labourer lines to pay.');

            $periodLabel = optional($locked->period_start)->format('d M Y') . ' – ' . optional($locked->period_end)->format('d M Y');

            $batch = PaymentVoucherNumbers::createBatch([
                'type' => 'Labour Payment',
                'payee_type' => \App\Models\PaymentVoucher::PAYEE_TYPE_EXTERNAL,
                'warehouse_id' => $locked->warehouse_id,
                'voucher_date' => now()->toDateString(),
                'common_particulars' => "Labour payment - {$periodLabel}",
                'reference' => $locked->reference,
                'common_notes' => "Generated from Labour List {$locked->reference}.",
                'created_by_user_id' => $user->id,
                'labour_list_id' => $locked->id,
            ]);

            foreach ($items as $item) {
                // The definitive figure once Super Admin has (optionally)
                // revised it during approval — never the raw requested
                // `amount` alone. See LabourListItem::finalAmount().
                $finalAmount = round($item->finalAmount(), 2);

                $voucher = PaymentVoucherNumbers::createVoucher([
                    'payment_voucher_batch_id' => $batch->id,
                    'type' => 'Labour Payment',
                    'payee_type' => $item->payee_type,
                    'payee_contact_id' => $item->contact_id,
                    'staff_id' => $item->staff_id,
                    'payee_name' => $item->payee_name,
                    'warehouse_id' => $locked->warehouse_id,
                    'voucher_date' => now()->toDateString(),
                    'total_amount' => $finalAmount,
                    'amount_in_words' => \App\Support\NumberToWords::convert($finalAmount),
                    'status' => \App\Models\PaymentVoucher::STATUS_DRAFT,
                    'prepared_by_user_id' => $user->id,
                    'labour_list_id' => $locked->id,
                    'labour_list_item_id' => $item->id,
                ]);

                // Trade (a skill descriptor) used to prefix this text
                // naturally ("Masonry — Foundation work"); Priority doesn't
                // read the same way in a payment voucher's particulars line,
                // so it's left out of this string rather than prefixed.
                $particulars = trim($item->work_description ?: 'Labour');
                $voucher->items()->create([
                    'line_number' => 1,
                    'particulars' => \App\Models\PaymentVoucher::particularsText($particulars),
                    'amount' => $finalAmount,
                ]);

                log_activity(__('Payment Voucher created from Labour List.'), ['pv_number' => $voucher->pv_number, 'labour_reference' => $locked->reference], $voucher, 'Payment Voucher');
            }

            $locked->update([
                'status' => LabourList::STATUS_PAYMENT_VOUCHER_CREATED,
                'payment_voucher_batch_id' => $batch->id,
            ]);

            return $batch;
        }));

        log_activity(__('Payment Voucher batch created from Labour List.'), ['reference' => $labour->reference, 'batch_number' => $created->batch_number], $labour->fresh(), 'Labour List');
        event(new LabourStatusChanged($labour->fresh(), (string) $user->name));

        return redirect()->route('payment-vouchers.batches.show', $created->id)->with('message', __('Payment Voucher(s) created from this Labour List.'));
    }

    public function printList(Request $request)
    {
        $this->authorize('viewAny', LabourList::class);

        return view('labour-lists-list-print', $this->listPrintPayload($request, false));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('viewAny', LabourList::class);
        $pdf = Pdf::loadView('labour-lists-list-print', $this->listPrintPayload($request, true));

        return $pdf->download('labour-lists-' . now()->format('Y-m-d') . '.pdf');
    }

    protected function listPrintPayload(Request $request, bool $isPdf): array
    {
        $user = $request->user();
        $filters = $request->all('search', 'warehouse_id', 'status', 'date_from', 'date_to', 'priority');

        $query = LabourList::query()->with(['warehouse:id,name', 'createdBy:id,name']);
        if (LabourPolicy::isSiteEngineerOnly($user)) {
            $query->where('created_by_user_id', $user->id);
        }

        return [
            'labourLists' => $query->filter($filters)->orderByDesc('id')->limit(2000)->get(),
            'isPdf' => $isPdf,
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $user->name,
        ];
    }

    public function print(Request $request, LabourList $labour)
    {
        $this->authorize('print', $labour);

        return view('labour-list-print', $this->printPayload($labour, false));
    }

    public function pdf(Request $request, LabourList $labour)
    {
        $this->authorize('print', $labour);
        $pdf = Pdf::loadView('labour-list-print', $this->printPayload($labour, true));

        return $pdf->download('labour-list-' . $labour->reference . '.pdf');
    }

    protected function printPayload(LabourList $labour, bool $isPdf): array
    {
        $labour->load(['warehouse:id,name,address', 'createdBy:id,name', 'submittedBy:id,name', 'clearedBy:id,name', 'approvedBy:id,name', 'items.contact:id,name', 'items.staff:id,name']);

        return [
            'labourList' => $this->presentDetail($labour),
            'qr' => \App\Support\PrintQrCode::dataUri(route('labour.show', $labour->id)),
            'isPdf' => $isPdf,
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => auth()->user()->name,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('labour_list', $labour),
        ];
    }

    /** Server-recomputes every item's amount and the header total — never trusts a client-submitted amount, mirroring PaymentVoucherController's own rule. */
    protected function syncItems(LabourList $labour, array $items): void
    {
        $labour->items()->delete();
        $total = 0;

        foreach ($items as $item) {
            $days = isset($item['days']) && $item['days'] !== '' ? (float) $item['days'] : null;
            $hours = isset($item['hours']) && $item['hours'] !== '' ? (float) $item['hours'] : null;
            $rate = (float) $item['rate'];
            $amount = LabourListItem::computeAmount($item['rate_type'], $rate, $days, $hours);

            $payeeName = $item['payee_type'] === LabourListItem::PAYEE_TYPE_STAFF
                ? optional(Staff::find($item['staff_id']))->name
                : optional(Contact::find($item['contact_id'] ?? null))->name;

            $this->rememberWorkItem($item['work_description'] ?? '');

            $labour->items()->create([
                'payee_type' => $item['payee_type'],
                'contact_id' => $item['contact_id'] ?? null,
                'staff_id' => $item['payee_type'] === LabourListItem::PAYEE_TYPE_STAFF ? $item['staff_id'] : null,
                'payee_name' => $payeeName ?: '—',
                'workforce_agreement_id' => $item['workforce_agreement_id'] ?? null,
                'priority' => $item['priority'] ?? LabourListItem::PRIORITY_NORMAL,
                'work_description' => $item['work_description'] ?? null,
                'days' => $days,
                'hours' => $hours,
                'rate_type' => $item['rate_type'],
                'rate' => $rate,
                'amount' => $amount,
            ]);

            $total += $amount;
        }

        $labour->update(['total_amount' => round($total, 2)]);
    }

    /** Same growable-bank pattern as PaymentVoucherController::rememberType()/typesBank(). */
    protected function rememberWorkItem(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $record = LabourWorkItem::firstOrCreate(['text' => $text]);
        if (! $record->wasRecentlyCreated) {
            $record->increment('use_count');
        }
    }

    protected function workItemsBank(): array
    {
        return LabourWorkItem::orderByDesc('use_count')->orderBy('id')->limit(200)->pluck('text')->values()->all();
    }

    protected function presentDetail(LabourList $labour): array
    {
        return [
            'id' => $labour->id,
            'reference' => $labour->reference,
            'warehouse_id' => $labour->warehouse_id,
            'warehouse_name' => optional($labour->warehouse)->name,
            'period_start' => optional($labour->period_start)->format('Y-m-d'),
            'period_end' => optional($labour->period_end)->format('Y-m-d'),
            'status' => $labour->status,
            'payment_status' => $labour->paymentRollupStatus(),
            'description' => $labour->description,
            'total_amount' => $labour->total_amount,
            'created_by_name' => optional($labour->createdBy)->name,
            'submitted_by_name' => optional($labour->submittedBy)->name,
            'submitted_at' => optional($labour->submitted_at)->format('Y-m-d H:i'),
            'cleared_by_name' => optional($labour->clearedBy)->name,
            'cleared_at' => optional($labour->cleared_at)->format('Y-m-d H:i'),
            'approved_by_name' => optional($labour->approvedBy)->name,
            'approved_at' => optional($labour->approved_at)->format('Y-m-d H:i'),
            'review_remarks' => $labour->review_remarks,
            'batch_id' => $labour->payment_voucher_batch_id,
            'batch_number' => optional($labour->batch)->batch_number,
            'items' => $labour->items->map(fn (LabourListItem $item) => [
                'id' => $item->id,
                'payee_type' => $item->payee_type,
                'contact_id' => $item->contact_id,
                'staff_id' => $item->staff_id,
                'payee_name' => $item->payee_type === LabourListItem::PAYEE_TYPE_STAFF ? optional($item->staff)->name : ($item->payee_name ?: optional($item->contact)->name),
                'priority' => $item->priority,
                'work_description' => $item->work_description,
                'days' => $item->days,
                'hours' => $item->hours,
                'rate_type' => $item->rate_type,
                'rate' => $item->rate,
                'amount' => $item->amount,
                'workforce_agreement_id' => $item->workforce_agreement_id,
                'agreement_reference' => optional($item->agreement)->reference,
                'agreement_outstanding_balance' => $item->agreement ? $item->agreement->outstandingBalance() : null,
                'approved_amount' => $item->approved_amount !== null ? (float) $item->approved_amount : null,
                'revision_reason' => $item->revision_reason,
                'final_amount' => $item->finalAmount(),
                'payment_voucher' => $item->relationLoaded('paymentVoucher') && $item->paymentVoucher ? [
                    'id' => $item->paymentVoucher->id,
                    'pv_number' => $item->paymentVoucher->pv_number,
                    'status' => $item->paymentVoucher->status,
                    'paid' => ! is_null($item->paymentVoucher->paid_at),
                ] : null,
            ]),
            'attachments' => $labour->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->title ?: $a->filename, 'download_url' => route('labour.attachments.download', [$labour->id, $a->id])]),
        ];
    }

    protected function nextReference(): string
    {
        $year = now()->format('Y');
        $prefixLength = strlen("LBR-{$year}-") + 1;
        $maxSuffix = (int) DB::table('labour_lists')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', "LBR-{$year}-%")
            ->value('max_suffix');

        return "LBR-{$year}-" . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    public function downloadAttachment(Request $request, LabourList $labour, Attachment $attachment)
    {
        $this->authorize('view', $labour);
        abort_unless((int) $attachment->model_id === (int) $labour->id && $attachment->model_type === LabourList::class, 404);

        return \Illuminate\Support\Facades\Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }
}
