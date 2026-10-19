<?php

namespace App\Services\Subcontracts;

use App\Models\SubcontractAgreement;
use App\Models\SubcontractClaimLine;
use App\Models\SubcontractPaymentRequest;
use App\Models\SubcontractVariation;
use App\Support\SchemaCache;
use Illuminate\Support\Facades\DB;

/**
 * The one place where every subcontract figure is defined, so the dashboard, the profile, the statement, the reports and ARIS agree.
 * Amounts are decimal strings (bcmath, 2 places) in the AGREEMENT's currency; figures of different currencies are never added together.
 *
 *   original            the approved agreement amount, never overwritten
 *   variations_net      approved increases minus approved reductions
 *   revised             original + variations_net
 *   scheduled           the work schedule total (active items; items of a variation count only once it is Approved), for comparison with `revised`
 *   requested_pending   requests that were submitted and are not decided (Submitted, Under Review, Returned), PLUS the claim lines (Contract Payment Requests) that are Pending, Returned or
 *                       Deferred in a claim that is not a draft or cancelled: they hold (reserve) that part of the balance until they are approved, rejected or cancelled
 *   claims_pending      just the claim part of requested_pending
 *   approved_requests   approved amount of Approved requests
 *   paid_live           payments whose Payment Voucher is Authorised and has a paid date (nothing else is "paid")
 *   in_process          payments initiated or with a voucher that is not paid yet (Draft, Submitted, Approved, Authorised but unpaid)
 *   paid_legacy         counted historical (Excel) payments
 *   paid_total          paid_live + paid_legacy
 *   approved_unpaid     approved request amounts minus what has actually been paid on them (this is what is still to be paid on approved requests)
 *   approved_work       paid_legacy + approved_requests: the value of work formally accepted
 *   commitment_left     revised - paid_total (the contract value still to be paid)
 *   headroom            revised - paid_legacy - approved_requests (how much more can still be approved)
 *   remaining_eligible_signed  revised - approved_work - requested_pending: what a NEW claim may still ask for on this agreement (requests and claims already waiting hold their part)
 *   remaining_eligible  the same, never below zero (for display)
 * An approved claim line has become an ordinary Approved payment request (claim_line_id), so it is already inside approved_requests / approved_unpaid / paid: nothing else changed.
 */
class SubcontractLedger
{
    public const PENDING = [SubcontractPaymentRequest::SUBMITTED, SubcontractPaymentRequest::REVIEW, SubcontractPaymentRequest::RETURNED];

    private static function add(string $a, ?string $b): string
    {
        return bcadd($a, (string) ($b ?? '0'), 2);
    }

    private static function sub(string $a, ?string $b): string
    {
        return bcsub($a, (string) ($b ?? '0'), 2);
    }

    /** A database decimal / SUM as an exact 2-place string (a float would lose the cents above about 7e13). */
    private static function dec($v): string
    {
        if ($v === null || $v === '') {
            return '0.00';
        }

        return is_float($v) ? number_format($v, 2, '.', '') : bcadd((string) $v, '0', 2);
    }

    /** A voucher that has actually been paid: Authorised and a paid date. The only definition of "paid" in the module. */
    public static function voucherIsPaid(?object $voucher): bool
    {
        return $voucher && $voucher->status === 'Authorised' && ! empty($voucher->paid_at) && empty($voucher->deleted_at);
    }

    /** @param array<int,int>|null $ids null = all agreements. @return array<int,array<string,string>> */
    public static function forAgreements(?array $ids = null): array
    {
        $agreements = DB::table('subcontract_agreements')->whereNull('deleted_at')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->get(['id', 'currency', 'original_amount', 'status', 'contact_id', 'warehouse_id']);
        $keys = $agreements->pluck('id')->all();
        $out = [];
        foreach ($agreements as $a) {
            $out[$a->id] = ['currency' => $a->currency, 'original' => self::dec($a->original_amount), 'variations_up' => '0.00', 'variations_down' => '0.00', 'scheduled' => '0.00', 'requested_pending' => '0.00', 'claims_pending' => '0.00',
                'approved_requests' => '0.00', 'paid_live' => '0.00', 'in_process' => '0.00', 'paid_legacy' => '0.00', 'status' => $a->status];
        }
        if (! $keys) {
            return [];
        }

        foreach (DB::table('subcontract_variations')->whereNull('deleted_at')->where('status', SubcontractVariation::APPROVED)->whereIn('agreement_id', $keys)->selectRaw('agreement_id, direction, SUM(amount) s')->groupBy('agreement_id', 'direction')->get() as $r) {
            $out[$r->agreement_id][$r->direction === 'reduction' ? 'variations_down' : 'variations_up'] = self::dec($r->s);
        }
        $items = DB::table('subcontract_work_items as i')->leftJoin('subcontract_variations as v', 'v.id', '=', 'i.variation_id')->whereNull('i.deleted_at')->where('i.status', 'active')->whereIn('i.agreement_id', $keys)
            ->where(fn ($q) => $q->whereNull('i.variation_id')->orWhere('v.status', SubcontractVariation::APPROVED))->selectRaw('i.agreement_id, SUM(i.amount) s')->groupBy('i.agreement_id')->get();
        foreach ($items as $r) {
            $out[$r->agreement_id]['scheduled'] = self::dec($r->s);
        }
        foreach (DB::table('subcontract_payment_requests')->whereNull('deleted_at')->whereIn('agreement_id', $keys)->selectRaw("agreement_id,
                SUM(CASE WHEN status IN ('" . implode("','", self::PENDING) . "') THEN requested_amount ELSE 0 END) pend,
                SUM(CASE WHEN status = 'Approved' THEN COALESCE(approved_amount, 0) ELSE 0 END) appr")->groupBy('agreement_id')->get() as $r) {
            $out[$r->agreement_id]['requested_pending'] = self::dec($r->pend);
            $out[$r->agreement_id]['approved_requests'] = self::dec($r->appr);
        }
        if (SchemaCache::hasTable('subcontract_claim_lines')) {
            // lines of submitted claims that still hold their part of the balance
            foreach (DB::table('subcontract_claim_lines as l')->join('subcontract_claims as c', 'c.id', '=', 'l.claim_id')->whereNull('l.deleted_at')->whereNull('c.deleted_at')->whereNotIn('c.status', ['Draft', 'Cancelled'])
                ->whereIn('l.status', SubcontractClaimLine::RESERVING)->whereIn('l.agreement_id', $keys)->selectRaw('l.agreement_id, SUM(l.requested_amount) s')->groupBy('l.agreement_id')->get() as $r) {
                $out[$r->agreement_id]['claims_pending'] = self::dec($r->s);
                $out[$r->agreement_id]['requested_pending'] = self::add($out[$r->agreement_id]['requested_pending'], self::dec($r->s));
            }
        }
        foreach (DB::table('subcontract_payments as p')->leftJoin('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')->whereIn('p.agreement_id', $keys)
            ->selectRaw("p.agreement_id,
                SUM(CASE WHEN v.status = 'Authorised' AND v.paid_at IS NOT NULL AND v.deleted_at IS NULL THEN p.amount ELSE 0 END) paid,
                SUM(CASE WHEN p.payment_voucher_id IS NULL THEN p.amount WHEN v.id IS NULL THEN 0 WHEN v.deleted_at IS NOT NULL THEN 0 WHEN v.status IN ('Rejected','Cancelled') THEN 0 WHEN v.status = 'Authorised' AND v.paid_at IS NOT NULL THEN 0 ELSE p.amount END) inproc")->groupBy('p.agreement_id')->get() as $r) {
            $out[$r->agreement_id]['paid_live'] = self::dec($r->paid);
            $out[$r->agreement_id]['in_process'] = self::dec($r->inproc);
        }
        foreach (DB::table('subcontract_historical_payments')->whereNull('deleted_at')->where('counted', 1)->whereIn('agreement_id', $keys)->selectRaw('agreement_id, SUM(amount) s')->groupBy('agreement_id')->get() as $r) {
            $out[$r->agreement_id]['paid_legacy'] = self::dec($r->s);
        }

        foreach ($out as $id => $f) {
            $net = self::sub($f['variations_up'], $f['variations_down']);
            $paidTotal = self::add($f['paid_live'], $f['paid_legacy']);
            $out[$id] += [
                'variations_net' => $net,
                'revised' => self::add($f['original'], $net),
                'paid_total' => $paidTotal,
                'approved_work' => self::add($f['paid_legacy'], $f['approved_requests']),
            ];
            $out[$id]['approved_unpaid'] = bccomp($x = self::sub($f['approved_requests'], $f['paid_live']), '0', 2) < 0 ? '0.00' : $x;
            $out[$id]['commitment_left'] = self::sub($out[$id]['revised'], $paidTotal);
            $out[$id]['headroom'] = self::sub($out[$id]['revised'], $out[$id]['approved_work']);
            $out[$id]['remaining_eligible_signed'] = self::sub($out[$id]['headroom'], $f['requested_pending']);
            $out[$id]['remaining_eligible'] = bccomp($out[$id]['remaining_eligible_signed'], '0', 2) < 0 ? '0.00' : $out[$id]['remaining_eligible_signed'];
        }

        return $out;
    }

    /** @return array<string,string> */
    public static function forAgreement(int $id): array
    {
        return self::forAgreements([$id])[$id] ?? [];
    }

    /**
     * What can still be approved on an agreement, leaving one request out (the one being decided).
     * A request may not push approved + legacy paid above the revised contract value unless a person with the override ability says why.
     */
    public static function headroomFor(int $agreementId, ?int $exceptRequestId = null): string
    {
        $f = self::forAgreement($agreementId);
        if (! $f) {
            return '0.00';
        }
        $h = $f['headroom'];
        if ($exceptRequestId) {
            $r = DB::table('subcontract_payment_requests')->where('id', $exceptRequestId)->where('status', SubcontractPaymentRequest::APPROVED)->first();
            if ($r) {
                $h = self::add($h, (string) $r->approved_amount);
            }
        }

        return $h;
    }

    /** What a single request has had paid, in process and still to pay (agreement currency). @return array{approved:string,paid:string,in_process:string,left:string} */
    public static function requestFigures(int $requestId): array
    {
        return self::requestFiguresMany([$requestId])[$requestId];
    }

    /**
     * The same for many requests in two queries (the agreement page, the reports and the alert sweep must not ask once per request).
     * A payment with no voucher yet is in process; a payment whose voucher row is gone is void and counts nowhere.
     * @param array<int,int> $requestIds
     * @return array<int,array{approved:string,paid:string,in_process:string,left:string}> keyed by request id
     */
    public static function requestFiguresMany(array $requestIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $requestIds)));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $reqs = DB::table('subcontract_payment_requests')->whereIn('id', $chunk)->get(['id', 'status', 'approved_amount'])->keyBy('id');
            $acc = [];
            foreach (DB::table('subcontract_payments as p')->leftJoin('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')->whereIn('p.request_id', $chunk)
                ->get(['p.request_id', 'p.amount', 'p.payment_voucher_id as pvid', 'v.status as vstatus', 'v.paid_at', 'v.deleted_at as vdel', 'v.id as vid']) as $r) {
                $acc[$r->request_id] ??= ['paid' => '0.00', 'proc' => '0.00'];
                if ($r->vid && $r->vstatus === 'Authorised' && $r->paid_at && ! $r->vdel) {
                    $acc[$r->request_id]['paid'] = self::add($acc[$r->request_id]['paid'], (string) $r->amount);
                } elseif (! $r->pvid || ($r->vid && ! $r->vdel && ! in_array($r->vstatus, ['Rejected', 'Cancelled'], true))) {
                    $acc[$r->request_id]['proc'] = self::add($acc[$r->request_id]['proc'], (string) $r->amount);
                }
            }
            foreach ($chunk as $id) {
                $req = $reqs[$id] ?? null;
                $paid = $acc[$id]['paid'] ?? '0.00';
                $proc = $acc[$id]['proc'] ?? '0.00';
                $approved = $req && $req->status === SubcontractPaymentRequest::APPROVED ? self::dec($req->approved_amount) : '0.00';
                $out[$id] = ['approved' => $approved, 'paid' => $paid, 'in_process' => $proc, 'left' => self::sub(self::sub($approved, $paid), $proc)];
            }
        }

        return $out;
    }

    /** A subcontractor's figures per currency (never added across currencies). @return array<string,array<string,string>> */
    public static function forContact(int $contactId, ?array $agreementIds = null): array
    {
        $ids = $agreementIds ?? DB::table('subcontract_agreements')->whereNull('deleted_at')->where('contact_id', $contactId)->pluck('id')->all();

        return self::foldByCurrency(self::forAgreements($ids));
    }

    /** The same, from figures already calculated by forAgreements() (no queries): drafts and cancelled agreements are left out, and archived ones are counted only when $archived is true (their own balance, never mixed with the live totals). */
    public static function foldByCurrency(array $figures, bool $archived = false): array
    {
        $by = [];
        foreach ($figures as $f) {
            if (in_array($f['status'], [SubcontractAgreement::DRAFT, SubcontractAgreement::CANCELLED], true) || ($f['status'] === SubcontractAgreement::ARCHIVED) !== $archived) {
                continue;
            }
            $c = $f['currency'];
            $by[$c] ??= array_fill_keys(['original', 'variations_net', 'revised', 'scheduled', 'requested_pending', 'approved_requests', 'approved_work', 'paid_live', 'paid_legacy', 'paid_total', 'in_process', 'approved_unpaid', 'commitment_left'], '0.00');
            foreach ($by[$c] as $k => $v) {
                $by[$c][$k] = self::add($v, $f[$k]);
            }
        }

        return $by;
    }
}
