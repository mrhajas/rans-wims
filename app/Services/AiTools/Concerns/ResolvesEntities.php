<?php

namespace App\Services\AiTools\Concerns;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Shared name -> model lookups for AI tool handlers. The Claude model only
 * ever supplies free-text names (an item, a warehouse, a user) — never IDs —
 * so every tool resolves those names itself, scoped to the current account,
 * before running the actual query. A miss returns null rather than throwing,
 * so the handler can report "I couldn't find X" instead of erroring.
 */
trait ResolvesEntities
{
    protected function resolveItem(?string $name): ?Item
    {
        if (! $name) {
            return null;
        }

        return Item::ofAccount()->where('name', 'like', '%' . trim($name) . '%')->first();
    }

    protected function resolveWarehouse(?string $name): ?Warehouse
    {
        if (! $name) {
            return null;
        }

        return Warehouse::ofAccount()->visible()->where('name', 'like', '%' . trim($name) . '%')->first();
    }

    protected function resolveUser(?string $name): ?User
    {
        if (! $name) {
            return null;
        }

        $name = trim($name);

        return User::where('account_id', getAccountId())
            ->where(function ($query) use ($name) {
                $query->where('name', 'like', '%' . $name . '%')
                    ->orWhere('username', 'like', '%' . $name . '%');
            })
            ->first();
    }

    /**
     * Resolve which warehouse IDs a query should be restricted to, given an
     * optional explicitly-named warehouse. Returns:
     * - ['ids' => [...], 'denied' => false]  scope to these warehouse IDs
     * - ['ids' => null, 'denied' => false]    unconstrained (full company)
     * - ['ids' => [],   'denied' => true]     named warehouse exists but the
     *                                          user isn't allowed to see it
     */
    protected function resolveWarehouseScope(?Warehouse $warehouse): array
    {
        $accessible = accessible_warehouse_ids();

        if ($warehouse) {
            if (is_array($accessible) && ! in_array($warehouse->id, $accessible, true)) {
                return ['ids' => [], 'denied' => true];
            }

            return ['ids' => [$warehouse->id], 'denied' => false];
        }

        return ['ids' => $accessible, 'denied' => false];
    }

    protected function parseDate(?string $date): ?\Carbon\Carbon
    {
        if (! $date) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether $user is allowed to ask about another user's activity —
     * mirrors the app-wide "mine" scope bypass rule (Super Admin/Admin or
     * an explicit view_all flag), so the assistant never leaks one
     * restricted user's activity to another.
     */
    protected function userMayViewOthers(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || (bool) $user->view_all;
    }
}
