<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. IMP-20260830-0001, safe to show in UI/audit trail
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('original_user_id')->index();
            $table->unsignedBigInteger('target_user_id')->index();
            $table->string('reason', 500);
            $table->string('mode', 20)->default('full'); // full | read_only
            $table->string('status', 20)->default('active')->index(); // active | ended | expired | terminated
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('ended_by_user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('original_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('target_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('ended_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
