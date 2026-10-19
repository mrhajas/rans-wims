<?php

namespace App\Services\Weather;

use App\Models\SiteDiary;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FeatureManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Who may see which project's weather, and which projects the weather module works on. */
class WeatherAccess
{
    public static function feature(string $key, ?User $user): bool
    {
        return FeatureManager::canAccess($key, $user);
    }

    /** Every project and warehouse (main warehouses included) the person may see: the same visibility as Site Diary. */
    public static function projects(User $user, bool $activeOnly = true): Builder
    {
        $q = Warehouse::query()->withoutGlobalScope('account')->where('warehouses.account_id', $user->account_id);
        if ($activeOnly) {
            $q->where('warehouses.active', 1)->whereNull('warehouses.completed_at');
        }
        // an administrator sees every project and warehouse, even when a few are assigned to them personally; everybody else only their own
        $ids = $user->hasRole(['Super Admin', 'Admin']) ? null : SiteDiary::projectIdsFor($user);
        if ($ids !== null) {
            $q->whereIn('warehouses.id', $ids ?: [0]);
        }

        return $q;
    }

    public static function canSeeProject(User $user, int|Warehouse $project): bool
    {
        $id = $project instanceof Warehouse ? $project->id : $project;

        return self::projects($user, false)->where('warehouses.id', $id)->exists();
    }

    /** Projects and warehouses the scheduler keeps fresh: active, not completed, with coordinates. */
    public static function scheduledProjects(?int $accountId = null): Builder
    {
        return Warehouse::query()->withoutGlobalScope('account')->whereNull('deleted_at')->where('active', 1)->whereNull('completed_at')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId));
    }
}
