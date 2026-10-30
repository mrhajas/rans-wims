<?php

namespace App\Models;

use Carbon\Carbon;

/** One issued RANS ID or pass. The person is linked (Staff, Contact, Visitor) or written on the card; the number is never reused; the history lives in idpass_events and idpass_prints. */
class IdPassCard extends Model
{
    public const PENDING = 'Pending Approval';

    public const ACTIVE = 'Active';

    public const SUSPENDED = 'Suspended';

    public const REVOKED = 'Revoked';

    public const REJECTED = 'Rejected';

    public const REPLACED = 'Replaced';

    public const EXPIRED = 'Expired';          // never stored: an Active card whose expiry date has passed

    public const STATUSES = [self::PENDING, self::ACTIVE, self::SUSPENDED, self::REVOKED, self::REJECTED, self::REPLACED, self::EXPIRED];

    /** Statuses in which the card still occupies its person (no second card can be issued next to it). */
    public const OPEN = [self::PENDING, self::ACTIVE, self::SUSPENDED];

    public static $tracksDeletes = true;

    protected $table = 'idpass_cards';

    protected $fillable = ['account_id', 'kind', 'category_id', 'number', 'seq_no', 'token', 'status', 'staff_id', 'contact_id', 'visitor_id', 'full_name', 'title', 'phone', 'company', 'photo_path', 'photo_original',
        'issued_at', 'expires_at', 'host_name', 'purpose', 'authorisation_type', 'vehicle_registration', 'vehicle_description', 'restrictions', 'notes', 'replaces_id', 'replaced_by_id', 'created_by_user_id',
        'approved_by_user_id', 'approved_at', 'decision_reason', 'status_by_user_id', 'status_at', 'status_reason', 'print_count', 'last_printed_at', 'expiry_notified_at', 'deleted_by'];

    public $casts = ['issued_at' => 'date', 'expires_at' => 'date', 'approved_at' => 'datetime', 'status_at' => 'datetime', 'last_printed_at' => 'datetime', 'expiry_notified_at' => 'datetime', 'print_count' => 'int'];

    protected $hidden = ['token'];

    public function category()
    {
        return $this->belongsTo(IdPassCategory::class, 'category_id')->withTrashed();
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id')->withTrashed();
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id')->withTrashed();
    }

    public function openAssignment()
    {
        return $this->hasOne(IdPassAssignment::class, 'card_id')->whereNull('released_at')->latestOfMany();
    }

    public function events()
    {
        return $this->hasMany(IdPassEvent::class, 'card_id')->orderByDesc('id');
    }

    public function prints()
    {
        return $this->hasMany(IdPassPrint::class, 'card_id')->orderByDesc('id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The status a person should read: an Active card past its expiry date is Expired (never trust the stored word alone). */
    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === self::ACTIVE && $this->expires_at && $this->expires_at->lt(Carbon::today(config('app.timezone')))) {
            return self::EXPIRED;
        }

        return (string) $this->status;
    }

    /** Valid right now (the only state in which a gate may let the holder through). */
    public function isValid(): bool
    {
        return $this->effective_status === self::ACTIVE && $this->issued_at->lte(Carbon::today(config('app.timezone')));
    }

    public function daysToExpiry(): ?int
    {
        return $this->expires_at ? (int) Carbon::today(config('app.timezone'))->diffInDays($this->expires_at, false) : null;
    }

    public function scopeOfKind($q, string $kind)
    {
        return $q->where('kind', $kind);
    }

    /** Cards that are valid right now. */
    public function scopeValidNow($q)
    {
        $today = Carbon::today(config('app.timezone'))->toDateString();

        return $q->where('status', self::ACTIVE)->where('issued_at', '<=', $today)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today));
    }

    public function scopeExpired($q)
    {
        return $q->where('status', self::ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<', Carbon::today(config('app.timezone'))->toDateString());
    }

    public function verifyUrl(): string
    {
        return route('id-passes.verify.show', ['token' => $this->token]);
    }
}
