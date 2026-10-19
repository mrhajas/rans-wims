<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site Diary: one diary per project/site/day (project = a warehouse row, no new project table), each holding photos and
 * videos. Only metadata lives here; the files themselves live in Google Drive (or the private local disk until they are
 * synced). Composite indexes end in deleted_at so the soft-delete filter stays inside the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_diaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 30)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->date('diary_date');
            $table->foreignId('recorded_by')->constrained('users');
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('Submitted');
            // Denormalised counters so the list/dashboard never counts media rows.
            $table->unsignedInteger('photos_count')->default(0);
            $table->unsignedInteger('videos_count')->default(0);
            $table->timestamp('last_media_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delete_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'diary_date', 'deleted_at'], 'sd_project_date_idx');
            $table->index(['recorded_by', 'diary_date', 'deleted_at'], 'sd_user_date_idx');
            $table->index(['diary_date', 'deleted_at'], 'sd_date_idx');
            $table->index(['created_at', 'deleted_at'], 'sd_created_idx');
        });

        Schema::create('site_diary_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_diary_id')->constrained('site_diaries')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('media_type', 10); // photo | video
            $table->string('storage_provider', 20)->default('local'); // local | google_drive
            $table->string('drive_folder_id', 128)->nullable();
            $table->string('drive_file_id', 128)->nullable();
            $table->string('original_name', 255);
            $table->string('local_path', 500)->nullable(); // private staging copy, removed once synced to Drive
            $table->string('thumb_path', 500)->nullable();
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedBigInteger('optimized_size')->nullable(); // size of the thumbnail kept locally
            $table->string('compression_status', 20)->default('none'); // none | thumbnail | skipped
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('upload_status', 12)->default('pending'); // pending | uploading | uploaded | failed
            $table->string('upload_error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('client_uuid', 64)->nullable(); // lets a retried upload find its own row instead of duplicating
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_diary_id', 'media_type', 'deleted_at'], 'sdm_diary_type_idx');
            $table->index(['upload_status', 'deleted_at'], 'sdm_status_idx');
            $table->index(['media_type', 'created_at', 'deleted_at'], 'sdm_type_created_idx');
            $table->unique(['site_diary_id', 'client_uuid'], 'sdm_client_uuid_unique');
        });

        // Drive folder ids are remembered so a folder is created once and then reused, never looked up again.
        Schema::create('site_diary_drive_folders', function (Blueprint $table) {
            $table->id();
            $table->string('path_key', 191)->unique();
            $table->string('drive_folder_id', 128);
            $table->timestamps();
        });

        // Optional "a diary is expected every day" rule per project.
        Schema::create('site_diary_project_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->unique()->constrained('warehouses')->cascadeOnDelete();
            $table->boolean('require_daily')->default(false);
            $table->timestamps();
        });

        Schema::create('site_diary_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 40);
            $table->boolean('enabled')->default(false);
            $table->boolean('notify_bell')->default(true);
            $table->boolean('send_email')->default(false);
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('exclude_actor')->default(true);
            $table->string('send_time', 5)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_diary_notification_rules');
        Schema::dropIfExists('site_diary_project_rules');
        Schema::dropIfExists('site_diary_drive_folders');
        Schema::dropIfExists('site_diary_media');
        Schema::dropIfExists('site_diaries');
    }
};
