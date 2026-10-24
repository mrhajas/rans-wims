<?php

namespace App\Http\Controllers;

use App\Models\PaymentVoucher;
use App\Models\Staff;
use App\Models\StaffContract;
use App\Models\StaffHistory;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tecdiary\Laravel\Attachments\Attachment;

/**
 * Staff > Contracts: add, edit, renew and end a staff member's employment contracts, keep the signed documents with them,
 * and print the contract sheet. Every ability is its own (read / create / update / delete / print staff contracts), given to roles in the Roles page.
 */
class StaffContractController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function mine(Staff $staff, StaffContract $contract): void
    {
        abort_unless((int) $contract->staff_id === (int) $staff->id, 404);
    }

    private function rules(): array
    {
        return [
            'type' => 'required|in:'.implode(',', StaffContract::TYPES),
            'position' => 'nullable|string|max:190',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'signed_date' => 'nullable|date',
            'basic_salary' => 'nullable|numeric|min:0',
            'probation_months' => 'nullable|integer|between:0,36',
            'notice_period_days' => 'nullable|integer|between:0,365',
            'notes' => 'nullable|string|max:3000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:'.attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ];
    }

    /**
     * A Permanent contract has a start date only: whatever end date was sent is dropped. Every other type needs an end date.
     */
    private function normalise(array $data): array
    {
        if (($data['type'] ?? '') === 'Permanent') {
            $data['end_date'] = null;
        } elseif (empty($data['end_date'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['end_date' => __('Enter the end date. Only a Permanent contract has no end date.')]);
        }

        return $data;
    }

    public function store(Request $request, Staff $staff)
    {
        abort_unless($request->user()->can('create-staff-contracts'), 403);
        $data = $request->validate($this->rules());
        $data = $this->normalise($data);

        $today = CarbonImmutable::today();
        $finished = ! empty($data['end_date']) && CarbonImmutable::parse($data['end_date'])->lt($today);
        if (! $finished && $staff->contracts()->where('status', StaffContract::STATUS_ACTIVE)->exists()) {
            return back()->withErrors(['start_date' => __('This person already has an active contract. Renew it instead, or end it first.')]);
        }

        $contract = DB::transaction(function () use ($data, $staff, $request, $finished) {
            $c = new StaffContract();
            $c->fill(collect($data)->except('attachments')->all());
            $c->contract_number = StaffContract::nextNumber();
            $c->staff_id = $staff->id;
            $c->status = $finished ? StaffContract::STATUS_ENDED : StaffContract::STATUS_ACTIVE; // a contract that already finished is kept as history
            $c->created_by_user_id = $request->user()->id;
            $c->save();

            return $c;
        });
        if ($request->hasFile('attachments')) {
            $contract->saveAttachments($request->file('attachments'), 'contract');
        }
        StaffHistory::record($staff, 'contract_added', __('Contract :n added (:t)', ['n' => $contract->contract_number, 't' => $contract->type]), [
            'contract_id' => $contract->id, 'occurred_on' => $contract->start_date->toDateString(),
            'details' => ['type' => $contract->type, 'start_date' => $contract->start_date->toDateString(), 'end_date' => optional($contract->end_date)->toDateString(), 'finished' => $finished],
        ]);
        log_activity(__('Staff contract added.'), ['staff' => $staff->staff_number, 'contract' => $contract->contract_number], $staff, 'Staff');
        \App\Services\StaffNotifier::fire('contract_added', $staff, __('Contract added'), __('Contract :c was added for :s by :n.', ['c' => $contract->contract_number, 's' => $staff->name, 'n' => $request->user()->name]), $request->user(), $contract);

        return back()->with('message', $finished ? __('Contract :n saved as a finished contract (its end date has passed).', ['n' => $contract->contract_number]) : __('Contract :n added.', ['n' => $contract->contract_number]));
    }

    public function update(Request $request, Staff $staff, StaffContract $contract)
    {
        abort_unless($request->user()->can('update-staff-contracts'), 403);
        $this->mine($staff, $contract);
        $data = $request->validate($this->rules());
        $data = $this->normalise($data);

        $changedEnd = optional($contract->end_date)->format('Y-m-d') !== ($data['end_date'] ?? null);
        $before = $contract->only(['type', 'position', 'start_date', 'end_date', 'basic_salary']);
        $contract->fill(collect($data)->except('attachments')->all());
        if ($changedEnd) {
            $contract->last_reminder_days = null; // a new end date starts the reminders again
        }
        $contract->save();
        if ($request->hasFile('attachments')) {
            $contract->saveAttachments($request->file('attachments'), 'contract');
        }
        $changes = collect($before)->map(fn ($v, $k) => [$v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $contract->{$k} instanceof \DateTimeInterface ? $contract->{$k}->format('Y-m-d') : $contract->{$k}])->filter(fn ($p) => (string) $p[0] !== (string) $p[1]);
        if ($changes->isNotEmpty() || $request->hasFile('attachments')) {
            StaffHistory::record($staff, 'contract_edited', __('Contract :n edited', ['n' => $contract->contract_number]), [
                'contract_id' => $contract->id, 'details' => ['changes' => $changes->map(fn ($p) => ['from' => $p[0], 'to' => $p[1]])->all(), 'documents_added' => $request->hasFile('attachments')],
            ]);
        }
        log_activity(__('Staff contract updated.'), ['staff' => $staff->staff_number, 'contract' => $contract->contract_number], $staff, 'Staff');
        \App\Services\StaffNotifier::fire('contract_updated', $staff, __('Contract updated'), __('Contract :c of :s was updated by :n.', ['c' => $contract->contract_number, 's' => $staff->name, 'n' => $request->user()->name]), $request->user(), $contract);

        return back()->with('message', __('Contract :n updated.', ['n' => $contract->contract_number]));
    }

    /** Renewal: a new contract that continues this one. The old one is closed as "Renewed". */
    public function renew(Request $request, Staff $staff, StaffContract $contract)
    {
        abort_unless($request->user()->can('update-staff-contracts'), 403);
        $this->mine($staff, $contract);
        abort_unless($contract->status === StaffContract::STATUS_ACTIVE, 422, __('Only an active contract can be renewed.'));
        $data = $request->validate($this->rules());
        $data = $this->normalise($data);
        if (CarbonImmutable::parse($data['start_date'])->lte(CarbonImmutable::parse($contract->start_date))) {
            return back()->withErrors(['start_date' => __('The renewal must start after the contract it renews (:d).', ['d' => $contract->start_date->format('d M Y')])]);
        }

        $new = DB::transaction(function () use ($data, $staff, $contract, $request) {
            $c = new StaffContract();
            $c->fill(collect($data)->except('attachments')->all());
            $c->contract_number = StaffContract::nextNumber();
            $c->staff_id = $staff->id;
            $c->status = StaffContract::STATUS_ACTIVE;
            $c->renewed_from_id = $contract->id;
            $c->created_by_user_id = $request->user()->id;
            $c->save();
            $contract->forceFill(['status' => StaffContract::STATUS_RENEWED])->save();
            // the new terms become the staff member's current position and pay, when they were filled in
            $staff->forceFill(array_filter(['position' => $c->position, 'basic_salary' => $c->basic_salary]))->save();
            if ($c->basic_salary !== null) {
                $staff->forceFill(['net_salary' => round((float) $staff->basic_salary + (float) $staff->allowances - (float) $staff->deductions, 2)])->save();
            }

            return $c;
        });
        if ($request->hasFile('attachments')) {
            $new->saveAttachments($request->file('attachments'), 'contract');
        }
        StaffHistory::record($staff, 'contract_renewed', __('Contract :a renewed as :b', ['a' => $contract->contract_number, 'b' => $new->contract_number]), [
            'contract_id' => $new->id, 'occurred_on' => $new->start_date->toDateString(),
            'details' => ['from' => $contract->contract_number, 'to' => $new->contract_number, 'type' => $new->type, 'start_date' => $new->start_date->toDateString(), 'end_date' => optional($new->end_date)->toDateString()],
        ]);
        \App\Services\StaffNotifier::fire('contract_renewed', $staff, __('Contract renewed'), __('The contract of :s was renewed as :c by :n.', ['s' => $staff->name, 'c' => $new->contract_number, 'n' => $request->user()->name]), $request->user(), $new);
        log_activity(__('Staff contract renewed.'), ['staff' => $staff->staff_number, 'from' => $contract->contract_number, 'to' => $new->contract_number], $staff, 'Staff');

        return back()->with('message', __('Contract :a renewed as :b.', ['a' => $contract->contract_number, 'b' => $new->contract_number]));
    }

    /**
     * Stops an active contract because the person left or management ended it. A reason is required, the contract is closed as
     * "Ended" with the date, and (unless told otherwise) the staff member's status follows: Terminated, Inactive or Retired.
     * Everything is kept in the staff history.
     */
    public function stop(Request $request, Staff $staff, StaffContract $contract)
    {
        abort_unless($request->user()->can('stop-staff-contracts'), 403);
        $this->mine($staff, $contract);
        abort_unless($contract->status === StaffContract::STATUS_ACTIVE, 422, __('Only an active contract can be stopped.'));
        $data = $request->validate([
            'reason_type' => 'required|in:'.implode(',', array_keys(StaffHistory::STOP_REASONS)),
            'reason' => 'required|string|min:3|max:2000',
            'effective_date' => 'required|date|after_or_equal:'.optional($contract->start_date)->format('Y-m-d'),
            'staff_status' => 'required|in:keep,Terminated,Inactive,Retired,Retired Active',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|max:10240|mimes:'.attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ], [
            'attachments.max' => __('You can attach up to 3 documents.'),
            'reason.required' => __('Write the reason. It is kept in the staff history.'),
            'effective_date.after_or_equal' => __('The date cannot be before the contract started (:d).', ['d' => optional($contract->start_date)->format('d M Y')]),
        ]);

        $from = $staff->status;
        $data = collect($data)->except('attachments')->all();
        DB::transaction(function () use ($data, $contract, $staff, $request) {
            $contract->forceFill([
                'status' => StaffContract::STATUS_ENDED, 'end_date' => $data['effective_date'], 'stopped_on' => $data['effective_date'],
                'stopped_reason_type' => $data['reason_type'], 'stopped_reason' => $data['reason'], 'stopped_by_user_id' => $request->user()->id,
            ])->save();
            if ($data['staff_status'] !== 'keep') {
                $staff->forceFill(['status' => $data['staff_status']] + (in_array($data['staff_status'], ['Retired', 'Retired Active'], true) ? ['retired_at' => $data['effective_date']] : []))->save();
            }
        });

        // the letters / photos that go with stopping the contract (kept with the contract, marked as stop documents)
        if ($request->hasFile('attachments')) {
            $contract->saveAttachments($request->file('attachments'), 'stop');
        }

        StaffHistory::record($staff, 'contract_stopped', __('Contract :n stopped: :r', ['n' => $contract->contract_number, 'r' => __(StaffHistory::STOP_REASONS[$data['reason_type']])]), [
            'contract_id' => $contract->id, 'reason_type' => $data['reason_type'], 'reason' => $data['reason'], 'occurred_on' => $data['effective_date'],
            'details' => ['type' => $contract->type, 'start_date' => optional($contract->start_date)->format('Y-m-d'), 'documents_added' => $request->hasFile('attachments') ? count($request->file('attachments')) : 0],
        ]);
        if ($data['staff_status'] !== 'keep' && $from !== $data['staff_status']) {
            StaffHistory::record($staff, 'status_changed', __('Status changed: :a to :b', ['a' => $from, 'b' => $data['staff_status']]), [
                'contract_id' => $contract->id, 'reason_type' => $data['reason_type'], 'reason' => $data['reason'], 'occurred_on' => $data['effective_date'], 'from_status' => $from, 'to_status' => $data['staff_status'],
            ]);
        }
        \App\Services\StaffNotifier::fire('contract_stopped', $staff, __('Contract stopped'), __('Contract :c of :s was stopped by :n.', ['c' => $contract->contract_number, 's' => $staff->name, 'n' => $request->user()->name]), $request->user(), $contract, ['reason' => (string) $data['reason']], [__('Reason') => $data['reason']]);
        log_activity(__('Staff contract stopped.'), ['staff' => $staff->staff_number, 'contract' => $contract->contract_number, 'reason' => $data['reason_type']], $staff, 'Staff');

        return back()->with('message', __('Contract :n stopped.', ['n' => $contract->contract_number]).($data['staff_status'] !== 'keep' ? ' '.__(':n is now :s.', ['n' => $staff->name, 's' => $data['staff_status']]) : ''));
    }

    public function destroy(Request $request, Staff $staff, StaffContract $contract)
    {
        abort_unless($request->user()->can('delete-staff-contracts'), 403);
        $this->mine($staff, $contract);
        StaffHistory::record($staff, 'contract_deleted', __('Contract :n deleted', ['n' => $contract->contract_number]), ['contract_id' => $contract->id, 'details' => ['type' => $contract->type, 'start_date' => optional($contract->start_date)->format('Y-m-d'), 'end_date' => optional($contract->end_date)->format('Y-m-d')]]);
        \App\Services\StaffNotifier::fire('contract_deleted', $staff, __('Contract deleted'), __('Contract :c of :s was deleted by :n.', ['c' => $contract->contract_number, 's' => $staff->name, 'n' => $request->user()->name]), $request->user(), $contract);
        log_activity(__('Staff contract deleted.'), ['staff' => $staff->staff_number, 'contract' => $contract->contract_number], $staff, 'Staff');
        // the contract it replaced becomes the active one again
        if ($contract->renewed_from_id && $contract->status === StaffContract::STATUS_ACTIVE) {
            StaffContract::where('id', $contract->renewed_from_id)->where('status', StaffContract::STATUS_RENEWED)->update(['status' => StaffContract::STATUS_ACTIVE]);
        }
        $contract->delete();

        return back()->with('message', __('Contract :n deleted.', ['n' => $contract->contract_number]));
    }

    public function print(Request $request, Staff $staff, StaffContract $contract)
    {
        abort_unless($request->user()->can('print-staff-contracts'), 403);
        $this->mine($staff, $contract);

        return view('staffs.contract-print', $this->printData($staff, $contract, false));
    }

    public function pdf(Request $request, Staff $staff, StaffContract $contract)
    {
        abort_unless($request->user()->can('print-staff-contracts'), 403);
        $this->mine($staff, $contract);

        return Pdf::loadView('staffs.contract-print', $this->printData($staff, $contract, true))->download('contract-'.$contract->contract_number.'.pdf');
    }

    private function printData(Staff $staff, StaffContract $contract, bool $isPdf): array
    {
        $contract->loadMissing('renewedFrom', 'attachments');

        return [
            'photoData' => $staff->photo ? \App\Support\PhotoData::dataUri(Storage::disk('assets')->path($staff->photo), 240) : null,
            'staff' => ['name' => $staff->name, 'staff_number' => $staff->staff_number, 'position' => $staff->position, 'employment_type' => $staff->employment_type, 'mobile_no' => $staff->mobile_no, 'personal_email' => $staff->personal_email, 'status' => $staff->status],
            'contract' => $contract->present(true),
            'history' => $staff->contracts()->get()->map(fn ($c) => $c->present())->all(),
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'qr' => \App\Support\PrintQrCode::dataUri(route('staffs.show', $staff->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('staff_contract', $contract),
        ];
    }

    public function downloadAttachment(Request $request, Staff $staff, StaffContract $contract, Attachment $attachment)
    {
        abort_unless($request->user()->can('read-staff-contracts'), 403);
        $this->mine($staff, $contract);
        abort_unless((int) $attachment->model_id === (int) $contract->id && $attachment->model_type === StaffContract::class, 404);

        $name = $attachment->title ?: $attachment->filename;
        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $name, ['Content-Disposition' => 'inline; filename="'.$name.'"']);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $name);
    }

    public function destroyAttachment(Request $request, Staff $staff, StaffContract $contract, Attachment $attachment)
    {
        abort_unless($request->user()->can('update-staff-contracts'), 403);
        $this->mine($staff, $contract);
        abort_unless((int) $attachment->model_id === (int) $contract->id && $attachment->model_type === StaffContract::class, 404);

        $file = (string) $attachment->filename;
        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();
        \App\Services\StaffNotifier::fire('document_removed', $staff, __('Staff document removed'), __('The file :f of contract :c of :s was removed by :n.', ['f' => $file, 'c' => $contract->contract_number, 's' => $staff->name, 'n' => $request->user()->name]), $request->user(), $contract, ['file' => $file], [__('File') => $file]);

        return back()->with('message', __('Attachment removed.'));
    }
}
