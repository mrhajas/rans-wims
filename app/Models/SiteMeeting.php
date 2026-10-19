<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Support\Carbon;

/**
 * One site meeting: when and where management meets at a site, who is invited, the agenda / info, and the minutes
 * document ("Management Meeting 07") the site engineers fill in — kept as sections of rows with the template snapshot
 * the meeting was created with.
 */
class SiteMeeting extends Model
{
    use HasAttachments;

    public const SCHEDULED = 'Scheduled';

    public const HELD = 'Held';

    public const POSTPONED = 'Postponed';

    public const CANCELLED = 'Cancelled';

    public const REPORT_NONE = 'Not started';

    public const REPORT_DRAFT = 'Draft';

    public const REPORT_SUBMITTED = 'Submitted';

    public const REPORT_FINAL = 'Finalised';

    // Everything that happens to a meeting is written explicitly by SiteMeetingLogger (with IP / device).
    protected static function eventsToBeRecorded(): \Illuminate\Support\Collection
    {
        return collect();
    }

    protected $table = 'site_meetings';

    public $casts = [
        'meeting_date' => 'date',
        'postponed_to' => 'date',
        'template' => 'array',
        'report_started_at' => 'datetime',
        'report_submitted_at' => 'datetime',
        'report_finalised_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function chairperson()
    {
        return $this->belongsTo(User::class, 'chairperson_id')->withTrashed();
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorder_id')->withTrashed();
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function attendees()
    {
        return $this->hasMany(SiteMeetingAttendee::class)->orderBy('sort')->orderBy('id');
    }

    public function rows()
    {
        return $this->hasMany(SiteMeetingRow::class)->orderBy('sort')->orderBy('id');
    }

    // ---- numbering ---------------------------------------------------------------------------------------------------

    public static function nextReference(): string
    {
        $prefix = 'SMT-'.now()->format('Y').'-';
        $last = static::withTrashed()->withoutGlobalScope('account')->where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** The next meeting number of a site ("Management Meeting 07"). */
    public static function nextMeetingNo(int $warehouseId): int
    {
        return ((int) static::withTrashed()->where('warehouse_id', $warehouseId)->max('meeting_no')) + 1;
    }

    public function getDisplayTitleAttribute(): string
    {
        $prefix = app(\App\Services\SiteMeetings\SiteMeetingSettings::class)->forAccount($this->account_id)->get('title_prefix');

        return $this->title ?: trim($prefix.' '.str_pad((string) $this->meeting_no, 2, '0', STR_PAD_LEFT));
    }

    /** A time like "09:30:00" → "09:30" (blank when not set). */
    public static function hm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    // ---- access ------------------------------------------------------------------------------------------------------

    private static array $siteIdMemo = [];

    /** Site ids the user is assigned to (null = every site: Super Admin, or an Admin with no assignment) — Site Diary's rule. */
    public static function siteIdsFor(User $user): ?array
    {
        if (array_key_exists($user->id, self::$siteIdMemo)) {
            return self::$siteIdMemo[$user->id];
        }

        return self::$siteIdMemo[$user->id] = $user->hasRole('Super Admin') ? null : SiteDiary::projectIdsFor($user);
    }

    /** True when the whole schedule of every site is open to the user (Super Admin, no assignment, or "view all"). */
    public static function seesEverySite(User $user): bool
    {
        return static::siteIdsFor($user) === null || $user->can('view-all-site-meetings');
    }

    /**
     * May this person WORK on the meeting (edit it, write / finalise its minutes, change its status, drag it)? Only for the sites
     * they are assigned to — or a meeting they scheduled / prepared / write the minutes of. Seeing the schedule is wider.
     */
    public function canWorkBy(User $user): bool
    {
        $ids = static::siteIdsFor($user);
        if ($ids === null || in_array((int) $this->warehouse_id, array_map('intval', $ids), true)) {
            return true;
        }

        return in_array((int) $user->id, array_map('intval', array_filter([$this->created_by, $this->prepared_by, $this->recorder_id])), true);
    }

    /** Meetings the user may WORK on / reuse: their own projects' or ones they prepared / wrote (everything for a Super Admin). */
    public function scopeWorkableBy($query, User $user)
    {
        $ids = static::siteIdsFor($user);
        if ($ids !== null) {
            $query->where(function ($q) use ($ids, $user) {
                $q->whereIn('site_meetings.warehouse_id', $ids ?: [0])
                    ->orWhere('site_meetings.created_by', $user->id)
                    ->orWhere('site_meetings.prepared_by', $user->id)
                    ->orWhere('site_meetings.recorder_id', $user->id);
            });
        }

        return $query;
    }

    public function scopeVisibleTo($query, User $user)
    {
        $ids = static::siteIdsFor($user);
        if (! static::seesEverySite($user)) {
            $query->where(function ($q) use ($ids, $user) {
                $q->whereIn('site_meetings.warehouse_id', $ids ?: [0])
                    ->orWhere('site_meetings.created_by', $user->id)
                    ->orWhere('site_meetings.prepared_by', $user->id)
                    ->orWhere('site_meetings.chairperson_id', $user->id)
                    ->orWhere('site_meetings.recorder_id', $user->id)
                    ->orWhereIn('site_meetings.id', SiteMeetingAttendee::query()->select('site_meeting_id')->where('user_id', $user->id));
            });
        }

        return $query;
    }

    public function isVisibleTo(User $user): bool
    {
        if (! $user->can('read-site-meetings')) {
            return false;
        }
        if (static::seesEverySite($user)) {
            return true;
        }
        $ids = static::siteIdsFor($user) ?? [];
        if (in_array((int) $this->warehouse_id, array_map('intval', $ids), true)) {
            return true;
        }

        return (int) $this->created_by === (int) $user->id || (int) $this->chairperson_id === (int) $user->id || (int) $this->recorder_id === (int) $user->id
            || $this->attendees()->where('user_id', $user->id)->exists();
    }

    public function reportLocked(): bool
    {
        return $this->report_status === self::REPORT_FINAL;
    }

    public function scopeFilter($query, array $f)
    {
        $query
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
                $q->where(function ($q) use ($like) {
                    $q->where('site_meetings.reference', 'like', $like)
                        ->orWhere('site_meetings.title', 'like', $like)
                        ->orWhere('site_meetings.venue', 'like', $like)
                        ->orWhere('site_meetings.agenda', 'like', $like)
                        ->orWhere('site_meetings.remarks', 'like', $like)
                        ->orWhereIn('site_meetings.warehouse_id', Warehouse::query()->select('id')->where('name', 'like', $like)->orWhere('code', 'like', $like));
                });
            })
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('site_meetings.warehouse_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('site_meetings.status', $v))
            ->when($f['report_status'] ?? null, fn ($q, $v) => $q->where('site_meetings.report_status', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('site_meetings.meeting_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('site_meetings.meeting_date', '<=', $v));

        return $query;
    }

    /** Monday-based (or per setting) week containing $date: [start, end] as Carbon. */
    public static function weekOf($date, int $weekStart = 1): array
    {
        $d = Carbon::parse($date)->startOfDay();
        $back = ($d->dayOfWeek - $weekStart + 7) % 7;
        $start = $d->copy()->subDays($back);

        return [$start, $start->copy()->addDays(6)];
    }
}
