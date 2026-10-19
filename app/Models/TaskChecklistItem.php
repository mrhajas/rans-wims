<?php

namespace App\Models;

class TaskChecklistItem extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['task_id', 'title', 'is_done', 'position', 'created_by_user_id'];

    public $casts = ['is_done' => 'boolean'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
