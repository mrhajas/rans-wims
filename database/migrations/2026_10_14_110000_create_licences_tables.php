<?php

use App\Services\Licensing\LicenceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WIMS Licence: the licences issued by the Licence Manager and the history of everything that happened to them.
 * Also creates the "WIMS Licence" permissions (Super Admin only until a Super Admin grants them to a role) and the
 * first licence, active for two years from 10 Oct 2026.
 */
return new class extends Migration
{
    private const ABILITIES = ['read-wims-licence', 'update-wims-licence'];

    public function up(): void
    {
        Schema::create('licences', function (Blueprint $table) {
            $table->id();
            $table->string('licence_number', 40)->unique();
            $table->char('key', 128)->unique();
            $table->string('licensee', 190);
            $table->string('edition', 60)->default('Enterprise');
            $table->string('licence_type', 40)->default('Subscription');
            $table->date('starts_at');
            $table->date('expires_at');                       // last day the licence is valid (inclusive)
            $table->unsignedInteger('max_users')->nullable();  // informational
            $table->string('status', 20)->default('Issued');   // Issued | Active | Deactivated | Superseded
            $table->json('meta')->nullable();                  // support level, modules covered...
            $table->text('notes')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('deactivated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('licence_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licence_id')->constrained('licences')->cascadeOnDelete();
            $table->string('event', 40);            // issued | activated | deactivated | reactivated | superseded | file-downloaded | printed
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['licence_id', 'created_at']);
        });

        foreach (self::ABILITIES as $ability) {
            Permission::query()->firstOrCreate(['name' => $ability, 'guard_name' => 'web']);
        }
        $role = DB::table('roles')->where('name', 'Super Admin')->first();
        if ($role) {
            \App\Models\Role::where('name', 'Super Admin')->first()?->givePermissionTo(self::ABILITIES);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The first licence: active for two years from 10 October 2026.
        $issuer = DB::table('users')->where('name', 'DrHajas')->orWhere('email', 'extriontech@gmail.com')->orderBy('id')->value('id');
        $licensee = DB::table('settings')->where('tec_key', 'name')->value('tec_value') ?: 'RANS COMPANY LIMITED';
        LicenceService::seedInitial($licensee, '2026-10-10', '2028-10-10', $issuer ? (int) $issuer : null);
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_events');
        Schema::dropIfExists('licences');
    }
};
