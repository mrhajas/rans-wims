<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_print_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable()->index();
            $table->string('role')->nullable()->index();
            $table->string('action_type', 20)->index();
            $table->string('module')->nullable()->index();
            $table->string('route_name')->nullable()->index();
            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('file_name')->nullable();
            $table->string('ip_address', 64)->nullable()->index();
            $table->string('device')->nullable();
            $table->string('os')->nullable();
            $table->string('browser')->nullable();
            $table->string('location')->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamp('performed_at')->index();
            $table->json('extra_attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_print_logs');
    }
};
