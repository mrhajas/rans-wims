<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A human-friendly, stamped-at-creation reference (RANS/{year}/{project
 * code}/{00001}) — separate from certificate_number, which stays the plain
 * per-project sequential integer everything else (numbering, uniqueness,
 * "previous certificate" lookups) is keyed on. The reference is a display
 * label only; nothing calculates against it, so it's safe to store as a
 * plain string rather than recompute it, and it never shifts even if the
 * project's code is edited later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_certificates', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('certificate_number');
        });
    }

    public function down(): void
    {
        Schema::table('project_certificates', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
