<?php

namespace App\Services\Qr;

use App\Models\Setting;

/** The QR code settings: one row of the settings table (key `qr_codes`), starting from config/qr.php. */
class QrSettings
{
    public const KEY = 'qr_codes';

    private static ?array $memo = null;

    public static function get(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $account = (int) (auth()->user()->account_id ?? 1);
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $account)->value('tec_value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $defaults = config('qr.defaults');

        return self::$memo = self::clean(array_replace($defaults, array_intersect_key($saved, $defaults)));
    }

    public static function save(array $data): array
    {
        $account = (int) (auth()->user()->account_id ?? 1);
        $clean = self::clean(array_replace(self::get(), array_intersect_key($data, config('qr.defaults'))));
        Setting::query()->updateOrCreate(['tec_key' => self::KEY, 'account_id' => $account], ['tec_value' => json_encode($clean)]);
        self::$memo = null;

        return $clean;
    }

    public static function forget(): void
    {
        self::$memo = null;
    }

    /** Keep every value inside what the code and the page can really use. */
    private static function clean(array $s): array
    {
        $s['enabled'] = (bool) $s['enabled'];
        foreach (['on_prints', 'caption', 'show_company', 'frame'] as $k) {
            $s[$k] = (bool) $s[$k];
        }
        $s['preview'] = in_array($s['preview'], ['small', 'medium', 'large'], true) ? $s['preview'] : 'medium';
        $s['print_mm'] = max(25, min(80, (int) $s['print_mm']));
        $s['copies'] = max(1, min(60, (int) $s['copies']));
        $s['columns'] = max(1, min(6, (int) $s['columns']));
        $s['error_correction'] = in_array($s['error_correction'], ['L', 'M', 'Q', 'H'], true) ? $s['error_correction'] : 'Q';
        $s['disabled_kinds'] = array_values(array_intersect(array_map('strval', (array) ($s['disabled_kinds'] ?? [])), array_keys(\App\Support\Qr\QrRegistry::all())));
        $s['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $s['color']) ? strtolower($s['color']) : '#111827';

        return $s;
    }
}
