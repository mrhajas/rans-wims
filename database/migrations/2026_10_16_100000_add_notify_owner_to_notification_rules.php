<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_rules', function (Blueprint $table) {
            // also tell the person who created the record (a transfer's requester, for example)
            $table->boolean('notify_owner')->default(false)->after('skip_actor');
        });
    }

    public function down(): void
    {
        Schema::table('notification_rules', function (Blueprint $table) {
            $table->dropColumn('notify_owner');
        });
    }
};
