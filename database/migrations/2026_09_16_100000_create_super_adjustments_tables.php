<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('date');

            // "Responsible User" — the submitter, auto-stamped on create via
            // User::$hasUser/$setUser (see App\Models\Model::booted()),
            // never user-editable from the form.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('reason');
            $table->text('details')->nullable();
            $table->text('remarks')->nullable();

            $table->string('status', 20)->default('Draft'); // Draft | Approved | Rejected

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Approval splits items by variance sign into up to two real
            // Adjustments (one Addition, one Subtraction) — see
            // SuperAdjustmentController::approve(). Either or both may stay
            // null (e.g. every item's physical count matched system stock).
            $table->foreignId('created_addition_adjustment_id')->nullable()->constrained('adjustments')->nullOnDelete();
            $table->foreignId('created_subtraction_adjustment_id')->nullable()->constrained('adjustments')->nullOnDelete();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
            $table->index('status');
        });

        Schema::create('super_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('super_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();

            // Locked to the item's own default unit — never user-selectable —
            // so the physical count and the live system stock it's compared
            // against are always in the same unit (see plan decision 3).
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('system_quantity_at_submission', 15, 4)->default(0);
            $table->decimal('physical_quantity', 15, 4);

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_adjustment_items');
        Schema::dropIfExists('super_adjustments');
    }
};
