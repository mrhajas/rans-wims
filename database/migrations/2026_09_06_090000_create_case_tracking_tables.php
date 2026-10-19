<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small, admin-manageable lookup tables — kept editable rather than
        // hardcoded so "Manage Case Categories"/"Manage Case Priorities"
        // (from the module spec) are real, working permissions instead of
        // decorative ones with nothing behind them.
        Schema::create('case_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('case_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Higher = more urgent; drives default sort order and the
            // "High Priority" dashboard stat (level >= high_priority_threshold).
            $table->unsignedInteger('level')->default(1);
            $table->string('color', 20)->default('slate');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('case_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('case_number')->unique();
            $table->string('subject');
            $table->text('description');

            $table->foreignId('case_category_id')->nullable()->constrained('case_categories')->nullOnDelete();
            $table->foreignId('case_priority_id')->nullable()->constrained('case_priorities')->nullOnDelete();

            // Optional link to the Project/Site (warehouse) this case concerns,
            // and a free-text module/reference field for cases that point at a
            // specific WIMS record (e.g. "Payment Voucher PV-2026-000004")
            // without needing a real polymorphic FK to every possible module.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('module_reference')->nullable();
            $table->string('external_url')->nullable();

            $table->string('status', 30)->default('Draft');

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('reopened_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('status');
            $table->index('created_by_user_id');
            $table->index('assigned_to_user_id');
            $table->index('warehouse_id');
        });

        // Flat comment list — deliberately not threaded/nested, matching the
        // module's own "simple and practical" brief. Attachments on a case
        // reuse the app's existing polymorphic Attachment system instead of a
        // new table (see CaseTicket's HasAttachments usage).
        Schema::create('case_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_ticket_id')->constrained('case_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('case_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_comments');
        Schema::dropIfExists('case_tickets');
        Schema::dropIfExists('case_priorities');
        Schema::dropIfExists('case_categories');
    }
};
