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
                'title' => __('Administration :r - :hq', ['r' => $d->reference, 'hq' => $d->hq]), 'message' => $message ?: __(':hq: :a for :d', ['hq' => $d->hq, 'a' => $amount, 'd' => $d->doc_date->format('d M Y')]),
                'url' => route('weekly-administration.show', $d->id), 'owner_user_id' => (int) $d->user_id, 'owner_users' => collect($owners)->map(fn ($id) => (object) ['id' => $id])->all(),
                'dedupe' => 'admin-' . $d->id . '-' . $event . '-' . now()->format('YmdHi'),
                'values' => ['reference' => $d->reference, 'hq' => (string) $d->hq, 'date' => $d->doc_date->format('d M Y'), 'amount' => $amount, 'beneficiary' => (string) ($d->beneficiary_name ?: optional($d->beneficiary)->name), 'status' => $d->status, 'lines' => (string) $d->lines()->count(), 'message' => $message],
                'details' => array_filter([__('Reference') => $d->reference, __('Headquarters') => $d->hq, __('Date') => $d->doc_date->format('d M Y'), __('Requested by') => $d->beneficiary_name, __('Total') => $amount, __('Status') => $d->status]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Administration alert failed', ['document' => $d->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** A payment event of a document (marked paid, fully paid, voucher made or released, reversed). */
    public static function firePayment(string $event, AdminDocument $d, \App\Models\AdminDocumentPayment $p, ?User $actor = null, string $message = ''): void
    {
        try {
            $d->loadMissing(['user:id,name', 'beneficiary:id,name']);
            $p->loadMissing('voucher:id,pv_number');
            $amount = $d->currency . ' ' . self::fmt($p->total_amount);
            $balance = $d->currency . ' ' . self::fmt(max(0, (float) $d->total_amount - (float) $d->paid_total));
            $owners = array_values(array_unique(array_filter([(int) $d->user_id, (int) $d->beneficiary_user_id, (int) $p->paid_to_user_id])));
            WorkflowMessenger::dispatch('administration', $event, $d, $actor, [
                'account_id' => $d->account_id ?? $actor?->account_id ?? 1,
                'title' => __('Administration :r payment - :hq', ['r' => $d->reference, 'hq' => $d->hq]), 'message' => $message ?: __(':a to :n', ['a' => $amount, 'n' => $p->paid_to_name]),
                'url' => route('weekly-administration.show', $d->id), 'owner_user_id' => (int) $d->user_id, 'owner_users' => collect($owners)->map(fn ($id) => (object) ['id' => $id])->all(),
                'dedupe' => 'admin-pay-' . $p->id . '-' . $event . '-' . ($p->updated_at ? $p->updated_at->format('YmdHis') : ''),
                'values' => ['reference' => $d->reference, 'hq' => (string) $d->hq, 'date' => $d->doc_date->format('d M Y'), 'amount' => $d->currency . ' ' . self::fmt($d->total_amount), 'beneficiary' => (string) $d->beneficiary_name, 'status' => $d->status, 'lines' => (string) $d->lines()->count(),
                    'paid_amount' => $amount, 'paid_total' => $d->currency . ' ' . self::fmt($d->paid_total), 'balance' => $balance, 'paid_to' => (string) $p->paid_to_name, 'payment' => $p->reference, 'voucher' => (string) optional($p->voucher)->pv_number,
                    'payment_status' => $d->payment_status, 'method' => (string) $p->method, 'message' => $message],
                'details' => array_filter([__('Reference') => $d->reference, __('Headquarters') => $d->hq, __('Payment') => $p->reference, __('Paid to') => $p->paid_to_name, __('Amount') => $amount, __('Paid so far') => $d->currency . ' ' . self::fmt($d->paid_total), __('Balance') => $balance,
                    __('Payment Voucher') => optional($p->voucher)->pv_number, __('Method') => $p->method]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Administration payment alert failed', ['document' => $d->id, 'payment' => $p->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
