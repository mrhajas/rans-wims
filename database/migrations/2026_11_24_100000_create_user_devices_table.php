<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Devices signed in per user: which sessions were admitted under the "devices per user" limit (see App\Services\Security\DeviceLimit). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_devices')) {
            return;
        }
        Schema::create('user_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('session_id', 100)->unique();
            $t->boolean('admitted')->default(false);
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->index(['user_id', 'admitted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
