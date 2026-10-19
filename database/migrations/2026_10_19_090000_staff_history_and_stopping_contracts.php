<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Why and when a contract was stopped (somebody left, or management ended it)
        Schema::table('staff_contracts', function (Blueprint $table) {
            $table->string('stopped_reason_type', 40)->nullable()->after('status');
            $table->text('stopped_reason')->nullable()->after('stopped_reason_type');
            $table->date('stopped_on')->nullable()->after('stopped_reason');
            $table->unsignedBigInteger('stopped_by_user_id')->nullable()->after('stopped_on');
        });

        // Everything that happened to a staff member's employment, kept for good (contracts added, renewed, stopped; retirement; status changes)
        Schema::create('staff_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->string('event', 30);
            $table->string('title', 190);
            $table->string('reason_type', 40)->nullable();
            $table->text('reason')->nullable();
            $table->date('occurred_on');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->json('details')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'occurred_on']);
        });

        // "Stop contract" is its own ability; roles that could edit contracts keep being able to stop them
        $stop = Permission::query()->firstOrCreate(['name' => 'stop-staff-contracts', 'guard_name' => 'web']);
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($stop));
        $edit = Permission::query()->where('name', 'update-staff-contracts')->where('guard_name', 'web')->first();
        if ($edit) {
            Role::query()->whereHas('permissions', fn ($q) => $q->where('permissions.id', $edit->id))->get()->each(fn ($r) => $r->givePermissionTo($stop));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_history');
        Schema::table('staff_contracts', function (Blueprint $table) {
            $table->dropColumn(['stopped_reason_type', 'stopped_reason', 'stopped_on', 'stopped_by_user_id']);
        });
    }
};
