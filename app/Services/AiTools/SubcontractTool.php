<?php

namespace App\Services\AiTools;

use App\Models\Contact;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\SubcontractVariation as V;
use App\Models\User;
use App\Services\Subcontracts\SubcontractLedger;

/**
 * ARIS answers about subcontractors, from the verified records (SubcontractLedger) and only to people who may see them. Amounts are never added across currencies, and the answer
 * always separates contract value, requested, approved and actually paid (a payment counts only when its Payment Voucher is paid).
 */
class SubcontractTool
{
    private function denied(User $user, bool $money = true): ?array
    {
        if (! $user->can('read-subcontracts') && ! $user->can('read-subcontract-agreements')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to subcontractor records.'];
        }
        if ($money && ! $user->can('view-financials-subcontracts')) {
            return ['error' => 'not_permitted', 'message' => 'You may not see subcontract amounts.'];
        }

        return null;
    }

    private function contact(?string $name): ?Contact
    {
        $name = trim((string) $name);

        return $name === '' ? null : Contact::query()->where('category', 'SubContractors')->where('name', 'like', '%' . $name . '%')->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$name])->first();
    }

    /** Contract value, approved variations, paid, approved unpaid and outstanding of one subcontractor. */
    public function summary(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $c = $this->contact($input['subcontractor_name'] ?? null);
        if (! $c) {
            return ['found' => false, 'message' => 'I could not find a subcontractor with that name in Contacts.'];
        }
        $ids = A::query()->visibleTo($user)->where('contact_id', $c->id)->pluck('id')->all();
        if (! $ids) {
            return ['found' => true, 'name' => $c->name, 'agreements' => 0, 'totals' => []];
        }

        return ['found' => true, 'name' => $c->name, 'agreements' => count($ids), 'totals' => SubcontractLedger::forContact($c->id, $ids)];
    }

    /** What waits for a decision. */
    public function pending(array $input, User $user): array
    {
        if ($d = $this->denied($user, false)) {
            return $d;
        }
        $ids = A::query()->visibleTo($user)->pluck('id');

        return ['found' => true, 'agreements' => A::query()->visibleTo($user)->whereIn('status', [A::SUBMITTED, A::REVIEW])->count(), 'variations' => V::query()->whereIn('agreement_id', $ids)->whereIn('status', [V::SUBMITTED, V::REVIEWED])->count(),
            'requests' => R::query()->whereIn('agreement_id', $ids)->whereIn('status', [R::SUBMITTED, R::REVIEW])->count(), 'approved_unpaid_requests' => R::query()->whereIn('agreement_id', $ids)->where('status', R::APPROVED)->count()];
    }

    /** Contract payment requests (claims) with lines waiting for a decision, returned for correction or deferred. Amounts only for who may see subcontract money. */
    public function claimsWaiting(array $input, User $user): array
    {
        if (! $user->can('read-subcontract-claims') && ! $user->can('decide-subcontract-claims')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to contract payment requests.'];
        }
        $money = $user->can('view-financials-subcontracts');
        $ok = A::query()->visibleTo($user)->select('id');
        $rows = \Illuminate\Support\Facades\DB::table('subcontract_claim_lines as l')->join('subcontract_claims as c', 'c.id', '=', 'l.claim_id')->join('contacts as ct', 'ct.id', '=', 'c.contact_id')->whereNull('l.deleted_at')->whereNull('c.deleted_at')
            ->whereNotIn('c.status', ['Draft', 'Cancelled'])->whereIn('l.status', ['Pending', 'Returned', 'Deferred'])->whereIn('l.agreement_id', $ok)->get(['c.id as cid', 'c.reference', 'c.currency', 'ct.name as cname', 'l.status', 'l.requested_amount']);
        $pending = $rows->where('status', 'Pending');
        $totals = [];
        foreach ($pending as $r) {
            $totals[$r->currency] = bcadd($totals[$r->currency] ?? '0.00', (string) $r->requested_amount, 2);
        }
        $items = $pending->groupBy('cid')->map(fn ($g) => $g->first()->reference . ' ' . $g->first()->cname . ' (' . $g->count() . ' line(s)' . ($money ? ', ' . $g->first()->currency . ' ' . number_format((float) $g->reduce(fn ($t, $r) => bcadd($t, (string) $r->requested_amount, 2), '0.00'), 2) : '') . ')')->values()->take(10)->all();

        return ['found' => true, 'claims' => $pending->pluck('cid')->unique()->count(), 'lines' => $pending->count(), 'returned_lines' => $rows->where('status', 'Returned')->count(), 'deferred_lines' => $rows->where('status', 'Deferred')->count(), 'totals' => $money ? $totals : null, 'items' => $items];
    }

    /** Completed agreements that are not fully paid yet: how many can be claimed now and how many wait for verification or evidence. Needs the financial permission (it is about balances). */
    public function completedUnpaid(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $ags = A::query()->visibleTo($user)->where('status', A::COMPLETED)->limit(2000)->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $ev = \App\Services\Subcontracts\SubcontractClaims::evidenceCounts($ags->pluck('id')->all());
        $out = ['found' => true, 'eligible' => 0, 'waiting_verification' => 0, 'missing_evidence' => 0, 'no_balance_open' => 0, 'outstanding' => []];
        foreach ($ags as $a) {
            $f = $fig[$a->id];
            if (bccomp($f['commitment_left'], '0', 2) <= 0) {
                continue;
            }
            $out['outstanding'][$a->currency] = bcadd($out['outstanding'][$a->currency] ?? '0.00', $f['commitment_left'], 2);
            $why = \App\Services\Subcontracts\SubcontractClaims::blockReason($a, $ev[$a->id] ?? 0);
            if ($why === 'not_verified') {
                $out['waiting_verification']++;
            } elseif ($why === 'no_evidence') {
                $out['missing_evidence']++;
            } elseif (bccomp($f['remaining_eligible_signed'], '0', 2) > 0) {
                $out['eligible']++;
            } else {
                $out['no_balance_open']++;
            }
        }

        return $out;
    }

    /** Imported accounts whose workbook figures are not reconciled. */
    public function unreconciled(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        if (! $user->can('reconcile-subcontracts') && ! $user->can('approve-reconciliation-subcontracts')) {
            return ['error' => 'not_permitted', 'message' => 'You may not see the import reconciliation.'];
        }
        $rows = \Illuminate\Support\Facades\DB::table('subcontract_reconciliations as r')->join('subcontract_agreements as a', 'a.id', '=', 'r.agreement_id')->where('r.status', 'open')->whereNull('r.deleted_at')->whereIn('a.id', A::query()->visibleTo($user)->select('id'))->limit(15)->get(['a.reference', 'a.title', 'r.source_paid', 'r.imported_paid']);

        return ['found' => true, 'count' => $rows->count(), 'items' => $rows->map(fn ($r) => $r->reference . ' ' . $r->title . ' (workbook paid ' . number_format((float) $r->source_paid, 2) . ', imported ' . number_format((float) $r->imported_paid, 2) . ')')->all()];
    }
}
