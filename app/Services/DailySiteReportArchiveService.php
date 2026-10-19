<?php

namespace App\Services;

use App\Models\DailySiteReport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class DailySiteReportArchiveService
{
    /**
     * Deletes only the physical files, keeping the attachment metadata rows intact
     * so archived reports can still list what was uploaded (title, size, type).
     */
    public static function archive(DailySiteReport $report, ?User $actor, bool $isAuto): void
    {
        $fileNames = $report->attachments->pluck('title')->filter()->values();

        foreach ($report->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->filepath);
        }

        $report->update([
            'status' => DailySiteReport::STATUS_ARCHIVED,
            'archived_by' => $actor?->id,
            'archived_at' => now(),
            'auto_archived' => $isAuto,
        ]);

        $fileList = $fileNames->isNotEmpty() ? $fileNames->implode(', ') : 'none';

        $report->logs()->create([
            'user_id' => $actor?->id,
            'role' => $actor ? optional($actor->roles->first())->name : null,
            'action' => $isAuto ? 'auto_archived' : 'archived',
            'remarks' => $isAuto
                ? 'Report auto-archived after ' . config('daily_site_reports.auto_archive_after_days') . ' days. Attachments removed: ' . $fileList . '.'
                : 'Report archived by ' . ($actor?->name ?? 'system') . '. Attachments removed: ' . $fileList . '.',
            'ip_address' => $isAuto ? null : request()->ip(),
            'user_agent' => $isAuto ? null : request()->userAgent(),
        ]);

        // Site Reports workflow page: "Site Report Archived" (by a person or by the nightly clean-up)
        try {
            $report->loadMissing(['warehouse:id,name', 'submittedBy:id,name']);
            $message = $isAuto
                ? $report->report_number . ' was archived automatically after the retention period. Its attachment files were removed.'
                : $report->report_number . ' was archived by ' . ($actor?->name ?? 'a user') . '. Its attachment files were removed.';
            \App\Services\Workflow\WorkflowMessenger::dispatch('site_reports', 'archived', $report, $actor, [
                'account_id' => $report->account_id ?? $actor?->account_id ?? 1, 'title' => __('Site Report Archived'), 'message' => $message,
                'url' => route('daily-site-reports.show', $report->id), 'skip_actor' => true,
                'owner_user_id' => (int) ($report->submitted_by ?? 0), 'warehouse_ids' => array_filter([$report->warehouse_id]),
                'values' => ['reference' => (string) $report->report_number, 'project' => optional($report->warehouse)->name ?? '', 'creator' => optional($report->submittedBy)->name ?? ''],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Site report archive notice failed', ['report_id' => $report->id, 'error' => $e->getMessage()]);
        }
    }
}
