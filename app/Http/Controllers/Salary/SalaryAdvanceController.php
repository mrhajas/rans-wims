<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalaryAdvanceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-salary-advances');

        $filters = $request->all('search', 'status');

        $advances = SalaryAdvance::query()
            ->with('staff:id,name,staff_number')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")->orWhereHas('staff', fn ($sq) => $sq->where('name', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString();

        $active = SalaryAdvance::where('status', SalaryAdvance::STATUS_ACTIVE)->get();

        return Inertia::render('Salary/Advances/Index', [
            'filters' => $filters,
            'statuses' => SalaryAdvance::statuses(),
            'advances' => $advances->through(fn (SalaryAdvance $a) => $this->presentSummary($a)),
            'summary' => [
                'total_active' => $active->count(),
                'total_outstanding' => round($active->sum(fn (SalaryAdvance $a) => $a->outstandingBalance()), 2),
                'total_pending' => SalaryAdvance::where('status', SalaryAdvance::STATUS_PENDING)->count(),
                'total_disbursed' => round(SalaryAdvance::whereIn('status', [SalaryAdvance::STATUS_ACTIVE, SalaryAdvance::STATUS_COMPLETED])->sum('amount'), 2),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create-salary-advances');

        return Inertia::render('Salary/Advances/Form', [
            'staffs' => Staff::payroll()->orderBy('name')->get(['id', 'name', 'staff_number', 'basic_salary']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create-salary-advances');

        $data = $this->validated($request);

        $advance = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $advance = SalaryAdvance::create($data + [
                    'reference' => $this->nextReference(),
                    'status' => SalaryAdvance::STATUS_PENDING,
                    'created_by_user_id' => $request->user()->id,
                ]);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                if ((string) $e->getCode() !== '23000' || $attempt === 2) {
                    throw $e;
                }
                $advance = null;
            }
        }

        if ($request->hasFile('attachments')) {
            $advance->saveAttachments($request->file('attachments'), 'Salary Advance');
        }

        log_activity(__('Salary Advance created.'), ['reference' => $advance->reference], $advance, 'Salary Advance');

        \App\Services\SalaryNotifier::fire('advance_created', $advance, $request->user(), __('Salary Advance Requested'), 'Salary advance ' . $advance->reference . ' for ' . optional($advance->staff)->name . ' was requested by ' . $request->user()->name . '.', ['reference' => $advance->reference, 'amount' => \App\Services\SalaryNotifier::money($advance->amount)]);

        return redirect()->route('salary.advances.show', $advance->id)->with('message', __('Salary Advance created.'));
    }

    public function show(Request $request, $id)
    {
        $this->authorize('read-salary-advances');

        $advance = SalaryAdvance::with(['staff:id,name,staff_number', 'approvedBy:id,name', 'repayments.record.period:id,label', 'attachments'])->findOrFail($id);

        return Inertia::render('Salary/Advances/Show', ['advance' => $this->presentDetail($advance)]);
    }

    public function update(Request $request, $id)
    {
        $this->authorize('update-salary-advances');

        $advance = SalaryAdvance::findOrFail($id);
        abort_unless($advance->status === SalaryAdvance::STATUS_PENDING, 422, 'Only a Pending salary advance can be edited.');

        $advance->update($this->validated($request));

        log_activity(__('Salary Advance updated.'), ['reference' => $advance->reference], $advance, 'Salary Advance');

        \App\Services\SalaryNotifier::fire('advance_updated', $advance, $request->user(), __('Salary Advance Edited'), 'Salary advance ' . $advance->reference . ' for ' . optional($advance->staff)->name . ' was edited by ' . $request->user()->name . '.', ['reference' => $advance->reference, 'amount' => \App\Services\SalaryNotifier::money($advance->amount)]);

        return back()->with('message', __('Salary Advance updated.'));
    }

    public function destroy(Request $request, $id)
    {
        $this->authorize('delete-salary-advances');

        $advance = SalaryAdvance::findOrFail($id);
        abort_unless($advance->status === SalaryAdvance::STATUS_PENDING, 422, 'Only a Pending salary advance can be deleted.');

        $advance->delete();

        log_activity(__('Salary Advance deleted.'), ['reference' => $advance->reference], $advance, 'Salary Advance');

        \App\Services\SalaryNotifier::fire('advance_deleted', $advance, $request->user(), __('Salary Advance Deleted'), 'Salary advance ' . $advance->reference . ' for ' . optional($advance->staff)->name . ' was deleted by ' . $request->user()->name . '.', ['reference' => $advance->reference, 'amount' => \App\Services\SalaryNotifier::money($advance->amount)]);

        return redirect()->route('salary.advances.index')->with('message', __('Salary Advance deleted.'));
    }

    public function approve(Request $request, $id)
    {
        $this->authorize('approve-salary-advances');

        DB::transaction(function () use ($id, $request) {
            $advance = SalaryAdvance::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($advance->status === SalaryAdvance::STATUS_PENDING, 422, 'This advance must be Pending for this action.');

            $advance->update([
                'status' => SalaryAdvance::STATUS_ACTIVE,
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => now(),
            ]);
            log_activity(__('Salary Advance approved.'), ['reference' => $advance->reference], $advance, 'Salary Advance');
        });

        $advance = SalaryAdvance::with('staff')->find($id);
        \App\Services\SalaryNotifier::fire('advance_approved', $advance, $request->user(), __('Salary Advance Approved'), 'Salary advance ' . $advance->reference . ' for ' . optional($advance->staff)->name . ' was approved by ' . $request->user()->name . '.', ['reference' => $advance->reference, 'amount' => \App\Services\SalaryNotifier::money($advance->amount)]);

        return back()->with('message', __('Salary Advance approved.'));
    }

    public function reject(Request $request, $id)
    {
        $this->authorize('approve-salary-advances');

        DB::transaction(function () use ($id) {
            $advance = SalaryAdvance::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($advance->status === SalaryAdvance::STATUS_PENDING, 422, 'This advance must be Pending for this action.');

            $advance->update(['status' => SalaryAdvance::STATUS_REJECTED]);
            log_activity(__('Salary Advance rejected.'), ['reference' => $advance->reference], $advance, 'Salary Advance');
        });

        $advance = SalaryAdvance::with('staff')->find($id);
        \App\Services\SalaryNotifier::fire('advance_rejected', $advance, $request->user(), __('Salary Advance Rejected'), 'Salary advance ' . $advance->reference . ' for ' . optional($advance->staff)->name . ' was rejected by ' . $request->user()->name . '.', ['reference' => $advance->reference, 'amount' => \App\Services\SalaryNotifier::money($advance->amount)]);

        return back()->with('message', __('Salary Advance rejected.'));
    }

    public function cancel(Request $request, $id)
    {
        $this->authorize('approve-salary-advances');

        DB::transaction(function () use ($id) {
            $advance = SalaryAdvance::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($advance->status === SalaryAdvance::STATUS_ACTIVE, 422, 'This advance must be Active for this action.');

            $advance->update(['status' => SalaryAdvance::STATUS_CANCELLED]);
            log_activity(__('Salary Advance cancelled.'), ['reference' => $advance->reference], $advance, 'Salary Advance');
        });

        $advance = SalaryAdvance::with('staff')->find($id);
        \App\Services\SalaryNotifier::fire('advance_cancelled', $advance, $request->user(), __('Salary Advance Cancelled'), 'Salary advance ' . $advance->reference . ' for ' . optional($advance->staff)->name . ' was cancelled by ' . $request->user()->name . '.', ['reference' => $advance->reference, 'amount' => \App\Services\SalaryNotifier::money($advance->amount)]);

        return back()->with('message', __('Salary Advance cancelled.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'staff_id' => 'required|exists:staffs,id',
            'advance_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:1000',
            'installments_count' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]) + ['installment_amount' => round($request->input('amount') / max(1, (int) $request->input('installments_count')), 2)];
    }

    private function nextReference(): string
    {
        $prefix = 'SAD-' . now()->format('Y') . '-';
        $prefixLength = strlen($prefix) + 1;
        $maxSuffix = (int) DB::table('salary_advances')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', "{$prefix}%")
            ->value('max_suffix');

        return $prefix . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function presentSummary(SalaryAdvance $advance): array
    {
        $amount = (float) $advance->amount;
        $outstanding = $advance->outstandingBalance();
        $progress = $amount > 0 ? round((($amount - $outstanding) / $amount) * 100) : 0;

        return [
            'id' => $advance->id,
            'reference' => $advance->reference,
            'staff_name' => optional($advance->staff)->name,
            'advance_date' => optional($advance->advance_date)->format('Y-m-d'),
            'amount' => $amount,
            'installment_amount' => (float) $advance->installment_amount,
            'outstanding_balance' => $outstanding,
            'repayment_progress' => $progress,
            'status' => $advance->status,
        ];
    }

    private function presentDetail(SalaryAdvance $advance): array
    {
        return array_merge($this->presentSummary($advance), [
            'reason' => $advance->reason,
            'notes' => $advance->notes,
            'installments_count' => $advance->installments_count,
            'approved_by_name' => optional($advance->approvedBy)->name,
            'repayments' => $advance->repayments->map(fn ($r) => [
                'period_label' => optional(optional($r->record)->period)->label,
                'amount' => (float) $r->amount,
                'date' => optional($r->created_at)->format('Y-m-d'),
            ])->values(),
        ]);
    }
}
