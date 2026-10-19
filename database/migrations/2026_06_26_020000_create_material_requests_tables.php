<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('material_requests')) {
            Schema::create('material_requests', function (Blueprint $table) {
                $table->id();
                $table->date('date_requested');
                $table->string('reference')->unique();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status', 32)->default('Pending Approval');
                $table->text('reason');
                $table->text('comments')->nullable();
                $table->text('approval_remarks')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->json('extra_attributes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['warehouse_id', 'status'], 'mr_wh_status_idx');
                $table->index(['user_id', 'status'], 'mr_user_status_idx');
                $table->index('date_requested');
            });
        }

        if (! Schema::hasTable('material_request_items')) {
            Schema::create('material_request_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('item_id')->constrained()->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('quantity', 15, 4);
                $table->string('priority', 16)->default('Normal');
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('material_request_status_logs')) {
            Schema::create('material_request_status_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status', 32);
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        $indexExists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'material_request_status_logs')
            ->where('index_name', 'mr_logs_mr_created_idx')
            ->exists();

        if (! $indexExists) {
            Schema::table('material_request_status_logs', function (Blueprint $table) {
                $table->index(['material_request_id', 'created_at'], 'mr_logs_mr_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_status_logs');
        Schema::dropIfExists('material_request_items');
        Schema::dropIfExists('material_requests');
    }
};
