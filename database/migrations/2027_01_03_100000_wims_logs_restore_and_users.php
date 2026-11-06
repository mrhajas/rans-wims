<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WIMS Logs: which archived records were put back into their live log (they are then kept there and not archived again by the next run), and a flag for archives whose
 * index already carries the user of every record (needed to filter archived records by user).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wims_log_restores')) {
            Schema::create('wims_log_restores', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('archive_id')->index();
                $t->string('source', 30);
                $t->string('record_key', 64);
                $t->unsignedBigInteger('record_num')->nullable();
                $t->unsignedBigInteger('by_user_id')->nullable();
                $t->string('by_name')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['archive_id', 'record_key']);
                $t->index(['source', 'record_num']);
                $t->index(['source', 'record_key']);
            });
        }
        if (Schema::hasTable('wims_log_archives') && ! Schema::hasColumn('wims_log_archives', 'users_indexed')) {
            Schema::table('wims_log_archives', function (Blueprint $t) {
                $t->boolean('users_indexed')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wims_log_restores');
        if (Schema::hasTable('wims_log_archives') && Schema::hasColumn('wims_log_archives', 'users_indexed')) {
            Schema::table('wims_log_archives', fn (Blueprint $t) => $t->dropColumn('users_indexed'));
        }
    }
};
