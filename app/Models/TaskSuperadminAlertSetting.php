<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskSuperadminAlertSetting extends Model
{
    protected $fillable = ['account_id', 'is_enabled', 'updated_by_user_id'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    /** On by default for an account that has never touched this setting — only an explicit row (from toggling it in the UI) overrides that. */
    public static function isEnabledFor(int $accountId): bool
    {
        $row = static::where('account_id', $accountId)->first();

        return $row ? (bool) $row->is_enabled : true;
    }
}
