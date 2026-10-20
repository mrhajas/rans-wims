<?php

namespace App\Services\Travels;

use App\Models\Contact;
use App\Models\Travel;
use App\Models\TravelAgentPayment;
use Illuminate\Support\Facades\DB;

/**
 * Travel reports in the WIMS report shape (title, filters, tiles, columns, rows, notes): the travel register, upcoming travels, by traveller, by destination,
 * what each ticket agent is owed, the statement of one agent and the payment register. Money stays per currency: nothing is added across currencies.
 */
class TravelReports
{
    public const LIMIT = 5000;

    public static function catalog(): array
    {
        return [
            ['key' => 'register', 'title' => __('Travel register'), 'about' => __('Every travel in the period: who, where, flight, ticket agent, amounts and status. Filter by country, local or international, agent, status and dates.'), 'filters' => ['search', 'contact_id', 'scope', 'country', 'currency', 'status', 'when', 'from', 'to']],
            ['key' => 'travel_costs', 'title' => __('Travel costs (all items)'), 'about' => __('Every cost line of the travels: ticket and visa (always owed to their agents), accommodation, transport, hotels, food, leisure and other expenses. One section is owed to agents, the other is the company\'s own expense. Filter by section, type of cost, agent, status, country and dates.'), 'filters' => ['search', 'section', 'category', 'contact_id', 'currency', 'status', 'scope', 'country', 'when', 'from', 'to']],
            ['key' => 'cost_summary', 'title' => __('Travel costs by type'), 'about' => __('The cost lines added up by section and type (ticket, visa, accommodation, transport...), in TZS. Filter by agent, status, country and dates.'), 'filters' => ['search', 'section', 'category', 'contact_id', 'status', 'scope', 'country', 'when', 'from', 'to']],
            ['key' => 'upcoming', 'title' => __('Upcoming travels'), 'about' => __('Travels that have not left yet, soonest first, with the days left.'), 'filters' => ['search', 'contact_id', 'scope', 'country', 'status', 'to']],
            ['key' => 'by_country', 'title' => __('Travels by country'), 'about' => __('Each country travelled to, local or international, with the number of travels and travellers and what it cost, per currency.'), 'filters' => ['scope', 'country', 'currency', 'status', 'from', 'to']],
            ['key' => 'by_destination', 'title' => __('Travels by destination'), 'about' => __('Each destination (city or place) with the number of travellers and what it cost, per currency.'), 'filters' => ['search', 'scope', 'country', 'currency', 'status', 'from', 'to']],
            ['key' => 'by_traveller', 'title' => __('Travels by traveller'), 'about' => __('How many times each person travelled, where, and what it cost, per currency.'), 'filters' => ['search', 'scope', 'country', 'currency', 'status', 'from', 'to']],
            ['key' => 'agent_balances', 'title' => __('Ticket agent balances'), 'about' => __('What each ticket agent and visa issuer is owed: opening balance, approved travels, payments and the balance.'), 'filters' => ['search', 'contact_id', 'currency', 'as_of', 'only_owing']],
            ['key' => 'agent_statement', 'title' => __('Statement of one ticket agent'), 'about' => __('The account of one agent in date order with the running balance and every payment voucher. Choose the agent.'), 'filters' => ['search', 'contact_id', 'currency', 'from', 'to']],
            ['key' => 'payments', 'title' => __('Agent payment history'), 'about' => __('Every payment voucher made to a ticket agent: when it was asked for and paid, who did it, the amount, the voucher status and the balance after it was paid.'), 'filters' => ['search', 'contact_id', 'currency', 'status_payment', 'from', 'to']],
        ];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, array_column(self::catalog(), 'key'), true);
    }

    public static function build($user, string $type, array $f, int $limit = self::LIMIT): array
    {
        $f = self::normalize($f);
        [$cols, $rows, $title, $about, $tiles] = match ($type) {
            'upcoming' => self::upcoming($f, $user),
            'by_traveller' => self::byTraveller($f, $user),
            'by_destination' => self::byDestination($f, $user),
            'by_country' => self::byCountry($f, $user),
            'travel_costs' => self::travelCosts($f, $user),
            'cost_summary' => self::costSummary($f, $user),
            'agent_balances' => self::agentBalances($f),
            'agent_statement' => self::agentStatement($f),
            'payments' => self::payments($f),
            default => self::register($f, $user),
        };
        $total = count($rows);
        $kept = array_slice($rows, 0, $limit);
        $display = array_map(function ($r) use ($cols) {
            $o = [];
            foreach ($cols as $c) {
                $v = $r[$c['key']] ?? '';
                $o[$c['key']] = ($c['type'] ?? '') === 'money' && $v !== '' && $v !== null ? TravelLedger::fmt($v) : $v;
            }

            return $o;
        }, $kept);
        // every row is numbered (the No column is in the print, the PDF and the Excel as well)
        foreach ($display as $i => $r) {
            $display[$i] = ['no' => $i + 1] + $r;
        }
        array_unshift($cols, ['key' => 'no', 'label' => __('No'), 'type' => 'num']);
        $cols = array_map(fn ($c) => $c + ['align' => in_array($c['type'] ?? '', ['money', 'num'], true) ? 'right' : 'left'], $cols);

        return ['type' => $type, 'title' => $title, 'reference' => 'TRR-' . now()->format('Ymd-His'), 'subtitle' => $about, 'table_title' => $title, 'filters' => self::filterText($f), 'tiles' => array_values(array_merge([['label' => __('Rows'), 'value' => $total]], $tiles)),
            'columns' => $cols, 'rows' => $display, 'raw' => $kept, 'total_rows' => $total, 'truncated' => $total > $limit, 'sections' => [],
            'notes' => [__('Only approved travels count towards what a ticket agent is owed; waiting travels are shown as pending.'), __('Amounts are shown in the currency of each travel: they are never added across currencies.'), __('Figures are drawn from the records at the time of generation.')]];
    }

    private static function normalize(array $f): array
    {
        $date = fn ($v) => ! empty($v) && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;

        return [
            'search' => mb_substr(trim((string) ($f['search'] ?? '')), 0, 100), 'contact_id' => ctype_digit((string) ($f['contact_id'] ?? '')) && ($f['contact_id'] ?? '') !== '' ? (int) $f['contact_id'] : null,
            'currency' => ! empty($f['currency']) ? strtoupper(mb_substr((string) $f['currency'], 0, 3)) : null, 'status' => in_array($f['status'] ?? '', Travel::STATUSES, true) ? $f['status'] : null,
            'status_payment' => in_array($f['status_payment'] ?? '', TravelAgentPayment::STATUSES, true) ? $f['status_payment'] : null,
            'scope' => array_key_exists($f['scope'] ?? '', Travel::SCOPES) ? $f['scope'] : null, 'when' => in_array($f['when'] ?? '', ['upcoming', 'past'], true) ? $f['when'] : null, 'country' => mb_substr(trim((string) ($f['country'] ?? '')), 0, 100),
            'category' => mb_substr(trim((string) ($f['category'] ?? '')), 0, 60), 'section' => in_array($f['section'] ?? '', ['agents', 'extras'], true) ? $f['section'] : null,
            'from' => $date($f['from'] ?? null), 'to' => $date($f['to'] ?? null), 'as_of' => $date($f['as_of'] ?? null), 'only_owing' => ! empty($f['only_owing']) && $f['only_owing'] !== '0',
        ];
    }

    private static function filterText(array $f): array
    {
        $o = [];
        if ($f['contact_id']) {
            $o[__('Agent / traveller')] = optional(Contact::withTrashed()->find($f['contact_id']))->name ?? '#' . $f['contact_id'];
        }
        foreach (['search' => __('Search'), 'section' => __('Section'), 'category' => __('Type of cost'), 'scope' => __('Local / international'), 'when' => __('Departure'), 'country' => __('Country'), 'currency' => __('Currency'), 'status' => __('Status'), 'status_payment' => __('Payment status'), 'from' => __('From'), 'to' => __('To'), 'as_of' => __('As of')] as $k => $l) {
            if (! empty($f[$k])) {
                $o[$l] = $k === 'section' ? ($f[$k] === 'agents' ? __('Owed to agents') : __('Company expenses')) : ($k === 'scope' ? __(Travel::SCOPES[$f[$k]]) : ($k === 'when' ? ($f[$k] === 'upcoming' ? __('Not left yet') : __('Already left')) : $f[$k]));
            }
        }
        if ($f['only_owing']) {
            $o[__('Show')] = __('Only agents we owe');
        }

        return $o ?: [__('Filters') => __('None')];
    }

    private static function travels(array $f, $user)
    {
        $like = fn ($v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';

        return Travel::query()->visibleTo($user)->with(['travellers', 'items.contact:id,name', 'ticketAgent:id,name', 'visaAgent:id,name', 'user:id,name'])
            ->when($f['status'], fn ($q, $v) => $q->where('status', $v))->when($f['currency'], fn ($q, $v) => $q->where('currency', $v))
            ->when($f['scope'], fn ($q, $v) => $q->where('scope', $v))->when($f['when'] === 'upcoming', fn ($q) => $q->whereDate('depart_date', '>=', now()->toDateString()))->when($f['when'] === 'past', fn ($q) => $q->whereDate('depart_date', '<', now()->toDateString()))->when($f['country'] !== '', fn ($q) => $q->where('to_country', 'like', $like($f['country'])))
            ->when($f['contact_id'], fn ($q, $v) => $q->where(fn ($w) => $w->where('ticket_agent_contact_id', $v)->orWhere('visa_agent_contact_id', $v)->orWhereHas('items', fn ($i) => $i->where('contact_id', $v))))
            ->when($f['from'], fn ($q, $v) => $q->whereDate('depart_date', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('depart_date', '<=', $v))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('to_place', 'like', $like($f['search']))->orWhere('to_country', 'like', $like($f['search']))->orWhere('from_place', 'like', $like($f['search']))
                ->orWhere('flight_number', 'like', $like($f['search']))->orWhere('airline', 'like', $like($f['search']))->orWhereHas('travellers', fn ($t) => $t->where('name', 'like', $like($f['search'])))));
    }

    /** Keeps the rows whose text contains the search words (every word must be found somewhere in the row). */
    private static function grep(array $rows, string $search): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($search))) ?: []));
        if (! $words) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($r) use ($words) {
            $hay = mb_strtolower(implode(' ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $r)));

            return collect($words)->every(fn ($w) => str_contains($hay, $w));
        }));
    }

    private static function flight(Travel $t): string
    {
        return trim(trim(($t->airline ?: '') . ' ' . ($t->flight_number ?: '')) . ($t->return_flight_number ? ' / ' . trim(($t->return_airline ?: $t->airline) . ' ' . $t->return_flight_number) : ''));
    }

    private static function register(array $f, $user): array
    {
        $ts = self::travels($f, $user)->orderBy('depart_date')->orderBy('id')->limit(self::LIMIT + 1)->get();
        $rows = $ts->map(fn ($t) => ['reference' => $t->reference, 'depart' => $t->depart_date->toDateString(), 'return' => $t->return_date ? $t->return_date->toDateString() : '', 'travellers' => $t->travellerNames(), 'from' => $t->from_place, 'to' => $t->to_place, 'country' => $t->to_country, 'scope' => __($t->scopeLabel()), 'flight' => self::flight($t),
            'agent' => optional($t->ticketAgent)->name, 'visa' => $t->has_visa ? (optional($t->visaAgent)->name ?: __('Yes')) : '', 'currency' => $t->currency, 'rate' => $t->currency === 'TZS' ? '' : rtrim(rtrim(number_format((float) $t->exchange_rate, 6, '.', ''), '0'), '.'), 'ticket' => (float) $t->ticket_amount, 'visa_amount' => (float) $t->visa_amount, 'total' => (float) $t->total_amount, 'other_tzs' => $t->itemsTotalTzs(), 'all_tzs' => round($t->travelTotalTzs() + $t->itemsTotalTzs(), 2), 'status' => __($t->status)])->all();
        $cols = [['key' => 'reference', 'label' => __('Reference')], ['key' => 'depart', 'label' => __('Departs')], ['key' => 'return', 'label' => __('Returns')], ['key' => 'travellers', 'label' => __('Traveller(s)')], ['key' => 'from', 'label' => __('From')], ['key' => 'to', 'label' => __('To')], ['key' => 'country', 'label' => __('Country')], ['key' => 'scope', 'label' => __('Local / international')], ['key' => 'flight', 'label' => __('Flight')],
            ['key' => 'agent', 'label' => __('Ticket agent')], ['key' => 'visa', 'label' => __('Visa issuer')], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'rate', 'label' => __('Rate agreed'), 'type' => 'num'], ['key' => 'ticket', 'label' => __('Ticket'), 'type' => 'money'], ['key' => 'visa_amount', 'label' => __('Visa'), 'type' => 'money'], ['key' => 'total', 'label' => __('Ticket + visa'), 'type' => 'money'], ['key' => 'other_tzs', 'label' => __('Other costs (TZS)'), 'type' => 'money'], ['key' => 'all_tzs', 'label' => __('All costs (TZS)'), 'type' => 'money'], ['key' => 'status', 'label' => __('Status')]];

        return [$cols, $rows, __('Travel register'), __('Every travel in the period.'), self::moneyTiles($ts->where('status', Travel::APPROVED), __('Approved'))];
    }

    /** Totals of approved money per currency as tiles. */
    private static function moneyTiles($travels, string $label): array
    {
        $by = [];
        foreach ($travels as $t) {
            $by[$t->currency] = ($by[$t->currency] ?? 0) + (int) round(((float) $t->total_amount) * 100);
        }
        ksort($by);

        return array_map(fn ($c, $cur) => ['label' => $label . ' ' . $cur, 'value' => $cur . ' ' . number_format($c / 100, 2)], $by, array_keys($by));
    }

    /**
     * Every cost line of the travels, one row each: the ticket and the visa (always owed to their agents) and the other items. Rejected and cancelled travels are left out unless
     * their status is chosen. $f['section'] 'agents' = owed to an agent (ticket, visa, items that name an agent), 'extras' = the company's own expenses.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function costLines(array $f, $user): array
    {
        $q = $f;
        $q['currency'] = null; // the currency of a line, not of the travel, is what is filtered below
        $q['search'] = '';
        $ts = self::travels($q, $user)->when(! $f['status'], fn ($x) => $x->whereIn('status', [Travel::PENDING, Travel::APPROVED]))->orderBy('depart_date')->orderBy('id')->limit(20000)->get();
        $lines = [];
        foreach ($ts as $t) {
            $base = ['reference' => $t->reference, 'depart' => $t->depart_date->toDateString(), 'travellers' => $t->travellerNames(), 'to' => $t->to_place . ($t->to_country ? ', ' . $t->to_country : ''), 'status' => __($t->status), 'travel_id' => $t->id];
            $rate = (float) ($t->exchange_rate ?: 1);
            if ((float) $t->ticket_amount > 0) {
                $lines[] = $base + ['section' => 'agents', 'category' => __('Ticket'), 'description' => trim(($t->airline ?: '') . ' ' . ($t->flight_number ?: '')), 'agent_id' => $t->ticket_agent_contact_id, 'agent' => optional($t->ticketAgent)->name, 'currency' => $t->currency, 'rate' => $rate, 'amount' => (float) $t->ticket_amount, 'tzs' => round((float) $t->ticket_amount * $rate, 2)];
            }
            if ($t->has_visa && (float) $t->visa_amount > 0) {
                $lines[] = $base + ['section' => 'agents', 'category' => __('Visa'), 'description' => trim(($t->visa_type ?: '') . ' ' . ($t->visa_country ?: '')), 'agent_id' => $t->visa_agent_contact_id, 'agent' => optional($t->visaAgent)->name, 'currency' => $t->currency, 'rate' => $rate, 'amount' => (float) $t->visa_amount, 'tzs' => round((float) $t->visa_amount * $rate, 2)];
            }
            foreach ($t->items as $i) {
                $lines[] = $base + ['section' => $i->contact_id ? 'agents' : 'extras', 'category' => $i->category, 'description' => (string) $i->description, 'agent_id' => $i->contact_id, 'agent' => optional($i->contact)->name, 'currency' => strtoupper($i->currency), 'rate' => (float) $i->exchange_rate, 'amount' => (float) $i->amount, 'tzs' => (float) $i->amount_tzs];
            }
        }
        $lines = array_values(array_filter($lines, fn ($l) => (! $f['section'] || $l['section'] === $f['section']) && (! $f['currency'] || $l['currency'] === $f['currency'])
            && ($f['category'] === '' || mb_strtolower($l['category']) === mb_strtolower($f['category'])) && (! $f['contact_id'] || (int) $l['agent_id'] === (int) $f['contact_id'])));

        return self::grep($lines, $f['search']);
    }

    private static function sectionTiles(array $lines): array
    {
        $c = ['agents' => 0, 'extras' => 0];
        foreach ($lines as $l) {
            $c[$l['section']] += (int) round($l['tzs'] * 100);
        }

        return [['label' => __('Owed to agents (TZS)'), 'value' => 'TZS ' . number_format($c['agents'] / 100, 2)], ['label' => __('Company expenses (TZS)'), 'value' => 'TZS ' . number_format($c['extras'] / 100, 2)], ['label' => __('All costs (TZS)'), 'value' => 'TZS ' . number_format(($c['agents'] + $c['extras']) / 100, 2)]];
    }

    private static function travelCosts(array $f, $user): array
    {
        $lines = self::costLines($f, $user);
        $rows = array_map(fn ($l) => ['reference' => $l['reference'], 'depart' => $l['depart'], 'travellers' => $l['travellers'], 'to' => $l['to'], 'section' => $l['section'] === 'agents' ? __('Owed to agent') : __('Company expense'), 'category' => $l['category'], 'description' => $l['description'], 'agent' => $l['agent'],
            'currency' => $l['currency'], 'rate' => $l['currency'] === 'TZS' ? '' : rtrim(rtrim(number_format($l['rate'], 6, '.', ''), '0'), '.'), 'amount' => $l['amount'], 'tzs' => $l['tzs'], 'status' => $l['status']], $lines);
        $cols = [['key' => 'reference', 'label' => __('Travel')], ['key' => 'depart', 'label' => __('Departs')], ['key' => 'travellers', 'label' => __('Traveller(s)')], ['key' => 'to', 'label' => __('To')], ['key' => 'section', 'label' => __('Section')], ['key' => 'category', 'label' => __('Type of cost')], ['key' => 'description', 'label' => __('Description')], ['key' => 'agent', 'label' => __('Agent')],
            ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'rate', 'label' => __('Rate'), 'type' => 'num'], ['key' => 'amount', 'label' => __('Amount'), 'type' => 'money'], ['key' => 'tzs', 'label' => __('In TZS'), 'type' => 'money'], ['key' => 'status', 'label' => __('Status')]];

        return [$cols, $rows, __('Travel costs'), __('Every cost line of the travels.'), self::sectionTiles($lines)];
    }

    private static function costSummary(array $f, $user): array
    {
        $by = [];
        foreach (self::costLines($f, $user) as $l) {
            $r = &$by[$l['section'] . '|' . mb_strtolower($l['category'])];
            $r ??= ['section' => $l['section'], 'category' => $l['category'], 'lines' => 0, 'travels' => [], 'tzs' => 0];
            $r['lines']++;
            $r['travels'][$l['travel_id']] = true;
            $r['tzs'] += (int) round($l['tzs'] * 100);
            unset($r);
        }
        $rows = collect($by)->sortBy(fn ($r) => [$r['section'], mb_strtolower($r['category'])])->map(fn ($r) => ['section' => $r['section'] === 'agents' ? __('Owed to agents') : __('Company expenses'), 'category' => $r['category'], 'lines' => $r['lines'], 'travels' => count($r['travels']), 'tzs' => $r['tzs'] / 100])->values()->all();
        $cols = [['key' => 'section', 'label' => __('Section')], ['key' => 'category', 'label' => __('Type of cost')], ['key' => 'lines', 'label' => __('Lines'), 'type' => 'num'], ['key' => 'travels', 'label' => __('Travels'), 'type' => 'num'], ['key' => 'tzs', 'label' => __('Total (TZS)'), 'type' => 'money']];
        $lines = array_map(fn ($r) => ['section' => $r['section'] === __('Owed to agents') ? 'agents' : 'extras', 'tzs' => $r['tzs']], $rows);

        return [$cols, $rows, __('Travel costs by type'), __('The cost lines added up by section and type.'), self::sectionTiles($lines)];
    }

    private static function upcoming(array $f, $user): array
    {
        $f['from'] = now()->toDateString();
        $f['status'] = $f['status'] ?: null;
        $q = self::travels($f, $user)->whereIn('status', $f['status'] ? [$f['status']] : [Travel::PENDING, Travel::APPROVED])->orderBy('depart_date')->orderBy('id')->limit(self::LIMIT + 1)->get();
        $rows = $q->map(fn ($t) => ['depart' => $t->depart_date->toDateString(), 'days' => max(0, (int) now()->startOfDay()->diffInDays($t->depart_date->copy()->startOfDay(), false)), 'reference' => $t->reference, 'travellers' => $t->travellerNames(), 'to' => $t->to_place, 'flight' => self::flight($t),
            'agent' => optional($t->ticketAgent)->name, 'currency' => $t->currency, 'total' => (float) $t->total_amount, 'status' => __($t->status)])->all();
        $cols = [['key' => 'depart', 'label' => __('Departs')], ['key' => 'days', 'label' => __('Days left'), 'type' => 'num'], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'travellers', 'label' => __('Traveller(s)')], ['key' => 'to', 'label' => __('To')], ['key' => 'flight', 'label' => __('Flight')],
            ['key' => 'agent', 'label' => __('Ticket agent')], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'total', 'label' => __('Total'), 'type' => 'money'], ['key' => 'status', 'label' => __('Status')]];

        return [$cols, $rows, __('Upcoming travels'), __('Travels that have not left yet.'), [['label' => __('Within 7 days'), 'value' => collect($rows)->where('days', '<=', 7)->count()], ['label' => __('Waiting approval'), 'value' => $q->where('status', Travel::PENDING)->count()]]];
    }

    private static function byTraveller(array $f, $user): array
    {
        $f['status'] = $f['status'] ?: Travel::APPROVED;
        $ts = self::travels($f, $user)->limit(20000)->get();
        $by = [];
        foreach ($ts as $t) {
            foreach ($t->travellers as $p) {
                $k = ($p->staff_id ? 's' . $p->staff_id : ($p->user_id ? 'u' . $p->user_id : 'n' . mb_strtolower($p->name))) . '|' . $t->currency;
                $r = &$by[$k];
                $r ??= ['traveller' => $p->name, 'currency' => $t->currency, 'travels' => 0, 'to' => [], 'ticket' => 0, 'visa' => 0, 'last' => null];
                $r['travels']++;
                $r['to'][$t->to_place] = true;
                $r['ticket'] += (int) round(((float) $p->ticket_amount) * 100);
                $r['visa'] += (int) round(((float) $p->visa_amount) * 100);
                $r['last'] = max((string) $r['last'], $t->depart_date->toDateString());
                unset($r);
            }
        }
        $rows = collect($by)->map(fn ($r) => ['traveller' => $r['traveller'], 'currency' => $r['currency'], 'travels' => $r['travels'], 'destinations' => implode(', ', array_keys($r['to'])), 'ticket' => $r['ticket'] / 100, 'visa' => $r['visa'] / 100, 'total' => ($r['ticket'] + $r['visa']) / 100, 'last' => $r['last']])
            ->sortBy(fn ($r) => mb_strtolower($r['traveller']))->values()->all();
        $cols = [['key' => 'traveller', 'label' => __('Traveller')], ['key' => 'travels', 'label' => __('Travels'), 'type' => 'num'], ['key' => 'destinations', 'label' => __('Destinations')], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'ticket', 'label' => __('Tickets'), 'type' => 'money'],
            ['key' => 'visa', 'label' => __('Visas'), 'type' => 'money'], ['key' => 'total', 'label' => __('Total'), 'type' => 'money'], ['key' => 'last', 'label' => __('Last departure')]];

        return [$cols, $rows, __('Travels by traveller'), __('What each person travelled and what it cost.'), [['label' => __('People'), 'value' => collect($rows)->pluck('traveller')->unique()->count()]]];
    }

    private static function byCountry(array $f, $user): array
    {
        $f['status'] = $f['status'] ?: Travel::APPROVED;
        $by = [];
        foreach (self::travels($f, $user)->limit(20000)->get() as $t) {
            $country = trim((string) $t->to_country) !== '' ? trim($t->to_country) : Travel::homeCountry();
            $k = mb_strtolower($country) . '|' . $t->currency;
            $r = &$by[$k];
            $r ??= ['country' => $country, 'scope' => $t->scope, 'currency' => $t->currency, 'travels' => 0, 'people' => 0, 'ticket' => 0, 'visa' => 0, 'last' => null];
            $r['travels']++;
            $r['people'] += $t->travellers->count();
            $r['ticket'] += (int) round(((float) $t->ticket_amount) * 100);
            $r['visa'] += (int) round(((float) $t->visa_amount) * 100);
            $r['last'] = max((string) $r['last'], $t->depart_date->toDateString());
            unset($r);
        }
        $rows = collect($by)->map(fn ($r) => ['country' => $r['country'], 'scope' => __(Travel::SCOPES[$r['scope']] ?? $r['scope']), 'currency' => $r['currency'], 'travels' => $r['travels'], 'people' => $r['people'], 'ticket' => $r['ticket'] / 100, 'visa' => $r['visa'] / 100, 'total' => ($r['ticket'] + $r['visa']) / 100, 'last' => $r['last']])
            ->sortBy(fn ($r) => [$r['scope'], mb_strtolower($r['country'])])->values()->all();
        $cols = [['key' => 'country', 'label' => __('Country')], ['key' => 'scope', 'label' => __('Local / international')], ['key' => 'travels', 'label' => __('Travels'), 'type' => 'num'], ['key' => 'people', 'label' => __('Travellers'), 'type' => 'num'], ['key' => 'currency', 'label' => __('Cur.')],
            ['key' => 'ticket', 'label' => __('Tickets'), 'type' => 'money'], ['key' => 'visa', 'label' => __('Visas'), 'type' => 'money'], ['key' => 'total', 'label' => __('Total'), 'type' => 'money'], ['key' => 'last', 'label' => __('Last departure')]];

        return [$cols, $rows, __('Travels by country'), __('Each country travelled to and what it cost.'), [['label' => __('Countries'), 'value' => collect($rows)->pluck('country')->map(fn ($c) => mb_strtolower((string) $c))->unique()->count()], ['label' => __('Home country'), 'value' => Travel::homeCountry()]]];
    }

    private static function byDestination(array $f, $user): array
    {
        $f['status'] = $f['status'] ?: Travel::APPROVED;
        $ts = self::travels($f, $user)->limit(20000)->get();
        $by = [];
        foreach ($ts as $t) {
            $k = mb_strtolower(trim($t->to_place)) . '|' . $t->currency;
            $r = &$by[$k];
            $r ??= ['to' => trim($t->to_place), 'currency' => $t->currency, 'travels' => 0, 'people' => 0, 'ticket' => 0, 'visa' => 0, 'last' => null];
            $r['travels']++;
            $r['people'] += $t->travellers->count();
            $r['ticket'] += (int) round(((float) $t->ticket_amount) * 100);
            $r['visa'] += (int) round(((float) $t->visa_amount) * 100);
            $r['last'] = max((string) $r['last'], $t->depart_date->toDateString());
            unset($r);
        }
        $rows = collect($by)->map(fn ($r) => ['to' => $r['to'], 'currency' => $r['currency'], 'travels' => $r['travels'], 'people' => $r['people'], 'ticket' => $r['ticket'] / 100, 'visa' => $r['visa'] / 100, 'total' => ($r['ticket'] + $r['visa']) / 100, 'last' => $r['last']])
            ->sortBy(fn ($r) => mb_strtolower($r['to']))->values()->all();
        $cols = [['key' => 'to', 'label' => __('Destination')], ['key' => 'travels', 'label' => __('Travels'), 'type' => 'num'], ['key' => 'people', 'label' => __('Travellers'), 'type' => 'num'], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'ticket', 'label' => __('Tickets'), 'type' => 'money'],
            ['key' => 'visa', 'label' => __('Visas'), 'type' => 'money'], ['key' => 'total', 'label' => __('Total'), 'type' => 'money'], ['key' => 'last', 'label' => __('Last departure')]];

        return [$cols, $rows, __('Travels by destination'), __('Each destination and what it cost.'), [['label' => __('Destinations'), 'value' => collect($rows)->pluck('to')->map(fn ($c) => mb_strtolower((string) $c))->unique()->count()]]];
    }

    private static function agentBalances(array $f): array
    {
        $b = TravelLedger::balances(['contact_id' => $f['contact_id'], 'currency' => $f['currency'], 'as_of' => $f['as_of'], 'only_owing' => $f['only_owing']]);
        $b = self::grep($b, $f['search']);
        $rows = array_map(fn ($r) => ['agent' => $r['agent'], 'category' => $r['category'], 'currency' => $r['currency'], 'opening' => (float) $r['opening'], 'ticket' => (float) $r['ticket'], 'visa' => (float) $r['visa'], 'paid' => (float) $r['paid'], 'balance' => (float) $r['balance'], 'pending' => (float) $r['pending'], 'last_payment' => $r['last_payment']], $b);
        $cols = [['key' => 'agent', 'label' => __('Agent')], ['key' => 'category', 'label' => __('Category')], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'opening', 'label' => __('Opening balance'), 'type' => 'money'], ['key' => 'ticket', 'label' => __('Tickets'), 'type' => 'money'], ['key' => 'visa', 'label' => __('Visas'), 'type' => 'money'],
            ['key' => 'paid', 'label' => __('Paid'), 'type' => 'money'], ['key' => 'balance', 'label' => __('Balance owed'), 'type' => 'money'], ['key' => 'pending', 'label' => __('Pending approval'), 'type' => 'money'], ['key' => 'last_payment', 'label' => __('Last payment')]];
        $tiles = array_map(fn ($t) => ['label' => __('Owed') . ' ' . $t['currency'], 'value' => $t['currency'] . ' ' . TravelLedger::fmt($t['balance'])], TravelLedger::totals($b));

        return [$cols, $rows, __('Ticket agent balances'), __('What each ticket agent and visa issuer is owed.'), $tiles];
    }

    private static function agentStatement(array $f): array
    {
        $cols = [['key' => 'date', 'label' => __('Date')], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'text', 'label' => __('Details')], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'rate', 'label' => __('Rate agreed'), 'type' => 'num'], ['key' => 'owed', 'label' => __('Owed'), 'type' => 'money'], ['key' => 'paid', 'label' => __('Paid'), 'type' => 'money'], ['key' => 'balance', 'label' => __('Balance'), 'type' => 'money']];
        if (! $f['contact_id']) {
            return [[['key' => 'date', 'label' => __('Date')]], [], __('Statement of one ticket agent'), __('Choose the agent.'), []];
        }
        $rows = array_map(fn ($r) => ['date' => $r['date'], 'reference' => $r['reference'], 'text' => $r['memo'] ? $r['text'] . ' (' . $r['currency'] . ' ' . TravelLedger::fmt($r['paid']) . ')' : $r['text'], 'currency' => $r['currency'], 'rate' => $r['rate'], 'owed' => $r['owed'], 'paid' => $r['memo'] ? '' : $r['paid'], 'balance' => $r['balance']], self::grep(TravelLedger::statement($f['contact_id'], $f['currency'], $f['from'], $f['to']), $f['search']));
        $tiles = array_map(fn ($t) => ['label' => __('Owed') . ' ' . $t['currency'], 'value' => $t['currency'] . ' ' . TravelLedger::fmt($t['balance'])], TravelLedger::totals(TravelLedger::balances(['contact_id' => $f['contact_id'], 'currency' => $f['currency']])));

        return [$cols, $rows, __('Statement of :a', ['a' => optional(Contact::withTrashed()->find($f['contact_id']))->name]), __('Opening balance, approved travels and payments with the running balance.'), $tiles];
    }

    private static function payments(array $f): array
    {
        $ps = TravelAgentPayment::query()->with(['contact:id,name', 'travel:id,reference', 'user:id,name', 'paidBy:id,name', 'voucher:id,pv_number,status,paid_at'])
            ->when($f['contact_id'], fn ($q, $v) => $q->where('contact_id', $v))->when($f['currency'], fn ($q, $v) => $q->where('currency', $v))->when($f['status_payment'], fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'], fn ($q, $v) => $q->whereDate(DB::raw('COALESCE(paid_on, requested_on)'), '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate(DB::raw('COALESCE(paid_on, requested_on)'), '<=', $v))
            ->orderBy('contact_id')->orderBy('id')->limit(self::LIMIT + 1)->get();
        // the balance after each paid voucher comes from the agent's statement (the same figures as the account)
        $after = [];
        foreach ($ps->pluck('contact_id')->unique() as $cid) {
            foreach (TravelLedger::statement((int) $cid) as $l) {
                if ($l['payment_id'] && ! $l['memo']) {
                    $after[$l['payment_id']] = $l['balance'];
                }
            }
        }
        $rows = $ps->map(fn ($p) => ['agent' => optional($p->contact)->name, 'asked' => $p->requested_on->toDateString(), 'paid_on' => $p->paid_on ? $p->paid_on->toDateString() : '', 'reference' => $p->reference, 'voucher' => optional($p->voucher)->pv_number,
            'voucher_status' => $p->voucher ? ($p->voucher->paid_at ? 'Paid' : $p->voucher->status) : '', 'travel' => optional($p->travel)->reference, 'method' => $p->method, 'currency' => $p->currency, 'rate' => $p->currency === 'TZS' ? '' : rtrim(rtrim(number_format((float) $p->exchange_rate, 6, '.', ''), '0'), '.'), 'amount' => (float) $p->amount, 'balance_after' => isset($after[$p->id]) ? (float) $after[$p->id] : '',
            'status' => __($p->status), 'by' => optional($p->user)->name, 'paid_by' => optional($p->paidBy)->name])->all();
        $rows = self::grep($rows, $f['search']);
        $cols = [['key' => 'agent', 'label' => __('Agent')], ['key' => 'asked', 'label' => __('Asked on')], ['key' => 'paid_on', 'label' => __('Paid on')], ['key' => 'reference', 'label' => __('Payment')], ['key' => 'voucher', 'label' => __('Payment Voucher')], ['key' => 'voucher_status', 'label' => __('Voucher status')],
            ['key' => 'travel', 'label' => __('Travel')], ['key' => 'method', 'label' => __('Method')], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'rate', 'label' => __('Rate agreed'), 'type' => 'num'], ['key' => 'amount', 'label' => __('Amount'), 'type' => 'money'], ['key' => 'balance_after', 'label' => __('Balance after'), 'type' => 'money'],
            ['key' => 'status', 'label' => __('Status')], ['key' => 'by', 'label' => __('Asked by')], ['key' => 'paid_by', 'label' => __('Paid by')]];
        $by = [];
        foreach ($ps->where('status', TravelAgentPayment::PAID) as $p) {
            $by[$p->currency] = ($by[$p->currency] ?? 0) + (int) round(((float) $p->amount) * 100);
        }
        ksort($by);

        return [$cols, $rows, __('Agent payment history'), __('Every payment voucher made to a ticket agent.'), array_map(fn ($c, $cur) => ['label' => __('Paid') . ' ' . $cur, 'value' => $cur . ' ' . number_format($c / 100, 2)], $by, array_keys($by))];
    }
}
