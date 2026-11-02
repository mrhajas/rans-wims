<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The remark an approver chooses or types when approving a transfer that leaves a main warehouse ("Go Ahead", "Confirm with Management", ...).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transfers', 'approval_remark')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->string('approval_remark', 300)->nullable()->after('approved_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transfers', 'approval_remark')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropColumn('approval_remark');
            });
        }
    }
};
