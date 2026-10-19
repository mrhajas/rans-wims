<?php

namespace App\Services\Subcontracts;

use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractClaim as C;
use App\Models\SubcontractClaimLine as L;
use App\Models\SubcontractPayment as P;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\User;
use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contract Payment Requests ("claims"): ONE request to pay a subcontractor for several completed agreements, decided line by line.
 *
 * The payable unit is the AGREEMENT (work groups are not paid on their own). Every rule is here, server side, inside transactions that lock the agreements in
 * ascending id order first (the same order the payment request approval uses, so two screens can never deadlock), then the claim, then its lines:
 *
 *  - eligible = the agreement is Completed, verified when settings ask for it, has evidence when settings ask for it, and still has a balance;
 *  - a line that is Pending / Returned / Deferred (in a claim that is not a draft) RESERVES its amount: SubcontractLedger counts it in `requested_pending`, so nothing else can be
 *    requested against the same balance; rejecting or cancelling it releases the reservation;
 *  - approving a line checks the headroom like any payment request (an override with a reason, only for who may) and then CREATES the ordinary per-agreement payment request
 *    (status Approved, claim_line_id set), so the voucher / ledger / report pipeline is unchanged and `paid`, `approved_unpaid`, `commitment_left` never need to know about claims;
 *  - the claim status is always worked out from its lines (recalc); payment progress (Unpaid / Partly Paid / Paid) is worked out from the ledger, never stored;
 *  - one Payment Voucher can pay several lines (startVoucher): its payments share the voucher, the sum of their TZS amounts is the voucher total.
 * Nothing here moves money: only a Payment Voucher that is Authorised and marked paid does.
 */
class SubcontractClaims
{
    public const LINE_ACTIONS = ['approve', 'reject', 'return', 'defer'];

    // ---- settings ------------------------------------------------------------------------------------------------------------------------

    public static function requiresVerification(): bool
    {
        return (bool) (SubcontractSettings::get()['claims_require_verification'] ?? true);
    }

    public static function requiresEvidence(): bool
    {
        return (bool) (SubcontractSettings::get()['claims_require_evidence'] ?? false);
    }

    public static function allowsPartial(): bool
    {
        return (bool) (SubcontractSettings::get()['claims_allow_partial_request'] ?? true);
    }

    // ---- completion and eligibility --------------------------------------------------------------------------------------------------------

    /** How many documents are attached to each agreement (the evidence of completion). @param array<int,int> $ids @return array<int,int> */
    public static function evidenceCounts(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('attachments')->where('model_type', (new A)->getMorphClass())->whereIn('model_id', $ids)->groupBy('model_id')->selectRaw('model_id, COUNT(*) n')->pluck('n', 'model_id')->map(fn ($n) => (int) $n)->all();
    }

    /** Why an agreement cannot be claimed yet (null = it can; the balance is judged separately). Codes: not_completed, not_verified, no_evidence. */
    public static function blockReason(A $a, int $evidence): ?string
    {
        if ($a->status !== A::COMPLETED) {
            return 'not_completed';
        }
        if (self::requiresVerification() && ! $a->completion_verified_at) {
            return 'not_verified';
        }
        if (self::requiresEvidence() && $evidence < 1) {
            return 'no_evidence';
        }

        return null;
    }

    public static function blockMessage(?string $code): ?string
    {
        return match ($code) {
            'not_completed' => __('The agreement is not completed.'),
            'not_verified' => __('The completion of the agreement is not verified yet.'),
            'no_evidence' => __('No document is attached to the agreement as evidence of completion.'),
            'no_balance' => __('Nothing is left to request on this agreement.'),
            default => null,
        };
    }

    /** The project names of an agreement: its own and those of its work groups. @param array<int,int> $ids @return array<int,array<int,string>> */
    private static function projectNames(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach (DB::table('subcontract_agreements as a')->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')->whereIn('a.id', $ids)->get(['a.id', 'w.name']) as $r) {
            if ($r->name) {
                $out[$r->id][$r->name] = $r->name;
            }
        }
        foreach (DB::table('subcontract_work_groups as g')->join('warehouses as w', 'w.id', '=', 'g.warehouse_id')->whereIn('g.agreement_id', $ids)->whereNull('g.deleted_at')->orderBy('g.sort_order')->orderBy('g.id')->get(['g.agreement_id', 'w.name']) as $r) {
            $out[$r->agreement_id][$r->name] = $r->name;
        }

        return array_map('array_values', $out);
    }

    /** Does the agreement belong to the project (its own project or the project of one of its work groups)? */
    private static function inProject($q, int $warehouseId)
    {
        return $q->where(fn ($w) => $w->where('warehouse_id', $warehouseId)->orWhereIn('id', DB::table('subcontract_work_groups')->where('warehouse_id', $warehouseId)->whereNull('deleted_at')->select('agreement_id')));
    }

    /**
     * The agreements a claim could ask payment for. Only those the user may see; figures only for who may see money.
     * Default: eligible ones (Completed, verified / evidence as the settings say, balance left). $withBlocked also lists the other payable agreements with the reason they cannot be claimed.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function eligibleAgreements(?int $contactId, ?int $warehouseId, User $user, bool $withBlocked = false, ?string $currency = null): array
    {
        $money = $user->can('view-financials-subcontracts');
        $q = A::query()->visibleTo($user)->with(['contact:id,name', 'warehouse:id,name'])->whereIn('status', $withBlocked ? A::PAYABLE : [A::COMPLETED])
            ->when($contactId, fn ($q, $v) => $q->where('contact_id', $v))->when($currency, fn ($q, $v) => $q->where('currency', $v));
        if ($warehouseId) {
            self::inProject($q, $warehouseId);
        }
        $ags = $q->orderBy('id')->limit(500)->get();
        $ids = $ags->pluck('id')->all();
        $fig = SubcontractLedger::forAgreements($ids);
        $ev = self::evidenceCounts($ids);
        $proj = self::projectNames($ids);
        $names = DB::table('users')->whereIn('id', $ags->pluck('completed_by_user_id')->merge($ags->pluck('completion_verified_by_user_id'))->filter()->unique()->values()->all() ?: [0])->pluck('name', 'id');
        $rows = [];
        foreach ($ags as $a) {
            $f = $fig[$a->id];
            $block = self::blockReason($a, $ev[$a->id] ?? 0);
            if (! $block && bccomp($f['remaining_eligible_signed'], '0', 2) <= 0) {
                $block = 'no_balance';
            }
            if ($block && ! $withBlocked) {
                continue;
            }
            $rows[] = [
                'agreement_id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'contact_id' => $a->contact_id, 'contact' => optional($a->contact)->name, 'warehouse_id' => $a->warehouse_id, 'project' => $proj[$a->id][0] ?? null, 'projects' => $proj[$a->id] ?? [],
                'currency' => $a->currency, 'status' => $a->status, 'completed_on' => optional($a->actual_completion)->toDateString(), 'completed_by' => $names[$a->completed_by_user_id] ?? null, 'completion_note' => $a->completion_note,
                'verified' => (bool) $a->completion_verified_at, 'verified_by' => $names[$a->completion_verified_by_user_id] ?? null, 'verified_at' => optional($a->completion_verified_at)->toDateTimeString(), 'evidence_count' => $ev[$a->id] ?? 0,
                'eligible' => $block === null, 'blocked' => $block, 'blocked_message' => self::blockMessage($block), 'url' => route('subcontracts.agreements.show', $a->id),
                'revised' => $money ? $f['revised'] : null, 'previous_paid' => $money ? $f['paid_total'] : null, 'pending' => $money ? $f['requested_pending'] : null, 'claims_pending' => $money ? $f['claims_pending'] : null,
                'approved_unpaid' => $money ? $f['approved_unpaid'] : null, 'approved_work' => $money ? $f['approved_work'] : null, 'remaining_eligible' => $money ? $f['remaining_eligible'] : null,
                'remaining_eligible_signed' => $money ? $f['remaining_eligible_signed'] : null, 'default_amount' => $money ? $f['remaining_eligible'] : null,
            ];
        }

        return $rows;
    }

    /** The snapshot stored on a line when it is requested: what the ledger said then. */
    private static function snapshot(array $f, bool $eligible = true): array
    {
        return ['revised' => $f['revised'], 'paid_total' => $f['paid_total'], 'pending' => $f['requested_pending'], 'approved_unpaid' => $f['approved_unpaid'], 'approved_work' => $f['approved_work'],
            'remaining_eligible' => $f['remaining_eligible_signed'], 'eligible' => $eligible, 'at' => now()->toDateTimeString()];
    }

    /** A typed amount as an exact 2-place string, or null when it is not a plain amount (no exponent, no letters, not too big). */
    private static function typed($v): ?string
    {
        $s = trim(str_replace(',', '', (string) $v));

        return preg_match('/^\d{1,14}(\.\d{1,4})?$/', $s) ? SubcontractAmounts::money($s) : null;
    }

    private static function fmt(string $v, string $cur): string
    {
        return SubcontractAmounts::fmt($v, $cur);
    }

    private static function fail(string $key, string $msg): never
    {
        throw ValidationException::withMessages([$key => $msg]);
    }

    private static function lockAgreements(array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids ? A::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
    }

    private static function isSuper(User $u): bool
    {
        return $u->hasRole('Super Admin');
    }

    private static function projectName(?int $warehouseId): ?string
    {
        return $warehouseId ? DB::table('warehouses')->where('id', $warehouseId)->value('name') : null;
    }

    // ---- drafts ----------------------------------------------------------------------------------------------------------------------------

    /**
     * Creates (or, for a Draft claim, replaces the content of) a claim. $d: contact_id, warehouse_id?, request_date, description?, lines: [[agreement_id, amount?, note?], ...].
     * A line with no amount asks for the whole remaining eligible balance. A person who may not see money cannot type an amount (it is the whole balance), so amounts cannot be probed.
     */
    public static function saveDraft(?C $claim, array $d, User $user): C
    {
        $money = $user->can('view-financials-subcontracts');
        $lines = [];
        foreach ((array) ($d['lines'] ?? []) as $row) {
            $id = (int) ($row['agreement_id'] ?? 0);
            if ($id > 0) {
                if (isset($lines[$id])) {
                    self::fail('lines', __('An agreement can be on a claim only once.'));
                }
                $lines[$id] = $row;
            }
        }
        if (! $lines) {
            self::fail('lines', __('Choose at least one agreement.'));
        }
        $contactId = (int) ($d['contact_id'] ?? 0);
        if (! \App\Models\Contact::query()->where('id', $contactId)->where('category', 'SubContractors')->exists()) {
            self::fail('contact_id', __('Choose a subcontractor from the SubContractors list in Contacts.'));
        }
        $warehouseId = ! empty($d['warehouse_id']) ? (int) $d['warehouse_id'] : null;
        $allowed = accessible_warehouse_ids();
        if ($warehouseId && ($allowed !== null && ! in_array($warehouseId, array_map('intval', (array) $allowed), true))) {
            self::fail('warehouse_id', __('You may not use this project.'));
        }
        $visible = A::query()->visibleTo($user)->whereIn('id', array_keys($lines))->pluck('id')->all();
        $run = function () use ($claim, $d, $user, $lines, $contactId, $warehouseId, $money, $visible) {
            return DB::transaction(function () use ($claim, $d, $user, $lines, $contactId, $warehouseId, $money, $visible) {
                $ags = self::lockAgreements(array_keys($lines)); // the first statement of the transaction: the figures read next are fresh
                if ($claim) {
                    $claim = C::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
                    if ($claim->status !== C::DRAFT) {
                        self::fail('claim', __('Only a draft claim can be changed.'));
                    }
                }
                $fig = SubcontractLedger::forAgreements(array_keys($lines));
                $ev = self::evidenceCounts(array_keys($lines));
                $currency = null;
                $built = [];
                foreach ($lines as $aid => $row) {
                    $a = $ags[$aid] ?? null;
                    $key = 'lines.' . $aid;
                    if (! $a || ! in_array($aid, array_map('intval', $visible), true)) {
                        self::fail($key, __('This agreement cannot be found.'));
                    }
                    if ((int) $a->contact_id !== $contactId) {
                        self::fail($key, __(':a belongs to another subcontractor.', ['a' => $a->reference]));
                    }
                    if ($warehouseId && ! A::withoutGlobalScopes()->whereKey($a->id)->where(fn ($w) => $w->where('warehouse_id', $warehouseId)->orWhereIn('id', DB::table('subcontract_work_groups')->where('warehouse_id', $warehouseId)->whereNull('deleted_at')->select('agreement_id')))->exists()) {
                        self::fail($key, __(':a is not in the project chosen.', ['a' => $a->reference]));
                    }
                    if ($why = self::blockReason($a, $ev[$aid] ?? 0)) {
                        self::fail($key, $a->reference . ': ' . self::blockMessage($why));
                    }
                    $currency ??= $a->currency;
                    if ($a->currency !== $currency) {
                        self::fail($key, __('A claim has one currency: :a is in :c.', ['a' => $a->reference, 'c' => $a->currency]));
                    }
                    $avail = $fig[$aid]['remaining_eligible_signed'];
                    if (bccomp($avail, '0', 2) <= 0) {
                        self::fail($key, $a->reference . ': ' . self::blockMessage('no_balance'));
                    }
                    $built[$aid] = [$a, self::amountFor($row['amount'] ?? null, $avail, $a->currency, $money, $key, $a->reference), trim((string) ($row['note'] ?? '')) ?: null, self::snapshot($fig[$aid])];
                }
                $header = ['contact_id' => $contactId, 'warehouse_id' => $warehouseId, 'currency' => $currency, 'request_date' => $d['request_date'] ?? now()->toDateString(), 'description' => isset($d['description']) && trim((string) $d['description']) !== '' ? trim((string) $d['description']) : null];
                $total = array_reduce($built, fn ($c, $b) => bcadd($c, $b[1], 2), '0.00');
                if (bccomp($total, '9999999999999.99', 2) > 0) {
                    self::fail('lines', __('The total of the lines is too large.'));
                }
                if ($claim) {
                    $claim->update($header + ['requested_total' => $total]);
                    L::query()->where('claim_id', $claim->id)->forceDelete(); // a draft keeps no history: the unique (claim, agreement) must be free for the new lines
                } else {
                    $claim = C::create($header + ['reference' => C::nextReference(), 'status' => C::DRAFT, 'requested_total' => $total, 'approved_total' => 0, 'requested_by_user_id' => $user->id]);
                }
                foreach ($built as $aid => [$a, $amt, $note, $snap]) {
                    L::create(['claim_id' => $claim->id, 'agreement_id' => $aid, 'requested_amount' => $amt, 'status' => L::DRAFT, 'note' => $note, 'snapshot' => $snap]);
                }

                return $claim;
            });
        };
        // a new claim gets its number under the numbering lock, taken before the transaction opens so the next number is read from a fresh snapshot
        return $claim ? $run() : ReferenceLock::run('subcontract_claims', $run);
    }

    /** The amount a line asks for: the typed one (only when the person sees money, and partial requests are allowed), otherwise the whole balance. */
    private static function amountFor($typed, string $available, string $currency, bool $money, string $key, string $ref): string
    {
        $whole = bccomp($available, '0', 2) > 0 ? $available : '0.00';
        if (! $money || $typed === null || trim((string) $typed) === '') {
            return $whole;
        }
        $amt = self::typed($typed);
        if ($amt === null || bccomp($amt, '0', 2) <= 0) {
            self::fail($key, $ref . ': ' . __('The amount must be a number above zero.'));
        }
        if (bccomp($amt, $available, 2) > 0) {
            self::fail($key, $ref . ': ' . __('The amount is more than the :a still open to request.', ['a' => self::fmt($whole, $currency)]));
        }
        if (! self::allowsPartial() && bccomp($amt, $whole, 2) !== 0) {
            self::fail($key, $ref . ': ' . __('Partial requests are not allowed: ask for the whole balance of :a.', ['a' => self::fmt($whole, $currency)]));
        }

        return $amt;
    }

    public static function deleteDraft(C $claim): void
    {
        DB::transaction(function () use ($claim) {
            $c = C::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            abort_unless($c->status === C::DRAFT, 422, __('Only a draft claim can be deleted.'));
            L::query()->where('claim_id', $c->id)->forceDelete();
            $c->delete();
        });
    }

    // ---- submit, review, cancel ------------------------------------------------------------------------------------------------------------

    /** Revalidates everything under lock and reserves the balances: Draft lines become Pending. */
    public static function submit(int $claimId, User $user): C
    {
        $ids = L::query()->where('claim_id', $claimId)->pluck('agreement_id')->all(); // read before the transaction: its first statement must be the lock, so the figures read next are fresh
        $claim = DB::transaction(function () use ($claimId, $user, $ids) {
            $ags = self::lockAgreements($ids);
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            if ($claim->status !== C::DRAFT) {
                self::fail('claim', __(':r is already :s.', ['r' => $claim->reference, 's' => strtolower(__($claim->status))]));
            }
            $lines = L::query()->where('claim_id', $claim->id)->where('status', L::DRAFT)->orderBy('agreement_id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                self::fail('lines', __('Choose at least one agreement.'));
            }
            self::checkLines($claim, $lines, $ags, $user);
            $fig = SubcontractLedger::forAgreements($lines->pluck('agreement_id')->all());
            foreach ($lines as $l) {
                $l->forceFill(['status' => L::PENDING, 'snapshot' => self::snapshot($fig[$l->agreement_id])])->save();
            }
            $claim->forceFill(['submitted_by_user_id' => $user->id, 'submitted_at' => now(), 'reviewed_by_user_id' => null, 'reviewed_at' => null])->save();
            self::recalc($claim);
            log_activity('Subcontract claim ' . $claim->reference . ' submitted', self::props($claim, ['lines' => $lines->map(fn ($l) => ['agreement' => optional($ags[$l->agreement_id] ?? null)->reference, 'requested' => (string) $l->requested_amount])->all()]), $claim, 'Subcontracts');

            return $claim;
        });
        SubcontractNotifier::fire('claim_submitted', 'claim', $claim, $user, __('Contract payment request :r was submitted.', ['r' => $claim->reference]), ['amount' => (string) $claim->requested_total]);

        return $claim;
    }

    /**
     * Revalidates lines about to become Pending (submit, resubmit a returned line): the agreement is still eligible, the currency and the contact are the claim's, the amount is
     * above zero and not above what is open to request. A line's own reservation (a Returned line) is added back first. Throws on the first problem.
     * @param \Illuminate\Support\Collection $lines the lines to check @param \Illuminate\Support\Collection $ags the locked agreements keyed by id
     */
    private static function checkLines(C $claim, $lines, $ags, User $user): void
    {
        $money = $user->can('view-financials-subcontracts');
        $ids = $lines->pluck('agreement_id')->all();
        $fig = SubcontractLedger::forAgreements($ids);
        $ev = self::evidenceCounts($ids);
        $visible = A::query()->visibleTo($user)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach ($lines as $l) {
            $a = $ags[$l->agreement_id] ?? null;
            $key = 'lines.' . $l->agreement_id;
            if (! $a || ! in_array((int) $a->id, $visible, true)) {
                self::fail($key, __('This agreement cannot be found.'));
            }
            if ((int) $a->contact_id !== (int) $claim->contact_id || $a->currency !== $claim->currency) {
                self::fail($key, $a->reference . ': ' . __('It does not match the subcontractor and currency of the claim.'));
            }
            if ($why = self::blockReason($a, $ev[$a->id] ?? 0)) {
                self::fail($key, $a->reference . ': ' . self::blockMessage($why));
            }
            if (bccomp((string) $l->requested_amount, '0', 2) <= 0) {
                self::fail($key, $a->reference . ': ' . __('The amount must be a number above zero.'));
            }
            $own = in_array($l->status, L::RESERVING, true) ? (string) $l->getOriginal('requested_amount') : '0.00'; // the amount held in the database, not a new one being tried
            $avail = bcadd($fig[$a->id]['remaining_eligible_signed'], $own, 2);
            if (bccomp((string) $l->requested_amount, $avail, 2) > 0) {
                self::fail($key, $a->reference . ': ' . ($money ? __('Only :a is open to request now (other requests hold the rest).', ['a' => self::fmt(bccomp($avail, '0', 2) > 0 ? $avail : '0.00', $a->currency)]) : __('The amount is more than is open to request now.')));
            }
        }
    }

    /** Take the claim into review: the reviewer is recorded; the status does not change (it follows the decisions). */
    public static function review(int $claimId, User $user): C
    {
        return DB::transaction(function () use ($claimId, $user) {
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            if (! in_array($claim->status, [C::SUBMITTED, C::REVIEW, C::PARTIAL], true) || ! L::query()->where('claim_id', $claim->id)->where('status', L::PENDING)->exists()) {
                self::fail('claim', __('Only a submitted claim with lines waiting can be taken into review.'));
            }
            if (self::selfDecision($claim, $user)) {
                self::fail('claim', __('You made or submitted this claim, so someone else must review it.'));
            }
            $claim->forceFill(['reviewed_by_user_id' => $user->id, 'reviewed_at' => now()])->save();
            log_activity('Subcontract claim ' . $claim->reference . ' taken into review', self::props($claim), $claim, 'Subcontracts');

            return $claim;
        });
    }

    /** Cancels what is still open on the claim (Draft, Pending, Returned, Deferred lines): their reservations are released. Approved lines stand (reverse them separately). */
    public static function cancel(int $claimId, User $user, string $reason): C
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') {
            self::fail('reason', __('Say why it is cancelled.'));
        }
        $ids = L::query()->where('claim_id', $claimId)->pluck('agreement_id')->all();
        $claim = DB::transaction(function () use ($claimId, $user, $reason, $ids) {
            self::lockAgreements($ids);
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            if ($claim->status === C::CANCELLED) {
                self::fail('claim', __(':r is already cancelled.', ['r' => $claim->reference]));
            }
            $open = L::query()->where('claim_id', $claim->id)->whereIn('status', [L::DRAFT, L::PENDING, L::RETURNED, L::DEFERRED])->orderBy('id')->lockForUpdate()->get();
            if ($open->isEmpty()) {
                self::fail('claim', __('Nothing on this claim is open to cancel.'));
            }
            foreach ($open as $l) {
                $l->forceFill(['status' => L::CANCELLED, 'decision_reason' => $reason, 'decided_by_user_id' => $user->id, 'decided_at' => now()])->save();
            }
            self::recalc($claim);
            log_activity('Subcontract claim ' . $claim->reference . ' cancelled', self::props($claim, ['reason' => $reason, 'lines' => $open->count()]), $claim, 'Subcontracts');

            return $claim;
        });

        return $claim;
    }

    // ---- decisions -------------------------------------------------------------------------------------------------------------------------

    private static function selfDecision(C $claim, User $user): bool
    {
        return ! self::isSuper($user) && ((int) $claim->requested_by_user_id === (int) $user->id || (int) $claim->submitted_by_user_id === (int) $user->id);
    }

    /**
     * Decides one or more lines of a claim in ONE transaction (all or nothing).
     * $decisions: [['line_id'=>, 'action'=>approve|reject|return|defer, 'amount'=>?, 'reason'=>?, 'override_reason'=>?], ...]
     * Who may do what is checked by the caller's abilities (decide-subcontract-claims for approve / reject / defer; review or decide for return); the maker-checker rule is here.
     * @return array{claim:C,results:array<int,array<string,mixed>>}
     */
    public static function decide(int $claimId, array $decisions, User $user): array
    {
        if (! $decisions) {
            self::fail('decisions', __('Choose what to do with at least one line.'));
        }
        $byLine = [];
        foreach ($decisions as $dec) {
            $lid = (int) ($dec['line_id'] ?? 0);
            if ($lid < 1 || isset($byLine[$lid])) {
                self::fail('decisions', __('Each line can be decided once at a time.'));
            }
            if (! in_array($dec['action'] ?? '', self::LINE_ACTIONS, true)) {
                self::fail('line_' . $lid, __('Unknown action.'));
            }
            $byLine[$lid] = $dec;
        }
        ksort($byLine);
        $lineRows = L::query()->where('claim_id', $claimId)->whereIn('id', array_keys($byLine))->get(['id', 'agreement_id']);
        if ($lineRows->count() !== count($byLine)) {
            abort(404);
        }
        $overrideAllowed = $user->can('override-subcontract-limits') && (bool) SubcontractSettings::get()['override_allowed'];
        $tolerance = ltrim(SubcontractAmounts::money((string) SubcontractSettings::get()['tolerance']), '-'); // a tolerance that is not a plain amount counts as 0.00, never as an error
        $refusals = [];
        $overrides = [];
        $results = [];
        $needsRef = collect($byLine)->contains(fn ($d) => $d['action'] === 'approve');
        $work = function () use ($claimId, $byLine, $lineRows, $user, $overrideAllowed, $tolerance, &$refusals, &$overrides, &$results) {
            return DB::transaction(function () use ($claimId, $byLine, $lineRows, $user, $overrideAllowed, $tolerance, &$refusals, &$overrides, &$results) {
                $ags = self::lockAgreements($lineRows->pluck('agreement_id')->all());
                $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
                if (in_array($claim->status, [C::DRAFT, C::CANCELLED], true)) {
                    self::fail('claim', __(':r is :s: nothing can be decided on it.', ['r' => $claim->reference, 's' => strtolower(__($claim->status))]));
                }
                if (self::selfDecision($claim, $user)) {
                    self::fail('claim', __('You made or submitted this claim, so someone else must decide it.'));
                }
                $lines = L::query()->where('claim_id', $claim->id)->whereIn('id', array_keys($byLine))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $errors = [];
                foreach ($byLine as $lid => $dec) {
                    $l = $lines[$lid];
                    $a = $ags[$l->agreement_id];
                    $act = $dec['action'];
                    $reason = mb_substr(trim((string) ($dec['reason'] ?? '')), 0, 1000);
                    $key = 'line_' . $lid;
                    if (! in_array($l->status, [L::PENDING, L::DEFERRED], true)) {
                        $errors[$key] = $a->reference . ': ' . __('This line is already :s.', ['s' => strtolower(__($l->status))]);
                        continue;
                    }
                    if ($act === 'defer' && $l->status === L::DEFERRED) {
                        $errors[$key] = $a->reference . ': ' . __('This line is already deferred.');
                        continue;
                    }
                    if (in_array($act, ['reject', 'return', 'defer'], true) && $reason === '') {
                        $errors[$key] = $a->reference . ': ' . match ($act) { 'reject' => __('Say why it is rejected.'), 'return' => __('Say what must be corrected.'), default => __('Say why the decision is deferred.') };
                        continue;
                    }
                    $before = $l->status;
                    if ($act === 'approve') {
                        if (! $a->isPayable()) {
                            $errors[$key] = $a->reference . ': ' . __('The agreement is :s: payments cannot be approved.', ['s' => strtolower(__($a->status))]);
                            continue;
                        }
                        $typed = $dec['amount'] ?? null;
                        $amount = ($typed === null || trim((string) $typed) === '') ? (string) $l->requested_amount : self::typed($typed);
                        if ($amount === null || bccomp($amount, '0', 2) <= 0 || bccomp($amount, (string) $l->requested_amount, 2) > 0) {
                            $errors[$key] = $a->reference . ': ' . __('The approved amount must be above zero and not above the amount requested.');
                            continue;
                        }
                        $head = SubcontractLedger::headroomFor($a->id);
                        $over = bcsub($amount, bcadd($head, $tolerance, 2), 2);
                        $why = '';
                        if (bccomp($over, '0', 2) > 0) {
                            $why = mb_substr(trim((string) ($dec['override_reason'] ?? '')), 0, 1000);
                            if (! $overrideAllowed || $why === '') {
                                $errors[$key] = $a->reference . ': ' . __('This is :o more than the contract still allows (remaining :h). An override with a reason, by someone allowed to give one, is needed; the excess is then carried forward as a negative balance.', ['o' => self::fmt($over, $a->currency), 'h' => self::fmt($head, $a->currency)]);
                                $refusals[] = ['line' => $l, 'agreement' => $a, 'over' => $over, 'claim' => $claim];
                                continue;
                            }
                        }
                        $req = R::create(['reference' => R::nextReference(), 'account_id' => $a->account_id, 'agreement_id' => $a->id, 'contact_id' => $a->contact_id, 'warehouse_id' => $a->warehouse_id, 'request_date' => $claim->request_date, 'currency' => $a->currency,
                            'requested_amount' => $l->requested_amount, 'approved_amount' => $amount, 'kind' => 'work', 'description' => __('Contract payment (claim :c)', ['c' => $claim->reference]), 'status' => R::APPROVED,
                            'requested_by_user_id' => $claim->requested_by_user_id, 'submitted_by_user_id' => $claim->submitted_by_user_id, 'submitted_at' => $claim->submitted_at, 'reviewed_by_user_id' => $claim->reviewed_by_user_id, 'reviewed_at' => $claim->reviewed_at,
                            'approved_by_user_id' => $user->id, 'approved_at' => now(), 'decision_remarks' => $reason ?: null, 'claim_line_id' => $l->id,
                            'override' => $why !== '', 'override_reason' => $why ?: null, 'override_by_user_id' => $why !== '' ? $user->id : null]);
                        $l->forceFill(['status' => L::APPROVED, 'approved_amount' => $amount, 'decision_reason' => $reason ?: null, 'decided_by_user_id' => $user->id, 'decided_at' => now(), 'request_id' => $req->id,
                            'override' => $why !== '', 'override_reason' => $why ?: null, 'override_by_user_id' => $why !== '' ? $user->id : null])->save();
                        if ($why !== '') {
                            $overrides[] = ['line' => $l, 'agreement' => $a, 'over' => $over, 'reason' => $why, 'claim' => $claim, 'request' => $req];
                        }
                        $results[$lid] = ['line_id' => $lid, 'action' => 'approve', 'status' => L::APPROVED, 'approved_amount' => $amount, 'request_id' => $req->id, 'request' => $req->reference];
                    } else {
                        $to = ['reject' => L::REJECTED, 'return' => L::RETURNED, 'defer' => L::DEFERRED][$act];
                        $l->forceFill(['status' => $to, 'approved_amount' => null, 'decision_reason' => $reason, 'decided_by_user_id' => $user->id, 'decided_at' => now()])->save();
                        $results[$lid] = ['line_id' => $lid, 'action' => $act, 'status' => $to];
                    }
                    log_activity('Subcontract claim ' . $claim->reference . ' line ' . $a->reference . ' ' . $act, self::props($claim, [
                        'agreement' => $a->reference, 'project' => self::projectName($a->warehouse_id), 'from' => $before, 'to' => $l->status, 'requested' => (string) $l->requested_amount, 'approved' => $l->approved_amount !== null ? (string) $l->approved_amount : null,
                        'reason' => $reason ?: null, 'request' => $results[$lid]['request'] ?? null, 'override' => $l->override ? ['by' => $user->id, 'reason' => $l->override_reason] : null,
                        'self_decision' => (int) $claim->requested_by_user_id === (int) $user->id || (int) $claim->submitted_by_user_id === (int) $user->id ? true : null,
                    ]), $claim, 'Subcontracts');
                }
                if ($errors) {
                    throw ValidationException::withMessages($errors); // nothing is kept: the whole decision is rolled back
                }
                if (! $claim->reviewed_by_user_id) {
                    $claim->forceFill(['reviewed_by_user_id' => $user->id, 'reviewed_at' => now()])->save();
                }
                self::recalc($claim);

                return $claim;
            });
        };
        try {
            $claim = $needsRef ? ReferenceLock::run('subcontract_requests', $work) : $work();
        } catch (ValidationException $e) {
            foreach ($refusals as $x) {
                self::overpaymentRefused($x['claim'], $x['line'], $x['agreement'], $x['over'], $user);
            }
            throw $e;
        }
        $kinds = collect($results)->pluck('action');
        $msg = __(':u decided :r: :a approved, :j rejected, :t returned, :d deferred.', ['u' => $user->name, 'r' => $claim->reference, 'a' => $kinds->filter(fn ($k) => $k === 'approve')->count(), 'j' => $kinds->filter(fn ($k) => $k === 'reject')->count(), 't' => $kinds->filter(fn ($k) => $k === 'return')->count(), 'd' => $kinds->filter(fn ($k) => $k === 'defer')->count()]);
        if ($kinds->contains('return')) {
            SubcontractNotifier::fire('claim_returned', 'claim', $claim, $user, collect($results)->where('action', 'return')->map(fn ($r) => optional(L::find($r['line_id']))->decision_reason)->filter()->implode('; ') ?: $msg, ['amount' => (string) $claim->requested_total]);
        }
        if ($kinds->contains(fn ($k) => $k !== 'return')) {
            SubcontractNotifier::fire('claim_decided', 'claim', $claim, $user, $msg, ['amount' => (string) $claim->approved_total]);
        }
        foreach ($overrides as $o) {
            $a = $o['agreement'];
            SubcontractNotifier::fire('limit_exceeded', 'claim', $o['claim'], $user, __(':r (:a) approved with an override (:o above the remaining value): :why', ['r' => $o['claim']->reference, 'a' => $a->reference, 'o' => $o['over'], 'why' => $o['reason']]));
            SubcontractAlerts::raise('control', 'override_used', 'high', __(':r was approved above the contract value', ['r' => $o['request']->reference]), __(':o above the remaining value, approved by :u with an override: :why', ['o' => $o['over'], 'u' => $user->name, 'why' => $o['reason']]),
                ['agreement_id' => $a->id, 'contact_id' => $a->contact_id, 'subject_type' => 'request', 'subject_id' => $o['request']->id, 'amount' => (string) $o['line']->approved_amount, 'currency' => $a->currency, 'trigger' => 'event', 'dedupe' => 'override-' . $o['request']->id]);
        }

        return ['claim' => $claim->refresh(), 'results' => array_values($results)];
    }

    private static function overpaymentRefused(C $claim, L $line, A $a, string $over, User $user): void
    {
        log_activity('Subcontract overpayment refused', ['claim' => $claim->reference, 'agreement' => $a->reference, 'over' => $over], $claim, 'Subcontracts');
        SubcontractNotifier::fire('limit_exceeded', 'claim', $claim, $user, __('Approval of :r (:a) refused: :o above the remaining contract value.', ['r' => $claim->reference, 'a' => $a->reference, 'o' => $over]));
        SubcontractAlerts::raise('control', 'overpayment_refused', 'medium', __('Approval of :r was refused', ['r' => $claim->reference . ' / ' . $a->reference]), __(':o above the remaining contract value; tried by :u.', ['o' => $over, 'u' => $user->name]),
            ['agreement_id' => $a->id, 'contact_id' => $a->contact_id, 'subject_type' => 'claim', 'subject_id' => $claim->id, 'currency' => $a->currency, 'trigger' => 'event', 'dedupe' => 'claim-refused-' . $line->id]);
    }

    /** The requester corrects a RETURNED line (a new amount, a note) and sends that line back for decision. */
    public static function resubmitLine(int $claimId, int $lineId, ?string $amount, ?string $note, User $user): C
    {
        $agr = L::query()->where('claim_id', $claimId)->whereKey($lineId)->value('agreement_id');
        abort_unless($agr, 404);
        $claim = DB::transaction(function () use ($claimId, $lineId, $amount, $note, $user, $agr) {
            $ags = self::lockAgreements([$agr]);
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            $l = L::query()->where('claim_id', $claim->id)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            $key = 'lines.' . $l->agreement_id;
            if ($l->status !== L::RETURNED) {
                self::fail($key, __('Only a returned line can be corrected and sent again.'));
            }
            $money = $user->can('view-financials-subcontracts');
            if ($amount !== null && trim($amount) !== '' && $money) {
                $amt = self::typed($amount);
                if ($amt === null || bccomp($amt, '0', 2) <= 0) {
                    self::fail($key, __('The amount must be a number above zero.'));
                }
                if (! self::allowsPartial()) {
                    $f = SubcontractLedger::forAgreement($l->agreement_id);
                    if (bccomp($amt, bcadd($f['remaining_eligible_signed'], (string) $l->requested_amount, 2), 2) !== 0) {
                        self::fail($key, __('Partial requests are not allowed: ask for the whole balance.'));
                    }
                }
                $l->requested_amount = $amt;
            }
            if ($note !== null) {
                $l->note = trim($note) ?: $l->note;
            }
            $history = ($l->snapshot ?? [])['history'] ?? [];
            $history[] = ['status' => $l->status, 'by' => $l->decided_by_user_id, 'at' => optional($l->decided_at)->toDateTimeString(), 'reason' => $l->decision_reason, 'requested' => (string) $l->getOriginal('requested_amount')];
            self::checkLines($claim, collect([$l]), $ags, $user);
            $l->forceFill(['status' => L::PENDING, 'decision_reason' => null, 'decided_by_user_id' => null, 'decided_at' => null, 'snapshot' => self::snapshot(SubcontractLedger::forAgreement($l->agreement_id)) + ['history' => $history]])->save();
            $claim->forceFill(['submitted_by_user_id' => $claim->submitted_by_user_id ?: $user->id])->save();
            self::recalc($claim);
            log_activity('Subcontract claim ' . $claim->reference . ' line ' . optional($ags[$l->agreement_id])->reference . ' corrected and sent again', self::props($claim, ['agreement' => optional($ags[$l->agreement_id])->reference, 'requested' => (string) $l->requested_amount]), $claim, 'Subcontracts');

            return $claim;
        });
        SubcontractNotifier::fire('claim_submitted', 'claim', $claim, $user, __('A corrected line of :r was sent again.', ['r' => $claim->reference]), ['amount' => (string) $claim->requested_total]);

        return $claim;
    }

    /** Drops one open line (releases its reservation). Allowed for a Draft-free open line: Pending, Returned or Deferred. */
    public static function cancelLine(int $claimId, int $lineId, User $user, string $reason): C
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') {
            self::fail('reason', __('Say why it is cancelled.'));
        }

        $agr = L::query()->where('claim_id', $claimId)->whereKey($lineId)->value('agreement_id');
        abort_unless($agr, 404);

        return DB::transaction(function () use ($claimId, $lineId, $user, $reason, $agr) {
            $ags = self::lockAgreements([$agr]);
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            $l = L::query()->where('claim_id', $claim->id)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            if (! in_array($l->status, [L::PENDING, L::RETURNED, L::DEFERRED], true)) {
                self::fail('line_' . $l->id, __('This line is already :s.', ['s' => strtolower(__($l->status))]));
            }
            $l->forceFill(['status' => L::CANCELLED, 'decision_reason' => $reason, 'decided_by_user_id' => $user->id, 'decided_at' => now()])->save();
            self::recalc($claim);
            log_activity('Subcontract claim ' . $claim->reference . ' line ' . optional($ags[$l->agreement_id])->reference . ' cancelled', self::props($claim, ['agreement' => optional($ags[$l->agreement_id])->reference, 'requested' => (string) $l->requested_amount, 'reason' => $reason]), $claim, 'Subcontracts');

            return $claim;
        });
    }

    /**
     * Takes an APPROVED line back: its payment request is cancelled by the existing request rules (no payment may have been started; say why). The line is then Cancelled
     * and its amount is open to request again.
     */
    public static function reverseLine(int $claimId, int $lineId, User $user, string $reason): C
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') {
            self::fail('reason', __('Say why it is cancelled.'));
        }

        $agr = L::query()->where('claim_id', $claimId)->whereKey($lineId)->value('agreement_id');
        abort_unless($agr, 404);

        return DB::transaction(function () use ($claimId, $lineId, $user, $reason, $agr) {
            $ags = self::lockAgreements([$agr]);
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            $l = L::query()->where('claim_id', $claim->id)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            $key = 'line_' . $l->id;
            if ($l->status !== L::APPROVED || ! $l->request_id) {
                self::fail($key, __('Only an approved line can be taken back.'));
            }
            $req = R::query()->whereKey($l->request_id)->lockForUpdate()->firstOrFail();
            self::sweepVoid($claim->id);
            if ($req->status !== R::APPROVED) {
                self::fail($key, __('The payment request of this line is already :s.', ['s' => strtolower(__($req->status))]));
            }
            if ($req->payments()->where('status', '<>', 'cancelled')->exists()) {
                self::fail($key, __('A payment was started for this line: cancel the voucher first.'));
            }
            $req->forceFill(['status' => R::CANCELLED, 'decision_remarks' => $reason])->save();
            $l->forceFill(['status' => L::CANCELLED, 'decision_reason' => $reason, 'decided_by_user_id' => $user->id, 'decided_at' => now()])->save();
            self::recalc($claim);
            log_activity('Subcontract claim ' . $claim->reference . ' line ' . optional($ags[$l->agreement_id])->reference . ' taken back', self::props($claim, ['agreement' => optional($ags[$l->agreement_id])->reference, 'request' => $req->reference, 'approved' => (string) $l->approved_amount, 'reason' => $reason]), $claim, 'Subcontracts');

            return $claim;
        });
    }

    /** Called inside the transaction of PaymentRequestController when an APPROVED request that came from a claim line is cancelled there: the line follows it. */
    public static function onRequestCancelled(R $req, User $user, string $reason): void
    {
        if (! $req->claim_line_id) {
            return;
        }
        $l = L::query()->whereKey($req->claim_line_id)->lockForUpdate()->first();
        if (! $l || $l->status !== L::APPROVED) {
            return;
        }
        $claim = C::query()->whereKey($l->claim_id)->lockForUpdate()->first();
        $l->forceFill(['status' => L::CANCELLED, 'decision_reason' => $reason ?: $l->decision_reason, 'decided_by_user_id' => $user->id, 'decided_at' => now()])->save();
        if ($claim) {
            self::recalc($claim);
            log_activity('Subcontract claim ' . $claim->reference . ' line taken back through its payment request ' . $req->reference, self::props($claim, ['request' => $req->reference, 'reason' => $reason ?: null]), $claim, 'Subcontracts');
        }
    }

    // ---- status ----------------------------------------------------------------------------------------------------------------------------

    /** The status of a claim, worked out from its lines, and the totals kept in step. Called inside every transaction that changes a line. */
    public static function recalc(C $claim): C
    {
        $lines = L::query()->where('claim_id', $claim->id)->where('status', '<>', L::CANCELLED)->get(['status', 'requested_amount', 'approved_amount']);
        $n = fn (string $s) => $lines->where('status', $s)->count();
        $total = $lines->count();
        [$d, $p, $a, $j, $t, $f] = [$n(L::DRAFT), $n(L::PENDING), $n(L::APPROVED), $n(L::REJECTED), $n(L::RETURNED), $n(L::DEFERRED)];
        $status = match (true) {
            $total === 0 => C::CANCELLED,
            $d === $total => C::DRAFT,
            $a === $total => C::APPROVED,
            $a >= 1 => C::PARTIAL,                          // something approved and something else still open or refused
            $p >= 1 && ($j + $t + $f) === 0 => C::SUBMITTED, // nothing decided yet
            $p >= 1 => C::REVIEW,                           // some decided, some pending
            $t >= 1 => C::RETURNED,
            $f >= 1 => C::DEFERRED,
            default => C::REJECTED,
        };
        $claim->forceFill(['status' => $status,
            'requested_total' => $lines->reduce(fn ($c, $l) => bcadd($c, (string) $l->requested_amount, 2), '0.00'),
            'approved_total' => $lines->where('status', L::APPROVED)->reduce(fn ($c, $l) => bcadd($c, (string) $l->approved_amount, 2), '0.00')])->save();

        return $claim;
    }

    /** The log properties every claim entry carries: actor, contract-independent facts about the claim. */
    private static function props(C $claim, array $more = []): array
    {
        return array_filter($more + ['claim' => $claim->reference, 'status' => $claim->status, 'contact' => $claim->contact_id, 'project' => self::projectName($claim->warehouse_id), 'requested_total' => (string) $claim->requested_total, 'approved_total' => (string) $claim->approved_total, 'actor' => auth()->id()], fn ($v) => $v !== null);
    }

    // ---- payment progress and presentation -------------------------------------------------------------------------------------------------

    /** Unpaid | Partly Paid | Paid for a request's figures (never stored). */
    public static function progress(array $rf): string
    {
        if (bccomp($rf['approved'], '0', 2) <= 0) {
            return 'Unpaid';
        }
        if (bccomp($rf['paid'], $rf['approved'], 2) >= 0) {
            return 'Paid';
        }

        return bccomp($rf['paid'], '0', 2) > 0 ? 'Partly Paid' : 'Unpaid';
    }

    /** Payment figures of every line of the claims (two queries) and of each claim in total. @param array<int,int> $claimIds @return array{lines:array<int,array>,claims:array<int,array>} */
    public static function figures(array $claimIds): array
    {
        $lines = L::query()->whereIn('claim_id', $claimIds ?: [0])->get(['id', 'claim_id', 'status', 'request_id', 'approved_amount']);
        $rf = SubcontractLedger::requestFiguresMany($lines->pluck('request_id')->filter()->all());
        $out = ['lines' => [], 'claims' => []];
        foreach ($lines as $l) {
            if ($l->status === L::APPROVED && $l->request_id && isset($rf[$l->request_id])) {
                $x = $rf[$l->request_id];
                $out['lines'][$l->id] = $x + ['progress' => self::progress($x)];
                $c = &$out['claims'][$l->claim_id];
                $c ??= ['approved' => '0.00', 'paid' => '0.00', 'in_process' => '0.00', 'left' => '0.00'];
                foreach (['approved', 'paid', 'in_process', 'left'] as $k) {
                    $c[$k] = bcadd($c[$k], $x[$k], 2);
                }
                unset($c);
            }
        }
        foreach ($out['claims'] as $id => $c) {
            $out['claims'][$id]['progress'] = self::progress($c);
        }

        return $out;
    }

    /** The claims an agreement is on (for the agreement page). */
    public static function claimsOfAgreement(int $agreementId, bool $money): array
    {
        $rows = L::query()->where('agreement_id', $agreementId)->whereHas('claim')->with('claim:id,reference,status,request_date')->orderByDesc('id')->limit(100)->get();
        $rf = SubcontractLedger::requestFiguresMany($rows->pluck('request_id')->filter()->all());

        return $rows->map(function ($l) use ($money, $rf) {
            $x = $l->request_id ? ($rf[$l->request_id] ?? null) : null;

            return ['claim_id' => $l->claim_id, 'reference' => $l->claim->reference, 'claim_status' => $l->claim->status, 'date' => optional($l->claim->request_date)->toDateString(), 'line_id' => $l->id, 'line_status' => $l->status,
                'requested' => $money ? (string) $l->requested_amount : null, 'approved' => $money && $l->approved_amount !== null ? (string) $l->approved_amount : null, 'paid' => $money && $x ? $x['paid'] : null, 'progress' => $x ? self::progress($x) : null, 'url' => route('subcontracts.claims.show', $l->claim_id)];
        })->values()->all();
    }

    // ---- vouchers --------------------------------------------------------------------------------------------------------------------------

    /** A payment whose voucher was rejected, cancelled or deleted is void: ALL the payments that share that voucher are freed together. */
    public static function sweepVoid(int $claimId): int
    {
        $freed = 0;
        $byVoucher = P::query()->where('claim_id', $claimId)->where('status', 'voucher')->whereNotNull('payment_voucher_id')->get()->groupBy('payment_voucher_id');
        foreach ($byVoucher as $vid => $payments) {
            $v = DB::table('payment_vouchers')->where('id', $vid)->first(['status', 'deleted_at', 'pv_number']);
            if (! $v || $v->deleted_at || in_array($v->status, ['Rejected', 'Cancelled'], true)) {
                foreach ($payments as $p) {
                    $p->update(['status' => 'cancelled']);
                    $freed++;
                }
                log_activity('Subcontract claim voucher void: its voucher is no longer valid', ['voucher' => $v->pv_number ?? $vid, 'payments' => $payments->count()], C::withoutGlobalScopes()->find($claimId), 'Subcontracts');
            }
        }

        return $freed;
    }

    /** A voucher that is cancelled or deleted frees every claim payment on it at once (called by the Payment Voucher module). */
    public static function releaseVoucher(int $voucherId, string $why): void
    {
        $payments = P::query()->where('payment_voucher_id', $voucherId)->whereNotNull('claim_id')->where('status', '<>', 'cancelled')->get();
        foreach ($payments->groupBy('claim_id') as $claimId => $set) {
            foreach ($set as $p) {
                $p->update(['status' => 'cancelled']);
            }
            log_activity('Subcontract claim voucher ' . $why . ': its payments are free again', ['voucher' => $voucherId, 'payments' => $set->count()], C::withoutGlobalScopes()->find($claimId), 'Subcontracts');
        }
    }

    /** The open payments of a claim: started (initiated) and not yet on a voucher. */
    public static function openPayments(int $claimId)
    {
        return P::query()->where('claim_id', $claimId)->where('status', 'initiated')->whereNull('payment_voucher_id')->orderBy('id');
    }

    /**
     * Starts ONE Payment Voucher for several approved lines. $amounts: [line_id => amount] (blank = all that is left on the line). Only one open set per claim; nothing is paid.
     * @return array{claim:C,payments:array<int,P>,total_tzs:string}
     */
    public static function startVoucher(int $claimId, array $amounts, ?string $rate, ?string $rateDate, User $user): array
    {
        if (! $amounts) {
            self::fail('lines', __('Choose at least one line to pay.'));
        }
        $lineIds = array_values(array_unique(array_map('intval', array_keys($amounts))));
        sort($lineIds);
        $agrIds = L::query()->where('claim_id', $claimId)->whereIn('id', $lineIds)->pluck('agreement_id')->all();
        if (count($agrIds) !== count($lineIds)) {
            abort(404);
        }
        $visible = A::query()->visibleTo($user)->whereIn('id', $agrIds)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (count($visible) !== count(array_unique($agrIds))) {
            abort(403);
        }

        return DB::transaction(function () use ($claimId, $amounts, $lineIds, $agrIds, $rate, $rateDate, $user) {
            $ags = self::lockAgreements($agrIds);
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            self::sweepVoid($claim->id);
            if (self::openPayments($claim->id)->exists()) {
                self::fail('lines', __('A voucher was already started for this claim. Finish it or cancel it first.'));
            }
            $lines = L::query()->where('claim_id', $claim->id)->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
            $reqs = R::query()->whereIn('id', $lines->pluck('request_id')->filter()->all() ?: [0])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $rf = SubcontractLedger::requestFiguresMany($reqs->keys()->all());
            $currency = $claim->currency;
            $rateStr = null;
            if ($currency !== 'TZS') {
                if (! $rate || ! $rateDate || ! preg_match('/^\d+(\.\d+)?$/', (string) $rate) || bccomp((string) $rate, '0', 6) <= 0 || bccomp((string) $rate, '99999999', 6) > 0) {
                    self::fail('exchange_rate', __('The claim is in :c: enter the exchange rate to TZS and its date.', ['c' => $currency]));
                }
                $rateStr = bcadd((string) $rate, '0', 6);
            }
            $payments = [];
            $totalTzs = '0.00';
            foreach ($lines as $l) {
                $a = $ags[$l->agreement_id];
                $req = $reqs[$l->request_id] ?? null;
                $key = 'lines.' . $l->id;
                if ($l->status !== L::APPROVED || ! $req || $req->status !== R::APPROVED) {
                    self::fail($key, $a->reference . ': ' . __('Only an approved line can be paid.'));
                }
                if (! $a->isPayable()) {
                    self::fail($key, $a->reference . ': ' . __('The agreement is :s: it cannot be paid.', ['s' => strtolower(__($a->status))]));
                }
                if (P::query()->where('request_id', $req->id)->where('status', 'initiated')->whereNull('payment_voucher_id')->exists()) {
                    self::fail($key, $a->reference . ': ' . __('A voucher was already started for this request. Finish it or cancel it first.'));
                }
                $left = $rf[$req->id]['left'];
                $typed = $amounts[$l->id] ?? null;
                $amount = ($typed === null || trim((string) $typed) === '') ? $left : self::typed($typed);
                if ($amount === null || bccomp($amount, '0', 2) <= 0) {
                    self::fail($key, $a->reference . ': ' . __('Enter the amount to pay.'));
                }
                if (bccomp($amount, $left, 2) > 0) {
                    self::fail($key, $a->reference . ': ' . __('Only :l is left to pay on this line.', ['l' => self::fmt($left, $currency)]));
                }
                $tzs = $rateStr ? SubcontractAmounts::round2(bcmul($amount, $rateStr, 6)) : $amount;
                if (bccomp($tzs, '9999999999999.99', 2) > 0) {
                    self::fail($key, __('The amount in TZS is too large.'));
                }
                $p = P::create(['request_id' => $req->id, 'agreement_id' => $a->id, 'amount' => $amount, 'currency' => $currency, 'exchange_rate' => $rateStr, 'rate_date' => $rateStr ? $rateDate : null, 'amount_tzs' => $tzs, 'status' => 'initiated',
                    'created_by_user_id' => $user->id, 'claim_id' => $claim->id, 'claim_line_id' => $l->id]);
                self::allocate($p, $req, $amount);
                $payments[] = $p;
                $totalTzs = bcadd($totalTzs, $tzs, 2);
            }
            if (bccomp($totalTzs, '9999999999999.99', 2) > 0) {
                self::fail('lines', __('The amount in TZS is too large.'));
            }
            log_activity('Subcontract claim ' . $claim->reference . ' payment started', self::props($claim, ['total_tzs' => $totalTzs, 'lines' => collect($payments)->map(fn ($p) => ['agreement' => $ags[$p->agreement_id]->reference, 'amount' => (string) $p->amount, 'amount_tzs' => (string) $p->amount_tzs])->all()]), $claim, 'Subcontracts');

            return ['claim' => $claim, 'payments' => $payments, 'total_tzs' => $totalTzs];
        });
    }

    /** Cancels the open set of payments (no voucher yet) so the lines can be paid another way. */
    public static function cancelOpenPayments(int $claimId, User $user): int
    {
        return DB::transaction(function () use ($claimId, $user) {
            $claim = C::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            $open = self::openPayments($claim->id)->lockForUpdate()->get();
            if ($open->isEmpty()) {
                self::fail('claim', __('Only a payment without a voucher can be cancelled here. A voucher is cancelled in Payment Vouchers.'));
            }
            foreach ($open as $p) {
                $p->update(['status' => 'cancelled']);
            }
            log_activity('Subcontract claim ' . $claim->reference . ' payment cancelled', self::props($claim, ['payments' => $open->count(), 'by' => $user->id]), $claim, 'Subcontracts');

            return $open->count();
        });
    }

    /** Spreads a payment over the work items of the request in proportion to what was requested (cents never lost: the last line takes the rest). */
    public static function allocate(P $p, R $r, string $amount): void
    {
        $items = $r->items()->whereNotNull('work_item_id')->get();
        $total = (string) $items->sum('amount');
        if ($items->isEmpty() || bccomp($total, '0', 2) <= 0) {
            return;
        }
        $left = $amount;
        foreach ($items->values() as $k => $i) {
            $part = $k === $items->count() - 1 ? $left : SubcontractAmounts::round2(bcdiv(bcmul($amount, (string) $i->amount, 6), $total, 6));
            if (bccomp($part, $left, 2) > 0) {
                $part = $left;
            }
            if (bccomp($part, '0', 2) > 0) {
                \App\Models\SubcontractPaymentAllocation::create(['payment_id' => $p->id, 'work_item_id' => $i->work_item_id, 'amount' => $part]);
            }
            $left = bcsub($left, $part, 2);
        }
    }

    /** What a voucher of a claim pays, line by line (for the voucher page and the claim page). @return array<int,array<string,mixed>> */
    public static function allocationsOfVoucher(int $voucherId, bool $money): array
    {
        return P::query()->where('payment_voucher_id', $voucherId)->whereNotNull('claim_id')->where('status', '<>', 'cancelled')->with(['agreement:id,reference,title,currency', 'request:id,reference'])->orderBy('id')->get()
            ->map(fn ($p) => ['payment_id' => $p->id, 'claim_line_id' => $p->claim_line_id, 'agreement_id' => $p->agreement_id, 'agreement' => optional($p->agreement)->reference, 'title' => optional($p->agreement)->title, 'request_id' => $p->request_id, 'request' => optional($p->request)->reference,
                'currency' => $p->currency, 'amount' => $money ? (string) $p->amount : null, 'amount_tzs' => $money ? (string) $p->amount_tzs : null])->values()->all();
    }
}
