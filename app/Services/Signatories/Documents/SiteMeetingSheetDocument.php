<?php

namespace App\Services\Signatories\Documents;

use App\Models\SiteMeeting;
use Illuminate\Support\Carbon;

/**
 * Site meeting sheet: the one-page meeting sheet (date, venue, agenda, invited people). The minutes have their own document
 * (site_meeting_minutes).
 *
 * Real storage on the meeting row:
 *  - scheduled  created_by + created_at  (GOOD): the person who scheduled the meeting in WIMS. The columns prepared_by and recorder_id are
 *               ASSIGNED people (a Super Admin may name somebody else), not people who acted, so they are not used.
 *
 * The right-hand line of the sheet is the chairperson, an assigned role with no recorded action: it stays as printed.
 *
 * State: a meeting is "in_progress" whatever its status (Scheduled, Held, Postponed or Cancelled): the sheet has no closing workflow, so it
 * is never frozen and always shows the scheduler's current usable signature. There is no finalisation to guard.
 */
final class SiteMeetingSheetDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'site_meeting_sheet';
    }

    public static function label(): string
    {
        return 'Site meeting sheet';
    }

    public static function actions(): array
    {
        return ['scheduled' => 'Scheduled (meeting created)'];
    }

    public function find(int $id): ?object
    {
        return SiteMeeting::query()->find($id);
    }

    public function state(object $record): string
    {
        return 'in_progress';
    }

    public function history(object $record): array
    {
        $at = $record->created_at instanceof Carbon ? $record->created_at : ($record->created_at ? Carbon::parse($record->created_at) : null);
        $ok = $record->created_by && $at;
        if (! $record->created_by && ! $at) {
            return [];
        }

        return ['scheduled' => [
            'user_id' => $record->created_by ? (int) $record->created_by : null, 'at' => $at, 'name' => null,
            'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : 'The person who scheduled this meeting, or when, is not recorded.',
        ]];
    }

    public static function defaultMappings(): array
    {
        // Left line "Prepared by (Site Engineer)" -> the person who scheduled the meeting. The chair line is not mapped (no recorded action).
        return [
            ['role' => 'prepared_by', 'action' => 'scheduled', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
