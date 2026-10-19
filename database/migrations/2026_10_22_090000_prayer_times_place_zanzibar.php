<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The Prayer Times place is now Zanzibar (Stone Town, East Africa Time). Only a setting that still holds the old Dar es Salaam
 * starting point is moved, so a place somebody chose on purpose is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::query()->where('tec_key', 'prayer_config')->get()->each(function ($row) {
            $c = json_decode((string) $row->tec_value, true);
            if (! is_array($c) || ($c['city'] ?? null) !== 'Dar es Salaam') {
                return;
            }
            $row->tec_value = json_encode(array_replace($c, ['city' => 'Zanzibar', 'lat' => -6.1659, 'lng' => 39.2026, 'timezone' => 'Africa/Dar_es_Salaam']));
            $row->save();
        });
    }

    public function down(): void
    {
        // nothing to undo
    }
};
