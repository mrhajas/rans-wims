<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payment against a certificate — never overwrites the
 * certificate's own amount (mirrors Supplier Records' Payment model: many
 * payments can target one document). Payment status is a computed
 * accessor on ProjectCertificate (SUM(amount) vs certificate net amount),
 * with status_override/override_reason here only when a specific payment
 * needs a manual correction note (e.g. a reversed/disputed payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_certificate_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained('project_certificates')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->string('method')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['certificate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_certificate_payments');
    }
};
