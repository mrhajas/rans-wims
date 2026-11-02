<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One long, READ-ONLY job (a PDF report) prepared by a separate process. See App\Services\BackgroundTasks\TaskService.
 * `active_lock` is unique and is set only while the task is queued or running: that is the database's own guard against the same person starting the same task twice.
 */
class BackgroundTask extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $table = 'background_tasks';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'started_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'finished_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    protected $hidden = ['result_path', 'active_lock', 'pid'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** What the browser is told about a task (never the server path, the lock or the process id). */
    public function toToast(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'status' => $this->status,
            'message' => $this->message,
            'error' => $this->status === self::FAILED ? $this->error : null,
            'started_at' => optional($this->started_at ?? $this->created_at)->toIso8601String(),
            'finished_at' => optional($this->finished_at)->toIso8601String(),
            'has_file' => $this->status === self::DONE && $this->result_path !== null,
            'file_name' => $this->result_name,
            'file_size' => $this->result_size,
        ];
    }
}
