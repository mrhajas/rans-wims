<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WimsConnectLog extends Model
{
    protected $fillable = [
        'idempotency_key', 'event_key', 'source_type', 'source_id',
        'recipient_user_id', 'address', 'channel', 'message',
        'status', 'error_message', 'retry_count', 'sent_at', 'delivered_at', 'subject', 'body_html', 'meta',
    ];

    protected $casts = [
        'retry_count' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'meta' => 'array',
    ];

    public const STATUSES = ['QUEUED', 'SENDING', 'SENT', 'DELIVERED', 'FAILED', 'RETRYING'];

    public function source()
    {
        return $this->morphTo();
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * $dedupeScope exists for recurring/digest-style events (a daily low-stock
     * summary, a future BOQ-threshold check) whose "source" — a Warehouse, a
     * BOQ row — doesn't change between runs. Without it, every run after the
     * first would collide with day one's key and get silently skipped, which
     * is correct for one-shot transaction events (a Transfer only dispatches
     * once) but wrong for anything that's meant to repeat. Pass something
     * like today's date; one-shot events simply omit it.
     */
    public static function buildIdempotencyKey(string $eventKey, ?string $sourceType, ?int $sourceId, int $recipientUserId, string $channel, ?string $dedupeScope = null): string
    {
        return implode(':', [$eventKey, $sourceType ?? 'none', $sourceId ?? 0, $recipientUserId, $channel, $dedupeScope ?? '-']);
    }
}
