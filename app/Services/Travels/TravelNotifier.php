<?php

namespace App\Services\Travels;

use App\Models\Travel;
use App\Models\TravelAgentPayment;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/** Hands a Travels event to its Alerts page (who is told, on which channel). Never throws: an alert must not stop a travel or a payment being saved. */
class TravelNotifier
{
    public static function fire(string $event, Travel $t, ?User $actor = null, string $message = '', array $extra = []): void
    {
        try {
            $t->loadMissing(['travellers.staff', 'ticketAgent:id,name', 'user:id,name']);
            $people = $t->travellers->map(fn ($x) => $x->user_id ? (object) ['id' => $x->user_id] : ($x->staff && $x->staff->user_id ? (object) ['id' => $x->staff->user_id] : null))->filter()->values()->all();
            $flight = trim(($t->airline ?: '') . ' ' . ($t->flight_number ?: ''));
            $amount = $t->currency . ' ' . TravelLedger::fmt($t->total_amount);
            $agent = optional($t->ticketAgent)->name ?: optional($t->visaAgent)->name ?: '';
            WorkflowMessenger::dispatch('travels', $event, $t, $actor, [
                'account_id' => $t->account_id ?? $actor?->account_id ?? 1,
                'title' => __('Travel :r', ['r' => $t->reference]), 'message' => $message ?: __(':n to :d on :t', ['n' => $t->travellerNames(), 'd' => $t->to_place, 't' => $t->depart_date->format('d M Y')]),
                'url' => route('travels.show', $t->id), 'owner_user_id' => (int) $t->user_id, 'owner_users' => $people,
                'dedupe' => 'travel-' . $t->id . '-' . $event . '-' . ($extra['days'] ?? ''),
                'values' => ['reference' => $t->reference, 'travellers' => $t->travellerNames(), 'destination' => $t->to_place, 'depart_date' => $t->depart_date->format('d M Y'), 'return_date' => $t->return_date ? $t->return_date->format('d M Y') : '',
                    'flight' => $flight, 'agent' => $agent, 'amount' => $amount, 'days' => (string) ($extra['days'] ?? ''), 'message' => $message],
                'details' => array_filter([__('Reference') => $t->reference, __('Traveller(s)') => $t->travellerNames(), __('From') => $t->from_place, __('To') => $t->to_place, __('Departs') => $t->depart_date->format('d M Y'), __('Returns') => $t->return_date ? $t->return_date->format('d M Y') : '',
                    __('Flight') => $flight, __('Ticket agent') => optional($t->ticketAgent)->name, __('Amount') => $amount]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Travel alert failed', ['travel' => $t->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    public static function firePayment(string $event, TravelAgentPayment $p, ?User $actor = null, string $message = ''): void
    {
        try {
            $p->loadMissing(['contact:id,name', 'voucher:id,pv_number']);
            $amount = $p->currency . ' ' . TravelLedger::fmt($p->amount);
            WorkflowMessenger::dispatch('travels', $event, $p, $actor, [
                'account_id' => $p->account_id ?? $actor?->account_id ?? 1,
                'title' => __('Travel agent payment :r', ['r' => $p->reference]), 'message' => $message ?: __(':a to :n', ['a' => $amount, 'n' => optional($p->contact)->name]),
                'url' => route('travels.agents.payments.show', $p->id), 'owner_user_id' => (int) $p->user_id,
                'values' => ['payment' => $p->reference, 'voucher' => optional($p->voucher)->pv_number ?? '', 'agent' => optional($p->contact)->name ?? '', 'amount' => $amount, 'method' => (string) $p->method, 'message' => $message],
                'details' => array_filter([__('Payment') => $p->reference, __('Payment Voucher') => optional($p->voucher)->pv_number, __('Agent') => optional($p->contact)->name, __('Amount') => $amount, __('Method') => $p->method, __('Date') => ($p->paid_on ?: $p->requested_on)->format('d M Y')]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Travel payment alert failed', ['payment' => $p->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
