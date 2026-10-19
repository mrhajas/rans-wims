<?php

namespace App\Traits;

use App\Models\PemAuditLog;
use Illuminate\Database\Eloquent\Model;

trait LogsPemAudit
{
    protected function logPemAudit(Model $loggable, string $action, ?array $previousValue = null, ?array $newValue = null, ?string $remarks = null): PemAuditLog
    {
        $user = auth()->user();
        $request = request();
        $info = user_agent_info($request->userAgent());

        return $loggable->auditLogs()->create([
            'user_id' => $user?->id,
            'role' => $user?->roles?->first()?->name,
            'action' => $action,
            'previous_value' => $previousValue,
            'new_value' => $newValue,
            'ip_address' => $request->ip(),
            'browser' => $info['browser'],
            'device' => $info['device'],
            'remarks' => $remarks,
        ]);
    }
}
