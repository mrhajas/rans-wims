<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One line of the history of a Travel Package: when, who, what changed, and the package (amounts and rates) as it was then. Written once, never edited. */
class TravelPackageAudit extends Eloquent
{
    public $timestamps = false;

    protected $table = 'travel_package_audits';

    protected $guarded = [];

    protected $casts = ['changes' => 'array', 'details' => 'array', 'created_at' => 'datetime', 'is_active' => 'boolean', 'exchange_rate' => 'decimal:6', 'ticket_amount' => 'decimal:2',
        'accommodation_amount' => 'decimal:2', 'per_diem_amount' => 'decimal:2', 'transport_amount' => 'decimal:2', 'total_per_person' => 'decimal:2'];

    /** Writes a line for a package: its snapshot now, what changed, and the person who did it. */
    public static function record(TravelPackage $p, string $action, array $changes = [], array $details = []): void
    {
        try {
            $u = auth()->user();
            static::create(['travel_package_id' => $p->id, 'action' => $action, 'user_id' => $u?->id, 'user_name' => $u?->name, 'changes' => $changes ?: null, 'details' => $details ?: null,
                'ip' => request()->ip(), 'created_at' => now()] + $p->snapshot());
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
