<?php

namespace App\Services\Subcontracts;

use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\SubcontractVariation as V;
use Illuminate\Support\Facades\DB;

/**
 * The account statement of a subcontractor (all their agreements) or of one agreement: opening (historical) position, original contract, approved variations,
 * approved requests, vouchers and payments, and the remaining contract value after each line. Legacy (Excel) lines and live WIMS lines are labelled apart.
 * The balance column is "revised contract value so far minus payments so far", kept per currency (currencies are never mixed).
 */
class SubcontractStatement
{
    /** @param array{contact_id:?int,agreement_id:?int,warehouse_id:?int,currency:?string,from:?string,to:?string,kind:?string} $f */
    public static function build($user, array $f): array
    {
        $ags = A::query()->visibleTo($user)->with('contact:id,name')->when($f['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))->when($f['agreement_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))->when($f['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v))->whereNotIn('status', [A::DRAFT, A::CANCELLED])->when(empty($f['agreement_id']), fn ($q) => $q->where('status', '<>', A::ARCHIVED))->orderBy('id')->get();
        $ids = $ags->pluck('id')->all();
        $by = $ags->keyBy('id');
        $ev = [];
        $seq = 0;
        $push = function (array $e) use (&$ev, &$seq, $by) {
            $a = $by[$e['agreement_id']];
            $ev[] = $e + ['seq' => ++$seq, 'agreement' => $a->reference, 'currency' => $a->currency, 'commitment' => null, 'approved' => null, 'paid' => null, 'source' => 'wims', 'status' => ''];
        };
        foreach ($ags as $a) {
            $push(['agreement_id' => $a->id, 'date' => substr((string) ($a->agreement_date ?: $a->as_at_date ?: $a->created_at), 0, 10), 'sort' => 0, 'type' => 'contract', '_link' => ['type' => 'agreement', 'id' => $a->id], 'reference' => $a->reference, 'description' => __('Original contract') . ': ' . $a->title, 'commitment' => (string) $a->original_amount, 'source' => $a->source === 'import' ? 'legacy' : 'wims']);
        }
        foreach (V::query()->whereIn('agreement_id', $ids)->where('status', V::APPROVED)->get() as $v) {
            $push(['agreement_id' => $v->agreement_id, 'date' => substr((string) ($v->effective_date ?: $v->approved_at ?: $v->created_at), 0, 10), 'sort' => 1, 'type' => 'variation', '_link' => ['type' => 'variation', 'id' => $v->id], 'reference' => $v->reference, 'description' => $v->title, 'commitment' => ($v->direction === 'reduction' ? '-' : '') . $v->amount]);
        }
        foreach (R::query()->whereIn('agreement_id', $ids)->where('status', R::APPROVED)->get() as $r) {
            $push(['agreement_id' => $r->agreement_id, 'date' => substr((string) ($r->approved_at ?: $r->request_date), 0, 10), 'sort' => 2, 'type' => 'request', '_link' => ['type' => 'request', 'id' => $r->id], 'reference' => $r->reference, 'description' => __('Payment request approved') . ($r->override ? ' (' . __('override') . ')' : ''), 'approved' => (string) $r->approved_amount]);
        }
        foreach (DB::table('subcontract_historical_payments')->whereIn('agreement_id', $ids)->whereNull('deleted_at')->where('counted', 1)->get() as $h) {
            $push(['agreement_id' => $h->agreement_id, 'date' => $h->payment_date ?: substr((string) ($by[$h->agreement_id]->as_at_date ?: $by[$h->agreement_id]->agreement_date ?: $by[$h->agreement_id]->created_at), 0, 10), 'sort' => 3, 'type' => 'legacy_payment', '_link' => ['type' => 'agreement', 'id' => $h->agreement_id], 'reference' => $h->source_sheet ?: '-',
                'description' => __('Historical payment (before WIMS)') . ($h->note ? ': ' . $h->note : ''), 'paid' => (string) $h->amount, 'source' => 'legacy']);
        }
        foreach (DB::table('subcontract_payments as p')->leftJoin('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->join('subcontract_payment_requests as r', 'r.id', '=', 'p.request_id')->whereIn('p.agreement_id', $ids)->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')
            ->get(['p.*', 'v.pv_number', 'v.status as vstatus', 'v.paid_at', 'v.deleted_at as vdel', 'r.reference as rref']) as $p) {
            $paid = $p->pv_number && $p->vstatus === 'Authorised' && $p->paid_at && ! $p->vdel;
            $void = $p->pv_number && ($p->vdel || in_array($p->vstatus, ['Rejected', 'Cancelled'], true));
            if ($void) {
                continue;
            }
            $push(['agreement_id' => $p->agreement_id, 'date' => substr((string) ($paid ? $p->paid_at : $p->created_at), 0, 10), 'sort' => 4, 'type' => $paid ? 'payment' : 'voucher', '_link' => $p->payment_voucher_id && $p->pv_number ? ['type' => 'voucher', 'id' => $p->payment_voucher_id] : ['type' => 'request', 'id' => $p->request_id], 'reference' => $p->pv_number ?: $p->rref,
                'description' => $paid ? __('Paid through Payment Voucher') : ($p->pv_number ? __('Voucher :s (not paid yet)', ['s' => $p->vstatus]) : __('Voucher not created yet')), 'paid' => $paid ? (string) $p->amount : null, 'status' => $paid ? 'Paid' : ($p->pv_number ? $p->vstatus : 'Started')]);
        }
        usort($ev, fn ($a, $b) => [$a['date'], $a['sort'], $a['seq']] <=> [$b['date'], $b['sort'], $b['seq']]);
        $bal = [];
        $rows = [];
        foreach ($ev as $e) {
            $c = $e['currency'];
            $bal[$c] ??= '0.00';
            if ($e['commitment'] !== null) {
                $bal[$c] = bcadd($bal[$c], $e['commitment'], 2);
            }
            if ($e['paid'] !== null) {
                $bal[$c] = bcsub($bal[$c], $e['paid'], 2);
            }
            $out = $e;
            $out['type_key'] = $e['type'];
            $out['balance'] = $bal[$c];
            $out['source'] = $e['source'] === 'legacy' ? __('Historical') : 'WIMS';
            $out['type'] = ['contract' => __('Contract'), 'variation' => __('Variation'), 'request' => __('Request approved'), 'legacy_payment' => __('Payment (historical)'), 'payment' => __('Payment'), 'voucher' => __('Voucher')][$e['type']] ?? $e['type'];
            $rows[] = $out;
        }
        // filters are applied after the running balance is built, so the balance of a filtered statement is still the true balance at that line
        $rows = array_values(array_filter($rows, fn ($r) => (empty($f['from']) || $r['date'] >= $f['from']) && (empty($f['to']) || $r['date'] <= $f['to']) && (empty($f['kind']) || $r['type_key'] === $f['kind'])));

        return ['rows' => $rows, 'notes' => [__('The balance is the revised contract value so far minus the payments so far, kept per currency.'), __('Historical lines come from the Excel workbook and are not Payment Vouchers; their dates are the payment dates where the workbook has them, otherwise the date of the account.'),
            __('Approved requests and vouchers that are not paid yet are shown for information; only a paid voucher or a counted historical payment reduces the balance.')]];
    }
}
