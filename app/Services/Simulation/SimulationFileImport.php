<?php

namespace App\Services\Simulation;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * Bring a spreadsheet (Excel or CSV) of fictional or planned records into the simulation. Only the columns listed for a kind of record are read; the file is
 * checked row by row first and nothing is saved unless every row is fine. The records are marked as made in the simulation (synthetic).
 */
class SimulationFileImport
{
    public const MAX_ROWS = 5000;

    /** kind => table, the columns that may be in the file (name => [required, kind]), and names looked up in the simulation */
    public static function specs(): array
    {
        return [
            'contacts' => ['label' => 'Contacts and subcontractors', 'table' => 'contacts', 'columns' => ['name' => [true, 'text'], 'category' => [true, 'text'], 'phone' => [false, 'text'], 'email' => [false, 'text'], 'location' => [false, 'text'], 'details' => [false, 'text']], 'lookups' => []],
            'projects' => ['label' => 'Projects', 'table' => 'warehouses', 'columns' => ['name' => [true, 'text'], 'code' => [false, 'text'], 'status' => [false, 'text'], 'site_budget' => [false, 'number'], 'start_date' => [false, 'date'], 'expected_end_date' => [false, 'date'], 'project_manager' => [false, 'text'], 'address' => [false, 'text']], 'lookups' => []],
            'subcontracts' => ['label' => 'Subcontract agreements', 'table' => 'subcontract_agreements', 'columns' => ['title' => [true, 'text'], 'currency' => [false, 'text'], 'original_amount' => [true, 'number'], 'status' => [false, 'text'], 'agreement_date' => [false, 'date'], 'expected_completion' => [false, 'date'], 'site' => [false, 'text']],
                'lookups' => ['contact' => ['column' => 'contact_id', 'table' => 'contacts', 'by' => 'name', 'required' => true], 'project' => ['column' => 'warehouse_id', 'table' => 'warehouses', 'by' => 'name', 'required' => false]]],
            'items' => ['label' => 'Items and materials', 'table' => 'items', 'columns' => ['name' => [true, 'text'], 'code' => [false, 'text'], 'sku' => [false, 'text'], 'price' => [false, 'number'], 'details' => [false, 'text']], 'lookups' => []],
        ];
    }

    public static function template(string $kind): array
    {
        $s = self::specs()[$kind] ?? abort(404);

        return array_merge(array_keys($s['columns']), array_keys($s['lookups']));
    }

    /** @return array{rows:array,errors:array} nothing is written */
    public static function check(string $kind, string $path): array
    {
        $s = self::specs()[$kind] ?? abort(404);
        $rows = (new FastExcel)->import($path)->take(self::MAX_ROWS + 1)->all();
        if (! count($rows)) {
            throw ValidationException::withMessages(['file' => __('The file has no rows.')]);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('The file has more than :n rows.', ['n' => self::MAX_ROWS])]);
        }
        $head = collect(array_keys((array) $rows[0]))->map(fn ($h) => strtolower(trim((string) $h)))->all();
        $need = collect($s['columns'])->filter(fn ($c) => $c[0])->keys()->merge(collect($s['lookups'])->filter(fn ($l) => $l['required'])->keys())->all();
        $missing = array_diff($need, $head);
        if ($missing) {
            throw ValidationException::withMessages(['file' => __('The first row must name these columns: :c.', ['c' => implode(', ', $missing)])]);
        }
        $errors = [];
        $clean = [];
        foreach ($rows as $i => $r) {
            $r = collect((array) $r)->mapWithKeys(fn ($v, $k) => [strtolower(trim((string) $k)) => is_string($v) ? trim($v) : $v])->all();
            $line = $i + 2;
            $out = [];
            foreach ($s['columns'] as $col => [$req, $type]) {
                $v = $r[$col] ?? null;
                if ($v instanceof \DateTimeInterface) {
                    $v = $v->format('Y-m-d');
                }
                if ($v === null || $v === '') {
                    if ($req) {
                        $errors[] = __('Row :n: ":c" is required.', ['n' => $line, 'c' => $col]);
                    }

                    continue;
                }
                if ($type === 'number') {
                    $v = str_replace(',', '', (string) $v);
                    if (! is_numeric($v) || (float) $v < 0 || ! preg_match('/^\d{1,16}(\.\d{1,4})?$/', ltrim((string) $v, '+'))) {
                        $errors[] = __('Row :n: ":c" must be a number (0 or more).', ['n' => $line, 'c' => $col]);

                        continue;
                    }
                } elseif ($type === 'date') {
                    try {
                        $v = \Carbon\Carbon::parse((string) $v)->toDateString();
                    } catch (\Throwable) {
                        $errors[] = __('Row :n: ":c" is not a date.', ['n' => $line, 'c' => $col]);

                        continue;
                    }
                } elseif (mb_strlen((string) $v) > 190 && $col !== 'details') {
                    $errors[] = __('Row :n: ":c" is too long.', ['n' => $line, 'c' => $col]);

                    continue;
                }
                $out[$col] = $v;
            }
            foreach ($s['lookups'] as $name => $l) {
                $v = $r[$name] ?? null;
                if ($v === null || $v === '') {
                    if ($l['required']) {
                        $errors[] = __('Row :n: ":c" is required.', ['n' => $line, 'c' => $name]);
                    }

                    continue;
                }
                $id = DB::table($l['table'])->where($l['by'], $v)->whereNull('deleted_at')->value('id');
                if (! $id) {
                    $errors[] = __('Row :n: no :t named ":v" in the simulation (import or add it first).', ['n' => $line, 't' => $name, 'v' => $v]);

                    continue;
                }
                $out[$l['column']] = $id;
            }
            $clean[] = $out;
        }

        return ['rows' => $clean, 'errors' => array_slice($errors, 0, 60), 'error_count' => count($errors)];
    }

    /** check, then save every row in one go; returns the batch */
    public static function run(string $kind, string $path, ?int $userId): array
    {
        $chk = self::check($kind, $path);
        if ($chk['error_count']) {
            throw ValidationException::withMessages(['file' => array_merge([__(':n problem(s) found; nothing was saved.', ['n' => $chk['error_count']])], $chk['errors'])]);
        }
        $s = self::specs()[$kind];
        $t = $s['table'];
        $db = DB::connection('mysql');
        $cols = collect($db->select('select COLUMN_NAME n, IS_NULLABLE nu, COLUMN_DEFAULT d, DATA_TYPE t, EXTRA ex from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ?', [$t]))->keyBy('n');
        $batch = DB::table('simulation_import_batches')->insertGetId(['reference' => 'SIM-FILE-' . now()->format('YmdHis') . '-' . random_int(100, 999), 'module' => $kind, 'user_id' => $userId, 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        DB::transaction(function () use ($chk, $t, $cols, &$ids, $userId, $kind) {
            foreach ($chk['rows'] as $row) {
                $new = $row;
                if (isset($cols['account_id'])) {
                    $new['account_id'] = 1;
                }
                foreach (['created_at', 'updated_at'] as $ts) {
                    if (isset($cols[$ts])) {
                        $new[$ts] = now();
                    }
                }
                if ($t === 'subcontract_agreements') {
                    $new += ['reference' => \App\Models\SubcontractAgreement::nextReference(), 'status' => 'Draft', 'user_id' => $userId, 'source' => 'simulation', 'currency' => 'TZS'];
                }
                foreach ($cols as $name => $c) {
                    if (! array_key_exists($name, $new) && $c->nu === 'NO' && $c->d === null && ! str_contains((string) $c->ex, 'auto_increment') && ! str_contains(strtoupper((string) $c->ex), 'GENERATED') && $name !== 'id') {
                        $new[$name] = in_array($c->t, ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'double', 'float'], true) ? 0 : (in_array($c->t, ['date'], true) ? now()->toDateString() : (in_array($c->t, ['datetime', 'timestamp'], true) ? now() : ''));
                    }
                }
                $ids[] = DB::table($t)->insertGetId($new);
            }
        });
        foreach (array_chunk($ids, 300) as $chunk) {
            DB::table('simulation_provenance')->insert(array_map(fn ($id) => ['table_name' => $t, 'sim_id' => $id, 'state' => 'synthetic', 'batch_id' => $batch, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()], $chunk));
        }
        DB::table('simulation_import_batches')->where('id', $batch)->update(['status' => 'done', 'summary' => json_encode(['rows' => [$t => count($ids)], 'total' => count($ids), 'from' => 'file']), 'finished_at' => now(), 'updated_at' => now()]);

        return ['batch' => $batch, 'count' => count($ids)];
    }
}
