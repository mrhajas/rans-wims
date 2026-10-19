<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The Method dropdown's own longest option ("Straight-Line with Residual
    // Value") is 33 characters — past the old varchar(32) column and the
    // matching validation rule, which would otherwise reject that pick with
    // a truncation error on save.
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('depreciation_method', 64)->default('Straight-Line Method')->change();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('depreciation_method', 32)->default('Straight Line')->change();
        });
    }
};
