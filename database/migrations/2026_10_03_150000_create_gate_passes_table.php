<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A Gate Pass is a stand-alone document generated from a Transfer or a Checkin (same idea as a
    // Delivery Note): it stores only the link to its parent record, so it always prints the parent's
    // current details, and it never touches stock. On top of that it records who is carrying the goods:
    // the assigned driver and the vehicle — either picked from the Transport module's drivers (Contacts)
    // and vehicles (Assets), or typed in when they are not registered.
    public function up(): void
    {
        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('reference', 30)->unique();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');

            $table->unsignedBigInteger('driver_contact_id')->nullable();
            $table->string('driver_name', 190)->nullable();
            $table->string('driver_phone', 60)->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('vehicle_plate', 60)->nullable();
            $table->string('vehicle_details', 190)->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_passes');
    }
};
