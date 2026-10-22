<?php

namespace App\Models;

/** One visit: a visitor came to see someone for a purpose, was checked in, and (later) checked out. The check-in time is never overwritten by the check-out. */
class VisitorVisit extends Model
{
    use \App\Traits\HasAttachments;

    public const IN = 'Checked In';

    public const OUT = 'Checked Out';

    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::IN, self::OUT, self::CANCELLED];

    public static $tracksDeletes = true;

    protected $table = 'visitor_visits';

    protected $setHash = true;

    protected $fillable = ['account_id', 'reference', 'hash', 'visitor_id', 'visit_date', 'check_in_at', 'check_out_at', 'duration_minutes', 'status', 'host_user_id', 'host_name', 'department', 'purpose', 'purpose_other',
        'appointment_ref', 'companions', 'vehicle_registration', 'remarks', 'registered_by_user_id', 'checked_out_by_user_id', 'cancelled_by_user_id', 'cancelled_at', 'cancel_reason', 'long_stay_alerted_at', 'deleted_by', 'warehouse_id', 'extra_attributes'];

    public $casts = ['visit_date' => 'date', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'cancelled_at' => 'datetime', 'long_stay_alerted_at' => 'datetime', 'extra_attributes' => 'array'];

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id')->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id')->withTrashed();
    }

    public function host()
    {
        return $this->belongsTo(User::class, 'host_user_id')->withTrashed();
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(VisitorIncomingItem::class, 'visit_id');
    }

    public static function nextReference(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('visitor_visits')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'VIS-%')->value('m');

        return 'VIS-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Whole minutes between check-in and now (or the check-out). */
    public function minutesOnPremises(): int
    {
        $end = $this->check_out_at ?: now();

        return max(0, (int) $this->check_in_at->diffInMinutes($end));
    }

    public static function durationText(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? $h . 'h ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'm' : $m . 'm';
    }

    /** Who sees which visits: everyone with the "all visits" right, otherwise the visits the person registered or was visited in. */
    public function scopeVisibleTo($q, User $u)
    {
        if ($u->hasRole('Super Admin')) {
            return $q;
        }
        $mine = fn ($w) => $w->where('registered_by_user_id', $u->id)->orWhere('host_user_id', $u->id);
        if ($u->can('view-all-visits')) {
            // everything of the projects the person works in (and visits with no project)
            $ids = \App\Services\VisitorLogs\VisitorSettings::accessibleWarehouseIds($u);

            return $ids === null ? $q : $q->where(fn ($w) => $w->whereNull('warehouse_id')->orWhereIn('warehouse_id', $ids ?: [0])->orWhere($mine));
        }

        return $q->where($mine);
    }

    public function scopeActive($q)
    {
        return $q->where('status', self::IN);
    }

    public function canBeSeenBy(User $u): bool
    {
        return $u->hasRole('Super Admin') || (int) $this->registered_by_user_id === $u->id || (int) $this->host_user_id === $u->id
            || ($u->can('view-all-visits') && $this->inMyProjects($u));
    }

    public function inMyProjects(User $u): bool
    {
        $ids = \App\Services\VisitorLogs\VisitorSettings::accessibleWarehouseIds($u);

        return $ids === null || ! $this->warehouse_id || in_array((int) $this->warehouse_id, $ids, true);
    }
}
