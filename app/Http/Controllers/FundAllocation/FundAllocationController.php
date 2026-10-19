<?php

namespace App\Http\Controllers\FundAllocation;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\FundAccount;
use App\Models\FundAllocation;
use App\Models\FundAllocationItem;
use App\Models\FundAllocationItemLine;
use App\Models\FundCategory;
use App\Models\FundPaymentMethod;
use App\Models\PaymentVoucher;
use App\Models\Staff;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\FundAllocationTotals;
use App\Models\PaymentVoucherItem;
use App\Support\NumberToWords;
use App\Support\PaymentVoucherNumbers;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Funds Allocation sheets: prepare (Draft) -> submit -> approve (+ choose the payer) -> payer ticks every payment ->
 * Completed. Every action authorises explicitly (no route-name derived ability), see Salary/SalaryPeriodController.
 */
class FundAllocationController extends Controller
{
    private const LOG = 'Funds Allocation';

    public function __construct()
    {
        $this->setAbilities([]);
    }

    /* ------------------------------------------------------------------------------------------------ pages */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->isAdmin($user) || $user->can('read-fund-allocations') || $user->can('pay-fund-allocations') || $user->can('create-fund-allocations'), 403);

        $filters = $request->all('search', 'status', 'from', 'to', 'payer');

        $paid = DB::table('fund_allocation_item_lines as l')
            ->join('fund_allocation_items as i', 'i.id', '=', 'l.fund_allocation_item_id')
            ->whereColumn('i.fund_allocation_id', 'fund_allocations.id')->whereNull('i.deleted_at')->whereNull('l.deleted_at')
            ->selectRaw('COALESCE(SUM(CASE WHEN l.is_paid = 1 THEN l.amount ELSE 0 END), 0)');
        $planned = DB::table('fund_allocation_item_lines as l')
            ->join('fund_allocation_items as i', 'i.id', '=', 'l.fund_allocation_item_id')
            ->whereColumn('i.fund_allocation_id', 'fund_allocations.id')->whereNull('i.deleted_at')->whereNull('l.deleted_at')
            ->selectRaw('COALESCE(SUM(l.amount), 0)');

        $paginator = $this->visible(FundAllocation::query(), $user)
            ->select('fund_allocations.*')
            ->selectSub($paid, 'paid_total')
            ->selectSub($planned, 'planned_total')
            ->withSum('balances as available_total', 'amount_tzs')
            ->withSum('items as allocated_total', 'allocated_amount')
            ->withCount('items as items_total')
            ->with(['preparedBy:id,name', 'approvedBy:id,name', 'assignedTo:id,name', 'clearedBy:id,name'])
            ->filter($filters)
            ->orderByDesc('reference')->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (FundAllocation $a) use ($request) {
                $hide = $this->hideBalances($request->user(), $a);
                $available = round((float) $a->available_total, 2);
                $allocated = round((float) $a->allocated_total, 2);
                $planned = (float) $a->planned_total;

                return [
                    'id' => $a->id,
                    'reference' => $a->reference,
                    'allocation_date' => optional($a->allocation_date)->toDateString(),
                    'title' => $a->title,
                    'status' => $a->status,
                    'item_count' => (int) $a->items_total,
                    'available' => $hide ? null : $available,
                    'allocated' => $allocated,
                    'balance' => $hide ? null : round($available - $allocated, 2),
                    'paid' => round((float) $a->paid_total, 2),
                    'progress' => $planned > 0 ? round((float) $a->paid_total / $planned * 100, 1) : 0,
                    'overspent' => ! $hide && $allocated > $available + 0.009,
                    'prepared_by' => $this->person($a->preparedBy),
                    'approved_by' => $this->person($a->approvedBy),
                    'assigned_to' => $this->person($a->assignedTo),
                    'cleared_by' => $this->person($a->clearedBy),
                    'can_edit' => $this->canEditNow($request->user(), $a) && $this->mayEdit($request->user(), $a),
                ];
            });

        return Inertia::render('FundAllocation/Index', [
            'filters' => $filters,
            'allocations' => paginated_response($paginator),
            'statuses' => FundAllocation::statuses(),
            'payers' => $this->payers(),
            'stats' => $this->stats($user),
            'can' => [
                'create' => $this->isAdmin($user) || $user->can('create-fund-allocations'),
                'template' => $user->hasRole('Super Admin') || $user->can('read-fund-allocation-templates') || $user->can('update-fund-allocation-templates'),
                'approve' => $user->can('approve-fund-allocations'),
                'pay' => $user->can('pay-fund-allocations'),
                'print' => $user->can('print-fund-allocations'),
                'export' => $user->can('export-fund-allocations'),
                'settings' => $user->can('manage-fund-allocation-settings'),
                'reports' => $user->can('read-fund-allocation-reports'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($this->mayPrepare($request->user()), 403);

        return Inertia::render('FundAllocation/Form', $this->formProps(null));
    }

    public function show(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($this->canSee($user, $a), 403);

        $allocation = $this->present($a);
        $totals = FundAllocationTotals::for($a);
        $hide = $this->hideBalances($user, $a);
        if ($hide) {
            // The assigned payer works from the allocations only: no cash/bank balances, available total or balance left.
            $allocation['balances'] = [];
            $totals['available'] = null;
            $totals['balance'] = null;
        }

        return Inertia::render('FundAllocation/Show', [
            'allocation' => $allocation,
            'totals' => $totals,
            'history' => $this->history($a),
            'can' => $this->abilities($user, $a) + ['see_balances' => ! $hide],
            'payers' => $this->payers(),
        ]);
    }

    public function edit(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($this->mayEdit($user, $a), 403);
        if (! $this->canEditNow($user, $a)) {
            return redirect()->route('fund-allocations.show', $a->id)->with('error', __('Only a Draft or Rejected sheet can be edited.'));
        }

        return Inertia::render('FundAllocation/Form', $this->formProps($a));
    }

    /* ------------------------------------------------------------------------------------- create / edit */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->mayPrepare($user), 403);

        $data = $this->validated($request, null);

        $allocation = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $allocation = DB::transaction(function () use ($data, $user) {
                    $a = FundAllocation::create([
                        'reference' => FundAllocation::nextReference(),
                        'allocation_date' => $data['allocation_date'],
                        'title' => $data['title'],
                        'notes' => $data['notes'],
                        'status' => FundAllocation::STATUS_DRAFT,
                        'prepared_by_user_id' => $user->id,
                    ]);
                    $this->syncChildren($a, $data);
                    log_activity(__('Allocation sheet created.'), ['reference' => $a->reference, 'items' => count($data['items'])], $a, self::LOG);

                    return $a;
                });
                break;
            } catch (QueryException $e) {
                // Only a reference collision (two sheets created at the same instant) is worth a retry.
                if ((string) $e->getCode() !== '23000' || $attempt === 2 || ! str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }
            }
        }
        abort_if(! $allocation, 500, 'Unable to generate a unique allocation reference.');

        $this->fire('created', $user, __('Allocation created'), $allocation->reference.': '.__('allocation sheet created by :name.', ['name' => $user->name]), $allocation);

        if ($request->boolean('submit')) {
            return $this->submitAfterSave($allocation, $user);
        }

        return redirect()->route('fund-allocations.show', $allocation->id)->with('message', __('Allocation sheet :ref saved as a draft.', ['ref' => $allocation->reference]));
    }

    /**
     * Copies a sheet into a brand-new Draft (new reference, today's date, the current user as preparer). Nothing about
     * the original's approval, payer, payments, clearing or linked vouchers is carried over. Because it spawns a
     * whole sheet in one click, it is gated behind a Super Admin password (any current Super Admin's password works,
     * so a preparer can ask a Super Admin to type it in) — same rule as other sensitive switches in WIMS.
     */
    public function duplicate(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        abort_unless($this->mayPrepare($user), 403);
        $source = $this->resolve($fund_allocation);
        abort_unless($this->canSee($user, $source), 403);

        $data = $request->validate(['password' => ['required', 'string']]);
        $authorized = User::role('Super Admin')->get()->contains(fn (User $u) => Hash::check($data['password'], $u->password));
        if (! $authorized) {
            throw ValidationException::withMessages(['password' => __('That password does not match any Super Admin account.')]);
        }

        $copy = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $copy = DB::transaction(function () use ($source, $user) {
                    $source->load(['balances', 'items.lines']);
                    $new = FundAllocation::create([
                        'reference' => FundAllocation::nextReference(),
                        'allocation_date' => now()->toDateString(),
                        'title' => $source->title,
                        'notes' => $source->notes,
                        'status' => FundAllocation::STATUS_DRAFT,
                        'prepared_by_user_id' => $user->id,
                    ]);
                    foreach ($source->balances as $b) {
                        $new->balances()->create($b->only(['fund_account_id', 'label', 'currency', 'amount', 'exchange_rate', 'amount_tzs', 'sort_order']));
                    }
                    foreach ($source->items as $it) {
                        $item = $new->items()->create($it->only([
                            'fund_category_id', 'sort_order', 'description', 'beneficiary', 'contact_id', 'beneficiary_user_id',
                            'beneficiary_warehouse_id', 'staff_id', 'warehouse_id', 'request_amount', 'allocated_amount', 'notes',
                        ]));
                        foreach ($it->lines as $l) {
                            $item->lines()->create($l->only(['fund_payment_method_id', 'amount', 'exchange_rate', 'usd_amount']));
                        }
                    }
                    log_activity(__('Allocation sheet duplicated from :ref.', ['ref' => $source->reference]), ['reference' => $new->reference, 'source' => $source->reference, 'items' => $source->items->count()], $new, self::LOG);
                    log_activity(__('Allocation sheet duplicated as :ref.', ['ref' => $new->reference]), ['reference' => $source->reference, 'copy' => $new->reference], $source, self::LOG);

                    return $new;
                });
                break;
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23000' || $attempt === 2 || ! str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }
            }
        }
        abort_if(! $copy, 500, 'Unable to generate a unique allocation reference.');

        $this->fire('duplicated', $user, __('Allocation duplicated'), $copy->reference.': '.__('created as a copy of :src by :name.', ['src' => $source->reference, 'name' => $user->name]), $copy);

        return redirect()->route('fund-allocations.show', $copy->id)->with('message', __('Allocation sheet :src duplicated as :ref (Draft).', ['src' => $source->reference, 'ref' => $copy->reference]));
    }

    public function update(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($this->mayEdit($user, $a), 403);

        $data = $this->validated($request, $a);

        DB::transaction(function () use ($a, $data, $user) {
            $locked = $this->lock($a->id);
            if (! $this->canEditNow($user, $locked)) {
                $this->fail(__('Only a Draft or Rejected sheet can be edited.'));
            }
            $post = ! $locked->isEditable();
            $locked->update(['allocation_date' => $data['allocation_date'], 'title' => $data['title'], 'notes' => $data['notes']]);
            $this->syncChildren($locked, $data);
            log_activity($post ? __('Allocation sheet edited by Super Admin after approval (:status).', ['status' => $locked->status]) : __('Allocation sheet updated.'), ['reference' => $locked->reference, 'items' => count($data['items']), 'status' => $locked->status], $locked, self::LOG);
            if ($post && $this->refreshProgress($locked->fresh(), $user)) {
                $this->guardSignatures($locked->id, ['cleared']); // Signatories finalisation guard: editing a paid sheet can complete it
            }
        });

        $this->fire('updated', $user, __('Allocation edited'), $a->reference.': '.__('allocation sheet edited by :name.', ['name' => $user->name]), $a->fresh(), [$a->prepared_by_user_id]);

        if ($request->boolean('submit') && $a->fresh()->isEditable()) {
            return $this->submitAfterSave($a->fresh(), $user);
        }

        return redirect()->route('fund-allocations.show', $a->id)->with('message', __('Allocation sheet :ref updated.', ['ref' => $a->reference]));
    }

    public function destroy(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($user->can('delete-fund-allocations') && $this->canSee($user, $a), 403);

        DB::transaction(function () use ($a) {
            $locked = $this->lock($a->id);
            if (! in_array($locked->status, [FundAllocation::STATUS_DRAFT, FundAllocation::STATUS_REJECTED, FundAllocation::STATUS_CANCELLED], true)) {
                $this->fail(__('Only a Draft, Rejected or Cancelled sheet can be deleted.'));
            }
            $locked->delete(); // soft delete; the model records deleted_by
            log_activity(__('Allocation sheet deleted.'), ['reference' => $locked->reference, 'status' => $locked->status], $locked, self::LOG);
        });

        $this->fire('deleted', $user, __('Allocation deleted'), $a->reference.': '.__('allocation sheet deleted by :name.', ['name' => $user->name]), $a, [$a->prepared_by_user_id], ['url' => route('fund-allocations.index')]);

        return redirect()->route('fund-allocations.index')->with('message', __('Allocation sheet :ref deleted.', ['ref' => $a->reference]));
    }

    /* ------------------------------------------------------------------------------------ print / PDF */

    public function print(Request $request, FundAllocation $fund_allocation)
    {
        $a = $this->printable($request, $fund_allocation);

        return view('fund-allocations.print', $this->printData($a, false));
    }

    public function pdf(Request $request, FundAllocation $fund_allocation)
    {
        $a = $this->printable($request, $fund_allocation);

        return Pdf::loadView('fund-allocations.print', $this->printData($a, true))
            ->setPaper('a4', 'landscape')
            ->download('funds-allocation-'.$a->reference.'.pdf');
    }

    /* ----------------------------------------------------------------------------------------- workflow */

    public function submit(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($this->maySubmit($user, $a), 403);

        $this->performSubmit($a, $user);

        return redirect()->route('fund-allocations.show', $a->id)->with('message', __('Allocation sheet :ref submitted for approval.', ['ref' => $a->reference]));
    }

    public function approve(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($user->can('approve-fund-allocations') && $this->canSee($user, $a), 403);

        $data = $request->validate(['assigned_to_user_id' => ['required', 'integer', 'exists:users,id']]);
        $payer = $this->eligiblePayer((int) $data['assigned_to_user_id']);

        DB::transaction(function () use ($a, $user, $payer) {
            $locked = $this->lock($a->id);
            if ($locked->status !== FundAllocation::STATUS_PENDING) {
                $this->fail(__('Only a sheet that is pending approval can be approved.'));
            }
            $locked->update([
                'status' => FundAllocation::STATUS_APPROVED,
                'approved_by_user_id' => $user->id,
                'approved_at' => now(),
                'assigned_to_user_id' => $payer->id,
                'rejection_reason' => null,
            ]);
            log_activity(__('Allocation sheet approved. Payer: :name.', ['name' => $payer->name]), ['reference' => $locked->reference, 'payer' => $payer->name, 'payer_id' => $payer->id], $locked, self::LOG);
        });

        $a->refresh();
        $this->fire('pay', $user, __('Allocation to pay'), $a->reference.': '.__('approved by :name, you are the payer.', ['name' => $user->name]), $a, [$payer]);
        // the preparer is not told twice when he or she is also the payer
        $this->fire('approved', $user, __('Allocation approved'), $a->reference.': '.__('approved by :name. Payer: :payer.', ['name' => $user->name, 'payer' => $payer->name]), $a, (int) $a->prepared_by_user_id === (int) $payer->id ? [] : [$a->prepared_by_user_id]);

        return back()->with('message', __('Allocation sheet :ref approved. Payer: :name.', ['ref' => $a->reference, 'name' => $payer->name]));
    }

    public function reject(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($user->can('approve-fund-allocations') && $this->canSee($user, $a), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($a, $data) {
            $locked = $this->lock($a->id);
            if ($locked->status !== FundAllocation::STATUS_PENDING) {
                $this->fail(__('Only a sheet that is pending approval can be rejected.'));
            }
            $locked->update(['status' => FundAllocation::STATUS_REJECTED, 'rejection_reason' => $data['reason']]);
            log_activity(__('Allocation sheet rejected.'), ['reference' => $locked->reference, 'reason' => $data['reason']], $locked, self::LOG);
        });

        $a->refresh();
        $this->fire('rejected', $user, __('Allocation rejected'), $a->reference.': '.$data['reason'], $a, [$a->prepared_by_user_id], ['remarks' => $data['reason']]);

        return back()->with('message', __('Allocation sheet :ref rejected.', ['ref' => $a->reference]));
    }

    public function reassign(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless(($user->can('approve-fund-allocations') || $user->hasRole('Super Admin')) && $this->canSee($user, $a), 403);

        $data = $request->validate(['assigned_to_user_id' => ['required', 'integer', 'exists:users,id']]);
        $payer = $this->eligiblePayer((int) $data['assigned_to_user_id']);

        $old = null;
        DB::transaction(function () use ($a, $payer, &$old, $user) {
            $locked = $this->lock($a->id);
            $reopen = in_array($locked->status, [FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED], true) && $user->hasRole('Super Admin');
            if (! $locked->isPayable() && ! $reopen) {
                $this->fail(__('The payer can only be changed while the sheet is Approved or In Progress.'));
            }
            $old = $locked->assignedTo;
            $locked->update(['assigned_to_user_id' => $payer->id] + ($locked->status === FundAllocation::STATUS_CLEARED ? ['status' => FundAllocation::STATUS_COMPLETED, 'cleared_by_user_id' => null, 'cleared_at' => null] : []));
            log_activity(__('Payer reassigned to :name.', ['name' => $payer->name]), ['reference' => $locked->reference, 'from' => optional($old)->name, 'to' => $payer->name], $locked, self::LOG);
        });

        $a->refresh();
        $this->fire('pay', $user, __('Allocation to pay'), $a->reference.': '.__('you were assigned as the payer by :name.', ['name' => $user->name]), $a, [$payer]);
        if ($old) {
            $this->fire('reassigned', $user, __('Allocation reassigned'), $a->reference.': '.__('you are no longer the payer.'), $a, [$old]);
        }

        return back()->with('message', __('Payer changed to :name.', ['name' => $payer->name]));
    }

    public function cancel(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless(($user->can('approve-fund-allocations') || $user->hasRole('Super Admin')) && $this->canSee($user, $a), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($a, $data, $user) {
            $locked = $this->lock($a->id);
            if (in_array($locked->status, [FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED, FundAllocation::STATUS_CANCELLED], true)) {
                $this->fail(__('A Completed, Cleared or already Cancelled sheet cannot be cancelled.'));
            }
            $locked->update([
                'status' => FundAllocation::STATUS_CANCELLED,
                'cancel_reason' => $data['reason'],
                'cancelled_by_user_id' => $user->id,
                'cancelled_at' => now(),
            ]);
            log_activity(__('Allocation sheet cancelled.'), ['reference' => $locked->reference, 'reason' => $data['reason']], $locked, self::LOG);
        });

        $a->refresh();
        $this->fire('cancelled', $user, __('Allocation cancelled'), $a->reference.': '.$data['reason'], $a, [$a->prepared_by_user_id, $a->assigned_to_user_id], ['remarks' => $data['reason']]);

        return back()->with('message', __('Allocation sheet :ref cancelled.', ['ref' => $a->reference]));
    }

    /** The payer (or an approver / Super Admin) closes a fully paid sheet: it becomes Cleared and is locked. */
    public function clear(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($this->mayClear($user, $a), 403);

        DB::transaction(function () use ($a, $user) {
            $locked = $this->lock($a->id);
            if ($locked->status !== FundAllocation::STATUS_COMPLETED) {
                $this->fail(__('Only a fully paid (Completed) sheet can be closed.'));
            }
            $locked->update(['status' => FundAllocation::STATUS_CLEARED, 'cleared_by_user_id' => $user->id, 'cleared_at' => now()]);
            $this->guardSignatures($locked->id); // Signatories finalisation guard (see payLine())
            log_activity(__('Allocation sheet closed and cleared.'), ['reference' => $locked->reference, 'cleared_by' => $user->name], $locked, self::LOG);
        });

        $a->refresh();
        $this->fire('cleared', $user, __('Allocation cleared'), $a->reference.': '.__('closed and cleared by :name.', ['name' => $user->name]), $a, [$a->approved_by_user_id, $a->prepared_by_user_id]);

        return back()->with('message', __('Allocation sheet :ref closed. It is now Cleared.', ['ref' => $a->reference]));
    }

    public function reopen(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($user->hasRole('Super Admin'), 403);

        DB::transaction(function () use ($a) {
            $locked = $this->lock($a->id);
            if (! in_array($locked->status, [FundAllocation::STATUS_REJECTED, FundAllocation::STATUS_CANCELLED], true)) {
                $this->fail(__('Only a Rejected or Cancelled sheet can be reopened.'));
            }
            $hasPaid = FundAllocationItemLine::whereHas('item', fn ($q) => $q->where('fund_allocation_id', $locked->id))->where('is_paid', true)->exists();
            if ($hasPaid) {
                $this->fail(__('This sheet has payments that were already ticked, so it cannot go back to Draft.'));
            }
            $was = $locked->status;
            $locked->update([
                'status' => FundAllocation::STATUS_DRAFT,
                'rejection_reason' => null, 'cancel_reason' => null, 'cancelled_by_user_id' => null, 'cancelled_at' => null,
                'submitted_at' => null, 'approved_by_user_id' => null, 'approved_at' => null, 'assigned_to_user_id' => null, 'completed_at' => null,
            ]);
            log_activity(__('Allocation sheet reopened as a draft.'), ['reference' => $locked->reference, 'was' => $was], $locked, self::LOG);
        });

        $this->fire('reopened', $user, __('Allocation reopened'), $a->reference.': '.__('reopened as a draft by :name.', ['name' => $user->name]), $a->fresh(), [$a->prepared_by_user_id]);

        return back()->with('message', __('Allocation sheet :ref reopened as a draft.', ['ref' => $a->reference]));
    }

    /* ------------------------------------------------------------------------------------ payer actions */

    public function payLine(Request $request, FundAllocationItemLine $fund_allocation_item_line)
    {
        $user = $request->user();
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:190']]);
        $line = $fund_allocation_item_line;

        $allocationId = (int) FundAllocationItem::whereKey($line->fund_allocation_item_id)->value('fund_allocation_id');
        $a = FundAllocation::findOrFail($allocationId);
        abort_unless($this->mayTick($user, $a), 403);

        $completed = false;
        DB::transaction(function () use ($a, $line, $data, $user, &$completed) {
            $locked = $this->lock($a->id);
            if (! $locked->isPayable()) {
                $this->fail(__('Payments can only be ticked while the sheet is Approved or In Progress.'));
            }
            $fresh = FundAllocationItemLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($fresh->is_paid) {
                $this->fail(__('This payment is already ticked.'));
            }
            $fresh->update([
                'is_paid' => true,
                'paid_at' => now(),
                'paid_by_user_id' => $user->id,
                'reference' => ($data['reference'] ?? null) !== null && trim($data['reference']) !== '' ? trim($data['reference']) : $fresh->reference,
            ]);
            $item = $fresh->item;
            log_activity(
                __('Payment ticked: :item (:method).', ['item' => $item->description, 'method' => optional($fresh->method)->name]),
                ['reference' => $locked->reference, 'item' => $item->description, 'method' => optional($fresh->method)->name, 'amount' => (float) $fresh->amount, 'payment_reference' => $fresh->reference],
                $locked,
                self::LOG
            );
            $completed = $this->refreshProgress($locked, $user);
            if ($completed) {
                $this->guardSignatures($locked->id, ['cleared']); // Signatories finalisation guard: the last tick completes the sheet
            }
        });

        $a->refresh();
        $this->fire('payment_ticked', $user, __('Payment ticked'), $a->reference.': '.__('a payment was ticked as paid by :name.', ['name' => $user->name]), $a, [$a->prepared_by_user_id, $a->approved_by_user_id]);
        if ($completed) {
            $this->announceCompleted($a, $user);
        }

        return back()->with('message', $completed ? __('All payments are ticked. The sheet is Completed.') : __('Payment ticked.'));
    }

    /** Payer / Super Admin: mark every unpaid payment of the chosen items (or of the whole sheet) as paid in one go. */
    public function payBulk(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($this->mayTick($user, $a), 403);

        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
            'reference' => ['nullable', 'string', 'max:190'],
        ]);
        if (empty($data['all']) && empty($data['item_ids'])) {
            $this->fail(__('Select at least one item first.'));
        }

        $count = 0;
        $amount = 0.0;
        $items = 0;
        $completed = false;
        DB::transaction(function () use ($a, $data, $user, &$count, &$amount, &$items, &$completed) {
            $locked = $this->lock($a->id);
            if (! $locked->isPayable()) {
                $this->fail(__('Payments can only be ticked while the sheet is Approved or In Progress.'));
            }
            $rows = $locked->items()->with('lines')
                ->when(empty($data['all']), fn ($q) => $q->whereIn('id', $data['item_ids']))
                ->get();
            $ref = isset($data['reference']) && trim($data['reference']) !== '' ? trim($data['reference']) : null;
            foreach ($rows as $item) {
                $touched = false;
                if ((float) $item->allocated_amount <= 0) {
                    continue; // nothing allocated: nothing to pay
                }
                foreach ($item->lines as $line) {
                    if ($line->is_paid || (float) $line->amount <= 0) {
                        continue;
                    }
                    $line->update(['is_paid' => true, 'paid_at' => now(), 'paid_by_user_id' => $user->id, 'reference' => $line->reference ?: $ref]);
                    $count++;
                    $amount += (float) $line->amount;
                    $touched = true;
                }
                $items += $touched ? 1 : 0;
            }
            if ($count === 0) {
                $this->fail(__('Everything selected is already marked as paid.'));
            }
            log_activity(
                __(':n payments on :i items marked as paid in one action.', ['n' => $count, 'i' => $items]),
                ['reference' => $locked->reference, 'payments' => $count, 'items' => $items, 'amount' => round($amount, 2)],
                $locked,
                self::LOG
            );
            $completed = $this->refreshProgress($locked->fresh(), $user);
            if ($completed) {
                $this->guardSignatures($locked->id, ['cleared']); // Signatories finalisation guard: the bulk tick completed the sheet
            }
        });

        $a->refresh();
        // Tell whoever raises payment vouchers that this sheet is ready for them.
        // (the people holding create-payment-vouchers are the event's step audience; with none, the Super Admins and Admins stand in as owners)
        $owners = [$a->prepared_by_user_id];
        if (! User::permission('create-payment-vouchers')->where('is_active', true)->exists()) {
            $owners = array_merge($owners, User::role(['Super Admin', 'Admin'])->where('is_active', true)->get()->all());
        }
        $this->fire('payment_ticked', $user, __('Payments ticked'), $a->reference.': '.__(':n payments (:i items) were ticked as paid by :name.', ['n' => $count, 'i' => $items, 'name' => $user->name]), $a, [$a->prepared_by_user_id, $a->approved_by_user_id]);
        $this->fire('vouchers_needed', $user, __('Generate payment vouchers'), $a->reference.': '.__(':n payments (:i items) were marked as paid by :name. Please generate the payment vouchers for this Funds Allocation.', ['n' => $count, 'i' => $items, 'name' => $user->name]), $a, $owners);
        if ($completed) {
            $this->announceCompleted($a, $user);
        }

        return back()->with('message', __(':n payments on :i items marked as paid. The people who raise vouchers were notified.', ['n' => $count, 'i' => $items]));
    }

    /** Create one Submitted (awaiting approval) payment voucher for every paid item that has none, then tell the approvers. */
    public function generateVouchers(Request $request, FundAllocation $fund_allocation)
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($this->mayGenerateVouchers($user, $a), 403);

        $data = $request->validate(['item_ids' => ['nullable', 'array'], 'item_ids.*' => ['integer']]);

        $created = [];
        // numbering lock first, then the transaction (see PaymentVoucherNumbers)
        PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($a, $data, $user, &$created) {
            $locked = $this->lock($a->id);
            if (! in_array($locked->status, [FundAllocation::STATUS_APPROVED, FundAllocation::STATUS_IN_PROGRESS, FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED], true)) {
                $this->fail(__('Payment vouchers can only be generated for an approved sheet.'));
            }
            $items = $locked->items()->with(['lines', 'category', 'contact', 'beneficiaryUser.staff', 'beneficiaryWarehouse'])
                ->whereNull('payment_voucher_id')
                ->when(! empty($data['item_ids']), fn ($q) => $q->whereIn('id', $data['item_ids']))
                ->lockForUpdate()->get()
                ->filter(fn ($i) => $i->lines->isNotEmpty() && $i->lines->every(fn ($l) => $l->is_paid) && $i->lines->sum('amount') > 0);
            if ($items->isEmpty()) {
                $this->fail(__('There are no fully paid items without a payment voucher.'));
            }

            foreach ($items as $item) {
                $total = round((float) $item->lines->sum('amount'), 2);
                $particulars = mb_substr(trim((string) $item->description).' - Funds Allocation '.$locked->reference, 0, PaymentVoucher::PARTICULARS_MAX);

                $payee = ['payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL, 'payee_contact_id' => null, 'staff_id' => null, 'payee_name' => (string) $item->beneficiary, 'warehouse_id' => $item->warehouse_id];
                if ($item->contact_id && $item->contact) {
                    $payee['payee_contact_id'] = $item->contact_id;
                    $payee['payee_name'] = $item->contact->name;
                } elseif ($item->beneficiary_user_id && $item->beneficiaryUser) {
                    $payee['payee_name'] = $item->beneficiaryUser->name;
                    $staff = $item->beneficiaryUser->staff;
                    if ($staff && $staff->status === 'Active') {
                        $payee['payee_type'] = PaymentVoucher::PAYEE_TYPE_STAFF;
                        $payee['staff_id'] = $staff->id;
                        $payee['payee_name'] = $staff->name;
                    }
                } elseif ($item->beneficiary_warehouse_id && $item->beneficiaryWarehouse) {
                    $payee['payee_name'] = $item->beneficiaryWarehouse->name;
                    $payee['warehouse_id'] = $payee['warehouse_id'] ?: $item->beneficiary_warehouse_id;
                }
                if (trim((string) $payee['payee_name']) === '') {
                    $payee['payee_name'] = __('Unspecified beneficiary');
                }

                $voucher = PaymentVoucherNumbers::createVoucher($payee + [
                    'type' => mb_substr(trim((string) optional($item->category)->name) ?: 'Administration', 0, 100),
                    'voucher_date' => now()->toDateString(),
                    'total_amount' => $total,
                    'amount_in_words' => NumberToWords::convert($total),
                    'status' => PaymentVoucher::STATUS_SUBMITTED, // straight to "awaiting approval"
                    'prepared_by_user_id' => $user->id,
                    'remarks' => mb_substr('Funds Allocation '.$locked->reference.' - '.$item->description.$this->usdNote($item), 0, 2000),
                ]);

                $voucher->items()->create(['line_number' => 1, 'particulars' => PaymentVoucher::particularsText($particulars), 'amount' => $total]);
                $item->update(['payment_voucher_id' => $voucher->id]);
                log_activity(__('Payment Voucher generated from Funds Allocation :ref.', ['ref' => $locked->reference]), ['pv_number' => $voucher->pv_number, 'fund_allocation' => $locked->reference], $voucher, 'Payment Voucher');
                $created[] = $voucher->pv_number;
            }
            log_activity(__(':n payment vouchers generated.', ['n' => count($created)]), ['reference' => $locked->reference, 'vouchers' => $created], $locked, self::LOG);
        }));

        $a->refresh();
        $n = count($created);
        // (the people holding approve-payment-vouchers are the event's step audience; with none, the Super Admins stand in as owners)
        $owners = User::permission('approve-payment-vouchers')->where('is_active', true)->exists() ? [] : User::role('Super Admin')->where('is_active', true)->get()->all();
        $this->fire('payment_vouchers_awaiting_approval', $user, __('Payment vouchers awaiting approval'), __(':n payment voucher(s) were generated from Funds Allocation :ref by :name and are awaiting your approval.', ['n' => $n, 'ref' => $a->reference, 'name' => $user->name]), $a, $owners, ['url' => route('payment-vouchers.index', ['status' => PaymentVoucher::STATUS_SUBMITTED])]);

        return back()->with('message', __(':n payment vouchers generated and sent for approval.', ['n' => $n]));
    }

    public function unpayLine(Request $request, FundAllocationItemLine $fund_allocation_item_line)
    {
        $user = $request->user();
        $line = $fund_allocation_item_line;

        $allocationId = (int) FundAllocationItem::whereKey($line->fund_allocation_item_id)->value('fund_allocation_id');
        $a = FundAllocation::findOrFail($allocationId);
        abort_unless($this->mayTick($user, $a), 403);

        DB::transaction(function () use ($a, $line) {
            $locked = $this->lock($a->id);
            if (! in_array($locked->status, [FundAllocation::STATUS_APPROVED, FundAllocation::STATUS_IN_PROGRESS, FundAllocation::STATUS_COMPLETED], true)) {
                $this->fail(__('Payments can only be un-ticked on an active sheet.'));
            }
            $fresh = FundAllocationItemLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->is_paid) {
                $this->fail(__('This payment is not ticked.'));
            }
            $fresh->update(['is_paid' => false, 'paid_at' => null, 'paid_by_user_id' => null]);
            $item = $fresh->item;
            log_activity(
                __('Payment tick removed: :item (:method).', ['item' => $item->description, 'method' => optional($fresh->method)->name]),
                ['reference' => $locked->reference, 'item' => $item->description, 'method' => optional($fresh->method)->name, 'amount' => (float) $fresh->amount],
                $locked,
                self::LOG
            );
            $this->refreshProgress($locked);
        });

        $a->refresh();
        $this->fire('payment_unticked', $user, __('Payment tick removed'), $a->reference.': '.__('a payment tick was removed by :name.', ['name' => $user->name]), $a, [$a->prepared_by_user_id, $a->approved_by_user_id]);

        return back()->with('message', __('Payment tick removed.'));
    }

    public function linkVoucher(Request $request, FundAllocationItem $fund_allocation_item)
    {
        $user = $request->user();
        $item = $fund_allocation_item;
        $a = FundAllocation::findOrFail($item->fund_allocation_id);
        abort_unless($this->canSee($user, $a) && ($this->mayTick($user, $a) || $user->can('update-fund-allocations') || $this->isAdmin($user)), 403);

        $data = $request->validate(['payment_voucher_id' => ['nullable', 'integer', Rule::exists('payment_vouchers', 'id')->whereNull('deleted_at')]]);

        DB::transaction(function () use ($a, $item, $data) {
            $locked = $this->lock($a->id);
            if (in_array($locked->status, [FundAllocation::STATUS_CANCELLED, FundAllocation::STATUS_REJECTED], true)) {
                $this->fail(__('A voucher cannot be linked to a Cancelled or Rejected sheet.'));
            }
            if ($locked->status === FundAllocation::STATUS_CLEARED && ! auth()->user()->hasRole('Super Admin')) {
                $this->fail(__('This sheet is Cleared. Only a Super Admin can change it.'));
            }
            $voucherId = $data['payment_voucher_id'] ?? null;
            if ($voucherId) {
                // the voucher is locked while it is checked: it must still stand (not deleted, cancelled or rejected) and pay one line only
                $target = PaymentVoucher::whereKey($voucherId)->lockForUpdate()->first();
                if (! $target || in_array($target->status, [PaymentVoucher::STATUS_CANCELLED, PaymentVoucher::STATUS_REJECTED], true)) {
                    $this->fail(__('A cancelled, rejected or deleted payment voucher cannot be linked.'));
                }
                if (FundAllocationItem::where('payment_voucher_id', $voucherId)->where('id', '<>', $item->id)->exists()) {
                    $this->fail(__('This payment voucher is already linked to another Funds Allocation line.'));
                }
            }
            $item->update(['payment_voucher_id' => $voucherId]);
            $pv = $voucherId ? PaymentVoucher::find($voucherId) : null;
            log_activity(
                $pv ? __('Voucher :pv linked to ":item".', ['pv' => $pv->pv_number, 'item' => $item->description]) : __('Voucher unlinked from ":item".', ['item' => $item->description]),
                ['reference' => $locked->reference, 'item' => $item->description, 'pv_number' => optional($pv)->pv_number],
                $locked,
                self::LOG
            );
        });

        $pvNo = ($data['payment_voucher_id'] ?? null) ? optional(PaymentVoucher::find($data['payment_voucher_id']))->pv_number : null;
        $this->fire('voucher_linked', $user, __('Payment voucher link changed'), $a->reference.': '.($pvNo ? __('voucher :pv linked to ":item" by :name.', ['pv' => $pvNo, 'item' => $item->description, 'name' => $user->name]) : __('voucher unlinked from ":item" by :name.', ['item' => $item->description, 'name' => $user->name])), $a);

        return back()->with('message', ($data['payment_voucher_id'] ?? null) ? __('Payment voucher linked.') : __('Payment voucher unlinked.'));
    }

    /** JSON picker used by the detail page (AutoComplete json mode posts {search} or {id}). */
    public function voucherSearch(Request $request)
    {
        $user = $request->user();
        abort_unless($this->isAdmin($user) || $user->can('pay-fund-allocations') || $user->can('update-fund-allocations'), 403);

        $q = trim((string) ($request->input('search', $request->input('q', ''))));
        $id = $request->input('id');

        $vouchers = PaymentVoucher::query()
            ->when($id, fn ($w) => $w->whereKey($id))
            ->when(! $id, fn ($w) => $w->where('status', '!=', PaymentVoucher::STATUS_CANCELLED)
                ->when($q !== '', fn ($s) => $s->where(fn ($x) => $x->where('pv_number', 'like', "%{$q}%")->orWhere('payee_name', 'like', "%{$q}%"))))
            ->orderByDesc('id')->limit(15)
            ->get(['id', 'pv_number', 'payee_name', 'total_amount', 'status', 'voucher_date']);

        $linked = FundAllocationItem::whereIn('payment_voucher_id', $vouchers->pluck('id'))->pluck('payment_voucher_id')->all();

        return response()->json($vouchers->map(fn ($v) => [
            'id' => $v->id,
            'label' => $v->pv_number.($v->payee_name ? ' - '.$v->payee_name : '').' ('.number_format((float) $v->total_amount, 2).')',
            'pv_number' => $v->pv_number,
            'payee_name' => $v->payee_name,
            'total_amount' => (float) $v->total_amount,
            'status' => $v->status,
            'voucher_date' => optional($v->voucher_date)->toDateString(),
            'already_linked' => in_array($v->id, $linked, true),
        ])->values());
    }

    /* ======================================================================================== internals */

    private function isAdmin(?User $u): bool
    {
        return (bool) $u && $u->hasRole(['Super Admin', 'Admin']);
    }

    private function mayPrepare(User $u): bool
    {
        return $this->isAdmin($u) || $u->can('create-fund-allocations');
    }

    private function canSee(User $u, FundAllocation $a): bool
    {
        if ($this->isAdmin($u) || $u->can('read-fund-allocations')) {
            return true;
        }
        if ((int) $a->prepared_by_user_id === (int) $u->id) {
            return true;
        }

        return $u->can('pay-fund-allocations') && (int) $a->assigned_to_user_id === (int) $u->id;
    }

    private function mayEdit(User $u, FundAllocation $a): bool
    {
        if (! $this->canSee($u, $a)) {
            return false;
        }

        return $this->isAdmin($u) || $u->can('update-fund-allocations') || ($u->can('create-fund-allocations') && (int) $a->prepared_by_user_id === (int) $u->id);
    }

    private function maySubmit(User $u, FundAllocation $a): bool
    {
        if (! ($u->can('create-fund-allocations') || $u->can('update-fund-allocations'))) {
            return false;
        }

        return $u->hasRole('Super Admin') || (int) $a->prepared_by_user_id === (int) $u->id;
    }

    /** Tick / untick: pay permission AND (the assigned payer OR Super Admin/Admin). */
    /** Draft/Rejected sheets for anyone allowed to edit; the Super Admin may also edit a sheet that is approved, paid or cleared. */
    private function canEditNow(User $u, FundAllocation $a): bool
    {
        if ($a->isEditable()) {
            return true;
        }

        return $u->hasRole('Super Admin') && in_array($a->status, [FundAllocation::STATUS_APPROVED, FundAllocation::STATUS_IN_PROGRESS, FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED], true);
    }

    /** After approval the assigned payer (who is not also an approver, admin or the preparer) must not see the available balances. */
    private function hideBalances(User $u, FundAllocation $a): bool
    {
        return ! in_array($a->status, [FundAllocation::STATUS_DRAFT, FundAllocation::STATUS_PENDING, FundAllocation::STATUS_REJECTED], true)
            && (int) $a->assigned_to_user_id === (int) $u->id
            && ! $this->isAdmin($u)
            && ! $u->can('approve-fund-allocations')
            && (int) $a->prepared_by_user_id !== (int) $u->id;
    }

    private function mayGenerateVouchers(User $u, FundAllocation $a): bool
    {
        return in_array($a->status, [FundAllocation::STATUS_APPROVED, FundAllocation::STATUS_IN_PROGRESS, FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED], true)
            && ($this->isAdmin($u) || $u->can('create-payment-vouchers'));
    }

    private function mayClear(User $u, FundAllocation $a): bool
    {
        return $a->status === FundAllocation::STATUS_COMPLETED
            && ($this->mayTick($u, $a) || $u->can('approve-fund-allocations') || $u->hasRole('Super Admin'));
    }

    private function mayTick(User $u, FundAllocation $a): bool
    {
        return $u->can('pay-fund-allocations') && ($this->isAdmin($u) || (int) $a->assigned_to_user_id === (int) $u->id);
    }

    private function visible($query, User $u)
    {
        if ($this->isAdmin($u) || $u->can('read-fund-allocations')) {
            return $query;
        }

        return $query->where(function ($w) use ($u) {
            $w->where('prepared_by_user_id', $u->id);
            if ($u->can('pay-fund-allocations')) {
                $w->orWhere('assigned_to_user_id', $u->id);
            }
        });
    }

    /**
     * Signatories finalisation guard. A sheet is final once it is Completed (the last payment is ticked) or Cleared. Called inside the
     * transaction AFTER the step is written: when the document setup marks a signature as required AND blocking and it is missing, the whole
     * step is rolled back with a message naming it. Nothing is blocked until an administrator turns that on. $ignoreActions are actions that
     * can only happen after this step (the clearing cannot be required before the sheet is completed).
     */
    private function guardSignatures(int $id, array $ignoreActions = []): void
    {
        $blockers = array_values(array_filter(
            app(\App\Services\Signatories\SignatureResolver::class)->blockers('fund_allocation', FundAllocation::query()->findOrFail($id)),
            fn ($b) => ! in_array($b['action'], $ignoreActions, true)
        ));
        if ($blockers) {
            $this->fail(__('This step cannot be done yet. Missing signatures: :list', ['list' => collect($blockers)->map(fn ($b) => $b['role_label'] . ' (' . $b['detail'] . ')')->implode('; ')]), 'signatures');
        }
    }

    private function resolve(FundAllocation $a): FundAllocation
    {
        // The model's route binding includes soft-deleted rows; a deleted sheet is simply gone.
        abort_if($a->trashed(), 404);

        return $a;
    }

    private function lock(int $id): FundAllocation
    {
        return FundAllocation::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function fail(string $message, string $key = 'workflow'): void
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }

    private function person($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    /** Anyone with an active login can be made the payer (users, not staff records). */
    private function payerQuery()
    {
        return User::where('is_active', true)->orderBy('name');
    }

    private function payers(): array
    {
        $canPay = User::permission('pay-fund-allocations')->pluck('users.id')
            ->merge(User::role(['Super Admin', 'Admin'])->pluck('users.id'))
            ->flip();

        return $this->payerQuery()->with('roles:id,name')->get(['id', 'name', 'username'])->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'roles' => $u->roles->pluck('name')->values()->all(),
            'can_pay' => $canPay->has($u->id),
        ])->all();
    }

    private function eligiblePayer(int $id): User
    {
        $payer = $this->payerQuery()->whereKey($id)->first();
        if (! $payer) {
            throw ValidationException::withMessages(['assigned_to_user_id' => [__('Choose an active user.')]]);
        }

        return $payer;
    }

    /** What THIS user may do with THIS sheet right now (drives the buttons on the detail page). */
    private function abilities(User $u, FundAllocation $a): array
    {
        $status = $a->status;
        $approve = $u->can('approve-fund-allocations');

        return [
            'update' => $this->canEditNow($u, $a) && $this->mayEdit($u, $a),
            'delete' => $u->can('delete-fund-allocations') && in_array($status, [FundAllocation::STATUS_DRAFT, FundAllocation::STATUS_REJECTED, FundAllocation::STATUS_CANCELLED], true),
            'submit' => $a->isEditable() && $this->maySubmit($u, $a),
            'approve' => $approve && $status === FundAllocation::STATUS_PENDING,
            'reject' => $approve && $status === FundAllocation::STATUS_PENDING,
            'reassign' => ($approve && $a->isPayable()) || ($u->hasRole('Super Admin') && in_array($status, [FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED], true)),
            'clear' => $this->mayClear($u, $a),
            'pay_bulk' => $this->mayTick($u, $a) && $a->isPayable(),
            'generate_vouchers' => $this->mayGenerateVouchers($u, $a),
            'cancel' => ($approve || $u->hasRole('Super Admin')) && ! in_array($status, [FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED, FundAllocation::STATUS_CANCELLED], true),
            'reopen' => $u->hasRole('Super Admin') && in_array($status, [FundAllocation::STATUS_REJECTED, FundAllocation::STATUS_CANCELLED], true),
            'pay' => $this->mayTick($u, $a) && in_array($status, [FundAllocation::STATUS_APPROVED, FundAllocation::STATUS_IN_PROGRESS, FundAllocation::STATUS_COMPLETED], true),
            'link_voucher' => ! in_array($status, [FundAllocation::STATUS_CANCELLED, FundAllocation::STATUS_REJECTED], true) && ($status !== FundAllocation::STATUS_CLEARED || $u->hasRole('Super Admin')) && ($this->mayTick($u, $a) || $u->can('update-fund-allocations') || $this->isAdmin($u)),
            'print' => $u->can('print-fund-allocations'),
        ];
    }

    private function stats(User $u): array
    {
        $rows = $this->visible(FundAllocation::query(), $u)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $byStatus = fn (string $s) => (int) ($rows[$s] ?? 0);

        $month = (float) $this->visible(FundAllocation::query(), $u)
            ->whereIn('status', [FundAllocation::STATUS_PENDING, FundAllocation::STATUS_APPROVED, FundAllocation::STATUS_IN_PROGRESS, FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED])
            ->whereBetween('allocation_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->withSum('items as allocated_sum', 'allocated_amount')
            ->get()->sum('allocated_sum');

        return [
            'total' => (int) $rows->sum(),
            'draft' => $byStatus(FundAllocation::STATUS_DRAFT),
            'pending' => $byStatus(FundAllocation::STATUS_PENDING),
            'approved' => $byStatus(FundAllocation::STATUS_APPROVED),
            'in_progress' => $byStatus(FundAllocation::STATUS_IN_PROGRESS),
            'completed' => $byStatus(FundAllocation::STATUS_COMPLETED),
            'allocated_total_this_month' => round($month, 2),
        ];
    }

    private function formProps(?FundAllocation $a): array
    {
        $usedCategoryIds = $a ? $a->items()->pluck('fund_category_id')->all() : [];

        return [
            'allocation' => $a ? $this->present($a) : null,
            'accounts' => FundAccount::active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'type', 'currency', 'account_number']),
            'categories' => FundCategory::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $usedCategoryIds))
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order']),
            'methods' => FundPaymentMethod::active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'short_label', 'currency']),
            'contacts' => Contact::query()->orderBy('name')->get(['id', 'name', 'category']),
            'staffs' => Staff::where('status', 'Active')->orderBy('name')->get(['id', 'name', 'staff_number']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'username']),
            'projects' => Warehouse::ofAccount()->active()->orderBy('name')->get(['id', 'name', 'status']),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'defaults' => ['allocation_date' => now()->toDateString()],
        ];
    }

    private function present(FundAllocation $a): array
    {
        $a->load([
            'preparedBy:id,name', 'approvedBy:id,name', 'assignedTo:id,name', 'completedBy:id,name', 'clearedBy:id,name',
            'balances',
            'items.category', 'items.lines.method', 'items.lines.paidBy:id,name',
            'items.contact:id,name', 'items.beneficiaryUser:id,name', 'items.beneficiaryWarehouse:id,name', 'items.staff:id,name', 'items.warehouse:id,name',
            'items.paymentVoucher:id,pv_number,status',
        ]);

        $items = $a->items->sortBy(fn ($i) => [optional($i->category)->sort_order ?? 0, $i->fund_category_id, $i->sort_order, $i->id])->values();

        return [
            'id' => $a->id,
            'reference' => $a->reference,
            'allocation_date' => optional($a->allocation_date)->toDateString(),
            'title' => $a->title,
            'notes' => $a->notes,
            'status' => $a->status,
            'prepared_by' => $this->person($a->preparedBy),
            'approved_by' => $this->person($a->approvedBy),
            'assigned_to' => $this->person($a->assignedTo),
            'submitted_at' => optional($a->submitted_at)->toDateTimeString(),
            'approved_at' => optional($a->approved_at)->toDateTimeString(),
            'completed_at' => optional($a->completed_at)->toDateTimeString(),
            'paid_by' => $this->person($a->completedBy),
            'cleared_by' => $this->person($a->clearedBy),
            'cleared_at' => optional($a->cleared_at)->toDateTimeString(),
            'rejection_reason' => $a->rejection_reason,
            'cancel_reason' => $a->cancel_reason,
            'balances' => $a->balances->map(fn ($b) => [
                'id' => $b->id,
                'fund_account_id' => $b->fund_account_id,
                'label' => $b->label,
                'currency' => $b->currency,
                'amount' => (float) $b->amount,
                'exchange_rate' => (float) $b->exchange_rate,
                'amount_tzs' => (float) $b->amount_tzs,
            ])->values()->all(),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'fund_category_id' => $i->fund_category_id,
                'category_name' => optional($i->category)->name,
                'sort_order' => $i->sort_order,
                'description' => $i->description,
                'beneficiary' => $i->beneficiary,
                'contact_id' => $i->contact_id,
                'beneficiary_user_id' => $i->beneficiary_user_id,
                'beneficiary_warehouse_id' => $i->beneficiary_warehouse_id,
                'beneficiary_warehouse' => $i->beneficiaryWarehouse ? ['id' => $i->beneficiaryWarehouse->id, 'name' => $i->beneficiaryWarehouse->name] : null,
                'beneficiary_user' => $i->beneficiaryUser ? ['id' => $i->beneficiaryUser->id, 'name' => $i->beneficiaryUser->name] : null,
                'staff_id' => $i->staff_id,
                'warehouse_id' => $i->warehouse_id,
                'contact' => $i->contact ? ['id' => $i->contact->id, 'name' => $i->contact->name] : null,
                'staff' => $i->staff ? ['id' => $i->staff->id, 'name' => $i->staff->name] : null,
                'warehouse' => $i->warehouse ? ['id' => $i->warehouse->id, 'name' => $i->warehouse->name] : null,
                'request_amount' => (float) $i->request_amount,
                'allocated_amount' => (float) $i->allocated_amount,
                'notes' => $i->notes,
                'paid' => $i->isFullyPaid(),
                'payment_voucher_id' => $i->payment_voucher_id,
                'payment_voucher' => $i->paymentVoucher ? [
                    'id' => $i->paymentVoucher->id,
                    'pv_number' => $i->paymentVoucher->pv_number,
                    'status' => $i->paymentVoucher->status,
                    'url' => route('payment-vouchers.show', $i->paymentVoucher->id),
                ] : null,
                'voucher_create_url' => route('payment-vouchers.create', ['fund_item' => $i->id]),
                'lines' => $i->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'fund_payment_method_id' => $l->fund_payment_method_id,
                    'method_name' => optional($l->method)->name,
                    'short_label' => optional($l->method)->short_label,
                    'amount' => (float) $l->amount,
                    'currency' => optional($l->method)->currency ?: 'TZS',
                    'exchange_rate' => $l->exchange_rate !== null ? (float) $l->exchange_rate : null,
                    'usd_amount' => $l->usd_amount !== null ? (float) $l->usd_amount : null,
                    'reference' => $l->reference,
                    'is_paid' => (bool) $l->is_paid,
                    'paid_at' => optional($l->paid_at)->toDateTimeString(),
                    'paid_by' => $this->person($l->paidBy),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function history(FundAllocation $a): array
    {
        return Activity::query()
            ->where('subject_type', $a->getMorphClass())->where('subject_id', $a->id)->where('log_name', self::LOG)
            ->with('causer:id,name')
            ->orderByDesc('id')->limit(100)->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'description' => $e->description,
                'causer' => optional($e->causer)->name ?? __('System'),
                'created_at' => optional($e->created_at)->toDateTimeString(),
                'properties' => $e->properties,
            ])->values()->all();
    }

    /* ------------------------------------------------------------------------- validation and saving */

    private function validated(Request $request, ?FundAllocation $a): array
    {
        $rules = [
            'allocation_date' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'balances' => ['nullable', 'array', 'max:50'],
            'balances.*.id' => ['nullable', 'integer'],
            'balances.*.fund_account_id' => ['nullable', 'integer', Rule::exists('fund_accounts', 'id')->whereNull('deleted_at')],
            'balances.*.label' => ['nullable', 'string', 'max:190'],
            'balances.*.currency' => ['nullable', 'string', 'size:3'],
            'balances.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'balances.*.exchange_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.fund_category_id' => ['required', 'integer', Rule::exists('fund_categories', 'id')->whereNull('deleted_at')],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.beneficiary' => ['nullable', 'string', 'max:255'],
            'items.*.contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')],
            'items.*.beneficiary_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'items.*.beneficiary_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'items.*.staff_id' => ['nullable', 'integer', Rule::exists('staffs', 'id')],
            'items.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'items.*.request_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'items.*.allocated_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.lines' => ['nullable', 'array', 'max:20'],
            'items.*.lines.*.id' => ['nullable', 'integer'],
            'items.*.lines.*.fund_payment_method_id' => ['required', 'integer', Rule::exists('fund_payment_methods', 'id')->whereNull('deleted_at')],
            'items.*.lines.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'items.*.lines.*.exchange_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items.*.lines.*.reference' => ['nullable', 'string', 'max:190'],
        ];

        $existingItems = $a ? $a->items()->with('lines')->get()->keyBy('id') : collect();
        $accounts = FundAccount::query()->get(['id', 'name', 'currency', 'account_number'])->keyBy('id');
        $activeCategoryIds = FundCategory::active()->pluck('id')->all();
        $usdMethodIds = FundPaymentMethod::query()->where('currency', 'USD')->pluck('id')->all();

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($v) use ($request, $existingItems, $accounts, $activeCategoryIds, $usdMethodIds) {
            foreach ((array) $request->input('balances', []) as $i => $b) {
                $account = $accounts->get($b['fund_account_id'] ?? null);
                $currency = strtoupper((string) ($b['currency'] ?? '')) ?: strtoupper((string) optional($account)->currency ?: 'TZS');
                if ($currency !== 'TZS' && ! ((float) ($b['exchange_rate'] ?? 0) > 0)) {
                    $v->errors()->add("balances.$i.exchange_rate", __('An exchange rate (1 :cur = ? TZS) is required for a non-TZS balance.', ['cur' => $currency]));
                }
                if (trim((string) ($b['label'] ?? '')) === '' && ! $account) {
                    $v->errors()->add("balances.$i.label", __('Choose an account or type a label.'));
                }
            }
            // Payments that were already ticked are facts: they cannot be removed or altered by editing the sheet.
            $sentItems = collect((array) $request->input('items', []))->keyBy(fn ($x) => (string) ($x['id'] ?? ''));
            foreach ($existingItems as $existing) {
                $paidLines = $existing->lines->where('is_paid', true);
                if ($paidLines->isEmpty()) {
                    continue;
                }
                $sent = $sentItems->get((string) $existing->id);
                if (! $sent) {
                    $v->errors()->add('items', __('":item" has payments that are already ticked, so it cannot be removed. Un-tick them first.', ['item' => $existing->description]));
                    continue;
                }
                $sentLines = collect((array) ($sent['lines'] ?? []))->keyBy(fn ($x) => (string) ($x['id'] ?? ''));
                foreach ($paidLines as $pl) {
                    $sl = $sentLines->get((string) $pl->id);
                    if (! $sl) {
                        $v->errors()->add('items', __('":item": a payment line that is already ticked cannot be removed. Un-tick it first.', ['item' => $existing->description]));
                    } elseif ((int) ($sl['fund_payment_method_id'] ?? 0) !== (int) $pl->fund_payment_method_id || abs((float) ($sl['amount'] ?? 0) - (float) $pl->amount) > 0.004
                        || ($pl->exchange_rate !== null && abs((float) ($sl['exchange_rate'] ?? 0) - (float) $pl->exchange_rate) > 0.0001)) {
                        $v->errors()->add('items', __('":item": the method or amount of a payment line that is already ticked cannot be changed. Un-tick it first.', ['item' => $existing->description]));
                    }
                }
            }
            // A line paid in USD needs its exchange rate (TZS per 1 USD), unless it is an old ticked line that never had one.
            foreach ((array) $request->input('items', []) as $i => $item) {
                $existingLines = optional($existingItems->get($item['id'] ?? null))->lines ?? collect();
                foreach ((array) ($item['lines'] ?? []) as $li => $l) {
                    if (! in_array((int) ($l['fund_payment_method_id'] ?? 0), $usdMethodIds, true)) {
                        continue;
                    }
                    $old = $existingLines->firstWhere('id', $l['id'] ?? null);
                    if (! ((float) ($l['exchange_rate'] ?? 0) > 0) && ! ($old && $old->is_paid)) {
                        $v->errors()->add("items.$i.lines", __('Enter the exchange rate (TZS per 1 USD) for the USD payment.'));
                    }
                }
            }
            foreach ((array) $request->input('items', []) as $i => $item) {
                $legacy = $existingItems->get($item['id'] ?? null);
                $hasBeneficiary = ! empty($item['contact_id']) || ! empty($item['beneficiary_user_id']) || ! empty($item['beneficiary_warehouse_id'])
                    || ($legacy && trim((string) $legacy->beneficiary) !== '' && trim((string) ($item['beneficiary'] ?? '')) === trim((string) $legacy->beneficiary));
                if (! $hasBeneficiary && trim((string) ($item['description'] ?? '')) !== '') {
                    $v->errors()->add("items.$i.beneficiary", __('Choose the beneficiary from Contacts, Users or Projects.'));
                }
                $cat = (int) ($item['fund_category_id'] ?? 0);
                $existing = $existingItems->get($item['id'] ?? null);
                $keepsCategory = $existing && (int) $existing->fund_category_id === $cat;
                if ($cat && ! $keepsCategory && ! in_array($cat, $activeCategoryIds, true)) {
                    $v->errors()->add("items.$i.fund_category_id", __('This category is not active.'));
                }
            }
        });
        $validator->validate();

        $in = $request->all();

        $balances = [];
        foreach ((array) ($in['balances'] ?? []) as $b) {
            $account = $accounts->get($b['fund_account_id'] ?? null);
            $currency = strtoupper((string) ($b['currency'] ?? '')) ?: strtoupper((string) optional($account)->currency ?: 'TZS');
            $balances[] = [
                'id' => $b['id'] ?? null,
                'fund_account_id' => $account ? $account->id : null,
                'label' => trim((string) ($b['label'] ?? '')) !== '' ? trim($b['label']) : ($account ? ($account->account_number ? $account->name . ' (' . $account->account_number . ')' : $account->name) : null),
                'currency' => $currency,
                'amount' => round((float) $b['amount'], 2),
                'exchange_rate' => $currency === 'TZS' ? 1 : (float) $b['exchange_rate'],
            ];
        }

        $items = [];
        foreach ((array) ($in['items'] ?? []) as $item) {
            $lines = [];
            foreach ((array) ($item['lines'] ?? []) as $l) {
                $isUsd = in_array((int) $l['fund_payment_method_id'], $usdMethodIds, true);
                $rate = $isUsd && (float) ($l['exchange_rate'] ?? 0) > 0 ? (float) $l['exchange_rate'] : null;
                $lines[] = [
                    'id' => $l['id'] ?? null,
                    'fund_payment_method_id' => (int) $l['fund_payment_method_id'],
                    'amount' => round((float) $l['amount'], 2),
                    'exchange_rate' => $rate,
                    'usd_amount' => $rate ? round((float) $l['amount'] / $rate, 2) : null,
                    'reference' => isset($l['reference']) && trim((string) $l['reference']) !== '' ? trim($l['reference']) : null,
                ];
            }
            $items[] = [
                'id' => $item['id'] ?? null,
                'fund_category_id' => (int) $item['fund_category_id'],
                'description' => trim($item['description']),
                'beneficiary' => isset($item['beneficiary']) && trim((string) $item['beneficiary']) !== '' ? trim($item['beneficiary']) : null,
                'contact_id' => $item['contact_id'] ?? null ?: null,
                'beneficiary_user_id' => empty($item['contact_id']) ? ($item['beneficiary_user_id'] ?? null ?: null) : null,
                'beneficiary_warehouse_id' => empty($item['contact_id']) && empty($item['beneficiary_user_id']) ? ($item['beneficiary_warehouse_id'] ?? null ?: null) : null,
                'staff_id' => $item['staff_id'] ?? null ?: null,
                'warehouse_id' => $item['warehouse_id'] ?? null ?: null,
                'request_amount' => round((float) ($item['request_amount'] ?? 0), 2),
                'allocated_amount' => round((float) ($item['allocated_amount'] ?? 0), 2),
                'notes' => isset($item['notes']) && trim((string) $item['notes']) !== '' ? trim($item['notes']) : null,
                'lines' => $lines,
            ];
        }

        return [
            'allocation_date' => $in['allocation_date'],
            'title' => isset($in['title']) && trim((string) $in['title']) !== '' ? trim($in['title']) : null,
            'notes' => isset($in['notes']) && trim((string) $in['notes']) !== '' ? trim($in['notes']) : null,
            'balances' => $balances,
            'items' => $items,
        ];
    }

    /** " (paid in USD 360.00 @ 2,500 TZS/USD)" for items that have USD lines, appended to the payment voucher remarks. */
    private function usdNote(FundAllocationItem $item): string
    {
        $usd = $item->lines->filter(fn ($l) => $l->usd_amount !== null && $l->exchange_rate);
        if ($usd->isEmpty()) {
            return '';
        }

        return ' (paid in USD '.number_format((float) $usd->sum('usd_amount'), 2).' @ '.rtrim(rtrim(number_format((float) $usd->first()->exchange_rate, 4), '0'), '.').' TZS/USD)';
    }

    /** Replace balances / items / lines, keeping the ids (and so voucher links and tick state) of rows that still exist. */
    private function syncChildren(FundAllocation $a, array $d): void
    {
        $existingBalances = $a->balances()->get()->keyBy('id');
        $keep = [];
        foreach ($d['balances'] as $i => $b) {
            $row = [
                'fund_account_id' => $b['fund_account_id'], 'label' => $b['label'], 'currency' => $b['currency'],
                'amount' => $b['amount'], 'exchange_rate' => $b['exchange_rate'], 'sort_order' => ($i + 1) * 10,
            ];
            $model = $b['id'] ? $existingBalances->get($b['id']) : null;
            if ($model) {
                $model->fill($row)->save();
            } else {
                $model = $a->balances()->create($row);
            }
            $keep[] = $model->id;
        }
        $existingBalances->except($keep)->each->forceDelete();

        $existingItems = $a->items()->get()->keyBy('id');
        $keepItems = [];
        foreach ($d['items'] as $i => $it) {
            $row = [
                'fund_category_id' => $it['fund_category_id'], 'sort_order' => ($i + 1) * 10, 'description' => $it['description'],
                'beneficiary' => $it['beneficiary'], 'contact_id' => $it['contact_id'], 'beneficiary_user_id' => $it['beneficiary_user_id'], 'beneficiary_warehouse_id' => $it['beneficiary_warehouse_id'], 'staff_id' => $it['staff_id'], 'warehouse_id' => $it['warehouse_id'],
                'request_amount' => $it['request_amount'], 'allocated_amount' => $it['allocated_amount'], 'notes' => $it['notes'],
            ];
            $item = $it['id'] ? $existingItems->get($it['id']) : null;
            if ($item) {
                $item->fill($row)->save();
            } else {
                $item = $a->items()->create($row);
            }
            $keepItems[] = $item->id;

            $existingLines = $item->lines()->get()->keyBy('id');
            $keepLines = [];
            foreach ($it['lines'] as $l) {
                $lrow = ['fund_payment_method_id' => $l['fund_payment_method_id'], 'amount' => $l['amount'], 'exchange_rate' => $l['exchange_rate'], 'usd_amount' => $l['usd_amount'], 'reference' => $l['reference']];
                $line = $l['id'] ? $existingLines->get($l['id']) : null;
                if ($line) {
                    $line->fill($lrow)->save();
                } else {
                    $line = $item->lines()->create($lrow);
                }
                $keepLines[] = $line->id;
            }
            $existingLines->except($keepLines)->each->forceDelete();
        }
        $existingItems->except($keepItems)->each->forceDelete();
    }

    /* ------------------------------------------------------------------------------------ submitting */

    private function submitAfterSave(FundAllocation $a, User $user)
    {
        if (! $this->maySubmit($user, $a)) {
            return redirect()->route('fund-allocations.show', $a->id)->with('message', __('Saved as a draft. You are not allowed to submit this sheet.'));
        }
        try {
            $this->performSubmit($a, $user);
        } catch (ValidationException $e) {
            // The draft is saved; send the preparer back to the editor with the reasons.
            return redirect()->route('fund-allocations.edit', $a->id)->withErrors($e->errors());
        }

        return redirect()->route('fund-allocations.show', $a->id)->with('message', __('Allocation sheet :ref submitted for approval.', ['ref' => $a->reference]));
    }

    private function performSubmit(FundAllocation $a, User $user): void
    {
        DB::transaction(function () use ($a) {
            $locked = $this->lock($a->id);
            if (! $locked->isEditable()) {
                $this->fail(__('Only a Draft or Rejected sheet can be submitted.'), 'submit');
            }
            $problems = $this->submissionProblems($locked);
            if ($problems) {
                throw ValidationException::withMessages(['submit' => [implode("\n", array_slice($problems, 0, 6))]]);
            }
            $locked->update(['status' => FundAllocation::STATUS_PENDING, 'submitted_at' => now(), 'rejection_reason' => null]);
            log_activity(__('Allocation sheet submitted for approval.'), ['reference' => $locked->reference], $locked, self::LOG);
        });

        $a->refresh();
        $this->fire('approval_needed', $user, __('Allocation awaiting approval'), $a->reference.': '.($a->title ?: optional($a->allocation_date)->toDateString()), $a);
    }

    private function submissionProblems(FundAllocation $a): array
    {
        $items = $a->items()->with('lines')->get();
        $problems = [];
        if ($items->isEmpty()) {
            $problems[] = __('Add at least one item before submitting.');
        } elseif ((float) $items->sum('allocated_amount') <= 0) {
            $problems[] = __('The total allocation is zero. Allocate an amount to at least one item.');
        }
        foreach ($items as $n => $item) {
            $label = '#'.($n + 1).' '.$item->description;
            $sum = round((float) $item->lines->sum('amount'), 2);
            if ($item->lines->contains(fn ($l) => (float) $l->amount <= 0)) {
                $problems[] = $label.': '.__('every payment line must be more than zero.');
            }
            if (abs($sum - (float) $item->allocated_amount) > 0.009) {
                $problems[] = $label.': '.__('payment split (:split) does not equal the allocation (:alloc).', ['split' => number_format($sum, 2), 'alloc' => number_format((float) $item->allocated_amount, 2)]);
            }
        }

        return $problems;
    }

    /* ------------------------------------------------------------------------------- payment progress */

    /**
     * Re-derive each item's paid_at and the sheet status from the ticks. Returns true when this call moved the
     * sheet into Completed. Caller holds the lock on the sheet.
     */
    private function refreshProgress(FundAllocation $a, ?User $actor = null): bool
    {
        $items = $a->items()->with('lines')->get();
        $any = false;
        $all = true;
        $hasLines = false;

        foreach ($items as $item) {
            $itemPaid = $item->lines->isNotEmpty() && $item->lines->every(fn ($l) => $l->is_paid);
            if ($itemPaid && ! $item->paid_at) {
                $item->forceFill(['paid_at' => now()])->save();
            } elseif (! $itemPaid && $item->paid_at) {
                $item->forceFill(['paid_at' => null])->save();
            }
            foreach ($item->lines as $l) {
                $hasLines = true;
                $l->is_paid ? $any = true : $all = false;
            }
        }

        $wasCompleted = in_array($a->status, [FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED], true);
        $reset = ['completed_at' => null, 'completed_by_user_id' => null, 'cleared_by_user_id' => null, 'cleared_at' => null];
        if ($hasLines && $all) {
            if (! $wasCompleted) {
                $a->update(['status' => FundAllocation::STATUS_COMPLETED, 'completed_at' => now(), 'completed_by_user_id' => optional($actor)->id ?: $a->assigned_to_user_id]);
                log_activity(__('All payments ticked. Allocation sheet completed.'), ['reference' => $a->reference], $a, self::LOG);

                return true;
            }
        } elseif ($any) {
            if ($a->status !== FundAllocation::STATUS_IN_PROGRESS) {
                $a->update(['status' => FundAllocation::STATUS_IN_PROGRESS] + $reset);
            }
        } elseif ($a->status !== FundAllocation::STATUS_APPROVED) {
            $a->update(['status' => FundAllocation::STATUS_APPROVED] + $reset);
        }

        return false;
    }

    private function announceCompleted(FundAllocation $a, User $actor): void
    {
        $this->fire('completed', $actor, __('Allocation completed'), $a->reference.': '.__('every payment has been ticked.'), $a, [$a->approved_by_user_id, $a->prepared_by_user_id]);
    }

    /* ------------------------------------------------------------------------------------ notifications */

    /**
     * Hands one event to the Funds Allocation workflow page's settings (Who Gets Notified / Messages). $owners are the people the event is
     * about (users or user ids, nulls ignored): they are "the owner" of the notice. The person who did the action is never told about it.
     * Events: approval_needed, pay, approved, rejected, reassigned, cancelled, vouchers_needed, payment_vouchers_awaiting_approval, completed, cleared.
     *
     * @param array $owners users or user ids
     */
    private function fire(string $event, User $actor, string $title, string $message, FundAllocation $a, array $owners = [], array $ctx = []): void
    {
        try {
        $a->loadMissing(['preparedBy:id,name', 'approvedBy:id,name', 'assignedTo:id,name']);
        $ids = collect($owners)->map(fn ($o) => $o instanceof User ? (int) $o->id : (int) $o)->filter()->unique()->values();

        WorkflowMessenger::dispatch('fund_allocations', $event, $a, $actor, $ctx + [
            'account_id' => $actor->account_id ?? 1,
            'title' => $title, 'message' => $message, 'url' => route('fund-allocations.show', $a->id),
            'owner_user_id' => (int) $ids->first(), 'owner_users' => $ids->slice(1)->map(fn ($id) => ['id' => $id])->all(),
            'skip_actor' => true,
            'values' => [
                'reference' => $a->reference, 'sheettitle' => $a->title ?: optional($a->allocation_date)->toDateString(), 'date' => optional($a->allocation_date)->format('d M Y') ?? '',
                'preparer' => optional($a->preparedBy)->name ?? '', 'approver' => optional($a->approvedBy)->name ?? '', 'payer' => optional($a->assignedTo)->name ?? '', 'status' => $a->status,
            ],
            'details' => [
                __('Reference') => $a->reference, __('Title') => $a->title, __('Prepared by') => optional($a->preparedBy)->name, __('Payer') => optional($a->assignedTo)->name,
            ],
        ]);
        } catch (\Throwable $e) {
            report($e); // a failed notice never stops the action
        }
    }

    /* ------------------------------------------------------------------------------------------- print */

    private function printable(Request $request, FundAllocation $fund_allocation): FundAllocation
    {
        $user = $request->user();
        $a = $this->resolve($fund_allocation);
        abort_unless($user->can('print-fund-allocations') && $this->canSee($user, $a), 403);

        return $a;
    }

    private function printData(FundAllocation $a, bool $isPdf): array
    {
        $a->load(['preparedBy:id,name', 'approvedBy:id,name', 'assignedTo:id,name', 'balances', 'items.category', 'items.lines.method', 'items.lines.paidBy:id,name', 'items.contact:id,name', 'items.staff:id,name', 'items.warehouse:id,name', 'items.paymentVoucher:id,pv_number']);
        $items = $a->items->sortBy(fn ($i) => [optional($i->category)->sort_order ?? 0, $i->fund_category_id, $i->sort_order, $i->id])->values();

        return [
            'allocation' => $a,
            'items' => $items,
            'totals' => FundAllocationTotals::for($a),
            'hideBalances' => $this->hideBalances(auth()->user(), $a),
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('fund-allocations.show', $a->id)),
            'isPdf' => $isPdf,
            'pdfUrl' => route('fund-allocations.pdf', $a->id),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('fund_allocation', $a),
        ];
    }
}
