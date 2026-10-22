<?php

namespace App\Models;

use App\Traits\FastPermissionChecks;
use App\Traits\LogActivity;
use App\Traits\Paginatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Laravel\Jetstream\HasProfilePhoto;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto {
        profilePhotoUrl as protected jetstreamProfilePhotoUrl;
    }
    use HasRoles, FastPermissionChecks {
        HasRoles::hasPermissionTo as protected spatieHasPermissionTo;
        HasRoles::checkPermissionTo as protected spatieCheckPermissionTo;
        FastPermissionChecks::hasPermissionTo insteadof HasRoles;
        FastPermissionChecks::checkPermissionTo insteadof HasRoles;
    }
    use LogActivity;
    use Notifiable;
    use Paginatable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * Field-personnel roles treated identically everywhere the app special-cases
     * "Site Engineer" by role name (as opposed to the ordinary permission system).
     * Engineers/Architect/Draftsman/Technician are kept in lockstep here — add a
     * new field-role name to this one array, not to the individual call sites,
     * and every hasRole()/whereIn('name', ...) check below stays in sync. The
     * legacy 'Site Engineers'/'Site Engineer' strings are kept for any account
     * whose role wasn't renamed.
     */
    const SITE_ENGINEER_ROLES = ['Site Engineers', 'Site Engineer', 'Engineers', 'Architect', 'Draftsman', 'Technician'];

    public function isSiteEngineerRole(): bool
    {
        return $this->hasRole(self::SITE_ENGINEER_ROLES);
    }

    /**
     * Overrides HasProfilePhoto's default of storing the uploaded file
     * as-is — avatars only ever render at a few dozen pixels in the UI, but
     * uploads were coming in at multi-megapixel camera/upscaled resolutions
     * (one real example was 2478x3186, 656KB), shipped to every viewer at
     * full size. Downscales to a 400px-max square before storing.
     */
    public function updateProfilePhoto(\Illuminate\Http\UploadedFile $photo, $storagePath = 'profile-photos')
    {
        $resized = $this->resizedProfilePhotoContents($photo, 400);

        tap($this->profile_photo_path, function ($previous) use ($photo, $resized, $storagePath) {
            $filename = $photo->hashName();
            \Illuminate\Support\Facades\Storage::disk($this->profilePhotoDisk())->put(
                $storagePath . '/' . $filename,
                $resized ?? file_get_contents($photo->getRealPath())
            );

            $this->forceFill([
                'profile_photo_path' => $storagePath . '/' . $filename,
            ])->save();

            if ($previous) {
                \Illuminate\Support\Facades\Storage::disk($this->profilePhotoDisk())->delete($previous);
            }
        });
    }

    private function resizedProfilePhotoContents(\Illuminate\Http\UploadedFile $photo, int $maxDimension): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $path = $photo->getRealPath();
        [$width, $height, $type] = @getimagesize($path) ?: [0, 0, null];
        if (! $width || ! $height || $width <= $maxDimension && $height <= $maxDimension) {
            return null;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => null,
        };
        if (! $source) {
            return null;
        }

        $scale = $maxDimension / max($width, $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $type === IMAGETYPE_PNG ? imagepng($resized, null, 8) : imagejpeg($resized, null, 85);
        $contents = ob_get_clean();

        imagedestroy($source);
        imagedestroy($resized);

        return $contents ?: null;
    }

    /**
     * Overrides CanResetPassword's default of sending Laravel's own
     * Illuminate\Auth\Notifications\ResetPassword (a generic Markdown mail)
     * so a password reset request instead gets this app's own branded
     * template — see App\Notifications\ResetPasswordNotification.
     */
    /**
     * WIMS notification emails go to the email on this person's WIMS Connect > Recipients entry (the contact book): that entry decides
     * the address and whether email is on at all. Anything that is not an app notification (password resets, login codes) still uses the
     * address of the login.
     */
    /** Notices whose email a person can switch off in their own profile: AppNotification type => users column. */
    public const PERSONAL_EMAIL_OPT_OUTS = [
        'assets_expiry_reminder' => 'asset_expiry_email_enabled',
        'compliance_reminder' => 'compliance_reminder_email_enabled',
        'compliance_escalation' => 'compliance_reminder_email_enabled',
    ];

    public function routeNotificationForMail($notification = null)
    {
        if ($notification instanceof \App\Notifications\AppNotification) {
            // a personal "no email for this kind of notice" choice (the bell notice still arrives)
            $column = self::PERSONAL_EMAIL_OPT_OUTS[($notification->toArray($this)['type'] ?? '')] ?? null;
            if ($column && ! $this->{$column}) {
                return null;
            }
            return $this->contactEmail();
        }

        return $this->email;
    }

    /**
     * Where this person's alert emails go: the email switched on for them in WIMS Connect > Recipients; none when it is switched off or
     * empty there; the address of the login only when Recipients has nothing about them at all.
     */
    public function contactEmail(): ?string
    {
        $row = \App\Models\WimsConnectRecipientChannel::where('user_id', $this->id)->where('channel', 'email')->first();
        if ($row) {
            return ($row->is_enabled && filled($row->address)) ? $row->address : null;
        }

        return filled($this->email) ? $this->email : null;
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    protected $appends = ['profile_photo_url', 'all_permissions'];

    /** The employee (Staff) record this login is linked to, if any. */
    public function staff()
    {
        return $this->hasOne(Staff::class, 'user_id');
    }

    /** True when any second factor is on: authenticator app, Email code or SMS code. */
    public function hasAnyTwoFactorMethod(): bool
    {
        return ! empty($this->two_factor_secret) || ! empty($this->two_factor_email_enabled_at) || ! empty($this->two_factor_sms_enabled_at);
    }

    protected $casts = ['email_verified_at' => 'datetime', 'extra_attributes' => 'array', 'is_active' => 'boolean', 'disabled_at' => 'datetime', 'chime_enabled' => 'boolean', 'quick_menu_enabled' => 'boolean', 'last_seen_at' => 'datetime', 'terms_accepted_at' => 'datetime', 'two_factor_email_enabled_at' => 'datetime', 'two_factor_sms_enabled_at' => 'datetime'];

    protected $fillable = [
        'name', 'email', 'username', 'password', 'view_all', 'edit_all', 'warehouse_id', 'extra_attributes',
        'task_reminders_email_enabled', 'chime_enabled', 'quick_menu_enabled', 'idle_timeout_minutes',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_recovery_codes', 'two_factor_secret'];

    protected $with = ['account', 'roles:id,name'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouse');
    }

    public function disabledBy()
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function manages($warehouseId)
    {
        if ($this->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        return $this->warehouses()->whereKey($warehouseId)->exists();
    }

    public function checkins()
    {
        return $this->hasMany(Checkin::class);
    }

    public function checkouts()
    {
        return $this->hasMany(Checkout::class);
    }

    public function materialRequests()
    {
        return $this->hasMany(MaterialRequest::class);
    }

    public function materialRequestStatusLogs()
    {
        return $this->hasMany(MaterialRequestStatusLog::class);
    }

    public function securityReminderRecipients()
    {
        return $this->hasMany(SecurityReminderRecipient::class);
    }

    /**
     * material_requests.user_id and digital_attendance_records/events.user_id
     * all use cascadeOnDelete() — without this guard, permanently deleting a
     * user (e.g. a former employee) silently hard-deletes every Material
     * Request they ever made and their entire attendance audit trail, with
     * no warning and no recovery path. Only checked in delP() — a plain
     * soft-delete via del() doesn't cascade at the DB level, so it can't
     * destroy this history the same way.
     */
    public function hasIrreplaceableHistory(): bool
    {
        return $this->materialRequests()->exists()
            || \App\Models\DigitalAttendanceRecord::where('user_id', $this->id)->exists();
    }

    public function del()
    {
        if ($this->checkins()->exists() || $this->checkouts()->exists()) {
            return false;
        }

        return $this->delete();
    }

    public function delP()
    {
        if ($this->checkins()->exists() || $this->checkouts()->exists() || $this->hasIrreplaceableHistory()) {
            return false;
        }

        log_activity(__choice('delete_text', ['record' => 'User']), $this, $this, 'User');
        $this->roles()->detach();

        return $this->forceDelete();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    /**
     * `all_permissions` is only for the signed-in user (the front-end `$can()` reads it from auth.user). Without this,
     * every nested user in a list (a Resource's `user:id,name`) dragged the full permission list along — ~26 KB per row,
     * 3.8 MB for one Adjustments page — and ran a permission query for each.
     */
    protected function getArrayableAppends()
    {
        $appends = parent::getArrayableAppends();

        if (! auth()->check() || (int) auth()->id() !== (int) $this->getKey()) {
            $appends = array_values(array_diff($appends, ['all_permissions']));
        }

        return $appends;
    }

    /** The signed-in user appears many times in one page's data (every comment, log line and name of theirs): their permission list is worked out once, for a moment. */
    private static array $permissionNamesMemo = [];

    public function getAllPermissionsAttribute()
    {
        $memo = self::$permissionNamesMemo[$this->getKey()] ?? null;
        if ($memo && microtime(true) - $memo[0] < 2) {
            return $memo[1];
        }
        $names = $this->getAllPermissions()->pluck('name');
        self::$permissionNamesMemo[$this->getKey()] = [microtime(true), $names];

        // getAllPermissions() eager-loads every role's full permission list onto the model, which would then be
        // serialised into auth.user.roles (~50 KB on every page). Only the role names are needed there.
        $this->roles->each->unsetRelation('permissions');

        return $names;
    }

    public function getRoleNamesAttribute()
    {
        return $this->roles->pluck('name');
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return in_array(SoftDeletes::class, class_uses($this))
            ? $this->where($this->getRouteKeyName(), $value)->withTrashed()->first()
            : parent::resolveRouteBinding($value);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('username', 'like', '%' . $search . '%');
            });
        })->when($filters['role'] ?? null, function ($query, $role) {
            $query->whereRole($role);
        })->when($filters['trashed'] ?? null, function ($query, $trashed) {
            if ($trashed === 'with') {
                $query->withTrashed();
            } elseif ($trashed === 'only') {
                $query->onlyTrashed();
            }
        });
    }

    public function scopeOfAccount($query, $account_id = null)
    {
        return $query->where('account_id', $account_id ?? auth()->user()->account_id ?? 1);
    }

    public function scopeOrderByName($query)
    {
        $query->orderBy('last_name')->orderBy('first_name');
    }

    public function scopeWhereRole($query, $role)
    {
        switch ($role) {
            case 'user': return $query;
            case 'owner': return $query;
        }
    }

    protected static function booted()
    {
        // static::addGlobalScope('account', function (Builder $builder) {
        //     $builder->where('account_id', auth()->user()->account_id);
        // });

        static::creating(function ($model) {
            if (! $model->account_id) {
                $model->account_id = auth()->user() ? auth()->user()->account_id : 1;
            }
        });

        // Recipients (the contact book) pulls the person's details from their records; a pulled address waits to be enabled
        static::saved(function (User $user) {
            try {
                \App\Services\WimsConnect\RecipientDirectory::syncUser($user);
            } catch (\Throwable) {
                // the contact book is refreshed again the next time Recipients is opened
            }
        });
    }

    /**
     * A user linked to a staff profile shows that profile's photo everywhere instead of their own uploaded
     * one; unlinked users (or linked staff without a photo) keep the normal profile photo.
     */
    public function profilePhotoUrl(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(function (): string {
            $staffPhoto = static::linkedStaffPhotos()[$this->id] ?? null;
            // a staff photo whose file is not on this server must not hide the user's own photo (or the default avatar)
            if ($staffPhoto && ! is_file(\Illuminate\Support\Facades\Storage::disk('assets')->path($staffPhoto))) {
                $staffPhoto = null;
            }

            return $staffPhoto
                ? \Illuminate\Support\Facades\Storage::disk('assets')->url($staffPhoto)
                : ($this->jetstreamProfilePhotoUrl()->get)();
        });
    }

    private static ?array $linkedStaffPhotoMap = null;

    /** user_id => staff photo path, loaded once per request (the staff table is small; avoids a query per user in lists). */
    public static function linkedStaffPhotos(): array
    {
        return static::$linkedStaffPhotoMap ??= Staff::query()->whereNotNull('user_id')->whereNotNull('photo')->pluck('photo', 'user_id')->all();
    }

    public static function forgetLinkedStaffPhotos(): void
    {
        static::$linkedStaffPhotoMap = null;
    }

    protected function defaultProfilePhotoUrl()
    {
        // No uploaded photo: the shared blue person-in-a-circle placeholder (public/images/default-avatar.svg).
        return url('/images/default-avatar.svg');
    }
}
