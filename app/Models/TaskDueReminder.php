<?php

namespace App\Models;

class TaskDueReminder extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['task_id', 'user_id'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
