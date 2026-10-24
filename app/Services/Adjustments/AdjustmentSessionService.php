<?php

namespace App\Services\Adjustments;

use App\Models\AdjustmentSession;
use App\Models\Stock;
use App\Models\SuperAdjustment;
use App\Models\SuperAdjustmentItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adjustment Session (Super Adjustment only). A session is one open count of one Project / Warehouse that takes several days: every Super Adjustment made
 * for that warehouse while it is open belongs to it, and an item counts as "counted" once it is on a Draft, Awaiting or Approved Super Adjustment of the
 * session. When the session ends, the items that still have stock and were never counted are listed and, if the person chooses, cleared through ONE
 * normal Super Adjustment (so the usual approvals still apply: nothing changes stock by itself).
 */
class AdjustmentSessionService
{
    /** Super Adjustment statuses that count as a count of the item (a rejected one does not). */
    public const COUNTING = [SuperAdjustment::STATUS_DRAFT, SuperAdjustment::STATUS_AWAITING, SuperAdjustment::STATUS_APPROVED];

    public static function nextReference(): string
    {
        $max = (int) DB::table('adjustment_sessions')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'ASN-%')->value('m');

        return 'ASN-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Every Super Adjustment of the session (whoever made it), newest last. */
    public static function documents(AdjustmentSession $s): Collection
    {
        return SuperAdjustment::withoutGlobalScope('mine')->where('adjustment_session_id', $s->id)->with(['user:id,name', 'approver:id,name', 'rejecter:id,name'])->withCount('items')->orderBy('id')->get();
    }

    /** @return array<int,int> item ids that are on a counting Super Adjustment of the session */
    public static function countedItemIds(Collection $docs): array
    {
        $ids = $docs->filter(fn ($d) => in_array($d->status, self::COUNTING, true))->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        return SuperAdjustmentItem::query()->whereIn('super_adjustment_id', $ids)->pluck('item_id')->unique()->map(fn ($i) => (int) $i)->values()->all();
    }

    /**
     * The items with stock in the warehouse that are not on any counting Super Adjustment of the session. `moved` is true when the stock row changed after the
     * session started (something was received, issued or moved), a hint that the item was handled even though nobody counted it.
     */
    public static function uncounted(AdjustmentSession $s, ?array $countedIds = null): Collection
    {
        $countedIds ??= self::countedItemIds(self::documents($s));

        return Stock::main()->ofWarehouse($s->warehouse_id)->where('quantity', '!=', 0)->whereNotIn('item_id', $countedIds)
            ->with(['item:id,name,code,unit_id,price', 'item.unit:id,name,code'])->get()
            ->filter(fn ($st) => $st->item)
            ->map(fn ($st) => [
                'item_id' => (int) $st->item_id, 'name' => $st->item->name, 'code' => $st->item->code, 'unit' => optional($st->item->unit)->name,
                'quantity' => (float) $st->quantity, 'price' => (float) ($st->item->price ?? 0), 'value' => round((float) $st->quantity * (float) ($st->item->price ?? 0), 2), 'moved' => $s->started_at && $st->updated_at && $st->updated_at->gte($s->started_at),
                'last_moved_at' => optional($st->updated_at)->toDateTimeString(),
            ])->sortBy(fn ($r) => mb_strtolower($r['name']))->values();
    }

    /** How many items with stock the warehouse has (what the count is measured against). */
    public static function stockedCount(int $warehouseId): int
    {
        return (int) Stock::main()->ofWarehouse($warehouseId)->where('quantity', '!=', 0)->count();
    }

    public static function start(User $user, int $warehouseId, ?string $name, ?string $note, bool $haltStock = false): AdjustmentSession
    {
        if (AdjustmentSession::openFor($warehouseId)) {
            throw ValidationException::withMessages(['warehouse_id' => __('This Project / Warehouse already has an open Adjustment Session. End it first.')]);
        }
        // two people can start at the same moment: a clash on the warehouse means a session is already open; a clash only on the reference is retried with the next number
        for ($try = 0; $try < 4; $try++) {
            try {
                return DB::transaction(fn () => AdjustmentSession::create([
                    'reference' => self::nextReference(), 'warehouse_id' => $warehouseId, 'name' => $name ? mb_substr(trim($name), 0, 190) : null, 'note' => $note ? trim($note) : null,
                    'status' => AdjustmentSession::STATUS_OPEN, 'open_warehouse_id' => $warehouseId, 'started_by_user_id' => $user->id, 'started_at' => now(),
                ] + ($haltStock ? ['halt_stock' => true, 'halted_by_user_id' => $user->id, 'halted_at' => now()] : [])));
            } catch (QueryException $e) {
                if (AdjustmentSession::openFor($warehouseId)) {
                    throw ValidationException::withMessages(['warehouse_id' => __('This Project / Warehouse already has an open Adjustment Session. End it first.')]);
                }
            }
        }

        throw ValidationException::withMessages(['warehouse_id' => __('The session could not be started, please try again.')]);
    }

    /**
     * Ends the session. $clearItemIds are the uncounted items to clear (counted at zero in ONE normal Super Adjustment that still needs its approvals);
     * the others stay as they are. Refuses while a Super Adjustment of the session is still waiting for approval. Returns [session, clearing Super Adjustment or null].
     *
     * @param  callable  $submit  fn(array $data): ?SuperAdjustment, saves and submits the clearing count the normal way
     */
    public static function end(User $user, AdjustmentSession $s, array $clearItemIds, ?string $note, callable $submit, bool $withoutClearing = false): array
    {
        $docs = self::documents($s);
        $pending = $docs->filter(fn ($d) => $d->isOpen());
        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages(['session' => __('Some Super Adjustments of this session are still waiting for approval (:r). Approve or reject them first, then end the session.', ['r' => $pending->pluck('reference')->implode(', ')])]);
        }

        $counted = self::countedItemIds($docs);
        $uncounted = self::uncounted($s, $counted);
        $chosen = collect($clearItemIds)->map(fn ($i) => (int) $i)->intersect($uncounted->pluck('item_id'))->values();
        // ending without clearing keeps the choice in the history (the items that were wanted cleared but were left as they are)
        $clearSet = $withoutClearing ? collect() : $chosen;
        $wantedSet = $withoutClearing ? $chosen : collect();

        $clearing = null;
        if ($clearSet->isNotEmpty()) {
            $lines = $uncounted->whereIn('item_id', $clearSet->all())->map(fn ($r) => ['item_id' => $r['item_id'], 'physical_quantity' => 0, 'auto_cleared' => true])->values()->all();
            $clearing = $submit([
                'warehouse_id' => $s->warehouse_id,
                'reason' => mb_substr(__('Adjustment Session :r: items not counted (cleared)', ['r' => $s->reference]), 0, 255),
                'details' => __('Created when the Adjustment Session ended: these items had stock but were not on any Super Adjustment of the session, and were chosen to be cleared.'),
                'remarks' => $note,
                'items' => $lines,
            ]);
        }

        $snapshot = [
            'documents' => $docs->map(fn ($d) => ['id' => $d->id, 'reference' => $d->reference, 'status' => $d->status, 'items' => (int) $d->items_count, 'by' => optional($d->user)->name])->values()->all(),
            'counted_items' => count($counted),
            'uncounted' => $uncounted->map(fn ($r) => $r + ['cleared' => $clearSet->contains($r['item_id']), 'wanted' => $wantedSet->contains($r['item_id'])])->values()->all(),
            'cleared_count' => $clearSet->count(),
            'wanted_count' => $wantedSet->count(),
            'ended_without_clearing' => $withoutClearing,
            'kept_count' => $uncounted->count() - $clearSet->count(),
        ];

        DB::transaction(function () use ($s, $user, $note, $clearing, $snapshot) {
            $s->forceFill([
                'status' => AdjustmentSession::STATUS_ENDED, 'open_warehouse_id' => null, 'ended_by_user_id' => $user->id, 'ended_at' => now(), 'end_note' => $note ? trim($note) : null,
                'clearing_super_adjustment_id' => optional($clearing)->id, 'snapshot' => $snapshot,
            ] + ($s->halt_stock ? ['halt_stock' => false, 'halt_released_by_user_id' => $user->id, 'halt_released_at' => now()] : []))->save(); // ending the session lifts the halt
        });
        \App\Support\StockHalt::forget();

        return [$s->fresh(), $clearing];
    }

    /** Halts the stock movement of the session's warehouse. Returns false when it is already halted or the session is not open. */
    public static function halt(User $user, AdjustmentSession $s): bool
    {
        return DB::transaction(function () use ($user, $s) {
            $locked = AdjustmentSession::query()->whereKey($s->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isOpen() || $locked->halt_stock) {
                return false;
            }
            $locked->forceFill(['halt_stock' => true, 'halted_by_user_id' => $user->id, 'halted_at' => now(), 'halt_released_by_user_id' => null, 'halt_released_at' => null])->save();
            $s->setRawAttributes($locked->getAttributes(), true);
            \App\Support\StockHalt::forget();

            return true;
        });
    }

    /** Lifts the halt while the session stays open. Returns false when it was not halted or the session is not open. */
    public static function release(User $user, AdjustmentSession $s): bool
    {
        return DB::transaction(function () use ($user, $s) {
            $locked = AdjustmentSession::query()->whereKey($s->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isOpen() || ! $locked->halt_stock) {
                return false;
            }
            $locked->forceFill(['halt_stock' => false, 'halt_released_by_user_id' => $user->id, 'halt_released_at' => now()])->save();
            $s->setRawAttributes($locked->getAttributes(), true);
            \App\Support\StockHalt::forget();

            return true;
        });
    }

    /** One line for the notices: "120 counted, 8 not counted, 8 cleared". */
    public static function summary(AdjustmentSession $s): string
    {
        $x = (array) $s->snapshot;

        return ((int) ($x['counted_items'] ?? 0)) . ' ' . __('counted') . ', ' . (((int) ($x['cleared_count'] ?? 0)) + ((int) ($x['kept_count'] ?? 0))) . ' ' . __('not counted') . ', ' . ((int) ($x['cleared_count'] ?? 0)) . ' ' . __('cleared') . (! empty($x['wanted_count']) ? ', ' . ((int) $x['wanted_count']) . ' ' . __('chosen to clear but left as they are') : '') . '.';
    }
}
