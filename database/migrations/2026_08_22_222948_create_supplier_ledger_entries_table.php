<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The financial master for Supplier Records — one row per financial
     * event that moves a supplier's balance. A supplier's current balance
     * is always `SUM(debit) - SUM(credit)` over this table, ordered by
     * entry_date/id, exactly like a real accounting ledger.
     *
     * Deliberately one table for every entry type (opening balance,
     * obligation, invoice, payment, credit note, discount, retention,
     * withholding tax, adjustments, ...) rather than a table per concept —
     * they share one lifecycle (post, optionally verify/lock) and the
     * running-balance query needs to stay a single SUM over one table.
     *
     * `source_type`/`source_id` link back to whatever operational record
     * caused the entry (PurchaseOrder, GoodsReceipt, SupplierInvoice,
     * Payment) — nullable for manual/opening-balance entries. Critically,
     * a Purchase Order's material obligation and its later-arriving invoice
     * share ONE row (see App\Support\SupplierRecords\Ledger::recordInvoice,
     * which updates the existing obligation entry in place instead of
     * inserting a second one) — this is what prevents double-counting.
     */
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            // Nullable = Legacy/Unallocated (Section 9) — a balance not yet
            // attributable to a specific project/warehouse.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->date('entry_date');
            $table->string('type', 32);
            $table->enum('direction', ['debit', 'credit']);
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100);
            $table->text('description')->nullable();
            $table->nullableMorphs('source');
            // Only opening_balance entries meaningfully use Pending/Verified;
            // everything else posts straight to Posted.
            $table->string('status', 20)->default('Posted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_on')->nullable();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'entry_date']);
            $table->index(['supplier_id', 'warehouse_id']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
