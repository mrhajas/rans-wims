<?php

namespace App\Services;

use App\Models\PaymentVoucher;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Hands a Payment Voucher event to the Payment Vouchers workflow page's settings (Who Gets Notified / Messages). Events: submitted,
 * approved, authorised, rejected, cancelled, labour_list_paid, transport_trips_paid, paid.
 */
class PaymentVoucherNotifier
{
    /**
     * @param User|null $actor the person the audience must not include (unless he or she is the owner)
     * $ctx may override owner_user_id, owner_users, url, values (for example 'actor' => the name to show as "done by"), remarks.
     */
    public static function fire(string $event, PaymentVoucher $voucher, ?User $actor, string $title, string $message, array $ctx = []): bool
    {
        try {
            return self::send($event, $voucher, $actor, $title, $message, $ctx);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Payment Voucher notice failed', ['event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** A batch of vouchers was created (one notice for the whole batch). */
    public static function batch(\App\Models\PaymentVoucherBatch $batch, ?User $actor): bool
    {
        try {
            $batch->loadMissing(['vouchers:id,payment_voucher_batch_id,total_amount']);
            $count = $batch->vouchers->count();
            $amount = 'TZS ' . number_format((float) $batch->vouchers->sum('total_amount'), 0);

            return WorkflowMessenger::dispatch('payment_vouchers', 'batch_created', $batch, $actor, [
                'account_id' => $actor?->account_id ?? 1, 'skip_actor' => true,
                'title' => 'Voucher Batch Created', 'message' => 'Batch ' . $batch->batch_number . ' with ' . $count . ' payment vouchers (' . $amount . ') was created by ' . ($actor?->name ?? '') . '.',
                'url' => route('payment-vouchers.batches.show', $batch->id), 'warehouse_ids' => $batch->warehouse_id ? [$batch->warehouse_id] : null,
                'details' => [__('Batch') => $batch->batch_number, __('Vouchers') => $count, __('Total') => $amount, __('Type') => $batch->type],
                'values' => ['batch' => $batch->batch_number, 'reference' => $batch->batch_number, 'count' => (string) $count, 'amount' => $amount, 'voucher' => $batch->batch_number, 'payee' => '', 'status' => 'Draft', 'preparer' => $actor?->name ?? '', 'project' => ''],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Payment Voucher batch notice failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private static function send(string $event, PaymentVoucher $voucher, ?User $actor, string $title, string $message, array $ctx): bool
    {
        $voucher->loadMissing(['preparedBy:id,name,account_id', 'warehouse:id,name', 'payeeContact:id,name']);
        $preparer = $voucher->preparedBy;
        $values = [
            'voucher' => $voucher->pv_number, 'reference' => $voucher->pv_number, 'payee' => $voucher->payee_name ?: (optional($voucher->payeeContact)->name ?? ''),
            'project' => optional($voucher->warehouse)->name ?? PaymentVoucher::COMPANY_NAME, 'amount' => 'TZS ' . number_format((float) $voucher->total_amount, 0),
            'status' => $voucher->status, 'preparer' => optional($preparer)->name ?? '',
        ];

        return WorkflowMessenger::dispatch('payment_vouchers', $event, $voucher, $actor, array_merge([
            'account_id' => $actor?->account_id ?? $preparer?->account_id ?? 1, 'warehouse_ids' => $voucher->warehouse_id ? [$voucher->warehouse_id] : null,
            'title' => $title, 'message' => $message, 'url' => route('payment-vouchers.show', $voucher->id),
            'owner_user_id' => (int) $voucher->prepared_by_user_id,
            'details' => [__('Voucher') => $voucher->pv_number, __('Payee') => $values['payee'], __('Amount') => $values['amount'], __('Prepared by') => $values['preparer']],
        ], $ctx, ['values' => array_merge($values, (array) ($ctx['values'] ?? []))]));
    }
}
