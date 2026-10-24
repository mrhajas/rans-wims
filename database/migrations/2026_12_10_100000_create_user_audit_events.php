<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The user audit history: the major things that happen to a user account (created, deleted, password changed, details changed, roles and
 * warehouses changed, disabled / enabled, two-step sign-in changed, face reset, linked to a staff profile, "login as"), who did them and from where.
 * Nothing about the modules. Passwords are never stored, only that they changed. The permission "audit-users" opens it; the Super Admin has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_audit_events')) {
            Schema::create('user_audit_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();          // the account it happened to (null for a role-wide change)
                $t->unsignedBigInteger('actor_id')->nullable();         // who did it (null: nobody signed in, e.g. a password reset link)
                $t->string('kind', 40);
                $t->string('user_name', 190)->nullable();               // kept as they were that day
                $t->string('actor_name', 190)->nullable();
                $t->json('details')->nullable();
                $t->string('ip', 64)->nullable();
                $t->string('device', 190)->nullable();
                $t->timestamp('created_at')->useCurrent()->index();
                $t->index(['user_id', 'created_at'], 'user_audit_user_idx');
                $t->index(['kind', 'created_at'], 'user_audit_kind_idx');
            });
        }

        // what the general activity log already knows about accounts created and deleted
        if (Schema::hasTable('activity_log') && ! DB::table('user_audit_events')->exists()) {
            $map = ['User has been created.' => 'user_created', 'User has been deleted.' => 'user_deleted'];
            $rows = DB::table('activity_log')->where('subject_type', 'App\\Models\\User')->whereIn('description', array_keys($map))->orderBy('id')->get();
            $names = DB::table('users')->pluck('name', 'id');
            foreach ($rows->chunk(200) as $chunk) {
                $insert = [];
                foreach ($chunk as $r) {
                    $ctx = json_decode((string) $r->properties, true)['context'] ?? [];
                    $insert[] = [
                        'account_id' => $r->account_id, 'user_id' => $r->subject_id, 'actor_id' => $r->causer_id, 'kind' => $map[$r->description],
                        'user_name' => $names[$r->subject_id] ?? null, 'actor_name' => $names[$r->causer_id] ?? null, 'details' => json_encode(['from_old_log' => true]),
                        'ip' => $ctx['ip'] ?? null, 'device' => $ctx['device'] ?? null, 'created_at' => $r->created_at,
                    ];
                }
                DB::table('user_audit_events')->insert($insert);
            }
        }

        if (Schema::hasTable('permissions')) {
            $p = Permission::query()->firstOrCreate(['name' => 'audit-users', 'guard_name' => 'web']);
            Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($p));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_audit_events');
        Permission::query()->where('name', 'audit-users')->delete();
    }
};
