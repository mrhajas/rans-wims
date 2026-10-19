<?php

namespace App\Services\Simulation;

use Illuminate\Support\Facades\DB;

/**
 * Where a simulation record came from. Three states:
 *  imported  - copied from production and not changed since
 *  modified  - copied from production and edited in the simulation
 *  synthetic - made in the simulation (no provenance row at all)
 * "Modified" is found by comparing the record with the copy kept when it was imported, so it is true however the record was changed.
 */
class SimulationProvenance
{
    private const IGNORE = ['updated_at', 'created_at', 'deleted_at', 'extra_attributes'];

    private static function conn(string $table): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection(SimulationImporter::isAccounts($table) ? 'accounts' : 'mysql');
    }

    private static function norm($row): array
    {
        $a = is_string($row) ? json_decode($row, true) : (array) $row;
        $a = is_array($a) ? $a : [];
        foreach (self::IGNORE as $k) {
            unset($a[$k]);
        }
        ksort($a);

        return array_map(fn ($v) => is_scalar($v) || $v === null ? ($v === null ? null : (string) $v) : json_encode($v), $a);
    }

    /** bring the stored state of a table's imported records up to date (and notice records that were deleted); returns the counts */
    public static function refresh(?string $table = null): array
    {
        $tables = $table ? [$table] : DB::table('simulation_provenance')->distinct()->pluck('table_name')->all();
        $out = [];
        foreach ($tables as $t) {
            $c = self::conn($t);
            DB::table('simulation_provenance')->where('table_name', $t)->where('state', '<>', 'synthetic')->orderBy('id')->chunk(400, function ($rows) use ($t, $c) {
                $now = $c->table($t)->whereIn('id', $rows->pluck('sim_id'))->get()->keyBy('id');
                foreach ($rows as $p) {
                    $cur = $now[$p->sim_id] ?? null;
                    $state = $cur === null ? 'removed' : (self::norm($cur) === self::norm($p->baseline) ? 'imported' : 'modified');
                    if ($state !== $p->state) {
                        DB::table('simulation_provenance')->where('id', $p->id)->update(['state' => $state, 'modified_at' => $state === 'modified' ? now() : null, 'modified_by' => $state === 'modified' ? (auth()->id()) : null, 'updated_at' => now()]);
                    }
                }
            });
            $out[$t] = DB::table('simulation_provenance')->where('table_name', $t)->selectRaw('state, COUNT(*) n')->groupBy('state')->pluck('n', 'state')->all();
        }

        return $out;
    }

    /** the state of ONE record: imported / modified / synthetic (no provenance) / removed */
    public static function stateOf(string $table, int $id): string
    {
        $p = DB::table('simulation_provenance')->where('table_name', $table)->where('sim_id', $id)->first();
        if (! $p || $p->state === 'synthetic') {
            return 'synthetic';
        }
        $cur = self::conn($table)->table($table)->where('id', $id)->first();

        return $cur === null ? 'removed' : (self::norm($cur) === self::norm($p->baseline) ? 'imported' : 'modified');
    }

    /** the differences between the record as imported and as it is now: [[field, was, now]] */
    public static function compare(string $table, int $id): ?array
    {
        $p = DB::table('simulation_provenance')->where('table_name', $table)->where('sim_id', $id)->first();
        if (! $p) {
            return null;
        }
        $cur = self::conn($table)->table($table)->where('id', $id)->first();
        $was = self::norm($p->baseline);
        $now = $cur ? self::norm($cur) : [];
        $out = [];
        foreach (array_unique(array_merge(array_keys($was), array_keys($now))) as $k) {
            if (($was[$k] ?? null) !== ($now[$k] ?? null)) {
                $out[] = ['field' => $k, 'was' => $was[$k] ?? null, 'now' => $now[$k] ?? null];
            }
        }

        return ['source_id' => $p->source_id, 'batch_id' => $p->batch_id, 'imported_at' => (string) $p->created_at, 'changes' => $out, 'removed' => $cur === null];
    }

    /** put the record back as it was imported */
    public static function restore(string $table, int $id): bool
    {
        $p = DB::table('simulation_provenance')->where('table_name', $table)->where('sim_id', $id)->first();
        if (! $p || ! ($base = json_decode($p->baseline, true))) {
            return false;
        }
        unset($base['id']);
        $c = self::conn($table);
        $c->table($table)->where('id', $id)->update($base);
        DB::table('simulation_provenance')->where('id', $p->id)->update(['state' => 'imported', 'modified_at' => null, 'modified_by' => null, 'updated_at' => now()]);

        return true;
    }

    public static function summary(): array
    {
        self::refresh();
        $rows = DB::table('simulation_provenance')->selectRaw('table_name, state, COUNT(*) n')->groupBy('table_name', 'state')->get();
        $by = [];
        foreach ($rows as $r) {
            $by[$r->table_name][$r->state] = (int) $r->n;
        }

        return $by;
    }
}
