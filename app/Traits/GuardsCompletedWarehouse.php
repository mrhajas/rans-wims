<?php

namespace App\Traits;

use App\Models\Warehouse;

trait GuardsCompletedWarehouse
{
    protected function completedWarehouseMessage(): string
    {
        return __("This project has been marked Complete and is now read-only, so this action can't be processed. Please contact your administrator if changes are still needed.");
    }

    protected function isAnyWarehouseCompleted(...$warehouseIds): bool
    {
        $user = request()->user();
        if ($user && $user->hasRole('Super Admin')) {
            return false;
        }

        $ids = array_filter($warehouseIds);

        return $ids && Warehouse::whereIn('id', $ids)->whereNotNull('completed_at')->exists();
    }

    /**
     * Use in mutating/GET actions that can gracefully redirect back with a
     * flash message the user will actually see (this app's Error.vue page
     * ignores abort() messages, so a hard abort() alone shows a generic
     * "Access Forbidden" screen with no explanation).
     */
    protected function rejectIfWarehouseCompleted(...$warehouseIds)
    {
        if (! $this->isAnyWarehouseCompleted(...$warehouseIds)) {
            return null;
        }

        return back()->with('error', $this->completedWarehouseMessage());
    }

    /**
     * Use only where a hard stop is unavoidable (e.g. no sensible "back" to
     * redirect to). Prefer rejectIfWarehouseCompleted() wherever possible.
     */
    protected function abortIfWarehouseCompleted(...$warehouseIds): void
    {
        if ($this->isAnyWarehouseCompleted(...$warehouseIds)) {
            abort(403, $this->completedWarehouseMessage());
        }
    }
}
