<?php

namespace App\Services\Subcontracts;

use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/** Hands a subcontract event to its Alerts page (who is told, on which channel). Never throws: an alert must not stop a record being saved. */
class SubcontractNotifier
{
    /**
     * @param string $kind agreement | variation | request | payment | import | claim
     * @param object $record the record (needs reference, id; optional account_id, user_id / requested_by_user_id)
     */
    public static function fire(string $event, string $kind, $record, ?User $actor = null, string $message = '', array $extra = []): void
    {
        try {
            $routes = ['agreement' => 'subcontracts.agreements.show', 'variation' => 'subcontracts.variations.show', 'request' => 'subcontracts.requests.show', 'payment' => 'subcontracts.requests.show', 'import' => 'subcontracts.import.show', 'claim' => 'subcontracts.claims.show'];
            $labels = ['agreement' => 'Subcontract Agreement', 'variation' => 'Subcontract Variation', 'request' => 'Subcontract Payment Request', 'payment' => 'Subcontract Payment', 'import' => 'Subcontract Import', 'claim' => 'Contract Payment Request'];
            $owner = (int) ($record->requested_by_user_id ?? $record->user_id ?? 0);
            $agreement = $kind === 'agreement' ? $record : (isset($record->agreement_id) ? \App\Models\SubcontractAgreement::find($record->agreement_id) : null);
            $contact = $agreement ? optional(\App\Models\Contact::withTrashed()->find($agreement->contact_id))->name : ($kind === 'claim' ? optional(\App\Models\Contact::withTrashed()->find($record->contact_id))->name : '');
            $amount = $extra['amount'] ?? ($record->requested_amount ?? $record->requested_total ?? $record->amount ?? $record->original_amount ?? '');
            WorkflowMessenger::dispatch('subcontracts', $event, $record, $actor, [
                'account_id' => $record->account_id ?? $actor?->account_id ?? 1,
                'title' => __($labels[$kind] ?? 'Subcontract') . ' ' . ($record->reference ?? ''), 'message' => $message ?: ($contact ? $contact . ' - ' . ($record->reference ?? '') : ($record->reference ?? '')),
                'url' => route($routes[$kind] ?? 'subcontracts.dashboard', $record->id), 'owner_user_id' => $owner,
                'values' => ['reference' => $record->reference ?? '', 'subcontractor' => (string) $contact, 'agreement' => $agreement->reference ?? '', 'amount' => $amount !== '' ? number_format((float) $amount, 2) : '', 'message' => $message],
                'details' => array_filter([__('Reference') => $record->reference ?? null, __('Subcontractor') => $contact, __('Agreement') => $agreement->reference ?? null, __('Amount') => $amount !== '' ? number_format((float) $amount, 2) : null]),
            ] + $extra);
        } catch (\Throwable $e) {
            \Log::warning('Subcontract alert failed', ['kind' => $kind, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
