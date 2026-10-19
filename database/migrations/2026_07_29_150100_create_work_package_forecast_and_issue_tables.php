<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_package_material_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('required_quantity', 12, 2);
            $table->date('required_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['work_package_id']);
        });

        Schema::create('work_package_labour_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trade', 100)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('duration_days')->nullable();
            $table->date('required_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['work_package_id']);
        });

        Schema::create('work_package_equipment_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('required_quantity', 12, 2)->default(1);
            $table->date('required_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['work_package_id']);
        });

        Schema::create('work_package_issues', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('work_package_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 20)->default('Medium');
            $table->string('status', 20)->default('Open');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('deadline')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['work_package_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_package_issues');
        Schema::dropIfExists('work_package_equipment_forecasts');
        Schema::dropIfExists('work_package_labour_forecasts');
        Schema::dropIfExists('work_package_material_forecasts');
    }
};
