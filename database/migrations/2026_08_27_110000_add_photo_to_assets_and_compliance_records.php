<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('tag_number');
        });

        Schema::table('compliance_records', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('photo');
        });

        Schema::table('compliance_records', function (Blueprint $table) {
            $table->dropColumn('photo');
        });
    }
};
