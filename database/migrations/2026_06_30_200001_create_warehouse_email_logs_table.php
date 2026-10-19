<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');                     // checkin | checkout | transfer
            $table->string('reference')->nullable();
            $table->string('warehouse_name')->nullable();
            $table->integer('recipient_count')->default(0);
            $table->json('recipients')->nullable();     // array of {name, email}
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_email_logs');
    }
};
