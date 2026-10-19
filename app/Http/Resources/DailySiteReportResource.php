<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DailySiteReportResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'warehouse_id' => $this->warehouse_id,
            'report_type' => $this->report_type,
            'priority' => $this->priority,
            'report_date' => $this->report_date?->format('Y-m-d'),
            'days_left_to_archive' => $this->daysLeftToArchive(),
            'remarks' => $this->remarks,
            'submitted_by' => $this->submitted_by,
            'status' => $this->status,
            'schedule_status' => $this->schedule_status,
            'viewed_by' => $this->viewed_by,
            'viewed_at' => $this->viewed_at,
            'downloaded_by' => $this->downloaded_by,
            'downloaded_at' => $this->downloaded_at,
            'archived_by' => $this->archived_by,
            'archived_at' => $this->archived_at,
            'auto_archived' => $this->auto_archived,
            'submitted_at' => $this->submitted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'can_edit' => $user ? $user->can('update', $this->resource) : false,
            'can_delete' => $user ? $user->can('delete', $this->resource) : false,
            'can_download' => $user ? $user->can('download', $this->resource) : false,
            'can_print' => $user ? $user->can('print', $this->resource) : false,
            'can_archive' => $user ? $user->can('archive', $this->resource) : false,
            'warehouse' => $this->whenLoaded('warehouse'),
            'submitted_by_user' => $this->whenLoaded('submittedBy'),
            'viewed_by_user' => $this->whenLoaded('viewedBy'),
            'downloaded_by_user' => $this->whenLoaded('downloadedBy'),
            'archived_by_user' => $this->whenLoaded('archivedBy'),
            'logs' => $this->whenLoaded('logs'),
            'attachments' => $this->whenLoaded('attachments'),
        ];
    }

    /**
     * Days remaining until this report becomes eligible for the nightly
     * auto-archive run (see AutoArchiveDailySiteReports) — null once it's
     * already archived. Mirrors that command's own cutoff exactly: a report
     * is eligible once `report_date` is more than N days old, so the last
     * "safe" day is `report_date + N`, and this counts down to it.
     */
    private function daysLeftToArchive(): ?int
    {
        if ($this->status === \App\Models\DailySiteReport::STATUS_ARCHIVED || ! $this->report_date) {
            return null;
        }

        $days = (int) (get_settings('daily_site_report_auto_archive_days') ?: config('daily_site_reports.auto_archive_after_days'));
        $archiveDate = $this->report_date->copy()->addDays($days)->startOfDay();
        $today = now()->startOfDay();

        return (int) floor(($archiveDate->getTimestamp() - $today->getTimestamp()) / 86400);
    }
}
