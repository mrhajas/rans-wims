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

    /**
     * Stock movement halted by an Adjustment Session (see App\Support\StockHalt). Unlike "completed" this holds for everybody, a Super Admin too.
     * Flash message names the person who halted it.
     */
    protected function rejectIfStockHalted(...$warehouseIds)
    {
        $halt = \App\Support\StockHalt::blocking(...$warehouseIds);
        if (! $halt) {
            return null;
        }

        return back()->with('error', \App\Support\StockHalt::message($halt));
    }

    /** For the actions that move stock: a completed project (read-only) or a warehouse whose stock movement is halted. */
    protected function rejectIfWarehouseBlocked(...$warehouseIds)
    {
        return $this->rejectIfWarehouseCompleted(...$warehouseIds) ?? $this->rejectIfStockHalted(...$warehouseIds);
    }
}
