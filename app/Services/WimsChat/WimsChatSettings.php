<?php

namespace App\Services\WimsChat;

use App\Models\Setting;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\SiteDiary\SiteDiarySettings;

/**
 * Every WIMSChat knob. Stored in the shared `settings` table (prefix "wimschat_", one row per key per account) — the
 * values below are only the DEFAULTS used until an administrator changes them on the WIMSChat settings page. Structured
 * values (file policies, reactions, statuses, role quotas, notification events) are stored as JSON.
 */
class WimsChatSettings
{
    public const PREFIX = 'wimschat_';

    /** Scalar defaults: the type of the default decides how the stored text is read back. */
    public const DEFAULTS = [
        'max_file_mb' => 3,
        'daily_quota_default_mb' => 25,
        'image_compress' => 0,
        'image_max_px' => 2048,
        'edit_window_minutes' => 15,       // 0 = no limit
        'delete_window_minutes' => 0,      // 0 = no limit
        'max_message_chars' => 4000,
        'group_max_members' => 50,
        'nudge_cooldown_seconds' => 60,
        'typing_ttl_seconds' => 8,
        'active_within_seconds' => 180,    // heartbeat seen this recently = active
        'away_timeout_minutes' => 30,      // …seen within this = away (idle); older = offline
        'show_last_seen' => 1,
        'allow_manual_status' => 1,
        'read_receipts' => 1,
        'poll_open_ms' => 3500,
        'poll_idle_ms' => 30000,
        'page_size' => 40,
        'max_files_per_message' => 5,
        'group_invite_required' => 1,      // people added to a group must accept before they can enter it
        'voice_enabled' => 1,
        'voice_max_seconds' => 30,
        'voice_max_kbps' => 192,           // generous ceiling used to sanity-check the size of a voice note
        'attachment_retention_days' => 90, // files / images / voice notes older than this are erased (0 = keep forever)
        'message_retention_days' => 90,    // messages older than this are deleted for good (0 = keep forever)
        'search_min_chars' => 2,
        'message_preview_chars' => 90,
    ];

    /** JSON defaults. */
    public const JSON_DEFAULTS = [
        'daily_quota_roles' => ['Super Admin' => 500],
        'blocked_extensions' => ['exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'sh', 'js', 'jar', 'vbs', 'ps1', 'php', 'phtml', 'dll', 'apk'],
        'reactions' => ['👍', '❤️', '😂', '😮', '😢', '🎉'],
        'statuses' => [
            ['key' => 'online', 'label' => 'Online', 'color' => '#16a34a', 'selectable' => true, 'appears_as' => 'online', 'quiet' => false],
            ['key' => 'away', 'label' => 'Away', 'color' => '#f59e0b', 'selectable' => true, 'appears_as' => 'away', 'quiet' => false],
            ['key' => 'busy', 'label' => 'Busy', 'color' => '#ef4444', 'selectable' => true, 'appears_as' => 'busy', 'quiet' => false],
            ['key' => 'dnd', 'label' => 'Do Not Disturb', 'color' => '#b91c1c', 'selectable' => true, 'appears_as' => 'dnd', 'quiet' => true],
            ['key' => 'invisible', 'label' => 'Invisible', 'color' => '#94a3b8', 'selectable' => true, 'appears_as' => 'offline', 'quiet' => false],
            ['key' => 'offline', 'label' => 'Offline', 'color' => '#94a3b8', 'selectable' => false, 'appears_as' => 'offline', 'quiet' => false],
        ],
        'themes' => ['ocean', 'teal', 'violet', 'rose', 'sunset', 'forest'],
        'notify_events' => ['offline_message' => true, 'mention' => true, 'reply' => true, 'nudge' => true, 'group' => true, 'attachment' => false],
        'notify_email_events' => [],
        'file_policies' => [
            'images' => ['label' => 'Images', 'enabled' => true, 'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'max_mb' => null, 'preview' => true, 'download' => true],
            'pdf' => ['label' => 'PDF', 'enabled' => true, 'extensions' => ['pdf'], 'mimes' => ['application/pdf'], 'max_mb' => null, 'preview' => true, 'download' => true],
            'documents' => ['label' => 'Documents', 'enabled' => true, 'extensions' => ['doc', 'docx', 'odt', 'rtf', 'txt'], 'mimes' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text', 'application/rtf', 'text/rtf', 'text/plain', 'application/zip', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'], 'max_mb' => null, 'preview' => false, 'download' => true],
            'spreadsheets' => ['label' => 'Spreadsheets', 'enabled' => true, 'extensions' => ['xls', 'xlsx', 'ods', 'csv'], 'mimes' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet', 'text/csv', 'text/plain', 'application/csv', 'application/zip', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'], 'max_mb' => null, 'preview' => false, 'download' => true],
            'presentations' => ['label' => 'Presentations', 'enabled' => true, 'extensions' => ['ppt', 'pptx', 'odp'], 'mimes' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.oasis.opendocument.presentation', 'application/zip', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'], 'max_mb' => null, 'preview' => false, 'download' => true],
            'archives' => ['label' => 'Archives', 'enabled' => false, 'extensions' => ['zip'], 'mimes' => ['application/zip', 'application/x-zip-compressed'], 'max_mb' => null, 'preview' => false, 'download' => true],
            'voice' => ['label' => 'Voice notes', 'enabled' => true, 'extensions' => ['webm', 'ogg', 'm4a', 'mp4', 'mp3', 'wav'], 'mimes' => ['audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mp4', 'audio/x-m4a', 'video/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'application/octet-stream'], 'max_mb' => null, 'preview' => true, 'download' => true],
            'video' => ['label' => 'Video', 'enabled' => false, 'extensions' => ['mp4', 'webm', 'mov'], 'mimes' => ['video/mp4', 'video/webm', 'video/quicktime'], 'max_mb' => null, 'preview' => false, 'download' => true],
        ],
    ];

    /** Personal (per-user) defaults, overridable by each person within what the admin allows. */
    public const USER_DEFAULTS = [
        'sound' => true,
        'desktop' => false,
        'preview' => true,
        'enter_to_send' => true,
        'read_receipts' => true,
        'show_last_seen' => true,
        'show_online' => true,
        'typing_visible' => true,
        'auto_preview_media' => true,
        'density' => 'comfortable', // comfortable | compact
        'theme' => 'teal',
    ];

    private ?array $cache = null;

    private ?int $accountId = null;

    public function forAccount(?int $accountId): self
    {
        $clone = clone $this;
        $clone->accountId = $accountId;
        $clone->cache = null;

        return $clone;
    }

    private function account(): ?int
    {
        return $this->accountId ?? optional(auth()->user())->account_id ?? 1;
    }

    private function rows(): array
    {
        return $this->cache ??= Setting::query()->where('account_id', $this->account())
            ->where('tec_key', 'like', self::PREFIX.'%')->pluck('tec_value', 'tec_key')->all();
    }

    public function get(string $key)
    {
        $raw = $this->rows()[self::PREFIX.$key] ?? null;
        if (array_key_exists($key, self::JSON_DEFAULTS)) {
            $decoded = $raw === null ? null : json_decode($raw, true);

            return $decoded === null ? self::JSON_DEFAULTS[$key] : $decoded;
        }
        $default = self::DEFAULTS[$key] ?? null;
        if ($raw === null) {
            return $default;
        }

        return is_int($default) ? (int) $raw : (string) $raw;
    }

    public function flag(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function set(string $key, $value): void
    {
        $text = array_key_exists($key, self::JSON_DEFAULTS) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) (is_bool($value) ? (int) $value : $value);
        Setting::updateOrCreate(['tec_key' => self::PREFIX.$key, 'account_id' => $this->account()], ['tec_value' => $text]);
        $this->cache = null;
    }

    /** Everything the settings page edits. */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $_) {
            $out[$k] = $this->get($k);
        }
        foreach (self::JSON_DEFAULTS as $k => $_) {
            $out[$k] = $this->get($k);
        }

        return $out;
    }

    // ---- files -----------------------------------------------------------------------------------------------

    /** Hard ceiling from PHP's own upload / post limits. */
    public static function serverMaxMb(): int
    {
        return SiteDiarySettings::serverMaxMb();
    }

    /** Category policies with the effective max size filled in. */
    public function policies(): array
    {
        $global = max(1, min((int) $this->get('max_file_mb'), self::serverMaxMb()));
        $out = [];
        foreach ((array) $this->get('file_policies') as $key => $p) {
            $p['extensions'] = array_values(array_unique(array_map('strtolower', (array) ($p['extensions'] ?? []))));
            $p['mimes'] = array_values(array_unique(array_map('strtolower', (array) ($p['mimes'] ?? []))));
            $cap = ($p['max_mb'] ?? null) ? (int) $p['max_mb'] : $global;
            $p['max_mb_effective'] = max(1, min($cap, self::serverMaxMb()));
            $out[$key] = $p;
        }

        return $out;
    }

    public function blockedExtensions(): array
    {
        return array_values(array_map('strtolower', (array) $this->get('blocked_extensions')));
    }

    public function maxFileBytes(): int
    {
        return max(1, min((int) $this->get('max_file_mb'), self::serverMaxMb())) * 1048576;
    }

    public function categoryFor(string $extension): ?string
    {
        $extension = strtolower($extension);
        if (in_array($extension, $this->blockedExtensions(), true)) {
            return null;
        }
        foreach ($this->policies() as $key => $p) {
            if (! empty($p['enabled']) && in_array($extension, $p['extensions'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** Daily upload allowance of a person, in bytes: the best quota among their roles, else the default. */
    public function dailyQuotaBytes(User $user): int
    {
        $roles = (array) $this->get('daily_quota_roles');
        $best = null;
        foreach ($user->roles->pluck('name') as $role) {
            if (isset($roles[$role]) && $roles[$role] !== '' && $roles[$role] !== null) {
                $best = max((float) $best, (float) $roles[$role]);
            }
        }
        $mb = $best ?? (float) $this->get('daily_quota_default_mb');

        return (int) round($mb * 1048576);
    }

    // ---- statuses / reactions ----------------------------------------------------------------------------------

    public function statuses(): array
    {
        $list = (array) $this->get('statuses');

        return array_values(array_filter($list, fn ($s) => ! empty($s['key'])));
    }

    public function statusMap(): array
    {
        $out = [];
        foreach ($this->statuses() as $s) {
            $out[$s['key']] = $s;
        }

        return $out;
    }

    public function reactions(): array
    {
        return FeatureManager::isEnabled('wimschat_reactions') ? array_values((array) $this->get('reactions')) : [];
    }

    /** Bytes a voice note of the allowed length can reasonably occupy (so a long recording cannot sneak in with a short claimed duration). */
    public function voiceMaxBytes(): int
    {
        $sec = max(1, (int) $this->get('voice_max_seconds')) + 2;

        return (int) ($sec * max(16, (int) $this->get('voice_max_kbps')) * 125) + 65536;
    }

    public function voiceOn(): bool
    {
        return $this->flag('voice_enabled') && FeatureManager::isEnabled('wimschat_voice');
    }

    public function userDefaults(): array
    {
        return self::USER_DEFAULTS;
    }
}
