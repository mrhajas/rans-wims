<?php

namespace App\Services\Administration;

use App\Models\AdminDocument;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/** Hands an Administration event to its Alerts page (who is told, on which channel). Never throws: an alert must not stop a document being saved. */
class AdminNotifier
{
    public static function fmt($v): string
    {
        return number_format((float) $v, 2, '.', ',');
    }

    public static function fire(string $event, AdminDocument $d, ?User $actor = null, string $message = ''): void
    {
        try {
            $d->loadMissing(['user:id,name', 'beneficiary:id,name']);
            $amount = $d->currency . ' ' . self::fmt($d->total_amount);
            $owners = array_values(array_unique(array_filter([(int) $d->user_id, (int) $d->beneficiary_user_id])));
            WorkflowMessenger::dispatch('administration', $event, $d, $actor, [
                'account_id' => $d->account_id ?? $actor?->account_id ?? 1,
                'title' => __('Administration :r', ['r' => $d->reference]), 'message' => $message ?: __(':a for :d', ['a' => $amount, 'd' => $d->doc_date->format('d M Y')]),
                'url' => route('weekly-administration.show', $d->id), 'owner_user_id' => (int) $d->user_id, 'owner_users' => collect($owners)->map(fn ($id) => (object) ['id' => $id])->all(),
                'dedupe' => 'admin-' . $d->id . '-' . $event . '-' . now()->format('YmdHi'),
                'values' => ['reference' => $d->reference, 'date' => $d->doc_date->format('d M Y'), 'amount' => $amount, 'beneficiary' => (string) ($d->beneficiary_name ?: optional($d->beneficiary)->name), 'status' => $d->status, 'lines' => (string) $d->lines()->count(), 'message' => $message],
                'details' => array_filter([__('Reference') => $d->reference, __('Date') => $d->doc_date->format('d M Y'), __('Requested by') => $d->beneficiary_name, __('Total') => $amount, __('Status') => $d->status]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Administration alert failed', ['document' => $d->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
