<?php

namespace App\Http\Controllers;

use App\Events\PaymentVoucherStatusChanged;
use App\Services\PaymentVoucherNotifier;
use App\Http\Resources\PaymentVoucherCollection;
use App\Models\Asset;
use App\Models\Contact;
use App\Models\FundAllocationItem;
use App\Models\PaymentRequest;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherParticular;
use App\Models\PaymentVoucherType;
use App\Models\PurchaseOrder;
use App\Models\Staff;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\NumberToWords;
use App\Support\PaymentVoucherNumbers;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;
use Spatie\Activitylog\Models\Activity;
use Tecdiary\Laravel\Attachments\Attachment;

class PaymentVoucherController extends Controller
{
    public const STATUSES = ['Draft', 'Submitted', 'Approved', 'Authorised', 'Rejected', 'Cancelled'];

    // The printed list shows at most this many rows; a PDF is built in memory, so it stops much earlier (the Excel export has everything).
    private const PRINT_LIST_ROWS = 2000;
    private const PDF_LIST_ROWS = 300;

    // Whitelist of Index-page-sortable columns -> actual DB column, so the
    // ?sort= query param can never be used to sort by an arbitrary/unindexed
    // or non-existent column.
    private const SORTABLE_COLUMNS = [
        'pv_number' => 'pv_number',
        'payee_name' => 'payee_name',
        'voucher_date' => 'voucher_date',
        'total_amount' => 'total_amount',
        'status' => 'status',
    ];

    public function index(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        [$sort, $direction] = $this->sortFromRequest($request);

        $vouchers = PaymentVoucher::query()
            ->with(['items', 'batch:id,batch_number'])
            ->filter($filters)
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString()
            ->through(fn (PaymentVoucher $v) => $this->presentSummary($v));

        return Inertia::render('PaymentVoucher/Index', [
            'filters' => array_merge($filters, ['sort' => $this->text($request->query('sort')), 'direction' => $direction]),
            'statuses' => array_merge(array_slice(self::STATUSES, 0, 4), [PaymentVoucher::DISPLAY_PAID], array_slice(self::STATUSES, 4)),
            'warehouses' => $this->warehouseOptions(),
            ...$this->peopleLists($request),
            'types' => $this->typesBank(),
            'paymentVouchers' => new PaymentVoucherCollection($vouchers),
            'cards' => [
                'draft' => PaymentVoucher::where('status', PaymentVoucher::STATUS_DRAFT)->count(),
                'submitted' => PaymentVoucher::where('status', PaymentVoucher::STATUS_SUBMITTED)->count(),
                'approved' => PaymentVoucher::where('status', PaymentVoucher::STATUS_APPROVED)->count(),
                'authorised' => PaymentVoucher::where('status', PaymentVoucher::STATUS_AUTHORISED)->whereNull('paid_at')->count(),
                'paid' => PaymentVoucher::where('status', PaymentVoucher::STATUS_AUTHORISED)->whereNotNull('paid_at')->count(),
                'total_value' => (float) PaymentVoucher::whereNotIn('status', [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED])->sum('total_amount'),
            ],
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        return view('payment-vouchers-list-print', $this->listPayload($request, self::PRINT_LIST_ROWS));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        // a PDF of thousands of rows runs out of time and memory: the PDF is capped, the Excel export has the full list
        return Pdf::loadView('payment-vouchers-list-print', $this->listPayload($request, self::PDF_LIST_ROWS))
            ->download('payment-vouchers-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        $filters = $this->filtersFromRequest($request);

        $rows = PaymentVoucher::query()
            ->with('items')
            ->filter($filters)
            ->orderByDesc('voucher_date')
            ->limit(10000)
            ->get();

        return (new FastExcel($rows))->download('payment-vouchers-' . now()->format('Ymd-His') . '.xlsx', function (PaymentVoucher $v) {
            return [
                'PV Number' => $this->sheetText($v->pv_number),
                'Type' => $this->sheetText($v->type),
                'Payee/Staff Type' => $this->sheetText($v->payee_type),
                'Payee' => $this->sheetText($v->payee_name),
                'Project' => $this->sheetText($v->warehouse_id ? $this->warehouseName($v->warehouse_id) : PaymentVoucher::COMPANY_NAME),
                'Date' => optional($v->voucher_date)->format('Y-m-d'),
                'Amount' => (float) $v->total_amount,
                'Amount in Words' => $this->sheetText($v->amount_in_words),
                'Particulars' => $this->sheetText($v->items->pluck('particulars')->implode('; ')),
                'Status' => $this->sheetText($v->display_status),
                'Prepared By' => $this->sheetText($this->userName($v->prepared_by_user_id)),
            ];
        });
    }

    /** Text typed by people must never be read by Excel as a formula (=HYPERLINK(...), +cmd, -1+1, @SUM): a leading quote makes it plain text. */
    private function sheetText(?string $value): ?string
    {
        if ($value !== null && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    public function transportFuelReport(Request $request)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        $filters = [
            'asset_id' => $this->text($request->query('asset_id')),
            'warehouse_id' => $this->text($request->query('warehouse_id')),
            'date_from' => $this->text($request->query('date_from')),
            'date_to' => $this->text($request->query('date_to')),
        ];

        $vouchers = PaymentVoucher::query()
            ->with(['asset:id,name,asset_number', 'warehouse:id,name'])
            ->filter($filters + ['type' => 'Transport / Fuel'])
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString()
            ->through(fn (PaymentVoucher $v) => $this->presentSummary($v));

        return Inertia::render('PaymentVoucher/TransportFuelReport', [
            'filters' => $filters,
            'vouchers' => new PaymentVoucherCollection($vouchers),
            'assets' => $this->assetOptions(),
            'warehouses' => $this->warehouseOptions(),
            'totalAmount' => (float) PaymentVoucher::query()
                ->filter($filters + ['type' => 'Transport / Fuel'])
                ->whereNotIn('status', [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED])
                ->sum('total_amount'),
        ]);
    }

    /** A query-string value as plain text: an array (?search[]=x) or anything else that is not a string counts as "not given". */
    private function text($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The one place the list filters are read from the request (list, print list, PDF list and Excel export all use it). */
    private function filtersFromRequest(Request $request): array
    {
        $filters = [];
        foreach (['status', 'warehouse_id', 'payee_contact_id', 'staff_id', 'asset_id', 'type', 'source', 'batch_number', 'date_from', 'date_to'] as $key) {
            $filters[$key] = $this->text($request->query($key));
        }
        $filters['search'] = trim((string) $this->text($request->query('search'))) ?: null;

        return $filters;
    }

    /** @return array{0: string, 1: string} [sort column, direction] */
    private function sortFromRequest(Request $request): array
    {
        $sort = self::SORTABLE_COLUMNS[$this->text($request->query('sort')) ?? ''] ?? 'voucher_date';
        $direction = $this->text($request->query('direction')) === 'asc' ? 'asc' : 'desc';

        return [$sort, $direction];
    }

    private function listPayload(Request $request, int $limit): array
    {
        $filters = $this->filtersFromRequest($request);
        [$sort, $direction] = $this->sortFromRequest($request);

        $matching = PaymentVoucher::query()->filter($filters)->count();
        $vouchers = PaymentVoucher::query()
            ->with('items')
            ->filter($filters)
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentVoucher $v) => $this->presentSummary($v));

        $logoPath = public_path('images/logo.png');

        return [
            'vouchers' => $vouchers,
            // like the index card: Rejected and Cancelled vouchers are listed but never counted as money, and the total covers every matching voucher, not only the rows shown
            'totalValue' => (float) PaymentVoucher::query()->filter($filters)->whereNotIn('status', [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED])->sum('total_amount'),
            'listNote' => $matching > $limit
                ? __('Showing the first :shown of :all matching vouchers. For the complete list use the Excel export.', ['shown' => number_format($limit), 'all' => number_format($matching)])
                : null,
            'printedBy' => $request->user()->name,
            'printedAt' => now()->format('Y-m-d H:i'),
            'logoPath' => file_exists($logoPath) ? $logoPath : null,
        ];
    }

    /**
     * The contact and staff lists feed the payee pickers (the voucher form and the list filters). They are names of every supplier and every
     * staff member, so they go only to people who can raise or edit vouchers; a read-only user gets the vouchers, not the address book.
     */
    private function peopleLists(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! ($user->can('create-payment-vouchers') || $user->can('update-payment-vouchers'))) {
            return [];
        }

        return ['contacts' => $this->contactOptions(), 'staffs' => $this->staffOptions()];
    }

    public function create(Request $request)
    {
        // Optional Funds Allocation hand-off: ?fund_item=<id> pre-fills the form from a
        // payable allocation line. An invalid/ineligible item is ignored with a warning.
        $prefill = null;
        $prefillWarning = null;
        if ($request->filled('fund_item')) {
            [$prefill, $prefillWarning] = $this->fundItemPrefill($request, (int) $request->query('fund_item'));
        }
        // Subcontract hand-off: ?subcontract_payment=<id> starts the voucher of an approved subcontract payment request
        if ($request->filled('subcontract_payment')) {
            [$prefill, $prefillWarning] = $this->subcontractPrefill($request, (int) $request->query('subcontract_payment'));
        }
        // Contract payment request (claim) hand-off: ?subcontract_claim=<id> starts ONE voucher for the approved lines selected on the claim
        if ($request->filled('subcontract_claim')) {
            [$prefill, $prefillWarning] = $this->subcontractClaimPrefill($request, (int) $request->query('subcontract_claim'));
        }

        return Inertia::render('PaymentVoucher/Form', [
            'paymentVoucher' => null,
            'contacts' => $this->contactOptions(),
            'staffs' => $this->staffOptions(),
            'warehouses' => $this->warehouseOptions(),
            'particulars' => $this->particularsBank(),
            'types' => $this->typesBank(),
            'assets' => $this->assetOptions(),
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'prefill' => $prefill,
            'prefillWarning' => $prefillWarning,
        ]);
    }

    /**
     * The payer (assigned to the sheet), Super Admin/Admin, or a user who may edit
     * allocations can act on an item - same rule as FundAllocation linkVoucher.
     */
    private function userCanActOnFundItem($user, FundAllocationItem $item): bool
    {
        $allocation = $item->allocation;
        if (! $allocation || ! $allocation->isPayable()) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        if ($user->can('pay-fund-allocations') && (int) $allocation->assigned_to_user_id === (int) $user->id) {
            return true;
        }

        return $user->can('update-fund-allocations');
    }

    /** @return array{0: ?array, 1: ?string} [prefill, warning] */
    private function fundItemPrefill(Request $request, int $itemId): array
    {
        $item = FundAllocationItem::with(['allocation', 'category', 'contact', 'staff', 'beneficiaryUser.staff', 'beneficiaryWarehouse'])->find($itemId);

        if (! $item || ! $item->allocation) {
            return [null, __('The Funds Allocation line could not be found, so the voucher was not pre-filled.')];
        }
        if (! $this->userCanActOnFundItem($request->user(), $item)) {
            return [null, __('You cannot create a voucher for this Funds Allocation line (the sheet must be Approved or In Progress and you must be its payer).')];
        }
        if ($item->payment_voucher_id) {
            return [null, __('This Funds Allocation line already has a payment voucher linked, so the voucher was not pre-filled.')];
        }

        $allocation = $item->allocation;
        $amount = (float) $item->allocated_amount;
        if ($amount <= 0) {
            // Raw query (not the lines() relation) - only the amounts are needed.
            $amount = (float) DB::table('fund_allocation_item_lines')->where('fund_allocation_item_id', $item->id)->sum('amount');
        }
        $description = trim((string) $item->description);
        $particulars = mb_substr($description . ' - Funds Allocation ' . $allocation->reference, 0, PaymentVoucher::PARTICULARS_MAX);

        $prefill = [
            'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL,
            'payee_contact_id' => null,
            'staff_id' => null,
            'payee_name' => '',
            'warehouse_id' => $item->warehouse_id,
            'voucher_date' => now()->format('Y-m-d'),
            'total_amount' => round($amount, 2),
            'particulars_remainder' => $particulars,
            'remarks' => mb_substr('Funds Allocation ' . $allocation->reference . ' - ' . $description, 0, 2000),
            'fund_item_id' => $item->id,
            'fund_item_summary' => [
                'reference' => $allocation->reference,
                'description' => $description,
                'category' => $item->category?->name,
            ],
        ];

        if ($item->contact_id && $item->contact) {
            $prefill['payee_contact_id'] = $item->contact_id;
            $prefill['payee_name'] = $item->contact->name;
        } elseif ($item->beneficiary_user_id && $item->beneficiaryUser) {
            $linkedStaff = $item->beneficiaryUser->staff;
            if ($linkedStaff && $linkedStaff->status === 'Active') {
                $prefill['payee_type'] = PaymentVoucher::PAYEE_TYPE_STAFF;
                $prefill['staff_id'] = $linkedStaff->id;
            }
            $prefill['payee_name'] = $item->beneficiaryUser->name;
        } elseif ($item->beneficiary_warehouse_id && $item->beneficiaryWarehouse) {
            $prefill['payee_name'] = $item->beneficiaryWarehouse->name;
            $prefill['warehouse_id'] = $prefill['warehouse_id'] ?: $item->beneficiary_warehouse_id;
        } elseif ($item->staff_id && $item->staff && $item->staff->status === 'Active') {
            $prefill['payee_type'] = PaymentVoucher::PAYEE_TYPE_STAFF;
            $prefill['staff_id'] = $item->staff_id;
            $prefill['payee_name'] = $item->staff->name;
        } else {
            $prefill['payee_name'] = (string) $item->beneficiary;
        }

        return [$prefill, null];
    }

    /**
     * JSON autocomplete for the Funds Allocation "Link existing voucher" picker.
     * Not auto-authorised (the action name maps to no ability), so check explicitly.
     */
    public function searchForFund(Request $request)
    {
        $this->authorize('read-payment-vouchers');

        $q = trim((string) $this->text($request->query('q')));

        $vouchers = PaymentVoucher::query()
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
                $w->where('pv_number', 'like', $like)->orWhere('payee_name', 'like', $like);
            }))
            // A voucher already attached to a fund allocation line is not offered again.
            ->whereDoesntHave('fundAllocationItem')
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'pv_number', 'payee_name', 'total_amount', 'status', 'voucher_date']);

        return response()->json($vouchers->map(fn (PaymentVoucher $v) => [
            'id' => $v->id,
            'pv_number' => $v->pv_number,
            'payee_name' => $v->payee_name,
            'total_amount' => (float) $v->total_amount,
            'status' => $v->status,
            'voucher_date' => optional($v->voucher_date)->format('Y-m-d'),
        ])->values());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $fundItemId = $request->validate([
            'fund_allocation_item_id' => 'nullable|integer|exists:fund_allocation_items,id',
        ])['fund_allocation_item_id'] ?? null;

        $subPaymentId = $request->validate(['subcontract_payment_id' => 'nullable|integer'])['subcontract_payment_id'] ?? null;
        $subClaimId = $request->validate(['subcontract_claim_id' => 'nullable|integer'])['subcontract_claim_id'] ?? null;
        if ($subPaymentId && $subClaimId) {
            throw ValidationException::withMessages(['subcontract_claim_id' => __('A voucher pays one subcontract request or one contract payment request, not both.')]);
        }

        $this->guardAgainstDuplicate($request, $data);

        // Optional Funds Allocation link: the voucher and the link are created atomically,
        // and the item row is locked so two vouchers cannot claim the same line.
        // The numbering lock is taken before the transaction opens, so the next number is read from a fresh snapshot.
        [$voucher, $subPayment, $claimInfo] = PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($request, $data, $fundItemId, $subPaymentId, $subClaimId) {
            $fundItem = $fundItemId ? $this->lockFundItemForLink($request, (int) $fundItemId) : null;
            $subPayment = $subPaymentId ? $this->lockSubcontractPayment($request, (int) $subPaymentId, $data) : null;
            $claimInfo = $subClaimId ? $this->lockSubcontractClaim($request, (int) $subClaimId, $data) : null;
            if ($fundItem) {
                $this->guardFundItemAmount($fundItem, $data);
            }
            $voucher = $this->createVoucherFromRequest($request, $data);

            if ($subPayment) {
                // the voucher and the subcontract payment are linked in one step: one payment, one voucher
                $subPayment->forceFill(['payment_voucher_id' => $voucher->id, 'status' => 'voucher'])->save();
                $voucher->forceFill(['subcontract_payment_id' => $subPayment->id])->save();
                log_activity(__('Payment voucher :pv linked to subcontract request :r', ['pv' => $voucher->pv_number, 'r' => optional($subPayment->request)->reference]), ['pv_number' => $voucher->pv_number, 'subcontract_payment_id' => $subPayment->id, 'amount' => (string) $subPayment->amount_tzs], $subPayment->agreement, 'Subcontracts');
            }

            if ($claimInfo) {
                // ONE voucher, several payments: every open payment of the claim is put on this voucher in the same step (a payment already on a voucher is not in the set, so nothing is paid twice)
                foreach ($claimInfo['payments'] as $cp) {
                    $cp->forceFill(['payment_voucher_id' => $voucher->id, 'status' => 'voucher'])->save();
                    log_activity(__('Payment voucher :pv linked to contract payment request :c', ['pv' => $voucher->pv_number, 'c' => $claimInfo['claim']->reference]), ['pv_number' => $voucher->pv_number, 'subcontract_payment_id' => $cp->id, 'claim' => $claimInfo['claim']->reference, 'amount' => (string) $cp->amount, 'amount_tzs' => (string) $cp->amount_tzs], $cp->agreement, 'Subcontracts');
                }
                $voucher->forceFill(['subcontract_claim_id' => $claimInfo['claim']->id])->save();
                log_activity(__('Payment voucher :pv created for contract payment request :c', ['pv' => $voucher->pv_number, 'c' => $claimInfo['claim']->reference]), ['pv_number' => $voucher->pv_number, 'total_tzs' => (string) $claimInfo['total'], 'allocations' => $claimInfo['payments']->count()], $claimInfo['claim'], 'Subcontracts');
            }

            if ($fundItem) {
                $fundItem->payment_voucher_id = $voucher->id;
                $fundItem->save();

                log_activity(
                    __('Payment voucher :pv linked to :item', ['pv' => $voucher->pv_number, 'item' => $fundItem->description]),
                    ['pv_number' => $voucher->pv_number, 'fund_allocation_item_id' => $fundItem->id],
                    $fundItem->allocation,
                    'Funds Allocation'
                );
            }

            return [$voucher, $subPayment, $claimInfo];
        }));

        if (! empty($claimInfo)) {
            \App\Services\Subcontracts\SubcontractNotifier::fire('claim_voucher_started', 'claim', $claimInfo['claim'], $request->user(), $voucher->pv_number, ['amount' => (string) $claimInfo['total']]);
        }
        if (! empty($subPayment)) {
            \App\Services\Subcontracts\SubcontractNotifier::fire('voucher_started', 'request', $subPayment->request, $request->user(), $voucher->pv_number, ['amount' => (string) $subPayment->amount_tzs]);
        }
        PaymentVoucherNotifier::fire('created', $voucher, $request->user(), 'Payment Voucher Created', 'Payment Voucher ' . $voucher->pv_number . ' was created by ' . $request->user()->name . '.', ['skip_actor' => true]);

        return redirect()->route('payment-vouchers.show', $voucher->id)->with('message', __('Payment Voucher created.'));
    }

    /** A rejected voucher of a subcontract payment may be corrected and sent again only while its payment is still alive: once the payment was cancelled (and the request can be paid again), reviving the voucher would pay it twice. */
    private function guardSubcontractVoucher(PaymentVoucher $voucher): void
    {
        $dead = \App\Models\SubcontractPayment::query()->where('payment_voucher_id', $voucher->id)->where('status', 'cancelled')->exists();
        abort_if($dead, 422, __('The subcontract payment of this voucher was cancelled. Start a new payment from the request instead.'));
    }

    /** @return array{0: ?array, 1: ?string} [prefill, warning] for a subcontract payment that was just started from an approved request */
    private function subcontractPrefill(Request $request, int $paymentId): array
    {
        $p = \App\Models\SubcontractPayment::with(['request', 'agreement.contact'])->find($paymentId);
        if (! $p || ! $p->request || ! $p->agreement) {
            return [null, __('The subcontract payment could not be found, so the voucher was not pre-filled.')];
        }
        if (! $request->user()->can('initiate-subcontract-vouchers') || ! \App\Models\SubcontractAgreement::query()->visibleTo($request->user())->whereKey($p->agreement_id)->exists()) {
            return [null, __('You may not start a voucher for a subcontract payment.')];
        }
        if ($p->payment_voucher_id || $p->status !== 'initiated') {
            return [null, __('This subcontract payment already has a voucher (or was cancelled), so the voucher was not pre-filled.')];
        }
        $a = $p->agreement;
        $text = mb_substr($a->title . ' - ' . $p->request->reference . ' - ' . $a->reference, 0, PaymentVoucher::PARTICULARS_MAX);

        return [[
            'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL, 'payee_contact_id' => $a->contact_id, 'staff_id' => null, 'payee_name' => optional($a->contact)->name ?? '', 'warehouse_id' => $a->warehouse_id,
            'voucher_date' => now()->format('Y-m-d'), 'total_amount' => (float) $p->amount_tzs, 'particulars_remainder' => $text, 'remarks' => mb_substr('Subcontract ' . $a->reference . ' / request ' . $p->request->reference, 0, 2000),
            'fund_item_id' => null, 'subcontract_payment_id' => $p->id,
            'fund_item_summary' => ['reference' => $p->request->reference, 'description' => $a->title, 'category' => $a->currency === 'TZS' ? null : __('Agreement in :c: :a at rate :r', ['c' => $a->currency, 'a' => number_format((float) $p->amount, 2), 'r' => (string) $p->exchange_rate])],
            'is_subcontract' => true, 'type' => 'Subcontractor',
        ], null];
    }

    /** @return array{0: ?array, 1: ?string} [prefill, warning] for the open payments of a contract payment request (claim) just started on its page */
    private function subcontractClaimPrefill(Request $request, int $claimId): array
    {
        $c = \App\Models\SubcontractClaim::query()->with('contact')->find($claimId);
        if (! $c) {
            return [null, __('The contract payment request could not be found, so the voucher was not pre-filled.')];
        }
        $payments = \App\Services\Subcontracts\SubcontractClaims::openPayments($c->id)->with(['agreement:id,reference,title,warehouse_id,contact_id,status', 'request:id,reference'])->get();
        $visible = \App\Models\SubcontractAgreement::query()->visibleTo($request->user())->whereIn('id', $payments->pluck('agreement_id')->all() ?: [0])->count();
        if (! $request->user()->can('initiate-subcontract-vouchers') || $visible !== $payments->pluck('agreement_id')->unique()->count()) {
            return [null, __('You may not start a voucher for a contract payment request.')];
        }
        if ($payments->isEmpty()) {
            return [null, __('This contract payment request has no payment waiting for a voucher, so the voucher was not pre-filled.')];
        }
        $total = $payments->reduce(fn ($t, $p) => bcadd($t, (string) $p->amount_tzs, 2), '0.00');
        $warehouses = $payments->pluck('agreement.warehouse_id')->filter()->unique();
        $refs = $payments->map(fn ($p) => optional($p->agreement)->reference)->filter()->unique()->implode(', ');
        $text = mb_substr(__('Contract payment :c - :n: :r', ['c' => $c->reference, 'n' => optional($c->contact)->name, 'r' => $refs]), 0, PaymentVoucher::PARTICULARS_MAX);

        return [[
            'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL, 'payee_contact_id' => $c->contact_id, 'staff_id' => null, 'payee_name' => optional($c->contact)->name ?? '', 'warehouse_id' => $warehouses->count() === 1 ? $warehouses->first() : $c->warehouse_id,
            'voucher_date' => now()->format('Y-m-d'), 'total_amount' => (float) $total, 'particulars_remainder' => $text, 'remarks' => mb_substr('Subcontract contract payment ' . $c->reference . ' / ' . $refs, 0, 2000),
            'fund_item_id' => null, 'subcontract_payment_id' => null, 'subcontract_claim_id' => $c->id,
            'fund_item_summary' => ['reference' => $c->reference, 'description' => trans_choice(':n contract|:n contracts', $payments->count(), ['n' => $payments->count()]) . ': ' . $refs, 'category' => $c->currency === 'TZS' ? null : __('Claim in :c: the TZS amounts use the rate typed when the payment was started', ['c' => $c->currency])],
            'is_subcontract' => true, 'is_subcontract_claim' => true, 'type' => 'Subcontractor',
            'claim_allocations' => $payments->map(fn ($p) => ['agreement' => optional($p->agreement)->reference, 'request' => optional($p->request)->reference, 'currency' => $p->currency, 'amount' => (string) $p->amount, 'amount_tzs' => (string) $p->amount_tzs])->values()->all(),
        ], null];
    }

    /** Locks the open payments of a claim that a new voucher takes, and checks the voucher is exactly what was approved: the subcontractor as payee and the exact TZS total of ALL of them. @return array{claim:\App\Models\SubcontractClaim,payments:\Illuminate\Support\Collection,total:string} */
    private function lockSubcontractClaim(Request $request, int $claimId, array $data): array
    {
        abort_unless($request->user()->can('initiate-subcontract-vouchers'), 403);
        $claim = \App\Models\SubcontractClaim::query()->whereKey($claimId)->lockForUpdate()->first();
        abort_unless($claim, 403);
        // lockForUpdate on the payments: two vouchers made at the same moment cannot both take them (the second finds none left)
        $payments = \App\Models\SubcontractPayment::query()->where('claim_id', $claim->id)->where('status', 'initiated')->whereNull('payment_voucher_id')->orderBy('id')->lockForUpdate()->get();
        $agrIds = $payments->pluck('agreement_id')->unique()->all();
        abort_unless(\App\Models\SubcontractAgreement::query()->visibleTo($request->user())->whereIn('id', $agrIds ?: [0])->count() === count($agrIds), 403);
        $payments->load(['agreement', 'request']);
        $total = $payments->reduce(fn ($t, $p) => bcadd($t, (string) $p->amount_tzs, 2), '0.00');
        $err = null;
        if ($payments->isEmpty()) {
            $err = __('This contract payment request has no payment waiting for a voucher (it already has one, or it was cancelled).');
        } elseif ($payments->contains(fn ($p) => ! $p->request || $p->request->status !== \App\Models\SubcontractPaymentRequest::APPROVED || ! $p->agreement || ! $p->agreement->isPayable())) {
            $err = __('A line of the contract payment request is no longer approved or payable.');
        } elseif ((int) ($data['payee_contact_id'] ?? 0) !== (int) $claim->contact_id) {
            $err = __('The payee must be the subcontractor of the contract payment request.');
        } elseif (bccomp(number_format((float) $data['total_amount'], 2, '.', ''), $total, 2) !== 0) {
            $err = __('The voucher total must be :a (the amount approved for the lines of this contract payment request).', ['a' => number_format((float) $total, 2)]);
        }
        if ($err) {
            throw \Illuminate\Validation\ValidationException::withMessages(['subcontract_claim_id' => $err]);
        }

        return ['claim' => $claim, 'payments' => $payments, 'total' => $total];
    }

    /** Locks the subcontract payment a new voucher claims, and checks the voucher is exactly what was approved: same payee, same TZS amount. */
    private function lockSubcontractPayment(Request $request, int $paymentId, array $data): \App\Models\SubcontractPayment
    {
        abort_unless($request->user()->can('initiate-subcontract-vouchers'), 403);
        $p = \App\Models\SubcontractPayment::query()->whereKey($paymentId)->lockForUpdate()->first();
        abort_unless($p && \App\Models\SubcontractAgreement::query()->visibleTo($request->user())->whereKey($p->agreement_id)->exists(), 403);
        $err = null;
        if (! $p || $p->status !== 'initiated' || $p->payment_voucher_id) {
            $err = __('This subcontract payment already has a voucher or was cancelled.');
        } elseif (! optional($p->request)->status || $p->request->status !== \App\Models\SubcontractPaymentRequest::APPROVED) {
            $err = __('The subcontract request is no longer approved.');
        } elseif (! $p->agreement->isPayable()) {
            $err = __('The agreement is :s: it cannot be paid.', ['s' => strtolower(__($p->agreement->status))]);
        } elseif ((int) ($data['payee_contact_id'] ?? 0) !== (int) $p->agreement->contact_id) {
            $err = __('The payee must be the subcontractor of the agreement.');
        } elseif (bccomp(number_format((float) $data['total_amount'], 2, '.', ''), (string) $p->amount_tzs, 2) !== 0) {
            $err = __('The voucher total must be :a (the amount approved for this subcontract payment).', ['a' => number_format((float) $p->amount_tzs, 2)]);
        }
        if ($err) {
            throw \Illuminate\Validation\ValidationException::withMessages(['subcontract_payment_id' => $err]);
        }

        return $p;
    }

    private function lockFundItemForLink(Request $request, int $itemId): FundAllocationItem
    {
        $item = FundAllocationItem::whereKey($itemId)->lockForUpdate()->first();
        $item?->load('allocation');

        if (! $item || ! $item->allocation || ! $this->userCanActOnFundItem($request->user(), $item)) {
            throw ValidationException::withMessages([
                'fund_allocation_item_id' => __('You cannot link a voucher to this Funds Allocation line (the sheet must be Approved or In Progress and you must be its payer).'),
            ]);
        }
        if ($item->payment_voucher_id) {
            throw ValidationException::withMessages([
                'fund_allocation_item_id' => __('This Funds Allocation line already has a payment voucher linked.'),
            ]);
        }

        return $item;
    }

    /** What a voucher for a Funds Allocation line must pay: the allocated amount, or the sum of its payment lines when no amount was allocated. Same rule as the pre-filled form. */
    private function fundItemAmount(FundAllocationItem $item): string
    {
        $amount = round((float) $item->allocated_amount, 2);
        if ($amount <= 0) {
            $amount = round((float) DB::table('fund_allocation_item_lines')->where('fund_allocation_item_id', $item->id)->sum('amount'), 2);
        }

        return number_format($amount, 2, '.', '');
    }

    /** A voucher that pays a Funds Allocation line carries that line's amount: it cannot be raised or lowered on the voucher form. */
    private function guardFundItemAmount(FundAllocationItem $item, array $data): void
    {
        $expected = $this->fundItemAmount($item);
        if (bccomp($expected, '0.00', 2) > 0 && bccomp((string) $data['total_amount'], $expected, 2) !== 0) {
            throw ValidationException::withMessages([
                'items' => __('This voucher pays a Funds Allocation line: the total must be :a, the amount of that line.', ['a' => number_format((float) $expected, 2)]),
            ]);
        }
    }

    /** A cancelled or deleted voucher no longer pays its Funds Allocation line: the line is freed so another voucher can be linked to it. */
    private function releaseFundItems(PaymentVoucher $voucher, string $why): void
    {
        FundAllocationItem::where('payment_voucher_id', $voucher->id)->lockForUpdate()->get()->each(function (FundAllocationItem $item) use ($voucher, $why) {
            $item->update(['payment_voucher_id' => null]);
            log_activity(
                __('Voucher :pv unlinked from ":item" (:why).', ['pv' => $voucher->pv_number, 'item' => $item->description, 'why' => $why]),
                ['pv_number' => $voucher->pv_number, 'fund_allocation_item_id' => $item->id],
                $item->allocation ?? $voucher,
                'Funds Allocation'
            );
        });
    }

    private function createVoucherFromRequest(Request $request, array $data): PaymentVoucher
    {
        $voucher = PaymentVoucherNumbers::createVoucher([
            'payee_type' => $data['payee_type'],
            'payee_contact_id' => $data['payee_contact_id'] ?? null,
            'staff_id' => $data['staff_id'] ?? null,
            'payee_name' => $data['payee_name'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'type' => $data['type'],
            'voucher_date' => $data['voucher_date'],
            'total_amount' => $data['total_amount'],
            'amount_in_words' => $data['amount_in_words'],
            'status' => PaymentVoucher::STATUS_DRAFT,
            'prepared_by_user_id' => $request->user()->id,
            'recipient_name' => $data['recipient_name'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'supplier_invoice_id' => $data['supplier_invoice_id'] ?? null,
            'payment_request_id' => $data['payment_request_id'] ?? null,
        ]);

        foreach ($data['items'] as $index => $item) {
            $voucher->items()->create([
                'line_number' => $index + 1,
                'particulars' => PaymentVoucher::particularsText($item['particulars_remainder']),
                'amount' => $item['amount'],
            ]);
            $this->rememberParticular($item['particulars_remainder']);
        }
        $this->rememberType($data['type']);

        if ($request->hasFile('attachments')) {
            $voucher->saveAttachments($request->file('attachments'), 'payment-voucher');
        }

        log_activity(__('Payment Voucher created.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');

        return $voucher;
    }

    public function show($id)
    {
        $voucher = PaymentVoucher::with(['items', 'payeeContact:id,name', 'staff:id,name,staff_number', 'warehouse:id,name', 'asset:id,name,asset_number', 'attachments', 'batch:id,batch_number'])->findOrFail($id);

        return Inertia::render('PaymentVoucher/Show', [
            'paymentVoucher' => $this->presentDetail($voucher),
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'activity' => $this->activityHistory($voucher),
        ]);
    }

    public function edit(Request $request, $id)
    {
        $voucher = PaymentVoucher::with(['items', 'attachments'])->findOrFail($id);
        abort_unless(in_array($voucher->status, [PaymentVoucher::STATUS_DRAFT, PaymentVoucher::STATUS_REJECTED], true), 422, 'Only Draft or Rejected payment vouchers can be edited.');
        $this->guardSubcontractVoucher($voucher);

        return Inertia::render('PaymentVoucher/Form', [
            'paymentVoucher' => $this->presentDetail($voucher),
            'contacts' => $this->contactOptions(),
            'staffs' => $this->staffOptions(),
            'warehouses' => $this->warehouseOptions(),
            'particulars' => $this->particularsBank(),
            'types' => $this->typesBank(),
            'assets' => $this->assetOptions(),
            'companyName' => PaymentVoucher::COMPANY_NAME,
        ]);
    }

    public function update(Request $request, $id)
    {
        $voucher = PaymentVoucher::findOrFail($id);
        abort_unless(in_array($voucher->status, [PaymentVoucher::STATUS_DRAFT, PaymentVoucher::STATUS_REJECTED], true), 422, 'Only Draft or Rejected payment vouchers can be edited.');
        $this->guardSubcontractVoucher($voucher);

        $data = $this->validated($request);

        // a voucher of a subcontract payment keeps the payee and the total that were approved for it
        if ($voucher->subcontract_payment_id && ($sp = \App\Models\SubcontractPayment::find($voucher->subcontract_payment_id))) {
            if ((int) ($data['payee_contact_id'] ?? 0) !== (int) $sp->agreement->contact_id || bccomp(number_format((float) $data['total_amount'], 2, '.', ''), (string) $sp->amount_tzs, 2) !== 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['items' => __('This voucher pays a subcontract request: the payee and the total (:a) cannot be changed.', ['a' => number_format((float) $sp->amount_tzs, 2)])]);
            }
        }

        // a voucher of a contract payment request keeps the subcontractor as payee and the total of ALL the payments it carries
        if ($voucher->subcontract_claim_id && ($sc = \App\Models\SubcontractClaim::withoutGlobalScopes()->find($voucher->subcontract_claim_id))) {
            $carried = (string) DB::table('subcontract_payments')->where('payment_voucher_id', $voucher->id)->whereNotNull('claim_id')->where('status', '<>', 'cancelled')->whereNull('deleted_at')->sum('amount_tzs');
            $carried = bcadd($carried === '' ? '0' : $carried, '0', 2);
            if ((int) ($data['payee_contact_id'] ?? 0) !== (int) $sc->contact_id || bccomp(number_format((float) $data['total_amount'], 2, '.', ''), $carried, 2) !== 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['items' => __('This voucher pays a contract payment request: the payee and the total (:a) cannot be changed.', ['a' => number_format((float) $carried, 2)])]);
            }
        }

        // a labour voucher keeps the amount approved on the labour list for that labourer
        if ($voucher->labour_list_item_id && ($labourItem = \App\Models\LabourListItem::find($voucher->labour_list_item_id))) {
            if (bccomp(number_format($labourItem->finalAmount(), 2, '.', ''), (string) $data['total_amount'], 2) !== 0) {
                throw ValidationException::withMessages(['items' => __('This voucher pays a labourer of a Labour List: the total (:a) cannot be changed.', ['a' => number_format($labourItem->finalAmount(), 2)])]);
            }
        }

        // a voucher of a Funds Allocation line keeps the amount of that line
        if ($fundItem = FundAllocationItem::where('payment_voucher_id', $voucher->id)->first()) {
            $this->guardFundItemAmount($fundItem, $data);
        }

        // A Loading & Unloading voucher keeps its payment in the personnel ledger in step with the voucher (amount, payee).
        $loadingPaymentId = null;
        $wasRejected = false;

        DB::transaction(function () use (&$voucher, $data, $request, &$wasRejected, &$loadingPaymentId, $id) {
            // The status was read before this transaction: lock the row and look again, so an approval (or a delete) that
            // arrived in between cannot be overwritten by this edit.
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($voucher->status, [PaymentVoucher::STATUS_DRAFT, PaymentVoucher::STATUS_REJECTED], true), 422, 'Only Draft or Rejected payment vouchers can be edited.');

            $wasRejected = $voucher->status === PaymentVoucher::STATUS_REJECTED;
            $wasLoading = $voucher->type === \App\Services\Transport\LoadingVoucherService::VOUCHER_TYPE;
            $voucher->update([
                'payee_type' => $data['payee_type'],
                'payee_contact_id' => $data['payee_contact_id'] ?? null,
                'staff_id' => $data['staff_id'] ?? null,
                'payee_name' => $data['payee_name'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'type' => $data['type'],
                'voucher_date' => $data['voucher_date'],
                'total_amount' => $data['total_amount'],
                'amount_in_words' => $data['amount_in_words'],
                'recipient_name' => $data['recipient_name'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_invoice_id' => $data['supplier_invoice_id'] ?? null,
                'payment_request_id' => $data['payment_request_id'] ?? null,
                'status' => $wasRejected ? PaymentVoucher::STATUS_DRAFT : $voucher->status,
            ] + ($wasRejected ? $this->clearedApprovalStamps() : []));

            if ($request->hasFile('attachments')) {
                $voucher->saveAttachments($request->file('attachments'), 'payment-voucher');
            }

            $loadingPaymentId = \App\Services\Transport\LoadingVoucherService::syncOnEdit($voucher, $wasLoading, (float) $data['total_amount']);

            // The lines are written again, so each line keeps the transport trip it was created for: by its own id when the
            // form sent one, otherwise by its position. The trips of the voucher are also remembered as a set on the voucher itself,
            // so lines may be merged or reworded without the voucher losing the trips it pays (see tripIds()).
            $oldLines = $voucher->items()->get(['id', 'line_number', 'transport_record_id']);
            $tripSet = $this->tripIds($voucher, $oldLines);
            $byId = $oldLines->keyBy('id');
            $sentIds = collect($data['items'])->contains(fn ($item) => ! empty($item['id']));
            $oldByPosition = $oldLines->values();

            $voucher->items()->delete();
            foreach ($data['items'] as $index => $item) {
                $old = $sentIds ? $byId->get((int) ($item['id'] ?? 0)) : $oldByPosition->get($index);
                $voucher->items()->create([
                    'line_number' => $index + 1,
                    'transport_record_id' => $old?->transport_record_id,
                    'transport_loading_payment_id' => $index === 0 ? $loadingPaymentId : null,
                    'particulars' => PaymentVoucher::particularsText($item['particulars_remainder']),
                    'amount' => $item['amount'],
                ]);
                $this->rememberParticular($item['particulars_remainder']);
            }
            if ($tripSet) {
                $voucher->forceFill(['extra_attributes' => array_merge($this->extraAttributes($voucher), ['transport_record_ids' => $tripSet])])->save();
            }
        });
        $this->rememberType($data['type']);

        log_activity(__('Payment Voucher updated.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');

        PaymentVoucherNotifier::fire('updated', $voucher->fresh(), $request->user(), 'Payment Voucher Edited', 'Payment Voucher ' . $voucher->pv_number . ' was edited by ' . $request->user()->name . '.', ['skip_actor' => true]);

        return redirect()->route('payment-vouchers.show', $id)->with('message', __('Payment Voucher updated.'));
    }

    /** The approval and authorisation of an earlier round: a voucher that goes back to Draft or Submitted starts the approvals again. */
    private function clearedApprovalStamps(): array
    {
        return [
            'approval_remarks' => null,
            'approved_by_user_id' => null, 'approved_at' => null,
            'authorised_by_user_id' => null, 'authorised_at' => null,
        ];
    }

    private function extraAttributes(PaymentVoucher $voucher): array
    {
        $attributes = $voucher->extra_attributes;

        return is_array($attributes) ? $attributes : ($attributes ? $attributes->toArray() : []);
    }

    /** The transport trips a voucher pays: the ones remembered on the voucher (extra_attributes) plus the ones its lines name. */
    private function tripIds(PaymentVoucher $voucher, $lines = null): array
    {
        $lines ??= $voucher->items()->get(['transport_record_id']);

        return collect($this->extraAttributes($voucher)['transport_record_ids'] ?? [])->merge($lines->pluck('transport_record_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function destroy(Request $request, $id)
    {
        $voucher = PaymentVoucher::findOrFail($id);
        abort_unless($voucher->status === PaymentVoucher::STATUS_DRAFT, 422, 'Only Draft payment vouchers can be deleted.');

        DB::transaction(function () use (&$voucher, $id) {
            // The status was read before this transaction: lock the row and look again, so a voucher that was approved in the meantime is not deleted.
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->status === PaymentVoucher::STATUS_DRAFT, 422, 'Only Draft payment vouchers can be deleted.');

            log_activity(__('Payment Voucher deleted.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');

            // The Draft-only guard above means no Transport Record linked to
            // this voucher was ever bumped to Pending Payment (see
            // UpdateTransportRecordsFromVoucher — it only fires once a
            // voucher leaves Draft), so this was the only place a deleted
            // voucher's trips were left permanently orphaned: still pointing
            // at payment_voucher_id of a now-gone voucher, which silently
            // blocked TransportRecordPolicy::createPaymentVoucher() from
            // ever allowing a new one to be created for them again.
            // A Loading & Unloading payment waiting on this voucher is released (the amount becomes available again).
            \App\Models\TransportLoadingPayment::pendingVoucher()->where('payment_voucher_id', $voucher->id)->get()->each(function ($loadingPayment) use ($voucher) {
                log_activity(__('Payment Voucher deleted, Loading & Unloading payment released.'), ['contact_id' => $loadingPayment->contact_id, 'amount' => $loadingPayment->amount, 'pv_number' => $voucher->pv_number], $voucher, 'Transport Loading');
                $loadingPayment->delete();
            });

            $linkedTrips = \App\Models\TransportRecord::where('payment_voucher_id', $voucher->id)->lockForUpdate()->get();
            foreach ($linkedTrips as $trip) {
                $trip->update([
                    'payment_voucher_id' => null,
                    // Recomputed, not blindly reset to Unpaid — a trip can
                    // already carry a payment made before this voucher
                    // existed (e.g. a manual cash payment recorded, then a
                    // voucher created for the remaining outstanding), and
                    // that payment must stay reflected.
                    'payment_status' => $trip->recomputedPaymentStatus(),
                ]);
                log_activity(__('Payment Voucher deleted, trip unlinked.'), ['trip_number' => $trip->trip_number, 'pv_number' => $voucher->pv_number], $trip, 'Transport Record');
            }

            // a Funds Allocation line that waited on this voucher is free again
            $this->releaseFundItems($voucher, __('voucher deleted'));
            // the payments of a contract payment request that waited on this voucher are free again, all of them at once
            \App\Services\Subcontracts\SubcontractClaims::releaseVoucher($voucher->id, __('voucher deleted'));

            $voucher->delete();
        });

        PaymentVoucherNotifier::fire('deleted', $voucher, $request->user(), 'Payment Voucher Deleted', 'Payment Voucher ' . $voucher->pv_number . ' was deleted by ' . auth()->user()->name . '.', ['skip_actor' => true]);

        return redirect()->route('payment-vouchers.index')->with('message', __('Payment Voucher removed.'));
    }

    public function submit(Request $request, $id)
    {
        $this->authorize('update-payment-vouchers');

        DB::transaction(function () use ($id) {
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($voucher->status, [PaymentVoucher::STATUS_DRAFT, PaymentVoucher::STATUS_REJECTED], true), 422, 'This payment voucher must be Draft or Rejected to submit.');
            $this->guardSubcontractVoucher($voucher);

            // A rejected voucher let its trips go; they are taken again here, or the voucher is not sent if another voucher has them.
            $this->relinkTrips($voucher);

            // A voucher that goes round again carries none of the stamps of the earlier round.
            $voucher->update(['status' => PaymentVoucher::STATUS_SUBMITTED] + $this->clearedApprovalStamps());
            log_activity(__('Payment Voucher submitted for approval.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
            event(new PaymentVoucherStatusChanged($voucher->fresh()));
        });

        return back()->with('message', __('Payment Voucher submitted for approval.'));
    }

    /**
     * The transport trips of the voucher (its lines, and the set remembered on it) belong to the voucher while it is alive. Rejecting a voucher unlinks them
     * (UpdateTransportRecordsFromVoucher), so submitting it again links them back, under a lock; if a trip has meanwhile been
     * taken by another live voucher, or was paid or cancelled, the voucher is refused instead of paying a trip twice.
     */
    private function relinkTrips(PaymentVoucher $voucher): void
    {
        $tripIds = collect($this->tripIds($voucher));
        if ($tripIds->isEmpty()) {
            return;
        }

        $trips = \App\Models\TransportRecord::whereIn('id', $tripIds)->lockForUpdate()->get();
        $problems = [];
        foreach ($tripIds as $tripId) {
            $trip = $trips->firstWhere('id', $tripId);
            if (! $trip || $trip->payment_status === \App\Models\TransportRecord::STATUS_CANCELLED) {
                $problems[] = __('trip :t no longer exists or is cancelled', ['t' => $trip?->trip_number ?? '#' . $tripId]);
            } elseif ($trip->payment_voucher_id && (int) $trip->payment_voucher_id !== (int) $voucher->id && $this->isLiveVoucher((int) $trip->payment_voucher_id)) {
                $problems[] = __('trip :t is already on another payment voucher', ['t' => $trip->trip_number]);
            } elseif ($trip->payment_voucher_id === null && $trip->outstanding_amount <= 0) {
                $problems[] = __('trip :t is already paid', ['t' => $trip->trip_number]);
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['items' => __('This voucher cannot be sent: :why. Edit the voucher lines first.', ['why' => implode('; ', $problems)])]);
        }

        foreach ($trips as $trip) {
            if ((int) $trip->payment_voucher_id !== (int) $voucher->id) {
                $trip->update(['payment_voucher_id' => $voucher->id]);
                log_activity(__('Payment Voucher submitted again, trip linked to it.'), ['trip_number' => $trip->trip_number, 'pv_number' => $voucher->pv_number], $trip, 'Transport Record');
            }
        }
    }

    /** A voucher that still stands (not deleted, rejected or cancelled). */
    private function isLiveVoucher(int $id): bool
    {
        $status = PaymentVoucher::whereKey($id)->value('status');

        return $status !== null && ! in_array($status, [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED], true);
    }

    public function approve(Request $request, $id)
    {
        $this->authorize('approve-payment-vouchers');

        DB::transaction(function () use ($id, $request) {
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->status === PaymentVoucher::STATUS_SUBMITTED, 422, 'This payment voucher must be Submitted for this action.');

            $voucher->update([
                'status' => PaymentVoucher::STATUS_APPROVED,
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => now(),
            ]);
            log_activity(__('Payment Voucher approved.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
            event(new PaymentVoucherStatusChanged($voucher->fresh()));
        });

        return back()->with('message', __('Payment Voucher approved.'));
    }

    public function rejectApproval(Request $request, $id)
    {
        $this->authorize('approve-payment-vouchers');

        $data = $request->validate(['approval_remarks' => 'required|string|max:1000']);

        DB::transaction(function () use ($id, $data) {
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->status === PaymentVoucher::STATUS_SUBMITTED, 422, 'This payment voucher must be Submitted for this action.');

            $voucher->update(['status' => PaymentVoucher::STATUS_REJECTED, 'approval_remarks' => $data['approval_remarks']]);
            log_activity(__('Payment Voucher rejected at approval stage.'), ['pv_number' => $voucher->pv_number, 'remarks' => $data['approval_remarks']], $voucher, 'Payment Voucher');
            event(new PaymentVoucherStatusChanged($voucher->fresh()));
        });

        return back()->with('message', __('Payment Voucher rejected.'));
    }

    public function authorise(Request $request, $id)
    {
        $this->authorize('authorise-payment-vouchers');

        DB::transaction(function () use ($id, $request) {
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->status === PaymentVoucher::STATUS_APPROVED, 422, 'This payment voucher must be Approved for this action.');

            $voucher->update([
                'status' => PaymentVoucher::STATUS_AUTHORISED,
                'authorised_by_user_id' => $request->user()->id,
                'authorised_at' => now(),
            ]);
            // Signatories finalisation guard: a required signature set to block the final step stops (and rolls back) the authorisation.
            abort_if(($missing = $this->missingSignatures($voucher)) !== null, 422, $missing ?? '');
            log_activity(__('Payment Voucher authorised.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
            event(new PaymentVoucherStatusChanged($voucher->fresh()));
        });

        return back()->with('message', __('Payment Voucher authorised.'));
    }

    public function rejectAuthorisation(Request $request, $id)
    {
        $this->authorize('authorise-payment-vouchers');

        $data = $request->validate(['approval_remarks' => 'required|string|max:1000']);

        DB::transaction(function () use ($id, $data) {
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->status === PaymentVoucher::STATUS_APPROVED, 422, 'This payment voucher must be Approved for this action.');

            $voucher->update(['status' => PaymentVoucher::STATUS_REJECTED, 'approval_remarks' => $data['approval_remarks']]);
            log_activity(__('Payment Voucher rejected at authorisation stage.'), ['pv_number' => $voucher->pv_number, 'remarks' => $data['approval_remarks']], $voucher, 'Payment Voucher');
            event(new PaymentVoucherStatusChanged($voucher->fresh()));
        });

        return back()->with('message', __('Payment Voucher rejected.'));
    }

    /**
     * Bulk stage-1 approval — reuses the exact same authorization and status
     * guard as the single-record approve(), just looped. Vouchers not
     * currently Submitted are silently skipped (not an error) and reported
     * back in the flash message, so one stray Draft/Rejected row in a
     * selection never blocks the rest of the batch.
     */
    public function bulkApprove(Request $request)
    {
        $this->authorize('approve-payment-vouchers');

        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);

        $candidateIds = PaymentVoucher::whereIn('id', $data['ids'])->where('status', PaymentVoucher::STATUS_SUBMITTED)->pluck('id');
        $processed = 0;

        foreach ($candidateIds as $id) {
            DB::transaction(function () use ($id, $request, &$processed) {
                $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->first();
                if (! $voucher || $voucher->status !== PaymentVoucher::STATUS_SUBMITTED) {
                    return;
                }

                $voucher->update([
                    'status' => PaymentVoucher::STATUS_APPROVED,
                    'approved_by_user_id' => $request->user()->id,
                    'approved_at' => now(),
                ]);
                log_activity(__('Payment Voucher approved.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
                event(new PaymentVoucherStatusChanged($voucher->fresh()));
                $processed++;
            });
        }

        $skipped = count($data['ids']) - $processed;

        return back()->with('message', $this->bulkSummary($processed, $skipped, 'approved'));
    }

    /**
     * Bulk fast-track: stage-1 approve then stage-2 authorise in one action,
     * for whichever selected vouchers are eligible for each step — requires
     * both permissions, exactly like doing the two single-record actions
     * back to back would. A Submitted voucher gets both stamps; an already-
     * Approved voucher only gets authorised; anything else is skipped.
     */
    public function bulkApproveAndAuthorise(Request $request)
    {
        $this->authorize('approve-payment-vouchers');
        $this->authorize('authorise-payment-vouchers');

        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);

        $candidateIds = PaymentVoucher::whereIn('id', $data['ids'])
            ->whereIn('status', [PaymentVoucher::STATUS_SUBMITTED, PaymentVoucher::STATUS_APPROVED])
            ->pluck('id');
        $processed = 0;

        $blocked = [];

        foreach ($candidateIds as $id) {
            try {
                DB::transaction(function () use ($id, $request, &$processed) {
                    $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->first();
                    if (! $voucher || ! in_array($voucher->status, [PaymentVoucher::STATUS_SUBMITTED, PaymentVoucher::STATUS_APPROVED], true)) {
                        return;
                    }

                    if ($voucher->status === PaymentVoucher::STATUS_SUBMITTED) {
                        $voucher->update([
                            'status' => PaymentVoucher::STATUS_APPROVED,
                            'approved_by_user_id' => $request->user()->id,
                            'approved_at' => now(),
                        ]);
                        log_activity(__('Payment Voucher approved.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
                    }

                    $voucher->update([
                        'status' => PaymentVoucher::STATUS_AUTHORISED,
                        'authorised_by_user_id' => $request->user()->id,
                        'authorised_at' => now(),
                    ]);
                    // Signatories finalisation guard: same rule as authorise(); a blocked voucher is left exactly as it was.
                    if (($missing = $this->missingSignatures($voucher)) !== null) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['signatures' => [$voucher->pv_number . ': ' . $missing]]);
                    }
                    log_activity(__('Payment Voucher authorised.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
                    event(new PaymentVoucherStatusChanged($voucher->fresh()));
                    $processed++;
                });
            } catch (\Illuminate\Validation\ValidationException $e) {
                $blocked[] = $e->errors()['signatures'][0] ?? '';
            }
        }

        $skipped = count($data['ids']) - $processed - count($blocked);

        $message = $this->bulkSummary($processed, $skipped, 'approved and authorised');
        if ($blocked) {
            $message .= ' ' . count($blocked) . ' left unchanged because required signatures are missing: ' . implode(' | ', $blocked);
        }

        return back()->with('message', $message);
    }

    /**
     * Signatories finalisation guard. Returns a message naming the missing signatures when the document setup marks a signature as
     * required AND blocking, otherwise null. Nothing is blocked until an administrator turns that on.
     * Authorising checks every signature except "paid" (the payment cannot exist yet); recording the payment checks the "paid" one.
     */
    private function missingSignatures(PaymentVoucher $voucher, string $stage = 'authorise'): ?string
    {
        $blockers = array_values(array_filter(
            app(\App\Services\Signatories\SignatureResolver::class)->blockers('payment_voucher', $voucher->fresh()),
            fn ($b) => $stage === 'paid' ? $b['action'] === 'paid' : $b['action'] !== 'paid'
        ));
        if (! $blockers) {
            return null;
        }

        return 'This payment voucher cannot be ' . ($stage === 'paid' ? 'marked as paid' : 'authorised') . ' yet. Missing signatures: ' . collect($blockers)->map(fn ($b) => $b['role_label'] . ' (' . $b['detail'] . ')')->implode('; ');
    }

    private function bulkSummary(int $done, int $skipped, string $verb): string
    {
        $message = "{$done} payment voucher(s) {$verb}.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (not eligible for this action).";
        }

        return $message;
    }

    public function cancel(Request $request, $id)
    {
        $this->authorize('approve-payment-vouchers');

        DB::transaction(function () use ($id) {
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($voucher->status, [PaymentVoucher::STATUS_AUTHORISED, PaymentVoucher::STATUS_CANCELLED, PaymentVoucher::STATUS_REJECTED], true), 422, 'This payment voucher can no longer be cancelled.');

            $voucher->update(['status' => PaymentVoucher::STATUS_CANCELLED]);
            // a Funds Allocation line that waited on this voucher is free again
            $this->releaseFundItems($voucher, __('voucher cancelled'));
            \App\Services\Subcontracts\SubcontractClaims::releaseVoucher($voucher->id, __('voucher cancelled'));
            log_activity(__('Payment Voucher cancelled.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
            event(new PaymentVoucherStatusChanged($voucher->fresh()));
        });

        return back()->with('message', __('Payment Voucher cancelled.'));
    }

    /**
     * Lightweight, additive "payment completed" record layered on top of
     * Authorised — see the paid_at/payment_reference/payment_method columns
     * — deliberately NOT a new core status, so it touches none of the
     * existing status-based conditionals across this module.
     */
    public function recordPayment(Request $request, $id)
    {
        $this->authorize('record-payment-payment-vouchers');

        $data = $request->validate([
            'payment_reference' => 'nullable|string|max:190',
            'payment_method' => 'nullable|string|max:100',
        ]);

        // lockForUpdate() + re-checking paid_at inside the transaction is
        // what actually makes "only settable once" hold under concurrency —
        // without it, two near-simultaneous requests (double-click, two open
        // tabs) could both read paid_at as null before either commits, both
        // pass the guard, and the second silently overwrite the first's
        // payment_reference/payment_method/paid_by_user_id.
        //
        // The paid stamp and everything that follows from it (Loading & Unloading payments, transport trips) are ONE
        // transaction: if any part fails nothing is recorded, so a retry never meets "already recorded as paid" on a half-done payment.
        // Notices are sent only after the commit.
        [$voucher, $paidTrips] = DB::transaction(function () use ($id, $data, $request) {
            $voucher = PaymentVoucher::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->status === PaymentVoucher::STATUS_AUTHORISED, 422, 'Only an Authorised payment voucher can be marked as paid.');
            abort_if(! empty($voucher->paid_at), 422, 'This payment voucher has already been recorded as paid.');

            $voucher->update([
                'paid_at' => now(),
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'paid_by_user_id' => $request->user()->id,
            ]);
            // Signatories: a "paid" signature set up as required and blocking stops (and rolls back) the payment, like authorise() does for its stage.
            abort_if(($missing = $this->missingSignatures($voucher, 'paid')) !== null, 422, $missing ?? '');

            log_activity(__('Payment Voucher marked as paid.'), ['pv_number' => $voucher->pv_number, 'payment_reference' => $data['payment_reference'] ?? null], $voucher, 'Payment Voucher');

            // Loading & Unloading personnel paid through this voucher: their pending payment becomes a real (Paid) one.
            foreach (\App\Models\TransportLoadingPayment::pendingVoucher()->where('payment_voucher_id', $voucher->id)->get() as $loadingPayment) {
                $loadingPayment->update([
                    'status' => \App\Models\TransportLoadingPayment::PAID,
                    'payment_date' => $voucher->paid_at?->toDateString() ?? now()->toDateString(),
                    'method' => $voucher->payment_method,
                    'reference' => $voucher->payment_reference ?: $voucher->pv_number,
                    'paid_by_user_id' => $voucher->paid_by_user_id,
                ]);
                log_activity(__('Loading & Unloading payment completed via Payment Voucher.'), ['contact_id' => $loadingPayment->contact_id, 'amount' => $loadingPayment->amount, 'pv_number' => $voucher->pv_number], $voucher, 'Transport Loading');
            }

            return [$voucher, $this->payLinkedTrips($voucher)];
        });

        // Deliberately not re-firing PaymentVoucherStatusChanged here — the
        // voucher's `status` is still Authorised (paid_at is an orthogonal
        // field, not a new status), so that listener's Authorised branch
        // would re-send an identical "authorised" notice a second time.
        // LabourList's payment rollup already recomputes live from paid_at
        // on every read (see LabourList::paymentRollupStatus()), so no
        // separate sync step is needed here.
        if ($voucher->labour_list_id) {
            $labourList = \App\Models\LabourList::find($voucher->labour_list_id);
            if ($labourList && $labourList->paymentRollupStatus() === 'Paid' && $labourList->createdBy) {
                // Who is told, and how, is set on the Payment Vouchers workflow page (event: Labour List Fully Paid).
                PaymentVoucherNotifier::fire('labour_list_paid', $voucher, $request->user(), 'Labour Payment Completed', $labourList->reference . ', all payment vouchers have now been paid.', [
                    'owner_user_id' => (int) $labourList->created_by_user_id, 'url' => route('labour.show', $labourList->id),
                    'values' => ['reference' => $labourList->reference],
                ]);
            }
        }

        if ($paidTrips->isNotEmpty()) {
            // Who is told, and how, is set on the Payment Vouchers workflow page (event: Transport Trips Paid).
            $creatorIds = $paidTrips->pluck('created_by_user_id')->filter()->unique()->values();
            PaymentVoucherNotifier::fire('transport_trips_paid', $voucher, $request->user(), 'Transport Payment Completed', $voucher->pv_number . ' has been paid, linked transport trips are now marked Paid.', [
                'owner_user_id' => (int) $creatorIds->first(), 'owner_users' => $creatorIds->slice(1)->map(fn ($id) => ['id' => $id])->all(),
                'url' => route('transport-records.index'),
            ]);
        }

        if ($voucher->subcontract_payment_id && ($sp = \App\Models\SubcontractPayment::with('request')->find($voucher->subcontract_payment_id)) && $sp->request) {
            log_activity(__('Subcontract payment confirmed by voucher :pv', ['pv' => $voucher->pv_number]), ['pv_number' => $voucher->pv_number, 'amount' => (string) $sp->amount, 'paid_at' => optional($voucher->paid_at)->toDateTimeString()], $sp->agreement, 'Subcontracts');
            \App\Services\Subcontracts\SubcontractNotifier::fire('payment_paid', 'request', $sp->request, $request->user(), $voucher->pv_number, ['amount' => (string) $sp->amount]);
        }
        if ($voucher->subcontract_claim_id && ($sc = \App\Models\SubcontractClaim::withoutGlobalScopes()->find($voucher->subcontract_claim_id))) {
            $paidPayments = \App\Models\SubcontractPayment::with('agreement')->where('payment_voucher_id', $voucher->id)->whereNotNull('claim_id')->where('status', '<>', 'cancelled')->get();
            foreach ($paidPayments as $cp) {
                log_activity(__('Contract payment confirmed by voucher :pv', ['pv' => $voucher->pv_number]), ['pv_number' => $voucher->pv_number, 'claim' => $sc->reference, 'amount' => (string) $cp->amount, 'amount_tzs' => (string) $cp->amount_tzs, 'paid_at' => optional($voucher->paid_at)->toDateTimeString()], $cp->agreement, 'Subcontracts');
            }
            log_activity(__('Contract payment request :c paid by voucher :pv', ['c' => $sc->reference, 'pv' => $voucher->pv_number]), ['pv_number' => $voucher->pv_number, 'allocations' => $paidPayments->count(), 'paid_at' => optional($voucher->paid_at)->toDateTimeString()], $sc, 'Subcontracts');
            \App\Services\Subcontracts\SubcontractNotifier::fire('claim_paid', 'claim', $sc, $request->user(), $voucher->pv_number, ['amount' => $paidPayments->reduce(fn ($t, $p) => bcadd($t, (string) $p->amount, 2), '0.00')]);
        }
        PaymentVoucherNotifier::fire('paid', $voucher, $request->user(), 'Payment Voucher Paid', 'Payment Voucher ' . $voucher->pv_number . ' has been paid.', ['values' => ['actor' => $request->user()->name]]);

        return back()->with('message', __('Payment Voucher marked as paid.'));
    }

    /**
     * Same inline-sync convention as the Labour block — Transport Records don't fire/listen for a "voucher paid" event
     * (see UpdateTransportRecordsFromVoucher's own docblock). Call inside the payment transaction.
     *
     * Each trip is paid what its OWN line on the voucher says (never the whole trip amount just because the voucher exists), and never
     * more than it still owes. What the voucher total has left after those lines (all of it, for an older voucher whose lines lost their trips)
     * is shared in order over the trips without a line of their own. A trip left partly paid is let go from the voucher, so the rest can be paid by another voucher or by hand; a trip that
     * received nothing is let go the same way.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\TransportRecord> the trips that were paid something
     */
    private function payLinkedTrips(PaymentVoucher $voucher)
    {
        $trips = \App\Models\TransportRecord::where('payment_voucher_id', $voucher->id)->orderBy('id')->lockForUpdate()->get();
        $paidTrips = collect();
        if ($trips->isEmpty()) {
            return $paidTrips;
        }

        $lines = $voucher->items()->get(['transport_record_id', 'amount'])->whereNotNull('transport_record_id');
        $perTrip = $lines->groupBy('transport_record_id')->map(fn ($group) => (string) $group->reduce(fn ($sum, $line) => bcadd($sum, number_format((float) $line->amount, 2, '.', ''), 2), '0.00'));
        // what is left of the voucher total after the trips that have a line of their own: shared, in order, over the trips without one
        $remaining = number_format((float) $voucher->total_amount, 2, '.', '');
        foreach ($trips as $trip) {
            if ($perTrip->has($trip->id)) {
                $remaining = bcsub($remaining, bccomp($perTrip[$trip->id], number_format($trip->outstanding_amount, 2, '.', ''), 2) > 0 ? number_format($trip->outstanding_amount, 2, '.', '') : $perTrip[$trip->id], 2);
            }
        }
        $remaining = bccomp($remaining, '0.00', 2) < 0 ? '0.00' : $remaining;
        $paidDate = $voucher->paid_at?->toDateString() ?? now()->toDateString();

        foreach ($trips as $trip) {
            $owed = number_format($trip->outstanding_amount, 2, '.', '');
            $wanted = $perTrip->has($trip->id) ? $perTrip[$trip->id] : $remaining;
            $pay = bccomp($wanted, $owed, 2) > 0 ? $owed : $wanted;
            if (! $perTrip->has($trip->id)) {
                $remaining = bcsub($remaining, $pay, 2);
            }

            if (bccomp($pay, '0.00', 2) <= 0) {
                $trip->update(['payment_voucher_id' => null, 'payment_status' => $trip->recomputedPaymentStatus()]);
                log_activity(__('Payment Voucher paid, trip had no amount on it and was unlinked.'), ['trip_number' => $trip->trip_number, 'pv_number' => $voucher->pv_number], $trip, 'Transport Record');

                continue;
            }

            $trip->paid_amount = bcadd(number_format((float) $trip->paid_amount, 2, '.', ''), $pay, 2);
            $fully = bccomp($trip->paid_amount, number_format((float) $trip->amount, 2, '.', ''), 2) >= 0;
            $trip->fill([
                'payment_status' => $fully ? \App\Models\TransportRecord::STATUS_PAID : \App\Models\TransportRecord::STATUS_PARTIALLY_PAID,
                'payment_date' => $paidDate,
                'paid_by_user_id' => $voucher->paid_by_user_id,
                'payment_reference' => $voucher->payment_reference,
                'payment_method' => $voucher->payment_method,
            ]);
            if (! $fully) {
                $trip->payment_voucher_id = null;
            }
            $trip->save();
            log_activity(__('Transport Record payment completed via Payment Voucher.'), ['trip_number' => $trip->trip_number, 'pv_number' => $voucher->pv_number, 'amount' => $pay], $trip, 'Transport Record');
            $paidTrips->push($trip);
        }

        return $paidTrips;
    }

    public function print(Request $request, $id)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        $voucher = PaymentVoucher::with(['items', 'payeeContact:id,name', 'warehouse:id,name'])->findOrFail($id);
        $this->recordPrint($voucher);

        return view('payment-vouchers.print', $this->printData($voucher, false));
    }

    public function pdf(Request $request, $id)
    {
        $this->authorize('read-payment-vouchers');
        $this->authorize('print-payment-vouchers');

        $voucher = PaymentVoucher::with(['items', 'payeeContact:id,name', 'warehouse:id,name'])->findOrFail($id);
        $this->recordPrint($voucher);

        return Pdf::loadView('payment-vouchers.print', $this->printData($voucher, true))
            ->download('payment-voucher-' . $voucher->pv_number . '.pdf');
    }

    private function recordPrint(PaymentVoucher $voucher): void
    {
        $isFirstPrint = $voucher->printed_count === 0;
        // one update for both columns (the print itself is logged below)
        $voucher->increment('printed_count', 1, $isFirstPrint ? ['first_printed_at' => now()] : []);
        log_activity($isFirstPrint ? __('Payment Voucher printed.') : __('Payment Voucher reprinted.'), ['pv_number' => $voucher->pv_number, 'print_count' => $voucher->printed_count], $voucher, 'Payment Voucher');
    }

    private function printData(PaymentVoucher $voucher, bool $isPdf): array
    {
        return [
            'voucher' => $this->presentDetail($voucher),
            'companyName' => PaymentVoucher::COMPANY_NAME,
            'qr' => \App\Support\PrintQrCode::dataUri(route('payment-vouchers.show', $voucher->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('payment_voucher', $voucher),
        ];
    }

    public function downloadAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('read-payment-vouchers');
        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === PaymentVoucher::class, 404);
        // a deleted voucher is gone, and so are its files (findOrFail leaves out soft-deleted rows)
        PaymentVoucher::findOrFail($id);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    private function validated(Request $request): array
    {
        $exists = fn (string $table) => Rule::exists($table, 'id')->whereNull('deleted_at');

        $data = $request->validate([
            'payee_type' => 'required|in:' . PaymentVoucher::PAYEE_TYPE_STAFF . ',' . PaymentVoucher::PAYEE_TYPE_EXTERNAL,
            'payee_contact_id' => ['nullable', 'integer', $exists('contacts')],
            'staff_id' => ['nullable', 'integer', Rule::exists('staffs', 'id')->whereNull('deleted_at')->whereIn('status', Staff::PAYROLL_STATUSES), 'required_if:payee_type,' . PaymentVoucher::PAYEE_TYPE_STAFF],
            // typed name is only needed when no contact is chosen: a chosen contact supplies its own name
            'payee_name' => ['nullable', 'string', 'max:190', Rule::requiredIf(fn () => $request->input('payee_type') === PaymentVoucher::PAYEE_TYPE_EXTERNAL && empty($request->input('payee_contact_id')))],
            'warehouse_id' => ['nullable', 'integer', $exists('warehouses')],
            'asset_id' => 'nullable|integer|exists:assets,id',
            'type' => 'required|string|max:100',
            'voucher_date' => ['required', 'date', 'after:2000-01-01', 'before:' . now()->addYear()->toDateString()],
            'recipient_name' => 'nullable|string|max:190',
            'internal_notes' => 'nullable|string|max:2000',
            'remarks' => 'nullable|string|max:2000',
            'purchase_order_id' => ['nullable', 'integer', $exists('purchase_orders')],
            'supplier_invoice_id' => ['nullable', 'integer', $exists('supplier_invoices')],
            'payment_request_id' => ['nullable', 'integer', $exists('payment_requests')],
            'items' => 'required|array|min:1|max:200',
            'items.*.id' => 'nullable|integer',
            'items.*.particulars_remainder' => 'required|string|max:' . PaymentVoucher::PARTICULARS_MAX,
            'items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:' . PaymentVoucher::MAX_LINE_AMOUNT, 'regex:/^\d+(\.\d{1,2})?$/'],
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        if ($data['payee_type'] === PaymentVoucher::PAYEE_TYPE_STAFF) {
            $data['payee_contact_id'] = null;
            $data['payee_name'] = Staff::findOrFail($data['staff_id'])->name;
        } else {
            $data['staff_id'] = null;
            // a chosen contact is printed under its own name, whatever was typed
            if (! empty($data['payee_contact_id'])) {
                $data['payee_name'] = Contact::findOrFail($data['payee_contact_id'])->name;
            }
        }

        // Total is always server-computed from the line items — a client-sent
        // total is never trusted, since this figure drives approval sign-off.
        // Every line is held as its stored 2-decimal figure and the total is the exact sum of those, so the total always equals the printed lines.
        $total = '0.00';
        $data['items'] = array_map(function ($item) use (&$total) {
            $item['particulars_remainder'] = trim($item['particulars_remainder']);
            $item['amount'] = number_format((float) $item['amount'], 2, '.', '');
            $total = bcadd($total, $item['amount'], 2);

            return $item;
        }, $data['items']);
        if (bccomp($total, PaymentVoucher::MAX_TOTAL_AMOUNT, 2) > 0) {
            throw ValidationException::withMessages(['items' => __('The total of the lines is too large (the most a voucher can hold is :max).', ['max' => number_format((float) PaymentVoucher::MAX_TOTAL_AMOUNT, 2)])]);
        }
        $data['type'] = trim($data['type']) ?: 'Administration';

        $data['total_amount'] = $total;
        $data['amount_in_words'] = NumberToWords::convert((float) $total);

        return $data;
    }

    /**
     * Grows the reusable "Being Paid for :" phrase bank — firstOrCreate on
     * the trimmed remainder (never the full prefixed text, since the prefix
     * is constant and adds no distinguishing value to the suggestion list),
     * bumping use_count so create()/edit() can offer the most-used phrases
     * first.
     */
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

    /** Same growable-bank pattern as rememberParticular(), for the Voucher Type list. */
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

    /**
     * Warns (does not block) when a voucher with the same payee, amount, and
     * date was created in the last few days — a preparer accidentally
     * double-submitting the same payment is the realistic case this catches;
     * a deliberate resubmission carries `confirm_duplicate=1` and skips the
     * check entirely.
     */
    private function guardAgainstDuplicate(Request $request, array $data): void
    {
        if ($request->boolean('confirm_duplicate')) {
            return;
        }

        $existing = PaymentVoucher::query()
            ->whereNotIn('status', [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED])
            ->where('total_amount', $data['total_amount'])
            ->where('voucher_date', $data['voucher_date'])
            ->where(function ($q) use ($data) {
                if (! empty($data['payee_contact_id'])) {
                    $q->where('payee_contact_id', $data['payee_contact_id']);
                } else {
                    $q->where('payee_name', $data['payee_name']);
                }
            })
            ->where('created_at', '>=', now()->subDays(3))
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'duplicate' => "Possible duplicate of {$existing->pv_number}, same payee, amount, and date, created recently. Submit again to confirm this is a separate voucher.",
            ]);
        }
    }

    private function activityHistory(PaymentVoucher $voucher): array
    {
        return $voucher->activities()
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
        return Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name'])
            ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values()->all();
    }

    private function assetOptions(): array
    {
        return Asset::active()->orderBy('name')->get(['id', 'name', 'asset_number'])->values()->all();
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

    private function presentSummary(PaymentVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'pv_number' => $voucher->pv_number,
            'payment_voucher_batch_id' => $voucher->payment_voucher_batch_id,
            'batch_number' => $voucher->relationLoaded('batch') ? $voucher->batch?->batch_number : null,
            'type' => $voucher->type,
            'payee_type' => $voucher->payee_type,
            'payee_contact_id' => $voucher->payee_contact_id,
            'staff_id' => $voucher->staff_id,
            'staff_number' => $voucher->relationLoaded('staff') ? $voucher->staff?->staff_number : null,
            'payee_name' => $voucher->payee_name,
            'warehouse_id' => $voucher->warehouse_id,
            'warehouse_name' => $voucher->warehouse_id ? $this->warehouseName($voucher->warehouse_id) : PaymentVoucher::COMPANY_NAME,
            'asset_id' => $voucher->asset_id,
            'asset_name' => $voucher->relationLoaded('asset') ? optional($voucher->asset)->name : null,
            'voucher_date' => optional($voucher->voucher_date)->format('Y-m-d'),
            'total_amount' => (float) $voucher->total_amount,
            'status' => $voucher->status,
            'display_status' => $voucher->display_status,
            'paid_at' => optional($voucher->paid_at)->toIso8601String(),
            'prepared_by_user_id' => $voucher->prepared_by_user_id,
            'prepared_by_name' => $this->userName($voucher->prepared_by_user_id),
            'created_at' => optional($voucher->created_at)->toIso8601String(),
            'printed_count' => $voucher->printed_count,
            'particulars_summary' => $voucher->relationLoaded('items')
                ? $voucher->items->pluck('particulars')->implode('; ')
                : null,
        ];
    }

    private static array $warehouseNameCache = [];

    private function warehouseName(int $id): ?string
    {
        return self::$warehouseNameCache[$id] ??= Warehouse::find($id)?->name;
    }

    private function subcontractLink(int $paymentId): ?array
    {
        $p = \App\Models\SubcontractPayment::with(['request:id,reference', 'agreement:id,reference'])->find($paymentId);

        return $p && $p->request ? ['id' => $p->request->id, 'reference' => $p->request->reference, 'agreement' => optional($p->agreement)->reference, 'url' => route('subcontracts.requests.show', $p->request->id)] : null;
    }

    /** The contract payment request a voucher pays and what it allocates to each agreement (amounts only for people who may see subcontract money). */
    private function subcontractClaimLink(PaymentVoucher $voucher): ?array
    {
        $c = \App\Models\SubcontractClaim::withoutGlobalScopes()->find($voucher->subcontract_claim_id);
        if (! $c) {
            return null;
        }
        $money = (bool) optional(auth()->user())->can('view-financials-subcontracts');

        return ['id' => $c->id, 'reference' => $c->reference, 'status' => $c->status, 'currency' => $c->currency, 'url' => optional(auth()->user())->can('read-subcontract-claims') ? route('subcontracts.claims.show', $c->id) : null,
            'allocations' => \App\Services\Subcontracts\SubcontractClaims::allocationsOfVoucher($voucher->id, $money), 'total_tzs' => $money ? (string) $voucher->total_amount : null];
    }

    private function mayPrepare(): bool
    {
        $user = auth()->user();

        return (bool) $user && ($user->can('create-payment-vouchers') || $user->can('update-payment-vouchers'));
    }

    private function presentDetail(PaymentVoucher $voucher): array
    {
        return array_merge($this->presentSummary($voucher), [
            'amount_in_words' => $voucher->amount_in_words,
            'approved_by_user_id' => $voucher->approved_by_user_id,
            'approved_by_name' => $this->userName($voucher->approved_by_user_id),
            'approved_at' => optional($voucher->approved_at)->toIso8601String(),
            'authorised_by_user_id' => $voucher->authorised_by_user_id,
            'authorised_by_name' => $this->userName($voucher->authorised_by_user_id),
            'authorised_at' => optional($voucher->authorised_at)->toIso8601String(),
            'approval_remarks' => $voucher->approval_remarks,
            'recipient_name' => $voucher->recipient_name,
            'recipient_date' => optional($voucher->recipient_date)->format('Y-m-d'),
            // the internal note is for the people who prepare vouchers; a reader of the voucher does not get it
            'internal_notes' => $this->mayPrepare() ? $voucher->internal_notes : null,
            'remarks' => $voucher->remarks,
            'purchase_order_id' => $voucher->purchase_order_id,
            'purchase_order_reference' => $voucher->purchase_order_id ? PurchaseOrder::find($voucher->purchase_order_id)?->reference : null,
            'supplier_invoice_id' => $voucher->supplier_invoice_id,
            'supplier_invoice_reference' => $voucher->supplier_invoice_id ? SupplierInvoice::find($voucher->supplier_invoice_id)?->reference : null,
            'payment_request_id' => $voucher->payment_request_id,
            'payment_request_reference' => $voucher->payment_request_id ? PaymentRequest::find($voucher->payment_request_id)?->reference : null,
            'subcontract_request' => $voucher->subcontract_payment_id ? $this->subcontractLink($voucher->subcontract_payment_id) : null,
            'subcontract_claim' => $voucher->subcontract_claim_id ? $this->subcontractClaimLink($voucher) : null,
            'labour_list_id' => $voucher->labour_list_id,
            'labour_list_reference' => $voucher->labour_list_id ? \App\Models\LabourList::find($voucher->labour_list_id)?->reference : null,
            'paid_at' => optional($voucher->paid_at)->toIso8601String(),
            'payment_reference' => $voucher->payment_reference,
            'payment_method' => $voucher->payment_method,
            'paid_by_name' => $this->userName($voucher->paid_by_user_id),
            'printed_count' => $voucher->printed_count,
            'first_printed_at' => optional($voucher->first_printed_at)->toIso8601String(),
            'attachments' => $voucher->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->title ?: $a->filename])->values(),
            'items' => $voucher->items->map(fn ($item) => [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'particulars' => $item->particulars,
                'amount' => (float) $item->amount,
            ])->values(),
        ]);
    }
}
