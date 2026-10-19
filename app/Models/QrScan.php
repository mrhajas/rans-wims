<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One scan of a QR code, allowed or refused. The history behind QR Scans. */
class QrScan extends Model
{
    public $timestamps = false;

    protected $fillable = ['account_id', 'qr_code_id', 'user_id', 'type', 'record_id', 'kind', 'reference', 'status', 'reason', 'via', 'ip', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public const STATUS = [
        'allowed' => 'Allowed',
        'no_permission' => 'No permission',
        'unavailable' => 'Not available',
        'disabled' => 'Module off',
        'invalid' => 'Invalid code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
