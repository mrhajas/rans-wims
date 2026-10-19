<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in restriction: addresses a Super Admin blacklisted or whitelisted by hand, and the counters behind the automatic blocks
 * (wrong sign-ins per address, code requests per account). Written by App\Services\Security\AccessGuard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('security_ip_rules')) {
            Schema::create('security_ip_rules', function (Blueprint $t) {
                $t->id();
                $t->string('ip', 45)->unique();
                $t->string('type', 10)->index();            // black | white
                $t->string('reason', 190)->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedInteger('hits')->default(0);     // attempts refused because of a blacklist rule
                $t->timestamp('last_hit_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('security_counters')) {
            Schema::create('security_counters', function (Blueprint $t) {
                $t->id();
                $t->string('scope', 30);                     // login_fail | otp_send
                $t->string('subject', 120);                  // ip:1.2.3.4 | user:12:login
                $t->unsignedInteger('count')->default(0);
                $t->unsignedTinyInteger('level')->default(0);        // how many blocks this subject already had
                $t->timestamp('window_started_at')->nullable();
                $t->timestamp('last_at')->nullable();
                $t->timestamp('blocked_until')->nullable()->index();
                $t->timestamp('last_block_at')->nullable();
                $t->unsignedInteger('blocks_total')->default(0);
                $t->unsignedInteger('hits')->default(0);     // attempts refused while blocked
                $t->string('note', 190)->nullable();
                $t->timestamps();
                $t->unique(['scope', 'subject']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_counters');
        Schema::dropIfExists('security_ip_rules');
    }
};
