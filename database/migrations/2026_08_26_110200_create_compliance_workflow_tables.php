<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_renewal_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_record_id')->constrained('compliance_records')->cascadeOnDelete();
            $table->date('previous_issue_date')->nullable();
            $table->date('previous_expiry_date')->nullable();
            $table->date('new_issue_date')->nullable();
            $table->date('new_expiry_date')->nullable();
            $table->timestamp('renewal_started_at')->nullable();
            $table->timestamp('renewal_submitted_at')->nullable();
            $table->string('application_number')->nullable();
            $table->decimal('renewal_fee', 15, 2)->nullable();
            $table->string('renewal_payment_reference')->nullable();
            $table->date('expected_completion_date')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('renewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('Renewal in Progress');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['compliance_record_id', 'status']);
        });

        Schema::create('compliance_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_record_id')->constrained('compliance_records')->cascadeOnDelete();
            // attachments.id is a plain INT UNSIGNED (not BIGINT) — foreignId()
            // would generate a BIGINT column and MySQL rejects the resulting
            // type-mismatched FK (errno 150), so this matches it by hand.
            $table->unsignedInteger('attachment_id');
            $table->foreign('attachment_id')->references('id')->on('attachments')->cascadeOnDelete();
            $table->string('document_type');
            $table->unsignedInteger('version_number')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('renewal_history_id')->nullable()->constrained('compliance_renewal_history')->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['compliance_record_id', 'document_type', 'is_current'], 'cdv_record_doctype_current_idx');
        });

        Schema::create('compliance_reminder_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_record_id')->constrained('compliance_records')->cascadeOnDelete();
            $table->foreignId('renewal_history_id')->nullable()->constrained('compliance_renewal_history')->nullOnDelete();
            $table->integer('interval_days');
            $table->timestamp('sent_at');
            $table->string('channel', 16)->default('database');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('Sent');
            $table->text('failure_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['compliance_record_id', 'renewal_history_id', 'interval_days'], 'crl_record_renewal_interval_idx');
        });

        Schema::create('compliance_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_record_id')->constrained('compliance_records')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->text('description')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            $table->index(['compliance_record_id', 'created_at'], 'ch_record_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_history');
        Schema::dropIfExists('compliance_reminder_log');
        Schema::dropIfExists('compliance_document_versions');
        Schema::dropIfExists('compliance_renewal_history');
    }
};
