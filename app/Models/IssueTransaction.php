<?php

namespace App\Models;

use App\Traits\HasAttachments;

class IssueTransaction extends Model
{
    use HasAttachments;

    public static $hasUser = true;

    public static $tracksDeletes = true;

    protected $fillable = [
        'issue_number',
        'issue_date',
        'warehouse_id',
        'project',
        'issued_to',
        'contact_number',
        'department',
        'expected_return_date',
        'remarks',
        'total_items',
        'total_quantity',
        'returned_quantity',
        'balance_quantity',
        'status',
        'user_id',
        'account_id',
        'deleted_by',
    ];

    public $casts = [
        'issue_date' => 'datetime',
        'expected_return_date' => 'date',
        'total_quantity' => 'float',
        'returned_quantity' => 'float',
        'balance_quantity' => 'float',
    ];

    public function items()
    {
        return $this->hasMany(IssueTransactionItem::class)->orderBy('sort_order')->withTrashed();
    }

    public function logs()
    {
        return $this->hasMany(IssueReturnLog::class)->latest();
    }

    public function returns()
    {
        return $this->hasMany(IssueReturn::class)->latest('return_date')->withTrashed();
    }

    public function scopeFilter($query, array $filters)
    {
        $query
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(function ($sq) use ($search) {
                $sq->where('issue_number', 'like', "%{$search}%")
                    ->orWhere('project', 'like', "%{$search}%")
                    ->orWhere('issued_to', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            }))
            ->when($filters['issue_number'] ?? null, fn ($q, $v) => $q->where('issue_number', 'like', "%{$v}%"))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['project'] ?? null, fn ($q, $v) => $q->where('project', 'like', "%{$v}%"))
            ->when($filters['issued_to'] ?? null, fn ($q, $v) => $q->where('issued_to', 'like', "%{$v}%"))
            ->when($filters['contact_number'] ?? null, fn ($q, $v) => $q->where('contact_number', 'like', "%{$v}%"))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['created_by'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->whereHas('items', fn ($iq) => $iq->where('item_id', $v)));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function refreshTotalsAndStatus(): void
    {
        $lines = IssueTransactionItem::query()
            ->where('issue_transaction_id', $this->id)
            ->whereNull('deleted_at')
            ->get(['issued_quantity', 'returned_quantity', 'balance_quantity']);

        $totalQty = (float) $lines->sum('issued_quantity');
        $returnedQty = (float) $lines->sum('returned_quantity');
        $balanceQty = max(0, (float) $lines->sum('balance_quantity'));

        $status = 'open';
        if ($balanceQty <= 0) {
            $status = 'fully_returned';
        } elseif ($returnedQty > 0) {
            $status = 'partially_returned';
        } elseif ($this->expected_return_date && $this->expected_return_date->isPast()) {
            $status = 'overdue';
        }

        $this->forceFill([
            'total_items' => $lines->count(),
            'total_quantity' => $totalQty,
            'returned_quantity' => $returnedQty,
            'balance_quantity' => $balanceQty,
            'status' => $status,
        ])->save();
    }
}
