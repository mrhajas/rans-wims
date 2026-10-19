<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_agreements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Same dual-payee-type shape as labour_list_items — a workforce
            // member is a Contact (Workers/SubContractors/Foreman) or,
            // occasionally, internal Staff.
            $table->string('payee_type', 32)->default('External Personnel');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staffs')->nullOnDelete();
            $table->string('payee_name');

            $table->text('scope_of_work')->nullable();
            $table->decimal('agreed_amount', 15, 2);
            $table->string('agreement_reference')->nullable();
            $table->date('agreement_date');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('Active'); // Active | Cancelled

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('status');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_agreements');
    }
};
