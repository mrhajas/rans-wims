<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_appreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_value', 15, 2);
            $table->decimal('period_appreciation', 15, 2);
            $table->decimal('accumulated_appreciation', 15, 2);
            $table->decimal('closing_value', 15, 2);
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_appreciation_entries');
    }
};
