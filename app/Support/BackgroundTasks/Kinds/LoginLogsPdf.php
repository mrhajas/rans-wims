<?php

namespace App\Support\BackgroundTasks\Kinds;

use App\Http\Controllers\LoginLogController;
use App\Http\Middleware\ConfirmLoginLogsAccess;
use App\Models\LoginLog;
use App\Models\User;
use App\Support\BackgroundTasks\TaskKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Login Logs PDF (the slowest report in WIMS: up to 3,000 rows drawn into one PDF). */
class LoginLogsPdf extends TaskKind
{
    public function key(): string
    {
        return 'login-logs-pdf';
    }

    public function label(array $params): string
    {
        return __('Login Logs PDF');
    }

    public function allowed(User $user): bool
    {
        return $user->can('print', LoginLog::class);
    }

    public function params(Request $request): array
    {
        $v = Validator::make((array) $request->input('params', []), [
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:30',
            'user_id' => 'nullable|integer',
            'role' => 'nullable|string|max:80',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'sort' => 'nullable|in:attempted_at,username,role,status,ip_address,os,browser',
            'sort_direction' => 'nullable|in:asc,desc',
        ])->validate();

        return array_filter($v, fn ($x) => $x !== null && $x !== '');
    }

    /** The same password confirmation the Login Logs pages ask for must be valid when the task is started. */
    public function startRefusal(Request $request): ?string
    {
        return ConfirmLoginLogsAccess::unlocked($request) ? null : __('Login Logs are locked again. Open Login Logs and confirm your password, then start the PDF.');
    }

    public function downloadMiddleware(): ?string
    {
        return ConfirmLoginLogsAccess::class;
    }

    public function build(array $params, User $user): array
    {
        return $this->callAction(LoginLogController::class, 'pdf', $params, $user);
    }
}
