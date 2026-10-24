<?php

namespace App\Http\Controllers\Travels;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Travels\Concerns\ManagesFiles;
use App\Models\Contact;
use App\Models\Staff;
use App\Models\Travel;
use App\Models\TravelItem;
use App\Models\TravelTraveller;
use App\Models\User;
use App\Services\Travels\TravelLedger;
use App\Services\Travels\TravelNotifier;
use App\Support\PrintQrCode;
use App\Support\ReferenceLock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Travels: the travels of staff and users. One record can carry one or more travellers; it says where they go, the flight, the ticket and the visa and who issued
 * them (contacts in the Ticket Agent / Visa Agent categories). Every travel is approval based: only an approved one counts towards what the agent is owed.
 * The departure may be in the future. Every action is logged; each record has a QR code, a print / PDF and attachments.
 */
class TravelController extends Controller
{
    use ManagesFiles;

    public function __construct()
    {
        $this->setAbilities([]); // every action is checked below, by name
    }

    private function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    private function visible()
    {
        return Travel::query()->visibleTo(auth()->user());
    }

    // ---------------------------------------------------------------------------------------------------- list

    public function index(Request $request)
    {
        $this->need('read-travels');
        $f = ['search' => trim((string) $request->query('search', '')), 'status' => (string) $request->query('status', ''), 'agent_id' => (string) $request->query('agent_id', ''), 'when' => (string) $request->query('when', ''),
            'currency' => (string) $request->query('currency', ''), 'scope' => (string) $request->query('scope', ''), 'country' => trim((string) $request->query('country', '')), 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')];
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $base = $this->visible()
            ->when(in_array($f['status'], Travel::STATUSES, true), fn ($q) => $q->where('status', $f['status']))
            ->when(ctype_digit($f['agent_id']) && $f['agent_id'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('ticket_agent_contact_id', (int) $f['agent_id'])->orWhere('visa_agent_contact_id', (int) $f['agent_id'])))
            ->when($f['currency'] !== '', fn ($q) => $q->where('currency', strtoupper(mb_substr($f['currency'], 0, 3))))
            ->when(array_key_exists($f['scope'], Travel::SCOPES), fn ($q) => $q->where('scope', $f['scope']))->when($f['country'] !== '', fn ($q) => $q->where('to_country', 'like', $like($f['country'])))
            ->when($f['when'] === 'upcoming', fn ($q) => $q->whereDate('depart_date', '>=', now()->toDateString()))->when($f['when'] === 'past', fn ($q) => $q->whereDate('depart_date', '<', now()->toDateString()))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('depart_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('depart_date', '<=', $f['to']))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('to_place', 'like', $like($f['search']))->orWhere('to_country', 'like', $like($f['search']))->orWhere('from_place', 'like', $like($f['search']))->orWhere('flight_number', 'like', $like($f['search']))
                ->orWhere('airline', 'like', $like($f['search']))->orWhere('booking_reference', 'like', $like($f['search']))->orWhereHas('travellers', fn ($t) => $t->where('name', 'like', $like($f['search']))->orWhere('ticket_number', 'like', $like($f['search'])))
                ->orWhereHas('ticketAgent', fn ($c) => $c->where('name', 'like', $like($f['search'])))));
        $rows = (clone $base)->with(['travellers', 'items', 'ticketAgent:id,name', 'visaAgent:id,name', 'user:id,name'])->orderByDesc('depart_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $rows->through(fn ($t) => $this->row($t));
        // the money of what is listed, per currency: the ticket and visa in the currency of each travel, the other costs in their own currency (TZS and USD, for example)
        $ids = (clone $base)->when($f['status'] === '', fn ($q) => $q->whereIn('status', [Travel::PENDING, Travel::APPROVED]))->select('travels.id');
        $sum = [];
        foreach (DB::table('travels')->whereIn('id', $ids)->selectRaw('currency, SUM(ticket_amount) t, SUM(visa_amount) v')->groupBy('currency')->get() as $r) {
            $sum[strtoupper($r->currency)]['travel'] = (int) round(((float) $r->t + (float) $r->v) * 100);
        }
        foreach (DB::table('travel_items')->whereNull('deleted_at')->whereIn('travel_id', $ids)->selectRaw('currency, SUM(amount) a')->groupBy('currency')->get() as $r) {
            $sum[strtoupper($r->currency)]['items'] = (int) round((float) $r->a * 100);
        }
        ksort($sum);
        $money = array_map(fn ($c, $cur) => ['currency' => $cur, 'travel' => number_format(($c['travel'] ?? 0) / 100, 2, '.', ''), 'items' => number_format(($c['items'] ?? 0) / 100, 2, '.', ''), 'total' => number_format((($c['travel'] ?? 0) + ($c['items'] ?? 0)) / 100, 2, '.', '')], $sum, array_keys($sum));
        usort($money, fn ($a, $b) => ($a['currency'] === 'TZS' ? 0 : 1) <=> ($b['currency'] === 'TZS' ? 0 : 1) ?: strcmp($a['currency'], $b['currency']));
        $counts = $this->visible()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $me = auth()->user();

        return Inertia::render('Travels/Index', [
            'rows' => $rows, 'totals' => $money, 'filters' => $f, 'statuses' => collect(Travel::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'agents' => $this->agentOptions(), 'currencies' => Travel::currencies(), 'countries' => Travel::query()->visibleTo($me0 = auth()->user())->whereNotNull('to_country')->distinct()->orderBy('to_country')->pluck('to_country'), 'scopes' => collect(Travel::SCOPES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'stats' => ['total' => (int) $counts->sum(), 'pending' => (int) ($counts[Travel::PENDING] ?? 0), 'approved' => (int) ($counts[Travel::APPROVED] ?? 0), 'rejected' => (int) ($counts[Travel::REJECTED] ?? 0), 'cancelled' => (int) ($counts[Travel::CANCELLED] ?? 0),
                'upcoming' => (int) $this->visible()->whereIn('status', [Travel::PENDING, Travel::APPROVED])->whereDate('depart_date', '>=', now()->toDateString())->count()],
            'can' => ['create' => $me->can('create-travels'), 'print' => $me->can('print-travels'), 'agents' => $me->can('read-travel-agents'), 'settings' => $me->can('manage-travel-settings'), 'packages' => $me->can('read-travel-packages') && \App\Services\FeatureManager::canAccess('travel_packages', $me)],
        ]);
    }

    // ---------------------------------------------------------------------------------------------------- create / edit

    public function create()
    {
        $this->need('create-travels');

        return Inertia::render('Travels/Form', $this->formProps(null));
    }

    public function store(Request $request)
    {
        $this->need('create-travels');
        $d = $this->validated($request, null);
        $t = ReferenceLock::run('travels', fn () => DB::transaction(function () use ($d) {
            $t = Travel::create(collect($d)->except('travellers', 'items')->all() + ['reference' => Travel::nextReference(), 'status' => Travel::PENDING]);
            $this->saveTravellers($t, $d['travellers']);
            $this->saveItems($t, $d['items']);

            return $t;
        }));
        $added = $this->saveFiles($t, $request, 'travels');
        log_activity('Travel ' . $t->reference . ' recorded', ['to' => $t->to_place, 'depart' => $t->depart_date->toDateString(), 'travellers' => count($d['travellers']), 'total' => $t->total_amount, 'currency' => $t->currency, 'rate' => (string) $t->exchange_rate, 'items' => count($d['items']), 'items_tzs' => $t->fresh()->itemsTotalTzs(), 'files' => $added], $t, 'Travels');
        TravelNotifier::fire('approval_needed', $t, $request->user());

        return redirect()->route('travels.show', $t->id)->with('message', __(':r saved. It needs approval before the ticket agent is owed for it.', ['r' => $t->reference]));
    }

    public function edit(Travel $travel)
    {
        $this->authorizeEdit($travel);
        $travel->load(['travellers', 'items']);

        return Inertia::render('Travels/Form', $this->formProps($travel));
    }

    public function update(Request $request, Travel $travel)
    {
        $this->authorizeEdit($travel);
        if ($travel->status === Travel::APPROVED) {
            return $this->updateDetails($request, $travel);
        }
        $d = $this->validated($request, $travel);
        DB::transaction(function () use ($travel, $d) {
            Travel::withoutGlobalScopes()->whereKey($travel->id)->lockForUpdate()->first();
            abort_unless($travel->fresh()->status === Travel::PENDING, 422, __('Only a travel that is still waiting for approval can be changed.'));
            $travel->update(collect($d)->except('travellers', 'items')->all());
            $travel->travellers()->forceDelete();
            $this->saveTravellers($travel, $d['travellers']);
            $travel->items()->forceDelete();
            $this->saveItems($travel, $d['items']);
        });
        $added = $this->saveFiles($travel, $request, 'travels');
        log_activity('Travel ' . $travel->reference . ' updated', ['travellers' => count($d['travellers']), 'total' => $travel->fresh()->total_amount, 'items' => count($d['items']), 'items_tzs' => $travel->fresh()->itemsTotalTzs(), 'files' => $added], $travel, 'Travels');

        return redirect()->route('travels.show', $travel->id)->with('message', __('Saved. It still needs approval.'));
    }

    /** An approved travel keeps its money and its people; only the trip and the flight details can still be corrected (a change of flight is normal). */
    private function updateDetails(Request $request, Travel $travel)
    {
        $d = $request->validate([
            'travel_type' => 'nullable|string|max:60', 'purpose' => 'nullable|string|max:2000', 'from_place' => 'nullable|string|max:150', 'from_country' => 'nullable|string|max:100', 'to_place' => 'required|string|max:150', 'to_country' => 'nullable|string|max:100', 'depart_date' => 'required|date', 'return_date' => 'nullable|date|after_or_equal:depart_date',
            'airline' => 'nullable|string|max:120', 'flight_number' => 'nullable|string|max:40', 'return_airline' => 'nullable|string|max:120', 'return_flight_number' => 'nullable|string|max:40', 'booking_reference' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:2000',
            'visa_type' => 'nullable|string|max:80', 'visa_country' => 'nullable|string|max:120', 'visa_reference' => 'nullable|string|max:80',
            'travellers' => 'nullable|array|max:30', 'travellers.*.id' => 'nullable|integer', 'travellers.*.ticket_number' => 'nullable|string|max:60', 'travellers.*.phone' => 'nullable|string|max:60',
            'attachments' => 'nullable|array|max:10', 'attachments.*' => 'file|max:8192|mimes:' . $this->fileTypes(),
        ]);
        $clean = fn ($v) => ($v = is_string($v) ? trim($v) : $v) === '' ? null : $v;
        $before = $travel->only(['depart_date', 'return_date', 'airline', 'flight_number', 'booking_reference']);
        $travel->update(collect($d)->only(['travel_type', 'purpose', 'from_place', 'from_country', 'to_place', 'to_country', 'depart_date', 'return_date', 'airline', 'flight_number', 'return_airline', 'return_flight_number', 'booking_reference', 'notes', 'visa_type', 'visa_country', 'visa_reference'])
            ->map(fn ($v) => is_string($v) ? $clean($v) : $v)->all());
        foreach ((array) ($d['travellers'] ?? []) as $row) {
            if (! empty($row['id'])) {
                TravelTraveller::query()->where('travel_id', $travel->id)->whereKey((int) $row['id'])->update(['ticket_number' => $clean($row['ticket_number'] ?? null), 'phone' => $clean($row['phone'] ?? null)]);
            }
        }
        $travel->forceFill(['scope' => Travel::scopeOfCountry($travel->to_country)])->save();
        $added = $this->saveFiles($travel, $request, 'travels');
        log_activity('Travel ' . $travel->reference . ' details corrected', ['before' => $before, 'files' => $added], $travel, 'Travels');

        return redirect()->route('travels.show', $travel->id)->with('message', __('Saved. The amounts and the people of an approved travel do not change.'));
    }

    // ---------------------------------------------------------------------------------------------------- show

    public function show(Travel $travel)
    {
        $this->need('read-travels');
        $this->authorizeView($travel);
        $travel->load(['travellers.staff:id,name,staff_number,user_id', 'items.contact:id,name,phone', 'ticketAgent', 'visaAgent', 'user:id,name', 'decidedBy:id,name']);
        $me = auth()->user();
        $own = (int) $travel->user_id === (int) $me->id;
        $pending = $travel->status === Travel::PENDING;
        $seesAgents = $me->can('read-travel-agents');
        $owed = fn ($c) => $c && $seesAgents ? TravelLedger::owed((int) $c->id) : null;

        return Inertia::render('Travels/Show', [
            't' => $this->row($travel) + [
                'purpose' => $travel->purpose, 'notes' => $travel->notes, 'visa_type' => $travel->visa_type, 'visa_country' => $travel->visa_country, 'visa_reference' => $travel->visa_reference, 'decision_remarks' => $travel->decision_remarks,
                'decided_by' => optional($travel->decidedBy)->name, 'decided_at' => optional($travel->decided_at)->toDateTimeString(), 'cancel_reason' => $travel->cancel_reason, 'cancelled_at' => optional($travel->cancelled_at)->toDateTimeString(),
                'created_at' => $travel->created_at->toDateTimeString(), 'ticket_agent_phone' => optional($travel->ticketAgent)->phone, 'visa_agent_phone' => optional($travel->visaAgent)->phone,
                'ticket_agent_owed' => $owed($travel->ticketAgent), 'visa_agent_owed' => $owed($travel->visaAgent),
            ],
            'attachments' => $this->filesOf($travel),
            'payments' => $seesAgents ? $travel->payments()->with(['contact:id,name', 'voucher:id,pv_number'])->orderByDesc('id')->get()->map(fn ($p) => ['id' => $p->id, 'reference' => $p->reference, 'agent' => optional($p->contact)->name, 'date' => ($p->paid_on ?: $p->requested_on)->toDateString(), 'amount' => (string) $p->amount, 'currency' => $p->currency, 'status' => $p->status, 'status_label' => __($p->status), 'pv_number' => optional($p->voucher)->pv_number])->values() : [],
            'can' => [
                'edit' => ($pending && ($own || $me->can('update-travels'))) || ($travel->status === Travel::APPROVED && $me->can('update-travels')),
                'edit_limited' => $travel->status === Travel::APPROVED && $me->can('update-travels'),
                'approve' => $pending && $me->can('approve-travels') && (! $own || $me->hasRole('Super Admin')),
                'reject' => $pending && $me->can('approve-travels'),
                'cancel' => ($pending && ($own || $me->can('cancel-travels') || $me->can('approve-travels'))) || ($travel->status === Travel::APPROVED && $me->can('cancel-travels')),
                'print' => $me->can('print-travels'), 'agents' => $seesAgents, 'pay' => $me->can('create-payment-voucher-from-travels'),
                'attach' => $this->mayAttach($travel), 'remove_file' => $pending && $this->mayEdit($travel),
            ],
        ]);
    }

    // ---------------------------------------------------------------------------------------------------- decisions

    public function approve(Request $request, Travel $travel)
    {
        $this->need('approve-travels');
        $this->authorizeView($travel);
        $me = $request->user();
        if ((int) $travel->user_id === (int) $me->id && ! $me->hasRole('Super Admin')) {
            return back()->with('error', __('You recorded this travel, so someone else must approve it.'));
        }
        $remarks = trim((string) $request->input('remarks', ''));
        $done = DB::transaction(function () use ($travel, $me, $remarks) {
            $t = Travel::withoutGlobalScopes()->lockForUpdate()->find($travel->id);
            if ($t->status !== Travel::PENDING) {
                return false;
            }
            $t->forceFill(['status' => Travel::APPROVED, 'decided_by_user_id' => $me->id, 'decided_at' => now(), 'decision_remarks' => $remarks ?: null])->save();

            return true;
        });
        if (! $done) {
            return back()->with('error', __('This travel has already been decided.'));
        }
        $travel->refresh();
        log_activity('Travel ' . $travel->reference . ' approved', ['total' => $travel->total_amount, 'currency' => $travel->currency], $travel, 'Travels');
        TravelNotifier::fire('approved', $travel, $me);

        return back()->with('message', __(':r approved. The ticket agent is now owed for it.', ['r' => $travel->reference]));
    }

    public function reject(Request $request, Travel $travel)
    {
        $this->need('approve-travels');
        $this->authorizeView($travel);
        $reason = trim($request->validate(['reason' => 'required|string|max:1000'])['reason']);
        $done = DB::transaction(function () use ($travel, $request, $reason) {
            $t = Travel::withoutGlobalScopes()->lockForUpdate()->find($travel->id);
            if ($t->status !== Travel::PENDING) {
                return false;
            }
            $t->forceFill(['status' => Travel::REJECTED, 'decided_by_user_id' => $request->user()->id, 'decided_at' => now(), 'decision_remarks' => $reason])->save();

            return true;
        });
        if (! $done) {
            return back()->with('error', __('This travel has already been decided.'));
        }
        $travel->refresh();
        log_activity('Travel ' . $travel->reference . ' rejected', ['reason' => $reason], $travel, 'Travels');
        TravelNotifier::fire('rejected', $travel, $request->user(), $reason);

        return back()->with('message', __(':r rejected. Nothing is owed for it.', ['r' => $travel->reference]));
    }

    public function cancel(Request $request, Travel $travel)
    {
        $this->authorizeView($travel);
        $me = $request->user();
        $reason = trim($request->validate(['reason' => 'required|string|max:1000'])['reason']);
        $own = (int) $travel->user_id === (int) $me->id;
        $error = null;
        DB::transaction(function () use ($travel, $me, $reason, $own, &$error) {
            $t = Travel::withoutGlobalScopes()->lockForUpdate()->find($travel->id);
            if ($t->status === Travel::PENDING) {
                if (! ($own || $me->can('cancel-travels') || $me->can('approve-travels'))) {
                    $error = __('You may not cancel this travel.');

                    return;
                }
            } elseif ($t->status === Travel::APPROVED) {
                if (! $me->can('cancel-travels')) {
                    $error = __('You may not cancel an approved travel.');

                    return;
                }
            } else {
                $error = __('This travel is already :s.', ['s' => strtolower(__($t->status))]);

                return;
            }
            $t->forceFill(['status' => Travel::CANCELLED, 'cancelled_by_user_id' => $me->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        });
        if ($error) {
            return back()->with('error', e($error));
        }
        $travel->refresh();
        log_activity('Travel ' . $travel->reference . ' cancelled', ['reason' => $reason], $travel, 'Travels');
        TravelNotifier::fire('cancelled', $travel, $me, $reason);

        return back()->with('message', __(':r cancelled.', ['r' => $travel->reference]));
    }

    // ---------------------------------------------------------------------------------------------------- attachments, print, download

    private function mayEdit(Travel $t): bool
    {
        $me = auth()->user();

        return $me->can('update-travels') || ((int) $t->user_id === (int) $me->id && $me->can('create-travels'));
    }

    /** Documents (tickets, visas, receipts) can be added while the travel waits, and later by someone who may change or approve travels. */
    private function mayAttach(Travel $t): bool
    {
        $me = auth()->user();
        if ($t->status === Travel::PENDING) {
            return $this->mayEdit($t);
        }

        return $t->status === Travel::APPROVED && ($me->can('update-travels') || $me->can('approve-travels'));
    }

    public function attach(Request $request, Travel $travel)
    {
        $this->need('read-travels');
        $this->authorizeView($travel);
        abort_unless($this->mayAttach($travel), 403);
        $request->validate($this->fileRules(), ['attachments.required' => __('Choose at least one file.')]);
        $n = $this->saveFiles($travel, $request, 'travels');
        log_activity('Travel ' . $travel->reference . ' document added', ['files' => $n], $travel, 'Travels');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    public function download(Travel $travel, int $file)
    {
        $this->need('read-travels');
        $this->authorizeView($travel);
        $a = $this->fileOf($travel, $file);
        $inline = request()->boolean('inline');
        log_activity('Travel ' . $travel->reference . ' document ' . ($inline ? 'viewed' : 'downloaded'), ['file' => $a->title], $travel, 'Travels');

        return $this->sendFile($a, $inline);
    }

    public function removeFile(Travel $travel, int $file)
    {
        $this->need('read-travels');
        $this->authorizeView($travel);
        abort_unless($travel->status === Travel::PENDING && $this->mayEdit($travel), 403);
        $a = $this->fileOf($travel, $file);
        $title = $a->title;
        $a->delete();
        log_activity('Travel ' . $travel->reference . ' document removed', ['file' => $title], $travel, 'Travels');

        return back()->with('message', __('Document removed.'));
    }

    /** The record on paper in the WIMS layout. Print opens the same PDF that Download saves; both are logged. */
    public function print(Travel $travel)
    {
        return $this->document($travel, false);
    }

    public function pdf(Travel $travel)
    {
        return $this->document($travel, true);
    }

    private function document(Travel $travel, bool $download)
    {
        $this->need('read-travels');
        $this->need('print-travels');
        $this->authorizeView($travel);
        $travel->load(['travellers', 'items.contact:id,name', 'ticketAgent', 'visaAgent', 'user:id,name', 'decidedBy:id,name']);
        log_activity('Travel ' . $travel->reference . ($download ? ' downloaded' : ' printed'), ['format' => 'pdf'], $travel, 'Travels');
        $pdf = Pdf::loadView('travels.print', [
            't' => $travel, 'isPdf' => true, 'companyName' => \App\Models\PaymentVoucher::COMPANY_NAME, 'qr' => PrintQrCode::dataUri(route('travels.show', $travel->id)), 'printedBy' => auth()->user()->name,
            'files' => $travel->attachments()->orderBy('id')->pluck('title')->all(), 'showAgentBalance' => auth()->user()->can('read-travel-agents'),
        ])->setPaper('a4', 'portrait');
        $name = 'travel-' . $travel->reference . '.pdf';

        return $download ? $pdf->download($name) : $pdf->stream($name);
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    private function authorizeView(Travel $t): void
    {
        abort_unless($this->visible()->whereKey($t->id)->exists(), 404);
    }

    private function authorizeEdit(Travel $t): void
    {
        $me = auth()->user();
        $this->authorizeView($t);
        abort_unless(
            ($t->status === Travel::PENDING && ($me->can('update-travels') || ((int) $t->user_id === (int) $me->id && $me->can('create-travels')))) || ($t->status === Travel::APPROVED && $me->can('update-travels')),
            403
        );
    }

    private function agentOptions()
    {
        return collect(TravelLedger::agentIds())->pipe(fn ($ids) => Contact::query()->whereIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'name', 'phone', 'category']))
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ($c->phone ? ' (' . $c->phone . ')' : '')])->values();
    }

    /** Contacts that can issue a ticket (Ticket Agent) and a visa (Visa Agent or Ticket Agent: many agents do both). The one already chosen on a record stays in the list. */
    private function issuerOptions(array $keep = [])
    {
        return Contact::query()->where(fn ($q) => $q->whereIn('category', [Contact::TICKET_AGENT_CATEGORY, Contact::VISA_AGENT_CATEGORY])->orWhereIn('id', array_filter($keep) ?: [0]))->orderBy('name')->get(['id', 'name', 'phone', 'category'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ($c->phone ? ' (' . $c->phone . ')' : ''), 'category' => $c->category]);
    }

    private function row(Travel $t): array
    {
        return ['id' => $t->id, 'reference' => $t->reference, 'status' => $t->status, 'status_label' => __($t->status), 'trip_type' => $t->trip_type, 'trip_label' => __($t->tripLabel()), 'travel_type' => $t->travel_type,
            'from_place' => $t->from_place, 'from_country' => $t->from_country, 'to_place' => $t->to_place, 'to_country' => $t->to_country, 'scope' => $t->scope, 'scope_label' => __($t->scopeLabel()), 'depart_date' => $t->depart_date->toDateString(), 'return_date' => $t->return_date ? $t->return_date->toDateString() : null, 'booking_date' => $t->booking_date ? $t->booking_date->toDateString() : null,
            'days_left' => $t->depart_date->isFuture() || $t->depart_date->isToday() ? (int) now()->startOfDay()->diffInDays($t->depart_date->copy()->startOfDay(), false) : null,
            'airline' => $t->airline, 'flight_number' => $t->flight_number, 'return_airline' => $t->return_airline, 'return_flight_number' => $t->return_flight_number, 'booking_reference' => $t->booking_reference,
            'ticket_agent_id' => $t->ticket_agent_contact_id, 'ticket_agent' => optional($t->ticketAgent)->name, 'has_visa' => (bool) $t->has_visa, 'visa_agent_id' => $t->visa_agent_contact_id, 'visa_agent' => optional($t->visaAgent)->name,
            'currency' => $t->currency, 'exchange_rate' => (string) $t->exchange_rate, 'ticket_amount' => (string) $t->ticket_amount, 'visa_amount' => (string) $t->visa_amount, 'total_amount' => (string) $t->total_amount, 'by' => optional($t->user)->name,
            'package_id' => $t->travel_package_id, 'package_name' => $t->package_name, 'travel_total_tzs' => number_format($t->travelTotalTzs(), 2, '.', ''), 'items_total_tzs' => number_format($t->itemsTotalTzs(), 2, '.', ''), 'grand_total_tzs' => number_format($t->travelTotalTzs() + $t->itemsTotalTzs(), 2, '.', ''),
            'items' => $t->items->map(fn ($i) => ['id' => $i->id, 'category' => $i->category, 'description' => $i->description, 'contact_id' => $i->contact_id, 'contact' => optional($i->contact)->name, 'currency' => $i->currency, 'exchange_rate' => (string) $i->exchange_rate, 'amount' => (string) $i->amount, 'amount_tzs' => (string) $i->amount_tzs])->values(),
            'travellers' => $t->travellers->map(fn ($p) => ['id' => $p->id, 'type' => $p->traveller_type, 'staff_id' => $p->staff_id, 'user_id' => $p->user_id, 'name' => $p->name, 'phone' => $p->phone, 'ticket_number' => $p->ticket_number, 'ticket_amount' => (string) $p->ticket_amount, 'visa_amount' => (string) $p->visa_amount, 'staff_number' => optional($p->staff)->staff_number])->values()];
    }

    private function formProps(?Travel $t): array
    {
        $keep = $t ? array_merge([$t->ticket_agent_contact_id, $t->visa_agent_contact_id], $t->items->pluck('contact_id')->all()) : [];

        return [
            't' => $t ? $this->row($t) + ['purpose' => $t->purpose, 'notes' => $t->notes, 'visa_type' => $t->visa_type, 'visa_country' => $t->visa_country, 'visa_reference' => $t->visa_reference] : null,
            'types' => Travel::travelTypes(), 'categories' => TravelItem::categories(), 'currencies' => Travel::currencies(), 'lastRates' => Travel::query()->where('currency', '!=', 'TZS')->orderBy('id')->get(['currency', 'exchange_rate'])->mapWithKeys(fn ($x) => [strtoupper($x->currency) => (string) (float) $x->exchange_rate])->all(), 'homeCountry' => Travel::homeCountry(), 'countries' => Travel::query()->whereNotNull('to_country')->distinct()->orderBy('to_country')->pluck('to_country'), 'issuers' => $this->issuerOptions($keep), 'today' => now()->toDateString(), 'attachments' => $t ? $this->filesOf($t) : [],
            'staff' => Staff::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Terminated'))->orderBy('name')->get(['id', 'name', 'staff_number', 'mobile_no', 'user_id'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name . ($s->staff_number ? ' (' . $s->staff_number . ')' : ''), 'name' => $s->name, 'phone' => $s->mobile_no, 'user_id' => $s->user_id]),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name, 'name' => $u->name]),
            'newAgentUrl' => route('contacts.create', ['category' => Contact::TICKET_AGENT_CATEGORY]), 'newVisaUrl' => route('contacts.create', ['category' => Contact::VISA_AGENT_CATEGORY]),
            'limited' => (bool) ($t && $t->status === Travel::APPROVED),
        ];
    }

    /** The checks for a new or changed travel; the totals are worked out here, never trusted from the browser. */
    private function validated(Request $request, ?Travel $existing): array
    {
        $d = $request->validate([
            'trip_type' => 'required|in:return,one_way', 'travel_type' => 'nullable|string|max:60', 'purpose' => 'nullable|string|max:2000', 'from_place' => 'nullable|string|max:150', 'from_country' => 'nullable|string|max:100', 'to_place' => 'required|string|max:150', 'to_country' => 'nullable|string|max:100',
            'depart_date' => 'required|date', 'return_date' => 'nullable|date|after_or_equal:depart_date', 'booking_date' => 'required|date',
            'airline' => 'nullable|string|max:120', 'flight_number' => 'nullable|string|max:40', 'return_airline' => 'nullable|string|max:120', 'return_flight_number' => 'nullable|string|max:40', 'booking_reference' => 'nullable|string|max:60',
            'ticket_agent_contact_id' => 'nullable|integer', 'currency' => 'required|string|size:3', 'has_visa' => 'nullable|boolean', 'visa_type' => 'nullable|string|max:80', 'visa_country' => 'nullable|string|max:120', 'visa_reference' => 'nullable|string|max:80',
            'visa_agent_contact_id' => 'nullable|integer', 'notes' => 'nullable|string|max:2000', 'exchange_rate' => 'nullable|numeric|gt:0|max:99999999',
            'items' => 'nullable|array|max:60', 'items.*.category' => 'required|string|max:60', 'items.*.description' => 'nullable|string|max:255', 'items.*.contact_id' => 'nullable|integer', 'items.*.currency' => 'required|string|size:3', 'items.*.exchange_rate' => 'nullable|numeric|gt:0|max:99999999', 'items.*.amount' => 'required|numeric|gt:0|max:99999999999',
            'travellers' => 'required|array|min:1|max:30', 'travellers.*.traveller_type' => 'required|in:staff,user,other', 'travellers.*.staff_id' => 'nullable|integer', 'travellers.*.user_id' => 'nullable|integer', 'travellers.*.name' => 'nullable|string|max:190',
            'travellers.*.phone' => 'nullable|string|max:60', 'travellers.*.ticket_number' => 'nullable|string|max:60', 'travellers.*.ticket_amount' => 'nullable|numeric|min:0|max:99999999999', 'travellers.*.visa_amount' => 'nullable|numeric|min:0|max:99999999999',
            'attachments' => 'nullable|array|max:10', 'attachments.*' => 'file|max:8192|mimes:' . $this->fileTypes(),
        ], ['travellers.required' => __('Add at least one traveller.'), 'travellers.min' => __('Add at least one traveller.'), 'to_place.required' => __('Where are they going?'), 'items.*.category.required' => __('Choose the type of cost.'), 'items.*.amount.required' => __('Enter the amount.'), 'items.*.amount.gt' => __('The amount must be more than zero.')]);

        $errors = [];
        $hasVisa = (bool) ($d['has_visa'] ?? false);
        $currency = strtoupper($d['currency']);
        // travellers: a staff member / user is taken from the records (name, phone); someone else is typed
        $rows = [];
        $seen = [];
        $ticketCents = 0;
        $visaCents = 0;
        foreach ($d['travellers'] as $i => $p) {
            $type = $p['traveller_type'];
            $staff = $type === 'staff' && ! empty($p['staff_id']) ? Staff::query()->find((int) $p['staff_id']) : null;
            $user = $type === 'user' && ! empty($p['user_id']) ? User::query()->find((int) $p['user_id']) : null;
            if ($type === 'staff' && ! $staff) {
                $errors["travellers.$i.staff_id"] = __('Choose the staff member.');
            }
            if ($type === 'user' && ! $user) {
                $errors["travellers.$i.user_id"] = __('Choose the user.');
            }
            $name = $staff ? $staff->name : ($user ? $user->name : trim((string) ($p['name'] ?? '')));
            if ($type === 'other' && $name === '') {
                $errors["travellers.$i.name"] = __('Type the name of the traveller.');
            }
            $key = $staff ? 's' . $staff->id : ($user ? 'u' . $user->id : 'n' . mb_strtolower($name));
            if (isset($seen[$key]) && $name !== '') {
                $errors["travellers.$i." . ($staff ? 'staff_id' : ($user ? 'user_id' : 'name'))] = __(':n is already on this travel.', ['n' => $name]);
            }
            $seen[$key] = true;
            $ticket = (int) round(((float) ($p['ticket_amount'] ?? 0)) * 100);
            $visa = $hasVisa ? (int) round(((float) ($p['visa_amount'] ?? 0)) * 100) : 0;
            $ticketCents += $ticket;
            $visaCents += $visa;
            $rows[] = ['traveller_type' => $type, 'staff_id' => $staff ? $staff->id : null, 'user_id' => $user ? $user->id : ($staff ? $staff->user_id : null), 'name' => $name, 'phone' => $this->clean($p['phone'] ?? ($staff ? $staff->mobile_no : null)),
                'ticket_number' => $this->clean($p['ticket_number'] ?? null), 'ticket_amount' => $ticket / 100, 'visa_amount' => $visa / 100, 'sort_order' => $i];
        }
        // the people who issue: the ticket issuer is needed as soon as there is a ticket price, the visa issuer as soon as there is a visa price
        $ticketAgent = ! empty($d['ticket_agent_contact_id']) ? (int) $d['ticket_agent_contact_id'] : null;
        $visaAgent = $hasVisa && ! empty($d['visa_agent_contact_id']) ? (int) $d['visa_agent_contact_id'] : null;
        $okIds = $this->issuerOptions(array_merge([$existing ? $existing->ticket_agent_contact_id : null, $existing ? $existing->visa_agent_contact_id : null], $existing ? $existing->items()->pluck('contact_id')->all() : []))->pluck('value')->map(fn ($v) => (int) $v)->all();
        if ($ticketAgent && ! in_array($ticketAgent, $okIds, true)) {
            $errors['ticket_agent_contact_id'] = __('Choose the ticket agent from the list (a contact in the Ticket Agent category).');
        }
        if ($visaAgent && ! in_array($visaAgent, $okIds, true)) {
            $errors['visa_agent_contact_id'] = __('Choose the visa issuer from the list.');
        }
        if ($ticketCents > 0 && ! $ticketAgent) {
            $errors['ticket_agent_contact_id'] = __('Who issued the ticket? A ticket agent is needed when there is a ticket price.');
        }
        if ($hasVisa && $visaCents > 0 && ! $visaAgent) {
            $errors['visa_agent_contact_id'] = __('Who issued the visa? An issuer is needed when there is a visa price.');
        }
        // the rate: a foreign currency must have one (how many TZS one unit is worth); TZS is always 1
        $rate = $currency === 'TZS' ? 1.0 : (float) ($d['exchange_rate'] ?? 0);
        if ($currency !== 'TZS' && $rate <= 0) {
            $errors['exchange_rate'] = __('Enter the exchange rate: how many TZS one :c is worth.', ['c' => $currency]);
        }
        // the other costs: each with its own currency and rate; naming an agent makes it owed to that agent
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $i => $it) {
            $cur = strtoupper($it['currency']);
            $irate = $cur === 'TZS' ? 1.0 : (float) ($it['exchange_rate'] ?? 0);
            if ($cur !== 'TZS' && $irate <= 0) {
                $errors["items.$i.exchange_rate"] = __('Enter the exchange rate: how many TZS one :c is worth.', ['c' => $cur]);
            }
            $cid = ! empty($it['contact_id']) ? (int) $it['contact_id'] : null;
            if ($cid && ! in_array($cid, $okIds, true)) {
                $errors["items.$i.contact_id"] = __('Choose the agent from the list (a ticket agent or visa issuer).');
            }
            $amount = round((float) $it['amount'], 2);
            $items[] = ['category' => trim($it['category']), 'description' => $this->clean($it['description'] ?? null), 'contact_id' => $cid, 'currency' => $cur, 'exchange_rate' => $irate, 'amount' => $amount, 'amount_tzs' => TravelItem::toTzs($amount, $irate), 'sort_order' => $i];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'trip_type' => $d['trip_type'], 'travel_type' => $this->clean($d['travel_type'] ?? null), 'purpose' => $this->clean($d['purpose'] ?? null), 'from_place' => $this->clean($d['from_place'] ?? null), 'from_country' => $this->clean($d['from_country'] ?? null), 'to_place' => trim($d['to_place']), 'to_country' => $this->clean($d['to_country'] ?? null), 'scope' => Travel::scopeOfCountry($d['to_country'] ?? null),
            'depart_date' => $d['depart_date'], 'return_date' => $d['trip_type'] === 'return' ? ($d['return_date'] ?? null) : null, 'booking_date' => $d['booking_date'],
            'airline' => $this->clean($d['airline'] ?? null), 'flight_number' => $this->clean($d['flight_number'] ?? null), 'return_airline' => $d['trip_type'] === 'return' ? $this->clean($d['return_airline'] ?? null) : null,
            'return_flight_number' => $d['trip_type'] === 'return' ? $this->clean($d['return_flight_number'] ?? null) : null, 'booking_reference' => $this->clean($d['booking_reference'] ?? null),
            'ticket_agent_contact_id' => $ticketAgent, 'currency' => $currency, 'exchange_rate' => $rate, 'ticket_amount' => $ticketCents / 100, 'has_visa' => $hasVisa, 'visa_type' => $hasVisa ? $this->clean($d['visa_type'] ?? null) : null,
            'visa_country' => $hasVisa ? $this->clean($d['visa_country'] ?? null) : null, 'visa_reference' => $hasVisa ? $this->clean($d['visa_reference'] ?? null) : null, 'visa_agent_contact_id' => $visaAgent, 'visa_amount' => $visaCents / 100,
            'total_amount' => ($ticketCents + $visaCents) / 100, 'notes' => $this->clean($d['notes'] ?? null), 'travellers' => $rows, 'items' => $items,
        ];
    }

    private function clean($v)
    {
        return ($v = is_string($v) ? trim($v) : $v) === '' ? null : $v;
    }

    private function saveItems(Travel $t, array $rows): void
    {
        foreach ($rows as $r) {
            TravelItem::create($r + ['travel_id' => $t->id]);
        }
    }

    private function saveTravellers(Travel $t, array $rows): void
    {
        foreach ($rows as $r) {
            TravelTraveller::create($r + ['travel_id' => $t->id]);
        }
    }
}
