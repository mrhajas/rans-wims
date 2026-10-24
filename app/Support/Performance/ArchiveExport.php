<?php

namespace App\Support\Performance;

use App\Models\User;
use App\Support\PrintDocuments;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The activity archive as WIMS-style PDF. A small selection is one PDF made at once; a large one is cut into parts of PART rows that the scheduler makes a few at a time
 * (command archive:export-run) and hands over as one ZIP, because a PDF of 100,000 lines cannot be drawn inside one web request.
 * Filters: from, to, log, search. No filter = everything; from without to = until today; to without from = from the very first record.
 */
class ArchiveExport
{
    public const SYNC_MAX = 300;  // up to this many rows the PDF is made at once
    public const PART = 300;       // rows in each part of a large export

    /** The archive rows the filters select. */
    public static function query(array $f)
    {
        $q = DB::table('activity_log_archive');
        if (! empty($f['from'])) {
            $q->where('created_at', '>=', $f['from'] . ' 00:00:00');
        }
        if (! empty($f['to'])) {
            $q->where('created_at', '<=', $f['to'] . ' 23:59:59');
        }
        if (! empty($f['log'])) {
            $q->where('log_name', $f['log']);
        }
        if (isset($f['search']) && trim((string) $f['search']) !== '') {
            $q->where('description', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], trim($f['search'])) . '%');
        }

        return $q;
    }

    public static function clean(array $in): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        return array_filter([
            'from' => $date($in['from'] ?? null), 'to' => $date($in['to'] ?? null),
            'log' => isset($in['log']) && $in['log'] !== '' ? mb_substr((string) $in['log'], 0, 100) : null,
            'search' => isset($in['search']) && trim((string) $in['search']) !== '' ? mb_substr(trim((string) $in['search']), 0, 120) : null,
        ], fn ($v) => $v !== null);
    }

    public static function period(array $f): string
    {
        $d = fn ($v) => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y');
        if (! empty($f['from']) && ! empty($f['to'])) {
            return $d($f['from']) . ' to ' . $d($f['to']);
        }
        if (! empty($f['from'])) {
            return $d($f['from']) . ' to today';
        }
        if (! empty($f['to'])) {
            return 'Beginning to ' . $d($f['to']);
        }

        return 'All dates';
    }

    /**
     * One PDF: up to $limit rows after $afterId. Returns [pdf bytes, last id, rows in it].
     *
     * @return array{0:string,1:int,2:int}
     */
    public static function part(array $f, int $afterId, int $limit, int $part, int $parts, int $total, int $firstNo, ?User $by): array
    {
        $rows = self::query($f)->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get();
        $names = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('causer_id')->filter()->unique())->pluck('name', 'id');
        $lines = $rows->map(fn ($r) => [
            \Illuminate\Support\Carbon::parse($r->created_at)->format('d/m/Y H:i'), $r->log_name, \Illuminate\Support\Str::limit((string) $r->description, 220),
            $r->causer_id ? ($names[$r->causer_id] ?? '#' . $r->causer_id) : null,
            $r->subject_type ? class_basename($r->subject_type) . ($r->subject_id ? ' #' . $r->subject_id : '') : null,
        ])->all();

        $doc = PrintDocuments::activityArchive([
            'part' => $part, 'parts' => $parts, 'first' => $firstNo, 'last' => $firstNo + max(0, count($lines) - 1), 'total' => $total,
            'period' => self::period($f), 'log' => $f['log'] ?? null, 'search' => $f['search'] ?? null,
        ], $lines, optional($by)->name);

        $pdf = Pdf::loadView('print.document', [
            'doc' => $doc, 'isPdf' => true, 'companyName' => optional(optional($by)->account)->name,
            'qr' => PrintQrCode::dataUri(route('performance.index')), 'pdfUrl' => route('performance.index'),
        ])->setPaper('a4', 'landscape')->output();

        return [$pdf, (int) ($rows->last()->id ?? $afterId), count($lines)];
    }

    // ---- the background export
    public static function dir(int $id): string
    {
        return 'exports/activity-archive/' . $id;
    }

    /** Makes parts until about $seconds have passed; returns true when the export is finished. */
    public static function work(object $export, int $seconds = 40): bool
    {
        $f = json_decode($export->filters ?? '[]', true) ?: [];
        $by = User::withoutGlobalScopes()->find($export->user_id);
        $started = microtime(true);
        $disk = Storage::disk('local');
        DB::table('activity_archive_exports')->where('id', $export->id)->update(['status' => 'running', 'updated_at' => now()]);

        while ($export->parts_done < $export->parts_total && (microtime(true) - $started) < $seconds) {
            $partNo = $export->parts_done + 1;
            [$pdf, $last, $count] = self::part($f, (int) $export->cursor_id, self::PART, $partNo, (int) $export->parts_total, (int) $export->total_rows, $export->parts_done * self::PART + 1, $by);
            $disk->put(self::dir($export->id) . '/activity-archive-part-' . str_pad((string) $partNo, 3, '0', STR_PAD_LEFT) . '-of-' . str_pad((string) $export->parts_total, 3, '0', STR_PAD_LEFT) . '.pdf', $pdf);
            unset($pdf);
            $export->parts_done = $partNo;
            $export->cursor_id = $last;
            DB::table('activity_archive_exports')->where('id', $export->id)->update(['parts_done' => $partNo, 'cursor_id' => $last, 'updated_at' => now()]);
            if ($count < self::PART) {
                break; // the archive ended earlier than counted (rows were cleared in the meantime)
            }
            gc_collect_cycles();
        }

        if ($export->parts_done >= $export->parts_total || ($count ?? self::PART) < self::PART) {
            return self::finish($export);
        }

        return false;
    }

    private static function finish(object $export): bool
    {
        $disk = Storage::disk('local');
        $dir = self::dir($export->id);
        $zipRel = $dir . '/activity-archive-' . $export->id . '.zip';
        $zipAbs = $disk->path($zipRel);
        $zip = new \ZipArchive();
        if ($zip->open($zipAbs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            DB::table('activity_archive_exports')->where('id', $export->id)->update(['status' => 'failed', 'error' => 'The ZIP file could not be created.', 'updated_at' => now()]);

            return true;
        }
        foreach ($disk->files($dir) as $file) {
            if (str_ends_with($file, '.pdf')) {
                $zip->addFile($disk->path($file), basename($file));
            }
        }
        $zip->close();
        foreach ($disk->files($dir) as $file) {
            if (str_ends_with($file, '.pdf')) {
                $disk->delete($file);
            }
        }
        DB::table('activity_archive_exports')->where('id', $export->id)->update(['status' => 'done', 'file' => $zipRel, 'finished_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** Removes the exports (and their files) older than $days. */
    public static function purge(int $days = 7): int
    {
        $old = DB::table('activity_archive_exports')->where('created_at', '<', now()->subDays($days))->get();
        foreach ($old as $e) {
            Storage::disk('local')->deleteDirectory(self::dir($e->id));
        }

        return DB::table('activity_archive_exports')->whereIn('id', $old->pluck('id'))->delete();
    }
}
