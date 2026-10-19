<?php

namespace App\Models;

class TaskAssignee extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['task_id', 'assignee_type', 'assignee_id', 'assigned_by_user_id'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function assignee()
    {
        return $this->morphTo();
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
