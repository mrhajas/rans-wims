<?php

namespace App\Services\Signatories\Documents;

use App\Models\SiteMeeting;
use Spatie\Activitylog\Models\Activity;

/**
 * Minutes of a site meeting (the "report" side of a site_meetings row; the one-page meeting sheet is not covered).
 *
 * Real records, all on the meeting row: report_started_by/at (first time the minutes were opened by an editor), report_submitted_by/at,
 * report_finalised_by/at. The names printed on the old sign lines (recorder, chairperson) are ASSIGNED roles, not people who acted, and
 * prepared_by is the person who prepared the MEETING (agenda), so none of them is used here.
 *
 * Standing rules: reopening clears the finalised pair but leaves the submitted pair behind, so a submission only stands while the minutes are
 * Submitted or Finalised AND no "reopened" entry in the Site Meetings activity log is newer than it (a minutes reopened and finalised again
 * without a fresh submit has no standing submission).
 *
 * States: "draft" = Not started or Draft (still being written); "in_progress" = Submitted; "final" = Finalised.
 */
final class SiteMeetingMinutesDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'site_meeting_minutes';
    }

    public static function label(): string
    {
        return 'Site meeting minutes';
    }

    public static function actions(): array
    {
        return [
            'started' => 'Minutes started (first opened by an editor)',
            'submitted' => 'Minutes submitted',
            'finalised' => 'Minutes finalised and locked',
        ];
    }

    public function find(int $id): ?object
    {
        return SiteMeeting::query()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->report_status) {
            SiteMeeting::REPORT_FINAL => 'final',
            SiteMeeting::REPORT_SUBMITTED => 'in_progress',
            default => 'draft',
        };
    }

    public function history(object $record): array
    {
        $h = [];
        $status = $record->report_status;

        if ($status !== SiteMeeting::REPORT_NONE && ($record->report_started_by || $record->report_started_at)) {
            $h['started'] = [
                'user_id' => $record->report_started_by ? (int) $record->report_started_by : null, 'at' => StockSigningGuard::at($record->report_started_at), 'name' => null,
                'quality' => $record->report_started_by && $record->report_started_at ? 'good' : 'partial', 'note' => null,
            ];
        }

        if (in_array($status, [SiteMeeting::REPORT_SUBMITTED, SiteMeeting::REPORT_FINAL], true) && ($record->report_submitted_by || $record->report_submitted_at)
            && ! $this->reopenedAfter($record, $record->report_submitted_at)) {
            $h['submitted'] = [
                'user_id' => $record->report_submitted_by ? (int) $record->report_submitted_by : null, 'at' => StockSigningGuard::at($record->report_submitted_at), 'name' => null,
                'quality' => $record->report_submitted_by && $record->report_submitted_at ? 'good' : 'partial', 'note' => null,
            ];
        }

        if ($status === SiteMeeting::REPORT_FINAL) {
            $h['finalised'] = [
                'user_id' => $record->report_finalised_by ? (int) $record->report_finalised_by : null, 'at' => StockSigningGuard::at($record->report_finalised_at), 'name' => null,
                'quality' => $record->report_finalised_by && $record->report_finalised_at ? 'good' : 'partial',
                'note' => $record->report_finalised_by && $record->report_finalised_at ? null : 'The minutes are finalised but who finalised them, or when, was not stamped.',
            ];
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        // Left side "Prepared by (Site Engineer)" -> the person who submitted the minutes. The right side "Chaired by" is the ASSIGNED chairperson, who is not stamped as having acted, so it is not mapped to chaired_by; the finaliser (the only recorded second actor) signs as certified_by.
        return [
            ['role' => 'prepared_by', 'action' => 'submitted', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'certified_by', 'action' => 'finalised', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }

    /** True when the minutes were reopened after the given time (the old submission then no longer stands). */
    private function reopenedAfter(object $record, $submittedAt): bool
    {
        if (! $submittedAt) {
            return false;
        }

        return Activity::query()->where('log_name', 'Site Meetings')->where('event', 'report_reopen')
            ->where('subject_type', SiteMeeting::class)->where('subject_id', $record->id)
            ->where('created_at', '>', $submittedAt)->exists();
    }
}
