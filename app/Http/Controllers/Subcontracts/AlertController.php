<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractAlert as Al;
use App\Models\User;
use App\Services\Subcontracts\SubcontractAlerts;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The subcontract Alerts page: everything that needs attention, with owners and a record of how each was dealt with (same design as Financial Alerts). */
class AlertController extends Controller
{
    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-alerts-subcontracts', 'resolve-alerts-subcontracts');
        $me = auth()->user();
        $base = $this->scoped();
        $q = (clone $base)
            ->when(array_key_exists((string) $request->query('category'), Al::CATEGORIES), fn ($w) => $w->where('category', $request->query('category')))
            ->when(array_key_exists((string) $request->query('severity'), Al::SEVERITIES), fn ($w) => $w->where('severity', $request->query('severity')))
            ->when(in_array($request->query('status', 'active'), ['open', 'acknowledged', 'resolved'], true), fn ($w) => $w->where('status', $request->query('status')), fn ($w) => $request->query('status', 'active') === 'active' ? $w->whereIn('status', ['open', 'acknowledged']) : $w)
            ->when(ctype_digit((string) $request->query('agreement_id')), fn ($w) => $w->where('agreement_id', (int) $request->query('agreement_id')))
            ->when($request->query('mine') === '1', fn ($w) => $w->where('assigned_to', $me->id))
            ->when(trim((string) $request->query('q')) !== '', function ($w) use ($request) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->query('q'))) . '%';

                return $w->where(fn ($x) => $x->where('reference', 'like', $like)->orWhere('title', 'like', $like)->orWhere('description', 'like', $like));
            });
        $rows = $q->orderByRaw("FIELD(severity,'critical','high','medium','low','info')")->orderByDesc('id')->paginate(20)->withQueryString();
        $col = $rows->getCollection();
        $agr = $this->visibleAgreements()->whereIn('id', $col->pluck('agreement_id')->filter()->unique())->with('contact:id,name')->get(['id', 'reference', 'title', 'contact_id'])->keyBy('id');
        $users = User::query()->whereIn('id', $col->pluck('assigned_to')->merge($col->pluck('resolved_by'))->filter()->unique())->pluck('name', 'id');
        $money = $this->money();
        $rows->setCollection($col->map(fn ($a) => [
            'id' => $a->id, 'reference' => $a->reference, 'category' => $a->category, 'category_label' => __(Al::CATEGORIES[$a->category] ?? $a->category), 'type' => $a->type, 'severity' => $a->severity, 'severity_label' => __(Al::SEVERITIES[$a->severity] ?? $a->severity),
            'title' => $a->title, 'description' => $a->description, 'agreement' => $a->agreement_id ? ($agr[$a->agreement_id]->reference ?? null) : null, 'agreement_id' => $a->agreement_id, 'contact' => $a->agreement_id ? optional(optional($agr[$a->agreement_id] ?? null)->contact)->name : null,
            'amount' => $money && $a->amount !== null ? (string) $a->amount : null, 'currency' => $a->currency, 'status' => $a->status, 'occurrences' => $a->occurrences, 'assigned' => $users[$a->assigned_to] ?? null, 'assigned_to' => $a->assigned_to,
            'since' => $a->created_at->toDateTimeString(), 'last_seen' => optional($a->last_seen_at)->diffForHumans(), 'read' => (bool) $a->read_at, 'resolution_notes' => $a->resolution_notes, 'resolved_by' => $users[$a->resolved_by] ?? null, 'escalated' => (bool) $a->escalated_at, 'link' => $this->link($a),
        ]));
        $open = (clone $base)->whereIn('status', ['open', 'acknowledged']);
        $can = $me->can('resolve-alerts-subcontracts');

        return Inertia::render('Subcontracts/Alerts', [
            'rows' => $rows, 'filters' => ['status' => $request->query('status', 'active')] + $request->only('category', 'severity', 'agreement_id', 'mine', 'q'),
            'counts' => ['open' => (clone $open)->count(), 'unread' => (clone $open)->whereNull('read_at')->count(), 'critical' => (clone $open)->whereIn('severity', ['critical', 'high'])->count(), 'mine' => (clone $open)->where('assigned_to', $me->id)->count(),
                'byCategory' => (clone $open)->selectRaw('category, COUNT(*) n')->groupBy('category')->pluck('n', 'category')],
            'categories' => collect(Al::CATEGORIES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'severities' => collect(Al::SEVERITIES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'agreements' => $this->visibleAgreements()->with('contact:id,name')->orderByDesc('id')->limit(400)->get(['id', 'reference', 'title', 'contact_id'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->reference . ' - ' . optional($a->contact)->name]),
            'users' => User::query()->where('is_active', 1)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'can' => ['resolve' => $can, 'scan' => $can],
        ]);
    }

    public function read(int $id)
    {
        $this->need('read-alerts-subcontracts', 'resolve-alerts-subcontracts');
        $this->scoped()->findOrFail($id)->forceFill(['read_at' => now()])->save();

        return back();
    }

    public function readAll()
    {
        $this->need('read-alerts-subcontracts', 'resolve-alerts-subcontracts');
        $this->scoped()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('message', __('All marked as read.'));
    }

    public function acknowledge(int $id)
    {
        $this->need('resolve-alerts-subcontracts');
        $a = $this->scoped()->findOrFail($id);
        if ($a->status === 'open') {
            $a->forceFill(['status' => 'acknowledged', 'acknowledged_by' => auth()->id(), 'acknowledged_at' => now(), 'read_at' => $a->read_at ?? now()])->save();
            log_activity('Subcontract alert acknowledged', ['alert' => $a->reference], null, 'Subcontracts');
        }

        return back()->with('message', __('Acknowledged.'));
    }

    public function assign(Request $request, int $id)
    {
        $this->need('resolve-alerts-subcontracts');
        $a = $this->scoped()->findOrFail($id);
        $uid = $request->validate(['user_id' => 'required|integer|exists:users,id'])['user_id'];
        abort_unless(User::query()->where('id', $uid)->where('is_active', 1)->exists(), 422);
        $a->forceFill(['assigned_to' => $uid])->save();
        log_activity('Subcontract alert assigned', ['alert' => $a->reference, 'to' => $uid], null, 'Subcontracts');

        return back()->with('message', __('Assigned.'));
    }

    /** A high or critical alert needs a written note: how it was dealt with is part of the record. */
    public function resolve(Request $request, int $id)
    {
        $this->need('resolve-alerts-subcontracts');
        $a = $this->scoped()->findOrFail($id);
        $needNote = in_array($a->severity, ['high', 'critical'], true);
        $notes = $request->validate(['notes' => ($needNote ? 'required' : 'nullable') . '|string|max:1000'])['notes'] ?? null;
        $a->forceFill(['status' => 'resolved', 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'resolution_notes' => $notes, 'read_at' => $a->read_at ?? now()])->save();
        log_activity('Subcontract alert resolved', ['alert' => $a->reference, 'notes' => $notes], null, 'Subcontracts');

        return back()->with('message', __('Resolved.'));
    }

    public function escalate(Request $request, int $id)
    {
        $this->need('resolve-alerts-subcontracts');
        $a = $this->scoped()->findOrFail($id);
        $note = $request->validate(['notes' => 'nullable|string|max:500'])['notes'] ?? null;
        $sev = ['info' => 'low', 'low' => 'medium', 'medium' => 'high', 'high' => 'critical', 'critical' => 'critical'][$a->severity];
        $a->forceFill(['severity' => $sev, 'escalated_at' => now(), 'status' => 'open'])->save();
        log_activity('Subcontract alert escalated', ['alert' => $a->reference, 'severity' => $sev, 'notes' => $note], null, 'Subcontracts');
        try {
            \App\Services\Workflow\WorkflowMessenger::dispatch('subcontracts', 'alert_raised', null, null, ['account_id' => $a->account_id ?: 1, 'title' => __('Escalated: :t', ['t' => $a->title]), 'message' => (string) $note, 'url' => route('subcontracts.alerts.index', ['q' => $a->reference]), 'dedupe' => 'sc-esc-' . $a->id . '-' . now()->format('YmdHi'),
                'values' => ['reference' => $a->reference, 'subcontractor' => '', 'agreement' => '', 'amount' => '', 'message' => $a->title], 'details' => [__('Alert') => $a->reference]]);
        } catch (\Throwable) {
        }

        return back()->with('message', __('Escalated.'));
    }

    /** Run the check now instead of waiting for the hourly one. */
    public function scan()
    {
        $this->need('resolve-alerts-subcontracts');
        $n = SubcontractAlerts::sweep();
        log_activity('Subcontract alerts checked', $n, null, 'Subcontracts');

        return back()->with('message', __('Checked. :n condition(s) found.', ['n' => array_sum($n)]));
    }

    /** Alerts about agreements the person cannot see are not theirs to read; figures-based ones need the permission to see money. */
    private function scoped()
    {
        $ids = $this->visibleAgreements()->pluck('id')->all();

        return Al::query()
            ->where(fn ($q) => $q->whereNull('agreement_id')->orWhereIn('agreement_id', $ids ?: [0]))
            ->when(! $this->money(), fn ($q) => $q->whereNotIn('category', ['balance', 'payment', 'control']));
    }

    private function link(Al $a): ?string
    {
        if ($a->subject_type === 'request' && $a->subject_id) {
            return route('subcontracts.requests.show', $a->subject_id);
        }
        if ($a->subject_type === 'claim' && $a->subject_id) {
            return route('subcontracts.claims.show', $a->subject_id);
        }
        if ($a->subject_type === 'variation' && $a->subject_id) {
            return route('subcontracts.variations.show', $a->subject_id);
        }
        if ($a->subject_type === 'mapping') {
            return $this->can('map-projects-subcontracts') ? route('subcontracts.project-mapping.index') : null;
        }

        return $a->agreement_id ? route('subcontracts.agreements.show', $a->agreement_id) : null;
    }
}
