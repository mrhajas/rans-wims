<?php

namespace App\Http\Controllers;

use App\Models\DownloadPrintLog;
use App\Models\OptimizerRun;
use App\Support\Optimizer\Metrics;
use App\Support\Optimizer\OptimizerSettings;
use App\Support\Optimizer\Runner;
use App\Support\Optimizer\Tasks;
use App\Support\PrintDocuments;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Settings > Optimize: makes the data already on the server smaller and faster (database tables, uploaded pictures, log files, technical leftovers), measures what
 * was gained and keeps an audit history that can be printed. Two permissions: read-optimizer (see the page, the history, print) and run-optimizer (scan and run).
 * Nobody but the Super Admin has them until a role is given them on the Roles screen.
 */
class OptimizerController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function allow(Request $request, string $ability): void
    {
        abort_unless($request->user() && $request->user()->can($ability), 403);
    }

    public function index(Request $request)
    {
        $this->allow($request, 'read-optimizer');
        $runs = OptimizerRun::query()->orderByDesc('id')->paginate(10)->through(fn (OptimizerRun $r) => $this->row($r))->withQueryString();

        return Inertia::render('Settings/Optimize', [
            'opt' => OptimizerSettings::all(),
            'limits' => OptimizerSettings::LIMITS,
            'defaults' => OptimizerSettings::DEFAULTS,
            'catalog' => Tasks::catalog(),
            'defaultTasks' => Tasks::DEFAULT,
            'runs' => $runs,
            'canRun' => $request->user()->can('run-optimizer'),
            'last' => optional(OptimizerRun::query()->whereIn('status', ['done', 'partial'])->latest('id')->first(), fn ($r) => $this->row($r)),
        ]);
    }

    /** What could be won, task by task. Nothing is changed. */
    public function scan(Request $request)
    {
        $this->allow($request, 'run-optimizer');
        @set_time_limit(120);

        return response()->json(Runner::scan());
    }

    public function updateSettings(Request $request)
    {
        $this->allow($request, 'run-optimizer');
        $rules = [];
        foreach (OptimizerSettings::LIMITS as $key => [$min, $max]) {
            $rules[$key] = "required|integer|min:$min|max:$max";
        }
        $data = $request->validate($rules);
        $before = OptimizerSettings::all();
        $saved = OptimizerSettings::save($data, (int) $request->user()->account_id);
        log_activity(__('Optimize settings saved'), ['from' => $before, 'to' => $saved], $request->user(), 'Setting');

        return back()->with('message', __('Optimize settings saved.'));
    }

    /** Starts an optimisation. It changes data on the server, so the person's own password is asked. The screen then asks for each task in turn (step) and closes it (finish). */
    public function run(Request $request)
    {
        $this->allow($request, 'run-optimizer');
        $data = $request->validate(['tasks' => 'required|array|min:1', 'tasks.*' => 'string|in:' . implode(',', Tasks::ALL), 'password' => 'required|string']);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
        $run = Runner::begin($data['tasks'], $request->user());
        $catalog = Tasks::catalog();

        return response()->json(['id' => $run->id, 'tasks' => array_map(fn ($k) => ['key' => $k, 'title' => $catalog[$k]['title'], 'tone' => $catalog[$k]['tone']], (array) $run->tasks)]);
    }

    /** One task of a running optimisation (a task that stopped at its time limit answers `more`: the screen asks again). */
    public function step(Request $request, int $run)
    {
        $this->allow($request, 'run-optimizer');
        $data = $request->validate(['task' => 'required|string|in:' . implode(',', Tasks::ALL)]);
        $record = OptimizerRun::query()->where('user_id', $request->user()->id)->findOrFail($run);
        $r = Runner::step($record, $data['task']);

        return response()->json(['ok' => (bool) ($r['ok'] ?? false), 'more' => (bool) ($r['more'] ?? false), 'summary' => $r['summary'] ?? '', 'changed' => (int) ($r['changed'] ?? 0), 'bytes' => (int) ($r['bytes'] ?? 0), 'bytes_text' => Runner::bytes((int) ($r['bytes'] ?? 0))]);
    }

    public function finish(Request $request, int $run)
    {
        $this->allow($request, 'run-optimizer');
        $record = OptimizerRun::query()->where('user_id', $request->user()->id)->findOrFail($run);
        $done = $record->status === 'running' ? Runner::finish($record, $request->user()) : $record;

        return response()->json(['id' => $done->id, 'status' => $done->status, 'rating' => $done->rating, 'rating_label' => Runner::ratingLabel($done->rating), 'saved_text' => Runner::bytes((int) $done->saved_bytes), 'url' => route('optimizer.show', $done->id)]);
    }

    public function show(Request $request, OptimizerRun $run)
    {
        $this->allow($request, 'read-optimizer');

        return Inertia::render('Settings/OptimizeRun', ['run' => $this->detail($run)]);
    }

    public function print(Request $request, int $run)
    {
        $this->allow($request, 'read-optimizer');

        return view('print.document', $this->documentData($request, OptimizerRun::query()->findOrFail($run), false, 'printed'));
    }

    public function pdf(Request $request, int $run)
    {
        $this->allow($request, 'read-optimizer');
        $record = OptimizerRun::query()->findOrFail($run);

        return Pdf::loadView('print.document', $this->documentData($request, $record, true, 'downloaded as PDF'))->setPaper('a4', 'landscape')->download('optimisation-' . $record->id . '.pdf');
    }

    /**
     * Every print and download of the report. The Download / Print log (middleware LogDownloadPrintActions) records it after the page is sent, so the person doing it now
     * is added to the report by name here, and the earlier ones come from that log.
     */
    private function printRecord(OptimizerRun $run): array
    {
        return DownloadPrintLog::query()->whereIn('route_name', ['optimizer.print', 'optimizer.pdf'])->where('url', 'like', '%/settings/optimize/runs/' . (int) $run->id . '/%')
            ->orderBy('performed_at')->limit(100)->get(['user_name', 'role', 'action_type', 'performed_at'])
            ->map(fn ($l) => ['who' => $l->user_name, 'role' => $l->role, 'action' => $l->action_type === 'print' ? __('Printed') : __('Downloaded as PDF'), 'at' => optional($l->performed_at)->toDateTimeString()])->all();
    }

    private function documentData(Request $request, OptimizerRun $run, bool $isPdf, string $what): array
    {
        $earlier = $this->printRecord($run);
        log_activity('Optimisation report OPT-' . str_pad((string) $run->id, 5, '0', STR_PAD_LEFT) . ' ' . $what, ['run' => $run->id], $request->user(), 'Setting');

        return [
            'doc' => PrintDocuments::optimizerRun($run, $request->user(), $earlier, $isPdf ? __('Downloaded as PDF') : __('Printed')), 'isPdf' => $isPdf, 'companyName' => optional($request->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('optimizer.show', $run->id)), 'pdfUrl' => route('optimizer.pdf', $run->id),
        ];
    }

    private function row(OptimizerRun $r): array
    {
        $sb = (float) $r->speed_before_ms;
        $sa = (float) $r->speed_after_ms;

        return [
            'id' => $r->id, 'ref' => 'OPT-' . str_pad((string) $r->id, 5, '0', STR_PAD_LEFT), 'user' => $r->user_name, 'status' => $r->status, 'rating' => $r->rating, 'rating_label' => Runner::ratingLabel($r->rating),
            'tasks' => (array) $r->tasks, 'saved' => (int) $r->saved_bytes, 'saved_text' => Runner::bytes((int) $r->saved_bytes),
            'files' => (int) $r->files_changed, 'tables' => (int) $r->tables_changed, 'seconds' => (int) $r->seconds,
            'speed_before' => $sb, 'speed_after' => $sa, 'speed_pct' => $sb > 0 ? round((($sb - $sa) / $sb) * 100, 1) : null,
            'at' => optional($r->started_at)->toDateTimeString(),
        ];
    }

    private function detail(OptimizerRun $r): array
    {
        $b = (array) $r->before;
        $a = (array) $r->after;
        $pairs = [
            ['key' => 'db', 'label' => __('Database (data, indexes and free space inside)'), 'before' => (int) ($b['db_total_bytes'] ?? $b['db_bytes'] ?? 0), 'after' => (int) ($a['db_total_bytes'] ?? $a['db_bytes'] ?? 0)],
            ['key' => 'images', 'label' => __('Uploaded pictures'), 'before' => (int) ($b['images_bytes'] ?? 0), 'after' => (int) ($a['images_bytes'] ?? 0)],
            ['key' => 'logs', 'label' => __('Server log files'), 'before' => (int) ($b['logs_bytes'] ?? 0), 'after' => (int) ($a['logs_bytes'] ?? 0)],
            ['key' => 'originals', 'label' => __('Kept originals of pictures (removed after the days set)'), 'before' => (int) ($b['originals_bytes'] ?? 0), 'after' => (int) ($a['originals_bytes'] ?? 0)],
            ['key' => 'disk', 'label' => __('Free disk space'), 'before' => (int) ($b['disk_free_bytes'] ?? 0), 'after' => (int) ($a['disk_free_bytes'] ?? 0), 'more_is_better' => true],
        ];

        return $this->row($r) + [
            'pairs' => $pairs, 'results' => (array) $r->results, 'options' => (array) $r->options, 'error' => $r->error,
            'speed' => ['before' => (array) $r->speed_before, 'after' => (array) $r->speed_after], 'rating_why' => (array) $r->rating_why, 'remaining' => (array) $r->remaining, 'printed' => $this->printRecord($r),
            'started' => optional($r->started_at)->toDateTimeString(), 'finished' => optional($r->finished_at)->toDateTimeString(),
            'canRun' => request()->user()->can('run-optimizer'),
            'originals_text' => Runner::bytes((int) ($a['originals_bytes'] ?? 0)), 'backups_text' => Runner::bytes((int) ($a['backups_bytes'] ?? 0)),
        ];
    }
}
