<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Funds Allocation: one dated sheet (fund_allocations) listing what will be paid out that day.
 *  - fund_allocation_balances : the money available that day (cash/bank accounts, any currency + exchange rate)
 *  - fund_allocation_items    : the lines, grouped by fund_categories (Administration, Procurement, ...)
 *  - fund_allocation_item_lines : how each item is paid (one row per payment method; the payer ticks them off)
 * Accounts, categories and payment methods are all user-editable (Settings page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->default('bank'); // bank | cash | mobile | other
            $table->string('currency', 3)->default('TZS');
            $table->string('account_number')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fund_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fund_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_label', 12);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fund_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('allocation_date')->index();
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('Draft')->index();
            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete(); // the payer
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fund_allocation_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_allocation_id')->constrained('fund_allocations')->cascadeOnDelete();
            $table->foreignId('fund_account_id')->nullable()->constrained('fund_accounts')->nullOnDelete();
            $table->string('label');
            $table->string('currency', 3)->default('TZS');
            $table->decimal('amount', 15, 2)->default(0);        // in the account's own currency
            $table->decimal('exchange_rate', 15, 6)->default(1); // 1 unit of that currency in TZS
            $table->decimal('amount_tzs', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('fund_allocation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_allocation_id')->constrained('fund_allocations')->cascadeOnDelete();
            $table->foreignId('fund_category_id')->constrained('fund_categories')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('description');
            $table->string('beneficiary')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staffs')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->decimal('request_amount', 15, 2)->default(0);
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->timestamp('paid_at')->nullable(); // set when every payment line of the item is ticked
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fund_allocation_item_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_allocation_item_id')->constrained('fund_allocation_items')->cascadeOnDelete();
            $table->foreignId('fund_payment_method_id')->constrained('fund_payment_methods')->restrictOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reference')->nullable(); // transaction / cheque / mobile-money reference entered by the payer
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Defaults (all editable afterwards).
        $now = now();
        foreach (['ADMINISTRATION', 'PROCUREMENT', 'CASH PROCUREMENT', 'GENERAL LABOUR', 'LABOUR CONTRACTED WORKS', 'SUB-CONTRACTED WORKS', 'SITE SECURITY', 'PRIVATE', 'SUPPLIERS', 'DIRECTOR ALLOWANCE'] as $i => $name) {
            DB::table('fund_categories')->insert(['name' => $name, 'sort_order' => ($i + 1) * 10, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([['Bank Transfer (TT)', 'TT'], ['Cash', 'CASH'], ['Mobile Money', 'MOBILE'], ['Cheque', 'CHEQUE'], ['USD Cash', 'USD']] as $i => [$name, $short]) {
            DB::table('fund_payment_methods')->insert(['name' => $name, 'short_label' => $short, 'sort_order' => ($i + 1) * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_allocation_item_lines');
        Schema::dropIfExists('fund_allocation_items');
        Schema::dropIfExists('fund_allocation_balances');
        Schema::dropIfExists('fund_allocations');
        Schema::dropIfExists('fund_payment_methods');
        Schema::dropIfExists('fund_categories');
        Schema::dropIfExists('fund_accounts');
    }
};
