<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Asset Type is now a two-option list: "Fixed Asset" / "UnFixed Asset".
     * Before, it was a free-text "bank" (seeded with 28 names such as Sedan,
     * Laptop, Land ...). Only types that are unambiguously immovable are
     * mapped; everything else is left exactly as it was (the edit form shows
     * a legacy value as an extra option so the record can still be saved).
     */
    private const TO_FIXED = ['land', 'building', 'warehouse'];

    public function up(): void
    {
        DB::table('assets')
            ->whereIn(DB::raw('LOWER(TRIM(asset_type))'), self::TO_FIXED)
            ->update(['asset_type' => 'Fixed Asset']);
    }

    public function down(): void
    {
        // The original free-text value is not retained; nothing to restore.
    }
};
