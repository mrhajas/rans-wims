<?php

namespace App\Services\MrReminders;

use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;

/** Who is told about a Material Request reminder and through which channels: soft-coded in the settings table (Super Admin role until somebody changes it). */
class ReminderSettings
{
    public const DEFAULTS = ['mrr_roles' => '["Super Admin"]', 'mrr_users' => '[]', 'mrr_email' => '1', 'mrr_sms' => '1', 'mrr_app' => '1'];

    /** Sample runs only: tell exactly these users instead of the saved roles and users. Never set by the web pages. */
    public static ?array $onlyUserIds = null;

    /** Sample runs only: the email and mobile used for everybody told, instead of the ones on their records. @var array{email?:string,sms?:string}|null */
    public static ?array $addresses = null;

    /** Sample runs only: leave the SMS out (a re-run to fix the email should not text the same phone again). */
    public static bool $noSms = false;

    public static function get(string $key): string
    {
        $v = get_settings($key);

        return ($v === null || $v === '') ? self::DEFAULTS[$key] : (string) $v;
    }

    /** @return array<int,string> */
    public static function roles(): array
    {
        return array_values(array_filter((array) json_decode(self::get('mrr_roles'), true), 'is_string'));
    }

    /** @return array<int,int> */
    public static function userIds(): array
    {
        return array_values(array_map('intval', array_filter((array) json_decode(self::get('mrr_users'), true), 'is_numeric')));
    }

    public static function email(): bool
    {
        return self::get('mrr_email') === '1';
    }

    public static function sms(): bool
    {
        return self::$noSms ? false : self::get('mrr_sms') === '1';
    }

    public static function app(): bool
    {
        return self::get('mrr_app') === '1';
    }

    /** Every active user who holds one of the chosen roles or was chosen by name. @return \Illuminate\Support\Collection<int,User> */
    public static function recipients(int $accountId)
    {
        $roles = self::$onlyUserIds !== null ? [] : self::roles();
        $ids = self::$onlyUserIds ?? self::userIds();

        return User::withoutGlobalScope('account')->where('account_id', $accountId)->where('is_active', true)->whereNull('disabled_at')->with('staff')->orderBy('name')->get()
            ->filter(fn (User $u) => in_array($u->id, $ids, true) || ($roles && $u->hasAnyRole($roles)))->values();
    }

    public static function forPage(int $accountId): array
    {
        return [
            'roles' => self::roles(), 'users' => self::userIds(), 'email' => self::email(), 'sms' => self::sms(), 'app' => self::app(),
            'roleOptions' => Role::query()->orderBy('name')->pluck('name')->values(),
            'userOptions' => User::withoutGlobalScope('account')->where('account_id', $accountId)->where('is_active', true)->whereNull('disabled_at')->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ];
    }

    public static function save(array $values, int $accountId): void
    {
        foreach ($values as $k => $v) {
            Setting::updateOrCreate(['tec_key' => $k, 'account_id' => $accountId], ['tec_value' => (string) $v]);
        }
    }
}
