<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_allocations', function (Blueprint $table) {
            $table->foreignId('completed_by_user_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete(); // "paid by": whoever ticked the last payment
            $table->foreignId('cleared_by_user_id')->nullable()->after('completed_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable()->after('cleared_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('fund_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by_user_id');
            $table->dropConstrainedForeignId('cleared_by_user_id');
            $table->dropColumn('cleared_at');
        });
    }
};
