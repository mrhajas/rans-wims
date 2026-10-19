<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The workflow pages can now send on every channel (app bell, SMS, email, WhatsApp, Meta), address everyone with a number, and stay inside
 * the record's own project; and Recipients becomes the contact book for email too (every active user starts with the email of their login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_notification_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_notification_rules', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('event_key');
                $table->boolean('bell_enabled')->default(true)->after('notify_step_owner');
                $table->boolean('whatsapp_enabled')->default(false)->after('email_enabled');
                $table->boolean('meta_enabled')->default(false)->after('whatsapp_enabled');
                $table->text('whatsapp_template')->nullable()->after('email_show_details');
                $table->text('meta_template')->nullable()->after('whatsapp_template');
                $table->boolean('notify_everyone')->default(false)->after('notify_step_owner');
                $table->boolean('limit_to_project')->default(false)->after('notify_everyone');
            }
        });

        // Recipients: an email contact for every active user (the email of their login), so the contact book is complete
        DB::table('users')->whereNull('deleted_at')->where('is_active', true)->whereNotNull('email')->where('email', '!=', '')->orderBy('id')->chunkById(300, function ($users) {
            foreach ($users as $u) {
                $has = DB::table('wims_connect_recipient_channels')->where('user_id', $u->id)->where('channel', 'email')->exists();
                if (! $has) {
                    DB::table('wims_connect_recipient_channels')->insert([
                        'user_id' => $u->id, 'channel' => 'email', 'address' => $u->email, 'is_enabled' => true, 'is_verified' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('wims_connect_recipient_channels')->where('channel', 'email')->delete();
        Schema::table('workflow_notification_rules', function (Blueprint $table) {
            foreach (['limit_to_project', 'notify_everyone', 'meta_template', 'whatsapp_template', 'meta_enabled', 'whatsapp_enabled', 'bell_enabled', 'is_enabled'] as $c) {
                if (Schema::hasColumn('workflow_notification_rules', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
