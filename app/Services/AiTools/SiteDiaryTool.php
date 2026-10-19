<?php

namespace App\Services\AiTools;

use App\Models\SiteDiary;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;
use App\Services\FeatureManager;

/**
 * ARIS answers about the Site Diary. Every handler checks the Site Diary permission and the Global Feature switch and
 * only ever reads through SiteDiary::listQuery(), which applies the user's project access and own/all visibility — so
 * ARIS can never reveal a diary the person could not open themselves. Photo / video bytes are never touched.
 */
class SiteDiaryTool
{
    use ResolvesEntities;

    private function denied(User $user): ?array
    {
        if (! FeatureManager::canAccess('site_diary', $user) || ! $user->can('read-site-diary')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to Site Diary.'];
        }

        return null;
    }

    private function scoped(User $user, array $input, array &$filters): ?array
    {
        if (! empty($input['project_or_warehouse_name'])) {
            $w = $this->resolveWarehouse($input['project_or_warehouse_name']);
            if (! $w) {
                return ['found' => false, 'message' => "I couldn't find a project matching \"{$input['project_or_warehouse_name']}\"."];
            }
            $filters['warehouse_id'] = $w->id;
            $filters['_project'] = $w->name;
        }
        $period = $input['period'] ?? null;
        if ($period === 'today') {
            $filters['date_from'] = $filters['date_to'] = now()->toDateString();
        } elseif ($period === 'month') {
            $filters['date_from'] = now()->startOfMonth()->toDateString();
            $filters['date_to'] = now()->toDateString();
        }

        return null;
    }

    public function todayList(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $filters = [];
        if ($e = $this->scoped($user, $input + ['period' => 'today'], $filters)) {
            return $e;
        }
        $rows = SiteDiary::listQuery($user, array_diff_key($filters, ['_project' => 1]))->limit(10)->get();

        return ['found' => true, 'count' => $rows->count(), 'project' => $filters['_project'] ?? null, 'items' => $rows->map(fn ($r) => "{$r->project_name} - {$r->recorder_name} ({$r->photos_count} photo(s))")->all()];
    }

    public function count(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $filters = [];
        if ($e = $this->scoped($user, $input, $filters)) {
            return $e;
        }
        $n = SiteDiary::listQuery($user, array_diff_key($filters, ['_project' => 1]))->reorder()->count();

        return ['found' => true, 'count' => $n, 'project' => $filters['_project'] ?? null, 'period' => $input['period'] ?? 'all'];
    }

    public function latestRecorder(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $filters = [];
        if ($e = $this->scoped($user, $input, $filters)) {
            return $e;
        }
        $row = SiteDiary::listQuery($user, array_diff_key($filters, ['_project' => 1]))->first();

        return $row ? ['found' => true, 'by' => $row->recorder_name, 'project' => $row->project_name, 'date' => $row->diary_date->format('d M Y'), 'reference' => $row->reference]
            : ['found' => false, 'message' => 'No site diaries were found.'];
    }

    public function photoCount(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        if (! $user->can('view-site-diary-photos')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to Site Diary photos.'];
        }
        $filters = [];
        if ($e = $this->scoped($user, $input, $filters)) {
            return $e;
        }
        $n = (int) SiteDiary::listQuery($user, array_diff_key($filters, ['_project' => 1]))->reorder()->sum('site_diaries.photos_count');

        return ['found' => true, 'photos' => $n, 'project' => $filters['_project'] ?? null];
    }

    public function latestRemarks(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $filters = [];
        if ($e = $this->scoped($user, $input, $filters)) {
            return $e;
        }
        $rows = SiteDiary::listQuery($user, array_diff_key($filters, ['_project' => 1]))->whereNotNull('site_diaries.remarks')->where('site_diaries.remarks', '!=', '')->limit(3)->get();

        return $rows->isEmpty() ? ['found' => false, 'message' => 'No site remarks were found.']
            : ['found' => true, 'project' => $filters['_project'] ?? null, 'items' => $rows->map(fn ($r) => $r->diary_date->format('d M').' - '.$r->project_name.' ('.$r->recorder_name.'): '.str($r->remarks)->limit(160))->all()];
    }
}
