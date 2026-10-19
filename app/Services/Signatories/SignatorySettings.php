<?php

namespace App\Services\Signatories;

use App\Models\Setting;

/** The Signatories settings: stored in the settings table (key `signatories`), starting from config/signatories.php. One place, read everywhere. */
class SignatorySettings
{
    public const KEY = 'signatories';

    public static function get(?int $accountId = null): array
    {
        $accountId ??= (int) (auth()->user()->account_id ?? 1);
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $accountId)->value('tec_value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $defaults = config('signatories.defaults');

        return array_replace($defaults, array_intersect_key($saved, $defaults));
    }

    public static function save(array $data, ?int $accountId = null): array
    {
        $accountId ??= (int) (auth()->user()->account_id ?? 1);
        $clean = array_replace(self::get($accountId), array_intersect_key($data, config('signatories.defaults')));
        Setting::query()->updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($clean)]);

        return $clean;
    }

    /** The options SignatureProcessor understands, from the settings. */
    public static function processorOptions(array $s): array
    {
        return [
            'ink_color' => $s['ink_color'], 'output_width_px' => (int) $s['output_width_px'], 'padding_pct' => (float) $s['padding_pct'], 'sensitivity' => (float) $s['sensitivity'],
            'min_contrast' => (float) $s['min_contrast'], 'min_ink_coverage_pct' => (float) $s['min_ink_coverage_pct'], 'max_ink_coverage_pct' => (float) $s['max_ink_coverage_pct'],
            'min_input_px' => (int) $s['min_input_px'], 'max_input_px' => (int) $s['max_input_px'],
        ];
    }
}
