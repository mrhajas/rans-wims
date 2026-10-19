<?php

use App\Models\Role;
use App\Services\Signatories\MappingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Supplier Records: record WHO did each workflow step and WHEN, from now on, and let the three request documents be printed.
 *
 *  1. purchase_requests / payment_requests got only a status and approval remarks: no submitter, no approver, no time. Adds the nullable stamps
 *     submitted_by_user_id + submitted_at, approved_by_user_id + approved_at, rejected_by_user_id + rejected_at.
 *  2. quotations (Draft, Sent, Received, Selected, Rejected) get the stamps that match their own steps: sent_*, received_*, selected_*, rejected_*.
 *  3. The print abilities print-purchase-requests, print-payment-requests, print-quotations, given to every role that can already read the document.
 *  4. The starting Signatories setup of the three documents (Requested/Recorded By and Approved By lines, nothing required).
 *
 * Nothing is back-filled: a request that was approved before this change has no stamps, so no approval is ever shown for it (the Signatories
 * module leaves that line blank). Safe to run twice.
 */
return new class extends Migration
{
    private const STAMPS = [
        'purchase_requests' => ['submitted', 'approved', 'rejected'],
        'payment_requests' => ['submitted', 'approved', 'rejected'],
        'quotations' => ['sent', 'received', 'selected', 'rejected'],
    ];

    /** print ability => the read ability whose holders get it */
    private const PRINT = [
        'print-purchase-requests' => 'read-purchase-requests',
        'print-payment-requests' => 'read-payment-requests',
        'print-quotations' => 'read-quotations',
    ];

    public function up(): void
    {
        foreach (self::STAMPS as $tableName => $steps) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($steps as $step) {
                $by = $step . '_by_user_id';
                $at = $step . '_at';
                if (! Schema::hasColumn($tableName, $by)) {
                    Schema::table($tableName, fn (Blueprint $t) => $t->unsignedBigInteger($by)->nullable());
                }
                if (! Schema::hasColumn($tableName, $at)) {
                    Schema::table($tableName, fn (Blueprint $t) => $t->dateTime($at)->nullable());
                }
            }
        }

        foreach (array_keys(self::PRINT) as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Role::query()->get() as $role) {
            foreach (self::PRINT as $print => $read) {
                if ($role->permissions->contains('name', $read)) {
                    $role->givePermissionTo($print);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // the starting signing setup of the three documents (nothing required, nothing blocking; never overwrites a setup an administrator already made)
        if (Schema::hasTable('signing_mappings') && Schema::hasTable('signing_roles')) {
            foreach (['purchase_request', 'payment_request', 'quotation'] as $type) {
                app(MappingSeeder::class)->seedAll($type);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::PRINT))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::STAMPS as $tableName => $steps) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($steps as $step) {
                foreach ([$step . '_by_user_id', $step . '_at'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        Schema::table($tableName, fn (Blueprint $t) => $t->dropColumn($column));
                    }
                }
            }
        }
    }
};
