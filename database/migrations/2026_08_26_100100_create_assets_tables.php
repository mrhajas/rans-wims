<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('asset_number')->unique();
            $table->string('name');
            $table->foreignId('asset_category_id')->nullable()->constrained('asset_categories')->nullOnDelete();
            $table->string('asset_type')->nullable();
            $table->text('description')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('vin_chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->string('barcode')->nullable();
            $table->string('tag_number')->nullable();

            // Ownership
            $table->string('ownership_type', 32)->default('Company Owned');
            $table->string('owner_name')->nullable();
            $table->string('lease_contract_reference')->nullable();
            $table->date('lease_start_date')->nullable();
            $table->date('lease_end_date')->nullable();
            $table->decimal('lease_monthly_cost', 15, 2)->nullable();
            $table->decimal('lease_deposit', 15, 2)->nullable();

            // Acquisition
            $table->date('acquisition_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('invoice_reference')->nullable();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->decimal('acquisition_cost', 15, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('transport_cost', 15, 2)->default(0);
            $table->decimal('installation_cost', 15, 2)->default(0);
            $table->decimal('other_capitalised_cost', 15, 2)->default(0);
            $table->decimal('total_capitalised_cost', 15, 2)->default(0);
            $table->string('funding_source')->nullable();

            // Current-state snapshot (full history lives in asset_movements)
            $table->foreignId('current_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('current_location_label')->nullable();
            $table->string('assigned_to_type', 32)->nullable();
            $table->foreignId('assigned_to_staff_id')->nullable()->constrained('staffs')->nullOnDelete();
            $table->string('assigned_to_label')->nullable();
            $table->date('assignment_date')->nullable();
            $table->date('expected_return_date')->nullable();

            // Status / Condition — kept separate
            $table->string('status', 32)->default('Available');
            $table->string('condition', 32)->default('New');

            // Depreciation config
            $table->string('depreciation_method', 32)->default('Straight Line');
            $table->unsignedSmallInteger('useful_life_years')->nullable();
            $table->decimal('residual_value', 15, 2)->default(0);
            $table->date('depreciation_start_date')->nullable();
            $table->string('depreciation_frequency', 16)->default('Annually');

            // Warranty (display-only)
            $table->string('warranty_provider')->nullable();
            $table->date('warranty_start')->nullable();
            $table->date('warranty_end')->nullable();
            $table->string('warranty_reference')->nullable();

            // Insurance (display-only)
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_policy_number')->nullable();
            $table->date('insurance_start')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->decimal('insurance_insured_value', 15, 2)->nullable();
            $table->decimal('insurance_premium', 15, 2)->nullable();

            // Vehicle / Machinery optional fields
            $table->string('fuel_type')->nullable();
            $table->decimal('fuel_capacity', 10, 2)->nullable();
            $table->decimal('current_meter', 12, 2)->nullable();
            $table->decimal('current_odometer', 12, 2)->nullable();
            $table->decimal('operating_hours', 12, 2)->nullable();
            $table->unsignedInteger('service_interval_value')->nullable();
            $table->string('service_interval_unit', 16)->nullable();
            $table->date('next_service_date')->nullable();
            $table->date('registration_expiry')->nullable();
            $table->date('inspection_expiry')->nullable();
            $table->date('license_expiry')->nullable();

            $table->timestamp('disposed_at')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('condition');
            $table->index('current_warehouse_id');
            $table->index('asset_category_id');
        });

        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('movement_type', 32); // Registered|Assigned|Check-Out|Check-In|Transfer

            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('from_label')->nullable();
            $table->foreignId('from_responsible_staff_id')->nullable()->constrained('staffs')->nullOnDelete();
            $table->string('from_responsible_label')->nullable();

            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('to_label')->nullable();
            $table->foreignId('to_responsible_staff_id')->nullable()->constrained('staffs')->nullOnDelete();
            $table->string('to_responsible_label')->nullable();

            $table->date('movement_date');
            $table->string('reason')->nullable();
            $table->string('condition_before', 32)->nullable();
            $table->string('condition_after', 32)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('damage_notes')->nullable();
            $table->text('missing_parts')->nullable();
            $table->text('accessories')->nullable();
            $table->decimal('meter_reading', 12, 2)->nullable();

            $table->foreignId('authorised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            // The base App\Models\Model always applies Eloquent's SoftDeletes
            // trait/global scope, so every model extending it needs a
            // deleted_at column even on an "append-only, never delete"
            // table like this one — it's just never actually set.
            $table->softDeletes();

            $table->index(['asset_id', 'movement_date']);
        });

        Schema::create('asset_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('maintenance_date');
            $table->string('maintenance_type', 32);
            $table->string('service_provider')->nullable();
            $table->text('description')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->decimal('meter_reading', 12, 2)->nullable();
            $table->date('next_service_date')->nullable();
            $table->decimal('next_service_meter', 12, 2)->nullable();
            $table->boolean('under_warranty')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'maintenance_date']);
        });

        Schema::create('asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_book_value', 15, 2);
            $table->decimal('period_depreciation', 15, 2);
            $table->decimal('accumulated_depreciation', 15, 2);
            $table->decimal('closing_book_value', 15, 2);
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'period_end']);
        });

        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('disposal_date');
            $table->string('disposal_method', 32); // Sold|Scrapped|Written Off|Donated|Returned to Owner|Lost|Stolen|Destroyed|Other
            $table->text('reason')->nullable();
            $table->decimal('disposal_value', 15, 2)->nullable();
            $table->string('buyer_recipient')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('final_book_value', 15, 2)->nullable();
            $table->decimal('gain_loss', 15, 2)->nullable();

            // Lost/Stolen specific
            $table->string('last_known_location')->nullable();
            $table->text('circumstances')->nullable();
            $table->string('report_reference')->nullable();
            $table->string('investigation_status')->nullable();
            $table->text('resolution')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_disposals');
        Schema::dropIfExists('asset_depreciation_entries');
        Schema::dropIfExists('asset_maintenance_records');
        Schema::dropIfExists('asset_movements');
        Schema::dropIfExists('assets');
    }
};
