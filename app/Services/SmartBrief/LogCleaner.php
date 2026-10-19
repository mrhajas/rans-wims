<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Smart Brief keeps no data of its own: its timeline is read from WIMS records. The only things it writes are its own lines in the activity log
 * (log name "Smart Brief": a brief opened, a report previewed or exported, settings changed). This class removes ONLY those lines, never a record
 * of real work.
 *  - clearOwn(): the Clear button on My Smart Brief. Removes the signed-in person's own Smart Brief lines, EXCEPT the lines that record that
 *    they opened another person's brief (those are the access audit and stay until the automatic clear).
 *  - autoClear(): everything written by Smart Brief older than the number of days chosen in Smart Brief Settings (0 = never).
 */
class LogCleaner
{
    public const LOG = 'Smart Brief';

    /** Lines about opening somebody else's brief: kept by the manual Clear. */
    private function accessAudit($q)
    {
        return $q->where('subject_type', User::class)->whereNotNull('subject_id');
    }

    public function clearOwn(User $user): int
    {
        $q = DB::table('activity_log')->where('log_name', self::LOG)->where('account_id', $user->account_id)->where('causer_type', User::class)->where('causer_id', $user->id)
            ->where(function ($w) use ($user) {
                $w->whereNull('subject_id')->orWhere('subject_type', '!=', User::class)->orWhere('subject_id', $user->id);
            });
        $n = (int) $q->delete();
        if ($n) {
            log_activity('Smart Brief log cleared', ['lines_removed' => $n, 'by' => 'the person (Clear button)'], null, self::LOG);
        }

        return $n;
    }

    public function autoClear(?int $accountId = null): int
    {
        $accounts = $accountId ? [$accountId] : DB::table('accounts')->pluck('id')->all();
        $total = 0;
        foreach ($accounts as $account) {
            $days = (int) (Settings::get((int) $account)['auto_clear_days'] ?? 0);
            if ($days < 1) {
                continue;
            }
            $cut = now()->subDays($days)->toDateTimeString();
            $n = (int) DB::table('activity_log')->where('log_name', self::LOG)->where('account_id', $account)->where('created_at', '<', $cut)->where('description', '!=', 'Smart Brief log cleared')->delete();
            if ($n) {
                log_activity('Smart Brief log cleared', ['lines_removed' => $n, 'by' => 'automatic clear', 'older_than_days' => $days], null, self::LOG);
            }
            $total += $n;
        }

        return $total;
    }

    /** Runs the automatic clear at most once a day even where the scheduler (cron) is not set up. */
    public function autoClearIfDue(?int $accountId = null): void
    {
        if (Cache::add('smart-brief-autoclear-' . now()->format('Ymd') . '-' . (int) $accountId, 1, now()->endOfDay())) {
            try {
                $this->autoClear($accountId);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
