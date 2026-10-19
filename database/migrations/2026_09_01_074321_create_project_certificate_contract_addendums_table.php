<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_certificate_contract_addendums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('project_certificate_contracts')->cascadeOnDelete();
            // Sequential per-contract (matches ProjectCertificate's own
            // per-warehouse certificate_number pattern) — displayed as
            // "Addendum No. N", separate from the free-text reference_number
            // field a QS might use for their own external filing reference.
            $table->unsignedInteger('addendum_number');
            $table->string('title');
            $table->text('reason')->nullable();
            $table->date('addendum_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->date('effective_date')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->text('notes')->nullable();
            $table->date('signed_date')->nullable();
            $table->string('signed_by')->nullable();
            // Denormalized from items on every save — same reasoning as
            // ProjectCertificate's cumulative_certified_amount: cheap to read
            // for the contract page's summary totals, no need to re-sum
            // every addendum's items on every page load.
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contract_id', 'addendum_number'], 'pcc_addendums_contract_number_unique');
            $table->index(['contract_id', 'status'], 'pcc_addendums_contract_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_certificate_contract_addendums');
    }
};
