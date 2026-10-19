<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Delivery Logs become the log of everything WIMS sends out (SMS, email, WhatsApp, Meta): an email keeps its subject, its body and its attachment names. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wims_connect_logs', function (Blueprint $table) {
            $table->string('subject', 255)->nullable()->after('message');
            $table->longText('body_html')->nullable()->after('subject');
            $table->json('meta')->nullable()->after('body_html');
        });
    }

    public function down(): void
    {
        Schema::table('wims_connect_logs', function (Blueprint $table) {
            $table->dropColumn(['subject', 'body_html', 'meta']);
        });
    }
};
