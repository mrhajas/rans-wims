<?php

namespace App\Services\SiteDiary;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Every Site Diary limit and switch is a setting (never a constant): maximum photos / videos, whether videos are on,
 * file sizes, allowed types, Drive switches. Stored in the normal `settings` table under a `site_diary_` prefix.
 * Google credentials are the one secret: they are stored encrypted and are never sent to the browser.
 */
class SiteDiarySettings
{
    public const PREFIX = 'site_diary_';

    /** key => default. The type lists are comma separated extensions. */
    public const DEFAULTS = [
        'photos_enabled' => 1,
        'max_photos' => 15,
        'videos_enabled' => 0,
        'max_videos' => 1,
        'max_photo_mb' => 15,
        'max_video_mb' => 200,
        'photo_types' => 'jpg,jpeg,png,webp',
        'video_types' => 'mp4,mov,webm',
        'drive_enabled' => 0,
        'drive_root_folder_id' => '',
        'drive_auth_type' => 'service_account',
        'thumbnails' => 1,
        'downloads_allowed' => 1,
        'password_on_delete' => 1,
        'remarks_max' => 5000,
        'remarks_required' => 0,
        'require_daily' => 0,
        'capture_max_px' => 2048,
        // every diary must carry at least one photo or video before it counts as saved
        'require_media' => 1,
        // removing a photo / video from a diary also deletes the file itself (Google Drive Trash + the WIMS server)
        'delete_files_on_remove' => 1,
        // days after which a file is erased from Google Drive (0 = keep forever); the diary record and history stay
        'drive_retention_days' => 0,
    ];

    public const RETENTION_CHOICES = [0, 30, 90, 180, 365];

    /** What the server can really handle — admins pick from these only. */
    public const SUPPORTED_PHOTO_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    public const SUPPORTED_VIDEO_TYPES = ['mp4', 'mov', 'webm'];

    private ?array $cache = null;

    private ?int $accountId = null;

    public function forAccount(?int $accountId): self
    {
        $clone = clone $this;
        $clone->accountId = $accountId;
        $clone->cache = null;

        return $clone;
    }

    private function account(): int
    {
        return (int) ($this->accountId ?? optional(auth()->user())->account_id ?? getAccountId(1));
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = Setting::query()->where('account_id', $this->account())->where('tec_key', 'like', self::PREFIX.'%')->pluck('tec_value', 'tec_key');
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = $rows[self::PREFIX.$key] ?? null;
            $out[$key] = $raw === null ? $default : (is_int($default) ? (int) $raw : (string) $raw);
        }

        return $this->cache = $out;
    }

    public function get(string $key)
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function flag(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function set(string $key, $value): void
    {
        Setting::updateOrCreate(['tec_key' => self::PREFIX.$key, 'account_id' => $this->account()], ['tec_value' => (string) $value]);
        $this->cache = null;
    }

    public function types(string $media): array
    {
        $supported = $media === 'photo' ? self::SUPPORTED_PHOTO_TYPES : self::SUPPORTED_VIDEO_TYPES;
        $chosen = array_filter(array_map('trim', explode(',', strtolower((string) $this->get($media.'_types')))));

        return array_values(array_intersect($supported, $chosen));
    }

    /** Server ceiling for one uploaded file, in MB (PHP's own upload / post limits cap whatever the admin sets). */
    public static function serverMaxMb(): int
    {
        $toMb = function ($v) {
            $v = trim((string) $v);
            if ($v === '' || $v === '-1') {
                return PHP_INT_MAX;
            }
            $n = (float) $v;
            switch (strtolower(substr($v, -1))) {
                case 'g': $n *= 1024; break;
                case 'k': $n /= 1024; break;
                case 'm': break;
                default: $n /= 1048576;
            }

            return (int) floor($n);
        };
        $max = min($toMb(ini_get('upload_max_filesize')), $toMb(ini_get('post_max_size')));

        return $max === PHP_INT_MAX ? 2048 : max(1, $max);
    }

    public function maxMb(string $media): int
    {
        return max(1, min((int) $this->get('max_'.$media.'_mb'), self::serverMaxMb()));
    }

    public function enabled(string $media): bool
    {
        return $this->flag($media.'s_enabled') && \App\Services\FeatureManager::isEnabled('site_diary_'.$media.'s');
    }

    public function maxCount(string $media): int
    {
        return max(0, (int) $this->get('max_'.$media.'s'));
    }

    // ---- Google credentials (encrypted, write-only) ------------------------------------------------------------

    public function credentials(): ?array
    {
        $raw = Setting::query()->where('account_id', $this->account())->where('tec_key', self::PREFIX.'drive_credentials')->value('tec_value');
        if (! $raw) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function storeCredentials(?array $data): void
    {
        if ($data === null) {
            Setting::query()->where('account_id', $this->account())->where('tec_key', self::PREFIX.'drive_credentials')->delete();

            return;
        }
        Setting::updateOrCreate(
            ['tec_key' => self::PREFIX.'drive_credentials', 'account_id' => $this->account()],
            ['tec_value' => Crypt::encryptString(json_encode($data))]
        );
    }

    /** Safe description of the stored credential for the settings page — never the secret itself. */
    public function credentialSummary(): array
    {
        $c = $this->credentials();
        if (! $c) {
            return ['connected' => false, 'type' => null, 'identity' => null, 'awaiting_connect' => false, 'client_saved' => false];
        }
        $type = $c['type'] ?? null;

        return [
            // "connected" means a usable credential: a service account, or an OAuth client that has been authorised
            'connected' => $type === 'service_account' || ($type === 'oauth' && ! empty($c['refresh_token'])),
            'type' => $type,
            'identity' => $c['identity'] ?? ($type === 'oauth' || $type === 'oauth_client' ? ('…'.substr((string) ($c['client_id'] ?? ''), -12)) : ($c['client_email'] ?? null)),
            'awaiting_connect' => $type === 'oauth_client' || ($type === 'oauth' && empty($c['refresh_token'])),
            'client_saved' => in_array($type, ['oauth', 'oauth_client'], true) && ! empty($c['client_id']),
        ];
    }
}
