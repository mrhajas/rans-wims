<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps track of where the pieces of an asset are (table asset_placements: one row per place with how many pieces are there).
 * Check-Out, Check-In and Transfer move pieces from one place to another, and a person can only move pieces that are really at
 * the place they pick. All changes run inside the caller's transaction on a locked asset row, so two people cannot move the
 * same pieces twice.
 */
class AssetPlacements
{
    public const EPS = 0.0005;

    public static function key(?int $warehouseId, ?string $label, ?int $userId, ?string $responsibleLabel): string
    {
        return md5(implode('|', [(int) $warehouseId, mb_strtolower(trim((string) $label)), (int) $userId, mb_strtolower(trim((string) $responsibleLabel))]));
    }

    public static function round(float $q): float
    {
        return round($q, 3);
    }

    /** Makes sure a row exists for an asset that has none yet (new or older assets): its whole quantity at its current place. */
    public static function ensure(Asset $asset): void
    {
        if (DB::table('asset_placements')->where('asset_id', $asset->id)->exists()) {
            return;
        }
        $qty = (float) ($asset->quantity ?? 1);
        if ($qty <= 0) {
            return;
        }
        self::add($asset->id, $asset->current_warehouse_id, $asset->current_location_label, $asset->assigned_to_user_id, $asset->assigned_to_label, $qty);
    }

    public static function add(int $assetId, ?int $warehouseId, ?string $label, ?int $userId, ?string $responsibleLabel, float $qty): void
    {
        $key = self::key($warehouseId, $label, $userId, $responsibleLabel);
        $row = DB::table('asset_placements')->where('asset_id', $assetId)->where('place_key', $key)->lockForUpdate()->first();
        if ($row) {
            DB::table('asset_placements')->where('id', $row->id)->update(['quantity' => self::round($row->quantity + $qty), 'updated_at' => now()]);

            return;
        }
        DB::table('asset_placements')->insert([
            'asset_id' => $assetId, 'warehouse_id' => $warehouseId ?: null, 'location_label' => trim((string) $label),
            'responsible_user_id' => $userId ?: null, 'responsible_label' => trim((string) $responsibleLabel),
            'place_key' => $key, 'quantity' => self::round($qty), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The rows with their names, biggest first (the first one is the asset's main place). */
    public static function list(Asset $asset): Collection
    {
        self::ensure($asset);
        $rows = DB::table('asset_placements as p')
            ->leftJoin('warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.responsible_user_id')
            ->where('p.asset_id', $asset->id)->where('p.quantity', '>', 0)->whereNull('p.deleted_at')
            ->orderByDesc('p.quantity')->orderBy('p.id')
            ->get(['p.id', 'p.warehouse_id', 'p.location_label', 'p.responsible_user_id', 'p.responsible_label', 'p.quantity', 'w.name as warehouse_name', 'u.name as user_name']);

        return $rows->values()->map(fn ($r, $i) => [
            'id' => $r->id,
            'warehouse_id' => $r->warehouse_id,
            'warehouse_name' => $r->warehouse_name,
            'location_label' => $r->location_label,
            'responsible_user_id' => $r->responsible_user_id,
            'responsible_name' => $r->user_name ?: ($r->responsible_label ?: null),
            'place' => self::placeName($r->warehouse_name, $r->location_label),
            'quantity' => (float) $r->quantity,
            'is_main' => $i === 0,
        ]);
    }

    public static function placeName(?string $warehouse, ?string $label): string
    {
        $parts = array_filter([trim((string) $warehouse), trim((string) $label)]);

        return $parts ? implode(' / ', $parts) : '-';
    }

    public static function total(Asset $asset): float
    {
        return (float) DB::table('asset_placements')->where('asset_id', $asset->id)->whereNull('deleted_at')->sum('quantity');
    }

    /**
     * Moves $qty pieces from one place (a row of this asset) to another place. Returns the row that was the source, as it was.
     *
     * @param  array{warehouse_id?:?int,label?:?string,user_id?:?int,responsible_label?:?string}  $to
     * @throws ValidationException when the pieces are not at that place or the two places are the same
     */
    public static function move(Asset $asset, ?int $fromId, ?float $qty, array $to): object
    {
        self::ensure($asset);
        $q = DB::table('asset_placements')->where('asset_id', $asset->id)->whereNull('deleted_at')->lockForUpdate();
        $from = $fromId ? (clone $q)->where('id', $fromId)->first() : (clone $q)->orderByDesc('quantity')->orderBy('id')->first();
        if (! $from) {
            throw ValidationException::withMessages(['from_placement_id' => __('Choose where the items are now.')]);
        }
        $qty = $qty === null ? (float) $from->quantity : self::round($qty);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Enter a quantity greater than zero.')]);
        }
        if ($qty > (float) $from->quantity + self::EPS) {
            $where = self::placeName(DB::table('warehouses')->where('id', $from->warehouse_id)->value('name'), $from->location_label);
            throw ValidationException::withMessages(['quantity' => __('Only :n are at :place, so you cannot move :q.', ['n' => self::fmt($from->quantity), 'place' => $where, 'q' => self::fmt($qty)])]);
        }
        $toKey = self::key($to['warehouse_id'] ?? null, $to['label'] ?? null, $to['user_id'] ?? null, $to['responsible_label'] ?? null);
        if ($toKey === $from->place_key) {
            throw ValidationException::withMessages(['to_warehouse_id' => __('The items are already there with that person. Choose a different place or person.')]);
        }

        $left = self::round((float) $from->quantity - $qty);
        if ($left <= self::EPS) {
            DB::table('asset_placements')->where('id', $from->id)->delete();
        } else {
            DB::table('asset_placements')->where('id', $from->id)->update(['quantity' => $left, 'updated_at' => now()]);
        }
        self::add($asset->id, $to['warehouse_id'] ?? null, $to['label'] ?? null, $to['user_id'] ?? null, $to['responsible_label'] ?? null, $qty);

        $from->moved = $qty;

        return $from;
    }

    /** The asset's headline fields follow its main place (where most pieces are). */
    public static function syncSnapshot(Asset $asset): void
    {
        $main = self::list($asset)->first();
        if (! $main) {
            return;
        }
        $changes = [
            'current_warehouse_id' => $main['warehouse_id'],
            'current_location_label' => $main['location_label'] !== '' ? $main['location_label'] : null,
            'assigned_to_user_id' => $main['responsible_user_id'],
            'assigned_to_label' => ($main['responsible_user_id'] || $main['responsible_name']) ? ($main['responsible_user_id'] ? null : $main['responsible_name']) : null,
        ];
        $held = (bool) ($main['responsible_user_id'] || $main['responsible_name']);
        if ($held && ! in_array($asset->status, ['Under Repair', 'Under Maintenance', 'Disposed', 'Sold', 'Written Off', 'Lost', 'Stolen'], true)) {
            $changes['status'] = 'Assigned';
        } elseif (! $held && $asset->status === 'Assigned') {
            $changes['status'] = 'Available';
            $changes['assignment_date'] = null;
            $changes['expected_return_date'] = null;
        }
        $asset->forceFill($changes)->save();
    }

    /**
     * Called when the Edit form is saved. The form's location and holder describe the main place, and its Quantity is the asset's
     * total: a changed location moves the main place, and a bigger or smaller total adds to or takes from the main place.
     *
     * @param  array  $old  the asset's location fields before the save
     * @param  array  $new  the validated form data
     */
    public static function applyFormSave(Asset $asset, array $old, array $new): void
    {
        self::ensure($asset);
        $total = self::total($asset);
        $newTotal = array_key_exists('quantity', $new) && $new['quantity'] !== null ? self::round((float) $new['quantity']) : $total;
        $oldKey = self::key($old['current_warehouse_id'] ?? null, $old['current_location_label'] ?? null, $old['assigned_to_user_id'] ?? null, $old['assigned_to_label'] ?? null);
        $newKey = self::key($new['current_warehouse_id'] ?? null, $new['current_location_label'] ?? null, $new['assigned_to_user_id'] ?? null, $new['assigned_to_label'] ?? null);

        $main = DB::table('asset_placements')->where('asset_id', $asset->id)->whereNull('deleted_at')->lockForUpdate()->where('place_key', $oldKey)->first()
            ?: DB::table('asset_placements')->where('asset_id', $asset->id)->whereNull('deleted_at')->lockForUpdate()->orderByDesc('quantity')->orderBy('id')->first();

        $delta = self::round($newTotal - $total);
        if ($delta < -self::EPS && (! $main || (float) $main->quantity + $delta < -self::EPS)) {
            throw ValidationException::withMessages(['quantity' => __('The quantity cannot be lower than what is in other places. :n are at the main place, so you can reduce it by at most that. Move or check in the others first.', ['n' => self::fmt($main ? $main->quantity : 0)])]);
        }

        if ($main && $oldKey !== $newKey && $main->place_key === $oldKey) {
            // the main place itself changed (location corrected in the form): re-key it, joining an existing row for that place
            $existing = DB::table('asset_placements')->where('asset_id', $asset->id)->where('place_key', $newKey)->first();
            if ($existing) {
                DB::table('asset_placements')->where('id', $existing->id)->update(['quantity' => self::round($existing->quantity + $main->quantity), 'updated_at' => now()]);
                DB::table('asset_placements')->where('id', $main->id)->delete();
                $main = DB::table('asset_placements')->where('id', $existing->id)->first();
            } else {
                DB::table('asset_placements')->where('id', $main->id)->update([
                    'warehouse_id' => $new['current_warehouse_id'] ?? null, 'location_label' => trim((string) ($new['current_location_label'] ?? '')),
                    'responsible_user_id' => $new['assigned_to_user_id'] ?? null, 'responsible_label' => trim((string) ($new['assigned_to_label'] ?? '')),
                    'place_key' => $newKey, 'updated_at' => now(),
                ]);
                $main = DB::table('asset_placements')->where('id', $main->id)->first();
            }
        }
        if ($main && abs($delta) > self::EPS) {
            $q = self::round((float) $main->quantity + $delta);
            if ($q <= self::EPS) {
                DB::table('asset_placements')->where('id', $main->id)->delete();
            } else {
                DB::table('asset_placements')->where('id', $main->id)->update(['quantity' => $q, 'updated_at' => now()]);
            }
        } elseif (! $main && $newTotal > 0) {
            self::add($asset->id, $new['current_warehouse_id'] ?? null, $new['current_location_label'] ?? null, $new['assigned_to_user_id'] ?? null, $new['assigned_to_label'] ?? null, $newTotal);
        }
    }

    public static function fmt($n): string
    {
        $n = (float) $n;

        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
