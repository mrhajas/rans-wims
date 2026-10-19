<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_certificates', function (Blueprint $table) {
            $table->string('payment_status_override')->nullable()->after('status_override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('project_certificates', function (Blueprint $table) {
            $table->dropColumn('payment_status_override');
        });
    }
};
