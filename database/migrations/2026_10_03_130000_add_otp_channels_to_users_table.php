<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email / SMS one-time codes as additional two-factor methods, next to the
     * existing authenticator app (two_factor_secret). A method is "on" once its
     * *_enabled_at is set; the phone is the number the user verified for SMS.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_email_enabled_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('two_factor_sms_enabled_at')->nullable()->after('two_factor_email_enabled_at');
            $table->string('two_factor_phone', 32)->nullable()->after('two_factor_sms_enabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_email_enabled_at', 'two_factor_sms_enabled_at', 'two_factor_phone']);
        });
    }
};
