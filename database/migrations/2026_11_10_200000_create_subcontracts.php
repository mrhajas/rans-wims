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
 * Subcontractor Management: agreements with subcontractors (Contacts category SubContractors), their work schedules, variations,
 * payment requests, the link to Payment Vouchers (the only way a live payment is made), historical (legacy) payments imported from the
 * Excel workbook, and the import / reconciliation staging. Money is decimal(18,2); a balance is always derived, never stored.
 * See docs/subcontracts-workbook-analysis.md for why the tables look the way they do.
 */
return new class extends Migration
{
    private const FEATURES = ['subcontracts', 'subcontract_register', 'subcontract_agreements', 'subcontract_variations', 'subcontract_requests', 'subcontract_statements', 'subcontract_reports', 'subcontract_import', 'subcontract_settings'];

    public function up(): void
    {
        if (! Schema::hasTable('subcontract_agreements')) {
            Schema::create('subcontract_agreements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();                 // SCA-2026-0001
                $t->unsignedBigInteger('contact_id')->index();         // the subcontractor (Contacts, category SubContractors)
                $t->unsignedBigInteger('warehouse_id')->nullable()->index(); // the project
                $t->string('site', 190)->nullable();                   // building / site / phase text
                $t->string('title', 190);
                $t->text('scope')->nullable();
                $t->string('agreement_type', 24)->default('quantity');  // quantity | lump_sum | mixed | labour
                $t->string('currency', 3)->default('TZS');
                $t->decimal('original_amount', 18, 2)->default(0);      // frozen once approved; never overwritten by variations
                $t->date('agreement_date')->nullable();
                $t->date('start_date')->nullable();
                $t->date('expected_completion')->nullable();
                $t->date('actual_completion')->nullable();
                $t->unsignedBigInteger('administrator_user_id')->nullable();
                $t->string('status', 20)->default('Draft')->index();    // Draft Submitted Under Review Approved Active Suspended Completed Terminated Cancelled
                $t->text('payment_terms')->nullable();
                $t->text('advance_terms')->nullable();
                $t->decimal('retention_percent', 6, 2)->default(0);
                $t->string('vat_treatment', 30)->nullable();
                $t->text('remarks')->nullable();
                $t->date('as_at_date')->nullable();                     // imported accounts: the date of the sheet
                $t->string('source', 12)->default('manual');            // manual | import
                $t->unsignedBigInteger('import_sheet_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('submitted_by_user_id')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('approved_by_user_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('decision_remarks')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['contact_id', 'status']);
            });
        }
        if (! Schema::hasTable('subcontract_work_groups')) {
            Schema::create('subcontract_work_groups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agreement_id')->index();
                $t->string('name', 190);
                $t->string('kind', 16)->default('location');            // location | category | phase | original | additional | revised | other
                $t->unsignedBigInteger('warehouse_id')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_work_items')) {
            Schema::create('subcontract_work_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agreement_id')->index();
                $t->unsignedBigInteger('group_id')->nullable()->index();
                $t->unsignedBigInteger('variation_id')->nullable()->index(); // an item added by a variation counts only when that variation is approved
                $t->unsignedInteger('line_no')->default(0);
                $t->text('description');
                $t->string('category', 80)->nullable();
                $t->string('beneficiary_name', 190)->nullable();         // the person the work was given to / paid on the sheet
                $t->unsignedBigInteger('beneficiary_contact_id')->nullable();
                $t->string('unit', 30)->nullable();
                $t->decimal('quantity', 18, 4)->nullable();
                $t->decimal('rate', 18, 4)->nullable();
                $t->string('pricing_method', 12)->default('qty_rate');   // qty_rate | fixed | lump_sum
                $t->decimal('amount', 18, 2)->default(0);                // server calculated for qty_rate, entered for fixed / lump_sum
                $t->string('item_type', 12)->default('original');        // original | additional | revised
                $t->string('status', 12)->default('active');             // active | cancelled
                $t->text('remarks')->nullable();
                $t->string('source_sheet', 60)->nullable();
                $t->unsignedInteger('source_row')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_variations')) {
            Schema::create('subcontract_variations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();                  // VAR-2026-0001
                $t->unsignedBigInteger('agreement_id')->index();
                $t->string('title', 190);
                $t->text('reason')->nullable();
                $t->string('direction', 10)->default('increase');       // increase | reduction
                $t->decimal('amount', 18, 2);                           // always positive; direction gives the sign
                $t->string('status', 12)->default('Draft')->index();    // Draft Submitted Reviewed Approved Rejected
                $t->unsignedBigInteger('requested_by_user_id')->nullable();
                $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('approved_by_user_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('decision_remarks')->nullable();
                $t->date('effective_date')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_payment_requests')) {
            Schema::create('subcontract_payment_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();                  // SPR-2026-0001
                $t->unsignedBigInteger('agreement_id')->index();
                $t->unsignedBigInteger('contact_id')->index();
                $t->unsignedBigInteger('warehouse_id')->nullable();
                $t->date('request_date');
                $t->string('currency', 3)->default('TZS');
                $t->decimal('requested_amount', 18, 2);
                $t->decimal('approved_amount', 18, 2)->nullable();
                $t->string('kind', 12)->default('work');                // work | advance | variation | other
                $t->text('description')->nullable();
                $t->string('status', 16)->default('Draft')->index();    // Draft Submitted Under Review Returned Rejected Approved Cancelled
                $t->unsignedBigInteger('requested_by_user_id')->nullable();
                $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('approved_by_user_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('decision_remarks')->nullable();
                $t->boolean('override')->default(false);               // approved above the remaining entitlement, with a reason
                $t->text('override_reason')->nullable();
                $t->unsignedBigInteger('override_by_user_id')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_request_items')) {
            Schema::create('subcontract_request_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('request_id')->index();
                $t->unsignedBigInteger('work_item_id')->nullable()->index();
                $t->string('description', 255)->nullable();
                $t->decimal('amount', 18, 2);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_payments')) {
            Schema::create('subcontract_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('request_id')->index();
                $t->unsignedBigInteger('agreement_id')->index();
                $t->unsignedBigInteger('payment_voucher_id')->nullable()->unique();   // one payment, one voucher
                $t->decimal('amount', 18, 2);                           // in the agreement currency
                $t->string('currency', 3)->default('TZS');
                $t->decimal('exchange_rate', 18, 6)->nullable();        // agreement currency to TZS, only when it is not TZS
                $t->date('rate_date')->nullable();
                $t->decimal('amount_tzs', 18, 2);                       // what the voucher carries
                $t->string('status', 12)->default('initiated');         // initiated | voucher | cancelled
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_payment_allocations')) {
            Schema::create('subcontract_payment_allocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('payment_id')->index();
                $t->unsignedBigInteger('work_item_id')->nullable()->index();
                $t->decimal('amount', 18, 2);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_historical_payments')) {
            Schema::create('subcontract_historical_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agreement_id')->index();
                $t->unsignedBigInteger('work_item_id')->nullable()->index();
                $t->unsignedBigInteger('import_sheet_id')->nullable()->index();
                $t->string('kind', 16)->default('opening_paid');         // opening_paid (counted) | dated_evidence (never counted)
                $t->boolean('counted')->default(true);
                $t->date('payment_date')->nullable();
                $t->decimal('amount', 18, 2);
                $t->string('currency', 3)->default('TZS');
                $t->decimal('exchange_rate', 18, 6)->nullable();
                $t->date('rate_date')->nullable();
                $t->decimal('amount_tzs', 18, 2)->nullable();
                $t->string('source_sheet', 60)->nullable();
                $t->unsignedInteger('source_row')->nullable();
                $t->text('note')->nullable();
                $t->unsignedBigInteger('imported_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_import_batches')) {
            Schema::create('subcontract_import_batches', function (Blueprint $t) {
                $t->id();
                $t->string('reference', 40)->unique();                  // SIB-2026-0001
                $t->string('file_name', 255);
                $t->string('file_hash', 64)->index();
                $t->string('status', 12)->default('staged');            // staged | imported | reverted
                $t->unsignedInteger('sheet_count')->default(0);
                $t->json('summary')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('imported_by_user_id')->nullable();
                $t->timestamp('imported_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_import_sheets')) {
            Schema::create('subcontract_import_sheets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('batch_id')->index();
                $t->string('sheet_name', 60);
                $t->string('sheet_state', 10)->default('visible');
                $t->string('layout', 16)->nullable();                   // A | B | custom | summary
                $t->string('title', 255)->nullable();
                $t->string('suggested_name', 190)->nullable();
                $t->date('as_at_date')->nullable();
                $t->string('currency', 3)->default('TZS');
                $t->unsignedBigInteger('contact_id')->nullable();       // the chosen Contact
                $t->unsignedBigInteger('warehouse_id')->nullable();     // the chosen project
                $t->string('decision', 12)->default('pending');         // pending | import | skip
                $t->json('totals')->nullable();                         // source totals: contract, paid, balance, dated list sum
                $t->json('flags')->nullable();                          // what needs review
                $t->json('parsed')->nullable();                         // groups, items, instalments, dated payments (the staging copy)
                $t->unsignedBigInteger('agreement_id')->nullable();     // after import
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('subcontract_reconciliations')) {
            Schema::create('subcontract_reconciliations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agreement_id')->unique();
                $t->unsignedBigInteger('import_sheet_id')->nullable();
                $t->decimal('source_contract', 18, 2)->nullable();
                $t->decimal('source_paid', 18, 2)->nullable();
                $t->decimal('source_balance', 18, 2)->nullable();
                $t->decimal('imported_contract', 18, 2)->nullable();
                $t->decimal('imported_paid', 18, 2)->nullable();
                $t->decimal('dated_list_total', 18, 2)->nullable();
                $t->string('status', 12)->default('open');              // open | approved
                $t->text('note')->nullable();
                $t->unsignedBigInteger('approved_by_user_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (Schema::hasTable('payment_vouchers') && ! Schema::hasColumn('payment_vouchers', 'subcontract_payment_id')) {
            Schema::table('payment_vouchers', fn (Blueprint $t) => $t->unsignedBigInteger('subcontract_payment_id')->nullable()->index());
        }

        $names = [];
        foreach (PermissionRegistry::modules() as $key => $m) {
            if (str_starts_with($key, 'subcontract')) {
                $names = array_merge($names, array_keys($m['abilities'] ?? []));
            }
        }
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (self::FEATURES as $key) {
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
        if (Schema::hasColumn('payment_vouchers', 'subcontract_payment_id')) {
            Schema::table('payment_vouchers', fn (Blueprint $t) => $t->dropColumn('subcontract_payment_id'));
        }
        foreach (['subcontract_reconciliations', 'subcontract_import_sheets', 'subcontract_import_batches', 'subcontract_historical_payments', 'subcontract_payment_allocations', 'subcontract_payments', 'subcontract_request_items', 'subcontract_payment_requests', 'subcontract_variations', 'subcontract_work_items', 'subcontract_work_groups', 'subcontract_agreements'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        foreach (PermissionRegistry::modules() as $key => $m) {
            if (str_starts_with($key, 'subcontract')) {
                Permission::query()->whereIn('name', array_keys($m['abilities'] ?? []))->delete();
            }
        }
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->whereIn('key', self::FEATURES)->delete();
            FeatureManager::flushCache();
        }
    }
};
