<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Database\Eloquent\Builder;

class TaskReminder extends Model
{
    use HasAttachments;

    public static $hasUser = true;

    public static $tracksDeletes = true;

    protected static function booted()
    {
        parent::booted();

        // Personal reminders are private notes, not business/warehouse data —
        // unlike Checkins/Checkouts/etc., a user's `view_all` flag (meant for
        // account-wide operational visibility) must not let them read other
        // people's reminders. Only Super Admin sees everyone's, same as the
        // parent scope, just without the view_all exemption.
        static::addGlobalScope('mine', function (Builder $builder) {
            $user = auth()->user();
            if ($user && ! $user->hasRole('Super Admin')) {
                $builder->where('user_id', $user->id);
            }
        });
    }

    public $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'notified_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'is_completed' => 'boolean',
    ];

    protected $fillable = [
        'account_id', 'user_id', 'name', 'notes', 'due_at', 'priority', 'category',
        'is_completed', 'completed_at', 'notified_at', 'snoozed_until',
    ];

    protected $setUser = true;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
