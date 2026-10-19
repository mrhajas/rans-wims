<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A contract can now be co-funded by more than one client, each paying
     * in their own currency — Original Contract Sum becomes the sum of
     * every client's contribution converted to TZS (via their own agreed
     * exchange_rate when their currency isn't already TZS), rather than a
     * single typed figure. One row per client per contract.
     */
    public function up(): void
    {
        Schema::create('project_certificate_contract_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('project_certificate_contracts')->cascadeOnDelete();
            $table->foreignId('client_contact_id')->constrained('contacts');
            $table->string('currency', 10)->default('TZS');
            $table->decimal('amount', 18, 2)->default(0);
            // Client's currency → TZS. Only meaningful (and required, at
            // the application layer) when currency isn't TZS — left
            // nullable here since a TZS row never needs one.
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Backfill every existing contract's single client_contact_id into
        // one client row, so "Original Contract Sum" keeps meaning exactly
        // what it already did for contracts set up before this feature —
        // its full original_contract_sum, in the contract's own currency,
        // at an implicit 1:1 rate (every contract in this system today is
        // TZS-denominated, so this is exact, not an approximation).
        DB::table('project_certificate_contracts')
            ->whereNotNull('client_contact_id')
            ->orderBy('id')
            ->get()
            ->each(function ($contract) {
                DB::table('project_certificate_contract_clients')->insert([
                    'contract_id' => $contract->id,
                    'client_contact_id' => $contract->client_contact_id,
                    'currency' => $contract->currency ?? 'TZS',
                    'amount' => $contract->original_contract_sum ?? 0,
                    'exchange_rate' => 1,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_certificate_contract_clients');
    }
};
