<?php

namespace App\Services;

use App\Models\IssueReturn;
use App\Models\IssueReturnLog;
use App\Models\IssueTransaction;

class IssueReturnLogService
{
    public static function write(string $action, ?IssueTransaction $issue = null, ?IssueReturn $return = null, ?string $details = null): void
    {
        $warehouseId = $issue?->warehouse_id ?? $return?->issueTransaction?->warehouse_id;

        IssueReturnLog::query()->create([
            'account_id' => getAccountId(),
            'user_id' => optional(auth()->user())->id,
            'issue_transaction_id' => $issue?->id ?? $return?->issue_transaction_id,
            'issue_return_id' => $return?->id,
            'warehouse_id' => $warehouseId,
            'action' => $action,
            'ip_address' => request()->ip(),
            'details' => $details,
        ]);
    }
}
