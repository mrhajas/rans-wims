<?php

namespace App\Http\Controllers;

use App\Http\Resources\StaffCollection;
use App\Models\PaymentVoucher;
use App\Models\Staff;
use App\Models\StaffContract;
use App\Models\StaffHistory;
use Carbon\CarbonImmutable;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'status' => $request->query('status'),
            'employment_type' => $request->query('employment_type'),
            'contract' => $request->user()->can('read-staff-contracts') ? $request->query('contract') : null,
            'search' => trim((string) $request->query('search', '')) ?: null,
        ];

        $staffs = Staff::query()
            ->with(['currentContract', 'attachments', 'contracts.attachments'])
            ->filter($filters)
            ->orderBy('name')
            ->paginate()
            ->withQueryString()
            ->through(fn (Staff $s) => $this->presentSummary($s) + ['files' => $this->fileSummary($s, $request->user()->can('read-staff-contracts'))]);

        return Inertia::render('Staff/Index', [
            'filters' => $filters,
            'statuses' => Staff::STATUSES,
            'contractFilters' => $request->user()->can('read-staff-contracts') ? ['expiring', 'expired', 'none', 'permanent', 'open'] : [],
            'canUpdate' => $request->user()->can('update-staffs'),
            'canDelete' => $request->user()->can('delete-staffs'),
            'canPrint' => $request->user()->can('print-staffs'),
            'canRetire' => $request->user()->can('retire-staffs'),
            'canContracts' => $request->user()->can('read-staff-contracts'),
            'canRenew' => $request->user()->can('update-staff-contracts'),
            'employmentTypes' => Staff::EMPLOYMENT_TYPES,
            'staffs' => new StaffCollection($staffs),
            'cards' => (function () {
                $counts = Staff::selectRaw(
                    "count(*) as `total`, sum(status = 'Active') as `active`, sum(status = 'Inactive') as `inactive`, sum(status = 'Terminated') as `terminated`, sum(status = 'Retired') as `retired`, sum(status = 'Retired Active') as `retired_active`"
                )->first();
                $today = CarbonImmutable::today()->toDateString();
                $inScope = fn ($q) => $q->whereIn('status', Staff::PAYROLL_STATUSES); // contracts matter for people still working or paid

                return [
                    'total' => (int) $counts->total,
                    'active' => (int) $counts->active,
                    'inactive' => (int) $counts->inactive,
                    'terminated' => (int) $counts->terminated,
                    'retired' => (int) $counts->retired,
                    'retired_active' => (int) $counts->retired_active,
                    'expiring' => Staff::query()->tap($inScope)->whereHas('currentContract', fn ($q) => $q->whereBetween('end_date', [$today, CarbonImmutable::today()->addDays(StaffContract::SOON_DAYS)->toDateString()]))->count(),
                    'expired' => Staff::query()->tap($inScope)->whereHas('currentContract', fn ($q) => $q->where('end_date', '<', $today))->count(),
                    'no_contract' => Staff::query()->where('status', 'Active')->doesntHave('currentContract')->count(),
                    'permanent' => Staff::query()->tap($inScope)->whereHas('currentContract', fn ($q) => $q->where('type', 'Permanent'))->count(),
                ];
            })(),
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('print-staffs');

        return view('staffs-list-print', $this->listPayload($request));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('print-staffs');

        return Pdf::loadView('staffs-list-print', $this->listPayload($request))
            ->download('staffs-' . now()->format('Ymd-His') . '.pdf');
    }

    /**
     * Same filters as index() — prints exactly the filtered/searched result
     * set the user has on screen, not the entire staff list.
     */
    private function listPayload(Request $request): array
    {
        $filters = [
            'status' => $request->query('status'),
            'employment_type' => $request->query('employment_type'),
            'contract' => $request->query('contract'),
            'search' => trim((string) $request->query('search', '')) ?: null,
        ];

        $staffs = Staff::query()
            ->with('currentContract')
            ->filter($filters)
            ->orderBy('name')
            ->get()
            ->map(fn (Staff $s) => $this->presentSummary($s) + ['thumb' => $s->photo ? \App\Support\PhotoData::dataUri(Storage::disk('assets')->path($s->photo), 90) : null]);

        return [
            'staffs' => $staffs,
            'printedBy' => $request->user()->name,
            'printedAt' => now()->format('Y-m-d H:i'),
            'logoPath' => file_exists(public_path('images/logo.png')) ? public_path('images/logo.png') : null,
        ];
    }

    public function create()
    {
        return Inertia::render('Staff/Form', [
            'staff' => null,
            'users' => $this->linkableUsers(null),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $staff = new Staff();
        $staff->fill($data);
        $staff->staff_number = $this->nextReference();
        $staff->created_by_user_id = $request->user()->id;

        if ($request->hasFile('photo')) {
            $staff->photo = $request->file('photo')->store('staffs', 'assets');
        }

        $staff->save();

        if ($request->hasFile('attachments')) {
            $staff->saveAttachments($request->file('attachments'), 'staff');
        }

        StaffHistory::record($staff, 'staff_created', __('Staff record created'), ['occurred_on' => optional($staff->join_date)->format('Y-m-d') ?: today()->toDateString(), 'to_status' => $staff->status, 'details' => ['join_date' => optional($staff->join_date)->format('Y-m-d'), 'position' => $staff->position]]);
        log_activity(__('Staff record created.'), ['staff_number' => $staff->staff_number], $staff, 'Staff');
        \App\Services\StaffNotifier::fire('created', $staff, __('Staff added'), $staff->name.' ('.$staff->staff_number.') '.__('was added by :n.', ['n' => $request->user()->name]), $request->user());

        return redirect()->route('staffs.show', $staff->id)->with('message', __('Staff record created.'));
    }

    public function show($id)
    {
        $staff = Staff::with(['attachments', 'contracts.attachments', 'contracts.renewedFrom'])->findOrFail($id);

        $vouchers = PaymentVoucher::where('staff_id', $staff->id)
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'pv_number', 'voucher_date', 'total_amount', 'status', 'type'])
            ->map(fn (PaymentVoucher $v) => [
                'id' => $v->id,
                'pv_number' => $v->pv_number,
                'voucher_date' => optional($v->voucher_date)->format('Y-m-d'),
                'total_amount' => (float) $v->total_amount,
                'status' => $v->status,
                'type' => $v->type,
            ]);

        return Inertia::render('Staff/Show', [
            'staff' => $this->presentDetail($staff),
            'paymentVouchers' => $vouchers,
            'paymentVouchersSummary' => [
                'count' => $vouchers->count(),
                'total' => (float) $vouchers->sum('total_amount'),
            ],
            'history' => request()->user()->can('read-staff-contracts') ? StaffHistory::query()->where('staff_id', $staff->id)->with('user:id,name')->orderByDesc('occurred_on')->orderByDesc('id')->get()->map->present()->values() : [],
            'stopReasons' => StaffHistory::STOP_REASONS,
            'canContractStop' => request()->user()->can('stop-staff-contracts'),
            'contracts' => request()->user()->can('read-staff-contracts') ? $staff->contracts->map(fn (StaffContract $c) => $c->present(true))->values() : [],
            'contractTypes' => StaffContract::TYPES,
            'contractRules' => ['soon' => StaffContract::SOON_DAYS, 'urgent' => StaffContract::URGENT_DAYS],
            'canUpdate' => request()->user()->can('update-staffs'),
            'canDelete' => request()->user()->can('delete-staffs'),
            'canPrint' => request()->user()->can('print-staffs'),
            'canContracts' => request()->user()->can('read-staff-contracts'),
            'canContractAdd' => request()->user()->can('create-staff-contracts'),
            'canContractEdit' => request()->user()->can('update-staff-contracts'),
            'canContractDelete' => request()->user()->can('delete-staff-contracts'),
            'canContractPrint' => request()->user()->can('print-staff-contracts'),
            'canRetire' => request()->user()->can('retire-staffs'),
        ]);
    }

    public function edit($id)
    {
        $staff = Staff::with('attachments')->findOrFail($id);

        return Inertia::render('Staff/Form', [
            'staff' => $this->presentDetail($staff),
            'users' => $this->linkableUsers($staff),
        ]);
    }

    public function update(Request $request, $id)
    {
        $staff = Staff::findOrFail($id);
        $data = $this->validated($request);

        if ($request->hasFile('photo')) {
            if ($staff->photo) {
                Storage::disk('assets')->delete($staff->photo);
            }
            $data['photo'] = $request->file('photo')->store('staffs', 'assets');
        }

        $statusBefore = $staff->status;
        $payBefore = $staff->only(['basic_salary', 'allowances', 'deductions']);
        $staff->update($data);
        if ($staff->status !== $statusBefore) {
            StaffHistory::record($staff, 'status_changed', __('Status changed: :a to :b', ['a' => $statusBefore, 'b' => $staff->status]), ['from_status' => $statusBefore, 'to_status' => $staff->status, 'details' => ['via' => 'edit form']]);
        }

        if ($request->hasFile('attachments')) {
            $staff->saveAttachments($request->file('attachments'), 'staff');
        }

        log_activity(__('Staff record updated.'), ['staff_number' => $staff->staff_number], $staff, 'Staff');
        $who = $request->user();
        \App\Services\StaffNotifier::fire('updated', $staff, __('Staff updated'), $staff->name.' ('.$staff->staff_number.') '.__('was updated by :n.', ['n' => $who->name]), $who);
        if ($staff->status !== $statusBefore) {
            \App\Services\StaffNotifier::fire('status_changed', $staff, __('Staff status changed'), $staff->name.' '.__('is now :s. Changed by :n.', ['s' => $staff->status, 'n' => $who->name]), $who, null, [], [__('From') => $statusBefore, __('To') => $staff->status]);
        }
        if ($staff->only(['basic_salary', 'allowances', 'deductions']) != $payBefore) {
            \App\Services\StaffNotifier::fire('salary_changed', $staff, __('Staff pay changed'), __('The pay of :s was changed by :n.', ['s' => $staff->name, 'n' => $who->name]), $who);
        }

        return redirect()->route('staffs.show', $id)->with('message', __('Staff record updated.'));
    }

    /**
     * Removing a staff record is deliberate and rarely undone, so it needs the signed-in admin's own
     * password in the request itself (not a "recently confirmed" session flag a forgotten tab could reuse).
     */
    public function destroy(Request $request, $id)
    {
        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            return back()->withErrors(['password' => __('The password you entered is incorrect.')]);
        }

        $staff = Staff::findOrFail($id);

        // Anything that carries this person's money history keeps pointing at them, so it blocks deletion.
        $blockers = collect([
            __('payment vouchers') => $staff->paymentVouchers()->exists(),
            __('salary records') => \App\Models\SalaryRecord::where('staff_id', $staff->id)->exists(),
            __('salary advances') => \App\Models\SalaryAdvance::where('staff_id', $staff->id)->exists(),
            __('salary loans') => \App\Models\SalaryLoan::where('staff_id', $staff->id)->exists(),
            __('salary opening balances') => \App\Models\SalaryOpeningBalance::where('staff_id', $staff->id)->exists(),
        ])->filter()->keys();

        if ($blockers->isNotEmpty()) {
            return back()->with('error', __('This staff member has :records on record and cannot be deleted.', ['records' => $blockers->implode(', ')]));
        }

        log_activity(__('Staff record deleted.'), ['staff_number' => $staff->staff_number, 'name' => $staff->name], $staff, 'Staff');
        $staff->delete();
        \App\Services\StaffNotifier::fire('deleted', $staff, __('Staff deleted'), $staff->name.' ('.$staff->staff_number.') '.__('was deleted by :n.', ['n' => $request->user()->name]), $request->user());

        return redirect()->route('staffs.index')->with('message', __('Staff record removed.'));
    }

    public function destroyPhoto($id)
    {
        $this->authorize('update-staffs');

        $staff = Staff::findOrFail($id);
        if ($staff->photo && Storage::disk('assets')->delete($staff->photo)) {
            $staff->update(['photo' => null]);
            \App\Services\StaffNotifier::fire('photo_removed', $staff, __('Staff photo removed'), __('The photo of :s (:no) was removed by :n.', ['s' => $staff->name, 'no' => $staff->staff_number, 'n' => auth()->user()?->name]), auth()->user());

            return back()->with('message', __('Staff photo removed.'));
        }

        return back()->with('error', __('The photo could not be removed.'));
    }

    /** Marks somebody retired: "Retired" (off payroll) or "Retired Active" (retired but still paid through payroll). */
    public function retire(Request $request, Staff $staff)
    {
        $this->authorize('retire-staffs');
        $data = $request->validate([
            'mode' => 'required|in:Retired,Retired Active',
            'retired_at' => 'required|date',
            'note' => 'nullable|string|max:2000',
            'end_contract' => 'sometimes|boolean',
        ]);

        $from = $staff->status;
        $staff->forceFill(['status' => $data['mode'], 'retired_at' => $data['retired_at'], 'retirement_note' => $data['note'] ?? null])->save();
        StaffHistory::record($staff, 'retired', $data['mode'] === 'Retired Active' ? __('Retired (still on payroll)') : __('Retired (off payroll)'), ['occurred_on' => $data['retired_at'], 'reason_type' => 'Retired', 'reason' => $data['note'] ?? null, 'from_status' => $from, 'to_status' => $data['mode']]);
        if ($request->boolean('end_contract', true)) {
            $staff->contracts()->where('status', StaffContract::STATUS_ACTIVE)->get()->each(function (StaffContract $c) use ($data) {
                $end = $c->end_date && $c->end_date->lte(CarbonImmutable::parse($data['retired_at'])) ? $c->end_date : CarbonImmutable::parse($data['retired_at']);
                $c->forceFill(['status' => StaffContract::STATUS_ENDED, 'end_date' => $end->toDateString(), 'stopped_on' => $data['retired_at'], 'stopped_reason_type' => 'Retired', 'stopped_reason' => $data['note'] ?? null, 'stopped_by_user_id' => auth()->id()])->save();
                StaffHistory::record($c->staff()->first(), 'contract_stopped', __('Contract :n ended at retirement', ['n' => $c->contract_number]), ['contract_id' => $c->id, 'reason_type' => 'Retired', 'reason' => $data['note'] ?? null, 'occurred_on' => $data['retired_at']]);
            });
        }
        log_activity(__('Staff marked :s.', ['s' => $data['mode']]), ['staff_number' => $staff->staff_number, 'retired_at' => $data['retired_at']], $staff, 'Staff');
        \App\Services\StaffNotifier::fire('retired', $staff, __('Staff retired'), $staff->name.' ('.$staff->staff_number.') '.__('is now :s. Marked by :n.', ['s' => $data['mode'], 'n' => $request->user()->name]), $request->user(), null, [], [__('Retired on') => CarbonImmutable::parse($data['retired_at'])->format('d M Y'), __('Note') => $data['note'] ?? null]);

        return back()->with('message', $data['mode'] === 'Retired Active'
            ? __(':n is now Retired Active: retired, and still on payroll.', ['n' => $staff->name])
            : __(':n is now Retired and is no longer on payroll.', ['n' => $staff->name]));
    }

    /** Puts a retired person back to Active (for example when they are re-engaged). */
    public function reinstate(Request $request, Staff $staff)
    {
        $this->authorize('retire-staffs');
        abort_unless(in_array($staff->status, ['Retired', 'Retired Active'], true), 422, __('Only a retired person can be reinstated.'));
        $from = $staff->status;
        $staff->forceFill(['status' => 'Active', 'retired_at' => null, 'retirement_note' => null])->save();
        StaffHistory::record($staff, 'reinstated', __('Reinstated: Active again'), ['from_status' => $from, 'to_status' => 'Active']);
        log_activity(__('Staff reinstated.'), ['staff_number' => $staff->staff_number], $staff, 'Staff');
        \App\Services\StaffNotifier::fire('reinstated', $staff, __('Staff reinstated'), $staff->name.' ('.$staff->staff_number.') '.__('is Active again. Done by :n.', ['n' => $request->user()->name]), $request->user());

        return back()->with('message', __(':n is Active again.', ['n' => $staff->name]));
    }

    public function print(Request $request, $id)
    {
        $this->authorize('print-staffs');

        $staff = Staff::with('attachments')->findOrFail($id);

        return view('staffs.print', $this->printData($staff, false));
    }

    public function pdf(Request $request, $id)
    {
        $this->authorize('print-staffs');

        $staff = Staff::with('attachments')->findOrFail($id);

        return Pdf::loadView('staffs.print', $this->printData($staff, true))
            ->download('staff-' . $staff->staff_number . '.pdf');
    }

    private function printData(Staff $staff, bool $isPdf): array
    {
        $can = auth()->user()?->can('read-staff-contracts');
        $contracts = $can ? $staff->contracts()->with('renewedFrom')->get()->map(fn (StaffContract $c) => $c->present())->all() : [];
        $until = $staff->retired_at && in_array($staff->status, ['Retired', 'Retired Active'], true) ? CarbonImmutable::parse($staff->retired_at) : CarbonImmutable::today();
        $service = null;
        if ($staff->join_date && CarbonImmutable::parse($staff->join_date)->lte($until)) {
            $d = CarbonImmutable::parse($staff->join_date)->diff($until);
            $service = trim(($d->y ? $d->y.' yr ' : '').($d->m ? $d->m.' mo' : ($d->y ? '' : $d->d.' days')));
        }

        return [
            'staff' => $this->presentDetail($staff),
            'photoUrl' => $staff->photo ? Storage::disk('assets')->path($staff->photo) : null,
            'photoData' => $staff->photo ? \App\Support\PhotoData::dataUri(Storage::disk('assets')->path($staff->photo), 420) : null,
            'contracts' => $contracts,
            'history' => $can ? StaffHistory::query()->where('staff_id', $staff->id)->with('user:id,name')->orderByDesc('occurred_on')->orderByDesc('id')->get()->map->present()->all() : [],
            'service' => $service,
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'qr' => \App\Support\PrintQrCode::dataUri(route('staffs.show', $staff->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('staff_profile', \App\Services\Signatories\Documents\StaffProfileDocument::record(auth()->user(), now(), $staff->staff_number)),
        ];
    }

    /**
     * Which documents a person has, for the paper-clip in the Contract column of the staff list: the files under Details > Attachments, and the
     * documents attached to their contracts (Contracts tab). Names only; opening one still goes through the page's own permission checks.
     */
    private function fileSummary(Staff $s, bool $withContracts): array
    {
        $name = fn (Attachment $a) => \Illuminate\Support\Str::limit((string) ($a->title ?: $a->filename), 38);
        $out = ['details' => $s->attachments->map($name)->values()->all(), 'contracts' => []];
        if ($withContracts) {
            foreach ($s->contracts as $c) {
                if ($c->attachments->isNotEmpty()) {
                    $out['contracts'][] = ['number' => $c->contract_number, 'files' => $c->attachments->map($name)->values()->all()];
                }
            }
        }

        return $out;
    }

    public function downloadAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('read-staffs');

        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === Staff::class, 404);

        $name = $attachment->title ?: $attachment->filename;

        // ?disposition=inline lets the browser render the file directly
        // (used by the preview/zoom UI) instead of forcing a download dialog.
        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $name, [
                'Content-Disposition' => 'inline; filename="' . $name . '"',
            ]);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $name);
    }

    public function destroyAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('update-staffs');

        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === Staff::class, 404);

        $file = (string) $attachment->filename;
        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();
        if ($staff = Staff::find($id)) {
            \App\Services\StaffNotifier::fire('document_removed', $staff, __('Staff document removed'), __('The file :f of :s (:no) was removed by :n.', ['f' => $file, 's' => $staff->name, 'no' => $staff->staff_number, 'n' => $request->user()->name]), $request->user(), null, ['file' => $file], [__('File') => $file]);
        }

        return back()->with('message', __('Attachment removed.'));
    }

    private function validated(Request $request): array
    {
        $staffId = $request->route('staff');

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'user_id' => ['nullable', 'integer', 'exists:users,id', \Illuminate\Validation\Rule::unique('staffs', 'user_id')->ignore($staffId)],
            'qualification' => 'nullable|string|max:190',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:' . implode(',', Staff::GENDERS),
            'employment_type' => 'nullable|in:' . implode(',', Staff::EMPLOYMENT_TYPES),
            'address' => 'nullable|string|max:2000',
            'personal_email' => 'nullable|email|max:190',
            'mobile_no' => ['nullable', 'string', 'max:50', new \App\Rules\TzMobile],
            'father_name' => 'nullable|string|max:190',
            'father_contact_no' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:190',
            'account_holder_name' => 'nullable|string|max:190',
            'account_no' => 'nullable|string|max:100',
            'tin_number' => 'nullable|string|max:100',
            'nssf_number' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:190',
            'join_date' => 'nullable|date',
            'basic_salary' => 'nullable|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'status' => 'required|in:' . implode(',', Staff::STATUSES),
            'photo' => 'nullable|image|mimes:png,jpg,jpeg,svg|max:5120',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $basic = (float) ($data['basic_salary'] ?? 0);
        $allowances = (float) ($data['allowances'] ?? 0);
        $deductions = (float) ($data['deductions'] ?? 0);

        $data['basic_salary'] = $basic;
        $data['allowances'] = $allowances;
        $data['deductions'] = $deductions;
        $data['net_salary'] = round($basic + $allowances - $deductions, 2);

        unset($data['photo'], $data['attachments']);

        return $data;
    }

    /** Logins that can still be tied to a staff profile (not linked elsewhere), plus this profile's current one. */
    private function linkableUsers(?Staff $staff)
    {
        return \App\Models\User::ofAccount()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereDoesntHave('staff')->when($staff?->user_id, fn ($q2) => $q2->orWhere('id', $staff->user_id)))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name . ' (' . $u->email . ')'])
            ->values();
    }

    private function nextReference(): string
    {
        $prefixLength = strlen('STF-') + 1;

        $maxSuffix = (int) DB::table('staffs')
            ->selectRaw("MAX(CAST(SUBSTRING(staff_number, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where('staff_number', 'like', 'STF-%')
            ->value('max_suffix');

        return 'STF-' . str_pad((string) ($maxSuffix + 1), 5, '0', STR_PAD_LEFT);
    }

    private static array $userNameCache = [];

    private function userName(?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return self::$userNameCache[$id] ??= User::find($id)?->name;
    }

    private function presentSummary(Staff $staff): array
    {
        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'name' => $staff->name,
            'photo_url' => $staff->photo ? Storage::disk('assets')->url($staff->photo) : null,
            'position' => $staff->position,
            'employment_type' => $staff->employment_type,
            'mobile_no' => $staff->mobile_no,
            'status' => $staff->status,
            'retired_at' => optional($staff->retired_at)->format('Y-m-d'),
            'net_salary' => (float) $staff->net_salary,
            'contract' => $this->contractSummary($staff),
        ];
    }

    /** The contract in force, in the few words the list shows: when it ends and how urgent that is. */
    private function contractSummary(Staff $staff): ?array
    {
        if (! auth()->user()?->can('read-staff-contracts')) {
            return null;
        }
        $c = $staff->relationLoaded('currentContract') ? $staff->currentContract : $staff->currentContract()->first();
        if (! $c) {
            return null;
        }

        return ['id' => $c->id, 'number' => $c->contract_number, 'type' => $c->type, 'start_date' => optional($c->start_date)->format('Y-m-d'), 'end_date' => optional($c->end_date)->format('Y-m-d'), 'days_left' => $c->daysLeft(), 'state' => $c->state()];
    }

    private function presentDetail(Staff $staff): array
    {
        return array_merge($this->presentSummary($staff), [
            'qualification' => $staff->qualification,
            'date_of_birth' => optional($staff->date_of_birth)->format('Y-m-d'),
            'gender' => $staff->gender,
            'address' => $staff->address,
            'personal_email' => $staff->personal_email,
            'father_name' => $staff->father_name,
            'father_contact_no' => $staff->father_contact_no,
            'bank_name' => $staff->bank_name,
            'account_holder_name' => $staff->account_holder_name,
            'account_no' => $staff->account_no,
            'tin_number' => $staff->tin_number,
            'nssf_number' => $staff->nssf_number,
            'join_date' => optional($staff->join_date)->format('Y-m-d'),
            'user_id' => $staff->user_id,
            'user' => $staff->user ? ['id' => $staff->user->id, 'name' => $staff->user->name, 'email' => $staff->user->email, 'url' => auth()->user()->can('update-users') ? route('users.edit', $staff->user->id) : null] : null,
            'basic_salary' => (float) $staff->basic_salary,
            'allowances' => (float) $staff->allowances,
            'deductions' => (float) $staff->deductions,
            'retirement_note' => $staff->retirement_note,
            'created_by_name' => $this->userName($staff->created_by_user_id),
            'created_at' => optional($staff->created_at)->toIso8601String(),
            'attachments' => $staff->relationLoaded('attachments')
                ? $staff->attachments->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'name' => $a->title ?: $a->filename,
                    'is_image' => str_starts_with((string) $a->filetype, 'image/'),
                    'is_pdf' => str_contains((string) $a->filetype, 'pdf'),
                    'mime' => (string) $a->filetype,
                    'preview_url' => route('staffs.attachments.download', [$staff->id, $a->id]) . '?disposition=inline',
                    'download_url' => route('staffs.attachments.download', [$staff->id, $a->id]),
                    'delete_url' => route('staffs.attachments.destroy', [$staff->id, $a->id]),
                ])->values()
                : [],
        ]);
    }
}
