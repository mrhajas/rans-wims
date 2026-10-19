<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Checkin;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Set-based equivalent of CheckinEventListener::setStock($checkin, false)
 * for the simple case in this import: starting stock 0, no variations,
 * no weight tracking on the lines. Writes stocks + stock_trails in a few
 * bulk queries instead of ~5 round-trips per line.
 */
class ApplyStoreDataStockSet extends Command
{
    protected $signature = 'import:applystockset
        {--reference=RSC-0049 : Check-in reference to apply}
        {--as=1 : User id to run as}
        {--attempts=20 : Retry attempts on connection drop}
        {--dry-run : Roll back after applying}';

    protected $description = 'Apply one check-in\'s stock set-based (faithful to the event engine, idempotent)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $user = User::find((int) $this->option('as'));
        if (! $user) { $this->error('Run-as user not found.'); return self::FAILURE; }
        Auth::login($user);
        $accountId = $user->account_id;

        $checkin = Checkin::where('reference', $this->option('reference'))->first();
        if (! $checkin) { $this->error('Check-in not found.'); return self::FAILURE; }
        $wh = $checkin->warehouse_id;

        // Pull lines in id order (mirrors $checkin->items iteration)
        $lines = DB::table('checkin_items')
            ->where('checkin_id', $checkin->id)
            ->orderBy('id')
            ->get(['id', 'item_id', 'unit_id', 'quantity']);

        $this->info(($dry ? '[DRY RUN] ' : '[LIVE] ') . "Applying {$checkin->reference} (warehouse {$wh}) - {$lines->count()} lines, set-based");

        // Idempotency: already has trails for these lines?
        $alreadyTrails = DB::table('stock_trails')
            ->where('subject_type', 'App\\Models\\CheckinItem')
            ->whereIn('subject_id', $lines->pluck('id'))
            ->count();
        if ($alreadyTrails > 0 && ! $dry) {
            $this->line("  = already applied ({$alreadyTrails} trails exist), nothing to do.");
            return self::SUCCESS;
        }

        // Existing stock rows (variation NULL) for these items at this warehouse
        $itemIds = $lines->pluck('item_id')->unique()->values();
        $existing = DB::table('stocks')
            ->where('warehouse_id', $wh)->whereNull('variation_id')
            ->whereIn('item_id', $itemIds)
            ->pluck('quantity', 'item_id'); // item_id => current qty (all 0 here)
        $hasRow = $existing->keys()->flip(); // item_id => idx

        $now = Carbon::now();
        $running = [];   // item_id => running balance
        $created = [];   // item_id => true once a row is (to be) created this run
        $trails = [];    // rows to insert
        $finalQty = [];  // item_id => final balance

        foreach ($lines as $ln) {
            $base = (float) $ln->quantity; // units have no operator => base == quantity
            $iid = $ln->item_id;
            $rowExists = $hasRow->has($iid) || isset($created[$iid]);

            if (! isset($running[$iid])) {
                $running[$iid] = (float) ($existing[$iid] ?? 0); // 0 here
            }

            if (! $rowExists) {
                // engine "else" branch: always creates row + trail (even qty 0)
                $running[$iid] += $base;
                $created[$iid] = true;
                $trails[] = $this->trail($ln, $iid, $running[$iid], $wh, $accountId, $now);
            } else {
                $new = $running[$iid] + $base;
                // engine writes trail only if quantity (or weight) changed; weight always 0
                if ($new != $running[$iid]) {
                    $running[$iid] = $new;
                    $trails[] = $this->trail($ln, $iid, $running[$iid], $wh, $accountId, $now);
                }
            }
            $finalQty[$iid] = $running[$iid];
        }

        $toUpdate = [];  // existing rows -> new qty
        $toInsert = [];  // new rows
        foreach ($finalQty as $iid => $qty) {
            if ($hasRow->has($iid)) {
                $toUpdate[$iid] = $qty;
            } else {
                $toInsert[] = [
                    'item_id' => $iid, 'warehouse_id' => $wh, 'variation_id' => null,
                    'quantity' => $qty, 'weight' => 0, 'alert_quantity' => 0,
                    'account_id' => $accountId, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        $this->line('  lines=' . $lines->count() . " trails=" . count($trails)
            . " stock_updates=" . count($toUpdate) . " stock_inserts=" . count($toInsert));

        for ($a = 1; $a <= (int) $this->option('attempts'); $a++) {
            try {
                DB::purge(); DB::reconnect();
                DB::transaction(function () use ($trails, $toUpdate, $toInsert, $wh, $dry) {
                    foreach (array_chunk($trails, 200) as $chunk) {
                        DB::table('stock_trails')->insert($chunk);
                    }
                    if ($toInsert) {
                        foreach (array_chunk($toInsert, 200) as $chunk) {
                            DB::table('stocks')->insert($chunk);
                        }
                    }
                    // CASE update for existing rows (chunked)
                    foreach (array_chunk($toUpdate, 200, true) as $chunk) {
                        $ids = array_keys($chunk);
                        $case = 'CASE item_id';
                        $bind = [];
                        foreach ($chunk as $iid => $qty) { $case .= " WHEN ? THEN ?"; $bind[] = $iid; $bind[] = $qty; }
                        $case .= ' END';
                        $in = implode(',', array_fill(0, count($ids), '?'));
                        DB::update(
                            "UPDATE stocks SET quantity = {$case}, updated_at = NOW()
                             WHERE warehouse_id = ? AND variation_id IS NULL AND item_id IN ({$in})",
                            array_merge($bind, [$wh], $ids)
                        );
                    }
                    if ($dry) { throw new \RuntimeException('__DRY__'); }
                });
                $this->info('  APPLIED' . ($dry ? ' (dry, rolled back)' : ''));
                return self::SUCCESS;
            } catch (\Throwable $e) {
                if ($e->getMessage() === '__DRY__') { $this->warn('  [DRY RUN] rolled back.'); return self::SUCCESS; }
                $this->line("  attempt {$a} failed: " . $e->getMessage());
                usleep(500000);
            }
        }
        $this->error('  FAILED after retries.');
        return self::FAILURE;
    }

    private function trail($ln, $iid, $qty, $wh, $accountId, $now): array
    {
        return [
            'subject_type' => 'App\\Models\\CheckinItem',
            'subject_id'   => $ln->id,
            'type'         => 'Updating Checkin Item',
            'weight'       => 0,
            'quantity'     => $qty,
            'item_id'      => $iid,
            'unit_id'      => $ln->unit_id,
            'variation_id' => null,
            'warehouse_id' => $wh,
            'account_id'   => $accountId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
    }
}
