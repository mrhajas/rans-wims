<?php

namespace App\Support\WimsLogs;

/**
 * The files of an archive, under storage/app/wims-logs/<log>/run-<id>.*
 *
 *   .bin      the records, each one compressed on its own (gzip) and written one after the other: nothing is shared between records, so every record has its own exact
 *             compressed size and can be opened without reading the rest.
 *   .idx      one line of JSON for each record: {"i": id, "t": date, "s": one-line description, "o": size before, "c": size compressed, "p": where it starts in the .bin}.
 *             It is written while the archive is open and compressed to .idx.gz when the archive is closed.
 *
 * A record is the whole database row as JSON (every column), so it can be read in full or put back exactly. Nothing here deletes anything from the database: the caller
 * does that only after verify() has read every record of a batch back and found it identical.
 */
class ArchiveStore
{
    public static function root(): string
    {
        return storage_path('app/wims-logs');
    }

    public static function rel(string $source, int $runId): string
    {
        return $source . '/run-' . $runId;
    }

    public static function bin(string $rel): string
    {
        return self::root() . '/' . $rel . '.bin';
    }

    public static function idx(string $rel): string
    {
        return self::root() . '/' . $rel . '.idx';
    }

    public static function idxGz(string $rel): string
    {
        return self::root() . '/' . $rel . '.idx.gz';
    }

    public static function exists(string $rel): bool
    {
        return is_file(self::bin($rel)) && (is_file(self::idxGz($rel)) || is_file(self::idx($rel)));
    }

    public static function ensureRoot(string $rel): void
    {
        $dir = dirname(self::bin($rel));
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('The archive folder could not be created.');
        }
        // nothing in this folder is meant to be fetched by a browser
        $ht = self::root() . '/.htaccess';
        if (! is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
    }

    /**
     * Writes a batch of rows to the open files.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{entries: array<int, array<string, mixed>>, hashes: array<string, string>, skipped: array<int, mixed>, original: int, compressed: int}
     */
    public static function append(string $rel, array $rows, string $pk, string $dateCol, callable $summary): array
    {
        self::ensureRoot($rel);
        clearstatcache(true, self::bin($rel));
        $bin = fopen(self::bin($rel), 'ab');
        $idx = fopen(self::idx($rel), 'ab');
        if (! $bin || ! $idx) {
            throw new \RuntimeException('The archive files could not be opened for writing.');
        }
        $offset = (int) filesize(self::bin($rel));
        $entries = [];
        $hashes = [];
        $skipped = [];
        $original = 0;
        $compressed = 0;
        $idxLines = '';

        try {
            foreach ($rows as $row) {
                try {
                    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                    $gz = gzcompress($json, 6);
                    if ($gz === false) {
                        throw new \RuntimeException('gzcompress failed');
                    }
                } catch (\Throwable $e) {
                    $skipped[] = $row[$pk] ?? null; // a row that cannot be written safely stays where it is
                    continue;
                }
                $id = $row[$pk];
                $entry = ['i' => $id, 't' => $row[$dateCol] ?? null, 's' => mb_substr((string) $summary($row), 0, 220), 'o' => strlen($json), 'c' => strlen($gz), 'p' => $offset];
                if (fwrite($bin, $gz) !== strlen($gz)) {
                    throw new \RuntimeException('The archive file could not be written (is the disk full?).');
                }
                $offset += strlen($gz);
                $original += strlen($json);
                $compressed += strlen($gz);
                $entries[] = $entry;
                $hashes[(string) $id] = sha1($json);
                $idxLines .= json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            fflush($bin);
            if (function_exists('fsync')) {
                @fsync($bin);
            }
            if ($idxLines !== '' && fwrite($idx, $idxLines) !== strlen($idxLines)) {
                throw new \RuntimeException('The archive index could not be written.');
            }
            fflush($idx);
            if (function_exists('fsync')) {
                @fsync($idx);
            }
        } finally {
            fclose($bin);
            fclose($idx);
        }

        return ['entries' => $entries, 'hashes' => $hashes, 'skipped' => $skipped, 'original' => $original, 'compressed' => $compressed];
    }

    /** Reads every record of $entries back from the file and checks it is exactly what was written. Throws at the first difference. */
    public static function verify(string $rel, array $entries, array $hashes): void
    {
        $h = fopen(self::bin($rel), 'rb');
        if (! $h) {
            throw new \RuntimeException('The archive file could not be read back.');
        }
        try {
            foreach ($entries as $e) {
                fseek($h, (int) $e['p']);
                $blob = fread($h, (int) $e['c']);
                $json = is_string($blob) && strlen($blob) === (int) $e['c'] ? @gzuncompress($blob) : false;
                if ($json === false || strlen($json) !== (int) $e['o'] || sha1($json) !== ($hashes[(string) $e['i']] ?? null)) {
                    throw new \RuntimeException('Record ' . $e['i'] . ' did not read back identical; nothing was removed from the live table.');
                }
            }
        } finally {
            fclose($h);
        }
    }

    /** Closes an archive: the index is compressed and the data file's checksum taken. @return array{sha256: string, stored: int} */
    public static function close(string $rel): array
    {
        if (is_file(self::idx($rel))) {
            $in = fopen(self::idx($rel), 'rb');
            $out = gzopen(self::idxGz($rel), 'wb6');
            if (! $in || ! $out) {
                throw new \RuntimeException('The archive index could not be compressed.');
            }
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
            fclose($in);
            gzclose($out);
            // the plain index goes only once the compressed one is readable and has as many lines
            if (self::countLines($rel) === self::countPlain($rel)) {
                @unlink(self::idx($rel));
            }
        }
        clearstatcache();

        return ['sha256' => (string) hash_file('sha256', self::bin($rel)), 'stored' => (int) @filesize(self::bin($rel)) + (int) @filesize(self::idxGz($rel))];
    }

    private static function countPlain(string $rel): int
    {
        $n = 0;
        $h = @fopen(self::idx($rel), 'rb');
        while ($h && fgets($h) !== false) {
            $n++;
        }
        if ($h) {
            fclose($h);
        }

        return $n;
    }

    private static function countLines(string $rel): int
    {
        $n = 0;
        foreach (self::lines($rel) as $_) {
            $n++;
        }

        return $n;
    }

    /** @return \Generator<int, array<string, mixed>> the index lines, in the order the records were written */
    public static function lines(string $rel): \Generator
    {
        $gz = is_file(self::idxGz($rel));
        $h = $gz ? @gzopen(self::idxGz($rel), 'rb') : @fopen(self::idx($rel), 'rb');
        if (! $h) {
            return;
        }
        try {
            while (! ($gz ? gzeof($h) : feof($h))) {
                $line = $gz ? gzgets($h) : fgets($h);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                $e = json_decode($line, true);
                if (is_array($e)) {
                    yield $e;
                }
            }
        } finally {
            $gz ? gzclose($h) : fclose($h);
        }
    }

    /**
     * One page of the record list (newest first inside the archive is not needed: they are in the order they were written). `$q` looks in the id, the date and the one-line description.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function page(string $rel, int $page, int $per, ?string $q = null, ?int $knownTotal = null): array
    {
        $q = $q !== null ? mb_strtolower(trim($q)) : null;
        $skip = ($page - 1) * $per;
        $rows = [];
        $seen = 0;
        $total = 0;
        foreach (self::lines($rel) as $e) {
            if ($q !== null && $q !== '' && ! str_contains(mb_strtolower($e['i'] . ' ' . ($e['t'] ?? '') . ' ' . ($e['s'] ?? '')), $q)) {
                continue;
            }
            $total++;
            if ($seen >= $skip && count($rows) < $per) {
                $rows[] = $e;
            }
            $seen++;
            // without a search the total is known, so the scan can stop once the page is full
            if (($q === null || $q === '') && $knownTotal !== null && count($rows) >= $per) {
                return ['rows' => $rows, 'total' => $knownTotal];
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** One record, whole: the row as it was in the database. @return array<string, mixed>|null */
    public static function record(string $rel, string $id): ?array
    {
        foreach (self::lines($rel) as $e) {
            if ((string) $e['i'] !== $id) {
                continue;
            }

            return self::read($rel, $e);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function read(string $rel, array $entry): ?array
    {
        $h = @fopen(self::bin($rel), 'rb');
        if (! $h) {
            return null;
        }
        fseek($h, (int) $entry['p']);
        $blob = fread($h, (int) $entry['c']);
        fclose($h);
        $json = is_string($blob) ? @gzuncompress($blob) : false;
        $row = $json !== false ? json_decode($json, true) : null;

        return is_array($row) ? $row : null;
    }

    /** Every record as one JSON line, for a readable download. @return \Generator<int, string> */
    public static function export(string $rel): \Generator
    {
        $h = @fopen(self::bin($rel), 'rb');
        if (! $h) {
            return;
        }
        try {
            foreach (self::lines($rel) as $e) {
                fseek($h, (int) $e['p']);
                $blob = fread($h, (int) $e['c']);
                $json = is_string($blob) ? @gzuncompress($blob) : false;
                if ($json !== false) {
                    yield $json . "\n";
                }
            }
        } finally {
            fclose($h);
        }
    }
}
