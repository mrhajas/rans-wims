<?php

namespace App\Services;

use App\Models\ArchiveNotificationRule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

/**
 * Confidential / Restricted Archive content needs a recent password confirmation (like "sudo"): the user types their
 * own WIMS password and, for UNLOCK_MINUTES, may open restricted records and their files. The Archive Tree adds a
 * stricter per-folder prompt on top. Everything here lives in the session only.
 */
class ArchiveRestrictedAccess
{
    public const UNLOCK_MINUTES = 15;

    private const FAIL_LIMIT = 3;

    public static function isUnlocked(): bool
    {
        return (int) session('archive_restricted_until', 0) > time();
    }

    public static function unlock(): void
    {
        session(['archive_restricted_until' => time() + self::UNLOCK_MINUTES * 60]);
    }

    /**
     * Checks the password. On success starts the unlock window; on failure logs FAILED_REAUTH and, after
     * FAIL_LIMIT misses in 15 minutes, notifies whoever the "Failed unlock attempts" rule says.
     */
    public static function attempt(Request $request, User $user, string $folder = ''): bool
    {
        if (! Hash::check((string) $request->input('password'), $user->password)) {
            ArchiveAccessLogger::logEvent($user, 'FAILED_REAUTH', ['folder' => $folder ?: 'restricted content']);
            self::checkFailureLimit($user);

            return false;
        }

        self::unlock();
        ArchiveAccessLogger::logEvent($user, 'FOLDER_UNLOCK', ['folder' => $folder ?: 'restricted content']);
        ArchiveNotifier::event(ArchiveNotificationRule::FOLDER_UNLOCKED, $user, null, ['folder' => $folder ?: 'restricted content']);

        return true;
    }

    private static function checkFailureLimit(User $user): void
    {
        $misses = Activity::query()
            ->where('log_name', ArchiveAccessLogger::LOG_NAME)
            ->where('event', 'failed_reauth')
            ->where('causer_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($misses >= self::FAIL_LIMIT && Cache::add('archive_failed_unlock_' . $user->id, 1, 15 * 60)) {
            ArchiveNotifier::event(ArchiveNotificationRule::FAILED_UNLOCK, $user, null, ['misses' => $misses]);
        }
    }
}
