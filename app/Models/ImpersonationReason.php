<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ImpersonationReason extends Model
{
    protected $fillable = ['account_id', 'text', 'use_count', 'last_used_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    /** Records a use of $text for $accountId — creates it on first use, bumps count/recency after. */
    public static function record(int $accountId, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $row = static::firstOrNew(['account_id' => $accountId, 'text' => Str::limit($text, 191, '')]);
        $row->use_count = ($row->use_count ?? 0) + 1;
        $row->last_used_at = now();
        $row->save();
    }
}
