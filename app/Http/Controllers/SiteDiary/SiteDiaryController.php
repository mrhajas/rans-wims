<?php

namespace App\Http\Controllers\SiteDiary;

use App\Models\SiteDiary;
use App\Models\SiteDiaryMedia;
use App\Models\SiteDiaryNotificationRule as Rule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SiteDiary\SiteDiaryLogger;
use App\Services\SiteDiary\SiteDiaryNotifier;
use App\Services\SiteDiary\SiteDiaryStats;
use App\Services\SiteDiary\SiteDiaryStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SiteDiaryController extends SiteDiaryBaseController
{
    public const PAGE_SIZES = [25, 50, 100];

    private const FILTER_KEYS = ['search', 'warehouse_id', 'recorded_by', 'date_from', 'date_to', 'created_from', 'created_to', 'has_photos', 'has_videos'];

    // ---- dashboard -----------------------------------------------------------------------------------------------

    public function dashboard(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-site-diary') && $user->can('view-site-diary-analytics'), 403);

        return Inertia::render('SiteDiary/Dashboard', [
            'projects' => $this->projects($user, false),
            'me' => $user->name,
            'can' => $this->abilities($user),
        ]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-site-diary') && $user->can('view-site-diary-analytics'), 403);
        $f = $request->validate(['warehouse_id' => 'nullable|integer', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date']);
        $stats = SiteDiaryStats::dashboard($user, array_filter($f));
        return response()->json($stats + ['recent' => $this->recentCards($user, 6)]);
    }

    /** Latest diaries as cards: reference, project, who, size, and a cover photo when the user may see photos. */
    private function recentCards(User $user, int $n): array
    {
        $rows = SiteDiary::listQuery($user, [])->limit($n)->get();
        $covers = $user->can('view-site-diary-photos') ? SiteDiaryStats::coverMedia($rows->pluck('id')->all()) : [];

        return $rows->map(fn ($d) => [
            'id' => $d->id, 'reference' => $d->reference, 'project' => $d->project_name, 'date' => $d->diary_date->toDateString(),
            'by' => $d->recorder_name, 'photos' => (int) $d->photos_count, 'videos' => (int) $d->videos_count, 'bytes' => (int) $d->media_bytes,
            'remarks' => str($d->remarks)->limit(90)->toString(),
            'cover' => isset($covers[$d->id]) ? route('site-diary.media.thumb', [$d->id, $covers[$d->id]]) : null,
        ])->all();
    }

    // ---- list ----------------------------------------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-site-diary'), 403);

        $filters = array_filter($request->only(self::FILTER_KEYS), fn ($v) => $v !== null && $v !== '');
        $filters['sort'] = $request->query('sort');
        $filters['direction'] = $request->query('direction');
        $perPage = in_array((int) $request->query('per_page'), self::PAGE_SIZES, true) ? (int) $request->query('per_page') : 25;
        $trashed = $request->query('trashed') === 'only' && $user->can('delete-any-site-diary');

        $paginator = SiteDiary::listQuery($user, $filters, $trashed, true)->paginate($perPage)->withQueryString();
        $canViewers = $user->can('view-site-diary-viewers');
        $viewers = $canViewers
            ? SiteDiaryLogger::viewersFor($paginator->getCollection()->pluck('id')->all(), $paginator->getCollection()->pluck('recorded_by', 'id')->map(fn ($v) => (int) $v)->all())
            : [];
        $paginator->setCollection($paginator->getCollection()->map(fn ($d) => [
            'viewers' => $viewers[$d->id] ?? [],
            'id' => $d->id,
            'reference' => $d->reference,
            'project' => $d->project_name,
            'project_code' => $d->project_code,
            'project_id' => $d->warehouse_id,
            'date' => $d->diary_date->toDateString(),
            'recorded_by' => $d->recorder_name,
            'remarks' => str($d->remarks)->limit(110)->toString(),
            'photos' => (int) $d->photos_count,
            'videos' => (int) $d->videos_count,
            'bytes' => (int) $d->media_bytes,
            'status' => $d->deleted_at ? 'Deleted' : $d->status,
            'created_at' => $d->created_at->toIso8601String(),
            'updated_at' => $d->updated_at->toIso8601String(),
            'deleted' => (bool) $d->deleted_at,
            'mine' => (int) $d->recorded_by === (int) $user->id,
        ]));

        $can = $this->abilities($user);
        $recorders = $can['viewAll']
            ? User::query()->where('account_id', $user->account_id)->whereIn('id', DB::table('site_diaries')->where('account_id', $user->account_id)->select('recorded_by'))->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['label' => $u->name, 'value' => $u->id])
            : collect();

        return Inertia::render('SiteDiary/Index', [
            'diaries' => paginated_response($paginator),
            'filters' => $request->only(array_merge(self::FILTER_KEYS, ['sort', 'direction', 'per_page', 'trashed'])),
            'projects' => $this->projects($user, false),
            'recorders' => $recorders->values(),
            'summary' => ! empty($filters['warehouse_id']) ? $this->projectSummary($user, (int) $filters['warehouse_id']) : null,
            'storage' => SiteDiaryStats::storage($user),
            'recent' => $this->recentCards($user, 4),
            'top_projects' => SiteDiaryStats::topProjects($user, 60),
            'can' => $can,
            'limits' => $this->limitsPayload($user),
        ]);
    }

    /** Project card: total diaries, latest diary, latest photos, latest remarks (also shown on the project page). */
    public function projectSummaryJson(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        abort_unless($user->can('read-site-diary'), 403);
        $ids = SiteDiary::projectIdsFor($user);
        abort_if($ids !== null && ! in_array($warehouse->id, array_map('intval', $ids), true), 404);

        return response()->json($this->projectSummary($user, $warehouse->id));
    }

    private function projectSummary(User $user, int $warehouseId): ?array
    {
        $ids = SiteDiary::projectIdsFor($user);
        if ($ids !== null && ! in_array($warehouseId, array_map('intval', $ids), true)) {
            return null;
        }
        $base = fn () => SiteDiary::listQuery($user, ['warehouse_id' => $warehouseId]);
        $total = SiteDiary::withoutGlobalScope('account')->where('account_id', $user->account_id)->visibleTo($user)->where('warehouse_id', $warehouseId)->where('status', '!=', 'Draft')->count();
        $latest = $base()->limit(3)->get();
        $can = $this->abilities($user);
        $photos = [];
        if ($can['viewPhotos'] && $latest->isNotEmpty()) {
            $photos = SiteDiaryMedia::whereIn('site_diary_id', $latest->pluck('id'))->where('media_type', 'photo')->whereNotNull('thumb_path')
                ->orderByDesc('id')->limit(6)->get(['id', 'site_diary_id'])
                ->map(fn ($m) => ['id' => $m->id, 'url' => route('site-diary.media.thumb', [$m->site_diary_id, $m->id]), 'diary' => $m->site_diary_id])->all();
        }

        return [
            'project' => optional(Warehouse::withoutGlobalScopes()->find($warehouseId))->name,
            'project_id' => $warehouseId,
            'total' => $total,
            'latest' => $latest->first() ? ['id' => $latest->first()->id, 'reference' => $latest->first()->reference, 'date' => $latest->first()->diary_date->toDateString(), 'by' => $latest->first()->recorder_name] : null,
            'remarks' => $latest->filter(fn ($d) => $d->remarks)->take(2)->map(fn ($d) => ['id' => $d->id, 'date' => $d->diary_date->toDateString(), 'by' => $d->recorder_name, 'text' => str($d->remarks)->limit(160)->toString()])->values()->all(),
            'photos' => $photos,
            'url' => route('site-diary.index', ['warehouse_id' => $warehouseId]),
        ];
    }

    // ---- create / store ----------------------------------------------------------------------------------------------

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('create-site-diary'), 403);

        $projects = $this->projects($user);
        $preset = $request->query('warehouse_id');

        return Inertia::render('SiteDiary/Form', [
            'diary' => null,
            'projects' => $projects,
            'preset_project' => $preset && $projects->contains('value', (int) $preset) ? (int) $preset : ($projects->count() === 1 ? $projects->first()['value'] : null),
            'today' => now()->toDateString(),
            'recorder' => $user->name,
            'media' => [],
            'can' => $this->abilities($user),
            'limits' => $this->limitsPayload($user),
        ]);
    }

    private function rules(User $user, bool $creating): array
    {
        $s = $this->settings($user);
        $rules = [
            'warehouse_id' => ['required', 'integer'],
            'diary_date' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => [$s->flag('remarks_required') ? 'required' : 'nullable', 'string', 'max:'.max(1, (int) $s->get('remarks_max'))],
        ];

        return $rules;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('create-site-diary'), 403);
        $data = $request->validate($this->rules($user, true));

        $allowed = $this->projects($user)->pluck('value')->map(fn ($v) => (int) $v)->all();
        if (! in_array((int) $data['warehouse_id'], $allowed, true)) {
            if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'])) {
                throw ValidationException::withMessages(['warehouse_id' => $this->completedWarehouseMessage()]);
            }
            throw ValidationException::withMessages(['warehouse_id' => __('You cannot record a diary for this project.')]);
        }
        // only people allowed to back-date can choose another day; everyone else always records today
        $date = ($user->can('backdate-site-diary') && ! empty($data['diary_date'])) ? $data['diary_date'] : now()->toDateString();

        $diary = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $diary = DB::transaction(fn () => SiteDiary::create([
                    'account_id' => $user->account_id,
                    'reference' => SiteDiary::nextReference(),
                    'warehouse_id' => (int) $data['warehouse_id'],
                    'diary_date' => $date,
                    'recorded_by' => $user->id,
                    'remarks' => $data['remarks'] ?? null,
                    // with the "attachment required" rule a diary stays a hidden draft until its first photo / video is saved
                    'status' => $this->settings($user)->flag('require_media') ? 'Draft' : 'Submitted',
                ]));
                break;
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23000' || ! str_contains($e->getMessage(), 'reference') || $attempt === 4) {
                    throw $e;
                }
            }
        }

        $diary->load('warehouse');
        SiteDiaryLogger::log($user, $diary, 'DIARY_CREATE', null, ['project' => optional($diary->warehouse)->name, 'diary_date' => $date]);
        if ($diary->status === 'Submitted') {
            $this->announce($diary, $user);
        }

        return response()->json(['id' => $diary->id, 'reference' => $diary->reference, 'url' => route('site-diary.show', $diary->id), 'upload_url' => route('site-diary.media.store', $diary->id)]);
    }

    // ---- show ----------------------------------------------------------------------------------------------------

    public function show(Request $request, SiteDiary $diary)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        $diary->load(['warehouse', 'recorder']);
        $can = $this->abilities($user, $diary);
        SiteDiaryLogger::log($user, $diary, 'DIARY_VIEW');

        return Inertia::render('SiteDiary/Show', [
            'diary' => $this->diaryPayload($diary),
            'media' => $this->mediaPayload($diary, $can),
            'history' => $can['history'] ? SiteDiaryLogger::timeline($diary) : null,
            'viewers' => $can['viewers'] ? ($this->viewersOf($diary)) : null,
            'can' => $can,
            'limits' => $this->limitsPayload($user),
            'drive_problem' => SiteDiaryMedia::where('site_diary_id', $diary->id)->where('upload_status', 'failed')->exists(),
            // Weather & Site Risk panel (null when the person may not read weather or the module is off)
            'weather' => \App\Services\Weather\DiaryWeather::panel($diary, $user),
        ]);
    }

    /** Everyone who has opened the diary (the recorder's own visits are left out), with how often and when last. */
    private function viewersOf(SiteDiary $diary): array
    {
        return SiteDiaryLogger::viewersFor([$diary->id], [$diary->id => (int) $diary->recorded_by])[$diary->id] ?? [];
    }

    public function edit(Request $request, SiteDiary $diary)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        $can = $this->abilities($user, $diary);
        abort_unless($can['update'] || $can['addPhotos'] || $can['addVideos'], 403);
        $diary->load(['warehouse', 'recorder']);

        $projects = $this->projects($user);
        if (! $projects->contains('value', $diary->warehouse_id)) { // keep showing the current project even if it is now completed
            $w = $diary->warehouse;
            $projects->push(['label' => $w->name.($w->code ? ' ('.$w->code.')' : ''), 'value' => $w->id, 'name' => $w->name, 'code' => $w->code, 'status' => $w->status]);
        }

        return Inertia::render('SiteDiary/Form', [
            'diary' => $this->diaryPayload($diary),
            'projects' => $projects->values(),
            'preset_project' => null,
            'today' => now()->toDateString(),
            'recorder' => optional($diary->recorder)->name,
            'media' => $this->mediaPayload($diary, $can),
            'can' => $can,
            'limits' => $this->limitsPayload($user),
        ]);
    }

    public function update(Request $request, SiteDiary $diary)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        abort_unless($diary->canEditBy($user), 403);
        if ($response = $this->rejectIfWarehouseCompleted($diary->warehouse_id)) {
            return response()->json(['message' => $this->completedWarehouseMessage()], 422);
        }
        $data = $request->validate($this->rules($user, false));

        $changes = [];
        $remarks = $data['remarks'] ?? null;
        if ((string) $remarks !== (string) $diary->remarks) {
            $changes['remarks'] = ['from' => str((string) $diary->remarks)->limit(200)->toString(), 'to' => str((string) $remarks)->limit(200)->toString()];
            $diary->remarks = $remarks;
        }
        if ($user->can('update-any-site-diary') && (int) $data['warehouse_id'] !== (int) $diary->warehouse_id) {
            $allowed = $this->projects($user)->pluck('value')->map(fn ($v) => (int) $v)->all();
            if (! in_array((int) $data['warehouse_id'], $allowed, true)) {
                throw ValidationException::withMessages(['warehouse_id' => __('You cannot move this diary to that project.')]);
            }
            $changes['project'] = ['from' => optional($diary->warehouse)->name, 'to' => optional(Warehouse::find($data['warehouse_id']))->name];
            $diary->warehouse_id = (int) $data['warehouse_id'];
        }
        if ($user->can('backdate-site-diary') && ! empty($data['diary_date']) && $data['diary_date'] !== $diary->diary_date->toDateString()) {
            $changes['date'] = ['from' => $diary->diary_date->toDateString(), 'to' => $data['diary_date']];
            $diary->diary_date = $data['diary_date'];
        }
        if ($changes) {
            $diary->save();
            SiteDiaryLogger::log($user, $diary->load('warehouse'), 'DIARY_UPDATE', null, ['changes' => $changes]);
            if ($diary->status === 'Submitted') {
                SiteDiaryNotifier::event(Rule::DIARY_UPDATED, $user, $diary, ['changes' => implode(', ', array_keys($changes))]);
            }
        }

        return response()->json(['id' => $diary->id, 'url' => route('site-diary.show', $diary->id), 'upload_url' => route('site-diary.media.store', $diary->id)]);
    }

    private function announce(SiteDiary $diary, User $user): void
    {
        $diary->loadMissing('warehouse');
        SiteDiaryNotifier::event(Rule::NEW_DIARY, $user, $diary);
        SiteDiaryNotifier::event(Rule::NEW_ASSIGNED_PROJECT, $user, $diary);
    }

    /**
     * Called when the browser has finished sending its files. A draft becomes a real diary here — and only if it
     * really holds at least one photo or video. Otherwise it stays a draft and the user is told what is missing.
     */
    public function uploadsComplete(Request $request, SiteDiary $diary)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        abort_unless($this->canAddMedia($diary, $user), 403);
        $data = $request->validate(['photos' => 'nullable|integer|min:0|max:500', 'videos' => 'nullable|integer|min:0|max:500']);

        if ($diary->status === 'Draft') {
            if (! SiteDiaryMedia::where('site_diary_id', $diary->id)->exists()) {
                return response()->json(['message' => __('Add at least one photo or video before saving the diary.'), 'errors' => ['media' => [__('Add at least one photo or video before saving the diary.')]]], 422);
            }
            $diary->forceFill(['status' => 'Submitted'])->save();
            SiteDiaryLogger::log($user, $diary->load('warehouse'), 'DIARY_SUBMIT', null, ['photos' => $diary->photos_count, 'videos' => $diary->videos_count]);
            $this->announce($diary, $user);
        } else {
            $diary->load('warehouse');
            if (($data['photos'] ?? 0) > 0) {
                SiteDiaryNotifier::event(Rule::NEW_PHOTOS, $user, $diary, ['count' => $data['photos']]);
            }
            if (($data['videos'] ?? 0) > 0) {
                SiteDiaryNotifier::event(Rule::NEW_VIDEOS, $user, $diary, ['count' => $data['videos']]);
            }
        }

        return response()->json(['ok' => true, 'url' => route('site-diary.show', $diary->id)]);
    }

    // ---- delete / restore -------------------------------------------------------------------------------------------

    public function destroy(Request $request, SiteDiary $diary)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        abort_unless($diary->canDeleteBy($user), 403);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'password' => 'nullable|string']);

        if ($this->settings($user)->flag('password_on_delete') && ! Hash::check((string) ($data['password'] ?? ''), $user->password)) {
            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }
        if ($response = $this->rejectIfWarehouseCompleted($diary->warehouse_id)) {
            return response()->json(['message' => $this->completedWarehouseMessage()], 422);
        }

        $diary->forceFill(['deleted_by' => $user->id, 'delete_reason' => $data['reason']])->save();
        $diary->delete();
        SiteDiaryLogger::log($user, $diary->load('warehouse'), 'DIARY_DELETE', null, ['reason' => $data['reason']]);
        SiteDiaryNotifier::event(Rule::DIARY_DELETED, $user, $diary, ['reason' => $data['reason']]);

        return response()->json(['success' => true, 'url' => route('site-diary.index')]);
    }

    public function restore(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user->can('delete-any-site-diary'), 403);
        $diary = SiteDiary::onlyTrashed()->findOrFail($id);
        abort_unless($diary->isVisibleTo($user), 404);
        $diary->restore();
        $diary->forceFill(['deleted_by' => null, 'delete_reason' => null])->save();
        SiteDiaryLogger::log($user, $diary->load('warehouse'), 'DIARY_RESTORE');
        SiteDiaryNotifier::event(Rule::DIARY_RESTORED, $user, $diary);

        return back()->with('message', __('Site Diary :ref restored.', ['ref' => $diary->reference]));
    }
}
