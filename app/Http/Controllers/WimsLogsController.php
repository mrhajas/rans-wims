<?php

namespace App\Http\Controllers;

use App\Models\DownloadPrintLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use App\Support\Performance\Tables;
use App\Models\WimsLogArchive;
use App\Models\WimsLogRun;
use App\Support\Optimizer\Runner;
use App\Support\PrintDocuments;
use App\Support\PrintQrCode;
use App\Support\WimsLogs\ArchiveStore;
use App\Support\WimsLogs\Archiver;
use App\Support\WimsLogs\Sources;
use App\Support\WimsLogs\Restorer;
use App\Support\WimsLogs\WimsLogSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings > WIMS Logs: old log records are moved out of the live database into compressed archive files, on a schedule or on demand, and this is where a Super Admin sees
 * every record that was moved, how much space it took before and after, and reads or downloads it. Two permissions: read-wims-logs (see, read, download, print) and
 * run-wims-logs (run now, change the numbers). Nobody but the Super Admin has them until a role is given them on the Roles screen.
 */
class WimsLogsController extends Controller
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
        $this->allow($request, 'read-wims-logs');
        // each part of the page is loaded on its own: when one fails (a table that is not there yet, a database that answers differently) the page still opens and says what failed,
        // instead of a blank 500
        $errors = [];
        $runs = $this->guard($errors, 'Run history', fn () => WimsLogRun::query()->orderByDesc('id')->paginate(10)->through(fn (WimsLogRun $r) => $this->runRow($r))->withQueryString(), new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10));
        $defaults = ['general' => ['wimslogs_enabled' => 1, 'wimslogs_every_days' => 5, 'wimslogs_optimize' => 1, 'wimslogs_seconds' => 40], 'sources' => collect(Sources::all())->map(fn ($s) => ['on' => true, 'keep_days' => $s['keep_days']])->all()];
        $zero = ['db_bytes' => 0, 'db_free' => 0, 'db_total' => 0, 'archives' => 0, 'rows' => 0, 'original' => 0, 'compressed' => 0, 'stored' => 0, 'saved' => 0, 'saved_pct' => null, 'freed' => 0, 'whole' => 0, 'disk_free' => 0];

        return Inertia::render('Settings/WimsLogs', [
            'conf' => $this->guard($errors, 'Settings', fn () => WimsLogSettings::all(), $defaults),
            'limits' => WimsLogSettings::LIMITS,
            'keepLimits' => [WimsLogSettings::KEEP_MIN, WimsLogSettings::KEEP_MAX],
            'logs' => $this->guard($errors, 'The logs', fn () => $this->logs(), []),
            'overview' => $this->guard($errors, 'The whole space', fn () => $this->overview(), $zero),
            'trend' => $this->guard($errors, 'Run chart', fn () => WimsLogRun::query()->whereIn('status', ['done', 'partial'])->orderByDesc('id')->limit(12)->get()->reverse()->values()
                ->map(fn ($r) => ['ref' => $r->ref(), 'at' => optional($r->started_at)->toDateString(), 'original' => (int) $r->original_bytes, 'stored' => (int) $r->stored_bytes, 'rows' => (int) $r->rows_archived])->all(), []),
            'runs' => $runs,
            'canRun' => $request->user()->can('run-wims-logs'),
            'next' => $this->guard($errors, 'Schedule', fn () => $this->nextScheduled(), null),
            'loadErrors' => $errors,
        ]);
    }

    /** Runs one part of a page; if it fails the failure is logged and described for the page (class, message and where), and the fallback is used. */
    private function guard(array &$errors, string $part, callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            $errors[] = ['part' => $part, 'error' => class_basename($e) . ': ' . \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 400), 'where' => basename($e->getFile()) . ':' . $e->getLine()];

            return $fallback;
        }
    }

    /** The logs, one line each: what is live, what is waiting to move, and what has already been archived (records, sizes, dates). */
    private function logs(array $keepOverride = []): array
    {
        $preview = collect(Archiver::preview($keepOverride))->keyBy('key');
        $agg = WimsLogArchive::query()->selectRaw('source, count(*) as archives, sum(rows_n) as rows_n, sum(original_bytes) as original, sum(compressed_bytes) as compressed, sum(stored_bytes) as `stored`, min(date_from) as date_from, max(date_to) as date_to, max(created_at) as last_at')
            ->groupBy('source')->get()->keyBy('source');
        $out = [];
        foreach (Sources::all() as $key => $src) {
            $p = $preview->get($key);
            $a = $agg->get($key);
            $original = (int) ($a->original ?? 0);
            $stored = (int) ($a->stored ?? 0);
            $out[] = [
                'key' => $key, 'label' => $src['label'], 'help' => $src['help'], 'table' => $src['table'], 'on' => WimsLogSettings::isOn($key), 'keep_days' => WimsLogSettings::keepDays($key),
                'live_rows' => (int) ($p['live_rows'] ?? 0), 'live_bytes' => (int) ($p['live_bytes'] ?? 0), 'due_rows' => (int) ($p['due_rows'] ?? 0), 'due_bytes' => (int) ($p['due_bytes'] ?? 0),
                'oldest_due' => $p['oldest_due'] ?? null, 'cutoff' => $p['cutoff'] ?? null,
                'archives' => (int) ($a->archives ?? 0), 'archived_rows' => (int) ($a->rows_n ?? 0), 'original' => $original, 'compressed' => (int) ($a->compressed ?? 0), 'stored' => $stored,
                'saved' => max(0, $original - $stored), 'saved_pct' => $original > 0 ? round((1 - $stored / $original) * 100) : null,
                'date_from' => $a->date_from ?? null, 'date_to' => $a->date_to ?? null, 'last_at' => $a->last_at ?? null,
            ];
        }

        return $out;
    }

    /** The whole space: the database now, the archive on disk, and what archiving has given back. */
    private function overview(): array
    {
        $db = Archiver::dbSize();
        $t = WimsLogArchive::query()->selectRaw('count(*) as n, coalesce(sum(rows_n),0) as rows_n, coalesce(sum(original_bytes),0) as original, coalesce(sum(compressed_bytes),0) as compressed, coalesce(sum(stored_bytes),0) as `stored`')->first();
        $freed = (int) WimsLogRun::query()->whereIn('status', ['done', 'partial'])->sum('db_freed_bytes');
        $original = (int) $t->original;
        $stored = (int) $t->stored;

        return [
            'db_bytes' => (int) ($db['bytes'] ?? 0), 'db_free' => (int) ($db['free'] ?? 0), 'db_total' => (int) ($db['bytes'] ?? 0) + (int) ($db['free'] ?? 0),
            'archives' => (int) $t->n, 'rows' => (int) $t->rows_n, 'original' => $original, 'compressed' => (int) $t->compressed, 'stored' => $stored,
            'saved' => max(0, $original - $stored), 'saved_pct' => $original > 0 ? round((1 - $stored / $original) * 100) : null,
            'freed' => max(0, $freed), 'whole' => (int) ($db['bytes'] ?? 0) + (int) ($db['free'] ?? 0) + $stored,
            'disk_free' => (int) @disk_free_space(base_path()),
        ];
    }

    private function nextScheduled(): ?array
    {
        if (! WimsLogSettings::get('wimslogs_enabled')) {
            return null;
        }
        $last = WimsLogRun::query()->where('trigger', 'schedule')->whereIn('status', ['done', 'partial'])->latest('started_at')->value('started_at');
        $every = WimsLogSettings::get('wimslogs_every_days');
        $due = $last ? \Illuminate\Support\Carbon::parse($last)->addDays($every)->startOfDay() : now();
        $at = $due->lt(now()) ? now()->addDay()->setTime(2, 40) : $due->setTime(2, 40);

        return ['at' => $at->toDateTimeString(), 'every_days' => $every, 'last' => $last ? (string) $last : null];
    }

    /** What a run would do now. Nothing is changed. */
    public function preview(Request $request)
    {
        $this->allow($request, 'read-wims-logs');
        // `days[source]=n`: what a run by hand would move if that log kept only n days live (the saved setting is not changed)
        $days = collect((array) $request->query('days', []))->filter(fn ($v, $k) => Sources::get((string) $k) && is_numeric($v))->map(fn ($v) => (int) $v)->all();

        return response()->json(['logs' => $this->logs($days), 'overview' => $this->overview()]);
    }

    public function updateSettings(Request $request)
    {
        $this->allow($request, 'run-wims-logs');
        $rules = ['general' => 'required|array', 'sources' => 'required|array'];
        foreach (WimsLogSettings::LIMITS as $key => [$min, $max]) {
            $rules["general.$key"] = "required|integer|min:$min|max:$max";
        }
        foreach (Sources::keys() as $k) {
            $rules["sources.$k.keep_days"] = 'required|integer|min:' . WimsLogSettings::KEEP_MIN . '|max:' . WimsLogSettings::KEEP_MAX;
            $rules["sources.$k.on"] = 'required|boolean';
        }
        $data = $request->validate($rules);
        $before = WimsLogSettings::all();
        $saved = WimsLogSettings::save($data['general'], $data['sources'], (int) $request->user()->account_id);
        log_activity(__('WIMS Logs settings saved'), ['from' => $before, 'to' => $saved], $request->user(), 'Setting');

        return back()->with('message', __('WIMS Logs settings saved.'));
    }

    /** Starts a run. It moves data out of the live tables, so the person's own password is asked. The screen then asks for each log in turn (step) and closes the run (finish). */
    public function run(Request $request)
    {
        $this->allow($request, 'run-wims-logs');
        $data = $request->validate([
            'sources' => 'required|array|min:1', 'sources.*' => 'string|in:' . implode(',', Sources::keys()), 'password' => 'required|string',
            'keep_days' => 'nullable|array', 'keep_days.*' => 'integer|min:' . WimsLogSettings::KEEP_MIN . '|max:' . WimsLogSettings::KEEP_MAX,
        ]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
        try {
            $run = Archiver::begin('manual', $request->user(), $data['sources'], array_intersect_key((array) ($data['keep_days'] ?? []), array_flip($data['sources'])));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['sources' => $e->getMessage()]);
        }
        $labels = collect(Sources::all())->map(fn ($s) => $s['label']);

        return response()->json(['id' => $run->id, 'ref' => $run->ref(), 'steps' => collect((array) $run->sources)->map(fn ($k) => ['key' => $k, 'title' => $labels[$k]])->push(['key' => 'optimize', 'title' => __('Compact the database tables')])->values()->all()]);
    }

    /** One log of a running run (a log that reached its time limit answers `more`: the screen asks again), or the compacting of the tables (`optimize`). */
    public function step(Request $request, int $run)
    {
        $this->allow($request, 'run-wims-logs');
        $data = $request->validate(['source' => 'required|string|in:' . implode(',', array_merge(Sources::keys(), ['optimize']))]);
        $record = WimsLogRun::query()->where('user_id', $request->user()->id)->findOrFail($run);
        @set_time_limit(0);

        if ($data['source'] === 'optimize') {
            $r = Archiver::optimizeStep($record);

            return response()->json(['ok' => $r['ok'], 'more' => $r['more'], 'summary' => $r['table'] ? sprintf(__('Table %s compacted: %s > %s'), $r['table'], Runner::bytes($r['before']), Runner::bytes($r['after'])) : __('Nothing to compact.')]);
        }
        $r = Archiver::step($record, $data['source']);

        return response()->json(['ok' => $r['ok'], 'more' => $r['more'], 'rows' => $r['rows'],
            'summary' => $r['ok'] ? sprintf(__('%s records moved (%s > %s)'), number_format($r['rows']), Runner::bytes($r['original']), Runner::bytes($r['compressed'])) : (string) $r['error']]);
    }

    public function finish(Request $request, int $run)
    {
        $this->allow($request, 'run-wims-logs');
        $record = WimsLogRun::query()->where('user_id', $request->user()->id)->findOrFail($run);
        $done = $record->status === 'running' ? Archiver::finish($record, $request->user()) : $record;

        return response()->json(['id' => $done->id, 'status' => $done->status, 'rows' => (int) $done->rows_archived, 'saved_text' => Runner::bytes(max(0, (int) $done->original_bytes - (int) $done->stored_bytes)), 'freed_text' => Runner::bytes(max(0, (int) $done->db_freed_bytes)), 'url' => route('wims-logs.show', $done->id)]);
    }

    public function show(Request $request, WimsLogRun $run)
    {
        $this->allow($request, 'read-wims-logs');

        return Inertia::render('Settings/WimsLogRun', ['run' => $this->runDetail($run)]);
    }

    /**
     * Every archived record of one log, newest archive first, a page at a time, searchable: the place to read what was moved out of the live database without knowing which run or
     * archive it is in.
     */
    public function browse(Request $request)
    {
        $this->allow($request, 'read-wims-logs');
        $errors = [];
        try {
            return $this->browsePage($request);
        } catch (\Throwable $e) {
            // the page still opens, and says what failed, instead of a blank 500
            report($e);
            $errors[] = ['part' => 'Archived records', 'error' => class_basename($e) . ': ' . \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 400), 'where' => basename($e->getFile()) . ':' . $e->getLine()];
            $logs = collect(Sources::all())->map(fn ($src, $k) => ['key' => $k, 'label' => $src['label'], 'rows' => 0, 'archives' => 0, 'original' => 0, 'stored' => 0, 'date_from' => null, 'date_to' => null])->values()->all();

            return Inertia::render('Settings/WimsLogBrowse', ['logs' => $logs, 'source' => (string) ($logs[0]['key'] ?? ''), 'records' => [], 'total' => 0, 'page' => 1, 'per' => 50, 'pages' => 1, 'q' => '', 'users' => [], 'user' => null, 'canRun' => false, 'loadErrors' => $errors]);
        }
    }

    private function browsePage(Request $request)
    {
        $keys = array_keys(Sources::all());
        $counts = WimsLogArchive::query()->selectRaw('source, count(*) as archives, sum(rows_n) as rows_n, sum(original_bytes) as original, sum(stored_bytes) as `stored`, min(date_from) as date_from, max(date_to) as date_to')->groupBy('source')->get()->keyBy('source');
        $logs = collect(Sources::all())->map(fn ($src, $k) => [
            'key' => $k, 'label' => $src['label'], 'rows' => (int) ($counts->get($k)->rows_n ?? 0), 'archives' => (int) ($counts->get($k)->archives ?? 0),
            'original' => (int) ($counts->get($k)->original ?? 0), 'stored' => (int) ($counts->get($k)->stored ?? 0),
            'date_from' => $counts->get($k)->date_from ?? null, 'date_to' => $counts->get($k)->date_to ?? null,
        ])->values()->all();

        $source = (string) $request->query('source', '');
        if (! in_array($source, $keys, true)) {
            $source = collect($logs)->firstWhere(fn ($l) => $l['rows'] > 0)['key'] ?? ($keys[0] ?? '');
        }
        $per = 50;
        $page = max(1, (int) $request->query('page', 1));
        $q = trim((string) $request->query('q', ''));
        $this->ensureUsersIndexed($source);
        $users = $this->userOptions(WimsLogArchive::query()->where('source', $source)->get());
        $user = $request->filled('user') && ctype_digit((string) $request->query('user')) ? (int) $request->query('user') : null;
        $res = $this->gather($source, $q, ($page - 1) * $per, $per, $user);
        $rows = $this->decorate($res['rows'], $source);
        $total = $res['total'];

        return Inertia::render('Settings/WimsLogBrowse', [
            'logs' => $logs, 'source' => $source, 'records' => $rows,
            'total' => $total, 'page' => $page, 'per' => $per, 'pages' => max(1, (int) ceil($total / $per)), 'q' => $q,
            'users' => $users, 'user' => $user, 'canRun' => (bool) $request->user()->can('run-wims-logs'),
        ]);
    }

    /**
     * `$limit` archived records of one log starting at `$offset` (newest archive first, in the order they were written inside an archive), only those matching `$q`, and how many match in all.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    private function gather(string $source, string $q, int $offset, int $limit, ?int $user = null): array
    {
        $need = $limit;
        $rows = [];
        $total = 0;
        foreach (WimsLogArchive::query()->where('source', $source)->orderByDesc('id')->get() as $a) {
            if (! ArchiveStore::exists((string) $a->path)) {
                continue;
            }
            $n = (int) $a->rows_n;
            if ($q === '' && $user === null) {
                $total += $n;
                if ($need <= 0 || $offset >= $n) {
                    $offset = max(0, $offset - $n);
                    continue;
                }
                $res = ArchiveStore::slice((string) $a->path, $offset, $need, null, $n);
            } else {
                $res = ArchiveStore::slice((string) $a->path, $offset, max($need, 0), $q, null, $user);
                $total += $res['total'];
                if ($offset >= $res['total']) {
                    $offset -= $res['total'];
                    continue;
                }
            }
            foreach ($res['rows'] as $e) {
                $rows[] = ['archive' => $a->id, 'ref' => $a->ref(), 'id' => $e['i'], 'at' => $e['t'] ?? null, 'summary' => $e['s'] ?? '', 'original' => (int) $e['o'], 'compressed' => (int) $e['c'], 'user_id' => (int) ($e['u'] ?? 0) ?: null];
            }
            $need -= count($res['rows']);
            $offset = 0;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** The log, the search and the totals the Archived records report is made from. */
    private function browseScope(Request $request): array
    {
        $source = (string) $request->query('source', '');
        abort_unless(Sources::get($source) !== null, 404);
        $q = trim((string) $request->query('q', ''));
        $agg = WimsLogArchive::query()->where('source', $source)->selectRaw('count(*) as archives, sum(rows_n) as rows_n, sum(original_bytes) as original, sum(compressed_bytes) as compressed, sum(stored_bytes) as `stored`, min(date_from) as date_from, max(date_to) as date_to')->first();

        return ['source' => $source, 'q' => $q, 'label' => Sources::get($source)['label'], 'agg' => $agg];
    }

    public function browsePrint(Request $request)
    {
        $this->allow($request, 'read-wims-logs');

        return view('print.document', $this->browseDocument($request, false, 'printed'));
    }

    public function browsePdf(Request $request)
    {
        $this->allow($request, 'read-wims-logs');
        $scope = $this->browseScope($request);

        return Pdf::loadView('print.document', $this->browseDocument($request, true, 'downloaded as PDF'))->setPaper('a4', 'landscape')->download('wims-logs-records-' . $scope['source'] . '.pdf');
    }

    /** Every record that matches, as a CSV (opens in Excel): what the page lists, without the 1,000-line limit of the printed report. */
    public function browseCsv(Request $request): StreamedResponse
    {
        $this->allow($request, 'read-wims-logs');
        $scope = $this->browseScope($request);
        log_activity('WIMS Logs records ' . $scope['source'] . ' downloaded as CSV', ['source' => $scope['source'], 'search' => $scope['q']], $request->user(), 'Setting');

        return response()->streamDownload(function () use ($scope) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['No', 'Date', 'Record', 'What it was', 'Archive', 'Size before (bytes)', 'Compressed (bytes)']);
            $n = 0;
            $offset = 0;
            do {
                $res = $this->gather($scope['source'], $scope['q'], $offset, 500);
                foreach ($res['rows'] as $r) {
                    fputcsv($out, [++$n, $r['at'], $r['id'], $r['summary'], $r['ref'], $r['original'], $r['compressed']]);
                }
                $offset += 500;
            } while (count($res['rows']) === 500 && $n < 200000);
            fclose($out);
        }, 'wims-logs-records-' . $scope['source'] . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function browseDocument(Request $request, bool $isPdf, string $what): array
    {
        $scope = $this->browseScope($request);
        $res = $this->gather($scope['source'], $scope['q'], 0, PrintDocuments::WIMS_LOG_RECORDS_LIMIT);
        log_activity('WIMS Logs records ' . $scope['source'] . ' ' . $what, ['source' => $scope['source'], 'search' => $scope['q'], 'rows' => count($res['rows'])], $request->user(), 'Setting');
        $query = array_filter(['source' => $scope['source'], 'q' => $scope['q'] ?: null]);

        return [
            'doc' => PrintDocuments::wimsLogRecords($scope['source'], $scope['label'], $scope['q'], $scope['agg'], $res['rows'], $res['total'], $request->user(), $isPdf ? __('Downloaded as PDF') : __('Printed')),
            'isPdf' => $isPdf, 'companyName' => optional($request->user()->account)->name ?? config('app.name'),
            'qr' => PrintQrCode::dataUri(route('wims-logs.browse', $query)), 'pdfUrl' => route('wims-logs.browse.pdf', $query),
        ];
    }

    /** The records of one archive, a page at a time, searchable. */
    public function archive(Request $request, WimsLogArchive $archive)
    {
        $this->allow($request, 'read-wims-logs');
        abort_unless(ArchiveStore::exists((string) $archive->path), 404);
        $per = 50;
        $page = max(1, (int) $request->query('page', 1));
        $q = trim((string) $request->query('q', ''));
        $this->ensureUsersIndexed($archive->source);
        $archive->refresh();
        $users = $this->userOptions(collect([$archive]));
        $user = $request->filled('user') && ctype_digit((string) $request->query('user')) ? (int) $request->query('user') : null;
        $res = ArchiveStore::page((string) $archive->path, $page, $per, $q, (int) $archive->rows_n, $user);
        $rows = $this->decorate(collect($res['rows'])->map(fn ($e) => ['archive' => $archive->id, 'ref' => $archive->ref(), 'id' => $e['i'], 'at' => $e['t'] ?? null, 'summary' => $e['s'] ?? '', 'original' => (int) $e['o'], 'compressed' => (int) $e['c'], 'user_id' => (int) ($e['u'] ?? 0) ?: null])->all(), $archive->source);

        return Inertia::render('Settings/WimsLogArchive', [
            'archive' => $this->archiveRow($archive) + ['run_ref' => optional($archive->run)->ref(), 'sha256' => $archive->sha256, 'skipped' => (int) $archive->skipped_n, 'restored' => (int) $archive->restored_n, 'error' => $archive->error],
            'records' => $rows,
            'total' => (int) $res['total'], 'page' => $page, 'per' => $per, 'pages' => max(1, (int) ceil($res['total'] / $per)), 'q' => $q,
            'users' => $users, 'user' => $user, 'canRun' => (bool) $request->user()->can('run-wims-logs'),
        ]);
    }

    /** Puts records of an archive back into their own live log: the ones named, or the whole archive. Needs the person's password. */
    public function restore(Request $request, WimsLogArchive $archive)
    {
        $this->allow($request, 'run-wims-logs');
        $data = $request->validate(['password' => 'required|string', 'all' => 'nullable|boolean', 'ids' => 'nullable|array|max:2000', 'ids.*' => 'string|max:64']);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
        abort_unless(ArchiveStore::exists((string) $archive->path), 404);
        if (empty($data['all']) && empty($data['ids'])) {
            throw ValidationException::withMessages(['ids' => __('Choose the records to put back.')]);
        }
        @set_time_limit(0);
        $r = Restorer::restore($archive, ! empty($data['all']) ? null : array_values($data['ids']), $request->user());

        return response()->json($r + ['restored_total' => (int) $archive->fresh()->restored_n]);
    }

    /** Names for the user ids of a page of records, and whether each record was put back. */
    private function decorate(array $rows, string $source): array
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
        $names = $ids ? User::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id') : collect();
        $put = [];
        if ($rows && Tables::has('wims_log_restores')) {
            foreach (DB::table('wims_log_restores')->where('source', $source)->whereIn('record_key', array_map(fn ($r) => (string) $r['id'], $rows))->get(['archive_id', 'record_key']) as $x) {
                $put[$x->archive_id . ':' . $x->record_key] = true;
            }
        }

        return array_map(fn ($r) => $r + ['user' => $r['user_id'] ? ($names[$r['user_id']] ?? '#' . $r['user_id']) : null, 'restored' => isset($put[$r['archive'] . ':' . $r['id']])], $rows);
    }

    private function isRestored(WimsLogArchive $archive, string $id): bool
    {
        return Tables::has('wims_log_restores') && DB::table('wims_log_restores')->where('archive_id', $archive->id)->where('record_key', $id)->exists();
    }

    /** The person a record belongs to: name and role (or the name stored in the record when the user no longer exists). */
    private function userInfo(?int $uid, array $row): ?array
    {
        $u = $uid ? User::withoutGlobalScopes()->withTrashed()->with('roles:id,name')->find($uid) : null;
        if ($u) {
            return ['id' => $u->id, 'name' => $u->name, 'role' => optional($u->roles->first())->name];
        }
        if (! empty($row['user_name'])) {
            return ['id' => $uid, 'name' => (string) $row['user_name'], 'role' => $row['role'] ?? null];
        }

        return $uid ? ['id' => $uid, 'name' => '#' . $uid, 'role' => null] : null;
    }

    /** The people who have records in these archives, for the user filter ("no user" first when some records belong to nobody). */
    private function userOptions($archives): array
    {
        $count = [];
        foreach ($archives as $a) {
            if (! ArchiveStore::exists((string) $a->path)) {
                continue;
            }
            $key = 'wimslogs:users:' . $a->id . ':' . (int) @filemtime(ArchiveStore::idxGz((string) $a->path));
            foreach (Cache::remember($key, 3600, fn () => ArchiveStore::userCounts((string) $a->path)) as $u => $n) {
                $count[$u] = ($count[$u] ?? 0) + $n;
            }
        }
        $names = User::withoutGlobalScopes()->withTrashed()->whereIn('id', array_filter(array_keys($count)))->pluck('name', 'id');
        $out = [];
        foreach ($count as $u => $n) {
            if ($u) {
                $out[] = ['id' => (int) $u, 'name' => $names[$u] ?? '#' . $u, 'n' => $n];
            }
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        if (! empty($count[0]) && $out) {
            array_unshift($out, ['id' => 0, 'name' => __('No user'), 'n' => $count[0]]);
        }

        return $out;
    }

    /** Archives made before the index carried the user of each record get it now (the records are only read, never changed). Time-boxed: the rest follows on the next visit. */
    private function ensureUsersIndexed(string $source): void
    {
        try {
            $src = Sources::get($source);
            $start = microtime(true);
            foreach (WimsLogArchive::query()->where('source', $source)->where('closed', true)->where('users_indexed', false)->get() as $a) {
                if (empty($src['user'])) {
                    $a->update(['users_indexed' => true]);
                    continue;
                }
                if (microtime(true) - $start > 20 || ! ArchiveStore::exists((string) $a->path)) {
                    continue;
                }
                ArchiveStore::reindexUsers((string) $a->path, $src['user']);
                $a->update(['users_indexed' => true]);
            }
        } catch (\Throwable $e) {
            // the filter simply shows what it can until the migration has run
        }
    }

    /** One record, whole: every column as it was in the database. */
    public function record(Request $request, WimsLogArchive $archive, string $id)
    {
        $this->allow($request, 'read-wims-logs');
        $row = ArchiveStore::record((string) $archive->path, $id);
        abort_unless($row !== null, 404);
        log_activity(__('Archived record read'), ['archive' => $archive->ref(), 'log' => $archive->source, 'record' => $id], $request->user(), 'Setting');

        $src = Sources::get($archive->source);
        $uid = $src && ! empty($src['user']) ? ($src['user'])($row) : null;

        return response()->json([
            'id' => $id, 'row' => $row, 'user' => $this->userInfo($uid, $row),
            'restored' => $this->isRestored($archive, $id), 'can_restore' => (bool) $request->user()->can('run-wims-logs'),
        ]);
    }

    /** Every record of an archive as readable lines of JSON in one compressed file (the route name has "download" in it, so every use is in the Download / Print log). */
    public function download(Request $request, WimsLogArchive $archive): StreamedResponse
    {
        $this->allow($request, 'read-wims-logs');
        abort_unless(ArchiveStore::exists((string) $archive->path), 404);
        log_activity(__('Archive downloaded'), ['archive' => $archive->ref(), 'log' => $archive->source, 'rows' => (int) $archive->rows_n], $request->user(), 'Setting');
        $name = 'wims-logs-' . $archive->source . '-' . $archive->ref() . '.jsonl.gz';

        return response()->streamDownload(function () use ($archive) {
            $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            foreach (ArchiveStore::export((string) $archive->path) as $line) {
                echo deflate_add($ctx, $line, ZLIB_NO_FLUSH);
            }
            echo deflate_add($ctx, '', ZLIB_FINISH);
        }, $name, ['Content-Type' => 'application/gzip']);
    }

    public function print(Request $request, int $run)
    {
        $this->allow($request, 'read-wims-logs');

        return view('print.document', $this->documentData($request, WimsLogRun::query()->findOrFail($run), false, 'printed'));
    }

    public function pdf(Request $request, int $run)
    {
        $this->allow($request, 'read-wims-logs');
        $record = WimsLogRun::query()->findOrFail($run);

        return Pdf::loadView('print.document', $this->documentData($request, $record, true, 'downloaded as PDF'))->setPaper('a4', 'landscape')->download('wims-logs-' . $record->ref() . '.pdf');
    }

    private function printRecord(WimsLogRun $run): array
    {
        return DownloadPrintLog::query()->whereIn('route_name', ['wims-logs.print', 'wims-logs.pdf'])->where('url', 'like', '%/settings/wims-logs/runs/' . (int) $run->id . '/%')
            ->orderBy('performed_at')->limit(100)->get(['user_name', 'role', 'action_type', 'performed_at'])
            ->map(fn ($l) => ['who' => $l->user_name, 'role' => $l->role, 'action' => $l->action_type === 'print' ? __('Printed') : __('Downloaded as PDF'), 'at' => optional($l->performed_at)->toDateTimeString()])->all();
    }

    private function documentData(Request $request, WimsLogRun $run, bool $isPdf, string $what): array
    {
        $earlier = $this->printRecord($run);
        log_activity('WIMS Logs report ' . $run->ref() . ' ' . $what, ['run' => $run->id], $request->user(), 'Setting');

        return [
            'doc' => PrintDocuments::wimsLogRun($run, $request->user(), $earlier, $isPdf ? __('Downloaded as PDF') : __('Printed')), 'isPdf' => $isPdf, 'companyName' => optional($request->user()->account)->name ?? config('app.name'),
            'qr' => PrintQrCode::dataUri(route('wims-logs.show', $run->id)), 'pdfUrl' => route('wims-logs.pdf', $run->id),
        ];
    }

    private function runRow(WimsLogRun $r): array
    {
        $sb = (float) $r->speed_before_ms;
        $sa = (float) $r->speed_after_ms;

        return [
            'id' => $r->id, 'ref' => $r->ref(), 'user' => $r->user_name, 'trigger' => $r->trigger, 'status' => $r->status, 'sources' => (array) $r->sources,
            'rows' => (int) $r->rows_archived, 'original' => (int) $r->original_bytes, 'stored' => (int) $r->stored_bytes, 'saved' => max(0, (int) $r->original_bytes - (int) $r->stored_bytes),
            'freed' => max(0, (int) $r->db_freed_bytes), 'seconds' => (int) $r->seconds,
            'speed_before' => $sb, 'speed_after' => $sa, 'speed_pct' => $sb > 0 ? round((($sb - $sa) / $sb) * 100, 1) : null,
            'at' => optional($r->started_at)->toDateTimeString(),
        ];
    }

    private function archiveRow(WimsLogArchive $a): array
    {
        return [
            'id' => $a->id, 'ref' => $a->ref(), 'run_id' => $a->run_id, 'source' => $a->source, 'label' => Sources::get($a->source)['label'] ?? $a->source, 'table' => $a->table_name,
            'rows' => (int) $a->rows_n, 'original' => (int) $a->original_bytes, 'compressed' => (int) $a->compressed_bytes, 'stored' => (int) $a->stored_bytes,
            'saved' => max(0, (int) $a->original_bytes - (int) $a->stored_bytes), 'saved_pct' => $a->original_bytes > 0 ? round((1 - $a->stored_bytes / $a->original_bytes) * 100) : null,
            'date_from' => optional($a->date_from)->toDateTimeString(), 'date_to' => optional($a->date_to)->toDateTimeString(), 'verified' => (bool) $a->verified, 'closed' => (bool) $a->closed,
            'available' => ArchiveStore::exists((string) $a->path),
        ];
    }

    private function runDetail(WimsLogRun $r): array
    {
        $b = (array) ($r->before['db'] ?? []);
        $a = (array) ($r->after['db'] ?? []);
        $optimized = (array) ($r->options['optimized'] ?? []);

        return $this->runRow($r) + [
            'archives' => $r->archives()->orderBy('id')->get()->map(fn ($x) => $this->archiveRow($x) + ['error' => $x->error, 'skipped' => (int) $x->skipped_n])->all(),
            'db' => ['before' => (int) ($b['bytes'] ?? 0) + (int) ($b['free'] ?? 0), 'after' => (int) ($a['bytes'] ?? 0) + (int) ($a['free'] ?? 0)],
            'optimized' => collect($optimized)->map(fn ($v, $t) => ['table' => $t, 'ok' => (bool) $v['ok'], 'before' => (int) $v['before'], 'after' => (int) $v['after']])->values()->all(),
            'keep_days' => (array) ($r->options['keep_days'] ?? []),
            'speed' => ['before' => (array) $r->speed_before, 'after' => (array) $r->speed_after],
            'started' => optional($r->started_at)->toDateTimeString(), 'finished' => optional($r->finished_at)->toDateTimeString(), 'error' => $r->error,
            'printed' => $this->printRecord($r), 'labels' => collect(Sources::all())->map(fn ($s) => $s['label'])->all(),
        ];
    }
}
