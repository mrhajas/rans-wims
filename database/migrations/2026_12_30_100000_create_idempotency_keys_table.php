<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per form that has created something (see App\Support\IdempotencyGuard): a retried or double-clicked submission of the same form finds its row and gets the
// first result back instead of creating a second record. Rows are kept 30 days (the daily housekeeping removes older ones). Nothing else is touched.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('idempotency_keys')) {
            return;
        }
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('scope', 80);
            $table->string('key', 64);
            $table->char('request_hash', 64);
            $table->string('record_type', 120)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('status', 12)->default('processing'); // processing | done (only 'done' is ever committed)
            $table->timestamp('created_at')->nullable()->index();
            $table->unique(['user_id', 'scope', 'key'], 'idem_user_scope_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
