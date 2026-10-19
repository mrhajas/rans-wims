<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loading & Unloading: a PREVIOUS (brought forward) balance for a person, e.g. money still owed to them from before the
 * module was used. One row per person; it is added to what they earned and reduced by what is paid, so it flows into
 * every balance, voucher limit and report. The amount can be negative (money already taken in advance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_loading_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained('contacts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('as_of_date');          // the balance stands as at this date; reports ending before it do not count it
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_loading_opening_balances');
    }
};
