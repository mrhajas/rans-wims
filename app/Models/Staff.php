<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Notifications\Notifiable;

class Staff extends Model
{
    use HasAttachments;
    use Notifiable;

    // Laravel's pluraliser treats "Staff" as an uncountable noun and would
    // otherwise infer the table name as "staff" (singular) instead of the
    // actual "staffs" table.
    protected $table = 'staffs';

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const GENDERS = ['Male', 'Female'];

    public const EMPLOYMENT_TYPES = ['Full time', 'Part time', 'Freelancing', 'Internship'];

    /** "Retired" has left payroll; "Retired Active" is retired but is still paid through payroll. */
    public const STATUSES = ['Active', 'Retired Active', 'Retired', 'Inactive', 'Terminated'];

    /** Who the salary module prepares, advances and loans for. */
    public const PAYROLL_STATUSES = ['Active', 'Retired Active'];

    protected $fillable = [
        'staff_number', 'user_id', 'photo', 'name', 'qualification', 'date_of_birth', 'gender', 'employment_type',
        'address', 'personal_email', 'mobile_no',
        'father_name', 'father_contact_no',
        'bank_name', 'account_holder_name', 'account_no', 'tin_number', 'nssf_number',
        'position', 'join_date', 'basic_salary', 'allowances', 'deductions', 'net_salary', 'status', 'retired_at', 'retirement_note',
        'created_by_user_id', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'date_of_birth' => 'date:Y-m-d',
        'join_date' => 'date:Y-m-d',
        'retired_at' => 'date:Y-m-d',
        'basic_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
    ];

    /** The system login this employee uses, if linked (see UserStaffLinkController). */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * Starting values for a staff profile created FROM a user — whatever the user record already knows, so
     * nobody retypes it. Deliberately leaves join date and salary empty: a guessed join date would make the
     * payroll engine pro-rate the first salary, and pay figures are for HR to enter.
     */
    public static function defaultsFromUser(User $user): array
    {
        $phone = data_get($user->extra_attributes, 'phone')
            ?: \App\Models\WimsConnectRecipientChannel::where('user_id', $user->id)->where('channel', 'sms')->value('address')
            ?: $user->two_factor_phone;

        return [
            'name' => $user->name,
            'personal_email' => $user->email,
            'mobile_no' => $phone,
            'position' => optional($user->roles()->orderBy('id')->first())->name,
            'employment_type' => 'Full time',
            'status' => $user->is_active === false ? 'Inactive' : 'Active',
            'user_id' => $user->id,
        ];
    }

    /** A mobile number is always stored as +255XXXXXXXXX; something that is not a valid number is kept as typed (the forms reject it). */
    public function setMobileNoAttribute($value): void
    {
        $this->attributes['mobile_no'] = \App\Support\TzPhone::normalize($value) ?? (filled($value) ? trim((string) $value) : null);
    }

    protected static function booted(): void
    {
        parent::booted(); // keeps the deleted_by / restored hooks of the base Model ($tracksDeletes = true)

        // User::profile_photo_url prefers the linked staff photo; keep its per-request map in step.
        static::saved(fn () => User::forgetLinkedStaffPhotos());
        static::saved(function (Staff $staff) {
            try {
                if ($staff->user_id && ($user = User::find($staff->user_id))) {
                    \App\Services\WimsConnect\RecipientDirectory::syncUser($user);
                }
            } catch (\Throwable) {
                // refreshed the next time Recipients is opened
            }
        });
        static::deleted(fn () => User::forgetLinkedStaffPhotos());

        // A deleted (or soft-deleted) staff profile must free its login so it can be linked again.
        static::deleted(function (Staff $staff) {
            if ($staff->user_id) {
                $staff->forceFill(['user_id' => null])->saveQuietly();
            }
        });
    }

    public function contracts()
    {
        return $this->hasMany(StaffContract::class)->orderByDesc('start_date')->orderByDesc('id');
    }

    /** The contract in force: the active one that started last. */
    public function currentContract()
    {
        return $this->hasOne(StaffContract::class)->where('status', StaffContract::STATUS_ACTIVE)->latest('start_date')->latest('id');
    }

    /** People the salary module may pay (Active, and Retired Active who are still on payroll). */
    public function scopePayroll($query)
    {
        return $query->whereIn('status', self::PAYROLL_STATUSES);
    }

    public function paymentVouchers()
    {
        return $this->hasMany(PaymentVoucher::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // Staff has no `email` column (Notifiable's default lookup) — its
    // address field is `personal_email`.
    public function routeNotificationForMail(): ?string
    {
        return $this->personal_email;
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['employment_type'] ?? null, fn ($q, $t) => $q->where('employment_type', $t))
            ->when($filters['contract'] ?? null, function ($q, $c) {
                $today = \Carbon\CarbonImmutable::today();
                match ($c) {
                    'expiring' => $q->whereHas('currentContract', fn ($x) => $x->whereBetween('end_date', [$today->toDateString(), $today->addDays(StaffContract::SOON_DAYS)->toDateString()])),
                    'expired' => $q->whereHas('currentContract', fn ($x) => $x->where('end_date', '<', $today->toDateString())),
                    'open' => $q->whereHas('currentContract', fn ($x) => $x->whereNull('end_date')),
                    'permanent' => $q->whereHas('currentContract', fn ($x) => $x->where('type', 'Permanent')),
                    'none' => $q->doesntHave('currentContract'),
                    default => null,
                };
            })
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('staff_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('mobile_no', 'like', "%{$search}%");
                });
            });
    }
}
