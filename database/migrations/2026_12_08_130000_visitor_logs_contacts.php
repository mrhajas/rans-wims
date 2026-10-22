<?php

use App\Models\Visitor;
use App\Services\VisitorLogs\VisitorContacts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Visitor Logs: visitors and the senders of incoming items are Contacts of the category "Visitors". Visitors that exist already are linked to a Contact. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visitors') && ! Schema::hasColumn('visitors', 'contact_id')) {
            Schema::table('visitors', fn (Blueprint $t) => $t->unsignedBigInteger('contact_id')->nullable()->index());
        }
        if (Schema::hasTable('visitor_incoming_items') && ! Schema::hasColumn('visitor_incoming_items', 'sender_contact_id')) {
            Schema::table('visitor_incoming_items', fn (Blueprint $t) => $t->unsignedBigInteger('sender_contact_id')->nullable()->index());
        }
        if (Schema::hasTable('visitors')) {
            Visitor::withoutGlobalScopes()->whereNull('contact_id')->each(fn ($v) => VisitorContacts::link($v));
        }
    }

    public function down(): void
    {
        foreach (['visitors' => 'contact_id', 'visitor_incoming_items' => 'sender_contact_id'] as $table => $col) {
            if (Schema::hasColumn($table, $col)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
