<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Site Meetings: who prepared a meeting (the logged-in person; only a Super Admin can name someone else), and two new
 * abilities — see the whole schedule of every site (Engineers, Admin, Project Manager get it) and a read-only switch that
 * limits a person to viewing / printing whatever else they were granted. Additive; safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('site_meetings', 'prepared_by')) {
            Schema::table('site_meetings', function (Blueprint $table) {
                $table->unsignedBigInteger('prepared_by')->nullable()->after('recorder_id');
            });
            \Illuminate\Support\Facades\DB::table('site_meetings')->whereNull('prepared_by')->update(['prepared_by' => \Illuminate\Support\Facades\DB::raw('created_by')]);
        }

        foreach (['view-all-site-meetings', 'view-only-site-meetings'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['Super Admin', 'Admin', 'Project Manager', 'Engineers'] as $role) {
            Role::query()->where('name', $role)->get()->each(fn ($r) => $r->givePermissionTo('view-all-site-meetings'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // columns and permissions are left in place
    }
};
