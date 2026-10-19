<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Face login hardening. A person's face template is not a secret (a photo of them is enough to compute one), so on its own it can be
 * posted from anywhere. Face login is therefore tied to DEVICES: each device a person set Face ID up on holds a private key that
 * cannot be exported from the browser, and every face sign-in must be signed with it. `strikes` makes repeated lock-outs last longer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('device_id', 36)->unique();       // random id the browser remembers
            $table->text('public_key');                     // base64 SPKI of the device's ECDSA P-256 key
            $table->string('label', 120)->nullable();       // "Chrome on macOS"
            $table->string('registered_ip', 64)->nullable();
            $table->string('registered_agent', 190)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('face_templates', function (Blueprint $table) {
            $table->unsignedSmallInteger('strikes')->default(0)->after('failed_attempts');   // how many times it has been locked
            $table->timestamp('last_failed_at')->nullable()->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('face_templates', function (Blueprint $table) {
            $table->dropColumn(['strikes', 'last_failed_at']);
        });
        Schema::dropIfExists('face_devices');
    }
};
