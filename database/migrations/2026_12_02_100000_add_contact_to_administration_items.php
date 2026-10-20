<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An Administration item (and each line made from it) can be linked to a staff member OR to a contact (never both), so reports can follow what is paid for either. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['admin_items', 'admin_document_items'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'contact_id')) {
                Schema::table($t, fn (Blueprint $b) => $b->unsignedBigInteger('contact_id')->nullable()->after('staff_id')->index());
            }
        }
    }

    public function down(): void
    {
        foreach (['admin_items', 'admin_document_items'] as $t) {
            if (Schema::hasColumn($t, 'contact_id')) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn('contact_id'));
            }
        }
    }
};
