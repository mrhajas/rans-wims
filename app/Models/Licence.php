<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/** One WIMS licence. The state shown to people (active, expiring, expired...) is worked out from the status and the dates. */
class Licence extends Model
{
    public const ISSUED = 'Issued';

    public const ACTIVE = 'Active';

    public const DEACTIVATED = 'Deactivated';

    public const SUPERSEDED = 'Superseded';

    public const EDITIONS = ['Standard', 'Professional', 'Enterprise'];

    public const TYPES = ['Subscription', 'Trial', 'Renewal', 'Extension'];

    /** A licence with this many days or fewer left is shown as "expiring". */
    public const WARN_DAYS = 30;

    protected $guarded = [];

    protected $hidden = ['key'];

    protected $casts = [
        'starts_at' => 'date',
        'expires_at' => 'date',
        'issued_at' => 'datetime',
        'applied_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'meta' => 'array',
        'max_users' => 'integer',
    ];

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function appliedBy()
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    public function deactivatedBy()
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }

    public function events()
    {
        return $this->hasMany(LicenceEvent::class)->latest('id');
    }

    /** active | expiring | expired | scheduled | deactivated | superseded | issued */
    public function getStateAttribute(): string
    {
        return match ($this->status) {
            self::DEACTIVATED => 'deactivated',
            self::SUPERSEDED => 'superseded',
            self::ISSUED => 'issued',
            default => $this->runningState(),
        };
    }

    private function runningState(): string
    {
        $today = today();
        if ($this->starts_at->gt($today)) {
            return 'scheduled';
        }
        if ($this->expires_at->lt($today)) {
            return 'expired';
        }

        return $this->days_left <= self::WARN_DAYS ? 'expiring' : 'active';
    }

    /** True while the licence lets people use WIMS. */
    public function getIsValidAttribute(): bool
    {
        return in_array($this->state, ['active', 'expiring'], true);
    }

    /** Whole days from today to the last valid day (0 on the last day, negative once expired). */
    public function getDaysLeftAttribute(): int
    {
        return (int) today()->diffInDays($this->expires_at, false);
    }

    public function getDurationDaysAttribute(): int
    {
        return (int) $this->starts_at->diffInDays($this->expires_at);
    }

    /** "2 years", "6 months", "45 days" */
    public function getDurationLabelAttribute(): string
    {
        $from = $this->starts_at->copy();
        $to = $this->expires_at->copy();
        $months = (int) $from->diffInMonths($to);
        if ($months >= 1 && $from->copy()->addMonths($months)->equalTo($to)) {
            return $months % 12 === 0 ? ($months / 12).' '.str('year')->plural($months / 12) : $months.' '.str('month')->plural($months);
        }

        return $this->duration_days.' days';
    }

    /** Share of the licence period already used, 0 to 100. */
    public function getElapsedPercentAttribute(): int
    {
        $total = max(1, $this->duration_days);
        $used = min($total, max(0, (int) $this->starts_at->diffInDays(today(), false)));

        return (int) round($used / $total * 100);
    }

    /** The moment the licence stops working (end of its last day). */
    public function endsAtMoment(): Carbon
    {
        return $this->expires_at->copy()->endOfDay();
    }

    /** The 128-character key split in 8 lines of 16 characters (4 blocks of 4) for display and print. */
    public function keyBlocks(): array
    {
        return array_map(fn (string $line): array => str_split($line, 4), str_split((string) $this->getRawOriginal('key'), 16));
    }
}
