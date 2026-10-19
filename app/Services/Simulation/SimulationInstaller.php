<?php

namespace App\Services\Simulation;

use App\Support\PasswordPolicy;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Builds (and checks) the simulation databases: same structure as production, none of its operational data and none of its users.
 * Run from the command line by someone who has database-administration rights (those rights are used for the installation only and are never stored).
 */
class SimulationInstaller
{
    /** lookup tables that every module needs on day one (kinds, units, categories ...). No people, no money, no documents. */
    public const REFERENCE_TABLES = ['accounts', 'permissions', 'units', 'categories', 'payment_voucher_types', 'case_categories', 'case_priorities', 'asset_types', 'asset_categories', 'asset_currencies',
        'fund_categories', 'fund_payment_methods', 'impersonation_reasons', 'compliance_categories', 'compliance_types', 'compliance_issuing_authorities', 'signing_roles'];

    public const REFERENCE_ACCOUNTS_TABLES = ['acct_currencies', 'acct_categories'];

    /** the only settings copied from production: appearance and numbering, never a key, a password or a gateway */
    public const SAFE_SETTINGS = ['app_name', 'currency_code', 'default_locale', 'fraction', 'language', 'modal_position', 'name', 'per_page', 'reference', 'sidebar', 'sidebar_style', 'version_label',
        'weight_unit', 'track_weight', 'over_selling', 'footer_credit', 'terms_content', 'terms_version'];

    private Connection $admin;

    private string $prodDb;

    private string $prodAcctDb;

    public function __construct(private array $adminLogin)
    {
        $this->prodDb = (string) config('database.connections.mysql.database');
        $this->prodAcctDb = (string) config('database.connections.accounts.database');
    }

    public function simDb(): string
    {
        return (string) config('database.connections.mysql_simulation.database');
    }

    public function simAcctDb(): string
    {
        return (string) config('database.connections.accounts_simulation.database');
    }

    /** Refuse to build anything that could touch production: the simulation databases must be different from it. */
    public function preflight(): void
    {
        if (! config('simulation.enabled')) {
            throw new RuntimeException('Set SIMULATION_ENABLED=true in .env first.');
        }
        foreach ([$this->simDb(), $this->simAcctDb()] as $db) {
            if ($db === '' || in_array($db, [$this->prodDb, $this->prodAcctDb], true)) {
                throw new RuntimeException('The simulation databases must be set (SIM_DB_DATABASE, SIM_DB_ACCOUNTS_DATABASE) and must NOT be the production databases.');
            }
            if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
                throw new RuntimeException("Database name '$db' is not allowed.");
            }
        }
        // one simulation database for everything is fine (the accounts tables are all named acct_*, so nothing can clash); it mirrors production when that has one database too
        if ($this->simDb() === $this->simAcctDb() && $this->prodDb !== $this->prodAcctDb) {
            throw new RuntimeException('Production keeps its accounts in a separate database, so SIM_DB_DATABASE and SIM_DB_ACCOUNTS_DATABASE must be two different databases (or move the accounts into the main database first).');
        }
    }

    private function connect(?string $database): Connection
    {
        $base = config('database.connections.mysql');
        config(['database.connections.sim_admin' => array_replace($base, ['host' => $this->adminLogin['host'] ?? $base['host'], 'port' => $this->adminLogin['port'] ?? $base['port'],
            'username' => $this->adminLogin['username'], 'password' => $this->adminLogin['password'], 'database' => $database])]);
        DB::purge('sim_admin');

        return DB::connection('sim_admin');
    }

    public function createDatabases(): void
    {
        $c = $this->connect(null);
        foreach ([$this->simDb(), $this->simAcctDb()] as $db) {
            $c->statement('CREATE DATABASE IF NOT EXISTS `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    /** @return array{main:int,accounts:int} number of tables created */
    public function cloneStructure(): array
    {
        return ['main' => $this->cloneDb($this->prodDb, $this->simDb()), 'accounts' => $this->cloneDb($this->prodAcctDb, $this->simAcctDb())];
    }

    private function cloneDb(string $from, string $to): int
    {
        $c = $this->connect($to);
        $n = 0;
        $c->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($c->select("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$from]) as $row) {
            $t = $row->t;
            if (collect($c->select("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$to, $t]))->isNotEmpty()) {
                continue;
            }
            $ddl = (array) $c->selectOne('SHOW CREATE TABLE `' . $from . '`.`' . $t . '`');
            $sql = $ddl['Create Table'] ?? null;
            if (! $sql) {
                continue;
            }
            $sql = preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);
            $c->statement($sql);
            $n++;
        }
        $c->statement('SET FOREIGN_KEY_CHECKS=1');

        return $n;
    }

    public function copyReference(): array
    {
        $c = $this->connect($this->simDb());
        $done = [];
        $c->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::REFERENCE_TABLES as $t) {
            if ($this->tableExists($c, $this->prodDb, $t) && $this->tableExists($c, $this->simDb(), $t) && (int) $c->selectOne('SELECT COUNT(*) n FROM `' . $this->simDb() . '`.`' . $t . '`')->n === 0) {
                $c->statement('INSERT INTO `' . $this->simDb() . '`.`' . $t . '` SELECT * FROM `' . $this->prodDb . '`.`' . $t . '`');
                $done[] = $t;
            }
        }
        foreach (self::REFERENCE_ACCOUNTS_TABLES as $t) {
            if ($this->tableExists($c, $this->prodAcctDb, $t) && $this->tableExists($c, $this->simAcctDb(), $t) && (int) $c->selectOne('SELECT COUNT(*) n FROM `' . $this->simAcctDb() . '`.`' . $t . '`')->n === 0) {
                $c->statement('INSERT INTO `' . $this->simAcctDb() . '`.`' . $t . '` SELECT * FROM `' . $this->prodAcctDb . '`.`' . $t . '`');
                $done[] = 'accounts:' . $t;
            }
        }
        // the migrations table, so the simulation only ever runs migrations it does not have yet
        if ((int) $c->selectOne('SELECT COUNT(*) n FROM `' . $this->simDb() . '`.`migrations`')->n === 0) {
            $c->statement('INSERT INTO `' . $this->simDb() . '`.`migrations` SELECT * FROM `' . $this->prodDb . '`.`migrations`');
        }
        $in = "'" . implode("','", self::SAFE_SETTINGS) . "'";
        $c->statement('INSERT IGNORE INTO `' . $this->simDb() . '`.`settings` SELECT * FROM `' . $this->prodDb . '`.`settings` WHERE tec_key IN (' . $in . ')');
        $c->statement('SET FOREIGN_KEY_CHECKS=1');

        return $done;
    }

    private function tableExists(Connection $c, string $db, string $t): bool
    {
        return collect($c->select('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]))->isNotEmpty();
    }

    /** the tables that exist only in the simulation (provenance, scenarios, import batches) */
    public function migrateSimulationOnly(): string
    {
        Artisan::call('migrate', ['--database' => 'mysql_simulation', '--path' => 'database/migrations/simulation', '--realpath' => false, '--force' => true]);

        return trim(Artisan::output());
    }

    /**
     * The code of WIMS looks roles up by name ("Super Admin", "Admin", "Project Manager" ...) and fails when one does not exist. So every production role NAME exists in
     * the simulation too, but empty: no abilities and nobody holds it. Nobody in the simulation is a Super Admin.
     */
    public function copyRoleNames(): int
    {
        $sim = DB::connection('mysql_simulation');
        $have = $sim->table('roles')->pluck('name')->all();
        $n = 0;
        foreach (DB::connection('mysql')->table('roles')->whereNotIn('name', $have)->get() as $r) {
            $row = (array) $r;
            unset($row['id']);
            $sim->table('roles')->insert($row);
            $n++;
        }

        return $n;
    }

    /** The one role every simulation user has: everything a person needs to work in WIMS, minus the abilities that run production itself. */
    public function ensureRole(): array
    {
        $db = DB::connection('mysql_simulation');
        $name = (string) config('simulation.role');
        $role = $db->table('roles')->where('name', $name)->where('guard_name', 'web')->first();
        $roleId = $role->id ?? $db->table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'account_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $never = (array) config('simulation.never_permissions');
        $ids = $db->table('permissions')->where('guard_name', 'web')->whereNotIn('name', $never)->pluck('id')->all();
        $have = $db->table('role_has_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();
        $new = array_diff($ids, $have);
        foreach (array_chunk($new, 500) as $chunk) {
            $db->table('role_has_permissions')->insert(array_map(fn ($p) => ['permission_id' => $p, 'role_id' => $roleId], $chunk));
        }
        // a permission added to production later must never silently reach a simulation role that was told not to have it
        $db->table('role_has_permissions')->where('role_id', $roleId)->whereIn('permission_id', $db->table('permissions')->whereIn('name', $never)->pluck('id'))->delete();

        return ['role' => $name, 'permissions' => count($ids), 'added' => count($new)];
    }

    /** The first simulation user. The password is asked for (or read from the environment) and never printed or stored anywhere but as a hash. */
    public function provisionAdmin(string $username, string $name, string $password, bool $allowWeak = false): int
    {
        $v = Validator::make(['password' => $password, 'username' => $username], ['password' => $allowWeak ? ['required', 'string', 'min:8'] : ['required', PasswordPolicy::rule()], 'username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,40}$/']]);
        if ($v->fails()) {
            throw new RuntimeException(implode(' ', $v->errors()->all()));
        }

        return app(SimulationAccounts::class)->create(['username' => $username, 'name' => $name, 'email' => $username . '@simulation.invalid', 'password' => $password], null, ! $allowWeak);
    }

    public function status(): array
    {
        $out = ['enabled' => (bool) config('simulation.enabled'), 'available' => \App\Support\Simulation\Environment::available()];
        try {
            $c = DB::connection('mysql_simulation');
            $out['main_tables'] = (int) $c->selectOne('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$this->simDb()])->n;
            $out['users'] = (int) $c->table('users')->count();
            $out['role'] = (bool) $c->table('roles')->where('name', config('simulation.role'))->exists();
            $out['production_tables'] = (int) DB::connection('mysql')->selectOne('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$this->prodDb, 'BASE TABLE'])->n;
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }
}
