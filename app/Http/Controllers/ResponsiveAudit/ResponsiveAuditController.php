<?php

namespace App\Http\Controllers\ResponsiveAudit;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\FeatureManager;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Responsive Audit: a Super Admin tool that opens every WIMS page at phone, tablet and desktop widths (in hidden frames, in the person's
 * own browser) and records buttons that wrap, clipped controls, small touch targets, sideways scrolling and dialog problems. The browser
 * does the measuring; the server keeps the runs, shows them, prints them and tells the Alerts audience. Nothing here changes any record.
 */
class ResponsiveAuditController extends Controller
{
    private const KEY = 'responsive_audit';
    private const DEFAULTS = ['min_target_px' => 32, 'widths' => [360, 390, 820, 1024], 'excluded' => []];
    private const CATEGORIES = ['wrap' => 'Button or chip label on two lines', 'over' => 'Control outside the screen', 'clipped' => 'Clipped label', 'small' => 'Small touch target', 'scroll' => 'Page scrolls sideways', 'dialog' => 'Dialog or dropdown problem'];

    public const STANDARDS = [
        'Button, chip and tab labels stay on one line. When a row is too narrow the controls move to the next line instead of squeezing a label onto two.',
        'Rows of buttons wrap deliberately and forms give a lone action the full width on phones.',
        'Controls are never shrunk below a comfortable size. Icon-only controls get a finger-sized hit area without changing how they look.',
        'The page never scrolls sideways. Media and code blocks stay inside their container and wide tables scroll inside their own wrapper or turn into cards.',
        'Dialogs, drawers, dropdowns, date pickers and search fields stay inside the screen and scroll when they are taller than it.',
        'These rules live in one place (the shared responsive stylesheet), so pages do not carry their own overrides.',
    ];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function user(Request $request, string $ability)
    {
        $user = $request->user();
        abort_unless($user->can($ability) && FeatureManager::canAccess('responsive_audit', $user), 403);

        return $user;
    }

    private function prefsFor(int $account): array
    {
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $account)->value('tec_value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public function index(Request $request)
    {
        $user = $this->user($request, 'view-responsive-audit');
        $prefs = $this->prefsFor($user->account_id);
        $runs = DB::table('ui_audit_runs')->where('account_id', $user->account_id)->orderByDesc('id')->limit(30)->get()->map(fn ($r) => [
            'id' => $r->id, 'width' => $r->width, 'pages' => $r->pages_scanned, 'findings' => $r->findings_total, 'summary' => json_decode($r->summary ?: '[]', true),
            'by' => $r->created_by ? (string) DB::table('users')->where('id', $r->created_by)->value('name') : null, 'at' => $r->created_at, 'seconds' => $r->duration_seconds,
        ]);

        return Inertia::render('ResponsiveAudit/Index', [
            'runs' => $runs,
            'prefs' => $prefs,
            'standards' => self::STANDARDS,
            'categories' => collect(self::CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'pages' => $this->pages($user, $prefs['excluded']),
            'can' => ['run' => $user->can('run-responsive-audit'), 'settings' => $user->can('manage-responsive-audit-settings'), 'print' => $user->can('print-responsive-audit')],
        ]);
    }

    /** Every parameter-free page route behind the sign-in that the person may open (the audit opens them as that person). */
    private function pages($user, array $excluded): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || $name === '' || ! in_array('feature.gate', $route->gatherMiddleware(), true)) {
                continue;
            }
            // the audit only LOOKS at pages: anything that changes or removes data is never opened, whatever it is called
            if (preg_match('/(maintenance|reset|wipe|purge|truncate|destroy)/i', $name . $uri)) {
                continue;
            }
            if (preg_match('/(print|pdf|csv|xls|export|download|file|data|json|ajax|health|sync|heartbeat|logout|options|search|lookup|suggest|reconcile|responsive-audit)/', $name . $uri) || Str::startsWith($uri, ['_', 'api', 'sanctum', 'livewire', 'storage', 'user/', 'two-factor', 'commands', 'face-login', 'offline', 'rauncher', 'quick-menu', 'hover-preview', 'media', 'scan', 'alerts', 'wimshow'])) {
                continue;
            }
            $feature = \App\Services\FeatureManager::featureForRoute($name);
            if ($feature && ! FeatureManager::canAccess($feature, $user)) {
                continue;
            }
            $url = '/' . ltrim($uri, '/');
            if (in_array($url, $excluded, true)) {
                continue;
            }
            $out[$url] = true;
        }

        return array_keys($out);
    }

    public function store(Request $request)
    {
        $user = $this->user($request, 'run-responsive-audit');
        $data = $request->validate([
            'width' => ['required', 'integer', 'min:240', 'max:2600'],
            'pages_scanned' => ['required', 'integer', 'min:0', 'max:5000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'findings' => ['array', 'max:3000'],
            'findings.*.page' => ['required', 'string', 'max:190'],
            'findings.*.category' => ['required', 'in:' . implode(',', array_keys(self::CATEGORIES))],
            'findings.*.selector' => ['nullable', 'string', 'max:190'],
            'findings.*.label' => ['nullable', 'string', 'max:120'],
            'findings.*.detail' => ['nullable', 'string', 'max:255'],
        ]);
        $findings = collect($data['findings'] ?? []);
        $summary = $findings->groupBy('category')->map->count()->all();
        $previous = DB::table('ui_audit_runs')->where('account_id', $user->account_id)->where('width', $data['width'])->orderByDesc('id')->value('findings_total');

        $runId = DB::transaction(function () use ($user, $data, $findings, $summary, $request) {
            $id = DB::table('ui_audit_runs')->insertGetId([
                'account_id' => $user->account_id, 'width' => $data['width'], 'pages_scanned' => $data['pages_scanned'], 'findings_total' => $findings->count(), 'summary' => json_encode($summary),
                'source' => 'browser', 'duration_seconds' => $data['duration_seconds'] ?? null, 'user_agent' => Str::limit((string) $request->userAgent(), 250, ''), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($findings->chunk(300) as $chunk) {
                DB::table('ui_audit_findings')->insert($chunk->map(fn ($f) => [
                    'run_id' => $id, 'page' => $f['page'], 'category' => $f['category'], 'selector' => $f['selector'] ?? null, 'label' => $f['label'] ?? null, 'detail' => $f['detail'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ])->all());
            }

            return $id;
        });
        log_activity('Responsive audit recorded', ['run_id' => $runId, 'width' => $data['width'], 'pages' => $data['pages_scanned'], 'findings' => $findings->count()], null, 'Responsive Audit');

        $ctx = ['account_id' => $user->account_id, 'url' => route('responsive-audit.runs.show', $runId), 'owner_user_id' => $user->id, 'dedupe' => 'responsive_audit|' . $runId,
            'values' => ['width' => $data['width'], 'findings' => $findings->count(), 'pages' => $data['pages_scanned'], 'previous' => (int) $previous]];
        if ($findings->count() > 0) {
            WorkflowMessenger::dispatch('responsive_audit', 'audit_completed', null, $user, $ctx + ['title' => __('Responsive audit finished'), 'message' => __(':n finding(s) at :w px on :p page(s).', ['n' => $findings->count(), 'w' => $data['width'], 'p' => $data['pages_scanned']])]);
        }
        if ($previous !== null && $findings->count() > (int) $previous) {
            WorkflowMessenger::dispatch('responsive_audit', 'audit_regression', null, $user, $ctx + ['dedupe' => 'responsive_audit|reg|' . $runId, 'title' => __('Responsive audit: more problems than before'), 'message' => __('The audit at :w px found :n finding(s), up from :p.', ['w' => $data['width'], 'n' => $findings->count(), 'p' => $previous])]);
        }

        return response()->json(['id' => $runId, 'findings' => $findings->count(), 'previous' => $previous]);
    }

    public function show(Request $request, int $run)
    {
        $user = $this->user($request, 'view-responsive-audit');
        $row = DB::table('ui_audit_runs')->where('account_id', $user->account_id)->where('id', $run)->first();
        abort_unless($row, 404);
        $findings = DB::table('ui_audit_findings')->where('run_id', $run)->orderBy('category')->orderBy('page')->limit(3000)->get(['page', 'category', 'selector', 'label', 'detail']);

        return response()->json([
            'run' => ['id' => $row->id, 'width' => $row->width, 'pages' => $row->pages_scanned, 'findings' => $row->findings_total, 'summary' => json_decode($row->summary ?: '[]', true), 'at' => $row->created_at],
            'findings' => $findings,
            'previous' => DB::table('ui_audit_runs')->where('account_id', $user->account_id)->where('width', $row->width)->where('id', '<', $run)->orderByDesc('id')->value('findings_total'),
        ]);
    }

    public function print(Request $request, int $run)
    {
        $user = $this->user($request, 'print-responsive-audit');
        $row = DB::table('ui_audit_runs')->where('account_id', $user->account_id)->where('id', $run)->first();
        abort_unless($row, 404);
        $findings = DB::table('ui_audit_findings')->where('run_id', $run)->orderBy('category')->orderBy('page')->limit(3000)->get();
        log_activity('Responsive audit printed', ['run_id' => $run], null, 'Responsive Audit');

        return view('responsive-audit.print', [
            'run' => $row, 'findings' => $findings, 'categories' => self::CATEGORIES, 'summary' => json_decode($row->summary ?: '[]', true), 'standards' => self::STANDARDS,
            'printedBy' => $user->name, 'printedAt' => now()->format('d M Y, H:i'), 'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional($user->account)->name),
        ]);
    }

    public function csv(Request $request, int $run)
    {
        $user = $this->user($request, 'print-responsive-audit');
        $row = DB::table('ui_audit_runs')->where('account_id', $user->account_id)->where('id', $run)->first();
        abort_unless($row, 404);
        log_activity('Responsive audit downloaded', ['run_id' => $run], null, 'Responsive Audit');
        $findings = DB::table('ui_audit_findings')->where('run_id', $run)->orderBy('category')->orderBy('page')->get();
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;

        return response()->streamDownload(function () use ($findings, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Page', 'Problem', 'Control', 'Label', 'Detail']);
            foreach ($findings as $f) {
                fputcsv($out, [$safe($f->page), self::CATEGORIES[$f->category] ?? $f->category, $safe($f->selector), $safe($f->label), $safe($f->detail)]);
            }
            fclose($out);
        }, 'responsive-audit-' . $run . '-' . $row->width . 'px.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function settings(Request $request)
    {
        $user = $this->user($request, 'manage-responsive-audit-settings');
        $data = $request->validate([
            'min_target_px' => ['required', 'integer', 'min:20', 'max:64'],
            'widths' => ['required', 'array', 'min:1', 'max:6'],
            'widths.*' => ['integer', 'min:240', 'max:2600'],
            'excluded' => ['nullable', 'array', 'max:200'],
            'excluded.*' => ['string', 'max:190', 'starts_with:/'],
        ]);
        $before = $this->prefsFor($user->account_id);
        $after = ['min_target_px' => (int) $data['min_target_px'], 'widths' => array_values(array_unique(array_map('intval', $data['widths']))), 'excluded' => array_values(array_unique($data['excluded'] ?? []))];
        Setting::query()->updateOrCreate(['tec_key' => self::KEY, 'account_id' => $user->account_id], ['tec_value' => json_encode($after)]);
        log_activity('Responsive audit standards changed', ['before' => $before, 'after' => $after], null, 'Responsive Audit');
        WorkflowMessenger::dispatch('responsive_audit', 'settings_changed', null, $user, ['account_id' => $user->account_id, 'title' => __('Responsive audit standards changed'), 'message' => __('The responsive audit standards were changed by :n.', ['n' => $user->name]), 'url' => route('responsive-audit.index'), 'dedupe' => 'responsive_audit|settings|' . intdiv(time(), 30)]);

        return back()->with('message', __('Audit standards saved.'));
    }
}
