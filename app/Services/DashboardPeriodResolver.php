<?php

namespace App\Services;

use Illuminate\Http\Request;

class DashboardPeriodResolver
{
    public function resolve(Request $request, ?callable $hasDataForPeriod = null): array
    {
        if ($request->filled('month') && $request->filled('year')) {
            return [(int) $request->input('month'), (int) $request->input('year')];
        }

        return [now()->month, now()->year];
    }
}
