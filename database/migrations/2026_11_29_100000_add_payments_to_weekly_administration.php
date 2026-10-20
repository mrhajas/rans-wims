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
 * Paying an authorized Administration document. A payment is a row with its lines: which document lines (or charity lines) it pays and how much of each, to whom
 * (a user, a staff member or a typed name), and how it came about: marked paid directly by a person with the permission, or through a Payment Voucher
 * ("Pending Voucher" while the voucher waits, "Paid" when the voucher is marked paid, "Released" when it is rejected, cancelled or deleted). What a line has been paid
 * is always the sum of its payment lines, so partial payments, reversals and vouchers can never disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_document_payments')) {
            Schema::create('admin_document_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->unsignedBigInteger('admin_document_id')->index();
                $t->string('status', 16)->default('Paid')->index();                 // Paid | Pending Voucher | Released | Reversed
                $t->string('source', 10)->default('direct');                       // direct | voucher
                $t->date('requested_on');
                $t->date('paid_on')->nullable()->index();
                $t->string('method', 100)->nullable();
                $t->string('payment_reference', 190)->nullable();
                $t->string('payee_type', 10)->default('other');                    // user | staff | other
                $t->unsignedBigInteger('paid_to_user_id')->nullable();
                $t->unsignedBigInteger('paid_to_staff_id')->nullable();
                $t->string('paid_to_name', 190)->nullable();
                $t->decimal('total_amount', 15, 2)->default(0);
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('payment_voucher_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();                      // who recorded / asked for it
                $t->unsignedBigInteger('paid_by_user_id')->nullable();
                $t->timestamp('released_at')->nullable();
                $t->text('release_reason')->nullable();
                $t->unsignedBigInteger('reversed_by_user_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->text('reverse_reason')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['admin_document_id', 'status'], 'admin_pay_doc_status_idx');
            });
        }
        if (! Schema::hasTable('admin_document_payment_lines')) {
            Schema::create('admin_document_payment_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('admin_document_payment_id')->index();
                $t->string('line_type', 8);                                          // item | charity
                $t->unsignedBigInteger('line_id');
                $t->decimal('amount', 15, 2);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['line_type', 'line_id'], 'admin_pay_line_idx');
            });
        }
        if (Schema::hasTable('admin_documents') && ! Schema::hasColumn('admin_documents', 'payment_status')) {
            Schema::table('admin_documents', function (Blueprint $t) {
                $t->decimal('paid_total', 15, 2)->default(0)->after('total_amount');
                $t->decimal('reserved_total', 15, 2)->default(0)->after('paid_total');   // waiting on a Payment Voucher
                $t->string('payment_status', 16)->default('Unpaid')->after('reserved_total')->index(); // Unpaid | Partially Paid | Paid
                $t->timestamp('last_paid_at')->nullable()->after('payment_status');
            });
        }
        if (Schema::hasTable('payment_voucher_items') && ! Schema::hasColumn('payment_voucher_items', 'admin_document_payment_id')) {
            Schema::table('payment_voucher_items', fn (Blueprint $t) => $t->unsignedBigInteger('admin_document_payment_id')->nullable()->index());
        }
        if (Schema::hasTable('payment_voucher_types')) {
            DB::table('payment_voucher_types')->updateOrInsert(['text' => 'Weekly Administration'], ['updated_at' => now(), 'created_at' => now()]);
        }

        $names = ['mark-paid-weekly-administration', 'reverse-payments-weekly-administration', 'create-payment-voucher-from-administration'];
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            $key = 'administration_payments';
            $f = SystemFeatureRegistry::features()[$key] ?? null;
            if ($f) {
                $exists = DB::table('system_features')->where('key', $key)->exists();
                DB::table('system_features')->updateOrInsert(['key' => $key], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
                ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
                DB::table('system_features')->where('key', $key)->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
                FeatureManager::flushCache();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_document_payment_lines');
        Schema::dropIfExists('admin_document_payments');
        if (Schema::hasColumn('admin_documents', 'payment_status')) {
            Schema::table('admin_documents', fn (Blueprint $t) => $t->dropColumn(['paid_total', 'reserved_total', 'payment_status', 'last_paid_at']));
        }
        if (Schema::hasColumn('payment_voucher_items', 'admin_document_payment_id')) {
            Schema::table('payment_voucher_items', fn (Blueprint $t) => $t->dropColumn('admin_document_payment_id'));
        }
        Permission::query()->whereIn('name', ['mark-paid-weekly-administration', 'reverse-payments-weekly-administration', 'create-payment-voucher-from-administration'])->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'administration_payments')->delete();
            FeatureManager::flushCache();
        }
    }
};
