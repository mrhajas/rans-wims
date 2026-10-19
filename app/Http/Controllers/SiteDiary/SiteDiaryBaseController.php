<?php

namespace App\Http\Controllers\SiteDiary;

use App\Http\Controllers\Controller;
use App\Models\SiteDiary;
use App\Models\SiteDiaryMedia;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FeatureManager;
use App\Services\SiteDiary\SiteDiarySettings;
use App\Traits\GuardsCompletedWarehouse;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for the Site Diary controllers. All permission checks are made here / in the actions (server-side);
 * the Authorizable auto-derivation is switched off because Site Diary has its own finer-grained abilities.
 */
abstract class SiteDiaryBaseController extends Controller
{
    use GuardsCompletedWarehouse;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    protected function settings(?User $user = null): SiteDiarySettings
    {
        return app(SiteDiarySettings::class)->forAccount(optional($user ?? auth()->user())->account_id);
    }

    /** A diary the user may not see answers 404, so its existence is not revealed. */
    protected function diaryFor(SiteDiary $diary, User $user, ?string $ability = null): SiteDiary
    {
        abort_unless($user->can('read-site-diary') && $diary->isVisibleTo($user), 404);
        if ($ability) {
            abort_unless($user->can($ability), 403);
        }

        return $diary;
    }

    /** Projects the user can record in (non-main warehouses they are assigned to). */
    protected function projects(User $user, bool $forEntry = true)
    {
        $q = Warehouse::query()->ofAccount($user->account_id)->active()
            ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses']);
        if ($forEntry) {
            $q->visible();
        }
        $ids = SiteDiary::projectIdsFor($user);
        if ($ids !== null) {
            $q->whereIn('id', $ids ?: [0]);
        }

        return $q->orderBy('name')->get(['id', 'name', 'code', 'status'])
            ->map(fn ($w) => ['label' => $w->name.($w->code ? ' ('.$w->code.')' : ''), 'value' => $w->id, 'name' => $w->name, 'code' => $w->code, 'status' => $w->status])->values();
    }

    protected function mediaEnabled(string $type): bool
    {
        return $this->settings()->enabled($type);
    }

    protected function canAddMedia(SiteDiary $diary, User $user): bool
    {
        if (! $diary->isVisibleTo($user)) {
            return false;
        }
        if ($diary->canEditBy($user)) {
            return true;
        }

        // the recorder may attach media to the diary they just created, even without the general "edit" ability
        return (int) $diary->recorded_by === (int) $user->id && $user->can('create-site-diary') && $diary->created_at && $diary->created_at->gte(now()->subHours(12));
    }

    protected function abilities(User $user, ?SiteDiary $diary = null): array
    {
        $s = $this->settings($user);
        $photos = $s->enabled('photo');
        $videos = $s->enabled('video');
        $addOk = $diary ? $this->canAddMedia($diary, $user) : $user->can('create-site-diary');
        $editOk = $diary ? $diary->canEditBy($user) : false;

        return [
            'create' => $user->can('create-site-diary'),
            'update' => $editOk,
            'delete' => $diary ? $diary->canDeleteBy($user) : false,
            'backdate' => $user->can('backdate-site-diary'),
            'changeProject' => $user->can('update-any-site-diary'),
            'addPhotos' => $photos && $addOk && $user->can('add-site-diary-photos'),
            'addVideos' => $videos && $addOk && $user->can('add-site-diary-videos'),
            'deletePhotos' => $editOk && $user->can('delete-site-diary-photos'),
            'deleteVideos' => $editOk && $user->can('delete-site-diary-videos'),
            'viewPhotos' => $photos && $user->can('view-site-diary-photos'),
            'viewVideos' => $videos && $user->can('view-site-diary-videos'),
            'download' => $user->can('download-site-diary-media') && $s->flag('downloads_allowed'),
            'history' => $user->can('view-site-diary-history'),
            'viewers' => $user->can('view-site-diary-viewers'),
            'analytics' => $user->can('view-site-diary-analytics') && FeatureManager::canAccess('site_diary_analytics', $user),
            'reports' => $user->can('view-site-diary-reports') && FeatureManager::canAccess('site_diary_reports', $user),
            'print' => $user->can('print-site-diary-reports'),
            'export' => $user->can('export-site-diary-reports'),
            'settings' => $user->can('manage-site-diary-settings'),
            'restore' => $user->can('delete-any-site-diary'),
            'viewAll' => SiteDiary::canSeeAll($user),
        ];
    }

    protected function limitsPayload(User $user): array
    {
        $s = $this->settings($user);

        return [
            'photos' => ['enabled' => $s->enabled('photo'), 'max' => $s->maxCount('photo'), 'types' => $s->types('photo'), 'max_mb' => $s->maxMb('photo')],
            'videos' => ['enabled' => $s->enabled('video'), 'max' => $s->maxCount('video'), 'types' => $s->types('video'), 'max_mb' => $s->maxMb('video')],
            'remarks_max' => (int) $s->get('remarks_max'),
            'capture_max_px' => (int) $s->get('capture_max_px'),
            'remarks_required' => $s->flag('remarks_required'),
            'password_on_delete' => $s->flag('password_on_delete'),
            'require_media' => $s->flag('require_media'),
            'delete_files_on_remove' => $s->flag('delete_files_on_remove'),
        ];
    }

    /** Media of a diary as the browser may see it (filtered by what the user may view). */
    protected function mediaPayload(SiteDiary $diary, array $can): array
    {
        $q = SiteDiaryMedia::where('site_diary_id', $diary->id)->with('uploader:id,name')->orderBy('id');
        $types = array_values(array_filter([$can['viewPhotos'] ? 'photo' : null, $can['viewVideos'] ? 'video' : null]));
        // a user who can add media still needs to see what they just uploaded in the uploader
        $q->whereIn('media_type', $types ?: ['__none__']);

        return $q->get()->map(function (SiteDiaryMedia $m) use ($diary, $can) {
            $row = $m->toClient([
                'thumb_url' => $m->thumb_path ? route('site-diary.media.thumb', [$diary->id, $m->id]) : null,
                'view_url' => route('site-diary.media.view', [$diary->id, $m->id]),
                'open_url' => route('site-diary.media.open', [$diary->id, $m->id]),
                'download_url' => $can['download'] ? route('site-diary.media.download', [$diary->id, $m->id]) : null,
            ]);

            return $row;
        })->values()->all();
    }

    protected function diaryPayload(SiteDiary $d): array
    {
        return [
            'id' => $d->id,
            'reference' => $d->reference,
            'date' => $d->diary_date->toDateString(),
            'remarks' => $d->remarks,
            'status' => $d->status,
            'photos_count' => (int) $d->photos_count,
            'videos_count' => (int) $d->videos_count,
            'media_bytes' => (int) $d->media_bytes,
            'project' => ['id' => $d->warehouse_id, 'name' => optional($d->warehouse)->name, 'code' => optional($d->warehouse)->code, 'status' => optional($d->warehouse)->status],
            'recorder' => ['id' => $d->recorded_by, 'name' => optional($d->recorder)->name],
            'created_at' => optional($d->created_at)->toIso8601String(),
            'updated_at' => optional($d->updated_at)->toIso8601String(),
            'deleted_at' => optional($d->deleted_at)->toIso8601String(),
            'delete_reason' => $d->delete_reason,
        ];
    }
}
