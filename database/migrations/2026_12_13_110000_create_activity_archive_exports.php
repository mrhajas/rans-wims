<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a PDF of a large part of the activity archive is made in the background, a few parts at a time, and handed over as one ZIP
        if (! Schema::hasTable('activity_archive_exports')) {
            Schema::create('activity_archive_exports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->json('filters')->nullable();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedSmallInteger('parts_total')->default(1);
                $table->unsignedSmallInteger('parts_done')->default(0);
                $table->unsignedBigInteger('cursor_id')->default(0); // the last archive row already in a finished part
                $table->string('status', 12)->default('queued')->index(); // queued | running | done | failed
                $table->string('file')->nullable(); // the finished zip, relative to storage/app
                $table->text('error')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_archive_exports');
    }
};
