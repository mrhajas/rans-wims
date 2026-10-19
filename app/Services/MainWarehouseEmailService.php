<?php

namespace App\Services;

use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The full email report of a warehouse activity (items table, history, PDF). Who gets it, whether it is sent and its subject are set on
 * the workflow page of the module it belongs to (Check-ins, Check-outs, Transfers, Adjustments, Material Reallocations, Site Reports):
 * the event named "... Email Report". This class only hands the report to that event.
 */
class MainWarehouseEmailService
{
    public static function isMainWarehouseCategory(?string $status): bool
    {
        $normalized = strtolower(trim((string) $status));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return in_array($normalized, ['main warehouse', 'main warehouses'], true);
    }

    /**
     * @param  string  $module       workflow module key, e.g. 'checkins'
     * @param  string  $event        its email report event, e.g. 'report'
     * @param  string  $type         'Checkin' | 'Checkout' | 'Transfer' | 'Adjustment' | 'Material Reallocation' | 'Site Report'
     * @param  array   $details      extra key => value rows shown in the email body
     * @param  array   $auditTrail   optional [{action, status, by, datetime, remarks}]
     * @param  array   $chainOfCustody optional [{milestone, by, when}]
     * @param  array   $attachments  optional [{data, filename, mime}] raw files
     */
    public static function report(
        string $module,
        string $event,
        Model $record,
        string $type,
        string $reference,
        string $warehouseName,
        array $details = [],
        ?int $accountId = null,
        array $items = [],
        ?string $performedBy = null,
        ?string $heading = null,
        array $auditTrail = [],
        array $chainOfCustody = [],
        array $attachments = [],
        array $warehouseIds = []
    ): void {
        $performedBy ??= optional(Auth::user())->name ?? 'System';
        $heading ??= "{$type} Done at {$warehouseName}";

        WorkflowMessenger::dispatch($module, $event, $record, Auth::user(), [
            'account_id' => (int) ($accountId ?: (Auth::user()?->account_id ?? 1)),
            'title' => $heading, 'message' => "{$type} {$reference} at {$warehouseName}, by {$performedBy}.",
            'include_actor' => true, 'warehouse_ids' => $warehouseIds ?: null,
            'dedupe' => ($record->getKey() ?? '0') . '|' . $event . '|' . md5($heading) . '|' . intdiv(time(), 20),
            'values' => ['reference' => $reference, 'warehouse' => $warehouseName, 'type' => $type, 'actor' => $performedBy, 'action' => (string) ($details['Action'] ?? '')],
            'rich_mail' => ['type' => $type, 'reference' => $reference, 'warehouse' => $warehouseName, 'details' => $details, 'items' => $items, 'audit' => $auditTrail, 'chain' => $chainOfCustody, 'attachments' => $attachments],
        ]);
    }
}
