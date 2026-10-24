<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentVoucherCollection;
use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherBatch;
use App\Models\PaymentVoucherParticular;
use App\Models\PaymentVoucherType;
use App\Models\Staff;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\NumberToWords;
use App\Support\PaymentVoucherNumbers;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;
use Tecdiary\Laravel\Attachments\Attachment;

/**
 * Bulk Payment Voucher Creation. A batch is a creation/grouping convenience
 * only — it reuses every existing Payment Voucher mechanism (numbering,
 * approval/authorisation, printing, attachments, activity log, permissions)
 * rather than duplicating any of it. See PaymentVoucherController for the
 * single-voucher counterpart this mirrors.
 */
class PaymentVoucherBatchController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        $batches = PaymentVoucherBatch::query()->visibleTo($request->user())
            ->with(['vouchers:id,payment_voucher_batch_id,status,total_amount,paid_at', 'warehouse:id,name'])
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['date_from'], fn ($q, $d) => $q->whereDate('voucher_date', '>=', $d))
            ->when($filters['date_to'], fn ($q, $d) => $q->whereDate('voucher_date', '<=', $d))
            ->when($filters['search'], function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('batch_number', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString();
        // without a filter the page total IS the number of batches: no second identical count
        $totalBatches = array_filter($filters) ? PaymentVoucherBatch::visibleTo($request->user())->count() : $batches->total();
        $batches = $batches->through(fn (PaymentVoucherBatch $b) => $this->presentSummary($b));

        return Inertia::render('PaymentVoucherBatch/Index', [
            'filters' => $filters,
            'warehouses' => $this->warehouseOptions(),
            'types' => array_values(array_diff($this->typesBank(), [\App\Services\Administration\AdminPaymentService::VOUCHER_TYPE])),
            'batches' => new PaymentVoucherCollection($batches),
            'cards' => [
                'total_batches' => $totalBatches,
                'total_vouchers' => PaymentVoucher::visibleTo($request->user())->whereNotNull('payment_voucher_batch_id')->count(),
                'total_value' => (float) PaymentVoucher::visibleTo($request->user())->whereNotNull('payment_voucher_batch_id')
                    ->whereNotIn('status', [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED])
                    ->sum('total_amount'),
            ],
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        return view('payment-voucher-batches-list-print', $this->listPayload($request));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        return Pdf::loadView('payment-voucher-batches-list-print', $this->listPayload($request))
            ->download('payment-voucher-batches-' . now()->format('Ymd-His') . '.pdf');
    }

    /** A query-string value as plain text: an array (?search[]=x) counts as "not given". */
    private function filtersFromRequest(Request $request): array
    {
        $text = fn ($value) => is_string($value) && $value !== '' ? $value : null;

        return [
            'type' => $text($request->query('type')),
            'warehouse_id' => $text($request->query('warehouse_id')),
            'date_from' => $text($request->query('date_from')),
            'date_to' => $text($request->query('date_to')),
            'search' => trim((string) $text($request->query('search'))) ?: null,
        ];
    }

    private function listPayload(Request $request): array
    {
        $filters = $this->filtersFromRequest($request);

        $batches = PaymentVoucherBatch::query()->visibleTo($request->user())
            ->with(['vouchers:id,payment_voucher_batch_id,status,total_amount,paid_at', 'warehouse:id,name'])
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['date_from'], fn ($q, $d) => $q->whereDate('voucher_date', '>=', $d))
            ->when($filters['date_to'], fn ($q, $d) => $q->whereDate('voucher_date', '<=', $d))
            ->when($filters['search'], function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('batch_number', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->limit(2000)
            ->get()
            ->map(fn (PaymentVoucherBatch $b) => $this->presentSummary($b));

        $logoPath = public_path('images/logo.png');

        return [
            'batches' => $batches,
            'totalValue' => $batches->sum('total_amount'),
            'printedBy' => $request->user()->name,
            'printedAt' => now()->format('Y-m-d H:i'),
            'logoPath' => file_exists($logoPath) ? $logoPath : null,
        ];
    }

    /**
     * The same payee for the same amount twice in this batch, or the same payee / amount / date as a voucher made in the last three days, is stopped
     * until the person confirms (the same warning a single voucher gives). Rows are named so the right one can be fixed.
     */
    private function guardDuplicateRows(Request $request, array $data, bool $isStaffBatch): void
    {
        if ($request->boolean('confirm_duplicate')) {
            return;
        }
        $names = $isStaffBatch ? Staff::whereIn('id', collect($data['rows'])->pluck('staff_id')->filter())->pluck('name', 'id') : Contact::whereIn('id', collect($data['rows'])->pluck('payee_contact_id')->filter())->pluck('name', 'id');
        $seen = [];
        $problems = [];
        foreach ($data['rows'] as $i => $row) {
            $key = ($isStaffBatch ? 's' . ($row['staff_id'] ?? '') : (! empty($row['payee_contact_id']) ? 'c' . $row['payee_contact_id'] : 'n' . mb_strtolower(trim((string) ($row['payee_name'] ?? ''))))) . '|' . number_format((float) $row['amount'], 2, '.', '');
            $who = $isStaffBatch ? ($names[$row['staff_id'] ?? 0] ?? '') : (! empty($row['payee_contact_id']) ? ($names[$row['payee_contact_id']] ?? '') : trim((string) ($row['payee_name'] ?? '')));
            if (isset($seen[$key])) {
                $problems[] = __('row :a and row :b: :w, same amount', ['a' => $seen[$key], 'b' => $i + 1, 'w' => $who]);
                continue;
            }
            $seen[$key] = $i + 1;
            $recent = PaymentVoucher::query()->visibleTo($request->user())->whereNotIn('status', [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED])
                ->where('total_amount', number_format((float) $row['amount'], 2, '.', ''))->where('voucher_date', $data['voucher_date'])->where('created_at', '>=', now()->subDays(3))
                ->where(fn ($q) => $isStaffBatch ? $q->where('staff_id', $row['staff_id'] ?? 0) : (! empty($row['payee_contact_id']) ? $q->where('payee_contact_id', $row['payee_contact_id']) : $q->where('payee_name', trim((string) ($row['payee_name'] ?? '')))))->first();
            if ($recent) {
                $problems[] = __('row :a: :w looks like :pv (same payee, amount and date, made recently)', ['a' => $i + 1, 'w' => $who, 'pv' => $recent->pv_number]);
            }
        }
        if ($problems) {
            throw \Illuminate\Validation\ValidationException::withMessages(['duplicate' => __('Possible duplicates: :p. Submit again to confirm these are separate vouchers.', ['p' => implode('; ', array_slice($problems, 0, 5))])]);
        }
    }

    public function create()
    {
        return Inertia::render('PaymentVoucherBatch/Form', [
            'contacts' => $this->contactOptions(),
            'staffs' => $this->staffOptions(),
            'warehouses' => $this->warehouseOptions(),
            'particulars' => $this->particularsBank(),
            'types' => array_values(array_diff($this->typesBank(), [\App\Services\Administration\AdminPaymentService::VOUCHER_TYPE])),
            'companyName' => PaymentVoucher::COMPANY_NAME,
        ]);
    }

    public function store(Request $request)
    {
        $exists = fn (string $table) => Rule::exists($table, 'id')->whereNull('deleted_at');
        $amountRules = ['required', 'numeric', 'min:0.01', 'max:' . PaymentVoucher::MAX_LINE_AMOUNT, 'regex:/^\d+(\.\d{1,2})?$/'];

        $data = $request->validate([
            'type' => 'required|string|max:100',
            'payee_type' => 'required|in:' . PaymentVoucher::PAYEE_TYPE_STAFF . ',' . PaymentVoucher::PAYEE_TYPE_EXTERNAL,
            'warehouse_id' => ['nullable', 'integer', $exists('warehouses')],
            'voucher_date' => ['required', 'date', 'after:2000-01-01', 'before:' . now()->addYear()->toDateString()],
            'common_particulars' => 'required|string|max:' . PaymentVoucher::PARTICULARS_MAX,
            'reference' => 'nullable|string|max:190',
            'common_notes' => 'nullable|string|max:2000',
            'rows' => 'required|array|min:1',
            'rows.*.payee_contact_id' => ['nullable', 'integer', $exists('contacts')],
            'rows.*.staff_id' => ['nullable', 'integer', Rule::exists('staffs', 'id')->whereNull('deleted_at')->whereIn('status', Staff::PAYROLL_STATUSES), 'required_if:payee_type,' . PaymentVoucher::PAYEE_TYPE_STAFF],
            'rows.*.payee_name' => 'nullable|string|max:190|required_if:payee_type,' . PaymentVoucher::PAYEE_TYPE_EXTERNAL,
            'rows.*.amount' => $amountRules,
            'rows.*.reference' => 'nullable|string|max:190',
            'rows.*.remarks' => 'nullable|string|max:500',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ]);

        // a person assigned to projects can only raise vouchers for one of those projects, or for the company
        $mine = PaymentVoucher::projectIdsFor($request->user());
        if ($mine !== null && ! empty($data['warehouse_id']) && ! in_array((int) $data['warehouse_id'], $mine, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['warehouse_id' => __('You are not assigned to this project, so you cannot raise vouchers for it.')]);
        }

        $data['type'] = trim($data['type']) ?: 'Administration';
        if (mb_strtolower($data['type']) === mb_strtolower(\App\Services\Administration\AdminPaymentService::VOUCHER_TYPE)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['type' => __('Payment Vouchers of type :t are made from the Administration module. They cannot be started here.', ['t' => \App\Services\Administration\AdminPaymentService::VOUCHER_TYPE])]);
        }
        $data['common_particulars'] = trim($data['common_particulars']);
        $isStaffBatch = $data['payee_type'] === PaymentVoucher::PAYEE_TYPE_STAFF;
        $this->guardDuplicateRows($request, $data, $isStaffBatch);

        // The numbering lock is taken before the transaction opens, so every number is read from a fresh snapshot (see PaymentVoucherNumbers).
        $batch = PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($data, $request, $isStaffBatch) {
            $batch = PaymentVoucherNumbers::createBatch([
                'type' => $data['type'],
                'payee_type' => $data['payee_type'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'voucher_date' => $data['voucher_date'],
                'common_particulars' => $data['common_particulars'],
                'reference' => $data['reference'] ?? null,
                'common_notes' => $data['common_notes'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]);

            $staffNames = $isStaffBatch
                ? Staff::whereIn('id', collect($data['rows'])->pluck('staff_id')->filter()->values())->pluck('name', 'id')
                : collect();
            $contactNames = ! $isStaffBatch
                ? Contact::whereIn('id', collect($data['rows'])->pluck('payee_contact_id')->filter()->values())->pluck('name', 'id')
                : collect();

            foreach ($data['rows'] as $row) {
                // the stored 2-decimal figure, so the voucher total is exactly its line
                $amount = number_format((float) $row['amount'], 2, '.', '');
                $notesParts = [];
                if (! empty($row['reference'])) {
                    $notesParts[] = 'Reference: ' . trim($row['reference']);
                }
                if (! empty($row['remarks'])) {
                    $notesParts[] = trim($row['remarks']);
                }

                $contactId = $isStaffBatch ? null : ($row['payee_contact_id'] ?? null);
                $voucher = PaymentVoucherNumbers::createVoucher([
                    'payment_voucher_batch_id' => $batch->id,
                    'payee_type' => $data['payee_type'],
                    'payee_contact_id' => $contactId,
                    'staff_id' => $isStaffBatch ? $row['staff_id'] : null,
                    // a chosen contact is printed under its own name, whatever was typed
                    'payee_name' => $isStaffBatch ? $staffNames->get($row['staff_id']) : ($contactNames->get($contactId) ?? $row['payee_name']),
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'type' => $data['type'],
                    'voucher_date' => $data['voucher_date'],
                    'total_amount' => $amount,
                    'amount_in_words' => NumberToWords::convert((float) $amount),
                    'status' => PaymentVoucher::STATUS_DRAFT,
                    'prepared_by_user_id' => $request->user()->id,
                    'internal_notes' => $notesParts ? implode(' - ', $notesParts) : null,
                ]);

                $voucher->items()->create([
                    'line_number' => 1,
                    'particulars' => PaymentVoucher::particularsText($data['common_particulars']),
                    'amount' => $amount,
                ]);

                log_activity(__('Payment Voucher created (bulk).'), ['pv_number' => $voucher->pv_number, 'batch_number' => $batch->batch_number], $voucher, 'Payment Voucher');
            }

            $this->rememberParticular($data['common_particulars']);
            $this->rememberType($data['type']);

            if ($request->hasFile('attachments')) {
                $batch->saveAttachments($request->file('attachments'), 'payment-voucher-batch');
            }

            log_activity(__('Payment Voucher Batch created.'), ['batch_number' => $batch->batch_number, 'voucher_count' => count($data['rows'])], $batch, 'Payment Voucher Batch');

            return $batch;
        }));

        \App\Services\PaymentVoucherNotifier::batch($batch, $request->user());

        return redirect()->route('payment-vouchers.batches.show', $batch->id)->with('message', __('Payment Voucher Batch created.'));
    }

    public function show($id)
    {
        $batch = PaymentVoucherBatch::with(['vouchers' => fn ($q) => $q->orderBy('id'), 'attachments'])->findOrFail($id);

        return Inertia::render('PaymentVoucherBatch/Show', [
            'batch' => $this->presentDetail($batch),
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'separation' => \App\Support\VoucherSeparation::enabled(),
            'activity' => $this->activityHistory($batch),
        ]);
    }

    public function printAll(Request $request, $id)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        $batch = PaymentVoucherBatch::with(['vouchers.items', 'vouchers.payeeContact:id,name', 'vouchers.warehouse:id,name'])->findOrFail($id);
        $vouchers = $this->presentForPrint($batch);

        return view('payment-voucher-batches.print', [
            'batch' => $batch,
            'vouchers' => $vouchers,
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'isPdf' => false,
        ]);
    }

    public function pdfAll(Request $request, $id)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        $batch = PaymentVoucherBatch::with(['vouchers.items', 'vouchers.payeeContact:id,name', 'vouchers.warehouse:id,name'])->findOrFail($id);
        $vouchers = $this->presentForPrint($batch);

        return Pdf::loadView('payment-voucher-batches.print', [
            'batch' => $batch,
            'vouchers' => $vouchers,
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'isPdf' => true,
        ])->download('payment-voucher-batch-' . $batch->batch_number . '.pdf');
    }

    /** Every voucher of the batch, ready to print. The signature set-up, settings and signers are read once for the whole batch, not once per voucher. */
    private function presentForPrint(PaymentVoucherBatch $batch)
    {
        return \App\Services\Signatories\SignatureResolver::scoped(fn () => $batch->vouchers->map(fn (PaymentVoucher $v) => $this->recordPrintAndPresent($v)));
    }

    public function downloadAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('read-payment-vouchers');
        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === PaymentVoucherBatch::class, 404);
        PaymentVoucherBatch::findOrFail($id);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    private function recordPrintAndPresent(PaymentVoucher $voucher): array
    {
        $isFirstPrint = $voucher->printed_count === 0;
        // one update for both columns (the print itself is logged below)
        $voucher->increment('printed_count', 1, $isFirstPrint ? ['first_printed_at' => now()] : []);
        log_activity($isFirstPrint ? __('Payment Voucher printed.') : __('Payment Voucher reprinted.'), ['pv_number' => $voucher->pv_number, 'print_count' => $voucher->printed_count], $voucher, 'Payment Voucher');

        return [
            'pv_number' => $voucher->pv_number,
            'type' => $voucher->type,
            'payee_name' => $voucher->payee_name,
            'warehouse_name' => $voucher->warehouse_id ? $voucher->warehouse?->name : PaymentVoucher::COMPANY_NAME,
            'voucher_date' => optional($voucher->voucher_date)->format('Y-m-d'),
            'items' => $voucher->items->map(fn ($item) => ['line_number' => $item->line_number, 'particulars' => $item->particulars, 'amount' => (float) $item->amount])->values(),
            'total_amount' => (float) $voucher->total_amount,
            'amount_in_words' => $voucher->amount_in_words,
            'remarks' => $voucher->remarks,
            'prepared_by_name' => $this->userName($voucher->prepared_by_user_id),
            'created_at' => optional($voucher->created_at)->toIso8601String(),
            'approved_by_name' => $this->userName($voucher->approved_by_user_id),
            'approved_at' => optional($voucher->approved_at)->toIso8601String(),
            'authorised_by_name' => $this->userName($voucher->authorised_by_user_id),
            'authorised_at' => optional($voucher->authorised_at)->toIso8601String(),
            'recipient_name' => $voucher->recipient_name,
            'recipient_date' => optional($voucher->recipient_date)->format('Y-m-d'),
            'status' => $voucher->status,
            'printed_count' => $voucher->printed_count,
            'qr' => \App\Support\PrintQrCode::dataUri(route('payment-vouchers.show', $voucher->id)),
            // Signatories: each voucher of the batch resolves its own signatures from its own workflow history
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('payment_voucher', $voucher),
        ];
    }

    private function rememberParticular(string $remainder): void
    {
        if ($remainder === '') {
            return;
        }

        $particular = PaymentVoucherParticular::firstOrCreate(['text' => $remainder]);
        if (! $particular->wasRecentlyCreated) {
            $particular->increment('use_count');
        }
    }

    private function particularsBank(): array
    {
        return PaymentVoucherParticular::orderByDesc('use_count')->limit(200)->pluck('text')->values()->all();
    }

    private function rememberType(string $type): void
    {
        if ($type === '') {
            return;
        }

        $record = PaymentVoucherType::firstOrCreate(['text' => $type]);
        if (! $record->wasRecentlyCreated) {
            $record->increment('use_count');
        }
    }

    private function typesBank(): array
    {
        return PaymentVoucherType::orderByDesc('use_count')->limit(200)->pluck('text')->values()->all();
    }

    private function activityHistory(PaymentVoucherBatch $batch): array
    {
        return $batch->activities()
            ->with('causer:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $a) => [
                'description' => $a->description,
                'causer' => $a->causer?->name ?? __('System'),
                'created_at' => optional($a->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function contactOptions(): array
    {
        return Contact::orderBy('name')->get(['id', 'name', 'category'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'category' => $c->category])
            ->values()->all();
    }

    private function warehouseOptions(): array
    {
        $mine = PaymentVoucher::projectIdsFor(auth()->user());

        return Warehouse::ofAccount()->active()->visible()->when($mine !== null, fn ($q) => $q->whereIn('id', $mine))->orderBy('name')->get(['id', 'name', 'status'])
            ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'status' => $w->status])->values()->all();
    }

    private function staffOptions(): array
    {
        return Staff::payroll()->orderBy('name')->get(['id', 'name', 'staff_number'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'staff_number' => $s->staff_number])
            ->values()->all();
    }

    private static array $userNameCache = [];

    private function userName(?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return self::$userNameCache[$id] ??= User::find($id)?->name;
    }

    /**
     * Rollup label for the batch as a whole — computed from its vouchers'
     * individual statuses, never stored, so it can never drift out of sync
     * with each voucher's own (authoritative) status.
     */
    private function batchStatus($vouchers): string
    {
        $statuses = $vouchers->pluck('status')->unique();
        if ($statuses->isEmpty()) {
            return PaymentVoucher::STATUS_DRAFT;
        }

        return $statuses->count() === 1 ? $statuses->first() : 'Mixed';
    }

    private function presentSummary(PaymentVoucherBatch $batch): array
    {
        $vouchers = $batch->vouchers;

        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'type' => $batch->type,
            'payee_type' => $batch->payee_type,
            'warehouse_id' => $batch->warehouse_id,
            'warehouse_name' => $batch->warehouse_id ? $batch->warehouse?->name : PaymentVoucher::COMPANY_NAME,
            'voucher_date' => optional($batch->voucher_date)->format('Y-m-d'),
            'voucher_count' => $vouchers->count(),
            // every voucher of the batch is Authorised and has been paid
            'all_paid' => $vouchers->count() > 0 && $vouchers->every(fn ($v) => $v->status === PaymentVoucher::STATUS_AUTHORISED && ! empty($v->paid_at)),
            // like the cards: a Rejected or Cancelled voucher is no money to pay, so it is not in the batch total
            'total_amount' => (float) $vouchers->reject(fn ($v) => in_array($v->status, [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED], true))->sum('total_amount'),
            'status' => $this->batchStatus($vouchers),
            'created_at' => optional($batch->created_at)->toIso8601String(),
        ];
    }

    private function presentDetail(PaymentVoucherBatch $batch): array
    {
        return array_merge($this->presentSummary($batch), [
            'reference' => $batch->reference,
            'common_particulars' => $batch->common_particulars,
            'common_notes' => $batch->common_notes,
            'created_by_user_id' => $batch->created_by_user_id,
            'created_by_name' => $this->userName($batch->created_by_user_id),
            'labour_list_id' => $batch->labour_list_id,
            'labour_list_reference' => $batch->labour_list_id ? \App\Models\LabourList::find($batch->labour_list_id)?->reference : null,
            'attachments' => $batch->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->title ?: $a->filename])->values(),
            'vouchers' => $batch->vouchers->map(fn (PaymentVoucher $v) => [
                'id' => $v->id,
                'pv_number' => $v->pv_number,
                'payee_name' => $v->payee_name,
                'total_amount' => (float) $v->total_amount,
                'status' => $v->status, 'display_status' => $v->display_status, 'prepared_by_ids' => \App\Support\VoucherSeparation::handlers($v), 'approved_by_user_id' => $v->approved_by_user_id,
                'printed_count' => $v->printed_count,
            ])->values(),
        ]);
    }
}
