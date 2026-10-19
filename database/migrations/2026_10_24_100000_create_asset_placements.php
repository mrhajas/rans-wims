<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the pieces of an asset are. An asset can have a quantity (for example 20 pcs of scaffolding), and Check-Out, Check-In and
 * Transfer can move only some of them, so the register keeps one row per place with how many pieces are there. A place is a
 * project / warehouse, a free-text location and, when somebody is holding them, who. Every existing asset gets one row at its current
 * location holding its whole quantity, so nothing changes for assets that are simply 1 piece.
 * Movements already had a quantity column (whole numbers, default 1); it becomes a decimal so kilograms and metres work, and gets a unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_placements')) {
            Schema::create('asset_placements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('location_label', 190)->default('');
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('responsible_label', 190)->default('');
                $table->char('place_key', 32);
                $table->decimal('quantity', 15, 3)->default(0);
                $table->timestamps();
                $table->softDeletes(); // the base model always soft-deletes; rows here are simply removed when empty

                $table->unique(['asset_id', 'place_key']);
            });
        }

        Schema::table('asset_movements', function (Blueprint $table) {
            $table->decimal('quantity', 15, 3)->default(1)->change();
            if (! Schema::hasColumn('asset_movements', 'unit_id')) {
                $table->unsignedBigInteger('unit_id')->nullable()->after('quantity');
            }
        });

        // every existing asset: one row at its current place with its whole quantity
        DB::table('assets')->whereNull('deleted_at')->orderBy('id')->chunkById(200, function ($assets) {
            foreach ($assets as $a) {
                if (DB::table('asset_placements')->where('asset_id', $a->id)->exists()) {
                    continue;
                }
                $qty = (float) ($a->quantity ?? 1);
                if ($qty <= 0) {
                    continue;
                }
                $label = trim((string) $a->current_location_label);
                $rlabel = trim((string) $a->assigned_to_label);
                $key = md5(implode('|', [(int) $a->current_warehouse_id, mb_strtolower($label), (int) $a->assigned_to_user_id, mb_strtolower($rlabel)]));
                DB::table('asset_placements')->insert([
                    'asset_id' => $a->id, 'warehouse_id' => $a->current_warehouse_id, 'location_label' => $label,
                    'responsible_user_id' => $a->assigned_to_user_id, 'responsible_label' => $rlabel,
                    'place_key' => $key, 'quantity' => $qty, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_placements');
        Schema::table('asset_movements', function (Blueprint $table) {
            if (Schema::hasColumn('asset_movements', 'unit_id')) {
                $table->dropColumn('unit_id');
            }
        });
    }
};
