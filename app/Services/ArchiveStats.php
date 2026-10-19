<?php

namespace App\Services;

use App\Models\ArchiveFile;
use App\Models\ArchiveRecord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Dashboard figures. Everything is computed from database metadata with four aggregate queries (no file is ever
 * opened, no folder scanned) and cached for a few minutes per filter set / permission level. Any change to a
 * record or file calls flush(), which bumps the cache version so the next load recomputes.
 *
 * "Storage Used" = what the files actually occupy: the optimised copy when one exists (never original + optimised),
 * plus thumbnails. "Original" = what was uploaded. "Saved" = original − optimised, and is 0 until files are optimised.
 */
class ArchiveStats
{
    private const TTL = 300;

    public const FILTERS = ['date_from', 'date_to', 'category', 'source_type', 'warehouse_id', 'department', 'file_type'];

    /**
     * Mark the cached figures stale. Throttled to once every 15 seconds so a burst of uploads does not make every dashboard
     * visit recompute the aggregates; at worst the dashboard is seconds behind (and never more than the cache TTL).
     */
    public static function flush(): void
    {
        if (Cache::add('archive_stats_flush_lock', 1, 15)) {
            Cache::forever('archive_stats_version', (int) Cache::get('archive_stats_version', 1) + 1);
        }
    }

    public static function forUser(User $user, array $filters): array
    {
        $filters = array_filter(array_intersect_key($filters, array_flip(self::FILTERS)), fn ($v) => $v !== null && $v !== '');
        $seeAll = $user->can('read-archive-confidential');
        $key = 'archive_stats:' . Cache::get('archive_stats_version', 1) . ':' . ($seeAll ? 'all' : 'std') . ':' . md5(json_encode($filters));

        $stats = Cache::remember($key, self::TTL, fn () => self::compute($filters, $seeAll));

        // Per-user, uncached and tiny: the latest document activity this user may see.
        $stats['recent'] = $user->can('read-archive') ? self::recent($user) : ['viewed' => [], 'downloaded' => []];

        return $stats;
    }

    private static function recordQuery(array $f, bool $seeAll)
    {
        $q = ArchiveRecord::query();
        if (! $seeAll) {
            $q->whereNotIn('archive_records.confidentiality', ArchiveRecord::RESTRICTED_LEVELS);
        }

        return $q->filter([
            'category' => $f['category'] ?? null,
            'source_type' => $f['source_type'] ?? null,
            'warehouse_id' => $f['warehouse_id'] ?? null,
            'department' => $f['department'] ?? null,
            'document_from' => $f['date_from'] ?? null,
            'document_to' => $f['date_to'] ?? null,
            'file_type' => $f['file_type'] ?? null,
        ]);
    }

    private static function compute(array $f, bool $seeAll): array
    {
        $now = now();
        $q = fn () => self::recordQuery($f, $seeAll)->toBase();
        // Date-range counts use plain indexed range conditions (no DATE() on the column), one cheap query each.
        $created = fn ($from) => (int) $q()->where('archive_records.created_at', '>=', $from)->count();

        // Documents / files / bytes come from the covering index (deleted_at, files_count, original_bytes, storage_bytes).
        $sums = $q()->selectRaw('count(*) as documents, coalesce(sum(files_count), 0) as files, coalesce(sum(original_bytes), 0) as original_bytes, coalesce(sum(storage_bytes), 0) as storage_bytes')->first();

        $recentlyUpdated = (int) $q()->where('archive_records.updated_at', '>=', $now->copy()->subDays(7))->whereColumn('archive_records.updated_at', '>', 'archive_records.created_at')->count();
        $expiring = (int) $q()->whereBetween('archive_records.expiry_date', [$now->toDateString(), $now->copy()->addDays(30)->toDateString()])->count();
        $expired = (int) $q()->where('archive_records.expiry_date', '<', $now->toDateString())->count();
        $confidential = (int) $q()->whereIn('archive_records.confidentiality', ArchiveRecord::RESTRICTED_LEVELS)->count();

        // Group by the plain indexed column (an expression around it would defeat the index); label gaps in PHP.
        $groupBy = fn (string $col) => $q()->selectRaw("archive_records.$col as label, count(*) as total, coalesce(sum(files_count), 0) as files, coalesce(sum(storage_bytes), 0) as bytes")
            ->groupBy("archive_records.$col")->orderByDesc('total')->limit(12)->get()
            ->map(fn ($r) => ['label' => filled($r->label) ? $r->label : 'Unspecified', 'count' => (int) $r->total, 'files' => (int) $r->files, 'bytes' => (int) $r->bytes])->all();

        // Documents added per month for the last 12 months (index on created_at; empty months are filled in here).
        $monthStart = $now->copy()->startOfMonth()->subMonths(11);
        $perMonth = $q()->where('archive_records.created_at', '>=', $monthStart)
            ->selectRaw("date_format(archive_records.created_at, '%Y-%m') as ym, count(*) as total")->groupBy('ym')->pluck('total', 'ym');
        $monthly = collect(range(0, 11))->map(function ($i) use ($monthStart, $perMonth) {
            $m = $monthStart->copy()->addMonths($i);

            return ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'count' => (int) ($perMonth[$m->format('Y-m')] ?? 0)];
        })->all();

        // File types: straight from archive_files when nothing narrows the records; otherwise joined to the filtered records.
        $unfiltered = $seeAll && empty($f);
        $fileQuery = $unfiltered
            ? DB::table('archive_files')->whereNull('archive_files.deleted_at')
            : self::recordQuery($f, $seeAll)->toBase()->join('archive_files', 'archive_files.archive_record_id', '=', 'archive_records.id')->whereNull('archive_files.deleted_at');

        $fileRows = $fileQuery
            ->when($f['file_type'] ?? null, fn ($qq, $t) => $qq->where('archive_files.file_type', $t))
            ->selectRaw('archive_files.file_type as file_type, count(*) as files, coalesce(sum(coalesce(archive_files.optimized_size, archive_files.size_bytes) + coalesce(archive_files.thumbnail_size, 0)), 0) as bytes')
            ->groupBy('archive_files.file_type')->orderByDesc('bytes')->get();

        $totalBytes = max((int) $fileRows->sum('bytes'), 1);
        $fileTypes = $fileRows->map(fn ($r) => [
            'type' => $r->file_type,
            'files' => (int) $r->files,
            'bytes' => (int) $r->bytes,
            'percent' => round($r->bytes / $totalBytes * 100, 1),
        ])->all();

        $original = (int) $sums->original_bytes;
        $stored = (int) $sums->storage_bytes;

        return [
            'totals' => [
                'documents' => (int) $sums->documents,
                'files' => (int) $sums->files,
                'original_bytes' => $original,
                'storage_bytes' => $stored,
                'saved_bytes' => max($original - $stored, 0),
                'package_bytes' => 0,
                'today' => $created($now->copy()->startOfDay()),
                'this_month' => $created($now->copy()->startOfMonth()),
                'recently_updated' => $recentlyUpdated,
                'expiring' => $expiring,
                'expired' => $expired,
                'confidential' => $confidential,
            ],
            'file_types' => $fileTypes,
            'monthly' => $monthly,
            'categories' => $groupBy('category'),
            'sources' => $groupBy('source_type'),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    private static function recent(User $user): array
    {
        $everyone = $user->can('view-archive-access-logs');
        $seeAll = $user->can('read-archive-confidential');

        $fetch = function (array $events) use ($user, $everyone, $seeAll) {
            return Activity::query()
                ->where('log_name', ArchiveAccessLogger::LOG_NAME)
                ->where('subject_type', ArchiveRecord::class)
                ->whereIn('event', $events)
                ->when(! $everyone, fn ($q) => $q->where('causer_id', $user->id))
                ->with(['causer:id,name', 'subject' => fn ($q) => $q->select('id', 'reference', 'title', 'confidentiality')])
                ->orderByDesc('id')
                ->limit(40)
                ->get()
                ->filter(fn ($a) => $a->subject && ($seeAll || ! $a->subject->is_restricted))
                ->unique(fn ($a) => $a->subject_id . '|' . ($a->properties['attachment_id'] ?? ''))
                ->take(6)
                ->map(fn ($a) => [
                    'record_id' => $a->subject_id,
                    'reference' => $a->subject->reference,
                    'title' => $a->subject->title,
                    'filename' => $a->properties['filename'] ?? null,
                    'user' => $a->causer?->name ?? 'System',
                    'action' => $a->properties['action'] ?? null,
                    'at' => $a->created_at?->toIso8601String(),
                ])->values()->all();
        };

        return [
            'viewed' => $fetch(['view', 'preview', 'open_new_tab']),
            'downloaded' => $fetch(['download']),
        ];
    }
}
