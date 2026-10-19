<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Growable "Work Performed" phrase bank — same shape as payment_voucher_types. */
    public function up(): void
    {
        Schema::create('labour_work_items', function (Blueprint $table) {
            $table->id();
            $table->string('text')->unique();
            $table->unsignedInteger('use_count')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_work_items');
    }
};
