<?php

namespace App\Services\Signatories\Documents;

use App\Models\MaterialReallocation;
use App\Models\MaterialReallocationLog;
use Illuminate\Support\Carbon;

/**
 * Material Reallocation (return of material to a warehouse). Real records, all on the row: requester user_id + created_at; submitted_on;
 * cleared_by_user_id/cleared_on (Project Manager clearance); warehouse_allocated_by_user_id/_on; dispatched_by_user_id/dispatch_date_time (typed
 * by the user, so it is when the dispatch happened, not when it was keyed in); received_by_user_id/received_date_time;
 * final_approved_by_user_id/final_approval_date_time (+ completed_on). material_reallocation_logs holds one row per transition (user_id, action,
 * created_at) across every Request Changes cycle.
 *
 * Workflow: Draft -> Submitted -> Project Manager Clearance -> Warehouse Allocation -> Dispatch Preparation -> Warehouse Receiving -> Completed,
 * with Request Changes (back to the requester, from Submitted or PM Clearance), Rejected and Cancelled as exits. Request Changes does NOT clear
 * the stamped columns, and a resubmission only re-stamps submitted_on, so a record sent back after clearance keeps its old cleared_by/cleared_on.
 * An action is therefore reported only when
 *  - the current status is at or past that stage (a Submitted record after a new cycle shows no clearance), and
 *  - it was stamped at or after the latest submission (submitted_on): anything older belongs to a previous cycle. A record sent back with
 *    Request Changes keeps only the request and the submission; a Rejected or Cancelled record keeps the stages of its last cycle.
 * The submitter has no column of its own, so it comes from the "submitted" log row of the last cycle (without it the actor is reported as not recorded).
 */
final class MaterialReallocationDocument implements SignableDocument
{
    private const RANK = [
        MaterialReallocation::STATUS_DRAFT => 0, MaterialReallocation::STATUS_SUBMITTED => 1, MaterialReallocation::STATUS_PM_CLEARANCE => 2,
        MaterialReallocation::STATUS_WAREHOUSE_ALLOCATION => 3, MaterialReallocation::STATUS_DISPATCH_PREPARATION => 4,
        MaterialReallocation::STATUS_WAREHOUSE_RECEIVING => 5, MaterialReallocation::STATUS_COMPLETED => 6,
    ];

    public static function key(): string
    {
        return 'material_reallocation';
    }

    public static function label(): string
    {
        return 'Material Reallocation';
    }

    public static function actions(): array
    {
        return [
            'requested' => 'Requested (request created by the Site Engineer)',
            'submitted' => 'Submitted (for Project Manager clearance)',
            'cleared' => 'PM Clearance (cleared by the Project Manager)',
            'warehouse_allocated' => 'Warehouse allocation',
            'dispatched' => 'Dispatched',
            'received' => 'Received (at the destination warehouse)',
            'final_approved' => 'Final approval (completed)',
        ];
    }

    public function find(int $id): ?object
    {
        return MaterialReallocation::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            MaterialReallocation::STATUS_DRAFT => 'draft',
            MaterialReallocation::STATUS_COMPLETED => 'final',
            default => 'in_progress', // submitted ... receiving, request changes, rejected, cancelled
        };
    }

    public function history(object $record): array
    {
        $h = [];
        if ($record->user_id || $record->created_at) {
            $h['requested'] = $this->entry($record->user_id, $record->created_at, $record->user_id ? null : 'The requester is not recorded.');
        }
        $status = $record->status;
        if ($status === MaterialReallocation::STATUS_DRAFT || ! $record->submitted_on) {
            return $h;
        }
        $logs = $this->lastCycleLogs($record);

        // submitted: the time is on the row; the person only in the log of the last submission
        $submit = $logs['submitted'] ?? null;
        $h['submitted'] = $this->entry($submit?->user_id, $record->submitted_on, $submit ? ($submit->user_id ? null : 'The person who submitted this is not recorded.') : 'The person who submitted this was not logged.');
        if ($status === MaterialReallocation::STATUS_REQUEST_CHANGES) {
            return $h; // sent back: nothing after the submission stands
        }

        $rank = self::RANK[$status] ?? null; // null: rejected / cancelled
        $stages = [
            'cleared' => [2, 'cleared_by_user_id', 'cleared_on'],
            'warehouse_allocated' => [3, 'warehouse_allocated_by_user_id', 'warehouse_allocated_on'],
            'dispatched' => [4, 'dispatched_by_user_id', 'dispatch_date_time'],
            'received' => [5, 'received_by_user_id', 'received_date_time'],
            'final_approved' => [6, 'final_approved_by_user_id', 'final_approval_date_time'],
        ];
        foreach ($stages as $action => [$need, $byCol, $atCol]) {
            if ($rank !== null && $rank < $need) {
                continue;
            }
            $by = $record->{$byCol};
            $at = $record->{$atCol};
            if (! $by && ! $at) {
                continue;
            }
            $log = $logs[$action] ?? null;
            if ($action === 'dispatched') {
                // the typed dispatch time may legitimately differ from when it was keyed in: the cycle test uses the log row when there is one
                if (! $log && $rank === null) {
                    continue;
                }
            } elseif ($at ? $at->lt($record->submitted_on) : ! $log) {
                continue; // stamped before the latest submission (an earlier Request Changes cycle), or no time and no log of this cycle to place it
            }
            $note = null;
            if (! $by) {
                $note = $log?->user_id ? null : 'The person who did this is not recorded.';
                $by = $log?->user_id;
            } elseif (! $at) {
                $note = 'The date and time of this action are not recorded.';
            }
            $h[$action] = $this->entry($by, $at, $note);
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        // Printed wording: Requested By, PM Clearance, Dispatched, Received, Final Approval. "submitted" (submit actor read from the cycle log, partial for older rows) and the warehouse allocation can be added as submitted_by / prepared_by in the setup.
        return [
            ['role' => 'requested_by', 'action' => 'requested', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'cleared_by', 'action' => 'cleared', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'dispatched_by', 'action' => 'dispatched', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
            ['role' => 'received_by', 'action' => 'received', 'slot' => 4, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'final_approved', 'slot' => 5, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?: ('Material Reallocation #' . $record->id));
    }

    /** A copy of the reallocation as it will be once $userId gives the final approval (nothing is saved): for the finalisation guard. */
    public function asIfFinalApproved(object $record, int $userId): object
    {
        $copy = clone $record;
        $copy->forceFill(['status' => MaterialReallocation::STATUS_COMPLETED, 'final_approved_by_user_id' => $userId, 'final_approval_date_time' => now(), 'completed_on' => now()]);

        return $copy;
    }

    /** The log rows written since the last "submitted" row, one per action (the latest wins). */
    private function lastCycleLogs(object $record): array
    {
        $rows = MaterialReallocationLog::query()->where('material_reallocation_id', $record->id)->orderBy('id')->get();
        $startId = $rows->where('action', 'submitted')->max('id');
        $out = [];
        foreach ($rows as $log) {
            if ($startId && $log->id < $startId) {
                continue;
            }
            $key = $log->action === 'allocated' ? 'warehouse_allocated' : $log->action;
            $out[$key] = $log;
        }

        return $out;
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
