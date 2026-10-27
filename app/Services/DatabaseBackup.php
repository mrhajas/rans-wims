<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DatabaseBackup
{
    /**
     * Writes the whole database to a file. The file is compressed (.sql.gz, about 7 times smaller: a quicker download, less disk on the hosting,
     * and an upload small enough for the hosting's limit when it is restored). Only a name ending in .sql keeps a plain file.
     */
    public function saveToDisk(string $filename, string $directory = null): string
    {
        $this->relaxLimits();
        $directory = $directory ?? storage_path('app/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create backup directory: ' . $directory);
        }

        $compress = ! str_ends_with(strtolower($filename), '.sql');
        if ($compress && ! str_ends_with(strtolower($filename), '.gz')) {
            $filename .= '.gz';
        }
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $partial = $path . '.partial';
        $handle = $compress ? gzopen($partial, 'wb5') : fopen($partial, 'w');

        if (! $handle) {
            throw new \RuntimeException('Could not write backup file.');
        }

        try {
            $this->writeDump($handle, $compress);
        } catch (\Throwable $e) {
            $compress ? gzclose($handle) : fclose($handle);
            @unlink($partial); // never leave half a backup that looks like a whole one

            throw $e;
        }
        $compress ? gzclose($handle) : fclose($handle);
        rename($partial, $path);

        return $path;
    }

    /** A backup or a restore of a big database must not be stopped by the hosting's time limit or run out of memory on its own bookkeeping. */
    private function relaxLimits(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && $this->bytes($limit) < 256 * 1048576) {
            @ini_set('memory_limit', '256M');
        }
        DB::connection()->disableQueryLog();
        // every query listener (the debug bar, the slow-query log) keeps the SQL of each statement: a restore runs thousands of statements of up to half a
        // megabyte each, which used to push the memory past 130 MB. A backup / restore request needs none of them.
        try {
            app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);
        } catch (\Throwable $e) {
            // nothing to forget
        }
    }

    private function bytes(string $v): int
    {
        $n = (int) $v;
        $u = strtolower(substr(trim($v), -1));

        return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
    }

    /** Runs a .sql or .sql.gz backup. Statements are run in batches inside one transaction each (much faster than one by one on a big database). */
    public function restoreFromFile(string $path): array
    {
        $this->relaxLimits();
        if (! is_readable($path)) {
            throw new \RuntimeException('Backup file is not readable.');
        }

        $gz = $this->isGzip($path);
        $handle = $gz ? gzopen($path, 'rb') : fopen($path, 'r');
        if (! $handle) {
            throw new \RuntimeException('Could not open backup file.');
        }
        // one whole line, however long (gzgets returns at most the length asked, so a long INSERT is put together from pieces)
        $read = function () use ($gz, $handle) {
            if (! $gz) {
                return fgets($handle);
            }
            $line = '';
            while (! gzeof($handle)) {
                $piece = gzgets($handle, 262144);
                if ($piece === false) {
                    break;
                }
                $line .= $piece;
                if (str_ends_with($piece, "\n")) {
                    break;
                }
            }

            return $line === '' ? false : $line;
        };
        $done = fn () => $gz ? gzeof($handle) : feof($handle);

        $statementsRun = 0;
        $errors = [];

        try {
            DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
            DB::unprepared('SET UNIQUE_CHECKS=0');
            DB::unprepared('SET autocommit=0');

            $buffer = '';
            $sinceCommit = 0;
            $run = function (string $sql) use (&$statementsRun, &$errors, &$sinceCommit) {
                try {
                    DB::unprepared($sql);
                    $statementsRun++;
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
                if (++$sinceCommit >= 200) { // a batch is saved, so a long restore never holds one giant open transaction
                    DB::unprepared('COMMIT');
                    $sinceCommit = 0;
                }
            };
            while (! $done()) {
                $line = $read();
                if ($line === false) {
                    break;
                }
                $trimmed = trim($line);
                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#'))) {
                    continue; // comments only count between statements (never inside one)
                }
                $buffer .= $line;
                if (str_ends_with(rtrim($line), ';')) {
                    $sql = trim($buffer);
                    $buffer = '';
                    if ($sql !== '') {
                        $run($sql);
                    }
                }
            }
            if (trim($buffer) !== '') {
                $run(trim($buffer));
            }
            DB::unprepared('COMMIT');
        } finally {
            try {
                DB::unprepared('COMMIT');
                DB::unprepared('SET autocommit=1');
                DB::unprepared('SET UNIQUE_CHECKS=1');
                DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Throwable $e) {
                // the connection is gone: nothing more to reset
            }
            $gz ? gzclose($handle) : fclose($handle);
        }

        return ['statements' => $statementsRun, 'errors' => $errors];
    }

    /** True for a gzip file (checked by its first two bytes, so a renamed file still works). */
    public function isGzip(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (! $h) {
            return false;
        }
        $magic = fread($h, 2);
        fclose($h);

        return $magic === "\x1f\x8b";
    }

    private function writeDump($out, bool $gz = false): void
    {
        // one write function for plain and compressed files
        $fwrite = $gz ? 'gzwrite' : 'fwrite';
        $databaseName = DB::connection()->getDatabaseName();
        $fwrite($out, "-- WIMS Database Backup\n");
        $fwrite($out, '-- Database: ' . $databaseName . "\n");
        $fwrite($out, '-- Generated: ' . now()->format('Y-m-d H:i:s') . "\n");
        $fwrite($out, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        $fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
        $fwrite($out, "SET NAMES utf8mb4;\n\n");

        $pdo = DB::connection()->getPdo();
        $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));

        foreach ($tables as $table) {
            $fwrite($out, "-- ----------------------------\n");
            $fwrite($out, "-- Table structure for `{$table}`\n");
            $fwrite($out, "-- ----------------------------\n");
            $fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");

            $createRow = (array) DB::select("SHOW CREATE TABLE `{$table}`")[0];
            $createStmt = $createRow['Create Table'] ?? $createRow['Create View'] ?? null;
            if ($createStmt) {
                $fwrite($out, $createStmt . ";\n\n");
            }

            $count = (int) DB::table($table)->count();
            if ($count === 0) {
                continue;
            }

            $fwrite($out, "-- Data for `{$table}` ({$count} rows)\n");

            $write = function ($rows) use ($out, $table, $pdo, $fwrite) {
                $first = (array) $rows->first();
                $columns = array_keys($first);
                $cols = '(' . implode(',', array_map(fn ($c) => "`{$c}`", $columns)) . ')';

                // several rows per INSERT (up to 100 rows or about 512 KB): a smaller file and far fewer statements to run when it is restored.
                // Each statement still sits on ONE line, which is what the restore reads.
                $batch = [];
                $size = 0;
                $flush = function () use (&$batch, &$size, $out, $table, $cols, $fwrite) {
                    if ($batch) {
                        $fwrite($out, "INSERT INTO `{$table}` {$cols} VALUES " . implode(',', $batch) . ";\n");
                    }
                    $batch = [];
                    $size = 0;
                };
                foreach ($rows as $row) {
                    $values = array_map(function ($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        if (is_int($v) || is_float($v)) return (string) $v;
                        if (is_bool($v)) return $v ? '1' : '0';
                        return $pdo->quote((string) $v);
                    }, array_values((array) $row));
                    $tuple = '(' . implode(',', $values) . ')';
                    $batch[] = $tuple;
                    $size += strlen($tuple);
                    if (count($batch) >= 100 || $size >= 524288) {
                        $flush();
                    }
                }
                $flush();
            };

            // Walk the table by its primary key when it has a single one (one pass, however large the table), not by OFFSET paging, which gets slower with every page
            // (the activity log alone took 15 of 20 seconds that way).
            $pk = $this->singlePrimaryKey($table);
            if ($pk) {
                DB::table($table)->chunkById(500, $write, $pk);
            } else {
                DB::table($table)->orderByRaw('1')->chunk(500, $write);
            }

            $fwrite($out, "\n");
        }

        $fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /** The name of the primary key column when the table has exactly one (and it is a whole number), else null. */
    private function singlePrimaryKey(string $table): ?string
    {
        try {
            $keys = DB::select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            if (count($keys) !== 1) {
                return null;
            }
            $column = $keys[0]->Column_name;
            $info = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column]);

            return $info && preg_match('/int/i', (string) $info[0]->Type) ? $column : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
