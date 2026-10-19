<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAuditEvent;
use App\Models\User;
use App\Services\Accounts\AcctAudit;
use App\Services\Accounts\AcctBalance;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The append-only audit trail of everything done in Accounts Tracking, with a check that no row has been altered. */
class AuditController extends AcctController
{
    public function index(Request $request)
    {
        $this->need('view-acct-audit');
        $q = AcctAuditEvent::query()
            ->when(trim((string) $request->query('q')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')->orWhere('event', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')))
            ->when(preg_match('/^[a-z_]{2,30}$/', (string) $request->query('entity')), fn ($w) => $w->where('entity_type', $request->query('entity')))
            ->when(ctype_digit((string) $request->query('user_id')), fn ($w) => $w->where('user_id', (int) $request->query('user_id')))
            ->when($request->query('from') && strtotime($request->query('from')), fn ($w) => $w->whereDate('created_at', '>=', $request->query('from')))->when($request->query('to') && strtotime($request->query('to')), fn ($w) => $w->whereDate('created_at', '<=', $request->query('to')));
        $rows = $q->orderByDesc('id')->paginate(30)->withQueryString();
        $users = User::query()->whereIn('id', $rows->getCollection()->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'entity' => $e->entity_type, 'entity_id' => $e->entity_id, 'reference' => $e->reference, 'user' => $users[$e->user_id] ?? null, 'role' => $e->role_context, 'reason' => $e->reason, 'ip' => $e->ip,
            'old' => $e->old_values, 'new' => $e->new_values, 'at' => $e->created_at->toDateTimeString(), 'link' => $this->link($e)]);

        return Inertia::render('Accounts/Audit', $this->shared() + ['rows' => $rows, 'filters' => $request->only('q', 'entity', 'user_id', 'from', 'to'),
            'entities' => AcctAuditEvent::query()->distinct()->orderBy('entity_type')->pluck('entity_type'), 'users' => User::query()->where('is_active', 1)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'canVerify' => true]);
    }

    /** Walk the whole chain, and compare every stored balance with the ledger. */
    public function verify()
    {
        $this->need('view-acct-audit');
        $bad = AcctAudit::verify();
        $drift = count(app(AcctBalance::class)->integrity());
        AcctAudit::log('audit.verified', 'audit', null, null, null, ['chain_break' => $bad, 'balance_drift' => $drift], null, $this->me());
        if ($bad === null && $drift === 0) {
            return back()->with('message', __('The audit trail is intact and every balance agrees with the ledger.'));
        }

        return back()->with('error', e($bad !== null ? __('The audit trail was altered at record :id.', ['id' => $bad]) : __(':n account balance(s) do not agree with the ledger. Open the account and use "Check balance".', ['n' => $drift])));
    }

    private function link(AcctAuditEvent $e): ?string
    {
        if (! $e->entity_id) {
            return null;
        }

        return match ($e->entity_type) {
            'transaction' => route('acct.transactions.show', $e->entity_id), 'transfer' => route('acct.transfers.show', $e->entity_id), 'account' => route('acct.accounts.show', $e->entity_id), 'reconciliation' => route('acct.reconciliations.show', $e->entity_id), default => null,
        };
    }
}
