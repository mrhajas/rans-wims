<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RANS ID: the background behind the photograph of an ID is a setting of the category (white for Staff IDs, blue for Aluminium, yellow orange for Carpenter).
 * A card keeps the cut-out of its photograph (transparent PNG) so the colour can be changed later without going back to a browser, and the colour its photograph was made with.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('idpass_categories', 'photo_background')) {
            Schema::table('idpass_categories', fn (Blueprint $t) => $t->string('photo_background', 7)->nullable()->after('arrow_color'));
        }
        Schema::table('idpass_cards', function (Blueprint $t) {
            if (! Schema::hasColumn('idpass_cards', 'photo_cutout')) {
                $t->string('photo_cutout')->nullable()->after('photo_original');
            }
            if (! Schema::hasColumn('idpass_cards', 'photo_bg')) {
                $t->string('photo_bg', 7)->nullable()->after('photo_cutout');
            }
        });
        foreach (['staff' => '#ffffff', 'aluminium' => '#1e6fd9', 'carpenter' => '#ffa800'] as $key => $hex) {
            DB::table('idpass_categories')->where('key', $key)->whereNull('photo_background')->update(['photo_background' => $hex]);
        }
    }

    public function down(): void
    {
        Schema::table('idpass_cards', fn (Blueprint $t) => $t->dropColumn(['photo_cutout', 'photo_bg']));
        Schema::table('idpass_categories', fn (Blueprint $t) => $t->dropColumn('photo_background'));
    }
};
