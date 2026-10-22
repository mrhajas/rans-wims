<?php

namespace App\Models;

/** One line of the history of a visit, an item or a visitor: what happened, who did it, when, the reason, and the value before and after. Never edited or deleted. */
class VisitorLogEvent extends Model
{
    public const UPDATED_AT = null;

    public static $hasAccount = true;

    protected $table = 'visitor_log_events';

    protected $fillable = ['account_id', 'subject_type', 'subject_id', 'subject_ref', 'event', 'user_id', 'reason', 'old_value', 'new_value'];

    public $casts = ['old_value' => 'array', 'new_value' => 'array', 'created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Writes one history line. Never throws. */
    public static function record(string $type, Model $subject, string $event, array $old = [], array $new = [], ?string $reason = null): void
    {
        try {
            static::create(['subject_type' => $type, 'subject_id' => $subject->getKey(), 'subject_ref' => $subject->reference ?? null, 'event' => $event, 'user_id' => optional(auth()->user())->id,
                'reason' => $reason, 'old_value' => $old ?: null, 'new_value' => $new ?: null]);
        } catch (\Throwable $e) {
            \Log::warning('Visitor log event not written', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
