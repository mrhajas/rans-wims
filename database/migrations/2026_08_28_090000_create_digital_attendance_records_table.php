<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');

            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->string('status', 32)->default('CLOCKED_IN');

            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('late_minutes')->nullable();

            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->decimal('clock_in_accuracy', 8, 2)->nullable();
            $table->string('clock_in_location_status', 32)->nullable();
            $table->string('clock_in_location_label')->nullable();
            $table->string('clock_in_ip', 64)->nullable();
            $table->text('clock_in_user_agent')->nullable();
            $table->string('clock_in_browser', 64)->nullable();
            $table->string('clock_in_os', 64)->nullable();
            $table->string('clock_in_device', 32)->nullable();

            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->decimal('clock_out_accuracy', 8, 2)->nullable();
            $table->string('clock_out_location_status', 32)->nullable();
            $table->string('clock_out_location_label')->nullable();
            $table->string('clock_out_ip', 64)->nullable();
            $table->text('clock_out_user_agent')->nullable();
            $table->string('clock_out_browser', 64)->nullable();
            $table->string('clock_out_os', 64)->nullable();
            $table->string('clock_out_device', 32)->nullable();

            $table->boolean('corrected')->default(false);
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'attendance_date']);
            $table->index('attendance_date');
            $table->index('status');
            $table->index('clock_in_at');
            $table->index('clock_out_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_attendance_records');
    }
};
