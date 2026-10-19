<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctAlert;
use App\Models\Accounts\AcctTransaction;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use App\Support\Accounts\AcctPermissions;
use App\Support\Accounts\Money;
use App\Support\ReferenceLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Financial alerts. One alert per unresolved condition: raising the same condition again only counts it (and, after the cooldown, re-notifies),
 * it never piles up a new alert each time. Notifications go through the WIMS Alerts page of the module (who is told, which channels).
 */
class AcctAlerts
{
    private const NOTIFY = ['balance' => 'balance_alert', 'reconciliation' => 'reconciliation_alert', 'currency' => 'rate_alert', 'security' => 'security_alert', 'transfer' => 'approval_needed', 'transaction' => 'unusual_transaction'];

    /**
     * @param array{account_id?:int, subject_type?:string, subject_id?:int, amount?:string, currency?:string, dedupe?:string, notify_event?:string, trigger?:string} $o
     */
    public static function raise(string $category, string $type, string $severity, string $title, ?string $description = null, array $o = []): ?AcctAlert
    {
        try {
            $key = $o['dedupe'] ?? ($type . '-' . ($o['account_id'] ?? '0') . '-' . ($o['subject_id'] ?? '0'));
            $open = AcctAlert::query()->where('dedupe_key', $key)->whereIn('status', ['open', 'acknowledged'])->latest('id')->first();
            $cool = (int) AcctSettings::get()['alert_cooldown_hours'];
            if ($open) {
                $renotify = $open->last_seen_at && $open->last_seen_at->lt(now()->subHours($cool));
                $open->forceFill(['occurrences' => $open->occurrences + 1, 'last_seen_at' => now()])->save();
                if ($renotify) {
                    self::notify($open, $o['notify_event'] ?? null, $o['approver_permission'] ?? null);
                }

                return $open;
            }
            $a = self::insert(self::attrs($key, $category, $type, $severity, $title, $description, $o));
            self::notify($a, $o['notify_event'] ?? null, $o['approver_permission'] ?? null);

            return $a;
        } catch (\Throwable $e) {
            Log::error('Accounts alert failed', ['type' => $type, 'error' => $e->getMessage()]);

            return null; // an alert must never break a financial operation
        }
    }

    /** The columns of a new alert. */
    private static function attrs(string $key, string $category, string $type, string $severity, string $title, ?string $description, array $o): array
    {
        return [
            'dedupe_key' => $key, 'category' => $category, 'type' => $type, 'severity' => $severity, 'title' => $title, 'description' => $description,
            'account_id' => $o['account_id'] ?? null, 'subject_type' => $o['subject_type'] ?? null, 'subject_id' => $o['subject_id'] ?? null, 'amount' => $o['amount'] ?? null, 'currency' => $o['currency'] ?? null,
            'trigger' => $o['trigger'] ?? $type, 'status' => 'open', 'last_seen_at' => now(),
        ];
    }

    /**
     * Many new alerts in one go (the scheduled sweep can find hundreds at once): their numbers are taken together and the rows go in with one statement per 200,
     * under the same lock a single alert uses. A condition another process has already raised in the meantime is skipped. Returns how many were made.
     * If the database reports a collision the batch is done one alert at a time instead, so nothing is lost.
     */
    private static function createMany(array $rows): int
    {
        $made = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            try {
                $made += ReferenceLock::run('acct_alert_new', function () use ($chunk) {
                    $taken = AcctAlert::query()->whereIn('dedupe_key', array_column($chunk, 'dedupe_key'))->whereIn('status', ['open', 'acknowledged'])->pluck('dedupe_key')->flip()->all();
                    $chunk = array_values(array_filter($chunk, fn ($r) => ! isset($taken[$r['dedupe_key']])));
                    if (! $chunk) {
                        return 0;
                    }
                    $refs = AcctNumbers::many('acct_alerts', 'ALR', count($chunk));
                    $now = now();
                    foreach ($chunk as $i => $r) {
                        $chunk[$i] = $r + ['reference' => $refs[$i], 'created_at' => $now, 'updated_at' => $now];
                    }
                    AcctAlert::query()->insert($chunk);

                    return count($chunk);
                });
            } catch (QueryException) {
                foreach ($chunk as $r) {
                    try {
                        self::insert($r);
                        $made++;
                    } catch (\Throwable $e) {
                        Log::error('Accounts alert failed', ['type' => $r['type'], 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        return $made;
    }

    /**
     * Number and insert in ONE step. The reference is "highest so far + 1", so the number must be taken and used under the same lock: with the lock released
     * before the insert, two alerts raised at once were given the same number and one of them was lost. If MySQL still reports a duplicate (the other
     * writer is inside a transaction that has not committed yet), the number is worked out again from the latest committed rows and the insert is retried.
     */
    private static function insert(array $attrs): AcctAlert
    {
        // a repeat of the same condition raised at the same moment by another process: the one that got the lock first made the alert, so use it
        $last = null;
        for ($try = 0; $try < 6; $try++) {
            try {
                return ReferenceLock::run('acct_alert_new', function () use ($attrs, $try) {
                    $open = AcctAlert::query()->where('dedupe_key', $attrs['dedupe_key'])->whereIn('status', ['open', 'acknowledged'])->latest('id')->first();
                    if ($open) {
                        return $open;
                    }

                    return AcctAlert::query()->create($attrs + ['reference' => AcctNumbers::next('acct_alerts', 'ALR', 'reference', $try > 0)]);
                });
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'Duplicate entry') && ! str_contains($e->getMessage(), '1062')) {
                    throw $e;
                }
                $last = $e;
                usleep(random_int(5000, 60000));
            }
        }

        throw $last;
    }

    /** Close an alert whose condition has gone away (a balance is back above its minimum, a rate was approved ...). */
    public static function clear(string $dedupe, string $note = 'The condition no longer applies.', ?array $open = null): void
    {
        if ($open !== null && ! isset($open[$dedupe])) {
            return; // the caller already knows no alert is open for this condition: nothing to ask the database
        }
        AcctAlert::query()->where('dedupe_key', $dedupe)->whereIn('status', ['open', 'acknowledged'])->get()->each(
            fn ($a) => $a->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => $note])->save()
        );
    }

    private static function notify(AcctAlert $a, ?string $event, ?string $approverPermission = null): void
    {
        $event ??= self::NOTIFY[$a->category] ?? 'unusual_transaction';
        $acct = $a->account_id ? AcctAccount::query()->find($a->account_id) : null;
        $amount = $a->amount !== null ? ($a->currency . ' ' . Money::format($a->amount, AcctRates::precision((string) $a->currency))) : '';
        $candidates = null;
        $extra = [];
        if ($acct && in_array($a->category, ['balance', 'reconciliation'], true)) {
            $extra = array_filter(array_merge([$acct->custodian_user_id], (array) $acct->alert_user_ids));
        }
        // anything waiting for approval goes to the people who hold THAT kind's approve ability (approve-acct-outflows, approve-acct-transfers ...)
        if ($approverPermission) {
            $extra = array_merge($extra, \App\Models\User::permission($approverPermission)->where('is_active', true)->pluck('id')->all());
        }
        try {
            WorkflowMessenger::dispatch('accounts_tracking', $event, null, null, [
                'account_id' => 1, 'title' => $a->title, 'message' => trim(($a->description ?? '') . ($acct ? ' (' . $acct->name . ')' : '')), 'url' => route('acct.alerts.index', ['q' => $a->reference]),
                'owner_user_id' => (int) ($acct->custodian_user_id ?? 0), 'dedupe' => 'acct-alert-' . $a->id . '-' . intdiv(time(), 3600),
                'values' => ['reference' => $a->reference, 'account' => $acct->name ?? '', 'amount' => $amount], 'extra_user_ids' => $extra, 'details' => array_filter([__('Alert') => $a->reference, __('Severity') => __(ucfirst($a->severity)), __('Account') => $acct->name ?? null, __('Amount') => $amount ?: null]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Accounts alert notification failed', ['alert' => $a->id, 'error' => $e->getMessage()]);
        }
    }

    /** Look at one account and raise or clear its balance alerts. */
    public static function scanAccount(AcctAccount $a, ?array $open = null): void
    {
        $p = AcctRates::precision($a->currency);
        $fmt = fn ($v) => $a->currency . ' ' . Money::format($v, $p);
        $o = ['account_id' => $a->id, 'currency' => $a->currency, 'amount' => $a->balance];

        if ($a->min_balance !== null && Money::cmp($a->balance, $a->min_balance) < 0) {
            self::raise('balance', 'below_minimum', Money::cmp($a->balance, '0') < 0 ? 'critical' : 'high', __(':a is below its minimum balance', ['a' => $a->name]), __('It holds :b; the minimum is :m.', ['b' => $fmt($a->balance), 'm' => $fmt($a->min_balance)]), $o + ['dedupe' => 'below-min-' . $a->id]);
        } else {
            self::clear('below-min-' . $a->id, 'The balance is back above the minimum.', $open);
        }
        if ($a->max_balance !== null && Money::cmp($a->balance, $a->max_balance) > 0) {
            self::raise('balance', 'above_maximum', 'medium', __(':a is above its maximum holding', ['a' => $a->name]), __('It holds :b; the maximum is :m.', ['b' => $fmt($a->balance), 'm' => $fmt($a->max_balance)]), $o + ['dedupe' => 'above-max-' . $a->id]);
            self::clear('near-max-' . $a->id, 'The balance is now above the maximum holding.', $open); // the stronger alert replaces the warning
        } elseif ($a->max_balance !== null && Money::cmp($a->balance, bcmul((string) $a->max_balance, '0.9', 4)) >= 0) {
            self::raise('balance', 'near_maximum', 'low', __(':a is close to its maximum holding', ['a' => $a->name]), __('It holds :b of a maximum of :m.', ['b' => $fmt($a->balance), 'm' => $fmt($a->max_balance)]), $o + ['dedupe' => 'near-max-' . $a->id]);
            self::clear('above-max-' . $a->id, 'The condition no longer applies.', $open);
        } else {
            self::clear('above-max-' . $a->id, 'The condition no longer applies.', $open);
            self::clear('near-max-' . $a->id, 'The condition no longer applies.', $open);
        }
        if (Money::cmp($a->balance, '0') < 0) {
            self::raise('balance', 'negative', 'critical', __(':a is negative', ['a' => $a->name]), __('It holds :b.', ['b' => $fmt($a->balance)]), $o + ['dedupe' => 'negative-' . $a->id]);
        } else {
            self::clear('negative-' . $a->id, 'The balance is no longer negative.', $open);
        }
    }

    /** A posted payment far above this account's recent average. */
    public static function unusual(AcctTransaction $t, AcctAccount $a): void
    {
        if ($t->direction !== 'out' || $t->kind !== 'payment') {
            return;
        }
        $avg = AcctTransaction::query()->where('account_id', $a->id)->where('direction', 'out')->where('kind', 'payment')->where('status', 'posted')->where('id', '<>', $t->id)->where('posted_at', '>=', now()->subDays(60))->avg('amount');
        $count = AcctTransaction::query()->where('account_id', $a->id)->where('direction', 'out')->where('kind', 'payment')->where('status', 'posted')->where('id', '<>', $t->id)->where('posted_at', '>=', now()->subDays(60))->count();
        $f = (float) AcctSettings::get()['unusual_factor'];
        if ($count >= 5 && $avg > 0 && (float) $t->amount > $avg * $f) {
            self::raise('transaction', 'unusual_amount', 'medium', __('Unusual payment on :a', ['a' => $a->name]), __(':r is more than :f times the account\'s recent average payment.', ['r' => $t->reference, 'f' => $f]), ['account_id' => $a->id, 'subject_type' => 'transaction', 'subject_id' => $t->id, 'amount' => $t->amount, 'currency' => $t->currency, 'dedupe' => 'unusual-' . $t->id, 'notify_event' => 'unusual_transaction']);
        }
    }

    /**
     * The scheduled sweep: things that become true with time. Written to stay cheap with many accounts and many waiting items: the alerts that are
     * already open are read once, a condition that is still open is only counted (in one update at the end), and only new alerts are created one by one.
     *
     * @param ?array $drift accounts whose stored balance disagrees with the ledger, when the caller has already worked that out (AcctBalance::integrity())
     */
    public static function sweep(?array $drift = null): array
    {
        $n = ['balance' => 0, 'pending' => 0, 'transfers' => 0, 'reconciliation' => 0, 'rates' => 0, 'drift' => 0];
        $set = AcctSettings::get();
        $open = AcctAlert::query()->whereIn('status', ['open', 'acknowledged'])->orderBy('id')->get(['id', 'dedupe_key', 'last_seen_at'])->keyBy('dedupe_key')->all();
        $bump = [];
        $accounts = AcctAccount::query()->where('status', 'active')->get();
        $last = AcctReconciler::lastApproved($accounts->filter(fn ($a) => (int) ($a->reconcile_every_days ?: 0) > 0)->pluck('id'));
        foreach ($accounts as $a) {
            self::scanAccount($a, $open);
            $n['balance']++;
            $every = (int) ($a->reconcile_every_days ?: 0);
            if ($every > 0) {
                $lastDate = $last[$a->id] ?? null;
                $due = $lastDate ? \Carbon\Carbon::parse($lastDate)->addDays($every) : \Carbon\Carbon::parse($a->opening_date ?: $a->created_at)->addDays($every);
                if ($due->isPast()) {
                    if (! self::seen('recon-overdue-' . $a->id, $open, $bump, $set)) {
                        self::raise('reconciliation', 'overdue', 'medium', __(':a needs reconciling', ['a' => $a->name]), __('Last reconciled :d; it should be every :n days.', ['d' => $lastDate ?: __('never'), 'n' => $every]), ['account_id' => $a->id, 'dedupe' => 'recon-overdue-' . $a->id]);
                    }
                    $n['reconciliation']++;
                } else {
                    self::clear('recon-overdue-' . $a->id, 'The account has been reconciled.', $open);
                }
            }
        }
        // a stored balance that disagrees with the ledger is serious whatever the cause: it is raised as a critical alert, not just printed by the command
        $drift ??= app(AcctBalance::class)->integrity();
        $bad = [];
        foreach ($drift as $b) {
            $bad['drift-' . $b['id']] = true;
            if (! self::seen('drift-' . $b['id'], $open, $bump, $set)) {
                self::raise('balance', 'balance_drift', 'critical', __('The balance of :a does not match its ledger', ['a' => $b['account']]), __('The stored balance is :s but the ledger adds up to :l (difference :d). Check the account and use "Check against ledger" on it.', ['s' => $b['stored'], 'l' => $b['ledger'], 'd' => $b['difference']]),
                    ['account_id' => $b['id'], 'currency' => $b['currency'], 'amount' => $b['difference'], 'dedupe' => 'drift-' . $b['id']]);
            }
            $n['drift']++;
        }
        foreach (array_keys($open) as $k) {
            if (str_starts_with($k, 'drift-') && ! isset($bad[$k])) {
                self::clear($k, 'The stored balance agrees with the ledger again.', $open);
            }
        }
        // Items that have waited too long: their alerts are made together and ONE message goes out for the lot, instead of one per item
        $hours = (int) $set['pending_alert_hours'];
        $newTxn = [];
        AcctTransaction::query()->whereIn('status', ['submitted', 'approved'])->where('submitted_at', '<', now()->subHours($hours))->whereNull('transfer_id')->get()->each(function ($t) use (&$n, $hours, $open, &$bump, $set, &$newTxn) {
            if (! self::seen('stale-' . $t->id, $open, $bump, $set)) {
                $newTxn[] = self::attrs('stale-' . $t->id, 'transaction', 'pending_too_long', 'medium', __(':r has waited more than :h hours', ['r' => $t->reference, 'h' => $hours]), __('It is still :s.', ['s' => __($t->status)]),
                    ['account_id' => $t->account_id, 'subject_type' => 'transaction', 'subject_id' => $t->id, 'amount' => $t->amount, 'currency' => $t->currency]);
            }
            $n['pending']++;
        });
        $th = (int) $set['transfer_pending_hours'];
        $newTrf = [];
        \App\Models\Accounts\AcctTransfer::query()->whereIn('status', ['submitted', 'approved'])->where('submitted_at', '<', now()->subHours($th))->get()->each(function ($t) use (&$n, $th, $open, &$bump, $set, &$newTrf) {
            if (! self::seen('tr-stale-' . $t->id, $open, $bump, $set)) {
                $newTrf[] = self::attrs('tr-stale-' . $t->id, 'transfer', 'transfer_pending_too_long', 'medium', __('Transfer :r has waited more than :h hours', ['r' => $t->reference, 'h' => $th]), null,
                    ['account_id' => $t->from_account_id, 'subject_type' => 'transfer', 'subject_id' => $t->id, 'amount' => $t->from_amount, 'currency' => $t->from_currency]);
            }
            $n['transfers']++;
        });
        try {
            if (($made = self::createMany($newTxn)) > 0) {
                self::digest('approval_needed', __(':n transactions have waited more than :h hours', ['n' => $made, 'h' => $hours]), __('They are still waiting for approval or posting. See the Alerts page.'), array_map(fn ($s) => 'approve-acct-' . $s, array_values(AcctPermissions::SUBJECTS)));
            }
            if (($made = self::createMany($newTrf)) > 0) {
                self::digest('approval_needed', __(':n transfers have waited more than :h hours', ['n' => $made, 'h' => $th]), __('They are still waiting for approval or posting. See the Alerts page.'), ['approve-acct-transfers']);
            }
        } catch (\Throwable $e) {
            Log::error('Accounts alert sweep failed', ['error' => $e->getMessage()]);
        }
        \App\Models\Accounts\AcctExchangeRate::query()->where('status', 'pending')->where('created_at', '<', now()->subHours(24))->get()->each(function ($r) use (&$n, $open, &$bump, $set) {
            if (! self::seen('rate-pending-' . $r->id, $open, $bump, $set)) {
                self::raise('currency', 'rate_pending', 'low', __('Exchange rate :p is waiting for approval', ['p' => $r->base_currency . '/' . $r->quote_currency]), null, ['dedupe' => 'rate-pending-' . $r->id, 'notify_event' => 'rate_alert']);
            }
            $n['rates']++;
        });
        // a currency in use with no approved rate to the reporting currency
        $rep = $set['reporting_currency'];
        AcctAccount::query()->where('status', 'active')->distinct()->pluck('currency')->each(function ($c) use ($rep, &$n, $open, &$bump, $set) {
            if ($c !== $rep && ! AcctRates::find($c, $rep)) {
                if (! self::seen('rate-missing-' . $c, $open, $bump, $set)) {
                    self::raise('currency', 'rate_missing', 'medium', __('No approved rate for :p', ['p' => $c . '/' . $rep]), __('Reporting-currency totals cannot include :c until a rate is approved.', ['c' => $c]), ['dedupe' => 'rate-missing-' . $c, 'notify_event' => 'rate_alert']);
                }
                $n['rates']++;
            } else {
                self::clear('rate-missing-' . $c, 'An approved rate exists.', $open);
            }
        });
        if ($bump) {
            AcctAlert::query()->whereIn('id', array_values(array_unique($bump)))->update(['occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => now()]);
        }

        return $n;
    }

    /** One message for a batch of alerts made together: the people who may approve are told once, not once per item. */
    private static function digest(string $event, string $title, string $message, array $approverPermissions): void
    {
        try {
            WorkflowMessenger::dispatch('accounts_tracking', $event, null, null, [
                'account_id' => 1, 'title' => $title, 'message' => $message, 'url' => route('acct.alerts.index'), 'dedupe' => 'acct-digest-' . md5($title) . '-' . intdiv(time(), 3600),
                'values' => ['reference' => $title, 'account' => '', 'amount' => ''], 'extra_user_ids' => \App\Models\User::permission($approverPermissions)->where('is_active', true)->pluck('id')->all(), 'details' => [__('Alert') => $title],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Accounts alert digest failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Is this condition already an open alert that does not need to tell anyone again yet? Then it is only counted (the caller updates all of them in one go).
     * One that has been quiet for longer than the cooldown returns false so that the ordinary path raises it again and re-notifies.
     */
    private static function seen(string $key, array $open, array &$bump, array $set): bool
    {
        $a = $open[$key] ?? null;
        if (! $a) {
            return false;
        }
        if ($a->last_seen_at && $a->last_seen_at->lt(now()->subHours((int) $set['alert_cooldown_hours']))) {
            return false;
        }
        $bump[] = $a->id;

        return true;
    }
}
