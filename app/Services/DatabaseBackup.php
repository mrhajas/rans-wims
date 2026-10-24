<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DatabaseBackup
{
    public function saveToDisk(string $filename, string $directory = null): string
    {
        $directory = $directory ?? storage_path('app/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create backup directory: ' . $directory);
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $handle = fopen($path, 'w');

        if (! $handle) {
            throw new \RuntimeException('Could not write backup file.');
        }

        $this->writeDump($handle);
        fclose($handle);

        return $path;
    }

    public function restoreFromFile(string $path): array
    {
        if (! is_readable($path)) {
            throw new \RuntimeException('Backup file is not readable.');
        }

        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new \RuntimeException('Could not open backup file.');
        }

        $statementsRun = 0;
        $errors = [];

        try {
            DB::unprepared('SET FOREIGN_KEY_CHECKS=0');

            $buffer = '';
            while (! feof($handle)) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                $buffer .= $line;
                if (str_ends_with(rtrim($line), ';')) {
                    $sql = trim($buffer);
                    $buffer = '';
                    if ($sql === '') {
                        continue;
                    }
                    try {
                        DB::unprepared($sql);
                        $statementsRun++;
                    } catch (\Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }

            if (trim($buffer) !== '') {
                try {
                    DB::unprepared(trim($buffer));
                    $statementsRun++;
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } finally {
            DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
            fclose($handle);
        }

        return ['statements' => $statementsRun, 'errors' => $errors];
    }

    private function writeDump($out): void
    {
        $databaseName = DB::connection()->getDatabaseName();
        fwrite($out, "-- WIMS Database Backup\n");
        fwrite($out, '-- Database: ' . $databaseName . "\n");
        fwrite($out, '-- Generated: ' . now()->format('Y-m-d H:i:s') . "\n");
        fwrite($out, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($out, "SET NAMES utf8mb4;\n\n");

        $pdo = DB::connection()->getPdo();
        $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));

        foreach ($tables as $table) {
            fwrite($out, "-- ----------------------------\n");
            fwrite($out, "-- Table structure for `{$table}`\n");
            fwrite($out, "-- ----------------------------\n");
            fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");

            $createRow = (array) DB::select("SHOW CREATE TABLE `{$table}`")[0];
            $createStmt = $createRow['Create Table'] ?? $createRow['Create View'] ?? null;
            if ($createStmt) {
                fwrite($out, $createStmt . ";\n\n");
            }

            $count = (int) DB::table($table)->count();
            if ($count === 0) {
                continue;
            }

            fwrite($out, "-- Data for `{$table}` ({$count} rows)\n");

            $write = function ($rows) use ($out, $table, $pdo) {
                $first = (array) $rows->first();
                $columns = array_keys($first);
                $cols = '(' . implode(',', array_map(fn ($c) => "`{$c}`", $columns)) . ')';

                // several rows per INSERT (up to 100 rows or about 512 KB): a smaller file and far fewer statements to run when it is restored.
                // Each statement still sits on ONE line, which is what the restore reads.
                $batch = [];
                $size = 0;
                $flush = function () use (&$batch, &$size, $out, $table, $cols) {
                    if ($batch) {
                        fwrite($out, "INSERT INTO `{$table}` {$cols} VALUES " . implode(',', $batch) . ";\n");
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

            fwrite($out, "\n");
        }

        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
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
