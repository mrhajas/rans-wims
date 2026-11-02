<?php

namespace App\Console\Commands;

use App\Models\MaterialReallocation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sample DRAFT records for trying "remove the draft status and approve" on the stock modules. Today only Material Reallocation has a draft that the
 * prompt applies to (Material Request, Adjustment and Super Adjustment have no blocked draft). Written straight to the tables, so nobody is notified
 * (no SMS, email or bell). Every sample carries SAMPLE in its reference and remarks, so --fresh removes exactly those and nothing else.
 */
class DraftSamplesStock extends Command
{
    protected $signature = 'draft-samples:stock {--fresh : Remove the previous samples first}';

    protected $description = 'Create sample Draft Material Reallocations (one complete, one without items) to try "remove draft and approve"';

    private const REFS = ['MRR-SAMPLE-0001', 'MRR-SAMPLE-0002'];

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $ids = DB::table('material_reallocations')->whereIn('reference', self::REFS)->pluck('id');
            DB::table('material_reallocation_logs')->whereIn('material_reallocation_id', $ids)->delete();
            DB::table('material_reallocation_items')->whereIn('material_reallocation_id', $ids)->delete();
            DB::table('material_reallocations')->whereIn('id', $ids)->delete();
            $this->info('Removed ' . $ids->count() . ' previous sample(s).');
        }

        $admin = User::find(1);
        $main = Warehouse::withoutGlobalScopes()->mainWarehouses()->orderBy('id')->get();
        $dest = Warehouse::withoutGlobalScopes()->whereNotIn('id', $main->pluck('id'))->orderBy('id')->first();
        $items = DB::table('items')->whereNotNull('unit_id')->orderBy('id')->limit(2)->get(['id', 'unit_id']);
        if (! $admin || ! $main->count() || ! $dest || $items->count() < 2) {
            $this->error('Needs user 1, a main warehouse, another warehouse and two items with a unit.');

            return self::FAILURE;
        }

        $made = [];
        foreach (self::REFS as $i => $ref) {
            if (DB::table('material_reallocations')->where('reference', $ref)->exists()) {
                $this->line("{$ref} already exists, left as it is.");

                continue;
            }
            $complete = $i === 0;
            $id = DB::table('material_reallocations')->insertGetId([
                'reference' => $ref,
                'source_warehouse_id' => $main->first()->id,
                'user_id' => $admin->id,
                'status' => MaterialReallocation::STATUS_DRAFT,
                'date_requested' => now()->toDateString(),
                'reason' => MaterialReallocation::reasons()[0],
                'reason_details' => $complete ? 'SAMPLE: complete draft, ready to be cleared for approval.' : 'SAMPLE: draft with no items yet.',
                'remarks' => 'SAMPLE (draft-samples:stock)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($complete) {
                foreach ($items as $n => $item) {
                    DB::table('material_reallocation_items')->insert([
                        'material_reallocation_id' => $id, 'item_id' => $item->id, 'unit_id' => $item->unit_id, 'requested_quantity' => ($n + 1) * 5,
                        'destination_warehouse_id' => $dest->id, 'remarks' => 'SAMPLE', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $made[] = $ref . ($complete ? ' (2 items, complete)' : ' (no items, incomplete)');
        }
        $this->info($made ? 'Created: ' . implode('; ', $made) : 'Nothing new to create.');

        return self::SUCCESS;
    }
}
