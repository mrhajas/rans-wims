<?php

namespace App\Services\Administration;

use Illuminate\Support\Facades\DB;

/**
 * The starting template of the weekly Administration document: the standing lines of the company's own weekly sheet (description, usual beneficiary, usual amount).
 * It is put in only when there are no items yet; afterwards the template is changed on the Template and Items page.
 */
class AdminTemplateSeeder
{
    /** description, usual beneficiary, usual amount */
    public const LINES = [
        ['HASSANAT&ZAINAB OFFICE CLEANING-WEEKLY ALLOWANCE', 'HASSANAT & ZAINAB', 180000],
        ['MTUMWA-TRANSPORT ALLOWANCE', 'MTUMWA', 10000],
        ['MR.NASSOR-WEEKLY FUEL', 'MR. NASSOR', 100000],
        ['ALI NASSOR-WEEKLY FUEL', 'ALI NASSOR', 150000],
        ['ASHA NASSOR-WEEKLY FUEL', 'ASHA NASSOR', 100000],
        ['SALIM NASSOR-WEEKLY FUEL', 'SALIM NASSOR', 120000],
        ['ABDULMAJID-WEEKLY FUEL', 'ABDULMAJID', 120000],
        ['ALI KARA-WEEKLY FUEL', 'ALI KARA', 120000],
        ['HAKEEM NASSIR-WEEKLY FUEL', 'HAKEEM NASSIR', 120000],
        ['ABDALLAH SALUM-WEEKLY FUEL ALLOWANCE', 'ABDALLAH SALUM', 100000],
        ['SALIM HAROUB-WEEKLY FUEL', 'SALIM HAROUB', 100000],
        ['KHALIFA-WEEKLY FUEL', 'KHALIFA', 200000],
        ['BARAKAT-WEEKLY CAR FUEL', 'BARAKAT', 150000],
        ['BARAKAT-WEEKLY VESPA FUEL', 'BARAKAT', 40000],
        ['BARAKATI-NEWS PAPER', 'BARAKATI', 30000],
        ['BARAKAT-REPAIR&MAINTANCE FOR SPADE (Z 603 AAG)', 'BARAKAT', 350000],
        ['RAMADHAN-WEEKLY CAR FUEL', 'RAMADHAN', 150000],
        ['RAMADHAN-WEEKLY VESPA FUEL', 'RAMADHAN', 40000],
        ['SALHA-TELEPHONE CREDIT', 'SALHA', 219000],
        ['REHEMA AHMADA-MASJID CLEANING (KOMBENI)', 'REHEMA AHMADA', 30000],
        ['CAR WASH', null, 40000],
        ['KITCHEN MATERIALS (GROCERIES AND CLEANING TOOLS)', null, 200000],
        ['OFFICE DRINKING WATER', null, 90000],
        ['OFFICE STATIONARY', null, 300000],
        ['OFFICE STAFF MEAL@6 DAYS', null, 700000],
        ['MISCELLANEOUS CHARITY', null, 200000],
    ];

    /** The sample Charity list (used only by the samples command). name, monthly amount */
    public const CHARITY = [
        ['SHEIKH JAMAL MURTAZA', 300000], ['SHEIKH ALI ISSA', 200000], ['MASJID ISTIQAMA K/SAMAKI', 200000], ['MASJID KOMBENI', 50000], ['MASJID KILIMNI', 70000], ['HAJI MTUMBATU', 50000],
    ];

    /** Puts the template in when the account has no items yet. Returns how many lines were added. */
    public static function run(?int $accountId = null): int
    {
        $accountId = $accountId ?: (int) (DB::table('accounts')->orderBy('id')->value('id') ?: 1);
        if (DB::table('admin_items')->where('account_id', $accountId)->exists()) {
            return 0;
        }
        $n = 0;
        foreach (self::LINES as [$name, $who, $amount]) {
            DB::table('admin_items')->insert(['account_id' => $accountId, 'name' => $name, 'name_key' => mb_strtolower(trim(preg_replace('/\s+/', ' ', $name))), 'default_beneficiary' => $who, 'default_amount' => $amount,
                'in_template' => 1, 'is_active' => 1, 'sort_order' => ++$n, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $n;
    }
}
