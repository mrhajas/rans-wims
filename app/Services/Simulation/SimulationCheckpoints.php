<?php

namespace App\Services\Simulation;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scenarios and checkpoints of the simulation.
 *
 * A scenario is a named state of the simulation's records. The simulation holds ONE live state at a time (the current scenario); a checkpoint is a full
 * copy of that state, kept as prefixed tables (sc<N>_...) inside the simulation database itself, so it needs no extra database. Switching scenario saves
 * the current one first, then loads the other. People, roles and sessions, the audit logs and the scenarios list itself are never part of a copy.
 * Nothing here can reach production: it only works inside the simulation's two databases.
 */
class SimulationCheckpoints
{
    /** tables that are not part of a scenario's state */
    private const KEEP = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'sessions', 'migrations', 'password_resets', 'password_reset_tokens', 'personal_access_tokens', 'failed_jobs', 'jobs',
        'login_logs', 'activity_log', 'notifications', 'cache', 'cache_locks', 'simulation_scenarios', 'simulation_checkpoints', 'settings', 'download_print_logs', 'user_section_visits'];

    private static function conns(): array
    {
        return ['main' => DB::connection('mysql'), 'accounts' => DB::connection('accounts')];
    }

    private static function tablesOf(ConnectionInterface $c): array
    {
        $db = $c->selectOne('select database() d')->d;

        return collect($c->select("select TABLE_NAME n from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_TYPE = 'BASE TABLE'", [$db]))->pluck('n')
            ->reject(fn ($t) => in_array($t, self::KEEP, true) || preg_match('/^sc\d+_/', $t))->values()->all();
    }

    public static function current(): ?object
    {
        return DB::table('simulation_scenarios')->where('is_current', 1)->first();
    }

    /** the first scenario exists from the start, so there is always a "current" one */
    public static function ensureCurrent(?int $by = null): object
    {
        $cur = self::current();
        if (! $cur) {
            $id = DB::table('simulation_scenarios')->insertGetId(['name' => 'Main simulation', 'description' => 'The starting scenario.', 'status' => 'active', 'is_current' => 1, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            $cur = DB::table('simulation_scenarios')->find($id);
        }

        return $cur;
    }

    /** copy the live state into a new checkpoint of a scenario */
    public static function save(int $scenarioId, string $name, string $kind = 'manual', ?int $by = null): int
    {
        $cpId = DB::table('simulation_checkpoints')->insertGetId(['scenario_id' => $scenarioId, 'name' => mb_substr($name, 0, 120), 'snapshot_main' => '', 'snapshot_accounts' => '', 'kind' => $kind, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        $prefix = 'sc' . $cpId . '_';
        $n = 0;
        try {
            foreach (self::conns() as $c) {
                $c->statement('SET FOREIGN_KEY_CHECKS=0');
                foreach (self::tablesOf($c) as $t) {
                    $c->statement('CREATE TABLE `' . $prefix . $t . '` LIKE `' . $t . '`');
                    $c->statement('INSERT INTO `' . $prefix . $t . '` SELECT * FROM `' . $t . '`');
                    $n++;
                }
                $c->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        } catch (\Throwable $e) {
            self::dropTables($prefix);
            DB::table('simulation_checkpoints')->where('id', $cpId)->delete();
            throw $e;
        }
        DB::table('simulation_checkpoints')->where('id', $cpId)->update(['snapshot_main' => $prefix, 'snapshot_accounts' => $prefix, 'tables' => $n, 'updated_at' => now()]);

        return $cpId;
    }

    /** replace the live state with a checkpoint's copy (the live state is lost unless it was saved first) */
    public static function restore(int $checkpointId): void
    {
        $cp = DB::table('simulation_checkpoints')->find($checkpointId) ?? throw ValidationException::withMessages(['checkpoint' => __('That checkpoint does not exist.')]);
        $prefix = $cp->snapshot_main;
        foreach (self::conns() as $c) {
            $c->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (self::tablesOf($c) as $t) {
                $has = $c->select('select 1 from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME = ?', [$prefix . $t]);
                $c->statement('DELETE FROM `' . $t . '`');
                if ($has) {
                    $c->statement('INSERT INTO `' . $t . '` SELECT * FROM `' . $prefix . $t . '`');
                }
            }
            $c->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public static function dropCheckpoint(int $checkpointId): void
    {
        $cp = DB::table('simulation_checkpoints')->find($checkpointId);
        if ($cp) {
            self::dropTables($cp->snapshot_main);
            DB::table('simulation_checkpoints')->where('id', $checkpointId)->delete();
        }
    }

    private static function dropTables(string $prefix): void
    {
        if ($prefix === '' || ! preg_match('/^sc\d+_$/', $prefix)) {
            return;
        }
        foreach (self::conns() as $c) {
            foreach ($c->select('select TABLE_NAME n from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME like ?', [str_replace('_', '\\_', $prefix) . '%']) as $r) {
                $c->statement('DROP TABLE IF EXISTS `' . $r->n . '`');
            }
        }
    }

    /** a new scenario: a copy of what is live now (or of another scenario's latest state), with its own baseline checkpoint */
    public static function create(string $name, ?string $description, ?int $by, ?int $fromScenario = null): int
    {
        $cur = self::ensureCurrent($by);
        if ($fromScenario && $fromScenario !== $cur->id) {
            $cp = DB::table('simulation_checkpoints')->where('scenario_id', $fromScenario)->orderByDesc('id')->first();
            throw_unless($cp, ValidationException::withMessages(['from' => __('That scenario has no saved state to copy.')]));
            $sourceCp = $cp->id;
        } else {
            $sourceCp = self::save($cur->id, 'Copied into ' . $name, 'auto', $by);
        }
        $id = DB::table('simulation_scenarios')->insertGetId(['name' => mb_substr($name, 0, 120), 'description' => $description, 'status' => 'active', 'is_current' => 0, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        // the new scenario's first and baseline checkpoint is a copy of that state
        $base = self::cloneCheckpoint($sourceCp, $id, 'Baseline', 'baseline', $by);
        if (! $fromScenario || $fromScenario === $cur->id) {
            self::dropCheckpoint($sourceCp);
        }

        return $id;
    }

    private static function cloneCheckpoint(int $from, int $scenarioId, string $name, string $kind, ?int $by): int
    {
        $src = DB::table('simulation_checkpoints')->find($from);
        $cpId = DB::table('simulation_checkpoints')->insertGetId(['scenario_id' => $scenarioId, 'name' => $name, 'snapshot_main' => '', 'snapshot_accounts' => '', 'kind' => $kind, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        $prefix = 'sc' . $cpId . '_';
        $n = 0;
        foreach (self::conns() as $c) {
            $c->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($c->select('select TABLE_NAME n from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME like ?', [str_replace('_', '\\_', $src->snapshot_main) . '%']) as $r) {
                $t = substr($r->n, strlen($src->snapshot_main));
                $c->statement('CREATE TABLE `' . $prefix . $t . '` LIKE `' . $r->n . '`');
                $c->statement('INSERT INTO `' . $prefix . $t . '` SELECT * FROM `' . $r->n . '`');
                $n++;
            }
            $c->statement('SET FOREIGN_KEY_CHECKS=1');
        }
        DB::table('simulation_checkpoints')->where('id', $cpId)->update(['snapshot_main' => $prefix, 'snapshot_accounts' => $prefix, 'tables' => $n, 'updated_at' => now()]);

        return $cpId;
    }

    /** make another scenario the live one: the current one is saved first, then the other one's latest state is loaded */
    public static function switchTo(int $scenarioId, ?int $by): void
    {
        $cur = self::ensureCurrent($by);
        if ($cur->id === $scenarioId) {
            return;
        }
        $target = DB::table('simulation_scenarios')->where('id', $scenarioId)->where('status', 'active')->first() ?? throw ValidationException::withMessages(['scenario' => __('That scenario cannot be opened.')]);
        $cp = DB::table('simulation_checkpoints')->where('scenario_id', $scenarioId)->orderByDesc('id')->first() ?? throw ValidationException::withMessages(['scenario' => __('That scenario has no saved state.')]);
        self::save($cur->id, 'Left ' . now()->format('d M H:i'), 'auto', $by);
        self::restore($cp->id);
        DB::table('simulation_scenarios')->update(['is_current' => 0]);
        DB::table('simulation_scenarios')->where('id', $scenarioId)->update(['is_current' => 1, 'updated_at' => now()]);
    }

    /** the live state becomes the baseline of the open scenario (the earlier baseline is replaced) */
    public static function saveBaseline(?int $by): int
    {
        $cur = self::ensureCurrent($by);
        $old = DB::table('simulation_checkpoints')->where('scenario_id', $cur->id)->where('kind', 'baseline')->pluck('id');
        $id = self::save($cur->id, 'Baseline', 'baseline', $by);
        foreach ($old as $o) {
            self::dropCheckpoint($o);
        }

        return $id;
    }

    /** put the current scenario back to its baseline */
    public static function resetToBaseline(?int $by): void
    {
        $cur = self::ensureCurrent($by);
        $base = DB::table('simulation_checkpoints')->where('scenario_id', $cur->id)->where('kind', 'baseline')->orderByDesc('id')->first();
        if (! $base) {
            throw ValidationException::withMessages(['scenario' => __('This scenario has no baseline to go back to. Save a checkpoint named Baseline first.')]);
        }
        self::save($cur->id, 'Before reset ' . now()->format('d M H:i'), 'auto', $by);
        self::restore($base->id);
    }

    /** headline figures of a checkpoint, or of the live state when $checkpointId is null, for comparing scenarios */
    public static function figures(?int $checkpointId): array
    {
        $prefix = $checkpointId ? DB::table('simulation_checkpoints')->where('id', $checkpointId)->value('snapshot_main') : '';
        $q = function (string $t, string $conn = 'mysql') use ($prefix) {
            $name = $prefix . $t;

            return DB::connection($conn)->table($name);
        };
        $count = function (string $t, string $conn = 'mysql', bool $soft = true) use ($q) {
            try {
                $x = $q($t, $conn);

                return (int) $x->when($soft, fn ($w) => $w->whereNull('deleted_at'))->count();
            } catch (\Throwable) {
                return null;
            }
        };
        $sum = function (string $t, string $col, string $conn = 'mysql', ?string $by = null, array $where = []) use ($q) {
            try {
                $x = $q($t, $conn)->whereNull('deleted_at');
                foreach ($where as $k => $v) {
                    $x->where($k, $v);
                }

                return $by ? $x->selectRaw("$by c, SUM($col) s")->groupBy($by)->pluck('s', 'c')->map(fn ($v) => (string) $v)->all() : (string) $x->sum($col);
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'counts' => ['Projects' => $count('warehouses'), 'Contacts' => $count('contacts'), 'Subcontract agreements' => $count('subcontract_agreements'), 'Payment vouchers' => $count('payment_vouchers'), 'Certificates' => $count('project_certificates'), 'Labour lists' => $count('labour_lists'),
                'Transport records' => $count('transport_records'), 'Staff' => $count('staffs'), 'Items' => $count('items'), 'Expenses and transactions' => $count('acct_transactions', 'accounts', false)],
            'money' => ['Subcontract contract value' => $sum('subcontract_agreements', 'original_amount', 'mysql', 'currency'), 'Payment voucher total' => $sum('payment_vouchers', 'total_amount'), 'Project budgets' => $sum('warehouses', 'site_budget'),
                'Transport cost' => $sum('transport_records', 'amount')],
        ];
    }
}
