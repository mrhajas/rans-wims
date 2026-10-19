<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The signing roles as WIMS words them on its own documents: Requested By, Submitted By, Cleared By, Issued By, Dispatched By, Paid By,
 * Recorded By, Chaired By and so on, next to the general ones. Adds what is missing for every account (never touches a role that exists,
 * so renamed or switched-off roles stay as they are), and re-spells "Authorized By" as "Authorised By" (the WIMS spelling) only where the
 * label was never changed. Safe to run twice.
 */
return new class extends Migration
{
    private const ROLES = [
        'requested_by' => ['Requested By', 15, 'The person who asked for it (material request, adjustment, reallocation)'],
        'submitted_by' => ['Submitted By', 18, 'The person who sent it on for review or approval'],
        'recorded_by' => ['Recorded By', 25, 'The person who recorded it (trips, payments, site records)'],
        'cleared_by' => ['Cleared By', 35, 'The person who cleared it in the workflow (Project Manager clearance and similar)'],
        'issued_by' => ['Issued By', 85, 'The person who issued the document or the materials'],
        'dispatched_by' => ['Dispatched By', 87, 'The person who sent the goods out'],
        'paid_by' => ['Paid By', 95, 'The person who made the payment'],
        'acknowledged_by' => ['Acknowledged By', 105, 'The person who acknowledged it'],
        'chaired_by' => ['Chaired By', 112, 'The person who chaired the meeting'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('signing_roles')) {
            return;
        }
        foreach (DB::table('accounts')->pluck('id') as $account) {
            foreach (self::ROLES as $key => [$label, $order, $desc]) {
                if (! DB::table('signing_roles')->where('account_id', $account)->where('key', $key)->exists()) {
                    DB::table('signing_roles')->insert(['account_id' => $account, 'key' => $key, 'label' => $label, 'description' => $desc, 'sort_order' => $order, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('signing_roles')->where('account_id', $account)->where('key', 'authorized_by')->where('label', 'Authorized By')->update(['label' => 'Authorised By', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
