<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (user, function) that IS subscribed — absence of a row means
     * not subscribed. Function keys come from App\Support\EmailFunctionRegistry.
     * This table is the single source of truth for who receives which system
     * email; EmailRecipientResolver is the only code that should read it.
     */
    public function up(): void
    {
        Schema::create('email_function_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('function_key');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'function_key']);
            $table->index('function_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_function_subscriptions');
    }
};
