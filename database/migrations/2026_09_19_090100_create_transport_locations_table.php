<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small, reusable, searchable master list for the Transport Record
 * destination types that aren't already first-class WIMS entities
 * (Warehouses/Projects and Suppliers/Clients are — see transport_records'
 * warehouse_id/destination_contact_id columns instead). Keeps trip entry
 * from re-typing the same free-text destination every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location_type', 32); // Office|Site|Workshop|Port|Airport|Government Office|Other Location
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'location_type']);
            $table->index('location_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_locations');
    }
};
