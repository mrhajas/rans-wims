<?php

namespace App\Support\Optimizer;

use Illuminate\Support\Facades\DB;

/** What there is, measured before and after an optimisation: the database, the uploaded pictures, the server logs, temporary files, and the free disk space. */
class Metrics
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    /** Folders with pictures that people uploaded. Never the program, never the backups. */
    public static function imageRoots(): array
    {
        return array_values(array_filter([
            public_path('images/profile-photos'),
            public_path('uploads'),
            storage_path('app/attachments'),
            storage_path('app/archive'),
            storage_path('app/site-diary'),
            storage_path('app/idpass'),
            storage_path('app/acct-attachments'),
        ], 'is_dir'));
    }

    /** Folders that are never opened: copies of the system itself and the simulation data. */
    public static function skipped(string $path): bool
    {
        return (bool) preg_match('#/(_simulation|backups|db-backups|db-restore|import-backups|optimizer)(/|$)#', str_replace('\\', '/', $path));
    }

    public static function dir(): string
    {
        return storage_path('app/optimizer');
    }

    /** @return \Generator<string> every file below $root (not the skipped folders) */
    public static function files(string $root, ?array $ext = null, bool $skipFolders = true): \Generator
    {
        if (! is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (! $f->isFile() || $f->isLink() || ($skipFolders && self::skipped($f->getPathname()))) {
                continue;
            }
            if ($ext !== null && ! in_array(strtolower($f->getExtension()), $ext, true)) {
                continue;
            }
            yield $f->getPathname();
        }
    }

    /** @return array{bytes:int,count:int} */
    public static function folder(array|string $roots, ?array $ext = null, bool $skipFolders = true): array
    {
        $bytes = 0;
        $count = 0;
        foreach ((array) $roots as $root) {
            foreach (self::files($root, $ext, $skipFolders) as $p) {
                $bytes += (int) @filesize($p);
                $count++;
            }
        }

        return ['bytes' => $bytes, 'count' => $count];
    }

    public static function database(): array
    {
        try {
            DB::statement('SET SESSION information_schema_stats_expiry = 0'); // MySQL 8 otherwise reports sizes up to a day old
        } catch (\Throwable $e) {
            // not MySQL 8
        }
        $r = DB::selectOne("select coalesce(sum(data_length + index_length), 0) as bytes, coalesce(sum(data_free), 0) as free, count(*) as tables_n from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'");

        return ['bytes' => (int) $r->bytes, 'free' => (int) $r->free, 'tables' => (int) $r->tables_n];
    }

    /** The biggest tables, with what could be won back from each. */
    public static function tables(int $limit = 10): array
    {
        try {
            DB::statement('SET SESSION information_schema_stats_expiry = 0');
        } catch (\Throwable $e) {
        }
        $rows = DB::select("select table_name as name, (data_length + index_length) as bytes, data_free as free, table_rows as rows_n from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' order by (data_length + index_length) desc limit " . (int) $limit);

        return array_map(fn ($r) => ['name' => $r->name, 'bytes' => (int) $r->bytes, 'free' => (int) $r->free, 'total' => (int) $r->bytes + (int) $r->free, 'rows' => (int) $r->rows_n], $rows);
    }

    public static function snapshot(): array
    {
        $db = self::database();
        $img = self::folder(self::imageRoots(), self::IMAGE_EXT);
        $logs = self::folder(storage_path('logs'));
        $originals = self::folder(self::dir() . '/originals', null, false);
        $backups = self::folder([storage_path('app/backups'), storage_path('app/db-backups')], null, false);

        return [
            'at' => now()->toDateTimeString(),
            'db_bytes' => $db['bytes'], 'db_free_bytes' => $db['free'], 'db_total_bytes' => $db['bytes'] + $db['free'], 'db_tables' => $db['tables'],
            'images_bytes' => $img['bytes'], 'images_count' => $img['count'],
            'logs_bytes' => $logs['bytes'], 'logs_count' => $logs['count'],
            'originals_bytes' => $originals['bytes'], 'originals_count' => $originals['count'],
            'backups_bytes' => $backups['bytes'], 'backups_count' => $backups['count'], // only shown: backups are never touched
            'disk_free_bytes' => (int) @disk_free_space(base_path()),
        ];
    }

    /** What the program itself occupies and can give back: the database (data, indexes and the free space inside its files) + pictures + logs + the kept originals (the backups are not part of it). */
    public static function footprint(array $s): int
    {
        return (int) (($s['db_total_bytes'] ?? ($s['db_bytes'] + ($s['db_free_bytes'] ?? 0))) + $s['images_bytes'] + $s['logs_bytes'] + ($s['originals_bytes'] ?? 0));
    }
}
