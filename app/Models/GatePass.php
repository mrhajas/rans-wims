<?php

namespace App\Models;

class GatePass extends Model
{
    public const SOURCE_TRANSFER = 'transfer';

    public const SOURCE_CHECKIN = 'checkin';

    public const SOURCE_EXTERNAL = 'external_stock';

    protected $fillable = [
        'account_id', 'reference', 'source_type', 'source_id',
        'driver_contact_id', 'driver_name', 'driver_phone',
        'asset_id', 'vehicle_plate', 'vehicle_details',
        'created_by_user_id', 'extra_attributes',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function driver()
    {
        return $this->belongsTo(Contact::class, 'driver_contact_id')->withTrashed();
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id')->withTrashed();
    }

    /** The Transfer or Checkin this pass was generated from (null if it was permanently removed). */
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

    /** Driver as printed: the registered driver when one was picked (so renames follow), otherwise what was typed. */
    public function driverDisplay(): array
    {
        if ($this->driver) {
            return ['name' => $this->driver->name, 'phone' => $this->driver->phone];
        }

        return ['name' => $this->driver_name, 'phone' => $this->driver_phone];
    }

    /** Vehicle as printed: the registered vehicle when one was picked, otherwise what was typed. */
    public function vehicleDisplay(): array
    {
        if ($this->asset) {
            $a = $this->asset;
            $details = collect([$a->manufacturer, $a->model, $a->year, $a->color])->filter()->implode(' ');

            return [
                'plate' => $a->registration_number ?: $a->name,
                'details' => $details ?: $a->name,
                'type' => $a->name,
            ];
        }

        return ['plate' => $this->vehicle_plate, 'details' => $this->vehicle_details, 'type' => null];
    }

    public static function nextReference(): string
    {
        $maxSuffix = (int) \Illuminate\Support\Facades\DB::table('gate_passes')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'GP-%')
            ->value('max_suffix');

        return 'GP-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }
}
