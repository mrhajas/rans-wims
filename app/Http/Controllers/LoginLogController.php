<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

class LoginLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LoginLog::class);

        $filters = $request->all(
            'search',
            'status',
            'role',
            'start_date',
            'end_date',
            'sort',
            'sort_direction'
        );

        $query = LoginLog::query()->with('user:id,name,username')->filter($filters);

        // Success/Failed counts ignore the `status` filter itself (but honor
        // every other active filter — search/role/date range) so the two stat
        // cards always show the true split to click between, rather than one
        // of them silently reading 0 whenever a status filter is already applied.
        $countFilters = collect($filters)->except('status')->all();
        $countBase = LoginLog::query()->filter($countFilters);

        return Inertia::render('LoginLog/Index', [
            'filters' => $filters,
            'loginLogs' => $query->paginate()->withQueryString(),
            'roles' => Role::query()->orderBy('name')->pluck('name')->values(),
            'addressTabs' => $this->addressTabs($request),
            'canExport' => $request->user()->can('export-login-logs'),
            'canPrint' => $request->user()->can('print-login-logs'),
            'stats' => [
                'success' => (clone $countBase)->where('status', 'success')->count(),
                'failed' => (clone $countBase)->where('status', 'failed')->count(),
            ],
        ]);
    }

    /** the Blacklist / Whitelist tabs (and their counts): Super Admin only, null for everyone else */
    private function addressTabs(Request $request): ?array
    {
        if (! $request->user()->hasRole('Super Admin') || ! \App\Services\Security\AccessGuard::ready()) {
            return null;
        }
        $live = fn ($q) => $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return [
            'black' => $live(\Illuminate\Support\Facades\DB::table('security_ip_rules')->where('type', 'black'))->count() + \Illuminate\Support\Facades\DB::table('security_counters')->whereIn('scope', ['login_fail', 'otp_send'])->where('blocked_until', '>', now())->count(),
            'white' => $live(\Illuminate\Support\Facades\DB::table('security_ip_rules')->where('type', 'white'))->count(),
        ];
    }

    public function show(LoginLog $loginLog)
    {
        $this->authorize('view', $loginLog);

        $loginLog->load('user:id,name,username,email');

        return Inertia::render('LoginLog/Show', [
            'loginLog' => $loginLog,
        ]);
    }

    public function export(Request $request)
    {
        $this->authorize('export', LoginLog::class);

        $rows = LoginLog::query()->with('user:id,name,username')->filter($request->all(
            'search',
            'status',
            'role',
            'start_date',
            'end_date',
            'sort',
            'sort_direction'
        ))->limit(10000)->get();

        return (new FastExcel($rows))->download('login-logs-' . now()->format('Ymd-His') . '.xlsx', function (LoginLog $log) {
            return [
                'Date Time' => optional($log->attempted_at)->format('Y-m-d H:i:s'),
                'Username' => $log->username,
                'Full Name' => optional($log->user)->name,
                'Role' => $log->role,
                'Status' => ucfirst($log->status),
                'IP Address' => $log->ip_address,
                'Device' => $log->device,
                'OS' => $log->os,
                'Browser' => $log->browser,
                'Location' => $log->location,
                'Error' => $log->error_message,
            ];
        });
    }

    public function print(Request $request)
    {
        $this->authorize('print', LoginLog::class);

        return view('login-logs-print', $this->viewData($request, true));
    }

    public function pdf(Request $request)
    {
        $this->authorize('print', LoginLog::class);

        // Up to 3000 log rows are drawn into one PDF: the default 128 MB of memory ran out (500 error).
        @ini_set('memory_limit', '1024M');
        @set_time_limit(180);
        $pdf = Pdf::loadView('login-logs-print', $this->viewData($request, false));

        return $pdf->download('login-logs-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function viewData(Request $request, bool $autoPrint): array
    {
        $filters = $request->all(
            'search',
            'status',
            'role',
            'start_date',
            'end_date',
            'sort',
            'sort_direction'
        );

        $logs = LoginLog::query()->with('user:id,name,username')->filter($filters)->limit(3000)->get();

        return [
            'logs' => $logs,
            'filters' => $filters,
            'autoPrint' => $autoPrint,
            'isPdf' => ! $autoPrint,
            'companyName' => optional($request->user()->account ?? null)->name,
            'generatedBy' => optional($request->user())->name,
        ];
    }
}
