<?php

use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WIMS Signatories:
 *  - signatories           one row per user who is registered as a signatory (linked to the existing user and staff records, never a copy)
 *  - signature_versions    every signature picture a signatory has had: processed PNG, who verified it, when it was active
 *  - signing_roles         Prepared by, Approved by ... (editable list)
 *  - signing_mappings      which document carries which signing role, which workflow action qualifies, when it shows, what happens when missing
 *  - signature_snapshots   the signature set frozen when a document becomes final, so later changes never rewrite it
 *  - signature_missing     every time a report needed a signature that was not available (for the dashboard)
 * plus the abilities (nobody holds them yet except Super Admin; every role may register and see its OWN signature) and the Feature Control rows.
 * Images live on the private "local" disk under signatures/, never in a public folder. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signatories')) {
            Schema::create('signatories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('staff_id')->nullable()->index();
                $table->string('status', 24)->default('pending_verification'); // pending_verification | active | suspended | revoked
                $table->unsignedBigInteger('active_version_id')->nullable();
                $table->unsignedBigInteger('registered_by')->nullable();
                $table->dateTime('registered_at')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->dateTime('verified_at')->nullable();
                $table->dateTime('suspended_at')->nullable();
                $table->unsignedBigInteger('suspended_by')->nullable();
                $table->string('suspend_reason', 255)->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->string('revoke_reason', 255)->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'user_id'], 'sig_user_unique');
            });
        }
        if (! Schema::hasTable('signature_versions')) {
            Schema::create('signature_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('signatory_id')->index();
                $table->unsignedInteger('version_no');
                $table->string('status', 24)->default('pending_verification'); // pending_verification | active | replaced | rejected | suspended | revoked
                $table->string('method', 12); // upload | camera | draw
                $table->string('processed_path', 255);
                $table->string('thumb_path', 255)->nullable();
                $table->string('original_path', 255)->nullable();
                $table->dateTime('original_purged_at')->nullable();
                $table->unsignedSmallInteger('width');
                $table->unsignedSmallInteger('height');
                $table->string('sha256', 64);
                $table->string('ink_color', 9);
                $table->json('metrics')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->dateTime('uploaded_at');
                $table->unsignedBigInteger('consent_by')->nullable(); // the signatory confirmed it is theirs and may be used
                $table->dateTime('consent_at')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->dateTime('verified_at')->nullable();
                $table->unsignedBigInteger('activated_by')->nullable();
                $table->dateTime('activated_at')->nullable();
                $table->dateTime('ended_at')->nullable(); // when it stopped being the signature in use (replaced, suspended, revoked)
                $table->string('ended_reason', 255)->nullable();
                $table->unsignedBigInteger('ended_by')->nullable();
                $table->timestamps();
                $table->unique(['signatory_id', 'version_no'], 'sig_version_unique');
            });
        }
        if (! Schema::hasTable('signing_roles')) {
            Schema::create('signing_roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('key', 40);
                $table->string('label', 60);
                $table->string('description', 255)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['account_id', 'key'], 'signing_role_unique');
            });
        }
        if (! Schema::hasTable('signing_mappings')) {
            Schema::create('signing_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('document_type', 60);          // key of a supported document (see config/signatories.php)
                $table->unsignedBigInteger('signing_role_id');
                $table->string('workflow_action', 60);        // which recorded action qualifies the signer (the document adapter knows them)
                $table->unsignedSmallInteger('slot')->default(1); // order on the page
                $table->boolean('is_required')->default(false);
                $table->string('show_when', 12)->default('after'); // always | after (once the action happened) | final (only on the finalised document)
                $table->boolean('blocks_final')->default(false);   // a missing signature stops the record being marked final
                $table->string('missing_behaviour', 12)->default('blank'); // blank (keep the blank line) | flag (blank line + dashboard) | block
                $table->string('permission', 80)->nullable();  // extra ability required to see the signature on the report
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['account_id', 'document_type', 'signing_role_id', 'workflow_action'], 'signing_map_unique');
            });
        }
        if (! Schema::hasTable('signature_snapshots')) {
            Schema::create('signature_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('document_type', 60);
                $table->unsignedBigInteger('record_id');
                $table->string('signing_role_key', 40);
                $table->string('workflow_action', 60);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('signature_version_id')->nullable();
                $table->string('name', 160)->nullable();
                $table->string('designation', 160)->nullable();
                $table->dateTime('action_at')->nullable();
                $table->string('action_label', 80)->nullable();
                $table->string('limitation', 255)->nullable(); // e.g. "No signature was on file when this action was taken"
                $table->dateTime('frozen_at');
                $table->timestamps();
                $table->unique(['document_type', 'record_id', 'signing_role_key', 'workflow_action'], 'sig_snapshot_unique');
            });
        }
        if (! Schema::hasTable('signature_missing')) {
            Schema::create('signature_missing', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('document_type', 60)->index();
                $table->unsignedBigInteger('record_id');
                $table->string('signing_role_key', 40);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('reason', 40); // no_action | no_actor | no_timestamp | not_registered | not_active | no_version_at_time | not_permitted
                $table->string('detail', 255)->nullable();
                $table->boolean('blocked')->default(false);
                $table->unsignedBigInteger('raised_by')->nullable();
                $table->dateTime('seen_at');
                $table->timestamps();
                $table->unique(['document_type', 'record_id', 'signing_role_key', 'reason'], 'sig_missing_unique');
            });
        }

        foreach (array_keys(PermissionRegistry::modules()['signatories']['abilities'] ?? []) as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        // every role may see and register its own signature (it stays pending until a verifier activates it)
        foreach (['view-own-signature', 'register-own-signature', 'upload-signatures', 'capture-signatures', 'preview-signatures'] as $name) {
            if ($p = Permission::query()->where('name', $name)->where('guard_name', 'web')->first()) {
                \App\Models\Role::query()->get()->each(fn ($role) => $role->givePermissionTo($p));
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            $order = (int) DB::table('system_features')->max('sort_order');
            foreach (SystemFeatureRegistry::features() as $key => $f) {
                if (! str_starts_with($key, 'signatories')) {
                    continue;
                }
                if (DB::table('system_features')->where('key', $key)->exists()) {
                    DB::table('system_features')->where('key', $key)->update(['route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now()]);
                    continue;
                }
                DB::table('system_features')->insert([
                    'key' => $key, 'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'is_enabled' => 1, 'depends_on' => $f['depends_on'] ?? null, 'route_prefixes' => json_encode($f['route_prefixes']),
                    'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            FeatureManager::flushCache();
        }

        // the starting signing roles for every account; they are editable on the Signing Roles page
        $roles = [
            'prepared_by' => ['Prepared By', 10], 'entered_by' => ['Entered By', 20], 'checked_by' => ['Checked By', 30], 'reviewed_by' => ['Reviewed By', 40],
            'recommended_by' => ['Recommended By', 50], 'verified_by' => ['Verified By', 60], 'approved_by' => ['Approved By', 70], 'authorized_by' => ['Authorized By', 80],
            'certified_by' => ['Certified By', 90], 'received_by' => ['Received By', 100], 'witnessed_by' => ['Witnessed By', 110],
        ];
        foreach (DB::table('accounts')->pluck('id') as $account) {
            foreach ($roles as $key => [$label, $order]) {
                if (! DB::table('signing_roles')->where('account_id', $account)->where('key', $key)->exists()) {
                    DB::table('signing_roles')->insert(['account_id' => $account, 'key' => $key, 'label' => $label, 'sort_order' => $order, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // signatures are sensitive records: nothing is dropped automatically
    }
};
