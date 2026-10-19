<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One unresolved condition on the subcontract accounts (see SubcontractAlerts). */
class SubcontractAlert extends Eloquent
{
    protected $guarded = [];

    protected $casts = ['read_at' => 'datetime', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime', 'last_seen_at' => 'datetime', 'escalated_at' => 'datetime'];

    public const CATEGORIES = ['balance' => 'Balance', 'approval' => 'Approval', 'payment' => 'Payment', 'contract' => 'Contract', 'control' => 'Control', 'setup' => 'Setup'];

    public const SEVERITIES = ['info' => 'Info', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public static function nextReference(): string
    {
        $prefix = 'SCL-' . now()->format('Y') . '-';
        $max = (int) \Illuminate\Support\Facades\DB::table('subcontract_alerts')->where('reference', 'like', $prefix . '%')->selectRaw('MAX(CAST(SUBSTRING(reference, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) m')->value('m');

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
