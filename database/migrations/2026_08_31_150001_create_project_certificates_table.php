<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('project_certificate_contracts')->cascadeOnDelete();
            // Sequential per-project, not global — see CertificateNumbering.
            // Enforced unique per warehouse below, independent of any
            // account-wide numbering scheme (get_reference() doesn't scope
            // to a parent record, so this is a bespoke counter).
            $table->unsignedInteger('certificate_number');
            $table->string('certificate_type')->default('Interim Payment Certificate');
            $table->enum('status', ['draft', 'submitted', 'approved', 'issued', 'revised', 'cancelled'])->default('draft');
            $table->date('valuation_date')->nullable();
            $table->date('certificate_date')->nullable();
            $table->date('payment_due_date')->nullable();
            $table->string('period_covered')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            // Denormalized at issue-time so later certificates' "Previous
            // Certified" reference is a cheap lookup, not a full graph
            // re-evaluation of every prior certificate (mirrors Ledger.php's
            // preference for stored aggregates over live recomputation).
            $table->decimal('cumulative_certified_amount', 18, 2)->nullable();
            $table->decimal('net_payment_amount', 18, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
            // True once issued — lines become read-only; a Revise snapshots
            // and bumps version instead of editing in place.
            $table->boolean('is_locked')->default(false);
            $table->string('status_override_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['warehouse_id', 'certificate_number']);
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_certificates');
    }
};
