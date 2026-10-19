<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A day can be saved with its own daily rate; the standard rate of that moment is kept beside it so a custom rate stays recognisable later. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_loading_attendances', function (Blueprint $table) {
            $table->decimal('standard_rate', 15, 2)->nullable()->after('daily_rate');
        });
    }

    public function down(): void
    {
        Schema::table('transport_loading_attendances', function (Blueprint $table) {
            $table->dropColumn('standard_rate');
        });
    }
};
