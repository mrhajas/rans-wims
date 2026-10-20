<?php

namespace App\Http\Controllers\Travels;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Travels\Concerns\ManagesFiles;
use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\Travel;
use App\Models\TravelAgentOpening;
use App\Models\TravelAgentPayment;
use App\Models\User;
use App\Services\Travels\TravelLedger;
use App\Services\Travels\TravelVoucherService;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Ticket agents and visa issuers (contacts) and what they are owed: the opening balance (owed before Travels started), the approved travels they issued and the
 * payments made, with the statement. The contact stays in Contacts (category Ticket Agent / Visa Agent); the balance, payments and history live here.
 * A payment is made ONLY through a Payment Voucher that is started here from the balance, then approved and paid in the Payment Vouchers module; once the voucher
 * is paid it reduces the balance. Every voucher is linked here as the payment history.
 */
class AgentController extends Controller
{
    use ManagesFiles;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    private function isAgent(Contact $c): bool
    {
        return in_array((int) $c->id, TravelLedger::agentIds(), true);
    }

    // ---------------------------------------------------------------------------------------------------- list

    public function index(Request $request)
    {
        $this->need('read-travel-agents');
        $f = ['search' => trim((string) $request->query('search', '')), 'currency' => strtoupper(mb_substr((string) $request->query('currency', ''), 0, 3)), 'owing' => (string) $request->query('owing', '')];
        $rows = collect(TravelLedger::balances(['currency' => $f['currency'] ?: null, 'only_owing' => $f['owing'] === '1']));
        $contacts = Contact::query()->whereIn('id', TravelLedger::agentIds() ?: [0])->get(['id', 'name', 'phone', 'category', 'email'])->keyBy('id');
        // agents that have no figures yet still get a line, so a new ticket agent can be opened straight away
        $with = $rows->pluck('contact_id')->unique();
        if (! $f['currency'] && $f['owing'] !== '1') {
            foreach ($contacts as $c) {
                if (! $with->contains($c->id)) {
                    $rows->push(['contact_id' => $c->id, 'agent' => $c->name, 'phone' => $c->phone, 'category' => $c->category, 'currency' => null, 'opening' => '0.00', 'opening_date' => null, 'ticket' => '0.00', 'visa' => '0.00', 'other' => '0.00', 'billed' => '0.00', 'travels' => 0,
                        'paid' => '0.00', 'payments' => 0, 'balance' => '0.00', 'reserved' => '0.00', 'available' => '0.00', 'pending' => '0.00', 'last_payment' => null, 'last_travel' => null]);
                }
            }
        }
        if ($f['search'] !== '') {
            $s = mb_strtolower($f['search']);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['agent'] . ' ' . $r['phone']), $s));
        }
        $rows = $rows->sortBy(fn ($r) => mb_strtolower($r['agent']))->values();
        $me = $request->user();

        return Inertia::render('Travels/Agents/Index', [
            'rows' => $rows->all(), 'filters' => $f, 'totals' => TravelLedger::totals($rows->filter(fn ($r) => $r['currency'])->all()), 'currencies' => Travel::currencies(),
            'newAgentUrl' => route('contacts.create', ['category' => Contact::TICKET_AGENT_CATEGORY]), 'newVisaUrl' => route('contacts.create', ['category' => Contact::VISA_AGENT_CATEGORY]),
            'can' => ['pay' => $me->can('create-payment-voucher-from-travels'), 'manage' => $me->can('manage-travel-agents'), 'print' => $me->can('print-travels'), 'create_contact' => $me->can('create-contacts')],
        ]);
    }

    // ---------------------------------------------------------------------------------------------------- one agent

    public function show(Request $request, Contact $contact)
    {
        $this->need('read-travel-agents');
        abort_unless($this->isAgent($contact), 404);
        $me = $request->user();
        $currency = strtoupper(mb_substr((string) $request->query('currency', ''), 0, 3)) ?: null;
        $from = $request->query('from') && strtotime((string) $request->query('from')) ? date('Y-m-d', strtotime((string) $request->query('from'))) : null;
        $to = $request->query('to') && strtotime((string) $request->query('to')) ? date('Y-m-d', strtotime((string) $request->query('to'))) : null;
        $balances = TravelLedger::balances(['contact_id' => $contact->id]);
        $travels = Travel::query()->visibleTo($me)->with(['travellers:id,travel_id,name', 'ticketAgent:id,name', 'visaAgent:id,name', 'items'])
            ->where(fn ($q) => $q->where('ticket_agent_contact_id', $contact->id)->orWhere('visa_agent_contact_id', $contact->id)->orWhereHas('items', fn ($i) => $i->where('contact_id', $contact->id)))->orderByDesc('depart_date')->orderByDesc('id')->limit(300)->get()
            ->map(fn ($t) => ['id' => $t->id, 'reference' => $t->reference, 'status' => $t->status, 'status_label' => __($t->status), 'depart_date' => $t->depart_date->toDateString(), 'to_place' => $t->to_place, 'to_country' => $t->to_country, 'travellers' => $t->travellerNames(), 'currency' => $t->currency, 'rate' => (string) $t->exchange_rate,
                'ticket' => (int) $t->ticket_agent_contact_id === (int) $contact->id ? (string) $t->ticket_amount : '0.00', 'visa' => $t->has_visa && (int) $t->visa_agent_contact_id === (int) $contact->id ? (string) $t->visa_amount : '0.00',
                'other' => $t->items->where('contact_id', $contact->id)->map(fn ($i) => ['label' => $i->category, 'currency' => $i->currency, 'amount' => (string) $i->amount])->values()->all()])->values();
        $statement = TravelLedger::statement($contact->id, $currency, $from, $to);
        $payments = TravelAgentPayment::query()->with(['user:id,name', 'paidBy:id,name', 'travel:id,reference', 'voucher:id,pv_number,status,paid_at'])->where('contact_id', $contact->id)->orderByDesc('id')->limit(300)->get();
        $after = collect($statement)->whereNotNull('payment_id')->mapWithKeys(fn ($l) => [$l['payment_id'] => $l['balance']])->all();
        $payments = $payments->map(fn ($p) => $this->paymentRow($p) + ['balance_after' => $after[$p->id] ?? null])->values();
        $openings = TravelAgentOpening::query()->where('contact_id', $contact->id)->orderBy('currency')->get()->map(fn ($o) => ['id' => $o->id, 'currency' => $o->currency, 'amount' => (string) $o->amount, 'as_of_date' => $o->as_of_date->toDateString(), 'notes' => $o->notes])->values();

        return Inertia::render('Travels/Agents/Show', [
            'agent' => ['id' => $contact->id, 'name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email, 'category' => $contact->category, 'location' => $contact->location, 'contact_url' => $me->can('update-contacts') ? route('contacts.edit', $contact->id) : null],
            'balances' => $balances, 'statement' => $statement, 'travels' => $travels, 'payments' => $payments, 'openings' => $openings,
            'filters' => ['currency' => $currency, 'from' => $from, 'to' => $to], 'currencies' => Travel::currencies(), 'today' => now()->toDateString(), 'rates' => $this->rateHints($contact->id),
            'can' => ['pay' => $me->can('create-payment-voucher-from-travels'), 'manage' => $me->can('manage-travel-agents'), 'print' => $me->can('print-travels'), 'vouchers' => $me->can('read-payment-vouchers')],
        ]);
    }

    // ---------------------------------------------------------------------------------------------------- opening balance

    public function setOpening(Request $request, Contact $contact)
    {
        $this->need('manage-travel-agents');
        abort_unless($this->isAgent($contact), 404);
        $d = $request->validate(['currency' => 'required|string|size:3', 'amount' => 'required|numeric|min:-99999999999|max:99999999999', 'as_of_date' => 'required|date', 'notes' => 'nullable|string|max:1000']);
        $cur = strtoupper($d['currency']);
        $row = TravelAgentOpening::query()->where('contact_id', $contact->id)->where('currency', $cur)->first();
        $before = $row ? ['amount' => (string) $row->amount, 'as_of_date' => $row->as_of_date->toDateString()] : null;
        $data = ['contact_id' => $contact->id, 'currency' => $cur, 'amount' => round((float) $d['amount'], 2), 'as_of_date' => $d['as_of_date'], 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'user_id' => $request->user()->id];
        $row ? $row->update($data) : ($row = TravelAgentOpening::create($data));
        log_activity('Travel agent opening balance ' . ($before ? 'changed' : 'set'), ['agent' => $contact->name, 'currency' => $cur, 'amount' => $data['amount'], 'as_of' => $d['as_of_date'], 'before' => $before], $row, 'Travels');

        return back()->with('message', __('Opening balance of :a saved: :c :m. Travels booked before :d are inside it.', ['a' => $contact->name, 'c' => $cur, 'm' => TravelLedger::fmt($data['amount']), 'd' => date('d M Y', strtotime($d['as_of_date']))]));
    }

    public function removeOpening(Request $request, Contact $contact, int $opening)
    {
        $this->need('manage-travel-agents');
        $row = TravelAgentOpening::query()->where('contact_id', $contact->id)->whereKey($opening)->firstOrFail();
        $row->forceFill(['deleted_by' => $request->user()->id])->save();
        $row->delete();
        log_activity('Travel agent opening balance removed', ['agent' => $contact->name, 'currency' => $row->currency, 'amount' => (string) $row->amount], $row, 'Travels');

        return back()->with('message', __('Opening balance removed.'));
    }

    // ---------------------------------------------------------------------------------------------------- payments (always through a Payment Voucher)

    /** Starts a Payment Voucher from the balance. The voucher is a Draft in the Payment Vouchers module; nothing changes in the balance until it is approved and marked paid. */
    public function requestPayment(Request $request, Contact $contact)
    {
        $this->need('create-payment-voucher-from-travels');
        abort_unless($this->isAgent($contact), 404);
        $d = $request->validate([
            'currency' => 'required|string|size:3', 'amount' => 'required|numeric|gt:0|max:99999999999', 'exchange_rate' => 'nullable|numeric|gt:0|max:99999999', 'notes' => 'nullable|string|max:1000', 'travel_id' => 'nullable|integer',
        ]);
        $travelId = ! empty($d['travel_id']) ? (int) $d['travel_id'] : null;
        if ($travelId && ! Travel::query()->whereKey($travelId)->where(fn ($q) => $q->where('ticket_agent_contact_id', $contact->id)->orWhere('visa_agent_contact_id', $contact->id)->orWhereHas('items', fn ($i) => $i->where('contact_id', $contact->id)))->exists()) {
            throw ValidationException::withMessages(['travel_id' => __('That travel was not issued by this agent.')]);
        }
        $cur = strtoupper($d['currency']);
        if ($cur !== 'TZS' && empty($d['exchange_rate'])) {
            throw ValidationException::withMessages(['exchange_rate' => __('Enter the exchange rate: how many TZS one :c is worth.', ['c' => $cur])]);
        }
        $payment = DB::transaction(fn () => TravelVoucherService::create($contact, $cur, (float) $d['amount'], (float) ($d['exchange_rate'] ?? 1), trim((string) ($d['notes'] ?? '')) ?: null, (int) $request->user()->id, $travelId));
        $voucher = $payment->voucher;

        return redirect()->route($request->user()->can('read-payment-vouchers') ? 'payment-vouchers.show' : 'travels.agents.show', $request->user()->can('read-payment-vouchers') ? $voucher->id : $contact->id)
            ->with('message', __('Payment Voucher :pv started for :a (:c :m). It waits for approval in the Payment Vouchers module; the balance changes only when it is paid.', ['pv' => $voucher->pv_number, 'a' => $contact->name, 'c' => $payment->currency, 'm' => TravelLedger::fmt($payment->amount)]));
    }

    public function paymentShow(TravelAgentPayment $payment)
    {
        $this->need('read-travel-agents');
        $payment->load(['contact', 'travel:id,reference', 'user:id,name', 'paidBy:id,name', 'voucher']);
        $me = auth()->user();
        $v = $payment->voucher;
        $names = $v ? User::query()->whereIn('id', array_filter([$v->prepared_by_user_id, $v->approved_by_user_id, $v->authorised_by_user_id, $v->paid_by_user_id]))->pluck('name', 'id') : collect();
        $line = collect(TravelLedger::statement((int) $payment->contact_id, $payment->currency))->firstWhere('payment_id', $payment->id);

        return Inertia::render('Travels/Agents/Payment', [
            'p' => $this->paymentRow($payment) + ['notes' => $payment->notes, 'release_reason' => $payment->release_reason, 'released_at' => optional($payment->released_at)->toDateTimeString(), 'created_at' => $payment->created_at->toDateTimeString(), 'agent_id' => $payment->contact_id,
                'agent_phone' => optional($payment->contact)->phone, 'travel_id' => $payment->travel_id, 'exchange_rate' => (string) $payment->exchange_rate, 'voucher_amount' => (string) $payment->voucher_amount, 'balance_after' => $line['balance'] ?? null],
            'voucher' => $v ? [
                'id' => $v->id, 'pv_number' => $v->pv_number, 'status' => $v->status, 'paid' => ! empty($v->paid_at), 'total' => (string) $v->total_amount, 'date' => optional($v->voucher_date)->toDateString(), 'method' => $v->payment_method, 'reference' => $v->payment_reference,
                'prepared_by' => $names[$v->prepared_by_user_id] ?? null, 'approved_by' => $names[$v->approved_by_user_id] ?? null, 'approved_at' => optional($v->approved_at)->toDateTimeString(), 'authorised_by' => $names[$v->authorised_by_user_id] ?? null,
                'authorised_at' => optional($v->authorised_at)->toDateTimeString(), 'paid_by' => $names[$v->paid_by_user_id] ?? null, 'paid_at' => optional($v->paid_at)->toDateTimeString(), 'url' => $me->can('read-payment-vouchers') ? route('payment-vouchers.show', $v->id) : null,
            ] : null,
            'attachments' => $this->filesOf($payment), 'owed' => TravelLedger::owed((int) $payment->contact_id),
            'can' => ['print' => $me->can('print-travels'), 'attach' => $me->can('create-payment-voucher-from-travels'), 'remove_file' => $me->can('create-payment-voucher-from-travels') && $payment->status !== TravelAgentPayment::PAID],
        ]);
    }

    public function paymentAttach(Request $request, TravelAgentPayment $payment)
    {
        $this->need('create-payment-voucher-from-travels');
        $request->validate($this->fileRules(), ['attachments.required' => __('Choose at least one file.')]);
        $n = $this->saveFiles($payment, $request, 'travel-payments');
        log_activity('Travel agent payment ' . $payment->reference . ' document added', ['files' => $n], $payment, 'Travels');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    public function paymentDownload(TravelAgentPayment $payment, int $file)
    {
        $this->need('read-travel-agents');
        $a = $this->fileOf($payment, $file);
        $inline = request()->boolean('inline');
        log_activity('Travel agent payment ' . $payment->reference . ' document ' . ($inline ? 'viewed' : 'downloaded'), ['file' => $a->title], $payment, 'Travels');

        return $this->sendFile($a, $inline);
    }

    public function paymentRemoveFile(TravelAgentPayment $payment, int $file)
    {
        $this->need('create-payment-voucher-from-travels');
        abort_unless($payment->status !== TravelAgentPayment::PAID, 403);
        $a = $this->fileOf($payment, $file);
        $title = $a->title;
        $a->delete();
        log_activity('Travel agent payment ' . $payment->reference . ' document removed', ['file' => $title], $payment, 'Travels');

        return back()->with('message', __('Document removed.'));
    }

    public function paymentPrint(TravelAgentPayment $payment)
    {
        return $this->paymentDocument($payment, false);
    }

    public function paymentPdf(TravelAgentPayment $payment)
    {
        return $this->paymentDocument($payment, true);
    }

    private function paymentDocument(TravelAgentPayment $payment, bool $download)
    {
        $this->need('read-travel-agents');
        $this->need('print-travels');
        $payment->load(['contact', 'travel:id,reference,to_place', 'user:id,name', 'paidBy:id,name', 'voucher']);
        $v = $payment->voucher;
        $names = $v ? User::query()->whereIn('id', array_filter([$v->prepared_by_user_id, $v->approved_by_user_id, $v->authorised_by_user_id, $v->paid_by_user_id]))->pluck('name', 'id') : collect();
        $line = collect(TravelLedger::statement((int) $payment->contact_id, $payment->currency))->firstWhere('payment_id', $payment->id);
        log_activity('Travel agent payment ' . $payment->reference . ($download ? ' downloaded' : ' printed'), ['format' => 'pdf'], $payment, 'Travels');
        $pdf = Pdf::loadView('travels.payment-print', [
            'p' => $payment, 'v' => $v, 'names' => $names, 'isPdf' => true, 'companyName' => PaymentVoucher::COMPANY_NAME, 'qr' => PrintQrCode::dataUri(route('travels.agents.payments.show', $payment->id)), 'printedBy' => auth()->user()->name,
            'files' => $payment->attachments()->orderBy('id')->pluck('title')->all(), 'owed' => TravelLedger::owed((int) $payment->contact_id), 'balanceAfter' => $line['balance'] ?? null,
        ])->setPaper('a4', 'portrait');
        $name = 'travel-payment-' . $payment->reference . '.pdf';

        return $download ? $pdf->download($name) : $pdf->stream($name);
    }

    /**
     * The exchange rate offered when a payment is made in a foreign currency: the rate of the agent's latest approved travel (or other cost) in that currency, and
     * failing that the rate of the latest payment. The person may leave it or change it; the rate they confirm is the one on the Payment Voucher.
     *
     * @return array<string,string> currency => TZS per one unit
     */
    private function rateHints(int $contactId): array
    {
        $out = [];
        $travels = Travel::query()->where(fn ($q) => $q->where('ticket_agent_contact_id', $contactId)->orWhere('visa_agent_contact_id', $contactId))->where('currency', '!=', 'TZS')->orderBy('booking_date')->orderBy('id')->get(['currency', 'exchange_rate']);
        foreach ($travels as $t) {
            $out[strtoupper($t->currency)] = (string) $t->exchange_rate;
        }
        $items = DB::table('travel_items')->whereNull('deleted_at')->where('contact_id', $contactId)->where('currency', '!=', 'TZS')->orderBy('id')->get(['currency', 'exchange_rate']);
        foreach ($items as $i) {
            $out[strtoupper($i->currency)] = (string) $i->exchange_rate;
        }
        foreach (TravelAgentPayment::query()->where('contact_id', $contactId)->where('currency', '!=', 'TZS')->orderBy('id')->get(['currency', 'exchange_rate']) as $p) {
            $out[strtoupper($p->currency)] ??= (string) $p->exchange_rate;
        }

        return array_map(fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.'), $out);
    }

    private function paymentRow(TravelAgentPayment $p): array
    {
        $v = $p->relationLoaded('voucher') ? $p->voucher : null;

        return ['id' => $p->id, 'reference' => $p->reference, 'agent' => optional($p->contact)->name, 'requested_on' => $p->requested_on->toDateString(), 'paid_on' => $p->paid_on ? $p->paid_on->toDateString() : null, 'amount' => (string) $p->amount, 'currency' => $p->currency,
            'method' => $p->method, 'rate' => $p->currency === 'TZS' ? '' : rtrim(rtrim(number_format((float) $p->exchange_rate, 6, '.', ''), '0'), '.'), 'payment_reference' => $p->payment_reference, 'status' => $p->status, 'status_label' => __($p->status), 'travel' => optional($p->travel)->reference, 'by' => optional($p->user)->name, 'paid_by' => optional($p->paidBy)->name,
            'voucher_id' => $p->payment_voucher_id, 'pv_number' => $v ? $v->pv_number : null, 'voucher_status' => $v ? ($v->paid_at ? 'Paid' : $v->status) : null];
    }
}
