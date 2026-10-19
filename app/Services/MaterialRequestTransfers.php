<?php

namespace App\Services;

use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfers made for a Material Request. Only items whose procedure is Main Warehouse (with the warehouse Procurement chose) are moved this way, and only
 * once the request is Partially or Fully Issued; every other item is left alone. One request can have several transfers: the user says how many of each
 * item to move, and one DRAFT transfer is made for each source warehouse (stock is not checked until the draft is turned into a normal transfer).
 * What can still be moved is what the request asked for minus what its other live transfers already carry. Cancelled and deleted transfers carry nothing.
 */
class MaterialRequestTransfers
{
    /** A transfer may be made once Procurement has marked the request Partially or Fully Issued. */
    public static function allows(MaterialRequest $mr): bool
    {
        return in_array($mr->status, [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED], true);
    }

    /** Transfers of this request that still count (not cancelled, not deleted). */
    private static function live(int $mrId, ?int $exceptTransferId = null)
    {
        return DB::table('transfers')->where('material_request_id', $mrId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', '<>', Transfer::APPROVAL_CANCELLED))
            ->when($exceptTransferId, fn ($q) => $q->where('id', '<>', $exceptTransferId));
    }

    /** @return array<int,array<int,array{id:int,reference:string,quantity:float,status:string}>> item_id => the live transfers that carry it */
    public static function byItem(int $mrId): array
    {
        $rows = DB::table('transfer_items as ti')->join('transfers as t', 't.id', '=', 'ti.transfer_id')->where('t.material_request_id', $mrId)->whereNull('t.deleted_at')->whereNull('ti.deleted_at')
            ->where(fn ($q) => $q->whereNull('t.approval_status')->orWhere('t.approval_status', '<>', Transfer::APPROVAL_CANCELLED))
            ->orderBy('t.id')->get(['ti.item_id', 'ti.quantity', 't.id', 't.reference', 't.draft', 't.approval_status', 't.received_at']);
        $out = [];
        foreach ($rows as $r) {
            $status = $r->approval_status === Transfer::APPROVAL_PENDING ? 'Awaiting Approval' : ($r->draft ? 'Draft' : ($r->received_at ? 'Received' : 'Approved'));
            $out[(int) $r->item_id][] = ['id' => (int) $r->id, 'reference' => $r->reference, 'quantity' => (float) $r->quantity, 'status' => $status];
        }

        return $out;
    }

    /** @return array<int,float> item_id => quantity already carried by live transfers */
    public static function carried(int $mrId, ?int $exceptTransferId = null): array
    {
        $ids = self::live($mrId, $exceptTransferId)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('transfer_items')->whereIn('transfer_id', $ids)->whereNull('deleted_at')->selectRaw('item_id, SUM(quantity) q')->groupBy('item_id')->pluck('q', 'item_id')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * One row per request line: asked, carried, remaining, plus the source warehouse Procurement chose (Main Warehouse / Transfer from Site).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function lines(MaterialRequest $mr, ?int $exceptTransferId = null): array
    {
        $mr->loadMissing(['items.item:id,name,code,unit_id,has_variants,has_serials', 'items.unit:id,name', 'items.procedureWarehouse:id,name']);
        $carried = self::carried($mr->id, $exceptTransferId);
        $onTransfer = self::byItem($mr->id);
        $left = $carried; // the same item on two lines: the first lines use up what is carried
        $out = [];
        foreach ($mr->items as $l) {
            // only Main Warehouse items with a chosen source warehouse are moved by transfer; the rest are disregarded
            if ($l->procedure !== MaterialRequest::PROCEDURE_MAIN_WAREHOUSE || ! $l->procedure_warehouse_id) {
                continue;
            }
            $asked = (float) $l->quantity;
            $take = min($asked, $left[$l->item_id] ?? 0.0);
            $left[$l->item_id] = ($left[$l->item_id] ?? 0.0) - $take;
            $remaining = round($asked - $take, 4);
            $fromStock = true;
            $out[] = ['id' => $l->id, 'item_id' => (int) $l->item_id, 'name' => optional($l->item)->name, 'code' => optional($l->item)->code, 'unit' => optional($l->unit)->name,
                'asked' => round($asked, 4), 'carried' => round($take, 4), 'remaining' => max(0, $remaining), 'procedure' => $l->procedure,
                'from_warehouse_id' => $fromStock ? (int) $l->procedure_warehouse_id : null, 'from_warehouse' => $fromStock ? optional($l->procedureWarehouse)->name : null,
                'plain' => ! (optional($l->item)->has_variants || optional($l->item)->has_serials),
                // an item that already has a transfer is not given another one: its transfer (and the balance it leaves) is shown instead
                'transfers' => $onTransfer[(int) $l->item_id] ?? [], 'has_transfer' => ! empty($onTransfer[(int) $l->item_id])];
        }

        return $out;
    }

    /** What is still to be moved, grouped by source warehouse (the first group is "the first issued items"; items with no source chosen come last). */
    public static function groups(MaterialRequest $mr): array
    {
        $groups = [];
        foreach (self::lines($mr) as $l) {
            if ($l['remaining'] <= 0.00005 || $l['has_transfer']) {
                continue;
            }
            $key = $l['from_warehouse_id'] ?: 0;
            $groups[$key] ??= ['from_warehouse_id' => $l['from_warehouse_id'], 'from_warehouse' => $l['from_warehouse'], 'items' => []];
            $groups[$key]['items'][] = $l;
        }
        uksort($groups, fn ($a, $b) => ($a === 0) <=> ($b === 0));

        return array_values($groups);
    }

    /** Remaining quantity per item for the transfer form (merged over duplicate lines). */
    public static function remainingByItem(MaterialRequest $mr, ?int $exceptTransferId = null): array
    {
        $r = [];
        foreach (self::lines($mr, $exceptTransferId) as $l) {
            $r[$l['item_id']] = round(($r[$l['item_id']] ?? 0) + $l['remaining'], 4);
        }

        return $r;
    }

    /** Items for the transfer form, shaped like an item picked in its search box, with the quantity still to move. */
    public static function prefill(MaterialRequest $mr, ?int $fromWarehouseId): array
    {
        $groups = self::groups($mr);
        $group = null;
        if ($fromWarehouseId !== null) {
            $group = collect($groups)->first(fn ($g) => (int) $g['from_warehouse_id'] === $fromWarehouseId);
        }
        $group ??= $groups[0] ?? null;
        $items = [];
        $skipped = [];
        foreach ($group['items'] ?? [] as $l) {
            if (! $l['plain']) {
                $skipped[] = $l['name'];
                continue;
            }
            $item = Item::with('variations')->find($l['item_id']);
            if (! $item) {
                continue;
            }
            $items[] = array_merge($item->toArray(), ['item_id' => $item->id, 'quantity' => $l['remaining'], 'unit_id' => $item->unit_id, 'weight' => 0, 'selected' => ['variations' => [], 'serials' => []], 'mr_remaining' => $l['remaining']]);
        }

        return ['from_warehouse_id' => $group['from_warehouse_id'] ?? null, 'items' => $items, 'skipped' => $skipped];
    }

    /**
     * The checks for a transfer made for a request: the request must allow it, the transfer goes to the request's warehouse, only the request's items,
     * never more than is still to move. Throws a validation error naming the problem.
     *
     * @param array<int,array<string,mixed>> $items the transfer form lines
     */
    public static function validate(int $mrId, int $toWarehouseId, array $items, ?int $exceptTransferId = null): void
    {
        $mr = MaterialRequest::query()->find($mrId);
        if (! $mr) {
            throw ValidationException::withMessages(['material_request_id' => __('This Material Request no longer exists.')]);
        }
        if (! self::allows($mr)) {
            throw ValidationException::withMessages(['material_request_id' => __('A transfer cannot be made for a Material Request that is :s.', ['s' => strtolower(__($mr->status))])]);
        }
        if ((int) $mr->warehouse_id !== $toWarehouseId) {
            throw ValidationException::withMessages(['to_warehouse_id' => __('A transfer for :r goes to the warehouse that asked for the material.', ['r' => $mr->reference])]);
        }
        $remaining = self::remainingByItem($mr, $exceptTransferId);
        $errors = [];
        $sum = [];
        foreach ($items as $i => $l) {
            $itemId = (int) ($l['item_id'] ?? 0);
            $qty = ! empty($l['selected']['variations']) ? collect($l['selected']['variations'])->sum(fn ($v) => (float) ($v['quantity'] ?? 0)) : (float) ($l['quantity'] ?? 0);
            if (! array_key_exists($itemId, $remaining)) {
                $errors["items.$i.item_id"] = __('This item is not on :r.', ['r' => $mr->reference]);
                continue;
            }
            // a negative quantity would give stock back to the source (and could hide behind the other lines of the same item); zero moves nothing
            if ($qty < 0 || ($qty < 0.00005 && empty($l['selected']['serials']) && empty($l['selected']['variations']))) {
                $errors["items.$i.quantity"] = __('Enter a quantity greater than zero.');
                continue;
            }
            $sum[$itemId] = ($sum[$itemId] ?? 0) + $qty;
            if ($sum[$itemId] > $remaining[$itemId] + 0.00005) {
                $errors["items.$i.quantity"] = __('Only :n is still to be moved for :r.', ['n' => rtrim(rtrim(number_format($remaining[$itemId], 4, '.', ''), '0'), '.') ?: '0', 'r' => $mr->reference]);
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Makes the draft transfers: one per source warehouse, from the quantities the user entered (item_id => quantity; zero or empty skips the item).
     * Stock is NOT checked here; that happens when a draft is turned into a normal transfer. Returns the transfers made.
     *
     * @param array<int|string,mixed> $quantities
     * @return array<int,Transfer>
     */
    public static function createDrafts(MaterialRequest $mr, array $quantities, $user): array
    {
        if (! self::allows($mr)) {
            throw ValidationException::withMessages(['quantities' => __('A transfer can only be made when the request is Partially or Fully Issued.')]);
        }
        $all = collect(self::lines($mr));
        $lines = $all->filter(fn ($l) => $l['plain'] && $l['remaining'] > 0.00005 && ! $l['has_transfer']);
        $errors = [];
        $take = [];
        foreach ($quantities as $itemId => $q) {
            if ($q === null || $q === '' || (float) $q == 0.0) {
                continue;
            }
            if (! is_numeric($q) || (float) $q < 0) {
                $errors["quantities.$itemId"] = __('Enter a quantity of zero or more.');
                continue;
            }
            if (round((float) $q, 4) < 0.0001) {
                // quantities are kept to 4 decimal places: anything smaller would be saved as 0
                $errors["quantities.$itemId"] = __('The smallest quantity is 0.0001.');
                continue;
            }
            $already = $all->first(fn ($l) => $l['item_id'] === (int) $itemId && $l['has_transfer']);
            if ($already) {
                $errors["quantities.$itemId"] = __(':item is already on transfer :t. Change the quantity on that transfer instead.', ['item' => $already['name'], 't' => $already['transfers'][0]['reference']]);
                continue;
            }
            $itemLines = $lines->where('item_id', (int) $itemId);
            $left = round((float) $itemLines->sum('remaining'), 4);
            if ($itemLines->isEmpty()) {
                $errors["quantities.$itemId"] = __('This item cannot be moved by transfer from this request.');
            } elseif ((float) $q > $left + 0.00005) {
                $errors["quantities.$itemId"] = __('Only :n is still to be moved.', ['n' => rtrim(rtrim(number_format($left, 4, '.', ''), '0'), '.') ?: '0']);
            } else {
                $take[(int) $itemId] = round((float) $q, 4);
            }
        }
        if (! $errors && ! $take) {
            $errors['quantities'] = __('Enter how many of at least one item to move.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // one draft transfer for each source warehouse
        $byWarehouse = [];
        foreach ($take as $itemId => $q) {
            $line = $lines->firstWhere('item_id', $itemId);
            $byWarehouse[$line['from_warehouse_id']][] = ['item_id' => $itemId, 'quantity' => $q, 'unit_id' => optional(Item::find($itemId))->unit_id];
        }
        $made = [];
        foreach ($byWarehouse as $fromId => $items) {
            $made[] = \App\Support\ReferenceLock::run('transfers', function () use ($mr, $fromId, $items) {
                self::validate($mr->id, (int) $mr->warehouse_id, array_map(fn ($i) => ['item_id' => $i['item_id'], 'quantity' => $i['quantity']], $items));
                $max = (int) DB::table('transfers')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'RST-%')->value('m');
                $data = ['reference' => 'RST-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT), 'date' => now()->toDateString(), 'draft' => true, 'from_warehouse_id' => (int) $fromId, 'to_warehouse_id' => (int) $mr->warehouse_id,
                    'material_request_id' => $mr->id, 'details' => __('For Material Request :r', ['r' => $mr->reference]),
                    'items' => array_map(fn ($i) => $i + ['id' => $i['item_id'], 'weight' => 0, 'selected' => ['variations' => [], 'serials' => []]], $items)];

                return (new \App\Actions\Tec\PrepareOrder($data, null, new Transfer()))->process()->save();
            });
        }
        foreach ($made as $t) {
            event(new \App\Events\TransferEvent($t, 'created'));
            log_activity('Transfer Created', ['reference' => $t->reference, 'material_request_id' => $mr->id, 'draft' => true, 'from_warehouse_id' => $t->from_warehouse_id, 'to_warehouse_id' => $t->to_warehouse_id], $t, 'Transfer');
        }

        return $made;
    }

    /**
     * Bringing a deleted transfer back must not take the request over what it asked: while it was deleted its quantity may have been moved by another
     * transfer. Returns the message to show, or null when there is room.
     */
    public static function restoreProblem(Transfer $transfer): ?string
    {
        $mr = $transfer->material_request_id ? MaterialRequest::query()->find((int) $transfer->material_request_id) : null;
        if (! $mr) {
            return null;
        }
        $remaining = self::remainingByItem($mr);
        $sum = [];
        foreach ($transfer->items as $l) {
            $sum[(int) $l->item_id] = ($sum[(int) $l->item_id] ?? 0.0) + (float) $l->quantity;
        }
        foreach ($sum as $itemId => $q) {
            if (array_key_exists($itemId, $remaining) && $q > $remaining[$itemId] + 0.00005) {
                return __('This transfer cannot be restored: :r has only :n still to be moved of an item on it.', ['r' => $mr->reference, 'n' => rtrim(rtrim(number_format($remaining[$itemId], 4, '.', ''), '0'), '.') ?: '0']);
            }
        }

        return null;
    }

    /** The transfers made for a request, for its page. */
    public static function transfers(MaterialRequest $mr): array
    {
        return Transfer::query()->where('material_request_id', $mr->id)->with(['fromWarehouse:id,name', 'items:id,transfer_id,item_id,quantity'])->orderBy('id')->get()->map(function ($t) {
            $status = $t->approval_status === Transfer::APPROVAL_CANCELLED ? 'Cancelled' : ($t->approval_status === Transfer::APPROVAL_PENDING ? 'Awaiting Approval' : ($t->draft ? 'Draft' : ($t->received_at ? 'Received' : 'Approved')));

            return ['id' => $t->id, 'reference' => $t->reference, 'date' => $t->date ? substr((string) $t->date, 0, 10) : null, 'from' => optional($t->fromWarehouse)->name, 'status' => $status, 'lines' => $t->items->count(), 'quantity' => round((float) $t->items->sum('quantity'), 4)];
        })->all();
    }
}
