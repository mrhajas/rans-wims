<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->date('reconciliation_date');
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            // Snapshotted at the moment of reconciliation — the live ledger
            // balance can keep moving afterward, this freezes what it was.
            $table->decimal('wims_balance', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->string('reason', 40)->nullable();
            $table->string('status', 20)->default('Open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_on')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_reconciliations');
    }
};
