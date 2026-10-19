<?php

namespace App\Listeners;

use App\Events\LabourStatusChanged;
use App\Models\LabourList;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Hands the status change to the Labour Lists workflow page's settings (Who Gets Notified / Messages). Who is told, on which channels and
 * with which words is decided there; the starting values are the old behaviour: whoever holds the next step in the chain, plus the list's
 * creator, on the app bell.
 */
class LabourStatusChangedListener
{
    public function handle(LabourStatusChanged $event): void
    {
        $labourList = $event->labourList;
        $accountId = $labourList->createdBy?->account_id ?? optional($labourList->warehouse)->account_id;

        $eventKey = match ($labourList->status) {
            LabourList::STATUS_SUBMITTED => 'submitted',
            LabourList::STATUS_RETURNED => 'returned',
            LabourList::STATUS_CLEARED => 'cleared',
            LabourList::STATUS_APPROVED => 'approved',
            LabourList::STATUS_PAYMENT_VOUCHER_CREATED => 'payment_voucher_created',
            default => null,
        };
        if (! $eventKey || ! $accountId) {
            return;
        }

        WorkflowMessenger::dispatch('labour', $eventKey, $labourList, auth()->user(), [
            'account_id' => $accountId,
            'owner_user_id' => (int) $labourList->created_by_user_id,
            'remarks' => $labourList->review_remarks,
            'title' => match ($eventKey) {
                'submitted' => 'Labour List Submitted', 'returned' => 'Labour List Returned', 'cleared' => 'Labour List Cleared',
                'approved' => 'Labour List Approved', default => 'Payment Voucher Created',
            },
            'message' => $labourList->reference . ' - ' . $labourList->status . '.',
        ]);
    }
}
