<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** RANS ID: an ID with no photograph can carry an avatar (a male or a female silhouette on the colour of the category); the card remembers which, so the avatar is never copied into a company record as if it were a photograph. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('idpass_cards', 'photo_avatar')) {
            Schema::table('idpass_cards', fn (Blueprint $t) => $t->string('photo_avatar', 8)->nullable()->after('photo_bg'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('idpass_cards', 'photo_avatar')) {
            Schema::table('idpass_cards', fn (Blueprint $t) => $t->dropColumn('photo_avatar'));
        }
    }
};
