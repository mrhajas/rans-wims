<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_packages')) {
            Schema::create('work_packages', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();
                $table->string('title');
                $table->string('category', 100)->nullable();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->string('building', 100)->nullable();
                $table->string('floor', 100)->nullable();
                $table->string('zone', 100)->nullable();
                $table->string('area', 100)->nullable();
                $table->foreignId('engineer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('foreman_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->foreignId('subcontractor_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('priority', 16)->default('Normal');
                $table->date('planned_start');
                $table->date('planned_finish');
                $table->unsignedInteger('planned_duration')->default(0);
                $table->json('dependencies')->nullable();
                $table->decimal('expected_progress_weight', 8, 2)->default(1);
                $table->decimal('actual_progress_percent', 5, 2)->default(0);
                $table->string('status', 32)->default('Not Started');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->json('extra_attributes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['warehouse_id', 'status'], 'wp_warehouse_status_idx');
                $table->index(['account_id', 'status'], 'wp_account_status_idx');
            });
        }

        if (! Schema::hasTable('pem_audit_logs')) {
            Schema::create('pem_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('loggable_type');
                $table->unsignedBigInteger('loggable_id');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('role', 64)->nullable();
                $table->string('action', 64);
                $table->json('previous_value')->nullable();
                $table->json('new_value')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->string('browser', 64)->nullable();
                $table->string('device', 64)->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['loggable_type', 'loggable_id', 'created_at'], 'pem_logs_loggable_created_idx');
                $table->index(['account_id', 'action'], 'pem_logs_account_action_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pem_audit_logs');
        Schema::dropIfExists('work_packages');
    }
};
