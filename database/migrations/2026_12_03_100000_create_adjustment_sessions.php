<?php

use App\Models\Role;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adjustment Session (Super Adjustment only): a physical count of one Project / Warehouse that takes several days and several Super Adjustments.
 * While it is open, every Super Adjustment made for that warehouse is added to it and the "items not in this count" prompt is switched off;
 * when it ends, the items that were never counted are listed (and can be cleared through one normal Super Adjustment).
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return ['read-adjustment-sessions', 'start-adjustment-sessions', 'end-adjustment-sessions', 'print-adjustment-sessions'];
    }

    public function up(): void
    {
        if (! Schema::hasTable('adjustment_sessions')) {
            Schema::create('adjustment_sessions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->constrained()->cascadeOnDelete();
                $t->string('reference')->unique();
                $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $t->string('name')->nullable();
                $t->text('note')->nullable();
                $t->string('status', 12)->default('Open')->index();                        // Open | Ended
                // set to the warehouse id while the session is open, null once it ends: the unique index makes "one open session per warehouse" hold even under a race
                $t->unsignedBigInteger('open_warehouse_id')->nullable()->unique();
                $t->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
                $t->timestamp('started_at')->nullable(); // nullable on purpose: MySQL gives the first NOT NULL timestamp column an ON UPDATE CURRENT_TIMESTAMP, which would move the start date at every save
                $t->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('ended_at')->nullable();
                $t->text('end_note')->nullable();
                $t->unsignedBigInteger('clearing_super_adjustment_id')->nullable();         // the Super Adjustment made at the end for the items that were not counted
                $t->json('snapshot')->nullable();                                           // what was counted / not counted / cleared when it ended
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['warehouse_id', 'status']);
            });
        }

        if (Schema::hasTable('super_adjustments') && ! Schema::hasColumn('super_adjustments', 'adjustment_session_id')) {
            Schema::table('super_adjustments', function (Blueprint $t) {
                $t->unsignedBigInteger('adjustment_session_id')->nullable()->index()->after('warehouse_id');
            });
        }

        foreach ($this->abilities() as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $super = Role::where('name', 'Super Admin')->first();
        if ($super) {
            $super->givePermissionTo($this->abilities());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $feature = SystemFeatureRegistry::features()['adjustment_sessions'] ?? null;
        if ($feature) {
            DB::table('system_features')->updateOrInsert(['key' => 'adjustment_sessions'], [
                'name' => $feature['name'], 'description' => $feature['description'], 'module' => $feature['module'], 'icon' => $feature['icon'],
                'is_enabled' => true, 'sort_order' => (int) DB::table('system_features')->max('sort_order') + 1,
                'route_prefixes' => json_encode($feature['route_prefixes']), 'depends_on' => $feature['depends_on'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('super_adjustments', 'adjustment_session_id')) {
            Schema::table('super_adjustments', fn (Blueprint $t) => $t->dropColumn('adjustment_session_id'));
        }
        Schema::dropIfExists('adjustment_sessions');
        DB::table('system_features')->where('key', 'adjustment_sessions')->delete();
        Permission::whereIn('name', $this->abilities())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
