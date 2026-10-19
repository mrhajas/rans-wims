<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per driver (whatsapp / meta / sms). `config` holds every
     * driver-specific field (URL, endpoint, tokens, sender ID, ...) as a
     * single encrypted JSON blob — decrypted only inside the matching
     * GatewayDriver at send time, never rendered to the frontend in full
     * (settings pages read back only a masked version).
     */
    public function up(): void
    {
        Schema::create('wims_connect_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('driver')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable();
            $table->string('status')->default('disconnected');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wims_connect_gateways');
    }
};
