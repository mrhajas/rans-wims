<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('labour_list_id')->constrained()->cascadeOnDelete();

            // Same dual-payee-type idea as PaymentVoucher::PAYEE_TYPE_* —
            // deliberately reused values ("Internal Staff" / "External
            // Personnel") so the labourer identity copies straight across
            // into the generated voucher with zero translation.
            $table->string('payee_type', 32)->default('External Personnel');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staffs')->nullOnDelete();
            $table->string('payee_name');

            $table->string('trade', 100)->nullable();
            $table->text('work_description')->nullable();
            $table->decimal('days', 8, 2)->nullable();
            $table->decimal('hours', 8, 2)->nullable();
            $table->string('rate_type', 20)->default('day'); // day | hour | fixed
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_list_items');
    }
};
