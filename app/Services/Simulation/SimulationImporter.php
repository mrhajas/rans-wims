<?php

namespace App\Services\Simulation;

use App\Support\Simulation\Environment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copies chosen production records into the simulation, as independent records.
 *
 * - Production is only ever READ, through the read-only connections, and only tables the SimulationCatalog names.
 * - Every link between records is remapped to the simulation's own ids; the production id is kept only as a separate "source" note (simulation_provenance).
 *   A link that cannot be carried over becomes empty: it can never point at a production record.
 * - Links the record NEEDS (a required column) are brought in with it; links it could do without are brought in only when the person ticks them.
 * - A record that was imported before is recognised and skipped, so a batch can be run again safely.
 */
class SimulationImporter
{
    public const MAX_ROWS = 20000;

    private array $meta = [];

    private ?array $baseTables = null;

    /** rows read from production during one plan/run: table => [id => row] */
    private array $rows = [];

    /** lookups read once per plan: provenance of a table (source id => simulation id) and the ids a reference table already holds */
    private array $mapCache = [];

    private array $simCache = [];

    public function __construct(private ?int $userId = null)
    {
    }

    // ------------------------------------------------------------ connections and table facts

    public static function isAccounts(string $table): bool
    {
        return in_array($table, SimulationCatalog::accountsTables(), true);
    }

    private function src(string $table): ConnectionInterface
    {
        return DB::connection(self::isAccounts($table) ? 'accounts_production_ro' : 'mysql_production_ro');
    }

    private function dst(string $table): ConnectionInterface
    {
        return DB::connection(self::isAccounts($table) ? 'accounts' : 'mysql');
    }

    /** columns, links and unique keys of a table, read from the simulation's own database (the same structure as production) */
    private function meta(string $table): array
    {
        if (isset($this->meta[$table])) {
            return $this->meta[$table];
        }
        $c = $this->dst($table);
        $db = $c->selectOne('select database() d')->d;
        $cols = [];
        foreach ($c->select('select COLUMN_NAME n, IS_NULLABLE nu, EXTRA ex, DATA_TYPE t from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ? order by ORDINAL_POSITION', [$db, $table]) as $r) {
            $cols[$r->n] = ['nullable' => $r->nu === 'YES', 'generated' => str_contains(strtoupper((string) $r->ex), 'GENERATED'), 'type' => $r->t];
        }
        if (! $cols) {
            throw new RuntimeException("Table $table is not in the simulation.");
        }
        $fk = [];
        foreach ($c->select('select COLUMN_NAME n, REFERENCED_TABLE_NAME r from information_schema.KEY_COLUMN_USAGE where TABLE_SCHEMA = ? and TABLE_NAME = ? and REFERENCED_TABLE_NAME is not null', [$db, $table]) as $r) {
            $fk[$r->n] = $r->r;
        }
        $uniq = [];
        foreach ($c->select('select INDEX_NAME i, COLUMN_NAME n from information_schema.STATISTICS where TABLE_SCHEMA = ? and TABLE_NAME = ? and NON_UNIQUE = 0', [$db, $table]) as $r) {
            $uniq[$r->i][] = $r->n;
        }
        $single = collect($uniq)->filter(fn ($v) => count($v) === 1)->map(fn ($v) => $v[0])->reject(fn ($n) => $n === 'id')->values()->all();

        return $this->meta[$table] = ['cols' => $cols, 'fk' => $fk, 'unique' => $single, 'soft' => isset($cols['deleted_at'])];
    }

    private function baseTables(): array
    {
        return $this->baseTables ??= array_merge(
            DB::connection('mysql')->table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::connection('mysql')->selectOne('select database() d')->d)->where('TABLE_TYPE', 'BASE TABLE')->pluck('TABLE_NAME')->all(),
            SimulationCatalog::accountsTables()
        );
    }

    /**
     * What a column of a table points at: ['user'] a person, ['account'], ['table', name], or ['none'] when the link is not carried over.
     */
    public function link(string $table, string $col, ?array $row = null): array
    {
        if ($col === 'id') {
            return ['plain'];
        }
        $poly = SimulationCatalog::polymorphic()[$table] ?? null;
        if ($poly && $col === $poly['id']) {
            if ($row === null) {
                return ['poly', array_values(array_unique($poly['map']))];
            }
            $target = $poly['map'][$row[$poly['type']] ?? ''] ?? null;

            return $target ? ['table', $target] : ['none'];
        }
        if ($col === 'account_id') {
            return ['account'];
        }
        $links = SimulationCatalog::links()[$table] ?? [];
        if (array_key_exists($col, $links)) {
            return $links[$col] === null ? ['none'] : ($links[$col] === '@user' ? ['user'] : ['table', $links[$col]]);
        }
        if (preg_match('/(^user_id$|_user_id$|_by$|_by_id$)/', $col)) {
            return ['user'];
        }
        $fk = $this->meta($table)['fk'][$col] ?? null;
        if ($fk) {
            return $fk === 'users' ? ['user'] : ($fk === 'accounts' ? ['account'] : ['table', $fk]);
        }
        if (! str_ends_with($col, '_id')) {
            return ['plain'];
        }
        $base = substr($col, 0, -3);
        $best = null;
        foreach ($this->baseTables() as $t) {
            foreach (array_unique([Str::singular($t), $t]) as $name) {
                if ($base === $name || str_ends_with($base, '_' . $name)) {
                    if ($best === null || strlen($name) > $best[1]) {
                        $best = [$t, strlen($name)];
                    }
                }
            }
        }

        return $best && $best[0] !== 'users' ? ['table', $best[0]] : ($best ? ['user'] : ['none']);
    }

    // ------------------------------------------------------------ provenance lookups

    /** the simulation id of a production record that was imported before, or null */
    private function mapped(string $table, int $sourceId): ?int
    {
        // one read of the provenance of a table per plan, instead of one query per record
        $this->mapCache[$table] ??= DB::table('simulation_provenance')->where('table_name', $table)->whereIn('state', ['imported', 'modified'])->whereNotNull('source_id')->pluck('sim_id', 'source_id')->map(fn ($v) => (int) $v)->all();

        return $this->mapCache[$table][$sourceId] ?? null;
    }

    private function simHas(string $table, $id): bool
    {
        $this->simCache[$table] ??= array_flip($this->dst($table)->table($table)->pluck('id')->all());

        return isset($this->simCache[$table][(int) $id]);
    }

    // ------------------------------------------------------------ searching production (read only)

    public function search(string $module, string $q = '', ?string $filter = null, int $page = 1, int $per = 25): array
    {
        $m = SimulationCatalog::modules()[$module] ?? abort(404);
        $table = $m['table'];
        $query = $this->src($table)->table($table);
        if ($this->meta($table)['soft']) {
            $query->whereNull('deleted_at');
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($m, $like) {
                foreach ($m['search'] as $col) {
                    $w->orWhere($col, 'like', $like);
                }
            });
        }
        if ($filter !== null && $filter !== '' && $m['filter']) {
            $query->where($m['filter']['column'], $filter);
        }
        $total = (clone $query)->count();
        $cols = array_values(array_unique(array_merge(['id', $m['title']], $m['sub'])));
        $rows = $query->orderByDesc('id')->forPage(max(1, $page), $per)->get($cols);
        $done = DB::table('simulation_provenance')->where('table_name', $table)->whereIn('source_id', $rows->pluck('id'))->whereIn('state', ['imported', 'modified'])->pluck('sim_id', 'source_id');
        $filters = $m['filter'] ? $this->src($table)->table($table)->when($this->meta($table)['soft'], fn ($x) => $x->whereNull('deleted_at'))->whereNotNull($m['filter']['column'])->distinct()->orderBy($m['filter']['column'])->limit(60)->pluck($m['filter']['column'])->all() : [];

        return ['total' => $total, 'page' => $page, 'per' => $per, 'filters' => $filters, 'rows' => $rows->map(fn ($r) => [
            'id' => $r->id, 'title' => (string) ($r->{$m['title']} ?? ('#' . $r->id)), 'sub' => collect($m['sub'])->map(fn ($c) => $r->$c ?? null)->filter(fn ($v) => $v !== null && $v !== '')->values()->all(), 'imported' => isset($done[$r->id]), 'sim_id' => $done[$r->id] ?? null,
        ])->all()];
    }

    /** what one production record looks like (personal details masked), so a person can check it is the right one before importing */
    public function preview(string $module, int $id): array
    {
        $m = SimulationCatalog::modules()[$module] ?? abort(404);
        $table = $m['table'];
        $row = $this->src($table)->table($table)->where('id', $id)->first();
        abort_unless($row, 404);
        $personal = $m['personal'] ?? [];
        $facts = [];
        foreach ((array) $row as $k => $v) {
            if (in_array($k, ['extra_attributes', 'password', 'remember_token', 'deleted_at'], true) || $v === null || $v === '' || (is_string($v) && strlen($v) > 300)) {
                continue;
            }
            $facts[] = ['key' => $k, 'value' => in_array($k, $personal, true) ? '(personal detail, masked on import)' : (string) $v];
        }
        $kids = [];
        foreach (SimulationCatalog::children()[$table] ?? [] as [$ct, $fk, $label]) {
            $kids[] = ['label' => __($label), 'count' => (int) $this->src($ct)->table($ct)->where($fk, $id)->when($this->meta($ct)['soft'], fn ($x) => $x->whereNull('deleted_at'))->count()];
        }

        return ['facts' => array_slice($facts, 0, 40), 'children' => $kids];
    }

    // ------------------------------------------------------------ planning

    /**
     * Work out everything an import would bring in, without writing anything.
     *
     * @param array{optional?:array,children?:array,files?:bool,mask?:bool} $opt
     */
    public function plan(string $module, array $ids, array $opt = []): array
    {
        $m = SimulationCatalog::modules()[$module] ?? abort(404);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            throw new RuntimeException(__('Choose at least one record.'));
        }
        if (count($ids) > (int) config('simulation.import_batch_limit', 2000)) {
            throw new RuntimeException(__('Too many records for one import (the limit is :n).', ['n' => config('simulation.import_batch_limit', 2000)]));
        }

        return $this->planRoots($module, [$m['table'] => $ids], $opt);
    }

    /**
     * The plan for several kinds of record at once: $roots is [table => ids]. (The Import Centre always starts from one chosen kind; the full import starts from many.)
     */
    public function planRoots(string $label, array $roots, array $opt = []): array
    {
        $this->rows = [];
        $this->mapCache = [];
        $this->simCache = [];
        $role = [];            // table => [id => selected|required|optional|child]
        $dups = [];            // table => [id => sim id]  already in the simulation
        $optional = [];        // table => [id => true]    could be brought in, not asked for
        $dropped = [];         // column notes
        $warnings = [];
        $blocked = [];
        $queue = [];
        foreach ($roots as $rt => $rids) {
            $queue[] = [$rt, array_values(array_unique(array_map('intval', $rids))), 'selected'];
        }
        $ids = array_merge(...array_values($roots) ?: [[]]);
        $maxRows = (int) ($opt['max_rows'] ?? self::MAX_ROWS);
        $total = 0;
        $reference = SimulationCatalog::reference();
        $childOpt = $opt['children'] ?? [];
        while ($queue) {
            [$t, $want, $why] = array_shift($queue);
            $want = array_values(array_filter($want, fn ($i) => ! isset($role[$t][$i]) && ! isset($dups[$t][$i])));
            if (! $want) {
                continue;
            }
            $meta = $this->meta($t);
            foreach (array_chunk($want, 500) as $chunk) {
                $found = $this->src($t)->table($t)->whereIn('id', $chunk)->when($meta['soft'], fn ($x) => $x->whereNull('deleted_at'))->get();
                foreach (array_diff($chunk, $found->pluck('id')->all()) as $gone) {
                    $warnings[] = __(':t #:i is not in production (or was deleted) and was skipped.', ['t' => $t, 'i' => $gone]);
                }
                foreach ($found as $row) {
                    $row = (array) $row;
                    if ($sim = $this->mapped($t, (int) $row['id'])) {
                        $dups[$t][(int) $row['id']] = $sim;   // already imported: reused, not copied again (its children may still be brought in)
                        $this->rows[$t][(int) $row['id']] = $row;
                        $this->queueChildren($queue, $t, [(int) $row['id']], $childOpt);

                        continue;
                    }
                    $role[$t][(int) $row['id']] = $why;
                    $this->rows[$t][(int) $row['id']] = $row;
                    if (++$total > $maxRows) {
                        throw new RuntimeException(__('This import would bring in more than :n records. Choose fewer.', ['n' => $maxRows]));
                    }
                    foreach ($row as $col => $val) {
                        if ($val === null || $col === 'id') {
                            continue;
                        }
                        $l = $this->link($t, $col, $row);
                        if ($l[0] === 'none' && str_ends_with($col, '_id')) {
                            if (! ($meta['cols'][$col]['nullable'] ?? true)) {
                                $blocked[] = __(':t.:c is required but its link cannot be carried over.', ['t' => $t, 'c' => $col]);
                            }
                            $dropped[$t . '.' . $col] = true;

                            continue;
                        }
                        if ($l[0] !== 'table') {
                            continue;
                        }
                        [, $rt] = $l;
                        $rv = (int) $val;
                        if ($this->mapped($rt, $rv) || (in_array($rt, $reference, true) && $this->simHas($rt, $rv)) || ($rt === $t && false)) {
                            continue;
                        }
                        $required = ! ($meta['cols'][$col]['nullable'] ?? true);
                        if ($required || ($opt['optional'][$rt] ?? false) || ! empty($opt['optional_all'])) {
                            $queue[] = [$rt, [$rv], $required ? 'required' : 'optional'];
                        } else {
                            $optional[$rt][$rv] = true;
                        }
                    }
                    $this->queueChildren($queue, $t, [(int) $row['id']], $childOpt);
                }
            }
        }
        // a record whose REQUIRED parent no longer exists in production (deleted there) cannot be copied meaningfully: it is left out, and so is whatever depends on it
        $orphans = $this->pruneOrphans($role);
        foreach ($orphans as $ot => $on) {
            $warnings[] = __(':n :t record(s) were left out because the record they belong to no longer exists in production.', ['n' => $on, 't' => $ot]);
        }
        $total = array_sum(array_map('count', $role));
        // children found for the same parent in many small steps are fine; now the numbers
        $tables = [];
        foreach ($role as $t => $byId) {
            $kinds = array_count_values($byId);
            $tables[] = ['table' => $t, 'label' => $this->label($t), 'count' => count($byId), 'selected' => $kinds['selected'] ?? 0, 'required' => $kinds['required'] ?? 0, 'optional' => $kinds['optional'] ?? 0, 'child' => $kinds['child'] ?? 0];
        }
        $childChoices = [];
        foreach ($this->childTables(array_keys($role) + array_keys($dups)) as [$pt, $ct, $fk, $label, $def]) {
            $ids = array_keys(($role[$pt] ?? []) + ($dups[$pt] ?? []));
            $n = $ids ? (int) $this->src($ct)->table($ct)->whereIn($fk, $ids)->when($this->meta($ct)['soft'], fn ($x) => $x->whereNull('deleted_at'))->count() : 0;
            $childChoices[$ct] = ['table' => $ct, 'label' => __($label), 'count' => $n, 'included' => (bool) ($childOpt[$ct] ?? $def), 'of' => $this->label($pt)];
        }

        return [
            'module' => $label, 'roots' => count($ids), 'total' => $total, 'tables' => $tables, 'duplicates' => collect($dups)->map(fn ($v, $t) => ['table' => $t, 'label' => $this->label($t), 'count' => count($v)])->values()->all(),
            'optional' => collect($optional)->map(fn ($v, $t) => ['table' => $t, 'label' => $this->label($t), 'count' => count($v), 'included' => (bool) ($opt['optional'][$t] ?? false)])->values()->all(),
            'children' => array_values($childChoices), 'dropped' => array_keys($dropped), 'warnings' => array_slice($warnings, 0, 30), 'blocked' => array_values(array_unique($blocked)),
            '_role' => $role, '_dups' => $dups,
        ];
    }

    /** @return array<string,int> how many records of each table were removed from the plan */
    private function pruneOrphans(array &$role): array
    {
        $removed = [];
        $reference = SimulationCatalog::reference();
        do {
            $changed = false;
            foreach ($role as $t => $byId) {
                $meta = $this->meta($t);
                foreach ($byId as $id => $why) {
                    $row = $this->rows[$t][$id];
                    foreach ($row as $col => $val) {
                        if ($val === null || $col === 'id' || ($meta['cols'][$col]['nullable'] ?? true)) {
                            continue;
                        }
                        $l = $this->link($t, $col, $row);
                        if ($l[0] !== 'table') {
                            continue;
                        }
                        [, $rt] = $l;
                        $rv = (int) $val;
                        if (isset($role[$rt][$rv]) || $this->mapped($rt, $rv) || (in_array($rt, $reference, true) && $this->simHas($rt, $rv))) {
                            continue;
                        }
                        unset($role[$t][$id]);
                        $removed[$t] = ($removed[$t] ?? 0) + 1;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return $removed;
    }

    private function queueChildren(array &$queue, string $table, array $parentIds, array $childOpt): void
    {
        foreach (SimulationCatalog::children()[$table] ?? [] as [$ct, $fk, , $def]) {
            if (! ($childOpt[$ct] ?? $def)) {
                continue;
            }
            $ids = $this->src($ct)->table($ct)->whereIn($fk, $parentIds)->when($this->meta($ct)['soft'], fn ($x) => $x->whereNull('deleted_at'))->pluck('id')->map(fn ($i) => (int) $i)->all();
            if ($ids) {
                $queue[] = [$ct, $ids, 'child'];
            }
        }
    }

    private function childTables(array $parents): array
    {
        $out = [];
        foreach ($parents as $p) {
            foreach (SimulationCatalog::children()[$p] ?? [] as [$ct, $fk, $label, $def]) {
                $out[] = [$p, $ct, $fk, $label, $def];
            }
        }

        return $out;
    }

    private function label(string $table): string
    {
        foreach (SimulationCatalog::modules() as $m) {
            if ($m['table'] === $table) {
                return __($m['label']);
            }
        }

        return Str::headline($table);
    }

    // ------------------------------------------------------------ running

    /**
     * Do it: bring the planned records in, in one transaction (all or nothing), with a batch record and provenance.
     *
     * @return array{batch:int,reference:string,summary:array}
     */
    public function run(string $module, array $ids, array $opt = []): array
    {
        return $this->runPlan($this->plan($module, $ids, $opt), $module, ['ids' => array_values($ids), 'options' => $opt], $opt);
    }

    /** The same, from several kinds of record at once ([table => ids]); used by the full import. */
    public function runRoots(string $label, array $roots, array $opt = []): array
    {
        return $this->runPlan($this->planRoots($label, $roots, $opt), $label, ['roots' => count($roots), 'options' => $opt], $opt);
    }

    private function runPlan(array $plan, string $module, array $selection, array $opt): array
    {
        if ($plan['blocked']) {
            throw new RuntimeException(implode(' ', $plan['blocked']));
        }
        $batchId = DB::table('simulation_import_batches')->insertGetId(['reference' => $this->batchReference(), 'module' => mb_substr($module, 0, 60), 'user_id' => $this->userId, 'status' => 'running', 'selection' => json_encode($selection), 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $main = DB::connection('mysql');
        $acct = DB::connection('accounts');
        $main->beginTransaction();
        $acct->beginTransaction();
        try {
            $summary = $this->write($plan, $batchId, $opt);
            $acct->commit();
            $main->commit();
        } catch (\Throwable $e) {
            $acct->rollBack();
            $main->rollBack();
            DB::table('simulation_import_batches')->where('id', $batchId)->update(['status' => 'failed', 'problems' => json_encode([$e->getMessage()]), 'finished_at' => now(), 'updated_at' => now()]);
            throw $e;
        }
        DB::table('simulation_import_batches')->where('id', $batchId)->update(['status' => 'done', 'summary' => json_encode($summary), 'problems' => json_encode($plan['warnings']), 'finished_at' => now(), 'updated_at' => now()]);
        $ref = (string) DB::table('simulation_import_batches')->where('id', $batchId)->value('reference');

        return ['batch' => $batchId, 'reference' => $ref, 'summary' => $summary];
    }

    private function batchReference(): string
    {
        $prefix = 'SIM-IMP-' . now()->format('Y') . '-';
        $max = (int) DB::table('simulation_import_batches')->where('reference', 'like', $prefix . '%')->selectRaw('MAX(CAST(SUBSTRING(reference, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) m')->value('m');

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function write(array $plan, int $batchId, array $opt): array
    {
        $role = $plan['_role'];
        $map = [];                              // table => [source id => simulation id]
        foreach ($plan['_dups'] as $t => $byId) {
            foreach ($byId as $src => $sim) {
                $map[$t][$src] = $sim;
            }
        }
        $tables = $this->insertionOrder(array_keys($role));
        $deferred = [];                         // [table, simId, column, refTable, sourceId]
        $inserted = [];                         // table => [simId...]
        $counts = [];
        $mask = (bool) ($opt['mask'] ?? true);
        $personal = collect(SimulationCatalog::modules())->mapWithKeys(fn ($m) => [$m['table'] => $m['personal'] ?? []])->all();
        $reference = SimulationCatalog::reference();
        foreach ($tables as $t) {
            $meta = $this->meta($t);
            $c = $this->dst($t);
            foreach ($role[$t] as $srcId => $why) {
                $row = $this->rows[$t][$srcId];
                $new = [];
                foreach ($row as $col => $val) {
                    if ($col === 'id' || ($meta['cols'][$col]['generated'] ?? false) || ! isset($meta['cols'][$col])) {
                        continue;
                    }
                    $l = $this->link($t, $col, $row);
                    $nullable = $meta['cols'][$col]['nullable'];
                    switch ($l[0]) {
                        case 'user':
                            $new[$col] = $val === null ? null : (($t === 'staffs' && $col === 'user_id') ? null : $this->userId);
                            break;
                        case 'none':
                            $new[$col] = str_ends_with($col, '_id') ? null : $val;
                            break;
                        case 'table':
                            if ($val === null) {
                                $new[$col] = null;
                                break;
                            }
                            $rt = $l[1];
                            $rv = (int) $val;
                            if (isset($map[$rt][$rv])) {
                                $new[$col] = $map[$rt][$rv];
                            } elseif (isset($role[$rt][$rv])) {
                                // not inserted yet (same table, or a loop): put it in once both exist
                                $new[$col] = null;
                                $deferred[] = [$t, $srcId, $col, $rt, $rv];
                            } elseif (in_array($rt, $reference, true) && $this->simHas($rt, $rv)) {
                                $new[$col] = $rv;
                            } else {
                                $new[$col] = null;                       // a link that was not asked for is not carried over
                            }
                            break;
                        default:
                            $new[$col] = ($mask && in_array($col, $personal[$t] ?? [], true)) ? $this->mask($col, $val, $srcId) : $val;
                    }
                    if ($new[$col] === null && ! $nullable && $l[0] !== 'table') {
                        $new[$col] = $this->fallback($meta['cols'][$col]['type']);
                    }
                }
                $new = $this->makeUnique($c, $t, $meta, $new, $batchId);
                // a link filled in later must be allowed to be empty for now
                $simId = (int) $c->table($t)->insertGetId($new);
                $map[$t][$srcId] = $simId;
                $inserted[$t][] = $simId;
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }
        foreach ($deferred as [$t, $srcId, $col, $rt, $rv]) {
            if (isset($map[$t][$srcId], $map[$rt][$rv])) {
                $this->dst($t)->table($t)->where('id', $map[$t][$srcId])->update([$col => $map[$rt][$rv]]);
            }
        }
        // provenance: the record as it now stands is its baseline
        $by = $this->userId;
        foreach ($inserted as $t => $simIds) {
            $srcOf = array_flip($map[$t]);
            foreach (array_chunk($simIds, 300) as $chunk) {
                $now = $this->dst($t)->table($t)->whereIn('id', $chunk)->get()->keyBy('id');
                DB::table('simulation_provenance')->insert(collect($chunk)->map(fn ($sid) => ['table_name' => $t, 'sim_id' => $sid, 'state' => 'imported', 'source_id' => $srcOf[$sid] ?? null, 'batch_id' => $batchId, 'baseline' => json_encode($now[$sid] ?? null),
                    'created_by' => $by, 'created_at' => now(), 'updated_at' => now()])->all());
            }
        }
        $files = ($opt['files'] ?? false) ? $this->copyFiles($map, $inserted) : ['files' => 0, 'skipped' => 0];

        return ['rows' => $counts, 'duplicates' => collect($plan['duplicates'])->pluck('count', 'table')->all(), 'files' => $files, 'total' => array_sum($counts)];
    }

    /** tables in the order they can be filled: what a table points at comes before it (a loop is broken by filling that link afterwards) */
    private function insertionOrder(array $tables): array
    {
        $deps = [];
        foreach ($tables as $t) {
            $deps[$t] = [];
            foreach (array_keys($this->meta($t)['cols']) as $col) {
                $l = $this->link($t, $col);
                foreach ($l[0] === 'table' ? [$l[1]] : ($l[0] === 'poly' ? $l[1] : []) as $target) {
                    if ($target !== $t && in_array($target, $tables, true)) {
                        $deps[$t][$target] = true;
                    }
                }
            }
        }
        $order = [];
        while ($deps) {
            $ready = array_keys(array_filter($deps, fn ($d) => ! array_diff_key($d, array_flip($order))));
            if (! $ready) {
                $ready = [array_key_first($deps)];          // a loop: take one, its link to a later table is filled in afterwards
            }
            foreach ($ready as $t) {
                $order[] = $t;
                unset($deps[$t]);
            }
        }

        return $order;
    }

    private function fallback(string $type)
    {
        return in_array($type, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'], true) ? 0 : (in_array($type, ['date'], true) ? now()->toDateString() : (in_array($type, ['datetime', 'timestamp'], true) ? now() : ''));
    }

    private function mask(string $col, $val, int $srcId)
    {
        if ($val === null || $val === '') {
            return $val;
        }
        if (str_contains($col, 'email')) {
            return 'record' . $srcId . '@simulation.invalid';
        }
        if (str_contains($col, 'phone') || str_contains($col, 'mobile') || str_contains($col, 'contact_no')) {
            return '+255 700 ' . str_pad((string) ($srcId % 1000), 3, '0', STR_PAD_LEFT) . ' ' . str_pad((string) (($srcId * 7) % 1000), 3, '0', STR_PAD_LEFT);
        }
        if ($col === 'photo') {
            return null;
        }
        if (str_contains($col, 'date_of_birth')) {
            return '1990-01-01';
        }

        return '(masked)';
    }

    /** a value that must be unique and already exists in the simulation gets a short suffix, so the copy never collides with something else */
    private function makeUnique(ConnectionInterface $c, string $t, array $meta, array $row, int $batchId): array
    {
        foreach ($meta['unique'] as $col) {
            if (! isset($row[$col]) || $row[$col] === '' || ! is_string($row[$col])) {
                continue;
            }
            $v = $row[$col];
            $n = 0;
            while ($c->table($t)->where($col, $v)->exists()) {
                $v = $row[$col] . '-S' . $batchId . ($n ? '-' . $n : '');
                $n++;
            }
            $row[$col] = $v;
        }

        return $row;
    }

    /** the documents of the imported records, copied into the simulation's own file folder (never touching production files) */
    private function copyFiles(array $map, array $inserted): array
    {
        $class = collect(SimulationCatalog::modules())->filter(fn ($m) => $m['class'])->mapWithKeys(fn ($m) => [$m['table'] => $m['class']])->merge(SimulationCatalog::attachmentClasses());
        $n = 0;
        $skipped = 0;
        // attachments imported as records in their own right (the full import): only their files are copied here
        foreach ($inserted['attachments'] ?? [] as $sid) {
            $a = DB::table('attachments')->where('id', $sid)->first();
            $from = $a ? rtrim((string) Environment::productionDiskRoot((string) $a->disk), '/\\') . DIRECTORY_SEPARATOR . $a->filepath : null;
            $to = $a ? rtrim((string) config('filesystems.disks.' . $a->disk . '.root'), '/\\') . DIRECTORY_SEPARATOR . $a->filepath : null;
            if ($a && is_file($from) && filesize($from) <= 25 * 1024 * 1024) {
                @mkdir(dirname($to), 0775, true);
                @copy($from, $to) ? $n++ : $skipped++;
            } else {
                $skipped++;
            }
        }
        foreach ($inserted as $t => $simIds) {
            if (isset($inserted['attachments']) || ! isset($class[$t])) {
                continue;
            }
            $srcOf = array_flip($map[$t]);
            $atts = DB::connection('mysql_production_ro')->table('attachments')->where('model_type', $class[$t])->whereIn('model_id', array_values($srcOf))->get();
            foreach ($atts as $a) {
                $simId = $map[$t][$a->model_id] ?? null;
                $from = rtrim((string) Environment::productionDiskRoot((string) $a->disk), '/\\') . DIRECTORY_SEPARATOR . $a->filepath;
                if (! $simId || ! is_file($from) || filesize($from) > 25 * 1024 * 1024) {
                    $skipped++;

                    continue;
                }
                $to = rtrim((string) config('filesystems.disks.' . $a->disk . '.root'), '/\\') . DIRECTORY_SEPARATOR . $a->filepath;
                @mkdir(dirname($to), 0775, true);
                if (! @copy($from, $to)) {
                    $skipped++;

                    continue;
                }
                $row = (array) $a;
                unset($row['id']);
                $row['uuid'] = (string) Str::uuid();
                $row['model_id'] = $simId;
                DB::table('attachments')->insert($row);
                $n++;
            }
        }

        return ['files' => $n, 'skipped' => $skipped];
    }
}
