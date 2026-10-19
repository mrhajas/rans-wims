<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'warehouse_id']);
        });

        // Seed the pivot from the legacy single warehouse_id column so existing
        // users keep the warehouse they were already assigned to.
        DB::table('users')
            ->whereNotNull('warehouse_id')
            ->select('id', 'warehouse_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('user_warehouse')->insertOrIgnore([
                    'user_id'      => $user->id,
                    'warehouse_id' => $user->warehouse_id,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_warehouse');
    }
};
