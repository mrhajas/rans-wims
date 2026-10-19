<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_report_logs', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->date('week_end');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->json('recipients')->nullable();
            $table->string('status');
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_report_logs');
    }
};
