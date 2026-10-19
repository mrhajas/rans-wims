<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The financial audit trail. Append-only from the application (nothing here updates or deletes), and tamper-evident: every row stores a
 * hash of itself and the row before it, so changing or removing a row anywhere breaks the chain, which `verify()` finds.
 * Banking secrets, passwords and tokens are never written: callers pass only what is needed.
 */
class AcctAudit
{
    private static ?string $correlation = null;

    public static function correlation(): string
    {
        return self::$correlation ??= (string) Str::uuid();
    }

    public static function log(string $event, string $entityType, $entityId, ?string $reference = null, ?array $old = null, ?array $new = null, ?string $reason = null, ?User $by = null): AcctAuditEvent
    {
        $by ??= auth()->user();
        $role = $by ? $by->getRoleNames()->implode(', ') : null;
        $conn = DB::connection('accounts');
        // The chain must be written one record at a time, in order: each row is hashed together with the row before it, so "read the last row, then insert"
        // has to be one indivisible step, and it must hold until the row is committed. Inside a caller's database transaction that is until the caller
        // commits; outside one we open a short transaction of our own. (Reading the last row in autocommit and inserting afterwards lets a second writer
        // chain from the same row: two rows then claim the same predecessor and verify() fails.)
        // The writers queue on the FIRST audit row, which never changes and is locked by its primary key (a record lock only). Locking "the last row" instead
        // also takes the gap after it, and two writers then deadlock: one holds the row, the other the gap, each waiting for the other.
        $write = function () use ($conn, $event, $entityType, $entityId, $reference, $old, $new, $reason, $by, $role) {
            $first = $conn->table('acct_audit')->orderBy('id')->value('id');
            if ($first !== null) {
                $conn->table('acct_audit')->where('id', $first)->lockForUpdate()->value('id');
            }
            $last = $conn->table('acct_audit')->orderByDesc('id')->lockForUpdate()->first(['id', 'hash']);
            $prev = (string) ($last->hash ?? '');
            $row = [
                'event' => $event, 'entity_type' => $entityType, 'entity_id' => $entityId, 'reference' => $reference, 'user_id' => $by?->id, 'role_context' => $role,
                'old_values' => $old === null ? null : json_encode($old), 'new_values' => $new === null ? null : json_encode($new), 'reason' => $reason,
                'ip' => request()->ip(), 'device' => substr((string) request()->userAgent(), 0, 250), 'correlation_id' => self::correlation(), 'created_at' => now()->toDateTimeString(),
            ];
            $row['hash'] = hash('sha256', $prev . '|' . json_encode($row));

            return $conn->table('acct_audit')->insertGetId($row);
        };
        $id = $conn->transactionLevel() > 0 ? $write() : $conn->transaction($write, 3);

        return AcctAuditEvent::find($id);
    }

    /** Walk the chain and return the id of the first row that does not match its hash, or null when it is intact. */
    public static function verify(): ?int
    {
        $prev = '';
        foreach (DB::connection('accounts')->table('acct_audit')->orderBy('id')->cursor() as $r) {
            $row = (array) $r;
            $hash = $row['hash'];
            unset($row['id'], $row['hash']);
            if (hash('sha256', $prev . '|' . json_encode($row)) !== $hash) {
                return (int) $r->id;
            }
            $prev = $hash;
        }

        return null;
    }
}
