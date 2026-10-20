<?php

use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Administration (the weekly administration expenses document): one document per week with numbered lines (description, beneficiary, amount) and an optional
 * Charity section, in three stages (Requested, Approved, Authorized). The lines come from a template of standing items (admin_items) or are typed in; the charity
 * section is pulled from the people kept in the Charity sub-module (admin_charity_people) and can be edited per document. Every typed line is remembered as an item
 * so reports can follow one item month by month.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_items')) {
            // the standing list of items: those marked "in the template" start every new weekly document; every other typed line is kept here too so it can be reported on
            Schema::create('admin_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('name', 190);
                $t->string('name_key', 190)->index();                                // lower-case, single spaces: the same item typed twice is one item
                $t->string('default_beneficiary', 190)->nullable();
                $t->unsignedBigInteger('default_beneficiary_user_id')->nullable();
                $t->decimal('default_amount', 15, 2)->default(0);
                $t->boolean('in_template')->default(false)->index();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('admin_charity_people')) {
            Schema::create('admin_charity_people', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('name', 190);                                              // for example "SHEIKH JAMAL MURTAZA" or "MASJID KOMBENI"
                $t->string('phone', 60)->nullable();
                $t->text('notes')->nullable();
                $t->decimal('monthly_amount', 15, 2)->default(0);
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('admin_documents')) {
            Schema::create('admin_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('hash', 64)->nullable();
                $t->string('status', 16)->default('Requested')->index();            // Requested | Approved | Authorized | Rejected | Cancelled
                $t->date('doc_date')->index();                                        // the date of the document: today when it is made, moved on when it is duplicated
                $t->string('department', 120)->default('ADMINISTRATION');
                $t->string('currency', 3)->default('TZS');
                $t->unsignedBigInteger('beneficiary_user_id')->nullable()->index();  // "Requested By": the person making the document, or whoever they pick
                $t->string('beneficiary_name', 190)->nullable();                     // the name as it was when the document was made
                $t->boolean('include_charity')->default(false);
                $t->decimal('items_total', 15, 2)->default(0);
                $t->decimal('charity_total', 15, 2)->default(0);
                $t->decimal('total_amount', 15, 2)->default(0);                       // the lines, plus the charity section when it is included
                $t->text('notes')->nullable();
                $t->string('source', 16)->default('template');                       // template | blank | duplicate
                $t->unsignedBigInteger('duplicated_from_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();              // prepared by
                $t->unsignedBigInteger('approved_by_user_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('approval_remarks')->nullable();
                $t->unsignedBigInteger('authorized_by_user_id')->nullable();
                $t->timestamp('authorized_at')->nullable();
                $t->text('authorization_remarks')->nullable();
                $t->unsignedBigInteger('rejected_by_user_id')->nullable();
                $t->timestamp('rejected_at')->nullable();
                $t->text('rejection_reason')->nullable();
                $t->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->text('cancel_reason')->nullable();
                $t->unsignedInteger('sent_count')->default(0);
                $t->timestamp('last_sent_at')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['status', 'doc_date'], 'admin_doc_status_date_idx');
            });
        }
        if (! Schema::hasTable('admin_document_items')) {
            Schema::create('admin_document_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('admin_document_id')->index();
                $t->unsignedBigInteger('admin_item_id')->nullable()->index();
                $t->string('description', 255);
                $t->string('beneficiary', 190)->nullable();                           // who the line is for (a column of the document)
                $t->unsignedBigInteger('beneficiary_user_id')->nullable();
                $t->decimal('amount', 15, 2)->default(0);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('admin_document_charities')) {
            Schema::create('admin_document_charities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('admin_document_id')->index();
                $t->unsignedBigInteger('admin_charity_person_id')->nullable()->index();
                $t->string('name', 190);                                              // the name of the person or place
                $t->string('description', 255);                                       // for example "CHARITY - SHEIKH ALI ISSA FOR SEPT 2026"
                $t->decimal('amount', 15, 2)->default(0);                             // what is paid: it may differ from the monthly amount
                $t->decimal('monthly_amount', 15, 2)->default(0);                     // the monthly amount at the time it was pulled
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('admin_document_sends')) {
            Schema::create('admin_document_sends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('admin_document_id')->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->json('recipients');
                $t->boolean('to_super_admins')->default(false);
                $t->string('status', 12)->default('Sent');                            // Sent | Failed
                $t->text('error')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        \App\Services\Administration\AdminTemplateSeeder::run();

        $names = array_keys(PermissionRegistry::modules()['weekly_administration']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (['weekly_administration', 'administration_charity', 'administration_reports'] as $key) {
                $f = SystemFeatureRegistry::features()[$key] ?? null;
                if (! $f) {
                    continue;
                }
                $exists = DB::table('system_features')->where('key', $key)->exists();
                DB::table('system_features')->updateOrInsert(['key' => $key], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
                ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
                DB::table('system_features')->where('key', $key)->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            }
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        foreach (['admin_document_sends', 'admin_document_charities', 'admin_document_items', 'admin_documents', 'admin_charity_people', 'admin_items'] as $t) {
            Schema::dropIfExists($t);
        }
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['weekly_administration']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->whereIn('key', ['weekly_administration', 'administration_charity', 'administration_reports'])->delete();
            FeatureManager::flushCache();
        }
    }
};
