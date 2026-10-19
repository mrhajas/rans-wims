<?php

namespace App\Models;

use App\Traits\OrderHelpers;
use App\Traits\HasAttachments;
use App\Traits\HasManySyncable;

class Transfer extends Model
{
    use HasAttachments;
    use HasManySyncable;
    use OrderHelpers;

    public static $hasReference = true;

    public static $hasUser = true;

    public static $tracksDeletes = true;

    protected $fillable = [
        'date', 'reference',  'draft', 'hash', 'to_warehouse_id', 'from_warehouse_id',
        'user_id', 'approved_by', 'account_id', 'details', 'extra_attributes', 'approved_at',
        'received_at', 'received_by', 'approval_status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'multi_transfer_id', 'material_request_id',
    ];

    public const APPROVAL_NOT_REQUIRED = 'not_required';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_CANCELLED = 'cancelled';

    public $casts = [
        'extra_attributes' => 'array',
        'received_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $setHash = true;

    protected $setUser = true;

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id')->withoutGlobalScopes();
    }

    public function multiTransfer()
    {
        return $this->belongsTo(MultiTransfer::class, 'multi_transfer_id')->withoutGlobalScopes();
    }

    public function del()
    {
        $this->items->each->delete();

        return $this->delete();
    }

    public function delP()
    {
        log_activity(__choice('delete_text', ['record' => 'Item']), $this, $this, 'Item');
        $this->items->each->forceDelete();

        return $this->forceDelete();
    }

    /** Whether a transfer leaving this warehouse needs approval (only MAIN warehouses do). */
    public static function warehouseNeedsApproval($warehouseId): bool
    {
        return $warehouseId && Warehouse::withoutGlobalScopes()->whereKey($warehouseId)->mainWarehouses()->exists();
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isAwaitingApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function items()
    {
        return $this->hasMany(TransferItem::class)->orderBy('id')->withTrashed();
    }

    public function scopeSearch($query, $s)
    {
        $query->where(
            fn ($q) => $q->where('id', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%"))
                ->orWhereHas('toWarehouse', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                ->orWhereHas('fromWarehouse', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
        );
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class, 'source_id')->where('source_type', DeliveryNote::SOURCE_TRANSFER);
    }

    public function gatePass()
    {
        return $this->hasOne(GatePass::class, 'source_id')->where('source_type', GatePass::SOURCE_TRANSFER);
    }
}
