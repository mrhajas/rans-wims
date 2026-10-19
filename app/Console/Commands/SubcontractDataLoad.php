<?php

namespace App\Console\Commands;

use App\Services\Subcontracts\SubcontractLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Loads the Subcontract data written by `subcontracts:export-data` into THIS database. Safe by design:
 *  - it refuses to run when the database already holds Subcontract agreements (it never merges or overwrites);
 *  - every subcontractor contact is matched by name inside the SubContractors category and reused, and created only when it does not exist yet, so no contact is duplicated;
 *  - a project or user that does not exist here is left empty (reported), never guessed;
 *  - everything runs in one transaction and the counts and the ledger totals are checked against the manifest before it is kept; any difference rolls everything back.
 */
class SubcontractDataLoad extends Command
{
    protected $signature = 'subcontracts:load-data {--path= : folder with the JSON files (default database/data/subcontracts)} {--dry-run : check and report, keep nothing}';

    protected $description = 'Load the exported Subcontract data (agreements, work items, previous payments, project mapping) into this database';

    private array $warn = [];

    public function handle(): int
    {
        $dir = $this->option('path') ?: database_path('data/subcontracts');
        if (! File::exists($dir . '/manifest.json')) {
            $this->error('No manifest.json in ' . $dir);

            return self::FAILURE;
        }
        $manifest = json_decode(File::get($dir . '/manifest.json'), true);
        foreach (SubcontractDataExport::TABLES as $t) {
            if (! Schema::hasTable($t)) {
                $this->error('The table ' . $t . ' is missing: run the migrations first (php artisan migrate or the Update Database utility).');

                return self::FAILURE;
            }
        }
        if (DB::table('subcontract_agreements')->exists()) {
            $this->error('This database already has Subcontract agreements. Nothing was loaded (this command never merges).');

            return self::FAILURE;
        }
        $users = DB::table('users')->pluck('id')->flip()->all();
        $whs = DB::table('warehouses')->pluck('id')->flip()->all();
        $fallbackUser = DB::table('users')->orderBy('id')->value('id');

        DB::beginTransaction();
        try {
            $contactMap = $this->contacts($dir);
            $remap = function (array $row) use ($contactMap, $users, $whs, $fallbackUser) {
                foreach ($row as $k => $v) {
                    if ($v === null) {
                        continue;
                    }
                    if ($k === 'contact_id' || $k === 'beneficiary_contact_id') {
                        $row[$k] = $contactMap[$v] ?? null;
                    } elseif ($k === 'warehouse_id') {
                        if (! isset($whs[$v])) {
                            $this->warn[] = 'project ' . $v . ' does not exist here';
                            $row[$k] = null;
                        }
                    } elseif (preg_match('/(^|_)(user_id|by)$/', $k) || in_array($k, ['administrator_user_id', 'deleted_by', 'confirmed_by_user_id'], true)) {
                        if (! isset($users[$v])) {
                            $row[$k] = null;
                        }
                    }
                }

                return $row;
            };
            foreach (SubcontractDataExport::TABLES as $t) {
                $rows = json_decode(File::get($dir . '/' . $t . '.json'), true) ?: [];
                $nullable = $this->notNullUserColumns($t);
                foreach ($this->chunks($rows) as $chunk) {
                    $chunk = array_map(function ($r) use ($remap, $nullable, $fallbackUser) {
                        $r = $remap($r);
                        foreach ($nullable as $c) {
                            if (array_key_exists($c, $r) && $r[$c] === null) {
                                $r[$c] = $fallbackUser;
                            }
                        }

                        return $r;
                    }, $chunk);
                    DB::table($t)->insert($chunk);
                }
                $this->line(str_pad($t, 36) . count($rows));
            }
            $this->verify($manifest);
            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->info('Dry run: everything checked, nothing kept.');
            } else {
                DB::commit();
                try {
                    log_activity('Subcontract data loaded from the export', ['agreements' => $manifest['tables']['subcontract_agreements'] ?? 0, 'ledger' => $manifest['ledger'] ?? []], null, 'Subcontracts');
                } catch (\Throwable $e) {
                    // no signed-in person when run from the command line or the update utility: the data is already kept
                }
                $this->info('Subcontract data loaded and checked.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Nothing was loaded: ' . $e->getMessage());

            return self::FAILURE;
        }
        foreach (array_count_values($this->warn) as $w => $n) {
            $this->warn($w . ' (' . $n . ' rows)');
        }

        return self::SUCCESS;
    }

    /** batches of rows that stay far below the database's packet limit (a sheet row can hold a lot of text) */
    private function chunks(array $rows): array
    {
        $out = [];
        $cur = [];
        $size = 0;
        foreach ($rows as $r) {
            $n = strlen(json_encode($r));
            if ($cur && ($size + $n > 400000 || count($cur) >= 300)) {
                $out[] = $cur;
                $cur = [];
                $size = 0;
            }
            $cur[] = $r;
            $size += $n;
        }
        if ($cur) {
            $out[] = $cur;
        }

        return $out;
    }

    /** user columns that may not be empty in the table: filled with the first user rather than failing */
    private function notNullUserColumns(string $table): array
    {
        $out = [];
        foreach (DB::select('SHOW COLUMNS FROM `' . $table . '`') as $c) {
            if (preg_match('/(^|_)(user_id|by)$/', $c->Field) && $c->Null === 'NO') {
                $out[] = $c->Field;
            }
        }

        return $out;
    }

    /** @return array<int,int> old contact id => contact id in this database */
    private function contacts(string $dir): array
    {
        $map = [];
        $made = 0;
        foreach (json_decode(File::get($dir . '/contacts.json'), true) ?: [] as $c) {
            $hit = DB::table('contacts')->whereNull('deleted_at')->where('category', $c['category'])->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($c['name']))])->orderBy('id')->value('id');
            if (! $hit) {
                $row = $c;
                unset($row['id']);
                $hit = DB::table('contacts')->insertGetId($row);
                $made++;
            }
            $map[$c['id']] = $hit;
        }
        $this->line(str_pad('contacts (' . $made . ' created, ' . (count($map) - $made) . ' already here)', 36) . count($map));

        return $map;
    }

    private function verify(array $m): void
    {
        foreach ($m['tables'] as $t => $n) {
            if ($t === 'contacts') {
                continue;
            }
            $have = DB::table($t)->count();
            if ($have !== (int) $n) {
                throw new \RuntimeException('Count of ' . $t . ' is ' . $have . ', expected ' . $n);
            }
        }
        $fig = SubcontractLedger::forAgreements(DB::table('subcontract_agreements')->whereNull('deleted_at')->pluck('id')->all());
        foreach (['original', 'revised', 'paid_total', 'commitment_left'] as $k) {
            $sum = collect($fig)->reduce(fn ($c, $f) => bcadd($c, (string) ($f[$k] ?? '0'), 2), '0');
            if (bccomp($sum, (string) $m['ledger'][$k], 2) !== 0) {
                throw new \RuntimeException('Ledger ' . $k . ' is ' . $sum . ', expected ' . $m['ledger'][$k]);
            }
        }
        foreach (['work_items_amount' => ['subcontract_work_items', 'amount'], 'historical_amount' => ['subcontract_historical_payments', 'amount']] as $k => [$t, $c]) {
            $sum = (string) DB::table($t)->whereNull('deleted_at')->sum($c);
            if (bccomp($sum, (string) $m['sums'][$k], 2) !== 0) {
                throw new \RuntimeException($k . ' is ' . $sum . ', expected ' . $m['sums'][$k]);
            }
        }
        $this->info('Counts and ledger totals match the export.');
    }
}
