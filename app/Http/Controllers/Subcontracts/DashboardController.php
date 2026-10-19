<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\SubcontractVariation as V;
use App\Services\Subcontracts\SubcontractLedger;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** The subcontract dashboard: every figure comes from SubcontractLedger (so it agrees with the profile, statement and reports) and respects what the viewer may see. */
class DashboardController extends Controller
{
    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-subcontracts');
        $f = ['project' => (string) $request->query('project', ''), 'contact' => (string) $request->query('contact', ''), 'status' => (string) $request->query('status', ''), 'currency' => (string) $request->query('currency', ''), 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')];
        $money = $this->money();
        $base = $this->visibleAgreements()
            ->when(ctype_digit($f['project']) && $f['project'] !== '', fn ($q) => $q->where('warehouse_id', (int) $f['project']))->when(ctype_digit($f['contact']) && $f['contact'] !== '', fn ($q) => $q->where('contact_id', (int) $f['contact']))
            ->when(in_array($f['status'], A::STATUSES, true), fn ($q) => $q->where('status', $f['status']))->when(in_array($f['currency'], SubcontractSettings::get()['currencies'], true), fn ($q) => $q->where('currency', $f['currency']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('agreement_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('agreement_date', '<=', $f['to']));
        $ids = (clone $base)->pluck('id')->all();
        $agreements = (clone $base)->get(['id', 'reference', 'title', 'contact_id', 'warehouse_id', 'currency', 'status', 'expected_completion'])->keyBy('id');
        $payable = $agreements->filter(fn ($a) => in_array($a->status, array_merge(A::PAYABLE, [A::SUSPENDED]), true));

        $counts = [
            'subcontractors' => $payable->pluck('contact_id')->unique()->count(), 'agreements' => $payable->count(), 'active' => $agreements->where('status', A::ACTIVE)->count(),
            'agreements_waiting' => $agreements->whereIn('status', [A::SUBMITTED, A::REVIEW])->count(),
            'variations_waiting' => V::query()->whereIn('agreement_id', $ids)->whereIn('status', [V::SUBMITTED, V::REVIEWED])->count(),
            'requests_waiting' => R::query()->whereIn('agreement_id', $ids)->whereIn('status', [R::SUBMITTED, R::REVIEW])->count(),
            'requests_returned' => R::query()->whereIn('agreement_id', $ids)->whereIn('status', [R::RETURNED, R::REJECTED])->count(),
            'requests_approved' => R::query()->whereIn('agreement_id', $ids)->where('status', R::APPROVED)->count(),
            'open_reconciliations' => $this->can('reconcile-subcontracts') ? DB::table('subcontract_reconciliations')->whereNull('deleted_at')->where('status', 'open')->whereIn('agreement_id', $ids)->count() : null,
        ];

        $overdue = $payable->filter(fn ($a) => $a->expected_completion && $a->expected_completion->lt(now()->startOfDay()) && in_array($a->status, [A::APPROVED, A::ACTIVE], true));
        $soon = $payable->filter(fn ($a) => $a->expected_completion && $a->expected_completion->gte(now()->startOfDay()) && $a->expected_completion->lte(now()->addDays((int) SubcontractSettings::get()['completion_alert_days'])) && in_array($a->status, [A::APPROVED, A::ACTIVE], true));

        $figures = $byContact = $byProject = $byMonth = $recentVouchers = null;
        if ($money) {
            // the same agreements as the Register and the Agreements list (not drafts, cancelled or archived), so the three pages always show the same totals
            $counted = $agreements->filter(fn ($a) => ! in_array($a->status, [A::DRAFT, A::CANCELLED, A::ARCHIVED], true));
            $fig = SubcontractLedger::forAgreements($counted->keys()->all());
            $figures = [];
            $byContactRaw = [];
            $byProjectRaw = [];
            foreach ($fig as $id => $x) {
                $a = $counted[$id];
                $c = $x['currency'];
                $figures[$c] ??= array_fill_keys(['original', 'variations_net', 'revised', 'approved_work', 'requested_pending', 'paid_live', 'paid_legacy', 'paid_total', 'approved_unpaid', 'commitment_left', 'in_process'], '0.00');
                foreach ($figures[$c] as $k => $v) {
                    $figures[$c][$k] = bcadd($v, $x[$k], 2);
                }
                $key = $a->contact_id . '|' . $c;
                $byContactRaw[$key] = ($byContactRaw[$key] ?? ['contact_id' => $a->contact_id, 'currency' => $c, 'revised' => '0.00', 'paid_total' => '0.00', 'commitment_left' => '0.00', 'approved_unpaid' => '0.00']);
                foreach (['revised', 'paid_total', 'commitment_left', 'approved_unpaid'] as $k) {
                    $byContactRaw[$key][$k] = bcadd($byContactRaw[$key][$k], $x[$k], 2);
                }
                $pk = ($a->warehouse_id ?: 0) . '|' . $c;
                $byProjectRaw[$pk] = ($byProjectRaw[$pk] ?? ['warehouse_id' => $a->warehouse_id, 'currency' => $c, 'agreements' => 0, 'revised' => '0.00', 'paid_total' => '0.00']);
                $byProjectRaw[$pk]['agreements']++;
                $byProjectRaw[$pk]['revised'] = bcadd($byProjectRaw[$pk]['revised'], $x['revised'], 2);
                $byProjectRaw[$pk]['paid_total'] = bcadd($byProjectRaw[$pk]['paid_total'], $x['paid_total'], 2);
            }
            $names = DB::table('contacts')->whereIn('id', collect($byContactRaw)->pluck('contact_id'))->pluck('name', 'id');
            $byContact = collect($byContactRaw)->sortByDesc(fn ($r) => (float) $r['commitment_left'])->take(10)->map(fn ($r) => $r + ['name' => $names[$r['contact_id']] ?? '-'])->values();
            $pn = DB::table('warehouses')->whereIn('id', collect($byProjectRaw)->pluck('warehouse_id')->filter())->pluck('name', 'id');
            $byProject = collect($byProjectRaw)->sortByDesc(fn ($r) => (float) $r['paid_total'])->take(10)->map(fn ($r) => $r + ['name' => $r['warehouse_id'] ? ($pn[$r['warehouse_id']] ?? '-') : __('No project')])->values();
            $byMonth = DB::table('subcontract_payments as p')->join('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->whereIn('p.agreement_id', $ids)->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')
                ->where('v.status', 'Authorised')->whereNotNull('v.paid_at')->whereNull('v.deleted_at')->where('v.paid_at', '>=', now()->subMonths(11)->startOfMonth())
                ->selectRaw("DATE_FORMAT(v.paid_at, '%Y-%m') ym, SUM(p.amount_tzs) s")->groupBy('ym')->orderBy('ym')->pluck('s', 'ym');
            $recentVouchers = DB::table('subcontract_payments as p')->join('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->join('subcontract_agreements as a', 'a.id', '=', 'p.agreement_id')->whereIn('p.agreement_id', $ids)->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')
                ->orderByDesc('p.id')->limit(8)->get(['v.id as vid', 'v.pv_number', 'v.status', 'v.paid_at', 'p.amount_tzs', 'a.reference as agreement', 'p.request_id'])
                ->map(fn ($r) => ['id' => $r->vid, 'pv' => $r->pv_number, 'status' => $r->paid_at && $r->status === 'Authorised' ? 'Paid' : $r->status, 'amount' => (string) $r->amount_tzs, 'agreement' => $r->agreement, 'request_id' => $r->request_id, 'paid_at' => $r->paid_at ? substr((string) $r->paid_at, 0, 10) : null])
                ->groupBy('id')->map(fn ($g) => array_merge($g->first(), ['amount' => $g->reduce(fn ($t, $r) => bcadd($t, (string) $r['amount'], 2), '0.00'), 'agreement' => $g->pluck('agreement')->unique()->implode(', ')]))->values(); // one row per voucher (a contract payment voucher pays several agreements)
        }

        $archivedIds = $agreements->where('status', A::ARCHIVED)->keys()->all();
        $archived = ['count' => count($archivedIds), 'figures' => $money && $archivedIds ? SubcontractLedger::foldByCurrency(SubcontractLedger::forAgreements($archivedIds), true) : []];

        $log = DB::table('activity_log')->where('log_name', 'Subcontracts')->orderByDesc('id')->limit(10)->get(['description', 'created_at', 'causer_id']);
        $names = DB::table('users')->whereIn('id', $log->pluck('causer_id')->filter()->unique()->all())->pluck('name', 'id');
        $recent = $log->map(fn ($r) => ['description' => $r->description, 'at' => $r->created_at, 'who' => $names[$r->causer_id] ?? null]);

        return Inertia::render('Subcontracts/Dashboard', [
            'filters' => $f, 'archived' => $archived, 'counts' => $counts, 'figures' => $figures, 'by_contact' => $byContact, 'by_project' => $byProject, 'by_month' => $byMonth,
            'overdue' => $overdue->map(fn ($a) => ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'due' => $a->expected_completion->toDateString()])->values(),
            'soon' => $soon->map(fn ($a) => ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'due' => $a->expected_completion->toDateString()])->values(),
            'recent_vouchers' => $recentVouchers, 'recent' => $this->can('view-audit-subcontracts') ? $recent : [], 'contacts' => $this->subcontractors(), 'projects' => $this->projects(),
            'statuses' => collect(A::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'currencies' => collect(SubcontractSettings::get()['currencies'])->map(fn ($c) => ['value' => $c, 'label' => $c])->values(), 'money' => $money,
            'can' => ['agreements' => $this->can('read-subcontract-agreements'), 'requests' => $this->can('read-subcontract-requests'), 'variations' => $this->can('read-subcontract-variations'), 'import' => $this->can('import-subcontracts'), 'reports' => $this->can('read-subcontract-reports'),
                'create_agreement' => $this->can('create-subcontract-agreements'), 'create_request' => $this->can('create-subcontract-requests'), 'reconcile' => $this->can('reconcile-subcontracts'), 'settings' => $this->can('manage-subcontract-settings')],
        ]);
    }
}
