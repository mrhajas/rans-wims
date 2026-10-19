<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use App\Models\Checkin;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImportStoreData extends Command
{
    protected $signature = 'import:storedata
        {--file=/tmp/storedata.json : Path to the parsed StoreData JSON}
        {--dry-run : Roll everything back at the end and only report}
        {--as=1 : User id to run as (account/user context)}
        {--contact=1 : Contact (supplier) id for the check-ins}';

    protected $description = 'Import StoreData.xlsx as items + per-warehouse check-ins (documents only, no stock events)';

    /** Sheet unit text => DB unit code (matched case-insensitively) */
    private array $unitMap = [
        'bags'  => 'bags',
        'tons'  => 'tons',
        'pcs'   => 'pcs',
        'box'   => 'box',
        'rolls' => 'rolls',
        'drum'  => 'drum',
        'kg'    => 'kg',
        'sqm'   => 'sqm',
    ];

    private function norm(?string $s): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $s)));
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $path = $this->option('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }
        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows) || ! count($rows)) {
            $this->error('No rows parsed from JSON.');
            return self::FAILURE;
        }

        $user = User::find((int) $this->option('as'));
        if (! $user) {
            $this->error('Run-as user not found.');
            return self::FAILURE;
        }
        Auth::login($user);
        $accountId = $user->account_id;

        $this->info(($dry ? '[DRY RUN] ' : '[LIVE] ') . 'Storing ' . count($rows) . " rows as {$user->name} (account {$accountId}), documents only, no stock events");

        $stats = [
            'units_created' => 0, 'items_created' => 0, 'items_matched' => 0,
            'checkins_created' => 0, 'lines_created' => 0, 'rows_zero_qty' => 0,
        ];
        $createdItems = [];

        try {
            DB::transaction(function () use ($rows, $accountId, &$stats, &$createdItems, $dry) {

                // Idempotency guard: don't double-load
                if (Checkin::where('details', 'Imported from StoreData.xlsx')->exists()) {
                    throw new \RuntimeException('An import batch already exists (checkins with details "Imported from StoreData.xlsx"). Aborting to avoid duplicates.');
                }

                // 1) Ensure SQM unit (matched case-insensitively)
                if (! Unit::whereRaw('LOWER(code) = ?', ['sqm'])->exists()) {
                    Unit::create(['name' => 'Square Metre', 'code' => 'sqm', 'account_id' => $accountId]);
                    $stats['units_created']++;
                }
                $units = Unit::all()->keyBy(fn ($u) => mb_strtolower($u->code));

                // 2) Existing items keyed by normalized name
                $items = Item::all()->keyBy(fn ($i) => $this->norm($i->name));
                $maxCode = (int) (Item::where('code', 'like', 'RSI-%')
                    ->selectRaw('MAX(CAST(SUBSTRING(code,5) AS UNSIGNED)) n')->value('n') ?? 0);

                // 3) Warehouses by name
                $warehouses = Warehouse::all()->keyBy(fn ($w) => $this->norm($w->name));

                // Resolve rows, create missing items, group by warehouse
                $maxRsc = (int) (Checkin::withTrashed()->where('reference', 'like', 'RSC-%')
                    ->selectRaw('MAX(CAST(SUBSTRING(reference,5) AS UNSIGNED)) n')->value('n') ?? 0);

                $grouped = [];
                foreach ($rows as $r) {
                    $whNorm = $this->norm($r['warehouse']);
                    if (! isset($warehouses[$whNorm])) {
                        throw new \RuntimeException("Warehouse not found: {$r['warehouse']}");
                    }
                    $uCode = $this->unitMap[mb_strtolower(trim($r['unit']))] ?? null;
                    if (! $uCode || ! isset($units[$uCode])) {
                        throw new \RuntimeException("Unit not mappable: {$r['unit']}");
                    }
                    $unit = $units[$uCode];

                    $nameNorm = $this->norm($r['name']);
                    if (isset($items[$nameNorm])) {
                        $item = $items[$nameNorm];
                        $stats['items_matched']++;
                    } else {
                        $maxCode++;
                        $item = Item::create([
                            'name'           => trim($r['name']),
                            'code'           => 'RSI-' . str_pad((string) $maxCode, 4, '0', STR_PAD_LEFT),
                            'unit_id'        => $unit->id,
                            'price'          => (float) ($r['price'] ?? 0),
                            'track_quantity' => true,
                            'track_weight'   => false,
                            'has_variants'   => false,
                            'has_serials'    => false,
                            'alert_quantity' => 0,
                            'account_id'     => $accountId,
                        ]);
                        $items[$nameNorm] = $item;
                        $createdItems[] = $item->code . '  ' . $item->name;
                        $stats['items_created']++;
                    }

                    $qty = $r['quantity'];
                    if ($qty === null || $qty === '') {
                        $stats['rows_zero_qty']++;
                        $qty = 0;
                    }

                    $grouped[$whNorm][] = [
                        'item_id'  => $item->id,
                        'unit_id'  => $unit->id,
                        'quantity' => (float) $qty,
                        'price'    => (float) ($r['price'] ?? 0),
                    ];
                }

                // 4) One check-in header per warehouse, then BULK insert line items (no events)
                $now = Carbon::now();
                $today = $now->toDateString();
                foreach ($grouped as $whNorm => $lines) {
                    $wh = $warehouses[$whNorm];
                    $maxRsc++;
                    $checkin = Checkin::create([
                        'date'         => $today,
                        'reference'    => 'RSC-' . str_pad((string) $maxRsc, 4, '0', STR_PAD_LEFT),
                        'draft'        => 0,
                        'contact_id'   => (int) $this->option('contact'),
                        'warehouse_id' => $wh->id,
                        'account_id'   => $accountId,
                        'details'      => 'Imported from StoreData.xlsx',
                    ]);

                    $bulk = array_map(fn ($ln) => [
                        'checkin_id'   => $checkin->id,
                        'item_id'      => $ln['item_id'],
                        'quantity'     => $ln['quantity'],
                        'weight'       => 0,
                        'unit_id'      => $ln['unit_id'],
                        'price'        => $ln['price'],
                        'warehouse_id' => $wh->id,
                        'account_id'   => $accountId,
                        'draft'        => 0,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ], $lines);

                    foreach (array_chunk($bulk, 200) as $chunk) {
                        DB::table('checkin_items')->insert($chunk);
                    }
                    $stats['lines_created'] += count($bulk);
                    $stats['checkins_created']++;
                    $this->line("  {$checkin->reference}  ->  {$wh->name}  (" . count($bulk) . ' lines)');
                }

                if ($dry) {
                    throw new DryRunRollback();
                }
            }, 3);
        } catch (DryRunRollback $e) {
            $this->warn('[DRY RUN] rolled back, nothing was written.');
        } catch (\Throwable $e) {
            $this->error('Aborted (rolled back): ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['metric', 'count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values());
        if ($createdItems) {
            $this->info('New items created: ' . count($createdItems));
        }

        return self::SUCCESS;
    }
}

class DryRunRollback extends \Exception {}
