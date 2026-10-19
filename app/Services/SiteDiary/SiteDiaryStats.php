<?php

namespace App\Services\SiteDiary;

use App\Models\SiteDiary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Dashboard / analytics figures. Aggregates only — never touches the media files or loads media rows. */
class SiteDiaryStats
{
    /** A raw query on site_diaries limited to what this user may see (same rules as SiteDiary::scopeVisibleTo). */
    private static function diaries(User $user, array $f)
    {
        $q = DB::table('site_diaries')->whereNull('site_diaries.deleted_at')->where('site_diaries.status', '!=', 'Draft')->where('site_diaries.account_id', $user->account_id);
        $ids = SiteDiary::projectIdsFor($user);
        if ($ids !== null) {
            $q->whereIn('site_diaries.warehouse_id', $ids ?: [0]);
        }
        if (! SiteDiary::canSeeAll($user)) {
            $q->where('site_diaries.recorded_by', $user->id);
        }
        $q->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('site_diaries.warehouse_id', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('site_diaries.diary_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('site_diaries.diary_date', '<=', $v));

        return $q;
    }

    public static function dashboard(User $user, array $f = []): array
    {
        $monthStart = now()->startOfMonth();
        $base = fn () => self::diaries($user, $f);

        $tot = $base()->selectRaw('COUNT(*) diaries, COALESCE(SUM(photos_count),0) photos, COALESCE(SUM(videos_count),0) videos, COUNT(DISTINCT warehouse_id) projects')->first();
        $month = $base()->where('site_diaries.created_at', '>=', $monthStart)->selectRaw('COUNT(*) diaries')->first();

        $mediaMonth = self::diaries($user, $f)
            ->join('site_diary_media', 'site_diary_media.site_diary_id', '=', 'site_diaries.id')
            ->whereNull('site_diary_media.deleted_at')
            ->where('site_diary_media.created_at', '>=', $monthStart)
            ->selectRaw("COALESCE(SUM(site_diary_media.media_type = 'photo'),0) photos, COALESCE(SUM(site_diary_media.media_type = 'video'),0) videos")
            ->first();

        $byProject = $base()->join('warehouses', 'warehouses.id', '=', 'site_diaries.warehouse_id')
            ->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('c')->limit(60)
            ->selectRaw('warehouses.id, warehouses.name label, COUNT(*) c, COALESCE(SUM(site_diaries.photos_count),0) photos, COALESCE(SUM(site_diaries.videos_count),0) videos, COALESCE(SUM(site_diaries.media_bytes),0) bytes')->get();

        $byUser = $base()->join('users', 'users.id', '=', 'site_diaries.recorded_by')
            ->groupBy('users.id', 'users.name')->orderByDesc('c')->limit(8)
            ->selectRaw('users.id, users.name label, COUNT(*) c')->get();

        $from = now()->subMonths(11)->startOfMonth();
        $uploads = self::diaries($user, $f)
            ->join('site_diary_media', 'site_diary_media.site_diary_id', '=', 'site_diaries.id')
            ->whereNull('site_diary_media.deleted_at')->where('site_diary_media.created_at', '>=', $from)
            ->groupBy('ym', 'site_diary_media.media_type')
            ->selectRaw("DATE_FORMAT(site_diary_media.created_at, '%Y-%m') ym, site_diary_media.media_type, COUNT(*) c")->get();

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $from->copy()->addMonths($i);
            $months[$d->format('Y-m')] = ['month' => $d->format('Y-m'), 'label' => $d->format('M'), 'photos' => 0, 'videos' => 0, 'count' => 0];
        }
        foreach ($uploads as $u) {
            if (isset($months[$u->ym])) {
                $months[$u->ym][$u->media_type === 'photo' ? 'photos' : 'videos'] = (int) $u->c;
                $months[$u->ym]['count'] += (int) $u->c;
            }
        }

        return [
            'cards' => [
                'diaries' => (int) $tot->diaries,
                'photos' => (int) $tot->photos,
                'videos' => (int) $tot->videos,
                'projects' => (int) $tot->projects,
                'diaries_month' => (int) $month->diaries,
                'photos_month' => (int) $mediaMonth->photos,
                'videos_month' => (int) $mediaMonth->videos,
            ],
            'by_project' => $byProject->map(fn ($r) => ['id' => $r->id, 'label' => $r->label, 'count' => (int) $r->c, 'photos' => (int) $r->photos, 'videos' => (int) $r->videos, 'bytes' => (int) $r->bytes])->all(),
            'storage' => self::storage($user, $f),
            'by_user' => $byUser->map(fn ($r) => ['id' => $r->id, 'label' => $r->label, 'count' => (int) $r->c])->all(),
            'monthly' => array_values($months),
        ];
    }

    /** Projects that require a daily diary but have none for the given date (global switch or per-project rule). */
    public static function missingFor(int $accountId, string $date): array
    {
        $global = (bool) app(SiteDiarySettings::class)->forAccount($accountId)->flag('require_daily');
        $projects = DB::table('warehouses')->where('warehouses.account_id', $accountId)->whereNull('warehouses.deleted_at')->where('warehouses.active', 1)->whereNull('warehouses.completed_at')
            ->whereNotIn(DB::raw('LOWER(TRIM(warehouses.status))'), ['main warehouse', 'main warehouses'])
            ->leftJoin('site_diary_project_rules as r', 'r.warehouse_id', '=', 'warehouses.id')
            ->where(function ($q) use ($global) {
                $q->where('r.require_daily', 1);
                if ($global) {
                    $q->orWhereNull('r.require_daily')->orWhere('r.require_daily', '!=', 0);
                }
            })
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('site_diaries')->whereColumn('site_diaries.warehouse_id', 'warehouses.id')->where('site_diaries.diary_date', $date)->where('site_diaries.status', '!=', 'Draft')->whereNull('site_diaries.deleted_at'))
            ->get(['warehouses.id', 'warehouses.name', 'warehouses.code']);

        return $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code])->all();
    }

    /** Space used by the attachments the user may see, split into photos and videos (one grouped query, no media rows loaded). */
    public static function storage(User $user, array $f = []): array
    {
        $rows = self::diaries($user, $f)
            ->join('site_diary_media', 'site_diary_media.site_diary_id', '=', 'site_diaries.id')
            ->whereNull('site_diary_media.deleted_at')
            ->groupBy('site_diary_media.media_type')
            ->selectRaw('site_diary_media.media_type t, COUNT(*) files, COALESCE(SUM(site_diary_media.file_size),0) bytes')
            ->get()->keyBy('t');
        $photo = (int) ($rows['photo']->bytes ?? 0);
        $video = (int) ($rows['video']->bytes ?? 0);

        return [
            'photos' => ['files' => (int) ($rows['photo']->files ?? 0), 'bytes' => $photo],
            'videos' => ['files' => (int) ($rows['video']->files ?? 0), 'bytes' => $video],
            'total' => $photo + $video,
        ];
    }

    /** First photo preview per diary (media id) for the recent-diary cards. [diary_id => media_id] */
    public static function coverMedia(array $diaryIds): array
    {
        if (! $diaryIds) {
            return [];
        }

        return DB::table('site_diary_media')->whereIn('site_diary_id', $diaryIds)->where('media_type', 'photo')->whereNotNull('thumb_path')->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'site_diary_id'])->unique('site_diary_id')->pluck('id', 'site_diary_id')->all();
    }

    /** Busiest projects (diaries, photos, videos, size) — one grouped query. */
    public static function topProjects(User $user, int $limit = 6): array
    {
        return self::diaries($user, [])->join('warehouses', 'warehouses.id', '=', 'site_diaries.warehouse_id')
            ->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('c')->limit($limit)
            ->selectRaw('warehouses.id, warehouses.name label, COUNT(*) c, COALESCE(SUM(site_diaries.photos_count),0) photos, COALESCE(SUM(site_diaries.videos_count),0) videos, COALESCE(SUM(site_diaries.media_bytes),0) bytes')
            ->get()->map(fn ($r) => ['id' => $r->id, 'label' => $r->label, 'count' => (int) $r->c, 'photos' => (int) $r->photos, 'videos' => (int) $r->videos, 'bytes' => (int) $r->bytes])->all();
    }
}
