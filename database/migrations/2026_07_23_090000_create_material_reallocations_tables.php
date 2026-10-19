<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('material_reallocations')) {
            Schema::create('material_reallocations', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();
                $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('status', 32)->default('Draft');
                $table->date('date_requested');
                $table->text('reason')->nullable();
                $table->text('remarks')->nullable();
                $table->text('approval_remarks')->nullable();

                $table->timestamp('submitted_on')->nullable();
                $table->foreignId('cleared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cleared_on')->nullable();
                $table->foreignId('warehouse_allocated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('warehouse_allocated_on')->nullable();
                $table->foreignId('driver_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('vehicle', 64)->nullable();
                $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dispatch_date_time')->nullable();
                $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_date_time')->nullable();
                $table->foreignId('final_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('final_approval_date_time')->nullable();
                $table->timestamp('completed_on')->nullable();

                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->json('extra_attributes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['source_warehouse_id', 'status'], 'mrr_wh_status_idx');
                $table->index(['user_id', 'status'], 'mrr_user_status_idx');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('material_reallocation_items')) {
            Schema::create('material_reallocation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_reallocation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('item_id')->constrained()->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('requested_quantity', 15, 4);
                $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->decimal('allocated_quantity', 15, 4)->nullable();
                $table->decimal('received_quantity', 15, 4)->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('parent_item_id')->nullable()->constrained('material_reallocation_items')->nullOnDelete();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('material_reallocation_logs')) {
            Schema::create('material_reallocation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_reallocation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('role', 64)->nullable();
                $table->string('action', 32);
                $table->string('previous_status', 32)->nullable();
                $table->string('new_status', 32)->nullable();
                $table->text('remarks')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $indexExists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'material_reallocation_logs')
            ->where('index_name', 'mrr_logs_mrr_created_idx')
            ->exists();

        if (! $indexExists) {
            Schema::table('material_reallocation_logs', function (Blueprint $table) {
                $table->index(['material_reallocation_id', 'created_at'], 'mrr_logs_mrr_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_reallocation_logs');
        Schema::dropIfExists('material_reallocation_items');
        Schema::dropIfExists('material_reallocations');
    }
};
