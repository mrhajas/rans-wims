<?php

namespace App\Http\Controllers\IdPasses\Concerns;

use App\Models\IdPassCard;
use App\Services\FeatureManager;
use App\Services\IdPass\IdPassKinds;
use Illuminate\Http\Request;

/** Shared by the ID & Pass controllers: every action names the permission it needs (a route prefix is not trusted), and a card is only reachable through its own kind. */
trait Guards
{
    protected function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    protected function needAny(array $abilities): void
    {
        $u = auth()->user();
        abort_unless(collect($abilities)->contains(fn ($a) => $u->can($a)), 403);
    }

    /** The module is on and the person may open it. */
    protected function module(): void
    {
        $u = auth()->user();
        abort_unless(FeatureManager::canAccess('id_passes', $u) && $u->can('read-id-passes'), 403);
    }

    protected function feature(string $key): void
    {
        abort_unless(FeatureManager::canAccess($key, auth()->user()), 403);
    }

    /** The kind the route belongs to (set by the route group), and a check that the person may read it. */
    protected function kindOf(Request $request): string
    {
        $kind = (string) $request->route('kind');
        abort_unless(isset(IdPassKinds::ALL[$kind]), 404);
        abort_unless(IdPassKinds::canRead($kind), 403);

        return $kind;
    }

    protected function cardOf(Request $request, string $kind): IdPassCard
    {
        return IdPassCard::query()->where('kind', $kind)->with('category')->findOrFail((int) $request->route('card'));
    }

    protected function can(string $kind, string $action): bool
    {
        return IdPassKinds::can($kind, $action);
    }

    protected function must(string $kind, string $action): void
    {
        abort_unless($this->can($kind, $action), 403);
    }

    protected function like(string $v): string
    {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
    }
}
