<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WimsConnectGateway extends Model
{
    protected $fillable = [
        'driver', 'is_enabled', 'config', 'status',
        'last_success_at', 'last_failure_at', 'last_error', 'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'encrypted:array',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    protected $hidden = ['config'];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Config values for display only — every value truncated to its last 4
     * characters, matching the "••••••••1234" convention used elsewhere in
     * WIMS for secrets. Never expose the real config array to the frontend.
     */
    public function maskedConfig(): array
    {
        return collect($this->config ?? [])->map(function ($value) {
            $value = (string) $value;

            return strlen($value) <= 4 ? str_repeat('•', strlen($value)) : str_repeat('•', 8).substr($value, -4);
        })->all();
    }
}
