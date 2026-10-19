<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('boq_material_compliances')) {
            return;
        }

        Schema::create('boq_material_compliances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('project_warehouse_id')->index();
            $table->unsignedBigInteger('warehouse_id')->index();
            $table->unsignedBigInteger('item_id')->index();
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('boq_quantity', 15, 4)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(
                ['account_id', 'project_warehouse_id', 'warehouse_id', 'item_id'],
                'boq_material_compliances_unique_scope'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_material_compliances');
    }
};
