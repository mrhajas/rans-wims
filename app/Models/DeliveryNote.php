<?php

namespace App\Models;

class DeliveryNote extends Model
{
    public const SOURCE_TRANSFER = 'transfer';

    public const SOURCE_CHECKIN = 'checkin';

    public const SOURCE_EXTERNAL = 'external_stock';

    protected $fillable = ['account_id', 'reference', 'source_type', 'source_id', 'created_by_user_id', 'extra_attributes'];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The Transfer or Checkin this note was generated from (null if it was permanently removed). */
    public function parent(): ?Model
    {
        return match ($this->source_type) {
            self::SOURCE_TRANSFER => Transfer::withTrashed()->find($this->source_id),
            self::SOURCE_CHECKIN => Checkin::withTrashed()->find($this->source_id),
            self::SOURCE_EXTERNAL => ExternalStockMovement::withTrashed()->find($this->source_id),
            default => null,
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            self::SOURCE_TRANSFER => 'Transfer',
            self::SOURCE_EXTERNAL => 'External Stock',
            default => 'Check-in',
        };
    }

    public static function nextReference(): string
    {
        $maxSuffix = (int) \Illuminate\Support\Facades\DB::table('delivery_notes')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'DN-%')
            ->value('max_suffix');

        return 'DN-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }
}
