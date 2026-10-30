<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One line of the life of a card. Written once, never edited, never deleted. */
class IdPassEvent extends Eloquent
{
    public const UPDATED_AT = null;

    public $timestamps = true;

    protected $table = 'idpass_events';

    protected $guarded = [];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(IdPassCard $card, string $event, ?string $from = null, ?string $to = null, array $detail = []): void
    {
        static::create(['card_id' => $card->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'user_id' => auth()->id(), 'ip' => request()?->ip(), 'detail' => $detail ?: null]);
    }
}
