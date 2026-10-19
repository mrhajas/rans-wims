<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Face login: one row per enrolled user. Only mathematical face templates (encrypted) are kept, never photos.
        Schema::create('face_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('descriptors');              // Crypt-encrypted JSON: a few 128-number vectors
            $table->unsignedTinyInteger('sample_count')->default(0);
            $table->timestamp('enrolled_at')->nullable();
            $table->string('enrolled_ip', 64)->nullable();
            $table->string('enrolled_device', 190)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 64)->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('reset_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_templates');
    }
};
