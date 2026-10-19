<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_activities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);
            $table->unsignedBigInteger('account_id');
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->unsignedBigInteger('work_activity_id')->nullable()->after('warehouse_id');
        });

        // Seed the default activity for each existing account.
        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            DB::table('work_activities')->insert([
                'name'       => 'Construction',
                'account_id' => $accountId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropColumn('work_activity_id');
        });
        Schema::dropIfExists('work_activities');
    }
};
