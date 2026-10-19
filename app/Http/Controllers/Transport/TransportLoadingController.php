<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\TransportLoadingAttendance;
use App\Models\TransportLoadingOpeningBalance;
use App\Models\TransportLoadingPayment;
use App\Models\TransportLoadingPlace;
use App\Models\Warehouse;
use App\Services\Transport\LoadingLedger;
use App\Services\Transport\LoadingVoucherService;
use App\Support\PaymentVoucherNumbers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Transport Routes > Loading & Unloading. Works like an attendance sheet: pick a day, mark each loading / unloading
 * worker (a Contact in the "Loading & UnLoading" category) Present or Absent, and every Present day earns the daily rate.
 * Payments are recorded beside it and the balance is earned minus paid. Permission checks are explicit (setAbilities([])).
 */
class TransportLoadingController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-transport-loading');

        // Balances & payments is the page people come for first; the daily attendance is the second tab.
        $tab = $request->query('tab') === 'attendance' ? 'attendance' : 'balances';
        $date = $this->validDate($request->query('date')) ?: now()->toDateString();
        $from = $this->validDate($request->query('date_from')) ?: now()->startOfMonth()->toDateString();
        $to = $this->validDate($request->query('date_to')) ?: now()->toDateString();
        $warehouseId = $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null;

        $workerIds = LoadingLedger::workerIds();
        $todayRows = TransportLoadingAttendance::query()->whereDate('work_date', $date)->whereIn('contact_id', $workerIds ?: [0])->get()->keyBy('contact_id');
        $balances = LoadingLedger::balances($workerIds);

        $props = [
            'tab' => $tab,
            'filters' => ['date' => $date, 'date_from' => $from, 'date_to' => $to, 'warehouse_id' => $warehouseId],
            'rate' => LoadingLedger::rate(),
            'projects' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'places' => TransportLoadingPlace::names(),
            // Where the day's saved marks were worked, so the two pickers open already filled in.
            'dayWhere' => [
                'warehouse_id' => $todayRows->pluck('warehouse_id')->filter()->countBy()->sortDesc()->keys()->first(),
                'other_place' => $todayRows->pluck('other_place')->filter()->countBy()->sortDesc()->keys()->first(),
            ],
            'category' => Contact::LOADING_CATEGORY,
            'stats' => [
                'workers' => count($workerIds),
                'present' => $todayRows->where('status', TransportLoadingAttendance::PRESENT)->count(),
                'earned_day' => (float) $todayRows->sum('amount'),
                'balance' => round((float) array_sum($balances), 2),
            ],
            'can' => [
                'report' => $request->user()->can('read-transport-loading-reports'),
                'voucher' => $request->user()->can('create-payment-voucher-from-transport'),
                'create' => $request->user()->can('create-transport-loading'),
                'update' => $request->user()->can('update-transport-loading'),
                'delete' => $request->user()->can('delete-transport-loading'),
                'pay' => $request->user()->can('pay-transport-loading'),
                'opening' => $request->user()->can('pay-transport-loading'),
                'print' => $request->user()->can('print-transport-loading-reports'),
                'export' => $request->user()->can('export-transport-loading-reports'),
            ],
        ];

        if ($tab === 'attendance') {
            $props['workers'] = Contact::query()->whereIn('id', $workerIds ?: [0])->orderBy('name')->get(['id', 'name', 'phone'])->map(function (Contact $c) use ($todayRows, $balances) {
                $row = $todayRows->get($c->id);

                return [
                    'contact_id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'balance' => $balances[$c->id] ?? 0,
                    'attendance_id' => $row?->id,
                    'status' => $row?->status,
                    'daily_rate' => $row ? (float) $row->daily_rate : null,
                    'standard_rate' => $row ? (float) ($row->standard_rate ?? $row->daily_rate) : null,
                    'warehouse_id' => $row?->warehouse_id,
                    'other_place' => $row?->other_place,
                ];
            })->values();
        } else {
            $rows = LoadingLedger::summary($from, $to, $warehouseId);
            $props['rows'] = $rows;
            $props['totals'] = LoadingLedger::totals($rows);
        }

        return Inertia::render('TransportManagement/Loading/Index', $props);
    }

    /** Saves one day's marks. New marks need create; changing a mark that already exists needs update. */
    public function storeAttendance(Request $request)
    {
        $this->authorize('create-transport-loading');
        $user = $request->user();

        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            // Worked somewhere that is not a project: a typed or picked place, kept for next time.
            'other_place' => 'nullable|string|max:80',
            'entries' => 'required|array|min:1|max:500',
            'entries.*.contact_id' => 'required|integer|exists:contacts,id',
            'entries.*.status' => ['required', Rule::in(TransportLoadingAttendance::STATUSES)],
            // The day's own rate; left out = the standard daily rate (Transport Settings).
            'entries.*.daily_rate' => 'nullable|numeric|min:0|max:10000000',
        ]);

        if (! empty($data['warehouse_id']) && TransportLoadingPlace::clean($data['other_place'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['other_place' => __('Choose a Project / site or another place, not both.')]);
        }
        $place = TransportLoadingPlace::remember($data['other_place'] ?? null, $user->id);

        $rate = LoadingLedger::rate();
        $allowed = array_flip(LoadingLedger::workerIds());
        $created = 0;
        $changed = 0;

        DB::transaction(function () use ($data, $rate, $allowed, $user, $place, &$created, &$changed) {
            foreach ($data['entries'] as $entry) {
                abort_unless(isset($allowed[(int) $entry['contact_id']]), 422, __('That person is not a Loading & Unloading worker.'));

                $existing = TransportLoadingAttendance::query()->where('contact_id', $entry['contact_id'])->whereDate('work_date', $data['date'])->lockForUpdate()->first();
                $present = $entry['status'] === TransportLoadingAttendance::PRESENT;
                $given = array_key_exists('daily_rate', $entry) && $entry['daily_rate'] !== null && $entry['daily_rate'] !== '' ? round((float) $entry['daily_rate'], 2) : null;

                if ($existing) {
                    $rateChanged = $given !== null && abs($given - (float) $existing->daily_rate) > 0.001;
                    if ($existing->status === $entry['status'] && ! $rateChanged && (int) $existing->warehouse_id === (int) ($data['warehouse_id'] ?? 0) && (string) $existing->other_place === (string) $place) {
                        continue;
                    }
                    abort_unless($user->can('update-transport-loading'), 403, __('You are not permitted to change attendance that was already saved.'));
                    // A day keeps the rate it was earned at unless a new rate is given; a day that earned nothing before takes the standard rate.
                    $dayRate = $given ?? ((float) $existing->daily_rate > 0 ? (float) $existing->daily_rate : $rate);
                    $existing->update([
                        'status' => $entry['status'],
                        'daily_rate' => $dayRate,
                        'standard_rate' => $existing->standard_rate ?? $rate,
                        'amount' => $present ? $dayRate : 0,
                        'warehouse_id' => $data['warehouse_id'] ?? null,
                        'other_place' => $place,
                        'recorded_by_user_id' => $user->id,
                    ]);
                    $changed++;
                } else {
                    $dayRate = $given ?? $rate;
                    TransportLoadingAttendance::create([
                        'contact_id' => $entry['contact_id'],
                        'work_date' => $data['date'],
                        'status' => $entry['status'],
                        'daily_rate' => $dayRate,
                        'standard_rate' => $rate,
                        'amount' => $present ? $dayRate : 0,
                        'warehouse_id' => $data['warehouse_id'] ?? null,
                        'other_place' => $place,
                        'recorded_by_user_id' => $user->id,
                    ]);
                    $created++;
                }
            }
        });

        $custom = collect($data['entries'])->filter(fn ($e) => isset($e['daily_rate']) && $e['daily_rate'] !== '' && abs((float) $e['daily_rate'] - $rate) > 0.001)->count();
        log_activity(__('Loading & Unloading attendance saved.'), ['date' => $data['date'], 'new' => $created, 'changed' => $changed, 'standard_rate' => $rate, 'custom_rate_entries' => $custom], $user, 'Transport Loading');

        $this->told(__('Loading & Unloading Attendance'), __('Attendance for :date was saved by :user (:n worker(s)).', ['date' => $data['date'], 'user' => $user->name, 'n' => $created + $changed]));

        return back()->with('message', __('Attendance saved for :date (:n worker(s)).', ['date' => $data['date'], 'n' => $created + $changed]));
    }

    public function destroyAttendance(Request $request, TransportLoadingAttendance $attendance)
    {
        $this->authorize('delete-transport-loading');

        $info = ['contact_id' => $attendance->contact_id, 'date' => $attendance->work_date->toDateString(), 'status' => $attendance->status, 'amount' => $attendance->amount];
        $attendance->delete();
        log_activity(__('Loading & Unloading attendance removed.'), $info, $request->user(), 'Transport Loading');

        $this->told(__('Loading & Unloading Attendance Removed'), __('An attendance entry of :date was removed by :user.', ['date' => $info['date'], 'user' => $request->user()->name]));

        return back()->with('message', __('Attendance removed.'));
    }

    public function show(Request $request, Contact $contact)
    {
        $this->authorize('read-transport-loading');
        abort_unless(in_array($contact->id, LoadingLedger::workerIds(), true), 404);

        $from = $this->validDate($request->query('date_from'));
        $to = $this->validDate($request->query('date_to'));

        $attendance = TransportLoadingAttendance::query()->where('contact_id', $contact->id)
            ->when($from, fn ($q) => $q->whereDate('work_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('work_date', '<=', $to))
            ->with('warehouse:id,name')->orderByDesc('work_date')->limit(400)->get()
            ->map(fn ($a) => ['id' => $a->id, 'date' => $a->work_date->toDateString(), 'status' => $a->status, 'rate' => $a->daily_rate, 'standard' => (float) ($a->standard_rate ?? $a->daily_rate), 'amount' => $a->amount, 'project' => $a->warehouse?->name ?? $a->other_place]);

        $payments = TransportLoadingPayment::query()->where('contact_id', $contact->id)
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->with(['paidBy:id,name', 'voucher:id,pv_number,status,paid_at'])->orderByDesc('payment_date')->orderByDesc('id')->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'date' => $p->payment_date->toDateString(), 'amount' => $p->amount, 'method' => $p->method, 'reference' => $p->reference,
                'notes' => $p->notes, 'by' => $p->paidBy?->name, 'status' => $p->status,
                'voucher_id' => $p->payment_voucher_id, 'voucher' => $p->voucher?->pv_number, 'voucher_status' => $p->voucher?->display_status,
            ]);

        $earned = (float) TransportLoadingAttendance::where('contact_id', $contact->id)->sum('amount');
        $paid = (float) TransportLoadingPayment::paid()->where('contact_id', $contact->id)->sum('amount');
        $pending = LoadingLedger::pendingOf($contact->id);
        $opening = TransportLoadingOpeningBalance::with('recordedBy:id,name')->where('contact_id', $contact->id)->first();
        $previous = (float) ($opening->amount ?? 0);

        return Inertia::render('TransportManagement/Loading/Show', [
            'worker' => ['id' => $contact->id, 'name' => $contact->name, 'phone' => $contact->phone],
            'filters' => ['date_from' => $from, 'date_to' => $to],
            'attendance' => $attendance->values(),
            'payments' => $payments->values(),
            'totals' => [
                'days' => (int) TransportLoadingAttendance::where('contact_id', $contact->id)->where('status', TransportLoadingAttendance::PRESENT)->count(),
                'opening' => round($previous, 2),
                'earned' => round($earned, 2),
                'paid' => round($paid, 2),
                'balance' => round($previous + $earned - $paid, 2),
                'pending' => $pending,
                'available' => round($previous + $earned - $paid - $pending, 2),
            ],
            'opening' => $opening ? ['amount' => (float) $opening->amount, 'as_of_date' => $opening->as_of_date->toDateString(), 'notes' => $opening->notes, 'by' => $opening->recordedBy?->name] : null,
            'rate' => LoadingLedger::rate(),
            'can' => [
                'print' => $request->user()->can('print-transport-loading-reports'),
                'voucher' => $request->user()->can('create-payment-voucher-from-transport'),
                'pay' => $request->user()->can('pay-transport-loading'),
                'opening' => $request->user()->can('pay-transport-loading'),
                'delete' => $request->user()->can('delete-transport-loading'),
            ],
        ]);
    }

    /** Printable statement of one person (attendance, payments, balance) for the chosen period; needs the print permission. */
    public function printStatement(Request $request, Contact $contact)
    {
        $this->authorize('print-transport-loading-reports');

        return view('reports.transport-loading-statement', $this->statementData($request, $contact, false));
    }

    public function pdfStatement(Request $request, Contact $contact)
    {
        $this->authorize('print-transport-loading-reports');

        @ini_set('memory_limit', '512M');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.transport-loading-statement', $this->statementData($request, $contact, true))
            ->setPaper('a4')
            ->download('loading-statement-' . \Illuminate\Support\Str::slug($contact->name) . '-' . now()->format('Ymd-His') . '.pdf');
    }

    private function statementData(Request $request, Contact $contact, bool $isPdf): array
    {
        abort_unless(in_array($contact->id, LoadingLedger::workerIds(), true), 404);
        $from = $this->validDate($request->query('date_from'));
        $to = $this->validDate($request->query('date_to'));

        $attendance = TransportLoadingAttendance::query()->where('contact_id', $contact->id)->with('warehouse:id,name')
            ->when($from, fn ($q) => $q->whereDate('work_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('work_date', '<=', $to))
            ->orderBy('work_date')->limit(2000)->get();
        $payments = TransportLoadingPayment::query()->where('contact_id', $contact->id)->with(['paidBy:id,name', 'voucher:id,pv_number,status,paid_at'])
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->orderBy('payment_date')->orderBy('id')->get();

        $earnedAll = (float) TransportLoadingAttendance::where('contact_id', $contact->id)->sum('amount');
        $paidAll = (float) TransportLoadingPayment::paid()->where('contact_id', $contact->id)->sum('amount');
        $pending = LoadingLedger::pendingOf($contact->id);
        $openingRow = TransportLoadingOpeningBalance::where('contact_id', $contact->id)->first();
        $previous = (float) ($openingRow->amount ?? 0);

        log_activity(__('Loading & Unloading statement :action.', ['action' => $isPdf ? 'downloaded as PDF' : 'printed']), ['contact_id' => $contact->id, 'from' => $from, 'to' => $to], $request->user(), 'Transport Loading');

        return [
            'isPdf' => $isPdf,
            'companyName' => 'RANS Company Limited',
            'worker' => $contact,
            'from' => $from,
            'to' => $to,
            'attendance' => $attendance,
            'payments' => $payments,
            'totals' => [
                'days' => $attendance->where('status', TransportLoadingAttendance::PRESENT)->count(),
                'earned_period' => round((float) $attendance->sum('amount'), 2),
                'paid_period' => round((float) $payments->where('status', TransportLoadingPayment::PAID)->sum('amount'), 2),
                'opening' => round($previous, 2),
                'opening_date' => $openingRow?->as_of_date?->toDateString(),
                'earned' => round($earnedAll, 2),
                'paid' => round($paidAll, 2),
                'pending' => $pending,
                'balance' => round($previous + $earnedAll - $paidAll, 2),
            ],
            'generatedBy' => optional($request->user())->name ?? 'N/A',
            'generatedAt' => $generatedAt = now(),
            // Signatories: the person who generates the report signs as Prepared By (nothing is stored; inert unless a document setup exists)
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('transport_loading_statement', \App\Services\Signatories\Documents\TransportLoadingStatementDocument::record($request->user(), $generatedAt)),
            'autoPrint' => ! $isPdf,
        ];
    }

    public function storePayment(Request $request)
    {
        $this->authorize('pay-transport-loading');

        $data = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'amount' => 'required|numeric|gt:0|max:999999999',
            'payment_date' => 'required|date|before_or_equal:today',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'allow_advance' => 'nullable|boolean',
        ]);
        if (! in_array((int) $data['contact_id'], LoadingLedger::workerIds(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['contact_id' => __('That person is not a Loading & Unloading worker.')]);
        }

        $payment = DB::transaction(function () use ($data, $request) {
            // Same-time payments for one worker must not both pass the balance check.
            Contact::whereKey($data['contact_id'])->lockForUpdate()->first();
            $balance = LoadingLedger::availableOf((int) $data['contact_id']);
            if ((float) $data['amount'] > $balance + 0.001 && ! ($data['allow_advance'] ?? false)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => __('This is more than the balance available (:balance). Tick "pay as advance" to record it anyway.', ['balance' => number_format($balance, 2)]),
                ]);
            }

            return TransportLoadingPayment::create([
                'contact_id' => $data['contact_id'],
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_by_user_id' => $request->user()->id,
            ]);
        });

        log_activity(__('Loading & Unloading payment recorded.'), ['contact_id' => $payment->contact_id, 'amount' => $payment->amount, 'date' => $data['payment_date']], $request->user(), 'Transport Loading');

        $this->told(__('Loading & Unloading Payment'), __('A payment of :amount to :name was recorded by :user.', ['amount' => number_format((float) $payment->amount, 2), 'name' => Contact::find($payment->contact_id)?->name, 'user' => $request->user()->name]));

        return back()->with('message', __('Payment of :amount recorded.', ['amount' => number_format((float) $payment->amount, 2)]));
    }

    /**
     * Generates a Payment Voucher (Draft) for this worker: it goes through the normal voucher approval, and when the voucher
     * is recorded as paid the payment lands in the worker's ledger. Until then the amount is reserved (Pending Voucher).
     */
    public function storeVoucher(Request $request)
    {
        $this->authorize('create-payment-voucher-from-transport');

        $data = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'amount' => 'required|numeric|gt:0|max:999999999',
            'notes' => 'nullable|string|max:500',
        ]);
        if (! in_array((int) $data['contact_id'], LoadingLedger::workerIds(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['contact_id' => __('That person is not a Loading & Unloading worker.')]);
        }

        $voucher = PaymentVoucherNumbers::locked(fn () => DB::transaction(fn () => LoadingVoucherService::create(Contact::findOrFail($data['contact_id']), (float) $data['amount'], $data['notes'] ?? null, $request->user()->id)));

        $this->told(__('Loading & Unloading Voucher'), __(':pv was created for :name by :user.', ['pv' => $voucher->pv_number, 'name' => Contact::find($data['contact_id'])?->name, 'user' => $request->user()->name]), route('payment-vouchers.show', $voucher->id));

        return redirect()->route('payment-vouchers.show', $voucher->id)->with('message', __(':pv created for :name. Submit it for approval; once paid it appears in their ledger.', ['pv' => $voucher->pv_number, 'name' => $voucher->payee_name]));
    }

    /**
     * Pays several people at once: one SEPARATE Draft voucher per person, each for the amount chosen for that person
     * (by default their whole available balance). All or nothing: if one amount is not allowed none is created.
     */
    public function storeBulkVouchers(Request $request)
    {
        $this->authorize('create-payment-voucher-from-transport');

        $data = $request->validate([
            'items' => 'required|array|min:1|max:60',
            'items.*.contact_id' => 'required|integer|distinct|exists:contacts,id',
            'items.*.amount' => 'required|numeric|gt:0|max:999999999',
            'notes' => 'nullable|string|max:500',
        ]);
        $workers = array_flip(LoadingLedger::workerIds());
        foreach ($data['items'] as $i => $item) {
            if (! isset($workers[(int) $item['contact_id']])) {
                throw \Illuminate\Validation\ValidationException::withMessages(["items.$i.contact_id" => __('That person is not a Loading & Unloading worker.')]);
            }
        }

        $vouchers = PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($data, $request) {
            $made = [];
            foreach ($data['items'] as $i => $item) {
                $made[] = LoadingVoucherService::create(Contact::findOrFail($item['contact_id']), (float) $item['amount'], $data['notes'] ?? null, $request->user()->id, "items.$i.amount");
            }

            return $made;
        }));

        log_activity(__('Payment Vouchers generated in bulk for Loading & Unloading personnel.'), ['count' => count($vouchers), 'pv_numbers' => collect($vouchers)->pluck('pv_number')->all()], $request->user(), 'Transport Loading');

        $this->told(__('Loading & Unloading Vouchers'), __(':n separate Payment Vouchers were created by :user.', ['n' => count($vouchers), 'user' => $request->user()->name]));

        return back()->with('message', __(':n separate Payment Vouchers created (:list). Submit each for approval; once paid they appear in each person\'s ledger.', ['n' => count($vouchers), 'list' => collect($vouchers)->pluck('pv_number')->implode(', ')]));
    }

    /** Sets (or changes) a person's previous balance: what was already owed to them, or already taken in advance (negative), before this module. */
    public function storeOpening(Request $request)
    {
        $this->authorize('pay-transport-loading');

        $data = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'amount' => 'required|numeric|not_in:0|between:-999999999,999999999',
            'as_of_date' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:500',
        ]);
        if (! in_array((int) $data['contact_id'], LoadingLedger::workerIds(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['contact_id' => __('That person is not a Loading & Unloading worker.')]);
        }

        DB::transaction(function () use ($data, $request) {
            Contact::whereKey($data['contact_id'])->lockForUpdate()->first();
            $row = TransportLoadingOpeningBalance::where('contact_id', $data['contact_id'])->first();
            $before = $row?->amount;
            $values = ['amount' => round((float) $data['amount'], 2), 'as_of_date' => $data['as_of_date'], 'notes' => $data['notes'] ?? null, 'recorded_by_user_id' => $request->user()->id];
            $row ? $row->update($values) : TransportLoadingOpeningBalance::create(['contact_id' => $data['contact_id']] + $values);
            log_activity($row ? __('Loading & Unloading previous balance changed.') : __('Loading & Unloading previous balance recorded.'), ['contact_id' => (int) $data['contact_id'], 'before' => $before, 'after' => $values['amount'], 'as_of' => $data['as_of_date']], $request->user(), 'Transport Loading');
        });

        $this->told(__('Loading & Unloading Previous Balance'), __('A previous balance of :amount for :name was saved by :user.', ['amount' => number_format((float) $data['amount'], 2), 'name' => Contact::find($data['contact_id'])?->name, 'user' => $request->user()->name]));

        return back()->with('message', __('Previous balance saved. It is now part of the balance.'));
    }

    public function destroyOpening(Request $request, Contact $contact)
    {
        $this->authorize('pay-transport-loading');

        $row = TransportLoadingOpeningBalance::where('contact_id', $contact->id)->firstOrFail();
        $info = ['contact_id' => $contact->id, 'amount' => $row->amount, 'as_of' => $row->as_of_date->toDateString()];
        $row->delete();
        log_activity(__('Loading & Unloading previous balance removed.'), $info, $request->user(), 'Transport Loading');

        $this->told(__('Loading & Unloading Previous Balance Removed'), __('The previous balance of :name was removed by :user.', ['name' => $contact->name, 'user' => $request->user()->name]));

        return back()->with('message', __('Previous balance removed.'));
    }

    public function destroyPayment(Request $request, TransportLoadingPayment $payment)
    {
        $this->authorize('delete-transport-loading');

        // A payment that belongs to a Payment Voucher follows that voucher (reject / cancel / delete it there).
        if ($payment->payment_voucher_id) {
            return back()->with('error', __('This payment belongs to a Payment Voucher. Cancel or delete the voucher instead.'));
        }

        $info = ['contact_id' => $payment->contact_id, 'amount' => $payment->amount, 'date' => $payment->payment_date->toDateString()];
        $payment->delete();
        log_activity(__('Loading & Unloading payment removed.'), $info, $request->user(), 'Transport Loading');

        $this->told(__('Loading & Unloading Payment Removed'), __('A payment of :amount was removed by :user and the balance restored.', ['amount' => number_format((float) $info['amount'], 2), 'user' => $request->user()->name]));

        return back()->with('message', __('Payment removed; the balance has been restored.'));
    }

    private function validDate($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }

    /** Tells whoever should hear of Loading & Unloading activity (Transport Routes > Notifications). */
    private function told(string $title, string $message, ?string $url = null): void
    {
        \App\Services\TransportNotifier::notify('transport-loading-changed', $title, $message, $url ?: route('transport-loading.index'));
    }
}
