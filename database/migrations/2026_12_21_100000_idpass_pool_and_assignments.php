<?php

use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RANS ID & Pass: Visitor Passes are a stock of numbered passes (001, 002 ...) that are handed to a visitor at check-in and taken back at check-out ("pooled" category),
 * and every hand-over is kept in idpass_assignments (who held the pass, from when to when). Visitor Logs visits remember which pass they were given.
 * Gate Passes belong to contacts of the category "Gate Pass" (the contact shows the pass it holds, since when and the passes it held before).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('idpass_categories', 'pooled')) {
            Schema::table('idpass_categories', fn (Blueprint $t) => $t->boolean('pooled')->default(false)->after('vehicle_required'));
        }
        if (! Schema::hasTable('idpass_assignments')) {
            Schema::create('idpass_assignments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->unsignedBigInteger('card_id')->index();
                $t->unsignedBigInteger('visit_id')->nullable()->index();         // the Visitor Logs visit it was given for
                $t->unsignedBigInteger('visitor_id')->nullable()->index();
                $t->unsignedBigInteger('contact_id')->nullable()->index();
                $t->string('holder_name', 190);                                   // as it was: the history reads the same later
                $t->string('holder_company', 150)->nullable();
                $t->string('holder_phone', 60)->nullable();
                $t->string('host_name', 150)->nullable();
                $t->timestamp('assigned_at')->useCurrent()->index();
                $t->timestamp('released_at')->nullable()->index();
                $t->unsignedBigInteger('assigned_by_user_id')->nullable();
                $t->unsignedBigInteger('released_by_user_id')->nullable();
                $t->string('release_reason', 255)->nullable();
                $t->string('note', 255)->nullable();
            });
        }
        if (Schema::hasTable('visitor_visits') && ! Schema::hasColumn('visitor_visits', 'idpass_card_id')) {
            Schema::table('visitor_visits', fn (Blueprint $t) => $t->unsignedBigInteger('idpass_card_id')->nullable()->index());
        }
        DB::table('idpass_categories')->where('key', 'visitor')->update(['pooled' => true, 'photo_required' => false, 'validity_value' => null, 'validity_unit' => 'years']);
        DB::table('idpass_categories')->where('key', 'gate')->whereNull('contact_categories')->update(['contact_categories' => json_encode(['Gate Pass'])]);

        $n = 'assign-visitor-passes';
        $p = Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($p));
        // the people who register visitors at reception can hand out the passes
        Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'create-visitor-logs'))->get()->each(fn ($r) => $r->givePermissionTo($p));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        unset($n);
    }

    public function down(): void
    {
        Schema::dropIfExists('idpass_assignments');
        if (Schema::hasColumn('visitor_visits', 'idpass_card_id')) {
            Schema::table('visitor_visits', fn (Blueprint $t) => $t->dropColumn('idpass_card_id'));
        }
        if (Schema::hasColumn('idpass_categories', 'pooled')) {
            Schema::table('idpass_categories', fn (Blueprint $t) => $t->dropColumn('pooled'));
        }
    }
};
