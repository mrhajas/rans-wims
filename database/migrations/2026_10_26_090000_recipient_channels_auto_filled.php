<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recipients pulls each person's details from their user and staff record. `auto_filled` marks an address that was pulled (and may be
 * refreshed when the user or staff record changes) as opposed to one a Super Admin typed in. A pulled address starts switched off and
 * only starts receiving messages once somebody enables it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wims_connect_recipient_channels', 'auto_filled')) {
            Schema::table('wims_connect_recipient_channels', function (Blueprint $table) {
                $table->boolean('auto_filled')->default(false)->after('is_enabled');
            });
        }

        // the emails seeded earlier from each login are pulled addresses
        DB::statement("UPDATE wims_connect_recipient_channels c JOIN users u ON u.id = c.user_id SET c.auto_filled = 1 WHERE c.channel = 'email' AND c.address = u.email");
    }

    public function down(): void
    {
        Schema::table('wims_connect_recipient_channels', function (Blueprint $table) {
            $table->dropColumn('auto_filled');
        });
    }
};
