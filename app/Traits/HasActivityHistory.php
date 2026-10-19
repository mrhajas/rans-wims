<?php

namespace App\Traits;

use Spatie\Activitylog\Models\Activity;

/**
 * Reads back the `activity_log` rows a model's own log_activity() calls
 * wrote, keyed by subject_type/subject_id — the model itself doesn't need
 * Spatie's LogsActivity trait for this to work, since log_activity()
 * already stamps performedOn($model) on every entry (see
 * ProjectMaterialLedgerController::activityHistory(), the original of this
 * pattern, which this trait was extracted from for reuse).
 */
trait HasActivityHistory
{
    private function activityHistory(string $modelClass, int $modelId): array
    {
        if (! class_exists(Activity::class)) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', $modelClass)
            ->where('subject_id', $modelId)
            ->with('causer:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'description' => $a->description,
                'causer' => $a->causer?->name ?? __('System'),
                // When this was logged during impersonation, the causer above
                // is deliberately the real Super Admin (see App\Models\Activity
                // ::booted()) — this surfaces who they were acting as, so the
                // UI can show both instead of only the audit-trail identity.
                'actingAs' => $a->properties['impersonation']['effective_user_name'] ?? null,
                'created_at' => $a->created_at?->toDateTimeString(),
                'properties' => $a->properties,
            ])
            ->values()
            ->all();
    }
}
