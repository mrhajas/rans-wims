<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per recipient per message — the actual send unit, and what the
     * History table lists. `name`/`phone` are copied in at send time rather
     * than FK'd to a Contact, since a recipient can be a raw typed-in number
     * that was never a Contact.
     */
    public function up(): void
    {
        Schema::create('bulk_sms_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_sms_message_id')->constrained('bulk_sms_messages')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone');
            $table->enum('status', ['Pending', 'Sending', 'Sent', 'Failed'])->default('Pending');
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_sms_recipients');
    }
};
