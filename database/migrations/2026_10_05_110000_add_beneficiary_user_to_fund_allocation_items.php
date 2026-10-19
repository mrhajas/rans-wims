<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_allocation_items', function (Blueprint $table) {
            $table->foreignId('beneficiary_user_id')->nullable()->after('contact_id')->constrained('users')->nullOnDelete();
            $table->foreignId('beneficiary_warehouse_id')->nullable()->after('beneficiary_user_id')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fund_allocation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('beneficiary_warehouse_id');
            $table->dropConstrainedForeignId('beneficiary_user_id');
        });
    }
};
