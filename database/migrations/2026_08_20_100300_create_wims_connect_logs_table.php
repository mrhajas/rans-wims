<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per attempted send — the Delivery Logs list. `idempotency_key`
     * (event key + source type/id + recipient + channel) is unique so a
     * retried job or a re-fired listener can never produce a duplicate
     * outbound message; the send path upserts on this key instead of
     * inserting blindly.
     */
    public function up(): void
    {
        Schema::create('wims_connect_logs', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('event_key');
            $table->nullableMorphs('source');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('channel');
            $table->text('message')->nullable();
            $table->enum('status', ['QUEUED', 'SENDING', 'SENT', 'DELIVERED', 'FAILED', 'RETRYING'])->default('QUEUED');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'status']);
            $table->index(['channel', 'status']);
            $table->index('recipient_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wims_connect_logs');
    }
};
