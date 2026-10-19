<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Needed to calculate and report PAYE/NSSF per employee for Salary Management. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staffs', function (Blueprint $table) {
            $table->string('tin_number')->nullable()->after('account_no');
            $table->string('nssf_number')->nullable()->after('tin_number');
        });
    }

    public function down(): void
    {
        Schema::table('staffs', function (Blueprint $table) {
            $table->dropColumn(['tin_number', 'nssf_number']);
        });
    }
};
