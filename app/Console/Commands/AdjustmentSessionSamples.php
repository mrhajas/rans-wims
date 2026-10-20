<?php

namespace App\Console\Commands;

use App\Models\AdjustmentSession;
use App\Models\Stock;
use App\Models\SuperAdjustment;
use App\Models\SuperAdjustmentApproval;
use App\Models\SuperAdjustmentItem;
use App\Models\User;
use App\Services\Adjustments\AdjustmentSessionService as Svc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts review samples in the LOCAL database: one OPEN Adjustment Session (two counts made on different days, one approved and one waiting) and one ENDED
 * session (two approved counts and the count that clears the items nobody counted, still waiting for approval). Every sample says SAMPLE. The counts equal the
 * stock, so nothing changes stock and nothing is sent to anybody. --remove takes them away again.
 */
class AdjustmentSessionSamples extends Command
{
    protected $signature = 'adjustments:session-samples {--remove : Remove the samples instead}';

    protected $description = 'Add (or remove) sample Adjustment Sessions with their Super Adjustments';

    public function handle(): int
    {
        $this->remove();
        if ($this->option('remove')) {
            $this->info('Samples removed.');

            return self::SUCCESS;
        }

        $first = User::role('Super Admin')->where('is_active', true)->orderBy('id')->first();
        $second = User::role('Super Admin')->where('is_active', true)->where('id', '<>', optional($first)->id)->orderBy('id')->first();
        if (! $first) {
            $this->error('There is no active Super Admin.');

            return self::FAILURE;
        }

        // two warehouses with plenty of stocked items
        $whs = Stock::main()->where('quantity', '>', 0)->select('warehouse_id', DB::raw('COUNT(*) n'))->groupBy('warehouse_id')->orderByDesc('n')->limit(2)->pluck('n', 'warehouse_id');
        if ($whs->count() < 2) {
            $this->error('Two warehouses with stock are needed.');

            return self::FAILURE;
        }
        [$open, $ended] = $whs->keys()->all();

        DB::transaction(function () use ($open, $ended, $first, $second) {
            // ---------- OPEN session: started 3 days ago, day 1 approved, day 2 waiting
            $s1 = $this->session($open, $first, now()->subDays(3)->setTime(8, 30), 'SAMPLE - Godown count (open)', 'Counting over several days. Sample for review.');
            $d1 = $this->count($s1, $this->pick($open, 0, 10), 'SAMPLE: day 1 count', SuperAdjustment::STATUS_APPROVED, $first, $second, now()->subDays(2)->setTime(15, 10));
            $d2 = $this->count($s1, $this->pick($open, 10, 10), 'SAMPLE: day 2 count', SuperAdjustment::STATUS_DRAFT, $first, null, now()->subDays(1)->setTime(16, 40));

            // ---------- ENDED session: 10 days ago to 7 days ago, two approved counts and a clearing count waiting for approval
            $s2 = $this->session($ended, $first, now()->subDays(10)->setTime(9, 0), 'SAMPLE - Year-end count (ended)', 'Finished. Sample for review.');
            $this->count($s2, $this->pick($ended, 0, 12), 'SAMPLE: day 1 count', SuperAdjustment::STATUS_APPROVED, $first, $second, now()->subDays(9)->setTime(14, 0));
            $this->count($s2, $this->pick($ended, 12, 12), 'SAMPLE: day 2 count', SuperAdjustment::STATUS_APPROVED, $first, $second, now()->subDays(8)->setTime(14, 30));
            $counted = Svc::countedItemIds(Svc::documents($s2));
            $uncounted = Svc::uncounted($s2, $counted);
            $clear = $uncounted->take(4);
            $clearing = $this->count($s2, $clear->pluck('item_id')->all(), 'SAMPLE: clears the items not counted', SuperAdjustment::STATUS_DRAFT, $first, null, now()->subDays(7)->setTime(11, 0), true);
            $docs = Svc::documents($s2);
            $s2->forceFill([
                'status' => AdjustmentSession::STATUS_ENDED, 'open_warehouse_id' => null, 'ended_by_user_id' => $first->id, 'ended_at' => now()->subDays(7)->setTime(11, 5),
                'end_note' => 'Sample: four items were never counted and are being cleared.', 'clearing_super_adjustment_id' => $clearing->id,
                'snapshot' => [
                    'documents' => $docs->map(fn ($d) => ['id' => $d->id, 'reference' => $d->reference, 'status' => $d->status, 'items' => (int) $d->items_count, 'by' => optional($d->user)->name])->values()->all(),
                    'counted_items' => count($counted),
                    'uncounted' => $uncounted->map(fn ($r) => $r + ['cleared' => $clear->contains('item_id', $r['item_id'])])->values()->all(),
                    'cleared_count' => $clear->count(), 'kept_count' => $uncounted->count() - $clear->count(),
                ],
            ])->save();
            $this->info("Open session {$s1->reference} (warehouse {$open}): {$d1->reference} approved, {$d2->reference} waiting.");
            $this->info("Ended session {$s2->reference} (warehouse {$ended}): clearing count {$clearing->reference} waiting for approval.");
        });

        return self::SUCCESS;
    }

    /** Items of the warehouse with stock, never negative (so a count equal to the stock changes nothing). */
    private function pick(int $warehouseId, int $skip, int $take): array
    {
        return Stock::main()->ofWarehouse($warehouseId)->where('quantity', '>', 0)->whereHas('item')->orderBy('item_id')->skip($skip)->take($take)->pluck('item_id')->all();
    }

    private function session(int $warehouseId, User $by, $at, string $name, string $note): AdjustmentSession
    {
        $s = AdjustmentSession::create([
            'reference' => Svc::nextReference(), 'warehouse_id' => $warehouseId, 'name' => $name, 'note' => $note, 'status' => AdjustmentSession::STATUS_OPEN,
            'open_warehouse_id' => $warehouseId, 'started_by_user_id' => $by->id, 'started_at' => $at, 'account_id' => 1,
        ]);
        DB::table('adjustment_sessions')->where('id', $s->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $s->fresh();
    }

    private function count(AdjustmentSession $s, array $itemIds, string $reason, string $status, User $by, ?User $approver2, $at, bool $clearing = false): SuperAdjustment
    {
        $sa = SuperAdjustment::create([
            'reference' => $this->nextReference(), 'warehouse_id' => $s->warehouse_id, 'adjustment_session_id' => $s->id, 'date' => $at->toDateString(), 'user_id' => $by->id,
            'reason' => $reason, 'status' => $status, 'account_id' => 1,
        ]);
        foreach ($itemIds as $iid) {
            $stock = (float) Stock::main()->ofWarehouse($s->warehouse_id)->where('item_id', $iid)->value('quantity');
            SuperAdjustmentItem::create([
                'super_adjustment_id' => $sa->id, 'item_id' => $iid, 'unit_id' => DB::table('items')->where('id', $iid)->value('unit_id'),
                'system_quantity_at_submission' => $stock, 'physical_quantity' => $clearing ? 0 : $stock, 'auto_cleared' => $clearing, 'account_id' => 1,
            ]);
        }
        if ($status === SuperAdjustment::STATUS_APPROVED) {
            // a count that equals the stock creates no adjustment, exactly as a real approval would; both phases are recorded
            $sa->forceFill(['approved_by_user_id' => ($approver2 ?: $by)->id, 'approved_at' => $at->copy()->addHours(2)])->save();
            foreach ([1 => $by, 2 => $approver2 ?: $by] as $phase => $u) {
                SuperAdjustmentApproval::create(['super_adjustment_id' => $sa->id, 'phase' => $phase, 'user_id' => $u->id, 'role' => 'Super Admin', 'approved_at' => $at->copy()->addHours($phase)]);
            }
        }
        DB::table('super_adjustments')->where('id', $sa->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $sa->fresh();
    }

    private function nextReference(): string
    {
        $max = (int) DB::table('super_adjustments')->selectRaw('MAX(CAST(SUBSTRING(reference, 6) AS UNSIGNED)) m')->where('reference', 'like', 'SADJ-%')->value('m');

        return 'SADJ-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function remove(): void
    {
        $ids = SuperAdjustment::withoutGlobalScopes()->withTrashed()->where('reason', 'like', 'SAMPLE:%')->pluck('id');
        DB::table('super_adjustment_approvals')->whereIn('super_adjustment_id', $ids)->delete();
        DB::table('super_adjustment_items')->whereIn('super_adjustment_id', $ids)->delete();
        SuperAdjustment::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->forceDelete();
        AdjustmentSession::withTrashed()->where('name', 'like', 'SAMPLE%')->forceDelete();
    }
}
