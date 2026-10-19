<?php

namespace App\Models;

use App\Traits\HasAttachments;

class Task extends Model
{
    use HasAttachments;

    // No account_id column — matches the current house convention for new
    // modules (Case Tracking, Workforce Agreements): a team-shared board
    // isn't per-account data the way most of the rest of the app is.
    public static $hasAccount = false;

    public static $tracksDeletes = true;

    protected $fillable = [
        'title', 'description', 'status', 'priority', 'category', 'position',
        'start_date', 'start_time', 'due_date', 'due_time', 'completed_at', 'warehouse_id', 'created_by_user_id',
        'is_private',
    ];

    public $casts = [
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'is_private' => 'boolean',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignees()
    {
        return $this->hasMany(TaskAssignee::class);
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function checklistItems()
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position');
    }
}
