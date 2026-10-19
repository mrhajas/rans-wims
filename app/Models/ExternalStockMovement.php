<?php

namespace App\Models;

use App\Traits\HasAttachments;

/** One movement of External Personnel stock: a Stock Out (they take), a Stock In (they bring) or opening stock. Approval based; see the migration for the rules. */
class ExternalStockMovement extends Model
{
    use HasAttachments;

    public const KINDS = ['withdrawal' => 'Stock Out', 'deposit' => 'Stock In', 'opening' => 'Opening stock'];

    public const PENDING = 'Pending Approval';

    public const APPROVED = 'Approved';

    public const REJECTED = 'Rejected';

    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    public static $tracksDeletes = true;

    protected $setHash = true;

    protected $setUser = true;

    protected $fillable = [
        'account_id', 'reference', 'hash', 'kind', 'date', 'contact_id', 'location', 'warehouse_id', 'status', 'purpose', 'collector_name', 'collector_phone', 'collector_id_no',
        'driver_contact_id', 'driver_name', 'driver_phone', 'asset_id', 'vehicle_plate', 'vehicle_details', 'user_id', 'decided_by_user_id', 'decided_at', 'decision_remarks',
        'cancelled_by_user_id', 'cancelled_at', 'cancel_reason', 'deleted_by',
    ];

    public $casts = ['date' => 'date', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime'];

    /** A paper record (Gate Pass, Delivery Note) can only be issued for an approved movement: until then the movement counts as a draft. */
    public function getDraftAttribute(): bool
    {
        return $this->status !== self::APPROVED;
    }

    public function items()
    {
        return $this->hasMany(ExternalStockItem::class, 'movement_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes()->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isOut(): bool
    {
        return $this->kind === 'withdrawal';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public static function nextReference(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('external_stock_movements')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'EXS-%')->value('m');

        return 'EXS-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Who may see which movements: the person who made it, people who approve / see everything, and people who work in that warehouse. */
    public function scopeVisibleTo($q, User $u)
    {
        if ($u->hasRole('Super Admin') || $u->view_all || $u->can('approve-external-stock') || $u->can('cancel-external-stock')) {
            return $q;
        }
        $ids = accessible_warehouse_ids();

        return $q->where(function ($w) use ($u, $ids) {
            $w->where('user_id', $u->id);
            if ($ids) {
                $w->orWhereIn('warehouse_id', $ids);
            }
        });
    }
}
