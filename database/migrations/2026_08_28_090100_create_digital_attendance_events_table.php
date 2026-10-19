<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only event trail — richer, domain-specific companion to the
     * generic Spatie activity log every model gets for free (see App\Models\Model).
     * Rows here are never updated; corrections/voids add a new row rather
     * than mutating history (see spec §13/§48 — "never silently change
     * historical attendance").
     */
    public function up(): void
    {
        Schema::create('digital_attendance_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->foreignId('digital_attendance_record_id')->nullable()->constrained('digital_attendance_records')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('event_type', 32);
            $table->text('description')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('digital_attendance_record_id');
            $table->index('user_id');
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_attendance_events');
    }
};
