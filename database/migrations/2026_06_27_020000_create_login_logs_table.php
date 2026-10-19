<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('username')->nullable()->index();
            $table->string('role')->nullable()->index();
            $table->string('status', 20)->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('device')->nullable();
            $table->string('os')->nullable()->index();
            $table->string('browser')->nullable()->index();
            $table->string('location')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('attempted_at')->index();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
