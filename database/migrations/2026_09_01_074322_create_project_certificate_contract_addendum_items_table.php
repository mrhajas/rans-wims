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
        Schema::create('project_certificate_contract_addendum_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addendum_id')->constrained('project_certificate_contract_addendums')->cascadeOnDelete();
            $table->text('description');
            // Dimensions are optional take-off measurements — when any are
            // given, the frontend derives quantity as their product
            // (missing ones treated as 1) rather than trusting a
            // separately-typed quantity that could silently drift out of
            // sync with the measurements next to it.
            $table->decimal('length', 12, 3)->nullable();
            $table->decimal('width', 12, 3)->nullable();
            $table->decimal('height', 12, 3)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('unit_rate', 18, 2)->default(0);
            // quantity * unit_rate — stored (not just computed on read) so
            // SQL SUM(amount) can cheaply back the addendum's total_amount.
            $table->decimal('amount', 18, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_certificate_contract_addendum_items');
    }
};
