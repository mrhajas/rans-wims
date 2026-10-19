<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A human-friendly name the admin creating the rule chooses themselves
     * — e.g. "Notify site engineers on dispatch" — distinct from the
     * underlying event key, which stays a technical identifier.
     */
    public function up(): void
    {
        Schema::table('wims_connect_rules', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('wims_connect_rules', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
