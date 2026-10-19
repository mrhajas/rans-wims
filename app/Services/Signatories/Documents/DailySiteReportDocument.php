<?php

namespace App\Services\Signatories\Documents;

use App\Models\DailySiteReport;

/**
 * Daily site report (and the other report types filed through the same screen).
 *
 * Real records: submitted_by (the site engineer the report is filed under) and created_at (the real submission time; the model's
 * submitted_at accessor is just created_at). The 'created' row of daily_site_report_logs records who actually typed the report in: when an
 * Admin files a report on behalf of an engineer that row names the Admin, so the engineer did not submit it and no submission is reported.
 *
 * NOT used: viewed_by/viewed_at. It is set as a side effect of the first Super Admin/Admin/Project Manager who opens or prints the report
 * (it locks the report for the engineer); nobody is asked to review or confirm anything, so it is not a review or an approval and is not exposed.
 *
 * State: there is no approval step, a report exists only once it is submitted, so it is always "final" (the engineer's signature in force on the
 * submission time is frozen the first time it is printed, and later edits to remarks do not change who submitted it).
 */
final class DailySiteReportDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'daily_site_report';
    }

    public static function label(): string
    {
        return 'Daily site report';
    }

    public static function actions(): array
    {
        return ['submitted' => 'Submitted (by the site engineer)'];
    }

    public function find(int $id): ?object
    {
        return DailySiteReport::query()->withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return 'final';
    }

    public function history(object $record): array
    {
        if (! $record->submitted_by) {
            return [];
        }
        $created = $record->logs()->where('action', 'created')->orderBy('id')->first();
        if ($created && $created->user_id && (int) $created->user_id !== (int) $record->submitted_by) {
            // entered on behalf of the engineer: the engineer did not submit it
            return [];
        }

        return ['submitted' => [
            'user_id' => (int) $record->submitted_by, 'at' => StockSigningGuard::at($record->created_at), 'name' => null,
            'quality' => $created && $created->user_id && $record->created_at ? 'good' : 'partial',
            'note' => $created && $created->user_id ? null : 'The report log has no usable creation entry, so it cannot be confirmed that the engineer entered the report themselves.',
        ]];
    }

    public static function defaultMappings(): array
    {
        // The report is submitted by the site engineer ("Submitted At" / "Site Engineer" in the header).
        return [
            ['role' => 'submitted_by', 'action' => 'submitted', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->report_number ?? ('#' . $record->id));
    }
}
