<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Document Archive. Records carry the searchable metadata; files carry the per-attachment size/type metadata
    // the dashboard aggregates (so no storage scan is ever needed). files_count / *_bytes on a record are
    // denormalised counters kept in step by ArchiveRecord::refreshTotals().
    public function up(): void
    {
        Schema::create('archive_records', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 60);
            $table->string('subcategory', 100)->nullable();
            $table->string('document_type', 60)->nullable()->index();
            $table->string('source_type', 40)->nullable();
            $table->string('organization', 190)->nullable()->index();
            $table->string('sender', 190)->nullable();
            $table->string('recipient', 190)->nullable();
            $table->string('reference_number', 120)->nullable()->index();
            $table->text('keywords')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('department', 100)->nullable()->index();
            $table->date('document_date')->nullable()->index();
            $table->date('received_date')->nullable()->index();
            $table->date('expiry_date')->nullable();
            $table->string('confidentiality', 20)->default('Internal');
            $table->string('status', 20)->default('Active')->index();
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedBigInteger('original_bytes')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // deleted_at rides along as the last column so counts / GROUP BYs over live rows are answered from the index alone.
            $table->index(['category', 'deleted_at']);
            $table->index(['source_type', 'deleted_at']);
            $table->index(['expiry_date', 'deleted_at']);
            $table->index(['confidentiality', 'deleted_at']);
            $table->index(['created_at', 'deleted_at']);
            $table->index('updated_at');
            $table->index('files_count');
            $table->index('storage_bytes');
            $table->index('title');
            // Covering index: the dashboard totals are answered from the index alone, without reading the table.
            // (deleted_at goes last: leading with it makes the optimiser prefer it over the selective date/filter indexes.)
            $table->index(['files_count', 'original_bytes', 'storage_bytes', 'deleted_at'], 'archive_records_totals');
        });

        Schema::create('archive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_record_id')->constrained('archive_records')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('extension', 12)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->string('file_type', 20)->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('optimized_size')->nullable();
            $table->unsignedBigInteger('thumbnail_size')->nullable();
            $table->string('processing_status', 20)->default('not_applicable')->index();
            $table->char('checksum', 64)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['archive_record_id', 'deleted_at']);
            // Covering index for the per-file-type count/size aggregate on the dashboard.
            $table->index(['file_type', 'deleted_at', 'size_bytes', 'optimized_size', 'thumbnail_size'], 'archive_files_types');
        });

        Schema::create('archive_record_tags', function (Blueprint $table) {
            $table->foreignId('archive_record_id')->constrained('archive_records')->cascadeOnDelete();
            $table->string('tag', 60);
            $table->primary(['archive_record_id', 'tag']);
            $table->index('tag');
        });

        // Word-prefix search over the descriptive columns and attachment filenames. MariaDB/MySQL InnoDB FULLTEXT.
        DB::statement('ALTER TABLE archive_records ADD FULLTEXT archive_records_search (title, description, keywords, organization, sender, recipient, reference_number)');
        DB::statement('ALTER TABLE archive_files ADD FULLTEXT archive_files_name (original_name)');
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_record_tags');
        Schema::dropIfExists('archive_files');
        Schema::dropIfExists('archive_records');
    }
};
