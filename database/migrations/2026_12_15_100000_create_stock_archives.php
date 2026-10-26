<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock Archive: a kept record of the whole company's stock (each warehouse / project by category), as a small PDF, with a history of what happened to it. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_archives')) {
            Schema::create('stock_archives', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();
                $t->string('title', 160);
                $t->string('kind', 20)->default('Scheduled');            // Scheduled (by WIMS) | Manual (by a person)
                $t->timestamp('generated_at')->nullable()->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable(); // null = WIMS itself
                $t->string('file_path', 255)->nullable();                // removed when the entry is deleted
                $t->unsignedBigInteger('file_size')->default(0);
                $t->unsignedInteger('warehouses_count')->default(0);
                $t->unsignedInteger('items_count')->default(0);
                $t->unsignedInteger('rows_count')->default(0);
                $t->decimal('total_value', 20, 2)->default(0);
                $t->timestamp('emailed_at')->nullable();
                $t->unsignedInteger('emailed_to')->default(0);
                $t->string('status', 12)->default('active')->index();    // active | deleted
                $t->timestamp('deleted_at')->nullable();
                $t->unsignedBigInteger('deleted_by_user_id')->nullable(); // null with a deleted_at = removed by WIMS (kept for the set number of days)
                $t->string('deleted_reason', 120)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('stock_archive_events')) {
            Schema::create('stock_archive_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('stock_archive_id')->index();
                $t->unsignedBigInteger('user_id')->nullable();           // null = WIMS
                $t->string('action', 24)->index();                       // generated, viewed, previewed, downloaded, emailed, email_failed, deleted, expired
                $t->string('note', 255)->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        // the permissions of the module (the Roles page lists them) and the Feature Control rows
        try {
            \Illuminate\Support\Facades\Artisan::call('permissions:sync');
        } catch (\Throwable $e) {
            // run `php artisan permissions:sync` by hand
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_archive_events');
        Schema::dropIfExists('stock_archives');
    }
};
