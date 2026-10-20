<?php

namespace App\Models;

/**
 * A multi-day physical count of one Project / Warehouse (Super Adjustment only). While it is Open every Super Adjustment made for that warehouse is
 * added to it (super_adjustments.adjustment_session_id); when it ends the items with stock that were never counted are listed and can be cleared.
 */
class AdjustmentSession extends Model
{
    public static $hasAccount = true;

    public static $tracksDeletes = true;

    const STATUS_OPEN = 'Open';

    const STATUS_ENDED = 'Ended';

    protected $fillable = [
        'account_id', 'reference', 'warehouse_id', 'name', 'note', 'status', 'open_warehouse_id', 'started_by_user_id', 'started_at',
        'ended_by_user_id', 'ended_at', 'end_note', 'clearing_super_adjustment_id', 'snapshot',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function superAdjustments()
    {
        return $this->hasMany(SuperAdjustment::class, 'adjustment_session_id');
    }

    public function clearingSuperAdjustment()
    {
        return $this->belongsTo(SuperAdjustment::class, 'clearing_super_adjustment_id');
    }

    /** The open session of a warehouse, if there is one. */
    public static function openFor(?int $warehouseId, bool $lock = false): ?self
    {
        // $lock: a shared lock, so a count being saved waits while the session is being ended (and then finds it ended)
        return $warehouseId ? self::query()->where('open_warehouse_id', $warehouseId)->where('status', self::STATUS_OPEN)->when($lock, fn ($q) => $q->sharedLock())->first() : null;
    }
}
