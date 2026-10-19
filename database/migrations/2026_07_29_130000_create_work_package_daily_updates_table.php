<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_package_daily_updates')) {
            Schema::create('work_package_daily_updates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_package_id')->constrained()->cascadeOnDelete();
                $table->text('activities_completed')->nullable();
                $table->decimal('progress_percent', 5, 2);
                $table->text('remarks')->nullable();
                $table->string('weather', 100)->nullable();
                $table->json('manpower')->nullable();
                $table->text('safety_observations')->nullable();
                $table->text('inspection_results')->nullable();
                $table->string('status', 32)->default('Pending Approval');
                $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_remarks')->nullable();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->json('extra_attributes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['work_package_id', 'status'], 'wpdu_wp_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_package_daily_updates');
    }
};
