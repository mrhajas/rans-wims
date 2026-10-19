<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A Delivery Note is a stand-alone document generated from a Transfer or a Checkin.
    // It stores only the link to its parent record (no copied figures), so it always
    // renders the parent's current details, and it never touches stock.
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('reference', 30)->unique();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
