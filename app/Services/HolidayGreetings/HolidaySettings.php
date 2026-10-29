<?php

namespace App\Services\HolidayGreetings;

use App\Models\Setting;

/** The Holiday Greetings settings, soft-coded in the settings table. */
class HolidaySettings
{
    /** Seven days: the longest an automatic send can be set ahead of a holiday. */
    public const AUTO_MAX_MINUTES = 10080;

    public const DEFAULTS = [
        'holiday_hours_before' => '8',        // how long before the holiday begins the Super Admins are reminded
        'holiday_skip_weekends' => '1',       // a holiday on a Saturday or Sunday does not count
        'holiday_remind_email' => '1',
        'holiday_remind_sms' => '1',
        'holiday_remind_app' => '1',
        'holiday_signoff' => 'Management and Staff',
        'holiday_company' => 'RANS Company Limited',
        'holiday_auto_enabled' => '0',        // greetings send themselves (off until a Super Admin turns it on)
        'holiday_auto_minutes' => '60',       // how long before the holiday begins (minutes)
        'holiday_auto_bundle' => '',          // the bundle they go to; empty = the whole greeting list
        'holiday_asset_url' => '',            // the public address pictures in the emails are loaded from; empty = the address of WIMS
    ];

    public static function get(string $key): string
    {
        $v = get_settings($key);

        return ($v === null || $v === '') ? (self::DEFAULTS[$key] ?? '') : (string) $v;
    }

    public static function hoursBefore(): int
    {
        return max(1, min(72, (int) self::get('holiday_hours_before')));
    }

    public static function skipWeekends(): bool
    {
        return self::get('holiday_skip_weekends') === '1';
    }

    public static function remindEmail(): bool
    {
        return self::get('holiday_remind_email') === '1';
    }

    public static function remindSms(): bool
    {
        return self::get('holiday_remind_sms') === '1';
    }

    public static function remindApp(): bool
    {
        return self::get('holiday_remind_app') === '1';
    }

    /** @return array{enabled:bool,minutes:int,bundle_id:?int} */
    public static function autoDefaults(): array
    {
        $b = (int) self::get('holiday_auto_bundle');

        return ['enabled' => self::get('holiday_auto_enabled') === '1', 'minutes' => (int) self::get('holiday_auto_minutes'), 'bundle_id' => $b > 0 ? $b : null];
    }

    public static function signoff(): string
    {
        return trim(self::get('holiday_signoff')) ?: self::DEFAULTS['holiday_signoff'];
    }

    public static function company(): string
    {
        return trim(self::get('holiday_company')) ?: self::DEFAULTS['holiday_company'];
    }

    /** The public address of WIMS: a background picture in an email is loaded by the reader's mail app from here, so it must be reachable from the internet. */
    public static function assetBase(): string
    {
        $v = trim(self::get('holiday_asset_url'));

        return rtrim($v !== '' ? $v : (string) config('app.url'), '/');
    }

    public static function all(): array
    {
        $o = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $o[$k] = self::get($k);
        }

        return $o;
    }

    public static function save(array $values, int $accountId): void
    {
        foreach ($values as $k => $v) {
            Setting::updateOrCreate(['tec_key' => $k, 'account_id' => $accountId], ['tec_value' => (string) $v]);
        }
    }
}
