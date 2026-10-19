<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SiteReportAssignments
{
    /**
     * Every (site engineer, assigned active project/warehouse) pair — the single
     * source of truth for "who must submit a report where," used by both the
     * missing-report reminder command and the compliance matrix so the two can
     * never disagree on scope.
     *
     * When $viewer is a Project Manager (and not also Admin/Super Admin), the pairs
     * are restricted to warehouses they manage. Admin/Super Admin (or no viewer,
     * e.g. from a console command) see every pair.
     *
     * @return Collection<int, object{user_id:int,user_name:string,warehouse_id:int,warehouse_name:string}>
     */
    public static function pairs(?User $viewer = null): Collection
    {
        $query = DB::table('user_warehouse')
            ->join('users', 'users.id', '=', 'user_warehouse.user_id')
            ->join('model_has_roles', function ($join) {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', User::SITE_ENGINEER_ROLES)
            ->whereNull('users.deleted_at')
            ->join('warehouses', 'warehouses.id', '=', 'user_warehouse.warehouse_id')
            ->where('warehouses.active', 1)
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                'warehouses.id as warehouse_id',
                'warehouses.name as warehouse_name'
            )
            ->distinct();

        if ($viewer && $viewer->hasRole(['Project Manager', 'Project Managers']) && ! $viewer->hasRole(['Super Admin', 'Admin'])) {
            $managedIds = $viewer->warehouses()->pluck('warehouses.id');
            $query->whereIn('warehouses.id', $managedIds);
        }

        return $query->orderBy('users.name')->orderBy('warehouses.name')->get();
    }
}
