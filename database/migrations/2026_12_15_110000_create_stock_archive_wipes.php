<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock Archive: wiping deleted entries for good needs two different Super Admins (one starts it, the other confirms). The record of each wipe stays. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_archive_wipes')) {
            return;
        }
        Schema::create('stock_archive_wipes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->nullable()->index();
            $t->json('entry_ids');
            $t->json('refs');                                  // the references wiped (the entries themselves are gone afterwards)
            $t->unsignedInteger('entries_count')->default(0);
            $t->string('status', 12)->default('pending')->index(); // pending | completed | cancelled | rejected
            $t->unsignedBigInteger('initiated_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable(); // who confirmed, rejected or cancelled
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_archive_wipes');
    }
};
