<?php

namespace App\Support\BackgroundTasks\Kinds;

use App\Http\Controllers\DownloadPrintLogController;
use App\Models\DownloadPrintLog;
use App\Models\User;
use App\Support\BackgroundTasks\TaskKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Download and Print Logs PDF. */
class DownloadPrintLogsPdf extends TaskKind
{
    public function key(): string
    {
        return 'download-print-logs-pdf';
    }

    public function label(array $params): string
    {
        return __('Download and Print Logs PDF');
    }

    public function allowed(User $user): bool
    {
        return $user->can('viewAny', DownloadPrintLog::class);
    }

    public function params(Request $request): array
    {
        $v = Validator::make((array) $request->input('params', []), [
            'search' => 'nullable|string|max:100',
            'action_type' => 'nullable|string|max:40',
            'module' => 'nullable|string|max:80',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'sort' => 'nullable|in:performed_at,user_name,action_type,module,ip_address,browser,status_code',
            'sort_direction' => 'nullable|in:asc,desc',
        ])->validate();

        return array_filter($v, fn ($x) => $x !== null && $x !== '');
    }

    public function build(array $params, User $user): array
    {
        return $this->callAction(DownloadPrintLogController::class, 'pdf', $params, $user);
    }
}
