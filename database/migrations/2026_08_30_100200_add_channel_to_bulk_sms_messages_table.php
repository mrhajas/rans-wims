<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bulk Connect now sends over WhatsApp/Meta/SMS, not just SMS — table
     * name stays bulk_sms_* (an internal implementation detail, not
     * user-facing) to avoid an unnecessary data migration.
     */
    public function up(): void
    {
        Schema::table('bulk_sms_messages', function (Blueprint $table) {
            $table->string('channel')->default('sms')->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_sms_messages', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
