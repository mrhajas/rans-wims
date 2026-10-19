<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Where each line of the Activity timeline opens (always in a new tab, see BriefView). In order of preference:
 *  1. the record's own page (a check-in, a transfer, a voucher ...); a line entry (one item of a transfer) opens its parent record;
 *  2. otherwise the module's list page;
 *  3. otherwise no link (a person is never sent to a page they are not allowed to open).
 * The viewer must hold the read ability of the page, exactly as the side menu requires.
 */
class RecordLinks
{
    /** model => [route name, read ability] : the record's own page */
    private const RECORD = [
        \App\Models\Checkin::class => ['checkins.show', 'read-checkins'],
        \App\Models\Checkout::class => ['checkouts.show', 'read-checkouts'],
        \App\Models\Transfer::class => ['transfers.show', 'read-transfers'],
        \App\Models\MultiTransfer::class => ['multi-transfers.show', 'read-multi-transfers'],
        \App\Models\Adjustment::class => ['adjustments.show', 'read-adjustments'],
        \App\Models\SuperAdjustment::class => ['super-adjustments.show', 'read-super-adjustments'],
        \App\Models\MaterialRequest::class => ['material-requests.show', 'read-material-requests'],
        \App\Models\PaymentVoucher::class => ['payment-vouchers.show', 'read-payment-vouchers'],
        \App\Models\Staff::class => ['staffs.show', 'read-staffs'],
        \App\Models\Item::class => ['items.show', 'read-items'],
        \App\Models\Asset::class => ['assets.show', 'read-assets'],
        \App\Models\FundAllocation::class => ['fund-allocations.show', 'read-fund-allocations'],
        \App\Models\DailySiteReport::class => ['daily-site-reports.show', 'read-daily-site-reports'],
        \App\Models\TransportRecord::class => ['transport-records.show', 'read-transport-records'],
        \App\Models\GatePass::class => ['gate-passes.show', 'read-gate-passes'],
        \App\Models\DeliveryNote::class => ['delivery-notes.show', 'read-delivery-notes'],
        \App\Models\SiteMeeting::class => ['site-meetings.show', 'read-site-meetings'],
        \App\Models\LabourList::class => ['labour.show', 'read-labour'],
        \App\Models\CaseTicket::class => ['case-tracking.show', 'read-case-tracking'],
        \App\Models\IssueTransaction::class => ['issue-materials.show', 'read-issue-returns'],
        \App\Models\LoginLog::class => ['login-logs.show', 'read-login-logs'],
    ];

    /** line / child model => [parent model, foreign key column] */
    private const PARENT = [
        \App\Models\CheckinItem::class => [\App\Models\Checkin::class, 'checkin_id'],
        \App\Models\CheckoutItem::class => [\App\Models\Checkout::class, 'checkout_id'],
        \App\Models\TransferItem::class => [\App\Models\Transfer::class, 'transfer_id'],
        \App\Models\AdjustmentItem::class => [\App\Models\Adjustment::class, 'adjustment_id'],
        \App\Models\SuperAdjustmentItem::class => [\App\Models\SuperAdjustment::class, 'super_adjustment_id'],
        \App\Models\MaterialRequestItem::class => [\App\Models\MaterialRequest::class, 'material_request_id'],
        \App\Models\PaymentVoucherItem::class => [\App\Models\PaymentVoucher::class, 'payment_voucher_id'],
        \App\Models\FundAllocationItem::class => [\App\Models\FundAllocation::class, 'fund_allocation_id'],
        \App\Models\IssueTransactionItem::class => [\App\Models\IssueTransaction::class, 'issue_transaction_id'],
        \App\Models\Stock::class => [\App\Models\Item::class, 'item_id'],
        \App\Models\StockTrail::class => [\App\Models\Item::class, 'item_id'],
        \App\Models\StaffContract::class => [\App\Models\Staff::class, 'staff_id'],
        \App\Models\AssetMovement::class => [\App\Models\Asset::class, 'asset_id'],
    ];

    /** module key => [route name, read abilities (any)] : the module's list page, used when the record has no page of its own */
    private const MODULE = [
        'archive' => ['archive.dashboard', ['read-archive']],
        'site_diary' => ['site-diary.index', ['read-site-diary', 'view-all-site-diaries']],
        'site_meetings' => ['site-meetings.index', ['read-site-meetings']],
        'signatories' => ['signatories.index', ['view-signatories']],
        'weather_risk' => ['weather.overview', ['read-weather']],
        'material_requests' => ['material-requests.index', ['read-material-requests', 'view-all-material-requests']],
        'material_reallocation' => ['material-reallocations.index', ['read-material-reallocations']],
        'issue_return' => ['issue-materials.index', ['read-issue-returns']],
        'funds_allocation' => ['fund-allocations.index', ['read-fund-allocations']],
        'salary' => ['salary.periods.index', ['read-salary-periods']],
        'payment_vouchers' => ['payment-vouchers.index', ['read-payment-vouchers']],
        'assets' => ['assets.index', ['read-assets']],
        'transport' => ['transport-records.index', ['read-transport-records']],
        'transfers' => ['transfers.index', ['read-transfers']],
        'multi_transfers' => ['multi-transfers.index', ['read-multi-transfers']],
        'checkins' => ['checkins.index', ['read-checkins']],
        'checkouts' => ['checkouts.index', ['read-checkouts']],
        'adjustments' => ['adjustments.index', ['read-adjustments']],
        'stock' => ['items.index', ['read-items']],
        'warehouses' => ['warehouses.index', ['read-warehouses']],
        'contacts' => ['contacts.index', ['read-contacts']],
        'staff' => ['staffs.index', ['read-staffs']],
        'task_manager' => ['task-manager.index', ['read-task-manager', 'read-all-task-manager']],
        'case_tracking' => ['case-tracking.index', ['read-case-tracking', 'read-all-case-tracking']],
        'daily_site_reports' => ['daily-site-reports.index', ['read-daily-site-reports']],
        'digital_attendance' => ['digital-attendance.show', ['read-digital-attendance', 'read-all-digital-attendance']],
        'compliance' => ['compliance-records.index', ['read-compliance-records']],
        'wims_connect' => ['wims-connect.index', ['read-wims-connect']],
        'downloads_prints' => ['download-print-logs.index', ['read-download-print-logs']],
        'auth' => ['login-logs.index', ['read-login-logs']],
        'users_security' => ['users.index', ['read-users']],
        'categories' => ['categories.index', ['read-categories']],
        'units' => ['units.index', ['read-units']],
    ];

    /**
     * One address per timeline event (null where the viewer may not open anything). Parent records of line entries are looked up in one query per
     * kind of line, not one per row.
     *
     * @param Collection<int, array> $events
     * @return array<string, ?string> event id => relative url
     */
    public function forEvents(User $viewer, Collection $events): array
    {
        $parents = [];
        foreach (self::PARENT as $child => [$parent, $fk]) {
            $ids = $events->where('subject_type', $child)->pluck('subject_id')->filter()->unique()->values()->all();
            if (! $ids) {
                continue;
            }
            $table = (new $child())->getTable();
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $fk)) {
                continue;
            }
            foreach (array_chunk($ids, 800) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->pluck($fk, 'id') as $id => $parentId) {
                    $parents[$child][$id] = $parentId;
                }
            }
        }

        $out = [];
        foreach ($events as $e) {
            $out[$e['id']] = $this->one($viewer, $e, $parents);
        }

        return $out;
    }

    private function one(User $viewer, array $e, array $parents): ?string
    {
        $type = $e['subject_type'] ?? null;
        $id = $e['subject_id'] ?? null;
        if ($type && $id) {
            if (isset(self::PARENT[$type]) && ! isset(self::RECORD[$type])) {
                $pid = $parents[$type][$id] ?? null;
                if ($pid && ($u = $this->record($viewer, self::PARENT[$type][0], $pid))) {
                    return $u;
                }
            } elseif ($u = $this->record($viewer, $type, $id)) {
                return $u;
            }
        }

        $module = $this->module($viewer, (string) ($e['module_key'] ?? ''));
        if ($module) {
            return $module;
        }
        // a printed or downloaded document that belongs to no module of its own opens the print and download log
        if (($e['kind'] ?? '') === 'report') {
            return $this->module($viewer, 'downloads_prints');
        }
        // Smart Brief's own entries (its alerts, access and settings changes) open Smart Brief
        if (($e['module'] ?? '') === 'Smart Brief' && Route::has('smart-brief.home') && ($viewer->can('view-own-smart-brief') || $viewer->can('view-smart-brief'))) {
            return route('smart-brief.home', [], false);
        }

        return null;
    }

    private function record(User $viewer, string $class, $id): ?string
    {
        if (! isset(self::RECORD[$class])) {
            return null;
        }
        [$route, $ability] = self::RECORD[$class];
        if (! Route::has($route) || ! $viewer->can($ability)) {
            return null;
        }

        return route($route, $id, false);
    }

    private function module(User $viewer, string $key): ?string
    {
        if (! isset(self::MODULE[$key])) {
            return null;
        }
        [$route, $abilities] = self::MODULE[$key];
        if (! Route::has($route)) {
            return null;
        }
        foreach ($abilities as $a) {
            if ($viewer->can($a)) {
                return route($route, [], false);
            }
        }

        return null;
    }
}
