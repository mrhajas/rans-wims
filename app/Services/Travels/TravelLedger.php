<?php

namespace App\Services\Travels;

use App\Models\Contact;
use App\Models\Travel;
use App\Models\TravelAgentPayment;
use Illuminate\Support\Facades\DB;

/**
 * What each ticket agent / visa issuer is owed, per currency. Nothing is stored as a balance: it is always
 *   opening balance  +  approved travels they issued (ticket and / or visa) and other costs owed to them  -  payments that are not voided.
 * Payments are made only through Payment Vouchers: a payment counts once its voucher is marked paid (a voucher still waiting is shown as "reserved").
 * A travel counts from its booking date; travels booked BEFORE the opening balance's date are already inside the opening figure and are not counted again.
 * The other costs of a travel (Travel items) count too when they name the agent they are owed to, each in its own currency.
 * Only Approved travels count (waiting ones are shown as "pending"; rejected and cancelled ones never count). All sums are done in cents so no figure drifts.
 */
class TravelLedger
{
    /** A money figure as text with two decimals. */
    public static function fmt($v): string
    {
        return number_format((float) $v, 2, '.', ',');
    }

    private static function cents($v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** The contacts that are ticket agents / visa issuers, plus anyone who already has Travels history (so a contact that changed category keeps its account). */
    public static function agentIds(): array
    {
        $byCategory = Contact::query()->whereIn('category', [Contact::TICKET_AGENT_CATEGORY, Contact::VISA_AGENT_CATEGORY])->pluck('id')->all();
        $withHistory = array_merge(
            DB::table('travels')->whereNull('deleted_at')->whereNotNull('ticket_agent_contact_id')->pluck('ticket_agent_contact_id')->all(),
            DB::table('travels')->whereNull('deleted_at')->whereNotNull('visa_agent_contact_id')->pluck('visa_agent_contact_id')->all(),
            DB::table('travel_items')->whereNull('deleted_at')->whereNotNull('contact_id')->pluck('contact_id')->all(),
            DB::table('travel_agent_openings')->whereNull('deleted_at')->pluck('contact_id')->all(),
            DB::table('travel_agent_payments')->whereNull('deleted_at')->pluck('contact_id')->all()
        );

        return array_values(array_unique(array_map('intval', array_merge($byCategory, $withHistory))));
    }

    /** The opening balance rows (one per agent and currency; the newest wins) keyed "contact|currency". */
    private static function openings(?int $contactId = null): array
    {
        $out = [];
        foreach (DB::table('travel_agent_openings')->whereNull('deleted_at')->when($contactId, fn ($q, $v) => $q->where('contact_id', $v))->orderBy('id')->get() as $o) {
            $out[$o->contact_id . '|' . strtoupper($o->currency)] = $o;
        }

        return $out;
    }

    /** The approved travels as amounts owed to an agent: one entry per (travel, agent, kind). */
    private static function charges(?int $contactId = null, ?string $asOf = null): array
    {
        $rows = DB::table('travels')->whereNull('deleted_at')->where('status', Travel::APPROVED)
            ->when($contactId, fn ($q, $v) => $q->where(fn ($w) => $w->where('ticket_agent_contact_id', $v)->orWhere('visa_agent_contact_id', $v)))
            ->get(['id', 'reference', 'currency', 'exchange_rate', 'ticket_agent_contact_id', 'ticket_amount', 'visa_agent_contact_id', 'visa_amount', 'has_visa', 'booking_date', 'depart_date', 'to_place']);
        $out = [];
        foreach ($rows as $t) {
            $date = $t->booking_date ?: $t->depart_date;
            if ($asOf && $date > $asOf) {
                continue;
            }
            $cur = strtoupper($t->currency);
            if ($t->ticket_agent_contact_id && (! $contactId || (int) $t->ticket_agent_contact_id === $contactId) && self::cents($t->ticket_amount) !== 0) {
                $out[] = ['contact_id' => (int) $t->ticket_agent_contact_id, 'currency' => $cur, 'kind' => 'ticket', 'rate' => (float) $t->exchange_rate, 'cents' => self::cents($t->ticket_amount), 'date' => $date, 'travel_id' => (int) $t->id, 'reference' => $t->reference, 'to' => $t->to_place];
            }
            if ($t->has_visa && $t->visa_agent_contact_id && (! $contactId || (int) $t->visa_agent_contact_id === $contactId) && self::cents($t->visa_amount) !== 0) {
                $out[] = ['contact_id' => (int) $t->visa_agent_contact_id, 'currency' => $cur, 'kind' => 'visa', 'rate' => (float) $t->exchange_rate, 'cents' => self::cents($t->visa_amount), 'date' => $date, 'travel_id' => (int) $t->id, 'reference' => $t->reference, 'to' => $t->to_place];
            }
        }

        // the other costs of an approved travel that name an agent (a car the agent rented, a hotel it booked...): owed to that agent in the item's own currency
        $items = DB::table('travel_items as i')->join('travels as t', 't.id', '=', 'i.travel_id')->whereNull('i.deleted_at')->whereNull('t.deleted_at')->where('t.status', Travel::APPROVED)->whereNotNull('i.contact_id')
            ->when($contactId, fn ($q, $v) => $q->where('i.contact_id', $v))->orderBy('i.id')
            ->get(['i.contact_id', 'i.currency', 'i.exchange_rate', 'i.amount', 'i.category', 'i.description', 't.id as travel_id', 't.reference', 't.booking_date', 't.depart_date', 't.to_place']);
        foreach ($items as $i) {
            $date = $i->booking_date ?: $i->depart_date;
            if ($asOf && $date > $asOf) {
                continue;
            }
            if (self::cents($i->amount) !== 0) {
                $out[] = ['contact_id' => (int) $i->contact_id, 'currency' => strtoupper($i->currency), 'kind' => 'item', 'rate' => (float) $i->exchange_rate, 'cents' => self::cents($i->amount), 'date' => $date, 'travel_id' => (int) $i->travel_id, 'reference' => $i->reference, 'to' => $i->to_place, 'label' => trim($i->category . ($i->description ? ' - ' . $i->description : ''))];
            }
        }

        return $out;
    }

    /**
     * One row per agent and currency. $f: contact_id, currency, as_of (a date: only what happened on or before it), only_owing.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function balances(array $f = []): array
    {
        $contactId = ! empty($f['contact_id']) ? (int) $f['contact_id'] : null;
        $currency = ! empty($f['currency']) ? strtoupper((string) $f['currency']) : null;
        $asOf = ! empty($f['as_of']) ? (string) $f['as_of'] : null;
        $opens = self::openings($contactId);
        $rows = [];
        $row = function (int $cid, string $cur) use (&$rows): string {
            $rows[$cid . '|' . $cur] ??= ['contact_id' => $cid, 'currency' => $cur, 'opening' => 0, 'opening_date' => null, 'ticket' => 0, 'visa' => 0, 'other' => 0, 'travels' => 0, 'paid' => 0, 'reserved' => 0, 'pending' => 0, 'payments' => 0, 'last_payment' => null, 'last_travel' => null, '_t' => []];

            return $cid . '|' . $cur;
        };
        foreach ($opens as $k => $o) {
            if ($asOf && $o->as_of_date > $asOf) {
                continue;
            }
            [$cid, $cur] = explode('|', $k);
            $r = &$rows[$row((int) $cid, $cur)];
            $r['opening'] = self::cents($o->amount);
            $r['opening_date'] = $o->as_of_date;
            unset($r);
        }
        foreach (self::charges($contactId, $asOf) as $c) {
            $o = $opens[$c['contact_id'] . '|' . $c['currency']] ?? null;
            if ($o && $c['date'] < $o->as_of_date) {
                continue; // booked before the opening date: already inside the opening balance
            }
            $r = &$rows[$row($c['contact_id'], $c['currency'])];
            $r[$c['kind'] === 'item' ? 'other' : $c['kind']] += $c['cents'];
            $r['_t'][$c['travel_id']] = true;
            $r['last_travel'] = max((string) $r['last_travel'], (string) $c['date']) ?: null;
            unset($r);
        }
        $pay = DB::table('travel_agent_payments')->whereNull('deleted_at')->whereIn('status', [TravelAgentPayment::PAID, TravelAgentPayment::PENDING_VOUCHER])
            ->when($contactId, fn ($q, $v) => $q->where('contact_id', $v))->get(['contact_id', 'currency', 'amount', 'paid_on', 'status', 'requested_on']);
        foreach ($pay as $p) {
            if ($p->status === TravelAgentPayment::PAID) {
                if ($asOf && $p->paid_on > $asOf) {
                    continue;
                }
                $r = &$rows[$row((int) $p->contact_id, strtoupper($p->currency))];
                $r['paid'] += self::cents($p->amount);
                $r['payments']++;
                $r['last_payment'] = max((string) $r['last_payment'], (string) $p->paid_on) ?: null;
            } else {
                $r = &$rows[$row((int) $p->contact_id, strtoupper($p->currency))];
                $r['reserved'] += self::cents($p->amount);
            }
            unset($r);
        }
        // waiting travels: what they would add once approved (information only)
        foreach (DB::table('travels')->whereNull('deleted_at')->where('status', Travel::PENDING)->when($contactId, fn ($q, $v) => $q->where(fn ($w) => $w->where('ticket_agent_contact_id', $v)->orWhere('visa_agent_contact_id', $v)))->get(['currency', 'ticket_agent_contact_id', 'ticket_amount', 'visa_agent_contact_id', 'visa_amount', 'has_visa']) as $t) {
            if ($t->ticket_agent_contact_id && (! $contactId || (int) $t->ticket_agent_contact_id === $contactId)) {
                $r = &$rows[$row((int) $t->ticket_agent_contact_id, strtoupper($t->currency))];
                $r['pending'] += self::cents($t->ticket_amount);
                unset($r);
            }
            if ($t->has_visa && $t->visa_agent_contact_id && (! $contactId || (int) $t->visa_agent_contact_id === $contactId)) {
                $r = &$rows[$row((int) $t->visa_agent_contact_id, strtoupper($t->currency))];
                $r['pending'] += self::cents($t->visa_amount);
                unset($r);
            }
        }
        foreach (DB::table('travel_items as i')->join('travels as t', 't.id', '=', 'i.travel_id')->whereNull('i.deleted_at')->whereNull('t.deleted_at')->where('t.status', Travel::PENDING)->whereNotNull('i.contact_id')->when($contactId, fn ($q, $v) => $q->where('i.contact_id', $v))->get(['i.contact_id', 'i.currency', 'i.amount']) as $i) {
            $r = &$rows[$row((int) $i->contact_id, strtoupper($i->currency))];
            $r['pending'] += self::cents($i->amount);
            unset($r);
        }
        $names = Contact::withTrashed()->whereIn('id', array_unique(array_column($rows, 'contact_id')))->get(['id', 'name', 'phone', 'category'])->keyBy('id');
        $out = [];
        foreach ($rows as $r) {
            if ($currency && $r['currency'] !== $currency) {
                continue;
            }
            $balance = $r['opening'] + $r['ticket'] + $r['visa'] + $r['other'] - $r['paid'];
            $c = $names[$r['contact_id']] ?? null;
            $out[] = [
                'contact_id' => $r['contact_id'], 'agent' => $c ? $c->name : '#' . $r['contact_id'], 'phone' => $c ? $c->phone : null, 'category' => $c ? $c->category : null, 'currency' => $r['currency'],
                'opening' => self::money($r['opening']), 'opening_date' => $r['opening_date'], 'ticket' => self::money($r['ticket']), 'visa' => self::money($r['visa']), 'other' => self::money($r['other']), 'billed' => self::money($r['ticket'] + $r['visa'] + $r['other']),
                'travels' => count($r['_t']), 'paid' => self::money($r['paid']), 'payments' => $r['payments'], 'balance' => self::money($balance), 'reserved' => self::money($r['reserved']), 'available' => self::money($balance - $r['reserved']), 'pending' => self::money($r['pending']),
                'last_payment' => $r['last_payment'], 'last_travel' => $r['last_travel'],
            ];
        }
        if (! empty($f['only_owing'])) {
            $out = array_values(array_filter($out, fn ($r) => (float) $r['balance'] > 0.004));
        }
        usort($out, fn ($a, $b) => [mb_strtolower($a['agent']), $a['currency']] <=> [mb_strtolower($b['agent']), $b['currency']]);

        return $out;
    }

    /** What one agent is owed now, per currency: ['TZS' => '1200000.00', ...]. */
    public static function owed(int $contactId): array
    {
        $o = [];
        foreach (self::balances(['contact_id' => $contactId]) as $r) {
            $o[$r['currency']] = $r['balance'];
        }

        return $o;
    }

    /** What can still be asked for in a new Payment Voucher: the balance minus what other waiting vouchers already reserve. */
    public static function available(int $contactId, string $currency): float
    {
        foreach (self::balances(['contact_id' => $contactId, 'currency' => $currency]) as $r) {
            return (float) $r['available'];
        }

        return 0.0;
    }

    /** Totals of balance rows per currency. */
    public static function totals(array $rows): array
    {
        $t = [];
        foreach ($rows as $r) {
            $x = &$t[$r['currency']];
            $x ??= ['currency' => $r['currency'], 'opening' => 0, 'billed' => 0, 'paid' => 0, 'balance' => 0, 'reserved' => 0, 'available' => 0, 'pending' => 0, 'agents' => 0];
            foreach (['opening', 'billed', 'paid', 'balance', 'reserved', 'available', 'pending'] as $k) {
                $x[$k] += self::cents($r[$k]);
            }
            $x['agents']++;
            unset($x);
        }
        ksort($t);

        return array_values(array_map(function ($x) {
            foreach (['opening', 'billed', 'paid', 'balance', 'reserved', 'available', 'pending'] as $k) {
                $x[$k] = self::money($x[$k]);
            }

            return $x;
        }, $t));
    }

    /**
     * The account of one agent in date order with a running balance per currency: the opening balance, each approved travel (ticket / visa), each payment.
     * A voided payment is listed but does not move the balance.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function statement(int $contactId, ?string $currency = null, ?string $from = null, ?string $to = null): array
    {
        $currency = $currency ? strtoupper($currency) : null;
        $opens = self::openings($contactId);
        $events = [];
        foreach ($opens as $k => $o) {
            [, $cur] = explode('|', $k);
            $events[] = ['date' => (string) $o->as_of_date, 'order' => 0, 'currency' => $cur, 'kind' => 'opening', 'rate' => 0, 'ref' => '', 'text' => __('Opening balance') . ($o->notes ? ' - ' . $o->notes : ''), 'debit' => self::cents($o->amount), 'credit' => 0, 'travel_id' => null, 'payment_id' => null, 'voided' => false, 'status' => 'Opening'];
        }
        foreach (self::charges($contactId) as $c) {
            if ($c['contact_id'] !== $contactId) {
                continue;
            }
            $o = $opens[$contactId . '|' . $c['currency']] ?? null;
            if ($o && $c['date'] < $o->as_of_date) {
                continue;
            }
            $events[] = ['date' => (string) $c['date'], 'order' => 1, 'currency' => $c['currency'], 'kind' => $c['kind'], 'rate' => $c['rate'], 'ref' => $c['reference'], 'text' => $c['kind'] === 'item' ? $c['label'] . ' - ' . $c['to'] : ($c['kind'] === 'visa' ? __('Visa') : __('Ticket')) . ' - ' . $c['to'], 'debit' => $c['cents'], 'credit' => 0, 'travel_id' => $c['travel_id'], 'payment_id' => null, 'voided' => false, 'status' => 'Approved'];
        }
        $vouchers = DB::table('payment_vouchers')->whereIn('id', DB::table('travel_agent_payments')->where('contact_id', $contactId)->whereNotNull('payment_voucher_id')->pluck('payment_voucher_id'))->pluck('pv_number', 'id');
        foreach (DB::table('travel_agent_payments')->whereNull('deleted_at')->where('contact_id', $contactId)->orderBy('id')->get() as $p) {
            $pv = $p->payment_voucher_id ? ($vouchers[$p->payment_voucher_id] ?? '') : '';
            $paid = $p->status === TravelAgentPayment::PAID;
            $label = $paid ? __('Paid by voucher') . ($pv ? ' ' . $pv : '') . ($p->method ? ' (' . $p->method . ')' : '') : ($p->status === TravelAgentPayment::PENDING_VOUCHER ? __('Payment requested, voucher :v waiting to be approved and paid (not counted yet)', ['v' => $pv]) : __('Voucher :v was not paid (released)', ['v' => $pv]) . ($p->release_reason ? ' - ' . $p->release_reason : ''));
            $events[] = ['date' => (string) ($paid ? $p->paid_on : $p->requested_on), 'order' => 2, 'currency' => strtoupper($p->currency), 'kind' => $paid ? 'payment' : 'payment_memo', 'rate' => (float) $p->exchange_rate, 'ref' => $p->reference, 'text' => $label, 'debit' => 0, 'credit' => self::cents($p->amount), 'travel_id' => $p->travel_id ? (int) $p->travel_id : null, 'payment_id' => (int) $p->id, 'voided' => ! $paid, 'status' => $p->status];
        }
        usort($events, fn ($a, $b) => [$a['currency'], $a['date'], $a['order']] <=> [$b['currency'], $b['date'], $b['order']]);
        $run = [];
        $out = [];
        foreach ($events as $e) {
            if ($currency && $e['currency'] !== $currency) {
                continue;
            }
            if (! $e['voided']) {
                $run[$e['currency']] = ($run[$e['currency']] ?? 0) + $e['debit'] - $e['credit'];
            }
            if (($from && $e['date'] < $from) || ($to && $e['date'] > $to)) {
                continue;
            }
            $out[] = ['date' => $e['date'], 'currency' => $e['currency'], 'kind' => $e['kind'], 'rate' => $e['currency'] === 'TZS' || ! $e['rate'] ? '' : rtrim(rtrim(number_format($e['rate'], 6, '.', ''), '0'), '.'), 'reference' => $e['ref'], 'text' => $e['text'], 'owed' => $e['debit'] ? self::money($e['debit']) : '', 'paid' => $e['credit'] ? self::money($e['credit']) : '',
                'balance' => self::money($run[$e['currency']] ?? 0), 'travel_id' => $e['travel_id'], 'payment_id' => $e['payment_id'], 'memo' => $e['voided'], 'status' => $e['status'] ?? ''];
        }

        return $out;
    }
}
