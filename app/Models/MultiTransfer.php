<?php

namespace App\Models;

/**
 * One request that takes items from SEVERAL warehouses to ONE destination. It owns no stock and no approval of its own: each source gets an
 * ordinary Transfer (multi_transfer_id points back here), held for approval like any transfer that leaves a main warehouse. The group's status is
 * worked out from those transfers, never stored.
 */
class MultiTransfer extends Model
{
    public static $tracksDeletes = true;

    protected $setHash = true;

    protected $setUser = true;

    protected $fillable = ['account_id', 'reference', 'hash', 'date', 'to_warehouse_id', 'user_id', 'details', 'deleted_by'];

    public $casts = ['date' => 'date'];

    /** A request is seen by its creator, by anyone who approves / cancels Multi Transfers or sees every record, and by people who work in the destination or a source warehouse. */
    public function scopeVisibleTo($q, User $u)
    {
        if ($u->hasRole('Super Admin') || $u->view_all || $u->can('approve-multi-transfers') || $u->can('cancel-multi-transfers')) {
            return $q;
        }
        $ids = accessible_warehouse_ids();

        return $q->where(function ($w) use ($u, $ids) {
            $w->where('user_id', $u->id);
            if ($ids) {
                $w->orWhereIn('to_warehouse_id', $ids)->orWhereHas('transfers', fn ($t) => $t->withoutGlobalScope('mine')->whereIn('from_warehouse_id', $ids));
            }
        });
    }

    public function transfers()
    {
        return $this->hasMany(Transfer::class, 'multi_transfer_id')->withoutGlobalScope('mine');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id')->withoutGlobalScopes();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** pending (nothing approved yet) | partial (some approved, some waiting) | approved | cancelled (every source cancelled) | received (all arrived). */
    public function status(?\Illuminate\Support\Collection $transfers = null): string
    {
        $t = $transfers ?? $this->transfers;
        $live = $t->where('approval_status', '!=', Transfer::APPROVAL_CANCELLED);
        if ($t->isEmpty() || $live->isEmpty()) {
            return 'cancelled';
        }
        $pending = $live->where('approval_status', Transfer::APPROVAL_PENDING)->count();
        $approved = $live->where('approval_status', Transfer::APPROVAL_APPROVED)->count();
        if ($pending === 0) {
            return $live->every(fn ($x) => $x->received_at) ? 'received' : 'approved';
        }

        return $approved > 0 ? 'partial' : 'pending';
    }
}
