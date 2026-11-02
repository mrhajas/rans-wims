<?php

namespace App\Support\BackgroundTasks\Kinds;

use App\Http\Controllers\ExecutiveReportController;
use App\Models\User;
use App\Support\BackgroundTasks\TaskKind;
use Illuminate\Http\Request;

/** The Executive Intelligence Report preview PDF (the weekly figures are worked out from the whole system, which takes the longest). Reads only; sends nothing to anyone. */
class ExecutiveReportPreview extends TaskKind
{
    public function key(): string
    {
        return 'executive-report-preview';
    }

    public function label(array $params): string
    {
        return __('Executive Report preview');
    }

    public function allowed(User $user): bool
    {
        return $user->hasPermissionTo('manage-executive-reports');
    }

    public function params(Request $request): array
    {
        return [];
    }

    public function build(array $params, User $user): array
    {
        return $this->callAction(ExecutiveReportController::class, 'preview', [], $user);
    }
}
