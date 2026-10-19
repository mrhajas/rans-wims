<?php

namespace App\Console\Commands;

use App\Services\Subcontracts\SubcontractLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Writes the Subcontract data of this database (agreements, work groups and items, the previous payments, the import record and the project mapping;
 * the Alerts page refills itself from them) with the subcontractor contacts they point to, as plain JSON files in database/data/subcontracts, plus a manifest of counts and ledger totals.
 * `subcontracts:load-data` puts them into another database (the live one) and checks every figure against the manifest.
 */
class SubcontractDataExport extends Command
{
    protected $signature = 'subcontracts:export-data {--path= : folder to write to (default database/data/subcontracts)}';

    protected $description = 'Export the Subcontract data and its contacts to JSON files for loading into another database';

    /** parents first: this is also the order they are loaded in */
    public const TABLES = ['subcontract_import_batches', 'subcontract_import_sheets', 'subcontract_agreements', 'subcontract_work_groups', 'subcontract_work_items', 'subcontract_historical_payments', 'subcontract_project_mappings', 'subcontract_reconciliations', 'subcontract_variations'];

    public function handle(): int
    {
        $dir = $this->option('path') ?: database_path('data/subcontracts');
        File::ensureDirectoryExists($dir);
        foreach (File::glob($dir . '/*.json') as $old) {
            File::delete($old);
        }
        $manifest = ['exported_at' => now()->toDateTimeString(), 'tables' => [], 'ledger' => []];
        foreach (self::TABLES as $t) {
            $rows = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            File::put($dir . '/' . $t . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $manifest['tables'][$t] = count($rows);
            $this->line(str_pad($t, 36) . count($rows));
        }
        // the contacts the data points to (agreement subcontractors, beneficiaries, alert/sheet contacts)
        $ids = collect(DB::table('subcontract_agreements')->pluck('contact_id'))
            ->merge(DB::table('subcontract_work_items')->pluck('beneficiary_contact_id'))->merge(DB::table('subcontract_import_sheets')->pluck('contact_id'))
            ->filter()->unique()->values()->all();
        $contacts = DB::table('contacts')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        File::put($dir . '/contacts.json', json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $manifest['tables']['contacts'] = count($contacts);
        $this->line(str_pad('contacts', 36) . count($contacts));

        $fig = SubcontractLedger::forAgreements(DB::table('subcontract_agreements')->whereNull('deleted_at')->pluck('id')->all());
        foreach (['original', 'revised', 'paid_total', 'commitment_left'] as $k) {
            $manifest['ledger'][$k] = bcadd('0', (string) collect($fig)->reduce(fn ($c, $f) => bcadd($c, (string) ($f[$k] ?? '0'), 2), '0'), 2);
        }
        $manifest['sums'] = [
            'work_items_amount' => (string) DB::table('subcontract_work_items')->whereNull('deleted_at')->sum('amount'),
            'historical_amount' => (string) DB::table('subcontract_historical_payments')->whereNull('deleted_at')->sum('amount'),
        ];
        File::put($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->info('Written to ' . $dir . '. Ledger totals: ' . json_encode($manifest['ledger']));

        return self::SUCCESS;
    }
}
