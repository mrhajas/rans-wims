<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkSmsRecipientBookEntry extends Model
{
    protected $table = 'bulk_sms_recipient_book';

    protected $fillable = ['name', 'phone', 'use_count', 'last_used_at'];

    protected $casts = [
        'use_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    /**
     * Called once per recipient after a real send attempt (success or
     * failure — a wrong number is still worth remembering was tried) so the
     * next compose's autocomplete offers it. Never overwrites a
     * user-supplied name with a blank one on a later resend of the same
     * number.
     */
    public static function remember(string $name, string $phone): void
    {
        $entry = static::firstOrNew(['phone' => $phone]);
        if ($name !== '') {
            $entry->name = $name;
        }
        $entry->use_count = ($entry->use_count ?? 0) + 1;
        $entry->last_used_at = now();
        $entry->save();
    }
}
