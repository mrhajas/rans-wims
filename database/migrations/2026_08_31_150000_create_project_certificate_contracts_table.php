<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per project (warehouse) that has certificates — holds the
 * contract-level fields Warehouse itself doesn't have (client, consultant,
 * contract sum, currency, dates). Deliberately a separate table rather than
 * new warehouse columns: most warehouses are never certificate-bearing
 * projects, and this keeps the change additive/isolated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_certificate_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->unique()->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('client_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('consultant_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // Defaults to Account::name at read-time when null — only set
            // here to override for a joint-venture / subcontracted case.
            $table->string('contractor_name')->nullable();
            $table->string('contract_reference')->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->boolean('vat_included')->default(false);
            $table->decimal('original_contract_sum', 18, 2)->default(0);
            $table->decimal('contract_variation_amount', 18, 2)->default(0);
            // Stored (not a DB-generated column) so it survives even if the
            // computation rule ever changes — recomputed on every save.
            $table->decimal('revised_contract_sum', 18, 2)->default(0);
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_certificate_contracts');
    }
};
