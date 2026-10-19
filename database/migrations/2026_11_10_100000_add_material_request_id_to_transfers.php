<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Material Request can have several Transfers (each takes some of its issued items), so a transfer remembers the request it was made for. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transfers', 'material_request_id')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->unsignedBigInteger('material_request_id')->nullable()->after('multi_transfer_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transfers', 'material_request_id')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropColumn('material_request_id');
            });
        }
    }
};
