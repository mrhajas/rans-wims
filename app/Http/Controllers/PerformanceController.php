<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Performance\ActivityArchiver;
use App\Support\Performance\ArchiveExport;
use App\Support\Performance\Housekeeping;
use App\Support\Performance\PerfSettings;
use App\Support\Performance\QueueMode;
use App\Support\Performance\Tables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings > Performance & Data (Super Admin only): how long things are kept, the activity archive (browse and download), what was slow, and sending in the background.
 * Every limit is a setting (App\Support\Performance\PerfSettings).
 */
class PerformanceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function superOnly(Request $request): void
    {
        abort_unless($request->user() && $request->user()->hasRole('Super Admin'), 403);
    }

    public function index(Request $request)
    {
        $this->superOnly($request);

        return Inertia::render('Settings/Performance', [
            'perf' => PerfSettings::all(), // not "settings": that name is the app-wide shared prop the layout and menu read
            'limits' => PerfSettings::LIMITS,
            'defaults' => PerfSettings::DEFAULTS,
            'stats' => $this->stats(),
            'queue' => $this->queueStatus(),
            'slow' => $this->slowSummary(),
            'last' => Housekeeping::last(),
            'exports' => $this->exportList($request),
            'logNames' => \App\Support\Performance\Tables::has('activity_log_archive') ? DB::table('activity_log_archive')->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name') : [],
        ]);
    }

    public function update(Request $request)
    {
        $this->superOnly($request);
        $rules = [];
        foreach (PerfSettings::LIMITS as $key => [$min, $max]) {
            $rules[$key] = "required|integer|min:$min|max:$max";
        }
        $data = $request->validate($rules);
        $before = PerfSettings::all();
        $saved = PerfSettings::save($data, (int) $request->user()->account_id);
        log_activity(__('Performance & Data settings saved'), ['from' => $before, 'to' => $saved], $request->user(), 'Setting');

        return back()->with('message', __('Performance & Data settings saved.'));
    }

    /** Runs the daily clean-up now. It moves history to the archive and removes old records, so the person's password is asked. */
    public function housekeeping(Request $request)
    {
        $this->superOnly($request);
        $this->confirmPassword($request);
        $r = Housekeeping::run();
        log_activity(__('Clean-up run by hand'), $r, $request->user(), 'Setting');

        return back()->with('message', __(':n activity record(s) moved to the archive.', ['n' => number_format($r['activity_archived'])]));
    }

    // ---- the activity archive: browse and download
    public function archive(Request $request)
    {
        $this->superOnly($request);
        $q = $this->archiveQuery($request);
        $page = $q->orderByDesc('id')->paginate(25)->withQueryString();
        $names = User::withoutGlobalScopes()->whereIn('id', collect($page->items())->pluck('causer_id')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => [
                'id' => $r->id, 'at' => $r->created_at, 'log' => $r->log_name, 'description' => $r->description, 'event' => $r->event,
                'by' => $r->causer_id ? ($names[$r->causer_id] ?? '#' . $r->causer_id) : null,
                'subject' => $r->subject_type ? class_basename($r->subject_type) . ($r->subject_id ? ' #' . $r->subject_id : '') : null,
            ])->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ]);
    }

    /** The archive as a CSV file (opens in Excel), with the same filters as the list. The route name has "download" in it, so every use is in the Download / Print log. */
    public function archiveDownload(Request $request): StreamedResponse
    {
        $this->superOnly($request);
        abort_unless(\App\Support\Performance\Tables::has('activity_log_archive'), 404);
        $q = $this->archiveQuery($request);
        log_activity(__('Activity archive downloaded'), $request->only(['from', 'to', 'log', 'search']) + ['rows' => (clone $q)->count()], $request->user(), 'Setting');
        $file = 'activity-archive-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the letters correctly
            fputcsv($out, ['ID', 'Date', 'Log', 'Description', 'Event', 'Subject type', 'Subject ID', 'Done by', 'Done by ID', 'Details', 'Archived on']);
            $q->orderBy('id')->chunkById(2000, function ($rows) use ($out) {
                $names = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('causer_id')->filter()->unique())->pluck('name', 'id');
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->id, $r->created_at, $r->log_name, $r->description, $r->event, $r->subject_type, $r->subject_id,
                        $r->causer_id ? ($names[$r->causer_id] ?? '') : '', $r->causer_id, $r->properties, $r->archived_at,
                    ]);
                }
                flush();
            });
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The filters of the archive screen (from / to / log / search) as the rows they select. */
    private function archiveFilters(Request $request): array
    {
        return ArchiveExport::clean($request->only(['from', 'to', 'log', 'search']));
    }

    private function archiveQuery(Request $request)
    {
        abort_unless(Tables::has('activity_log_archive'), 404);

        return ArchiveExport::query($this->archiveFilters($request));
    }

    /** What a PDF of the current filters would be: made at once, or in the background in parts. */
    public function archivePlan(Request $request)
    {
        $this->superOnly($request);
        $total = (int) $this->archiveQuery($request)->count();
        $parts = (int) max(1, ceil($total / ArchiveExport::PART));

        return response()->json(['total' => $total, 'parts' => $parts, 'now' => $total <= ArchiveExport::SYNC_MAX, 'minutes' => max(1, (int) ceil($parts / 15)), 'period' => ArchiveExport::period($this->archiveFilters($request))]);
    }

    /** A PDF made at once (the selection is small enough for one file). Logged: the route name has "pdf" in it. */
    public function archivePdf(Request $request)
    {
        $this->superOnly($request);
        $f = $this->archiveFilters($request);
        $total = (int) ArchiveExport::query($f)->count();
        if ($total === 0) {
            return back()->with('error', __('No archived record matches these filters.'));
        }
        if ($total > ArchiveExport::SYNC_MAX) {
            return back()->with('error', __('That is too many records for one file. Use "Prepare PDF" to have it made in the background.'));
        }
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);
        [$pdf] = ArchiveExport::part($f, 0, ArchiveExport::SYNC_MAX, 1, 1, $total, 1, $request->user());
        log_activity(__('Activity archive downloaded as PDF'), $f + ['rows' => $total], $request->user(), 'Setting');

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="activity-archive-' . now()->format('Ymd-His') . '.pdf"']);
    }

    /** Asks for a large PDF: the scheduler makes it in parts and hands it over as one ZIP. */
    public function archiveExportStart(Request $request)
    {
        $this->superOnly($request);
        $f = $this->archiveFilters($request);
        $total = (int) ArchiveExport::query($f)->count();
        if ($total === 0) {
            return back()->with('error', __('No archived record matches these filters.'));
        }
        $parts = (int) max(1, ceil($total / ArchiveExport::PART));
        $id = DB::table('activity_archive_exports')->insertGetId([
            'user_id' => $request->user()->id, 'filters' => json_encode($f), 'total_rows' => $total, 'parts_total' => $parts, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        log_activity(__('Activity archive PDF requested'), $f + ['rows' => $total, 'parts' => $parts, 'export' => $id], $request->user(), 'Setting');

        return back()->with('message', __('Your PDF is being prepared: :n records in :p files. You can leave this page; it is ready in about :m minute(s) and appears under "My PDF exports".', ['n' => number_format($total), 'p' => $parts, 'm' => max(1, (int) ceil($parts / 15))]));
    }

    public function archiveExports(Request $request)
    {
        $this->superOnly($request);

        return response()->json($this->exportList($request));
    }

    public function archiveExportDownload(Request $request, int $export)
    {
        $this->superOnly($request);
        $e = DB::table('activity_archive_exports')->where('id', $export)->where('user_id', $request->user()->id)->first();
        abort_unless($e && $e->status === 'done' && $e->file && \Illuminate\Support\Facades\Storage::disk('local')->exists($e->file), 404);
        log_activity(__('Activity archive PDF export downloaded'), ['export' => $e->id, 'rows' => $e->total_rows, 'parts' => $e->parts_total], $request->user(), 'Setting');

        return \Illuminate\Support\Facades\Storage::disk('local')->download($e->file, 'activity-archive-' . $e->id . '.zip');
    }

    public function archiveExportDelete(Request $request, int $export)
    {
        $this->superOnly($request);
        $e = DB::table('activity_archive_exports')->where('id', $export)->where('user_id', $request->user()->id)->first();
        abort_unless($e, 404);
        \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory(ArchiveExport::dir($e->id));
        DB::table('activity_archive_exports')->where('id', $e->id)->delete();

        return back()->with('message', __('The export was removed.'));
    }

    /**
     * Clears (permanently deletes) the archived history that the current filters select; no filter = the whole archive. Needs the administrator's own password.
     * The count that was shown on the screen must still be the count now, so nothing more than what the person saw is removed.
     */
    public function archiveClear(Request $request)
    {
        $this->superOnly($request);
        $this->confirmPassword($request);
        $f = $this->archiveFilters($request);
        $q = ArchiveExport::query($f);
        $n = (int) (clone $q)->count();
        if ($n === 0) {
            return back()->with('error', __('No archived record matches these filters.'));
        }
        if ($request->filled('expected') && (int) $request->input('expected') !== $n) {
            return back()->with('error', __('The archive changed while you were confirming (:a now, :b shown). Nothing was cleared; check the number and try again.', ['a' => number_format($n), 'b' => number_format((int) $request->input('expected'))]));
        }
        $deleted = 0;
        do {
            $ids = (clone $q)->orderBy('id')->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('activity_log_archive')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);
        log_activity(__('Activity archive cleared'), $f + ['rows' => $deleted, 'scope' => $f ? 'filtered' : 'whole archive'], $request->user(), 'Setting');

        return back()->with('message', __(':n archived record(s) were cleared.', ['n' => number_format($deleted)]));
    }

    private function exportList(Request $request): array
    {
        if (! Tables::has('activity_archive_exports')) {
            return [];
        }

        return DB::table('activity_archive_exports')->where('user_id', $request->user()->id)->orderByDesc('id')->limit(8)->get()->map(function ($e) {
            $f = json_decode($e->filters ?? '[]', true) ?: [];

            return ['id' => $e->id, 'status' => $e->status, 'rows' => $e->total_rows, 'parts' => $e->parts_total, 'done' => $e->parts_done, 'period' => ArchiveExport::period($f), 'log' => $f['log'] ?? null, 'search' => $f['search'] ?? null, 'error' => $e->error, 'at' => $e->created_at, 'finished' => $e->finished_at];
        })->all();
    }

    // ---- slow queries and pages
    public function clearSlow(Request $request)
    {
        $this->superOnly($request);
        $n = \App\Support\Performance\Tables::has('slow_logs') ? DB::table('slow_logs')->delete() : 0;
        log_activity(__('Slow query records cleared'), ['rows' => $n], $request->user(), 'Setting');

        return back()->with('message', __('Slow query records cleared.'));
    }

    // ---- sending in the background
    public function queueRun(Request $request)
    {
        $this->superOnly($request);
        // sends what is waiting right now (for at most 20 seconds), without waiting for the worker
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 20, '--tries' => 3, '--timeout' => 20]);

        return back()->with('message', __('Waiting messages were sent.'));
    }

    public function queueRetry(Request $request)
    {
        $this->superOnly($request);
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('message', __('Failed messages were put back in the line.'));
    }

    public function queueFlush(Request $request)
    {
        $this->superOnly($request);
        $this->confirmPassword($request);
        Artisan::call('queue:flush');
        log_activity(__('Failed background messages cleared'), [], $request->user(), 'Setting');

        return back()->with('message', __('Failed messages were cleared.'));
    }

    // ---- helpers
    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => 'required|string']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
    }

    private function stats(): array
    {
        $has = \App\Support\Performance\Tables::has('activity_log_archive');

        return [
            'live' => (int) DB::table('activity_log')->count(),
            'archive' => $has ? (int) DB::table('activity_log_archive')->count() : 0,
            'oldest_live' => DB::table('activity_log')->min('created_at'),
            'oldest_archive' => $has ? DB::table('activity_log_archive')->min('created_at') : null,
            'due' => ActivityArchiver::due(),
            'cutoff' => optional(ActivityArchiver::cutoff())->toDateString(),
            'slow_week' => \App\Support\Performance\Tables::has('slow_logs') ? (int) DB::table('slow_logs')->where('created_at', '>=', now()->subDays(7))->count() : 0,
        ];
    }

    private function queueStatus(): array
    {
        $beat = QueueMode::lastBeat();
        $failed = \App\Support\Performance\Tables::has('failed_jobs') ? DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get(['id', 'queue', 'exception', 'failed_at']) : collect();

        return [
            'wanted' => PerfSettings::get('perf_queue_background') === 1,
            'alive' => QueueMode::alive(),
            'beat_ago' => $beat ? time() - $beat : null,
            'active' => PerfSettings::get('perf_queue_background') === 1 && QueueMode::alive(),
            'pending' => \App\Support\Performance\Tables::has('jobs') ? (int) DB::table('jobs')->count() : 0,
            'failed' => \App\Support\Performance\Tables::has('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0,
            'failed_list' => $failed->map(fn ($f) => ['id' => $f->id, 'queue' => $f->queue, 'at' => $f->failed_at, 'error' => \Illuminate\Support\Str::limit(strtok((string) $f->exception, "\n"), 200)])->values(),
        ];
    }

    private function slowSummary(): array
    {
        if (! \App\Support\Performance\Tables::has('slow_logs')) {
            return [];
        }

        return DB::table('slow_logs')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('fingerprint, kind, MAX(label) as label, COUNT(*) as n, ROUND(AVG(duration_ms)) as avg_ms, MAX(duration_ms) as max_ms, MAX(created_at) as last_at, MAX(path) as path, ROUND(AVG(queries)) as queries')
            ->groupBy('fingerprint', 'kind')->orderByDesc('max_ms')->limit(20)->get()
            ->map(fn ($r) => (array) $r)->all();
    }
}
