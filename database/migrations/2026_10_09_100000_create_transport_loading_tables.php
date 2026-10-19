<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Transport Routes > Loading & Unloading: a daily attendance of the loading / unloading workers (Contacts in the
 * "Loading & UnLoading" category). Each day worked earns the daily rate that applied that day; payments are kept
 * beside it, and the balance is simply earned minus paid.
 */
return new class extends Migration
{
    private const ABILITIES = [
        'read-transport-loading',
        'create-transport-loading',
        'update-transport-loading',
        'delete-transport-loading',
        'pay-transport-loading',
    ];

    public function up(): void
    {
        Schema::create('transport_loading_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 12)->default('Present'); // Present | Absent
            $table->decimal('daily_rate', 15, 2)->default(0);  // the rate that applied that day (kept, so a later rate change never rewrites history)
            $table->decimal('amount', 15, 2)->default(0);      // daily_rate when Present, 0 when Absent
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'work_date']);
            $table->index('work_date');
        });

        Schema::create('transport_loading_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'payment_date']);
        });

        foreach (self::ABILITIES as $ability) {
            Permission::query()->firstOrCreate(['name' => $ability, 'guard_name' => 'web']);
        }
        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo(self::ABILITIES);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The new pages belong to the Transport Routes feature switch in Global Feature Control.
        $row = DB::table('system_features')->where('key', 'transport_management')->first();
        if ($row) {
            $prefixes = json_decode($row->route_prefixes ?: '[]', true) ?: [];
            if (! in_array('transport-loading.', $prefixes, true)) {
                $prefixes[] = 'transport-loading.';
                DB::table('system_features')->where('key', 'transport_management')->update(['route_prefixes' => json_encode($prefixes)]);
            }
        }
        if (class_exists(\App\Services\FeatureManager::class)) {
            \App\Services\FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_loading_payments');
        Schema::dropIfExists('transport_loading_attendances');
    }
};
