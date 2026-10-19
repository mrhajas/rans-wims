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
        Schema::table('project_certificate_contracts', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->default(15.00)->after('vat_included');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_certificate_contracts', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });
    }
};
