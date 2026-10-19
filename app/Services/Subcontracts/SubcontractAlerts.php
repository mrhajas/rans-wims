<?php

namespace App\Services\Subcontracts;

use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractAlert;
use App\Services\Workflow\WorkflowMessenger;
use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Subcontract alerts. One alert per unresolved condition: raising the same condition again only counts it (and, after the cooldown, tells people again), it never piles
 * up a new alert each time. The scheduled sweep raises what is true now and closes the alerts whose condition has gone away. Who is told, and on which channel, is set
 * on the module's Alert Workflow page (event "alert_raised").
 */
class SubcontractAlerts
{
    /** Severity order, for "did it get worse". */
    private const RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /** During a sweep only: the open and the person-resolved alerts of every dedupe key, read once, and the ids that were merely seen again (counted together at the end). */
    private static ?array $known = null;

    /** @var array<int,int> */
    private static array $touch = [];

    /**
     * @param array{agreement_id?:int, contact_id?:int, subject_type?:string, subject_id?:int, amount?:string, currency?:string, dedupe?:string, trigger?:string, link?:string} $o
     */
    public static function raise(string $category, string $type, string $severity, string $title, ?string $description = null, array $o = []): ?SubcontractAlert
    {
        try {
            $key = $o['dedupe'] ?? ($type . '-' . ($o['agreement_id'] ?? '0') . '-' . ($o['subject_id'] ?? '0'));
            $sweep = ($o['trigger'] ?? 'sweep') === 'sweep';
            $open = self::$known !== null ? (self::$known['open'][$key] ?? null) : SubcontractAlert::query()->where('dedupe_key', $key)->whereIn('status', ['open', 'acknowledged'])->latest('id')->first();
            $cool = max(1, (int) (SubcontractSettings::get()['alert_cooldown_hours'] ?? 24));
            if ($open) {
                $renotify = $open->last_seen_at && $open->last_seen_at->lt(now()->subHours($cool));
                $amount = $o['amount'] ?? $open->amount;
                $same = $open->title === $title && (string) $open->description === (string) $description && ((string) $amount === (string) $open->amount || (is_numeric($amount) && is_numeric($open->amount) && bccomp((string) $amount, (string) $open->amount, 2) === 0));
                if (self::$known !== null && $same && ! $renotify) {
                    self::$touch[] = $open->id; // only "seen again": counted with the others at the end of the sweep
                    $open->occurrences++;

                    return $open;
                }
                $open->forceFill(['occurrences' => $open->occurrences + 1, 'last_seen_at' => now(), 'title' => $title, 'description' => $description, 'amount' => $amount])->save();
                if ($renotify) {
                    self::notify($open);
                }

                return $open;
            }
            // A person resolved this alert and the condition still holds: it stays resolved (no new reference, no new message) until it gets worse or the cooldown (7 times the repeat time) has passed.
            $res = ! $sweep ? null : (self::$known !== null ? (self::$known['resolved'][$key] ?? null) : SubcontractAlert::query()->where('dedupe_key', $key)->latest('id')->first());
            if ($res && $res->status === 'resolved' && $res->resolved_by && $res->trigger === 'sweep') {
                if (! self::worsened($res, $type, $severity, $o['amount'] ?? null) && $res->resolved_at && $res->resolved_at->gt(now()->subHours($cool * 7))) {
                    return $res;
                }
                $res->forceFill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution_notes' => null, 'acknowledged_by' => null, 'acknowledged_at' => null, 'read_at' => null, 'severity' => $severity,
                    'occurrences' => $res->occurrences + 1, 'last_seen_at' => now(), 'title' => $title, 'description' => $description, 'amount' => $o['amount'] ?? $res->amount])->save();
                log_activity('Subcontract alert re-opened', ['alert' => $res->reference, 'severity' => $severity], null, 'Subcontracts');
                self::notify($res);

                return $res;
            }
            $a = ReferenceLock::run('subcontract_alerts', fn () => SubcontractAlert::query()->create([
                'reference' => SubcontractAlert::nextReference(), 'dedupe_key' => $key, 'category' => $category, 'type' => $type, 'severity' => $severity, 'title' => $title, 'description' => $description,
                'agreement_id' => $o['agreement_id'] ?? null, 'contact_id' => $o['contact_id'] ?? null, 'subject_type' => $o['subject_type'] ?? null, 'subject_id' => $o['subject_id'] ?? null,
                'amount' => $o['amount'] ?? null, 'currency' => $o['currency'] ?? null, 'trigger' => $o['trigger'] ?? 'sweep', 'status' => 'open', 'last_seen_at' => now(),
            ]));
            if (self::$known !== null) {
                self::$known['open'][$key] = $a; // raised twice in one sweep: the second time it is the same alert
            }
            self::notify($a);

            return $a;
        } catch (\Throwable $e) {
            Log::error('Subcontract alert failed', ['type' => $type, 'error' => $e->getMessage()]);

            return null; // an alert must never break a payment or an approval
        }
    }

    /** Is the condition worse than when a person resolved the alert: a higher severity, or more money at stake (less money left, for "close to the limit"). */
    private static function worsened(SubcontractAlert $r, string $type, string $severity, $amount): bool
    {
        if ((self::RANK[$severity] ?? 0) > (self::RANK[$r->severity] ?? 0)) {
            return true;
        }
        if (is_numeric($amount) && is_numeric($r->amount)) {
            $c = bccomp(ltrim((string) $amount, '-'), ltrim((string) $r->amount, '-'), 2);

            return $type === 'near_limit' ? $c < 0 : $c > 0;
        }

        return false;
    }

    /** Close an alert whose condition has gone away. */
    public static function clear(string $dedupe, string $note = 'The condition no longer applies.'): void
    {
        SubcontractAlert::query()->where('dedupe_key', $dedupe)->whereIn('status', ['open', 'acknowledged'])->get()->each(
            fn ($a) => $a->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => $note])->save()
        );
    }

    /** Only the serious ones tell people (a note to the module's alert recipients); the rest wait on the page. */
    private static function notify(SubcontractAlert $a): void
    {
        if (! in_array($a->severity, ['high', 'critical'], true)) {
            return;
        }
        try {
            $amount = $a->amount !== null ? ($a->currency . ' ' . number_format((float) $a->amount, 2)) : '';
            $agr = $a->agreement_id ? A::withoutGlobalScopes()->find($a->agreement_id) : null;
            WorkflowMessenger::dispatch('subcontracts', 'alert_raised', null, null, [
                'account_id' => $a->account_id ?: 1, 'title' => $a->title, 'message' => (string) $a->description, 'url' => route('subcontracts.alerts.index', ['q' => $a->reference]),
                'dedupe' => 'sc-alert-' . $a->id . '-' . intdiv(time(), 3600),
                'values' => ['reference' => $a->reference, 'subcontractor' => (string) optional($agr?->contact)->name, 'agreement' => $agr->reference ?? '', 'amount' => $amount, 'message' => $a->title],
                'details' => array_filter([__('Alert') => $a->reference, __('Severity') => __(ucfirst($a->severity)), __('Agreement') => $agr->reference ?? null, __('Amount') => $amount ?: null]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Subcontract alert notification failed', ['alert' => $a->id, 'error' => $e->getMessage()]);
        }
    }

    /** The scheduled check: everything that is true now. Returns how many conditions it found per category. */
    public static function sweep(): array
    {
        // one query for the alerts that are open, one for the ones a person resolved: raise() then never asks per condition
        $every = SubcontractAlert::query()->whereIn('status', ['open', 'acknowledged'])->orderBy('id')->get();
        $open = $every->keyBy('dedupe_key');
        $resolved = SubcontractAlert::query()->whereIn('id', SubcontractAlert::query()->selectRaw('MAX(id)')->groupBy('dedupe_key'))->where('status', 'resolved')->whereNotNull('resolved_by')->where('trigger', 'sweep')->get()->keyBy('dedupe_key');
        self::$known = ['open' => $open->all(), 'resolved' => $resolved->all()];
        self::$touch = [];
        try {
            $n = self::runSweep($every);
            foreach (array_chunk(self::$touch, 1000) as $ids) {
                SubcontractAlert::query()->whereIn('id', $ids)->update(['occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => now(), 'updated_at' => now()]);
            }

            return $n;
        } finally {
            self::$known = null;
            self::$touch = [];
        }
    }

    private static function runSweep($openAlerts): array
    {
        $s = SubcontractSettings::get();
        $n = ['balance' => 0, 'approval' => 0, 'payment' => 0, 'contract' => 0, 'setup' => 0];
        $seen = [];
        $mark = function (string $k) use (&$seen) {
            $seen[$k] = true;
        };
        $fmt = fn ($v, $c) => $c . ' ' . number_format((float) $v, 2);
        $agreements = A::withoutGlobalScopes()->whereNotIn('status', [A::DRAFT, A::CANCELLED, A::ARCHIVED, A::TERMINATED])->with('contact:id,name')->get();
        $figs = SubcontractLedger::forAgreements($agreements->pluck('id')->all());
        // "approved, not paid": counted from the oldest approval that still has money to pay (a request that is fully paid does not keep the clock running)
        $unpaidSince = [];
        $withUnpaid = $agreements->filter(fn ($a) => isset($figs[$a->id]) && bccomp($figs[$a->id]['approved_unpaid'], '0', 2) > 0)->pluck('id')->all();
        if ($withUnpaid) {
            $appr = DB::table('subcontract_payment_requests')->whereNull('deleted_at')->where('status', 'Approved')->whereIn('agreement_id', $withUnpaid)->get(['id', 'agreement_id', 'approved_at']);
            $rf = SubcontractLedger::requestFiguresMany($appr->pluck('id')->all());
            foreach ($appr as $r) {
                if ($r->approved_at && bccomp($rf[$r->id]['approved'], $rf[$r->id]['paid'], 2) > 0 && (! isset($unpaidSince[$r->agreement_id]) || $r->approved_at < $unpaidSince[$r->agreement_id])) {
                    $unpaidSince[$r->agreement_id] = $r->approved_at;
                }
            }
        }
        $near = max(0, (float) ($s['near_limit_percent'] ?? 10));
        foreach ($agreements as $a) {
            $f = $figs[$a->id] ?? null;
            if (! $f) {
                continue;
            }
            $who = optional($a->contact)->name ?: '';
            $o = ['agreement_id' => $a->id, 'contact_id' => $a->contact_id, 'currency' => $a->currency];
            $left = $f['commitment_left'];
            $paying = in_array($a->status, [A::APPROVED, A::ACTIVE, A::COMPLETED, A::SUSPENDED], true);
            if (bccomp($left, '0', 2) < 0) {
                $over = bcmul($left, '-1', 2);
                $big = bccomp($f['revised'], '0', 2) > 0 && bccomp($over, bcmul($f['revised'], '0.1', 2), 2) > 0;
                self::raise('balance', 'negative_outstanding', $big ? 'critical' : 'high', __(':r is paid above its contract value', ['r' => $a->reference]), __(':w has been paid :o more than the contract value of :c (outstanding :l).', ['w' => $who ?: __('The subcontractor'), 'o' => $fmt($over, $a->currency), 'c' => $fmt($f['revised'], $a->currency), 'l' => $fmt($left, $a->currency)]), $o + ['dedupe' => 'negative-' . $a->id, 'amount' => $left]);
                $mark('negative-' . $a->id);
                $n['balance']++;
            } elseif ($paying && $a->status !== A::COMPLETED && $near > 0 && bccomp($f['revised'], '0', 2) > 0 && bccomp($left, '0', 2) > 0 && bccomp(bcmul($left, '100', 4), bcmul($f['revised'], (string) $near, 4), 4) <= 0) {
                self::raise('balance', 'near_limit', 'low', __(':r is close to its contract value', ['r' => $a->reference]), __('Only :l (:p%) of :c is left to pay :w.', ['l' => $fmt($left, $a->currency), 'p' => rtrim(rtrim(number_format($near, 2, '.', ''), '0'), '.'), 'c' => $fmt($f['revised'], $a->currency), 'w' => $who]), $o + ['dedupe' => 'near-' . $a->id, 'amount' => $left]);
                $mark('near-' . $a->id);
                $n['balance']++;
            }
            if ($paying && bccomp($f['approved_unpaid'], '0', 2) > 0) {
                $days = (int) ($s['unpaid_alert_days'] ?? 7);
                $since = $unpaidSince[$a->id] ?? null;
                if ($since && \Carbon\Carbon::parse($since)->lt(now()->subDays($days))) {
                    self::raise('payment', 'approved_not_paid', 'medium', __(':r has approved money that is not paid', ['r' => $a->reference]), __(':u has been approved for more than :d days and is not yet paid to :w.', ['u' => $fmt($f['approved_unpaid'], $a->currency), 'd' => $days, 'w' => $who]), $o + ['dedupe' => 'unpaid-' . $a->id, 'amount' => $f['approved_unpaid'], 'subject_type' => 'agreement', 'subject_id' => $a->id]);
                    $mark('unpaid-' . $a->id);
                    $n['payment']++;
                }
            }
            if ($a->expected_completion && in_array($a->status, [A::APPROVED, A::ACTIVE], true)) {
                $d = $a->expected_completion->copy()->startOfDay();
                if ($d->lt(now()->startOfDay())) {
                    self::raise('contract', 'past_completion', 'medium', __(':r is past its expected completion date', ['r' => $a->reference]), __('It was due on :d and is still :s.', ['d' => $d->toDateString(), 's' => __($a->status)]), $o + ['dedupe' => 'late-' . $a->id]);
                    $mark('late-' . $a->id);
                    $n['contract']++;
                } elseif ($d->lte(now()->addDays((int) $s['completion_alert_days'])->endOfDay())) {
                    self::raise('contract', 'ending_soon', 'low', __(':r ends soon', ['r' => $a->reference]), __('It is due on :d.', ['d' => $d->toDateString()]), $o + ['dedupe' => 'ends-' . $a->id]);
                    $mark('ends-' . $a->id);
                    $n['contract']++;
                }
            }
        }
        // things that wait for a decision for too long
        $cut = now()->subHours((int) $s['review_alert_hours']);
        foreach (DB::table('subcontract_payment_requests')->whereNull('deleted_at')->whereIn('status', ['Submitted', 'Under Review'])->where('updated_at', '<', $cut)->get() as $r) {
            self::raise('approval', 'request_waiting', 'medium', __('Payment request :r is waiting for a decision', ['r' => $r->reference]), __('Waiting since :d (more than :h hours).', ['d' => substr((string) $r->updated_at, 0, 16), 'h' => (int) $s['review_alert_hours']]), ['agreement_id' => $r->agreement_id, 'contact_id' => $r->contact_id, 'subject_type' => 'request', 'subject_id' => $r->id, 'amount' => $r->requested_amount, 'currency' => $r->currency, 'dedupe' => 'req-wait-' . $r->id]);
            $mark('req-wait-' . $r->id);
            $n['approval']++;
        }
        foreach (DB::table('subcontract_variations')->whereNull('deleted_at')->whereIn('status', ['Submitted', 'Reviewed'])->where('updated_at', '<', $cut)->get() as $v) {
            self::raise('approval', 'variation_waiting', 'medium', __('Variation :r is waiting for a decision', ['r' => $v->reference]), __('Waiting since :d (more than :h hours).', ['d' => substr((string) $v->updated_at, 0, 16), 'h' => (int) $s['review_alert_hours']]), ['agreement_id' => $v->agreement_id, 'subject_type' => 'variation', 'subject_id' => $v->id, 'amount' => $v->amount, 'dedupe' => 'var-wait-' . $v->id]);
            $mark('var-wait-' . $v->id);
            $n['approval']++;
        }
        foreach (DB::table('subcontract_agreements')->whereNull('deleted_at')->whereIn('status', [A::SUBMITTED, A::REVIEW])->where('updated_at', '<', $cut)->get() as $g) {
            self::raise('approval', 'agreement_waiting', 'medium', __('Agreement :r is waiting for a decision', ['r' => $g->reference]), __('Waiting since :d (more than :h hours).', ['d' => substr((string) $g->updated_at, 0, 16), 'h' => (int) $s['review_alert_hours']]), ['agreement_id' => $g->id, 'contact_id' => $g->contact_id, 'subject_type' => 'agreement', 'subject_id' => $g->id, 'currency' => $g->currency, 'dedupe' => 'agr-wait-' . $g->id]);
            $mark('agr-wait-' . $g->id);
            $n['approval']++;
        }
        // contract payment requests (claims) with a line that has waited for a decision too long (no amount in the alert: people who may not see money read it too)
        if (\App\Support\SchemaCache::hasTable('subcontract_claims')) {
            foreach (DB::table('subcontract_claims as c')->whereNull('c.deleted_at')->whereNotIn('c.status', ['Draft', 'Cancelled'])->where('c.updated_at', '<', $cut)
                ->whereExists(fn ($e) => $e->from('subcontract_claim_lines as l')->whereColumn('l.claim_id', 'c.id')->whereNull('l.deleted_at')->where('l.status', 'Pending'))->get(['c.id', 'c.reference', 'c.contact_id', 'c.currency', 'c.updated_at']) as $c) {
                self::raise('approval', 'claim_waiting', 'medium', __('Contract payment request :r is waiting for a decision', ['r' => $c->reference]), __('Waiting since :d (more than :h hours).', ['d' => substr((string) $c->updated_at, 0, 16), 'h' => (int) $s['review_alert_hours']]), ['contact_id' => $c->contact_id, 'subject_type' => 'claim', 'subject_id' => $c->id, 'currency' => $c->currency, 'dedupe' => 'claim-wait-' . $c->id]);
                $mark('claim-wait-' . $c->id);
                $n['approval']++;
            }
        }
        // setup: agreements that no Project(s) / Warehouse(s) is tied to (one alert, not one per agreement)
        $noProject = $agreements->whereNull('warehouse_id')->count();
        if ($noProject > 0) {
            self::raise('setup', 'no_project', 'low', trans_choice(':n subcontract agreement has no Project(s) / Warehouse(s)|:n subcontract agreements have no Project(s) / Warehouse(s)', $noProject, ['n' => $noProject]), __('Match the project names of the Excel to Project(s) / Warehouse(s) on the Project Mapping page so the agreements, reports and totals show the right project.'), ['dedupe' => 'no-project', 'subject_type' => 'mapping']);
            $mark('no-project');
            $n['setup']++;
        }
        // close the sweep's own alerts that were not raised this time (one update)
        $gone = collect($openAlerts)->filter(fn ($a) => $a->trigger === 'sweep' && ! isset($seen[$a->dedupe_key]))->pluck('id')->all();
        foreach (array_chunk($gone, 1000) as $ids) {
            SubcontractAlert::query()->whereIn('id', $ids)->whereIn('status', ['open', 'acknowledged'])->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => __('The condition no longer applies.'), 'updated_at' => now()]);
        }

        return $n;
    }
}
