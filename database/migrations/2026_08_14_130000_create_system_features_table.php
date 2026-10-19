<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately not account-scoped (no account_id column) — mirrors the
     * existing `settings` table's whole-system semantics (see get_settings()),
     * since Global Feature Control is a Super-Admin, install-wide switchboard.
     */
    public function up(): void
    {
        Schema::create('system_features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('module');
            $table->string('icon')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('route_prefixes')->nullable();
            $table->string('depends_on')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_features');
    }
};
