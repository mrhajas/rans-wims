<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transfer previously had no "received" state at all — only draft and
     * approved_by/approved_at (see the WIMS Connect analysis doc's gap
     * note). Purely additive and nullable: every existing Transfer row and
     * every existing query against this table is unaffected.
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('approved_at');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn('received_at');
        });
    }
};
