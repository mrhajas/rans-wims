<?php

namespace App\Support;

use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which Material Requests, decided by PERMISSIONS on the Roles screen (the old per-role "Who Sees What" rules
 * are gone). ONE implementation serves both the single-record check (isVisible, used by the policy) and the list
 * scope (scopeQueryFor, used by the index, dashboard, offline sync, search, hover preview and the AI tool), so the two
 * can never disagree.
 *
 *  - read-material-requests          opens the module: the person sees (a) the requests they created and (b) the
 *                                    requests of the projects (warehouses) they are assigned to, once those are
 *                                    Approved, Partially Issued, Issued or Cleared.
 *  - view-all-material-requests      sees every request of every project in every status.
 *  - neither                         no access at all (not even to their own requests).
 *
 * Super Admin holds every permission through the global Gate::before, so it sees everything.
 */
class MaterialRequestVisibility
{
    public const READ = 'read-material-requests';
    public const VIEW_ALL = 'view-all-material-requests';

    /** Statuses a site person (assigned to the project but without "view all") can see. */
    public static function siteStatuses(): array
    {
        return [
            MaterialRequest::STATUS_APPROVED,
            MaterialRequest::STATUS_PARTIALLY_ISSUED,
            MaterialRequest::STATUS_ISSUED,
            MaterialRequest::STATUS_CLEARED,
        ];
    }

    public static function canViewAll(User $user): bool
    {
        return $user->can(self::VIEW_ALL);
    }

    /** Hard gate for reaching the module at all (view all implies read). */
    public static function hasAnyAccess(User $user): bool
    {
        return $user->can(self::READ) || $user->can(self::VIEW_ALL);
    }

    /** True when the person sees nothing but their own requests (no view-all and no assigned project), which hides the "Requested By" / Warehouse filters. */
    public static function isOwnOnly(User $user): bool
    {
        return self::hasAnyAccess($user)
            && ! self::canViewAll($user)
            && ! $user->warehouses()->exists();
    }

    public static function isVisible(User $user, MaterialRequest $materialRequest): bool
    {
        if (! self::hasAnyAccess($user)) {
            return false;
        }
        if (self::canViewAll($user)) {
            return true;
        }
        if ((int) $materialRequest->user_id === (int) $user->id) {
            return true;
        }

        return in_array($materialRequest->status, self::siteStatuses(), true)
            && $user->warehouses()->where('warehouses.id', $materialRequest->warehouse_id)->exists();
    }

    /** The same rule as isVisible(), as a query scope (mutates and returns $query). */
    public static function scopeQueryFor(Builder $query, User $user): Builder
    {
        if (! self::hasAnyAccess($user)) {
            return $query->whereRaw('1 = 0');
        }
        if (self::canViewAll($user)) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $warehouseIds = $user->warehouses()->pluck('warehouses.id');

        return $query->where(function (Builder $any) use ($table, $user, $warehouseIds) {
            $any->where($table . '.user_id', $user->id)
                ->orWhere(function (Builder $site) use ($table, $warehouseIds) {
                    $site->whereIn($table . '.warehouse_id', $warehouseIds)
                        ->whereIn($table . '.status', self::siteStatuses());
                });
        });
    }
}
