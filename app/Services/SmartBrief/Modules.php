<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use App\Support\ActivityModules;

/**
 * Which modules' activity a viewer may see inside another person's brief. A brief is built from records of many modules, so it must not
 * show a viewer a record they could not open themselves: each activity carries its module (ActivityModules) and the viewer needs any ONE of
 * that module's read abilities. Chat (WIMSChat) is private and never shown to others; security / administrative modules need the
 * "sensitive" ability. A module this map does not know is treated as sensitive. A person's own brief is never filtered.
 */
class Modules
{
    /** module key => [read abilities (any of)] ; 'sensitive' => needs view-smart-brief-sensitive ; 'private' => never shown to others */
    public const MAP = [
        'archive' => ['read-archive'],
        'site_diary' => ['read-site-diary', 'view-all-site-diaries'],
        'site_meetings' => ['read-site-meetings'],
        'wimschat' => 'private',
        'weather_risk' => ['read-weather'],
        'signatories' => ['view-signatories'],
        'material_requests' => ['read-material-requests', 'view-all-material-requests'],
        'material_reallocation' => ['read-material-reallocations'],
        'issue_return' => ['read-issue-returns'],
        'funds_allocation' => ['read-fund-allocations'],
        'salary' => ['read-salary-periods', 'read-salary-advances', 'read-salary-loans', 'read-salary-vouchers', 'read-salary-opening-balances'],
        'payment_vouchers' => ['read-payment-vouchers'],
        'supplier_records' => ['read-supplier-records', 'read-purchase-requests', 'read-payment-requests', 'read-purchase-orders'],
        'assets' => ['read-assets'],
        'transport' => ['read-transport-records', 'read-transport-dashboard', 'read-transport-vehicles'],
        'transfers' => ['read-transfers'],
        'multi_transfers' => ['read-multi-transfers'],
        'qr_codes' => ['manage-qr-codes'],
        'checkins' => ['read-checkins'],
        'checkouts' => ['read-checkouts'],
        'adjustments' => ['read-adjustments', 'read-super-adjustments'],
        'stock' => ['read-items'],
        'warehouses' => ['read-warehouses'],
        'contacts' => ['read-contacts'],
        'staff' => ['read-staffs'],
        'task_manager' => ['read-task-manager', 'read-all-task-manager'],
        'case_tracking' => ['read-case-tracking', 'read-all-case-tracking'],
        'daily_site_reports' => ['read-daily-site-reports'],
        'digital_attendance' => ['read-digital-attendance', 'read-all-digital-attendance'],
        'compliance' => ['read-compliance-records', 'view-project-certificates', 'read-labour'],
        'delivery_gate' => ['read-delivery-notes', 'read-gate-passes'],
        'wims_connect' => ['read-wims-connect'],
        'downloads_prints' => ['read-download-print-logs'],
        'auth' => 'open', // sign-in / sign-out rows: controlled by the Sessions section, not by a module
        'users_security' => 'sensitive',
        'settings' => 'sensitive',
        'other' => 'sensitive',
    ];

    public static function label(string $key): string
    {
        return $key === 'auth' ? 'Sign-in & sessions' : ActivityModules::label($key);
    }

    /** May $viewer see activity of this module in somebody else's brief? */
    public static function visibleTo(User $viewer, string $moduleKey): bool
    {
        $rule = self::MAP[$moduleKey] ?? 'sensitive';
        if ($rule === 'open') {
            return true;
        }
        if ($rule === 'private') {
            return false;
        }
        if ($rule === 'sensitive') {
            return $viewer->can('view-smart-brief-sensitive');
        }
        foreach ($rule as $ability) {
            if ($viewer->can($ability)) {
                return true;
            }
        }

        return false;
    }

    /** All module keys the viewer may see in other people's briefs. */
    public static function visibleKeys(User $viewer): array
    {
        return array_values(array_filter(array_keys(self::MAP), fn ($k) => self::visibleTo($viewer, $k)));
    }

    /** Model class => [route name, read ability]: where a record in a brief can be opened (only if the route exists and the viewer may read it). */
    public const LINKS = [
        \App\Models\Checkin::class => ['checkins.show', 'read-checkins'],
        \App\Models\Checkout::class => ['checkouts.show', 'read-checkouts'],
        \App\Models\Transfer::class => ['transfers.show', 'read-transfers'],
        \App\Models\MultiTransfer::class => ['multi-transfers.show', 'read-multi-transfers'],
        \App\Models\Adjustment::class => ['adjustments.show', 'read-adjustments'],
        \App\Models\MaterialRequest::class => ['material-requests.show', 'read-material-requests'],
        \App\Models\PaymentVoucher::class => ['payment-vouchers.show', 'read-payment-vouchers'],
        \App\Models\Staff::class => ['staffs.show', 'read-staffs'],
        \App\Models\Contact::class => ['contacts.show', 'read-contacts'],
        \App\Models\Item::class => ['items.show', 'read-items'],
        \App\Models\Warehouse::class => ['warehouses.show', 'read-warehouses'],
    ];

    public static function linkFor(User $viewer, ?string $subjectType, $subjectId): ?string
    {
        if (! $subjectType || ! $subjectId || ! isset(self::LINKS[$subjectType])) {
            return null;
        }
        [$route, $ability] = self::LINKS[$subjectType];
        if (! \Illuminate\Support\Facades\Route::has($route) || ! $viewer->can($ability)) {
            return null;
        }

        return route($route, $subjectId, false);
    }
}
