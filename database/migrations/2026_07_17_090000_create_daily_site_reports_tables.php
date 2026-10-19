<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_site_reports')) {
            Schema::create('daily_site_reports', function (Blueprint $table) {
                $table->id();
                $table->string('report_number')->unique();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->string('report_type', 64);
                $table->string('priority', 16)->default('Normal');
                $table->date('report_date');
                $table->text('remarks')->nullable();
                $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
                $table->string('status', 16)->default('Submitted');
                $table->foreignId('viewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('viewed_at')->nullable();
                $table->foreignId('downloaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('downloaded_at')->nullable();
                $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('archived_at')->nullable();
                $table->boolean('auto_archived')->default(false);
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->json('extra_attributes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['warehouse_id', 'status'], 'dsr_wh_status_idx');
                $table->index(['submitted_by', 'status'], 'dsr_user_status_idx');
                $table->index('report_date');
            });
        }

        if (! Schema::hasTable('daily_site_report_logs')) {
            Schema::create('daily_site_report_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('daily_site_report_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('role', 64)->nullable();
                $table->string('action', 32);
                $table->text('remarks')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['daily_site_report_id', 'created_at'], 'dsr_logs_report_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_site_report_logs');
        Schema::dropIfExists('daily_site_reports');
    }
};
