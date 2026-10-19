<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single wide table for v1 rather than a per-type hierarchy (Bank
     * Guarantee / Loan / Licence / Certificate / Insurance / Contract) —
     * the type-specific field sets are small and mostly non-overlapping,
     * and this matches the size of the Payment Voucher precedent. The
     * Form UI only shows the field group relevant to the selected
     * compliance_type. A future refactor could split by type if the
     * column count grows unreasonably.
     */
    public function up(): void
    {
        Schema::create('compliance_records', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('compliance_number')->unique();
            $table->string('compliance_type');
            $table->foreignId('compliance_category_id')->nullable()->constrained('compliance_categories')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('issuing_authority')->nullable();
            $table->string('issued_to')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department')->nullable();

            // Dates
            $table->date('issue_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->date('submission_date')->nullable();
            $table->date('next_review_date')->nullable();

            // Relationships
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('contract_reference')->nullable();

            // Scope / Status / Priority
            $table->string('scope', 32)->default('Company');
            $table->string('status', 32)->default('Draft');
            $table->string('priority', 16)->default('Normal');
            $table->boolean('priority_is_manual_override')->default(false);

            // Bank Guarantee fields
            $table->string('guarantee_number')->nullable();
            $table->string('guarantee_bank')->nullable();
            $table->string('guarantee_beneficiary')->nullable();
            $table->string('guarantee_applicant')->nullable();
            $table->string('guarantee_type', 32)->nullable();
            $table->decimal('guarantee_amount', 15, 2)->nullable();
            $table->string('guarantee_currency', 8)->nullable();
            $table->date('guarantee_claim_expiry')->nullable();
            $table->text('guarantee_purpose')->nullable();
            $table->decimal('guarantee_commission', 15, 2)->nullable();
            $table->text('guarantee_renewal_terms')->nullable();

            // Loan fields
            $table->string('loan_lender')->nullable();
            $table->string('loan_reference')->nullable();
            $table->string('loan_facility_type')->nullable();
            $table->decimal('loan_principal_amount', 15, 2)->nullable();
            $table->string('loan_currency', 8)->nullable();
            $table->decimal('loan_interest_rate', 5, 2)->nullable();
            $table->date('loan_start_date')->nullable();
            $table->date('loan_maturity_date')->nullable();
            $table->string('loan_instalment_frequency', 16)->nullable();
            $table->decimal('loan_instalment_amount', 15, 2)->nullable();
            $table->decimal('loan_outstanding_balance', 15, 2)->nullable();
            $table->date('loan_next_payment_date')->nullable();
            $table->text('loan_security')->nullable();

            // Insurance fields
            $table->string('insurance_policy_number')->nullable();
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_policy_type')->nullable();
            $table->string('insurance_insured_party')->nullable();
            $table->decimal('insurance_insured_value', 15, 2)->nullable();
            $table->decimal('insurance_premium', 15, 2)->nullable();

            // Contract fields
            $table->string('contract_number')->nullable();
            $table->string('contract_type')->nullable();
            $table->text('contract_parties')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->unsignedInteger('contract_notice_period_days')->nullable();
            $table->text('contract_renewal_terms')->nullable();

            // Licence / Certificate shared fields
            $table->string('licence_or_certificate_holder')->nullable();
            $table->decimal('renewal_fee', 15, 2)->nullable();
            $table->text('renewal_requirements')->nullable();

            // Recurrence (descriptor only — auto-generator deferred)
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_frequency', 16)->nullable();
            $table->unsignedInteger('recurrence_custom_days')->nullable();

            // Reminders / recipients
            $table->json('reminder_intervals')->nullable();
            $table->foreignId('escalation_recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('additional_recipient_user_ids')->nullable();
            $table->string('responsible_role')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extra_attributes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('priority');
            $table->index('expiry_date');
            $table->index('scope');
            $table->index('compliance_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_records');
    }
};
