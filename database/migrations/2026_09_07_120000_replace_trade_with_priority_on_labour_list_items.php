<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `trade` was a free-text, optional field — replaced outright with a
     * fixed-choice `priority` (Low/Normal/High/Extreme, default Normal) per
     * an explicit request to change the field's purpose, not add alongside
     * it. Old trade text (a skill/trade descriptor) isn't a valid priority
     * value, so rows are backfilled to the default rather than migrated —
     * an additive add-then-drop rather than renameColumn()+change(), since
     * no other migration in this app relies on doctrine/dbal for a
     * combined rename+retype and this avoids being the first to need it.
     */
    public function up(): void
    {
        Schema::table('labour_list_items', function (Blueprint $table) {
            $table->string('priority', 16)->default('Normal')->after('trade');
        });

        DB::table('labour_list_items')->update(['priority' => 'Normal']);

        Schema::table('labour_list_items', function (Blueprint $table) {
            $table->dropColumn('trade');
        });
    }

    public function down(): void
    {
        Schema::table('labour_list_items', function (Blueprint $table) {
            $table->string('trade', 100)->nullable()->after('payee_name');
        });

        Schema::table('labour_list_items', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
