<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional cross-reference to a paper Delivery Note or Issue Note tied
 * to this trip — purely informational, no business logic reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->string('delivery_note_ref', 190)->nullable()->after('destination_location');
        });
    }

    public function down(): void
    {
        Schema::table('transport_records', function (Blueprint $table) {
            $table->dropColumn('delivery_note_ref');
        });
    }
};
