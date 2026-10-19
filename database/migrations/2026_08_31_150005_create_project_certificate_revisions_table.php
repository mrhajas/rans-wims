<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A full snapshot taken immediately before an issued certificate is
 * revised — never destroys history. `snapshot` carries the certificate's
 * own fields plus every line at the moment of revision, so a past version
 * remains fully reconstructable even though project_certificate_lines
 * itself only ever holds the CURRENT version's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_certificate_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained('project_certificates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['certificate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_certificate_revisions');
    }
};
