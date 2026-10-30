<?php

namespace App\Services\AiTools;

use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\IdPassPrint;
use App\Models\Staff;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassQuery;
use App\Services\IdPass\NameFitter;
use Carbon\Carbon;

/**
 * ARIS answers about RANS IDs and passes from the real records: how many, who has none, which workers hold an ID, what expires or has expired, what was issued lately, and when an ID was
 * printed and by whom. It only sees the kinds the person may open (the same rule as the screens), the print history needs its own permission, and it never changes anything.
 */
class IdPassTool
{
    private const KINDS = ['staff' => 'staff', 'staff id' => 'staff', 'staff ids' => 'staff', 'compound' => 'compound', 'compound worker' => 'compound', 'compound workers' => 'compound', 'worker' => 'compound', 'workers' => 'compound',
        'visitor' => 'visitor', 'visitors' => 'visitor', 'visitor pass' => 'visitor', 'visitor passes' => 'visitor', 'gate' => 'gate', 'gate pass' => 'gate', 'gate passes' => 'gate'];

    private function denied(User $u): ?array
    {
        if (! FeatureManager::canAccess('id_passes', $u) || ! $u->can('read-id-passes') || ! IdPassKinds::readable($u)) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to RANS ID & Pass Management.'];
        }

        return null;
    }

    private function kindOf(?string $s): ?string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/\s+ids?$/', '', $s) ?: $s;

        return self::KINDS[$s] ?? self::KINDS[mb_strtolower(trim((string) $s)) . ' id'] ?? null;
    }

    private function scope(User $u, ?string $kind)
    {
        $allowed = IdPassKinds::readable($u);
        $kinds = $kind ? array_values(array_intersect([$kind], $allowed)) : $allowed;

        return [$kinds, IdPassCard::query()->whereIn('kind', $kinds ?: ['-'])];
    }

    public function counts(array $in, User $u): array
    {
        if ($d = $this->denied($u)) {
            return $d;
        }
        $kind = $this->kindOf($in['kind'] ?? null);
        if (($in['kind'] ?? '') !== '' && ! $kind && ! preg_match('/^ids?$/i', (string) $in['kind'])) {
            return ['found' => false, 'message' => 'I know Staff IDs, Compound Worker IDs, Visitor Passes and Gate Passes.'];
        }
        [$kinds] = $this->scope($u, $kind);
        if (! $kinds) {
            return ['error' => 'not_permitted', 'message' => 'You may not see that kind of ID.'];
        }
        $c = IdPassQuery::counts($kinds);
        $status = mb_strtolower((string) ($in['status'] ?? ''));
        $key = ['active' => 'active', 'valid' => 'active', 'expired' => 'expired', 'suspended' => 'suspended', 'revoked' => 'revoked', 'pending' => 'pending', 'replaced' => 'replaced', 'rejected' => 'rejected'][$status] ?? null;

        return ['found' => true, 'kind' => $kind ? IdPassKinds::get($kind)['label'] : 'IDs and passes', 'status' => $key, 'count' => $key ? $c[$key] : $c['total'], 'counts' => $c];
    }

    public function missing(array $in, User $u): array
    {
        if ($d = $this->denied($u)) {
            return $d;
        }
        if (! IdPassKinds::canRead('staff', $u)) {
            return ['error' => 'not_permitted', 'message' => 'You may not see Staff IDs.'];
        }
        $have = IdPassCard::query()->where('kind', 'staff')->whereIn('status', IdPassCard::OPEN)->whereNotNull('staff_id')->pluck('staff_id');
        $q = Staff::query()->whereIn('status', ['Active', 'Retired Active'])->whereNotIn('id', $have)->orderBy('name');
        $total = (clone $q)->count();

        return ['found' => true, 'total' => $total, 'names' => $q->limit(25)->pluck('name')->map(fn ($n) => NameFitter::display((string) $n))->all()];
    }

    public function list(array $in, User $u): array
    {
        if ($d = $this->denied($u)) {
            return $d;
        }
        $kind = $this->kindOf($in['kind'] ?? null);
        [$kinds, $q] = $this->scope($u, $kind);
        if (! $kinds) {
            return ['error' => 'not_permitted', 'message' => 'You may not see that kind of ID.'];
        }
        $cat = trim((string) ($in['category'] ?? ''));
        if ($cat !== '') {
            $c = IdPassCategory::query()->whereIn('kind', $kinds)->where('name', 'like', '%' . $cat . '%')->first();
            if (! $c) {
                return ['found' => false, 'message' => "I could not find a category called \"{$cat}\"."];
            }
            $q->where('category_id', $c->id);
        }
        $statuses = array_values(array_filter(array_map(fn ($x) => ucfirst(mb_strtolower(trim($x))), explode(',', (string) ($in['status'] ?? ''))), fn ($x) => in_array($x, IdPassCard::STATUSES, true)));
        if (count($statuses) > 1) {
            $q->whereIn('status', $statuses);
        } else {
            $q = IdPassQuery::apply($q, IdPassQuery::filters(['status' => $statuses[0] ?? '']));
        }
        $period = (string) ($in['period'] ?? '');
        $today = Carbon::today(config('app.timezone'));
        if ($period === 'this week') {
            $q->whereDate('issued_at', '>=', $today->copy()->startOfWeek()->toDateString());
        } elseif ($period === 'this month') {
            $q->whereDate('issued_at', '>=', $today->copy()->startOfMonth()->toDateString());
        } elseif ($period === 'today') {
            $q->whereDate('issued_at', $today->toDateString());
        }
        $total = (clone $q)->count();
        $rows = $q->with('category:id,name')->orderByDesc('issued_at')->limit(25)->get();

        return ['found' => $total > 0, 'total' => $total, 'items' => $rows->map(fn ($c) => $c->number . ' ' . NameFitter::display((string) $c->full_name) . ' (' . optional($c->category)->name . ', ' . $c->effective_status . ($c->expires_at ? ', until ' . $c->expires_at->format('j M Y') : '') . ')')->all(), 'message' => $total ? null : 'Nothing matches.'];
    }

    public function expiring(array $in, User $u): array
    {
        if ($d = $this->denied($u)) {
            return $d;
        }
        [$kinds, $q] = $this->scope($u, $this->kindOf($in['kind'] ?? null));
        $today = Carbon::today(config('app.timezone'));
        $scope = (string) ($in['scope'] ?? 'expiring');
        if ($scope === 'expired') {
            $q->expired();
        } else {
            $end = ($in['period'] ?? '') === 'this month' ? $today->copy()->endOfMonth() : $today->copy()->addDays(max(1, min(365, (int) ($in['days'] ?? 30))));
            $q->where('status', IdPassCard::ACTIVE)->whereBetween('expires_at', [$today->toDateString(), $end->toDateString()]);
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('expires_at')->limit(25)->get();

        return ['found' => $total > 0, 'scope' => $scope, 'total' => $total, 'items' => $rows->map(fn ($c) => $c->number . ' ' . NameFitter::display((string) $c->full_name) . ' (' . $c->expires_at->format('j M Y') . ')')->all(), 'message' => $total ? null : ($scope === 'expired' ? 'No ID has expired.' : 'No ID is about to expire.')];
    }

    public function printed(array $in, User $u): array
    {
        if ($d = $this->denied($u)) {
            return $d;
        }
        if (! $u->can('read-id-print-history') || ! FeatureManager::canAccess('id_print_centre', $u)) {
            return ['error' => 'not_permitted', 'message' => 'You may not see the print history of IDs.'];
        }
        $n = trim((string) ($in['number'] ?? ''));
        $card = IdPassCard::query()->whereIn('kind', IdPassKinds::readable($u))->where('number', $n)->first();
        if (! $card) {
            return ['found' => false, 'message' => "I could not find an ID numbered {$n}."];
        }
        $rows = IdPassPrint::query()->with('user:id,name')->where('card_id', $card->id)->latest('id')->limit(5)->get();
        if ($rows->isEmpty()) {
            return ['found' => true, 'number' => $card->number, 'holder' => NameFitter::display((string) $card->full_name), 'printed' => false];
        }
        $last = $rows->first();

        return ['found' => true, 'number' => $card->number, 'holder' => NameFitter::display((string) $card->full_name), 'printed' => true, 'times' => (int) $card->print_count, 'last_by' => optional($last->user)->name, 'last_at' => $last->created_at->timezone(config('app.timezone'))->format('j M Y, H:i'),
            'recent' => $rows->map(fn ($p) => ($p->action) . ' by ' . optional($p->user)->name . ' on ' . $p->created_at->timezone(config('app.timezone'))->format('j M Y H:i'))->all()];
    }
}
