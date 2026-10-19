<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One scan-to-open code of one record. The record keeps no QR field: the code is this row, made the first time it is asked for. */
class QrCode extends Model
{
    protected $table = 'qr_codes';

    protected $fillable = ['account_id', 'type', 'record_id', 'token', 'scans_count', 'last_scanned_at', 'last_scanned_by', 'created_by', 'revoked_at', 'revoked_by'];

    protected $casts = ['last_scanned_at' => 'datetime', 'revoked_at' => 'datetime', 'scans_count' => 'integer'];
}
