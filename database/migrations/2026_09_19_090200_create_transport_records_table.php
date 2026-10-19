<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per individual trip — never combined, even when the same vehicle
 * makes several trips to the same destination on the same day. Destination
 * is a tagged union: Project/Warehouse both resolve to warehouse_id (WIMS
 * has no separate Projects table — a Project is just a non-main-warehouse
 * Warehouse row), Supplier/Client resolve to destination_contact_id (reusing
 * Contacts, same as driver_contact_id), everything else resolves to
 * transport_location_id. Amount auditing (default_amount snapshot +
 * amount_changed_* columns) and payment fields are deliberately columns
 * here rather than a separate history table — see the module's plan for why
 * (cheap "current state" reads, full history via the existing System Logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_records', function (Blueprint $table) {
            $table->id();
            $table->string('trip_number')->unique();
            $table->date('trip_date');
            $table->time('trip_time')->nullable();

            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('driver_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('destination_type', 32);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('destination_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('transport_location_id')->nullable()->constrained('transport_locations')->nullOnDelete();
            $table->string('destination_label')->nullable();

            $table->string('departure_location', 190)->nullable();
            $table->string('destination_location', 190)->nullable();
            $table->text('purpose')->nullable();

            $table->decimal('default_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->boolean('amount_overridden')->default(false);
            $table->text('amount_change_reason')->nullable();
            $table->foreignId('amount_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('amount_changed_at')->nullable();

            $table->string('payment_status', 32)->default('Unpaid');
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('payment_notes')->nullable();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'trip_date']);
            $table->index(['driver_contact_id', 'trip_date']);
            $table->index('warehouse_id');
            $table->index('destination_type');
            $table->index('payment_status');
            $table->index('payment_voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_records');
    }
};
