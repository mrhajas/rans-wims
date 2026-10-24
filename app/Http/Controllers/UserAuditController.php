<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserAuditEvent;
use App\Support\UserAudit;
use Illuminate\Http\Request;

/** The user audit history (Users page, "Audit History"): who did what to which account. JSON for the pop-up window. */
class UserAuditController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('audit-users'), 403);

        $f = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'kind' => ['nullable', 'string', 'max:40'],
            'group' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'with_users' => ['nullable', 'boolean'],
        ]);

        $groups = collect(UserAudit::KINDS)->groupBy(fn ($k) => $k[3], true)->map(fn ($g) => $g->keys()->all());
        $scope = fn () => UserAuditEvent::query()
            ->where(fn ($w) => $w->where('account_id', $request->user()->account_id)->orWhereNull('account_id'))
            ->when($f['user_id'] ?? null, fn ($w, $id) => $w->where('user_id', $id))
            ->when($f['from'] ?? null, fn ($w, $d) => $w->whereDate('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($w, $d) => $w->whereDate('created_at', '<=', $d))
            ->when($f['search'] ?? null, fn ($w, $s) => $w->where(fn ($x) => $x->where('user_name', 'like', "%{$s}%")->orWhere('actor_name', 'like', "%{$s}%")->orWhere('details', 'like', "%{$s}%")));
        $q = $scope()
            ->when($f['kind'] ?? null, fn ($w, $k) => $w->where('kind', $k))
            ->when($f['group'] ?? null, fn ($w, $g) => $w->whereIn('kind', $groups[$g] ?? ['-']))
            ->orderByDesc('id');
        // how many lines each group has under the other filters (the chips show it)
        $byKind = $scope()->selectRaw('kind, COUNT(*) c')->groupBy('kind')->pluck('c', 'kind');
        $counts = $groups->map(fn ($kinds) => (int) collect($kinds)->sum(fn ($k) => $byKind[$k] ?? 0))->put('', (int) $byKind->sum());

        $page = $q->paginate(15)->withQueryString();

        $rows = collect($page->items())->map(fn ($e) => [
            'id' => $e->id,
            'kind' => $e->kind,
            'user_id' => $e->user_id,
            'user' => $e->user_name,
            'actor_id' => $e->actor_id,
            'actor' => $e->actor_name,
            'details' => $e->details ?: new \stdClass(),
            'ip' => $e->ip,
            'device' => $e->device,
            'at' => optional($e->created_at)->toIso8601String(),
        ])->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'kinds' => collect(UserAudit::KINDS)->map(fn ($k, $key) => ['key' => $key, 'label' => __($k[0]), 'icon' => $k[1], 'tone' => $k[2], 'group' => $k[3]])->values(),
            'counts' => $counts,
            'users' => $request->boolean('with_users') ? User::ofAccount()->orderBy('name')->get(['id', 'name', 'username'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name.' (@'.$u->username.')'])->values() : null,
            'subject' => ($f['user_id'] ?? null) ? User::withTrashed()->whereKey($f['user_id'])->first(['id', 'name', 'username']) : null,
        ]);
    }
}
