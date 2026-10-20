<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Groups the raw activity-log "log_name" values (model class names and free-text names, written differently by
 * different modules: "PaymentVoucher" / "Payment Voucher" / "PaymentVoucherItem") into the WIMS modules people know,
 * for the Module filter on System Logs. Names that match nothing fall under "Other".
 */
class ActivityModules
{
    /** key => [label, regex tested against the log name with spaces/punctuation removed, lower-cased] — first match wins. */
    private const RULES = [
        'archive' => ['Archive', '/^archive/'],
        'site_diary' => ['Site Diary', '/^sitediary/'],
        'site_meetings' => ['Site Meetings', '/^sitemeeting/'],
        'wimschat' => ['WIMSChat', '/^wimschat/'],
        'weather_risk' => ['Weather & Site Risk', '/^weather/'],
        'signatories' => ['Signatories', '/^signator/'],
        'material_requests' => ['Material Requests', '/^materialrequest/'],
        'material_reallocation' => ['Material Reallocation', '/^materialreallocation/'],
        'issue_return' => ['Issue & Return', '/^(issuetransaction|issuereturn)/'],
        'funds_allocation' => ['Funds Allocation', '/^(fundallocation|fundaccount|fundcategory|fundpayment|fundsallocation)/'],
        'salary' => ['Salary Management', '/^salary/'],
        'payment_vouchers' => ['Payment Vouchers', '/^paymentvoucher/'],
        'supplier_records' => ['Supplier Records', '/^(supplier|quotation|purchase|paymentrequest|goodsreceipt)/'],
        'assets' => ['Assets', '/^asset/'],
        'transport' => ['Transport Routes', '/^transport/'],
        'qr_codes' => ['QR Codes', '/^qr/'],
        'multi_transfers' => ['Multi Transfers', '/^multitransfer/'],
        'transfers' => ['Transfers', '/^transfer/'],
        'checkins' => ['Checkins', '/^(checkin|checkin)/'],
        'checkouts' => ['Checkouts', '/^checkout/'],
        'adjustments' => ['Adjustments', '/^(adjustment|superadjustment)/'],
        'stock' => ['Stock & Items', '/^(stock|item|category|unit|barcode)/'],
        'warehouses' => ['Projects / Warehouses', '/^warehouse/'],
        'contacts' => ['Contacts', '/^contact/'],
        'staff' => ['RANS Staffs', '/^staff/'],
        'task_manager' => ['Task Manager', '/^(task|workpackage|workactivity)/'],
        'case_tracking' => ['Case Tracking', '/^case/'],
        'daily_site_reports' => ['Daily Site Reports', '/^dailysitereport/'],
        'digital_attendance' => ['Digital Attendance', '/^digitalattendance/'],
        'travels' => ['Travels', '/^(travel)/'],
        'weekly_administration' => ['Administration (Weekly)', '/^(administration|admindocument|adminitem|admincharity)/'],
        'compliance' => ['Compliance', '/^(boqmaterialcompliance|compliance|projectcertificate|labour)/'],
        'delivery_gate' => ['Delivery Notes & Gate Passes', '/^(deliverynote|gatepass)/'],
        'wims_connect' => ['WIMS Connect', '/^wimsconnect/'],
        'downloads_prints' => ['Downloads & Prints', '/^downloadprint/'],
        'users_security' => ['Users & Security', '/^(user|role|loginlog|impersonation|security|profile|twofactor|otp)/'],
        'settings' => ['Settings & System', '/^(setting|systemfeature|backup|terms|notice|account)/'],
    ];

    public const OTHER = 'other';

    /** The display name of a module key ("Other" for the catch-all). */
    public static function label(string $key): string
    {
        return $key === self::OTHER || ! isset(self::RULES[$key]) ? 'Other' : self::RULES[$key][0];
    }

    public static function keyFor(?string $logName): string
    {
        $normal = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $logName));

        foreach (self::RULES as $key => [, $regex]) {
            if (preg_match($regex, $normal)) {
                return $key;
            }
        }

        return self::OTHER;
    }

    /** Every distinct log name in the log, remembered for a few minutes (the table is large; the list of names is not). */
    private static function logNames(): array
    {
        return Cache::remember('activity_modules.log_names', 600, fn () => DB::table('activity_log')->whereNotNull('log_name')->distinct()->pluck('log_name')->all());
    }

    /** [{ value, label }] for the filter dropdown — only modules that actually have log entries, alphabetical. */
    public static function options(): array
    {
        $present = collect(self::logNames())->map(fn ($n) => self::keyFor($n))->unique();

        return $present
            ->map(fn ($key) => ['value' => $key, 'label' => $key === self::OTHER ? 'Other' : self::RULES[$key][0]])
            ->sortBy(fn ($o) => $o['value'] === self::OTHER ? 'zzz' : strtolower($o['label']))
            ->values()
            ->all();
    }

    /** The raw log names that belong to a module key (empty for an unknown key). */
    public static function namesFor(?string $key): array
    {
        if (! $key || ($key !== self::OTHER && ! isset(self::RULES[$key]))) {
            return [];
        }

        return collect(self::logNames())->filter(fn ($n) => self::keyFor($n) === $key)->values()->all();
    }
}
