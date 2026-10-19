<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Signatories, group F2: from now on WIMS records WHO did each step (and when) on the four supplier / agreement documents that were printed
 * without a document of their own. Nullable columns only, nothing is backfilled: a record handled before this change simply has no stamp for
 * the step and the report shows no signature for it (the Signatories adapters never guess an actor).
 *
 *   purchase_orders      submitted_by_user_id, sent_by_user_id, acknowledged_by_user_id, cancelled_by_user_id, rejected_by_user_id + rejected_on
 *                        (approved_by_user_id / approved_on and submitted_on / sent_on / acknowledged_on / cancelled_on already existed)
 *   supplier_invoices    approved_by_user_id + approved_on, disputed_by_user_id + disputed_on
 *   workforce_agreements cancelled_by_user_id + cancelled_at
 *   goods_receipts       nothing to add: a receipt has one step (received_by_user_id + created_at)
 *
 * Also creates the print abilities print-purchase-orders / print-goods-receipts / print-supplier-invoices / print-workforce-agreements and
 * grants each to every role that already holds the matching read ability (Super Admin passes every check anyway). Safe to run twice.
 */
return new class extends Migration
{
    private const PRINT_ABILITIES = [
        'print-purchase-orders' => 'read-purchase-orders',
        'print-goods-receipts' => 'read-goods-receipts',
        'print-supplier-invoices' => 'read-supplier-invoices',
        'print-workforce-agreements' => 'read-workforce-agreements',
    ];

    public function up(): void
    {
        $this->addColumns('purchase_orders', [
            'submitted_by_user_id' => 'id', 'sent_by_user_id' => 'id', 'acknowledged_by_user_id' => 'id',
            'cancelled_by_user_id' => 'id', 'rejected_by_user_id' => 'id', 'rejected_on' => 'time',
        ]);
        $this->addColumns('supplier_invoices', [
            'approved_by_user_id' => 'id', 'approved_on' => 'time', 'disputed_by_user_id' => 'id', 'disputed_on' => 'time',
        ]);
        $this->addColumns('workforce_agreements', ['cancelled_by_user_id' => 'id', 'cancelled_at' => 'time']);

        foreach (array_keys(self::PRINT_ABILITIES) as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Role::query()->with('permissions')->get() as $role) {
            foreach (self::PRINT_ABILITIES as $print => $read) {
                if ($role->permissions->contains('name', $read) && ! $role->permissions->contains('name', $print)) {
                    $role->givePermissionTo($print);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::PRINT_ABILITIES))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // The stamped columns are history that may already be in use: they are left in place on purpose.
    }

    /** @param array<string, 'id'|'time'> $columns */
    private function addColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ($columns as $column => $kind) {
            if (Schema::hasColumn($table, $column)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($column, $kind) {
                $kind === 'id' ? $t->unsignedBigInteger($column)->nullable() : $t->timestamp($column)->nullable();
            });
        }
    }
};
