<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Communication Rule Builder's storage. `scope_type` selects which of
     * role_name / warehouse_id / user_id is populated (global uses none).
     * Precedence at resolve time is user > project (warehouse_id) > role >
     * global, matching the analysis doc's documented precedence model — not
     * enforced by the schema itself, computed by RecipientResolver.
     */
    public function up(): void
    {
        Schema::create('wims_connect_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_key');
            $table->enum('scope_type', ['global', 'role', 'project', 'user'])->default('global');
            $table->string('role_name')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('channel');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_key', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wims_connect_rules');
    }
};
