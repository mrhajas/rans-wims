<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's address + on/off state per channel — the "Communication
     * Channels" checkboxes on a user's WIMS Connect profile. Deliberately
     * not reusing a single phone column on `users`: a person's WhatsApp
     * number, their Meta-registered number, and their SMS number can
     * legitimately differ.
     */
    public function up(): void
    {
        Schema::create('wims_connect_recipient_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel');
            $table->string('address');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wims_connect_recipient_channels');
    }
};
