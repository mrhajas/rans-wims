<?php

namespace App\Services\Signatories\Documents;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatusLog;
use Illuminate\Support\Carbon;

/**
 * Material Request. What WIMS really records:
 *  - the requester: material_requests.user_id + created_at;
 *  - every status change and every procedure save: material_request_status_logs (user_id, status, created_at);
 *  - the final clearance: cleared_by_user_id + cleared_at (the same moment as the "Cleared" log).
 * There is no approved_by column, so the approval is the LATEST genuine "Approved" log.
 *
 * The real workflow (MaterialRequestPolicy::allowedTargetStatuses):
 *   Pending Approval -> Approved | Rejected (Approve step) -> Partially Issued / Issued (Issue step) -> Cleared (Complete step, the last one).
 * Procurement saves procedures in between ("Update Procedure" logs). Printed label vs real meaning (a mismatch in the old block, kept as is when
 * the module is off): "Reviewed By (Procurement / Store Manager)" prints cleared_by/cleared_at, which is the LAST step (Complete), not a
 * procurement review, and "Final Approval By" prints the Approve step, which is the FIRST decision. The new block therefore maps the actions by
 * what they really are: requested = Prepared By, procedures = Reviewed By (the Procurement review), approved = Approved By; "issued" and "cleared"
 * (completion) can be added in the document setup.
 *
 * Traps handled:
 *  - Editing a request writes a log row carrying the CURRENT status ("Approved" for an approved request that is edited), which is not an
 *    approval. A log row that repeats the status of the previous workflow row is therefore an edit and is ignored (except Partially Issued,
 *    which legitimately repeats for another batch).
 *  - A request that is Rejected or still Pending shows no approval; an approval is reported only while the status is at or past Approved and
 *    the last Approve-step decision was Approved.
 *  - Requests in Approved-or-later statuses that have no Approved log (legacy data) get no approval: the line stays blank.
 */
final class MaterialRequestDocument implements SignableDocument
{
    private const RANK = [
        MaterialRequest::STATUS_DRAFT => 0, MaterialRequest::STATUS_REJECTED => 0, MaterialRequest::STATUS_PENDING_APPROVAL => 1,
        MaterialRequest::STATUS_APPROVED => 2, MaterialRequest::STATUS_PARTIALLY_ISSUED => 3, MaterialRequest::STATUS_ISSUED => 4,
        MaterialRequest::STATUS_CLEARED => 5, MaterialRequest::STATUS_CLOSED => 5,
    ];

    private const PROCEDURE_LOG = 'Update Procedure';

    public static function key(): string
    {
        return 'material_request';
    }

    public static function label(): string
    {
        return 'Material Request';
    }

    public static function actions(): array
    {
        return [
            'requested' => 'Requested (request created by the requester)',
            'approved' => 'Approved (Approve step)',
            'procedures' => 'Reviewed (Procurement procedures set)',
            'issued' => 'Issued (Issue step)',
            'cleared' => 'Cleared (final Complete step)',
        ];
    }

    public function find(int $id): ?object
    {
        return MaterialRequest::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            MaterialRequest::STATUS_DRAFT => 'draft',
            MaterialRequest::STATUS_CLEARED, MaterialRequest::STATUS_CLOSED => 'final',
            default => 'in_progress', // pending, approved, partially / fully issued, rejected (closed to further signing but never approved)
        };
    }

    public function history(object $record): array
    {
        $h = [];
        if ($record->user_id || $record->created_at) {
            $h['requested'] = $this->entry($record->user_id, $record->created_at, $record->user_id ? null : 'The requester is not recorded.');
        }

        $rank = self::RANK[$record->status] ?? null;
        if ($rank === null) {
            return $h;
        }
        $logs = $this->logs($record);

        if ($rank >= 2) {
            $decision = $this->lastOf($logs, [MaterialRequest::STATUS_APPROVED, MaterialRequest::STATUS_REJECTED]);
            if ($decision && $decision->status === MaterialRequest::STATUS_APPROVED) {
                $h['approved'] = $this->fromLog($decision);
            }
            if ($proc = $this->lastOf($logs, [self::PROCEDURE_LOG])) {
                $h['procedures'] = $this->fromLog($proc);
            }
        }
        if ($rank >= 3 && ($issued = $this->lastOf($logs, [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED]))) {
            $h['issued'] = $this->fromLog($issued);
        }
        if ($rank >= 5) {
            $cleared = $record->status === MaterialRequest::STATUS_CLEARED ? $this->lastOf($logs, [MaterialRequest::STATUS_CLEARED]) : null;
            if ($cleared) {
                $h['cleared'] = $this->fromLog($cleared);
            } elseif ($record->cleared_by_user_id || $record->cleared_at) {
                $note = $record->status === MaterialRequest::STATUS_CLOSED ? 'Closed is a legacy status; the clearance columns are the only record.' : null;
                $note = $note ?: (! $record->cleared_by_user_id ? 'The person who cleared this request is not recorded.' : (! $record->cleared_at ? 'The date and time of the clearance are not recorded.' : null));
                $h['cleared'] = $this->entry($record->cleared_by_user_id, $record->cleared_at, $note);
            } elseif ($record->status === MaterialRequest::STATUS_CLEARED) {
                $h['cleared'] = $this->entry(null, null, 'The request is Cleared but nobody and no time are recorded for it.');
            }
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        // Header wording: Requested By and Cleared By; signing lines: Reviewed By (Procurement review), Approved By.
        return [
            ['role' => 'requested_by', 'action' => 'requested', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'reviewed_by', 'action' => 'procedures', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
            ['role' => 'cleared_by', 'action' => 'cleared', 'slot' => 4, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?: ('Material Request #' . $record->id));
    }

    /** A copy of the request as it will be once $userId clears it (nothing is saved): for the finalisation guard. */
    public function asIfCleared(object $record, int $userId): object
    {
        $copy = clone $record;
        $copy->forceFill(['status' => MaterialRequest::STATUS_CLEARED, 'cleared_by_user_id' => $userId, 'cleared_at' => now()]);

        return $copy;
    }

    /**
     * The genuine workflow rows in order: status-change rows plus procedure saves, with edit rows (same status as the previous workflow row)
     * dropped. Delivery updates are not part of the sign-off.
     * @return list<MaterialRequestStatusLog>
     */
    private function logs(object $record): array
    {
        $workflow = [MaterialRequest::STATUS_DRAFT, MaterialRequest::STATUS_PENDING_APPROVAL, MaterialRequest::STATUS_APPROVED, MaterialRequest::STATUS_REJECTED,
            MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED, MaterialRequest::STATUS_CLEARED, MaterialRequest::STATUS_CLOSED];
        $out = [];
        $prev = null;
        foreach (MaterialRequestStatusLog::query()->where('material_request_id', $record->id)->orderBy('id')->get() as $log) {
            if ($log->status === self::PROCEDURE_LOG) {
                $out[] = $log;
                continue;
            }
            if (! in_array($log->status, $workflow, true)) {
                continue;
            }
            $isEdit = $log->status === $prev && $log->status !== MaterialRequest::STATUS_PARTIALLY_ISSUED;
            $prev = $log->status;
            if (! $isEdit) {
                $out[] = $log;
            }
        }

        return $out;
    }

    private function lastOf(array $logs, array $statuses): ?MaterialRequestStatusLog
    {
        $found = null;
        foreach ($logs as $l) {
            if (in_array($l->status, $statuses, true)) {
                $found = $l;
            }
        }

        return $found;
    }

    private function fromLog(MaterialRequestStatusLog $log): array
    {
        return $this->entry($log->user_id, $log->created_at, $log->user_id ? ($log->created_at ? null : 'The date and time of this action are not recorded.') : 'The person who did this is not recorded.');
    }

    private function entry(?int $userId, $at, ?string $note): array
    {
        return [
            'user_id' => $userId ? (int) $userId : null,
            'at' => $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : null),
            'name' => null,
            'quality' => $note ? 'partial' : 'good',
            'note' => $note,
        ];
    }
}
