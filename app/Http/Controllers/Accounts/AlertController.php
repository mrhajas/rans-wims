<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctAlert;
use App\Models\User;
use App\Services\Accounts\AcctAlerts;
use App\Services\Accounts\AcctAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** The financial Alerts page: everything that needs attention, with owners and a record of how each was dealt with. */
class AlertController extends AcctController
{
    private const CATEGORIES = ['balance' => 'Balance', 'transaction' => 'Transaction', 'transfer' => 'Transfer', 'reconciliation' => 'Reconciliation', 'currency' => 'Currency', 'security' => 'Security'];

    private const SEVERITIES = ['info' => 'Info', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public function index(Request $request)
    {
        $this->need('read-acct-alerts');
        $me = $this->me();
        $base = $this->scoped();
        $q = (clone $base)
            ->when(array_key_exists((string) $request->query('category'), self::CATEGORIES), fn ($w) => $w->where('category', $request->query('category')))
            ->when(array_key_exists((string) $request->query('severity'), self::SEVERITIES), fn ($w) => $w->where('severity', $request->query('severity')))
            ->when(in_array($request->query('status', 'active'), ['open', 'acknowledged', 'resolved'], true), fn ($w) => $w->where('status', $request->query('status')), fn ($w) => $request->query('status') === 'all' ? $w : $w->whereIn('status', ['open', 'acknowledged'])) // "all" is asked for; anything unknown is the default list
            ->when(ctype_digit((string) $request->query('account_id')), fn ($w) => $w->where('account_id', (int) $request->query('account_id')))
            ->when($request->query('mine') === '1', fn ($w) => $w->where('assigned_to', $me->id))
            ->when(trim((string) $request->query('q')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')->orWhere('title', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')));
        $accts = AcctAccount::query()->pluck('name', 'id');
        $rows = $q->orderByRaw("FIELD(severity,'critical','high','medium','low','info')")->orderByDesc('id')->paginate(20)->withQueryString();
        $users = User::query()->whereIn('id', $rows->getCollection()->pluck('assigned_to')->merge($rows->getCollection()->pluck('resolved_by'))->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($a) => ['id' => $a->id, 'reference' => $a->reference, 'category' => $a->category, 'category_label' => __(self::CATEGORIES[$a->category] ?? $a->category), 'type' => $a->type, 'severity' => $a->severity, 'severity_label' => __(self::SEVERITIES[$a->severity] ?? $a->severity), 'title' => $a->title, 'description' => $a->description,
            'account' => $accts[$a->account_id] ?? null, 'account_id' => $a->account_id, 'amount' => $a->amount !== null ? (string) $a->amount : null, 'currency' => $a->currency, 'status' => $a->status, 'occurrences' => $a->occurrences, 'assigned' => $users[$a->assigned_to] ?? null, 'assigned_to' => $a->assigned_to,
            'since' => $a->created_at->toDateTimeString(), 'last_seen' => optional($a->last_seen_at)->diffForHumans(), 'read' => (bool) $a->read_at, 'resolution_notes' => $a->resolution_notes, 'resolved_by' => $users[$a->resolved_by] ?? null, 'escalated' => (bool) $a->escalated_at, 'link' => $this->link($a)]);

        $open = (clone $base)->whereIn('status', ['open', 'acknowledged']);

        return Inertia::render('Accounts/Alerts', $this->shared() + [
            'rows' => $rows, 'filters' => ['status' => $request->query('status', 'active')] + $request->only('category', 'severity', 'account_id', 'mine', 'q'),
            'counts' => ['open' => (clone $open)->count(), 'unread' => (clone $open)->whereNull('read_at')->count(), 'critical' => (clone $open)->whereIn('severity', ['critical', 'high'])->count(), 'mine' => (clone $open)->where('assigned_to', $me->id)->count(),
                'byCategory' => (clone $open)->selectRaw('category, COUNT(*) n')->groupBy('category')->pluck('n', 'category')],
            'categories' => collect(self::CATEGORIES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'severities' => collect(self::SEVERITIES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'accounts' => $this->accountOptions(null, false), 'users' => User::query()->where('is_active', 1)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'can' => ['resolve' => $me->can('resolve-acct-alerts')],
        ]);
    }

    public function read(int $id)
    {
        $this->need('read-acct-alerts');
        $this->scoped()->findOrFail($id)->forceFill(['read_at' => now()])->save();

        return back();
    }

    public function readAll()
    {
        $this->need('read-acct-alerts');
        $this->scoped()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('message', __('All marked as read.'));
    }

    public function acknowledge(int $id)
    {
        $this->need('resolve-acct-alerts');
        $a = $this->scoped()->findOrFail($id);
        if ($a->status === 'open') {
            $a->forceFill(['status' => 'acknowledged', 'acknowledged_by' => $this->me()->id, 'acknowledged_at' => now(), 'read_at' => $a->read_at ?? now()])->save();
            AcctAudit::log('alert.acknowledged', 'alert', $a->id, $a->reference, null, null, null, $this->me());
        }

        return back()->with('message', __('Acknowledged.'));
    }

    public function assign(Request $request, int $id)
    {
        $this->need('resolve-acct-alerts');
        $a = $this->scoped()->findOrFail($id);
        $uid = $request->validate(['user_id' => 'required|integer|exists:users,id'])['user_id'];
        if ($a->status === 'resolved') {
            return back()->with('error', e(__('This alert is already resolved.')));
        }
        $a->forceFill(['assigned_to' => $uid])->save();
        AcctAudit::log('alert.assigned', 'alert', $a->id, $a->reference, null, ['to' => $uid], null, $this->me());

        return back()->with('message', __('Assigned.'));
    }

    /** A high or critical alert needs a written note: how it was dealt with is part of the record. */
    public function resolve(Request $request, int $id)
    {
        $this->need('resolve-acct-alerts');
        $a = $this->scoped()->findOrFail($id);
        $needNote = in_array($a->severity, ['high', 'critical'], true);
        $notes = $request->validate(['notes' => ($needNote ? 'required' : 'nullable') . '|string|max:1000'])['notes'] ?? null;
        // re-read under a lock: two people resolving at once, or a second click later, must not overwrite who resolved it and what was written
        $done = DB::connection('accounts')->transaction(function () use ($a, $notes) {
            $l = AcctAlert::query()->lockForUpdate()->findOrFail($a->id);
            if ($l->status === 'resolved') {
                return false;
            }
            $l->forceFill(['status' => 'resolved', 'resolved_by' => $this->me()->id, 'resolved_at' => now(), 'resolution_notes' => $notes, 'read_at' => $l->read_at ?? now()])->save();
            AcctAudit::log('alert.resolved', 'alert', $l->id, $l->reference, null, null, $notes, $this->me());

            return true;
        });
        if (! $done) {
            return back()->with('error', e(__('This alert was already resolved. Who resolved it and the note are kept.')));
        }

        return back()->with('message', __('Resolved.'));
    }

    public function escalate(Request $request, int $id)
    {
        $this->need('resolve-acct-alerts');
        $a = $this->scoped()->findOrFail($id);
        $note = $request->validate(['notes' => 'nullable|string|max:500'])['notes'] ?? null;
        if ($a->status === 'resolved') {
            return back()->with('error', e(__('This alert is already resolved. It cannot be escalated.')));
        }
        $sev = ['info' => 'low', 'low' => 'medium', 'medium' => 'high', 'high' => 'critical', 'critical' => 'critical'][$a->severity];
        $a->forceFill(['severity' => $sev, 'escalated_at' => now(), 'status' => 'open'])->save();
        AcctAudit::log('alert.escalated', 'alert', $a->id, $a->reference, null, ['severity' => $sev], $note, $this->me());
        try {
            \App\Services\Workflow\WorkflowMessenger::dispatch('accounts_tracking', 'security_alert', null, null, ['account_id' => 1, 'title' => __('Escalated: :t', ['t' => $a->title]), 'message' => (string) $note, 'url' => route('acct.alerts.index', ['q' => $a->reference]), 'dedupe' => 'acct-esc-' . $a->id . '-' . now()->format('YmdH'),
                'values' => ['reference' => $a->reference, 'account' => '', 'amount' => ''], 'details' => [__('Alert') => $a->reference]]);
        } catch (\Throwable) {
        }

        return back()->with('message', __('Escalated.'));
    }

    private function scoped()
    {
        return $this->scopedAlerts();
    }

    private function link(AcctAlert $a): ?string
    {
        return match ($a->subject_type) {
            'transaction' => route('acct.transactions.show', $a->subject_id), 'transfer' => route('acct.transfers.show', $a->subject_id), 'reconciliation' => route('acct.reconciliations.show', $a->subject_id), 'rate' => route('acct.currencies.index'),
            default => $a->account_id ? route('acct.accounts.show', $a->account_id) : null,
        };
    }
}
