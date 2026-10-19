<?php

namespace App\Http\Controllers\SiteDiary;

use App\Models\SiteDiary;
use App\Models\SiteDiaryMedia;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\SiteDiary\SiteDiaryLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class SiteDiaryReportController extends SiteDiaryBaseController
{
    public const TYPES = [
        'daily' => 'Daily Site Diary Report',
        'project' => 'Project Site Diary Report',
        'range' => 'Date Range Site Diary Report',
        'user' => 'User Site Diary Report',
        'project_summary' => 'Project Diary Summary',
        'media' => 'Media Upload Report',
        'access' => 'Site Diary Access Report',
    ];

    private const FILTERS = ['type', 'warehouse_id', 'recorded_by', 'date_from', 'date_to', 'media_type', 'search'];

    private const ROW_CAP = 5000;

    private function guard(Request $request, ?string $ability = null): User
    {
        $user = $request->user();
        abort_unless($user->can('read-site-diary') && $user->can('view-site-diary-reports') && FeatureManager::canAccess('site_diary_reports', $user), 403);
        if ($ability) {
            abort_unless($user->can($ability), 403);
        }

        return $user;
    }

    public function index(Request $request)
    {
        $user = $this->guard($request);
        $filters = $this->filters($request);
        $payload = $this->payload($user, $filters, false);

        return Inertia::render('SiteDiary/Reports', [
            'filters' => $filters,
            'types' => collect(self::TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'projects' => $this->projects($user, false),
            'recorders' => SiteDiary::canSeeAll($user)
                ? User::query()->where('account_id', $user->account_id)->whereIn('id', DB::table('site_diaries')->where('account_id', $user->account_id)->select('recorded_by'))->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['label' => $u->name, 'value' => $u->id])
                : [],
            'cards' => collect($payload['groups'][0]['cards'] ?? [])->map(fn ($c) => ['label' => $c['label'], 'value' => $c['value'], 'tone' => $c['tone']])->values(),
            'columns' => collect($payload['columns'])->pluck('label')->values(),
            'rows' => collect($payload['rows'])->take(200)->map(fn ($r) => collect($r)->map(fn ($c) => is_array($c) ? array_intersect_key($c, array_flip(['text', 'sub', 'tone', 'url', 'strong'])) : $c)->values())->values(),
            'total_rows' => count($payload['rows']),
            'can' => $this->abilities($user),
        ]);
    }

    public function print(Request $request)
    {
        $user = $this->guard($request, 'print-site-diary-reports');
        SiteDiaryLogger::log($user, null, 'REPORT_PRINTED', null, ['report' => $request->query('type', 'daily')]);

        return view('reports.register-list', $this->payload($user, $this->filters($request), false));
    }

    public function pdf(Request $request)
    {
        $user = $this->guard($request, 'print-site-diary-reports');
        SiteDiaryLogger::log($user, null, 'REPORT_DOWNLOADED', null, ['report' => $request->query('type', 'daily')]);

        return Pdf::loadView('reports.register-list', $this->payload($user, $this->filters($request), true))
            ->setPaper('a4', 'landscape')->download('site-diary-report-'.now()->format('Ymd-His').'.pdf');
    }

    public function export(Request $request)
    {
        $user = $this->guard($request, 'export-site-diary-reports');
        $payload = $this->payload($user, $this->filters($request), false);
        SiteDiaryLogger::log($user, null, 'EXPORT_GENERATED', null, ['report' => $request->query('type', 'daily')]);

        $headers = collect($payload['columns'])->pluck('label')->all();
        $rows = collect($payload['rows'])->map(function ($r) use ($headers) {
            $cells = collect($r)->map(fn ($c) => is_array($c) ? trim(($c['text'] ?? '').(! empty($c['sub']) ? ' - '.$c['sub'] : '')) : $c)->values();

            return collect($headers)->mapWithKeys(fn ($h, $i) => [$h => $cells[$i] ?? ''])->all();
        });

        return (new \Rap2hpoutre\FastExcel\FastExcel($rows))->download('site-diary-report-'.now()->format('Ymd-His').'.xlsx');
    }

    // ---- photo gallery report ---------------------------------------------------------------------------------------

    public function gallery(Request $request)
    {
        return $this->galleryView($request);
    }

    public function galleryPdf(Request $request)
    {
        $user = $this->guard($request, 'print-site-diary-reports');
        $data = $this->galleryData($request, $user, 120);
        SiteDiaryLogger::log($user, null, 'REPORT_DOWNLOADED', null, ['report' => 'photo_gallery']);

        return Pdf::loadView('site-diary.gallery-report', $data + ['isPdf' => true, 'autoPrint' => false])
            ->setPaper('a4', 'portrait')->download('site-diary-photo-gallery-'.now()->format('Ymd-His').'.pdf');
    }

    private function galleryView(Request $request)
    {
        $user = $this->guard($request, 'print-site-diary-reports');
        $data = $this->galleryData($request, $user, 300);
        SiteDiaryLogger::log($user, null, 'REPORT_PRINTED', null, ['report' => 'photo_gallery']);

        return view('site-diary.gallery-report', $data + ['isPdf' => false, 'autoPrint' => true]);
    }

    private function galleryData(Request $request, User $user, int $photoCap): array
    {
        abort_unless($user->can('view-site-diary-photos') && $this->settings($user)->enabled('photo'), 403);
        $f = $request->validate(['diary_id' => 'nullable|integer', 'warehouse_id' => 'nullable|integer', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date', 'recorded_by' => 'nullable|integer']);
        abort_unless(! empty($f['diary_id']) || ! empty($f['warehouse_id']), 422, __('Choose a project or a diary for the gallery report.'));

        $q = SiteDiary::listQuery($user, array_filter(['warehouse_id' => $f['warehouse_id'] ?? null, 'date_from' => $f['date_from'] ?? null, 'date_to' => $f['date_to'] ?? null, 'recorded_by' => $f['recorded_by'] ?? null]) + ['sort' => 'date', 'direction' => 'asc'])
            ->where('site_diaries.photos_count', '>', 0);
        if (! empty($f['diary_id'])) {
            $q->where('site_diaries.id', $f['diary_id']);
        }
        $diaries = $q->limit(60)->get();

        $disk = Storage::disk('local');
        $left = $photoCap;
        $skipped = 0;
        $blocks = [];
        foreach ($diaries as $d) {
            $photos = [];
            foreach (SiteDiaryMedia::where('site_diary_id', $d->id)->where('media_type', 'photo')->with('uploader:id,name')->orderBy('id')->get() as $m) {
                if ($left <= 0 || ! $m->thumb_path || ! $disk->exists($m->thumb_path)) {
                    $skipped++;

                    continue;
                }
                $photos[] = ['name' => $m->original_name, 'when' => optional($m->uploaded_at ?? $m->created_at)->format('d M Y H:i'), 'by' => optional($m->uploader)->name,
                    'src' => 'data:image/jpeg;base64,'.base64_encode($disk->get($m->thumb_path))];
                $left--;
            }
            $blocks[] = ['reference' => $d->reference, 'project' => $d->project_name, 'code' => $d->project_code, 'date' => $d->diary_date->format('d M Y'), 'by' => $d->recorder_name, 'remarks' => $d->remarks, 'photos' => $photos];
        }

        return [
            'title' => 'Photo Gallery Report',
            'companyName' => optional($user->account)->name ?? 'RANS Company Limited',
            'generatedBy' => $user->name,
            'generatedAt' => now(),
            'blocks' => $blocks,
            'skipped' => $skipped,
            'capped' => $left <= 0,
        ];
    }

    // ---- shared builder -----------------------------------------------------------------------------------------------

    private function filters(Request $request): array
    {
        $f = array_filter($request->only(self::FILTERS), fn ($v) => $v !== null && $v !== '');
        $f['type'] = isset(self::TYPES[$f['type'] ?? '']) ? $f['type'] : 'daily';
        if ($f['type'] === 'daily' && empty($f['date_from']) && empty($f['date_to'])) {
            $f['date_from'] = $f['date_to'] = now()->toDateString();
        }
        if (! empty($f['media_type']) && ! in_array($f['media_type'], ['photo', 'video'], true)) {
            unset($f['media_type']);
        }

        return $f;
    }

    private function payload(User $user, array $f, bool $isPdf): array
    {
        $chips = [];
        if (! empty($f['warehouse_id'])) {
            $chips[] = ['Project', optional(\App\Models\Warehouse::withoutGlobalScopes()->find($f['warehouse_id']))->name ?? $f['warehouse_id']];
        }
        if (! empty($f['recorded_by'])) {
            $chips[] = ['Recorded by', User::whereKey($f['recorded_by'])->value('name') ?? $f['recorded_by']];
        }
        if (! empty($f['date_from']) || ! empty($f['date_to'])) {
            $chips[] = ['Dates', ($f['date_from'] ?? '…').' → '.($f['date_to'] ?? '…')];
        }
        if (! empty($f['media_type'])) {
            $chips[] = ['Media', ucfirst($f['media_type'])];
        }
        if (! empty($f['search'])) {
            $chips[] = ['Search', $f['search']];
        }

        $base = [
            'title' => self::TYPES[$f['type']],
            'isPdf' => $isPdf,
            'autoPrint' => ! $isPdf,
            'companyName' => optional($user->account)->name ?? 'RANS Company Limited',
            'generatedBy' => $user->name,
            'generatedAt' => now(),
            'filters' => $chips,
        ];

        return $base + match ($f['type']) {
            'project_summary' => $this->projectSummary($user, $f),
            'media' => $this->mediaReport($user, $f),
            'access' => $this->accessReport($user, $f),
            default => $this->diaryReport($user, $f),
        };
    }

    private function diaryReport(User $user, array $f): array
    {
        $diaries = SiteDiary::listQuery($user, $f + ['sort' => 'date', 'direction' => 'desc'])->limit(self::ROW_CAP)->get();
        $rows = [];
        foreach ($diaries as $i => $d) {
            $rows[] = [
                $i + 1,
                ['text' => $d->reference, 'strong' => true],
                ['text' => $d->project_name, 'sub' => $d->project_code],
                $d->diary_date->format('d M Y'),
                $d->recorder_name,
                ['text' => str((string) $d->remarks)->limit(260)->toString()],
                ['text' => (string) $d->photos_count, 'tone' => $d->photos_count ? 'blue' : 'slate'],
                ['text' => (string) $d->videos_count, 'tone' => $d->videos_count ? 'indigo' : 'slate'],
            ];
        }

        return [
            'scopeNote' => 'Exactly the site diaries matching the filters above.',
            'groups' => [['title' => 'Summary', 'cards' => [
                ['label' => 'Diaries', 'value' => number_format($diaries->count()), 'tone' => 'navy', 'icon' => 'grid'],
                ['label' => 'Projects', 'value' => number_format($diaries->pluck('warehouse_id')->unique()->count()), 'tone' => 'teal', 'icon' => 'building'],
                ['label' => 'Photos', 'value' => number_format($diaries->sum('photos_count')), 'tone' => 'sky', 'icon' => 'box'],
                ['label' => 'Videos', 'value' => number_format($diaries->sum('videos_count')), 'tone' => 'violet', 'icon' => 'tag'],
                ['label' => 'Recorders', 'value' => number_format($diaries->pluck('recorded_by')->unique()->count()), 'tone' => 'amber', 'icon' => 'users'],
            ]]],
            'tableTitle' => 'Site diaries ('.number_format($diaries->count()).')',
            'columns' => [['label' => 'No', 'width' => '26px'], ['label' => 'Reference'], ['label' => 'Project'], ['label' => 'Date'], ['label' => 'Recorded By'], ['label' => 'Remarks'], ['label' => 'Photos'], ['label' => 'Videos']],
            'rows' => $rows,
            'emptyText' => 'No site diaries match these filters.',
        ];
    }

    private function projectSummary(User $user, array $f): array
    {
        $q = SiteDiary::withoutGlobalScope('account')->where('site_diaries.account_id', $user->account_id)->where('site_diaries.status', '!=', 'Draft')->visibleTo($user)->filter($f + [])
            ->join('warehouses', 'warehouses.id', '=', 'site_diaries.warehouse_id')->join('users', 'users.id', '=', 'site_diaries.recorded_by')
            ->groupBy('warehouses.id', 'warehouses.name', 'warehouses.code')->orderBy('warehouses.name')
            ->selectRaw('warehouses.name, warehouses.code, COUNT(*) diaries, SUM(site_diaries.photos_count) photos, SUM(site_diaries.videos_count) videos, MAX(site_diaries.diary_date) last_date, COUNT(DISTINCT site_diaries.recorded_by) people');
        $data = $q->limit(self::ROW_CAP)->get();
        $rows = $data->values()->map(fn ($r, $i) => [
            $i + 1, ['text' => $r->name, 'strong' => true, 'sub' => $r->code], (string) $r->diaries, (string) (int) $r->photos, (string) (int) $r->videos, (string) $r->people, $r->last_date ? \Carbon\Carbon::parse($r->last_date)->format('d M Y') : '—',
        ])->all();

        return [
            'scopeNote' => 'One line per project: how many diaries, photos and videos were recorded.',
            'groups' => [['title' => 'Summary', 'cards' => [
                ['label' => 'Projects', 'value' => number_format($data->count()), 'tone' => 'navy', 'icon' => 'building'],
                ['label' => 'Diaries', 'value' => number_format($data->sum('diaries')), 'tone' => 'teal', 'icon' => 'grid'],
                ['label' => 'Photos', 'value' => number_format($data->sum('photos')), 'tone' => 'sky', 'icon' => 'box'],
                ['label' => 'Videos', 'value' => number_format($data->sum('videos')), 'tone' => 'violet', 'icon' => 'tag'],
            ]]],
            'tableTitle' => 'Projects ('.number_format($data->count()).')',
            'columns' => [['label' => 'No', 'width' => '26px'], ['label' => 'Project'], ['label' => 'Diaries'], ['label' => 'Photos'], ['label' => 'Videos'], ['label' => 'Recorders'], ['label' => 'Latest diary']],
            'rows' => $rows,
            'emptyText' => 'No site diaries found.',
        ];
    }

    private function mediaReport(User $user, array $f): array
    {
        $q = SiteDiaryMedia::withoutGlobalScope('account')
            ->join('site_diaries', 'site_diaries.id', '=', 'site_diary_media.site_diary_id')
            ->join('warehouses', 'warehouses.id', '=', 'site_diaries.warehouse_id')
            ->join('users', 'users.id', '=', 'site_diary_media.uploaded_by')
            ->where('site_diaries.account_id', $user->account_id)->whereNull('site_diaries.deleted_at')->where('site_diaries.status', '!=', 'Draft')
            ->when(! empty($f['media_type']), fn ($q) => $q->where('site_diary_media.media_type', $f['media_type']))
            ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('site_diaries.warehouse_id', $f['warehouse_id']))
            ->when(! empty($f['recorded_by']), fn ($q) => $q->where('site_diary_media.uploaded_by', $f['recorded_by']))
            ->when(! empty($f['date_from']), fn ($q) => $q->where('site_diary_media.created_at', '>=', $f['date_from'].' 00:00:00'))
            ->when(! empty($f['date_to']), fn ($q) => $q->where('site_diary_media.created_at', '<=', $f['date_to'].' 23:59:59'));
        $ids = SiteDiary::projectIdsFor($user);
        if ($ids !== null) {
            $q->whereIn('site_diaries.warehouse_id', $ids ?: [0]);
        }
        if (! SiteDiary::canSeeAll($user)) {
            $q->where('site_diaries.recorded_by', $user->id);
        }
        $items = $q->orderByDesc('site_diary_media.id')->limit(self::ROW_CAP)
            ->get(['site_diary_media.*', 'site_diaries.reference as diary_ref', 'warehouses.name as project_name', 'users.name as uploader_name']);
        $canPhoto = $user->can('view-site-diary-photos') && $this->settings($user)->enabled('photo');
        $canVideo = $user->can('view-site-diary-videos') && $this->settings($user)->enabled('video');

        $rows = $items->values()->map(fn ($m, $i) => [
            $i + 1,
            ['text' => $m->original_name, 'sub' => $m->diary_ref, 'url' => (($m->media_type === 'photo' && $canPhoto) || ($m->media_type === 'video' && $canVideo)) ? route('site-diary.media.open', [$m->site_diary_id, $m->id]) : null],
            $m->project_name, ['text' => ucfirst($m->media_type), 'tone' => $m->media_type === 'photo' ? 'blue' : 'indigo'],
            $m->uploader_name, number_format($m->file_size / 1048576, 2).' MB', optional($m->created_at)->format('d M Y H:i'),
            ['text' => ucfirst($m->upload_status), 'tone' => $m->upload_status === 'uploaded' ? 'green' : ($m->upload_status === 'failed' ? 'red' : 'amber')],
        ])->all();

        return [
            'scopeNote' => 'Every photo / video upload matching the filters above.',
            'groups' => [['title' => 'Summary', 'cards' => [
                ['label' => 'Files', 'value' => number_format($items->count()), 'tone' => 'navy', 'icon' => 'grid'],
                ['label' => 'Photos', 'value' => number_format($items->where('media_type', 'photo')->count()), 'tone' => 'sky', 'icon' => 'box'],
                ['label' => 'Videos', 'value' => number_format($items->where('media_type', 'video')->count()), 'tone' => 'violet', 'icon' => 'tag'],
                ['label' => 'Total size', 'value' => number_format($items->sum('file_size') / 1048576, 1).' MB', 'tone' => 'teal', 'icon' => 'trend'],
                ['label' => 'Failed', 'value' => number_format($items->where('upload_status', 'failed')->count()), 'tone' => 'rose', 'icon' => 'alert'],
            ]]],
            'tableTitle' => 'Uploads ('.number_format($items->count()).')',
            'columns' => [['label' => 'No', 'width' => '26px'], ['label' => 'File / Diary'], ['label' => 'Project'], ['label' => 'Type'], ['label' => 'Uploaded By'], ['label' => 'Size'], ['label' => 'Uploaded'], ['label' => 'Status']],
            'rows' => $rows,
            'emptyText' => 'No uploads match these filters.',
        ];
    }

    /** Who looked at / downloaded what. Needs the history ability as well, since it exposes other people's activity. */
    private function accessReport(User $user, array $f): array
    {
        abort_unless($user->can('view-site-diary-history'), 403);
        $q = Activity::query()->where('log_name', SiteDiaryLogger::LOG_NAME)
            ->whereIn('event', array_map('strtolower', SiteDiaryLogger::ACCESS_ACTIONS))->with('causer:id,name');
        $ids = SiteDiary::projectIdsFor($user);
        if ($ids !== null) {
            $q->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.project_id'))"), array_map('strval', $ids ?: [0]));
        }
        if (! empty($f['warehouse_id'])) {
            $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.project_id')) = ?", [(string) $f['warehouse_id']]);
        }
        if (! empty($f['recorded_by'])) {
            $q->where('causer_id', $f['recorded_by']);
        }
        if (! empty($f['date_from'])) {
            $q->where('created_at', '>=', $f['date_from'].' 00:00:00');
        }
        if (! empty($f['date_to'])) {
            $q->where('created_at', '<=', $f['date_to'].' 23:59:59');
        }
        $logs = $q->orderByDesc('id')->limit(self::ROW_CAP)->get();

        $rows = $logs->values()->map(fn ($a, $i) => [
            $i + 1, optional($a->created_at)->format('d M Y H:i:s'), optional($a->causer)->name ?? 'System',
            ['text' => str_replace('_', ' ', $a->properties['action'] ?? strtoupper($a->event)), 'tone' => str_contains((string) ($a->properties['action'] ?? ''), 'DOWNLOAD') ? 'amber' : 'slate'],
            ['text' => $a->properties['diary_reference'] ?? '', 'sub' => $a->properties['filename'] ?? ''],
            ['text' => $a->properties['ip'] ?? '', 'sub' => trim(($a->properties['browser'] ?? '').' · '.($a->properties['device'] ?? ''), ' ·')],
        ])->all();

        return [
            'scopeNote' => 'Views, new-tab opens and downloads of Site Diary records and media.',
            'groups' => [['title' => 'Summary', 'cards' => [
                ['label' => 'Events', 'value' => number_format($logs->count()), 'tone' => 'navy', 'icon' => 'grid'],
                ['label' => 'Downloads', 'value' => number_format($logs->filter(fn ($a) => str_contains((string) ($a->properties['action'] ?? ''), 'DOWNLOAD'))->count()), 'tone' => 'amber', 'icon' => 'box'],
                ['label' => 'People', 'value' => number_format($logs->pluck('causer_id')->unique()->count()), 'tone' => 'teal', 'icon' => 'users'],
            ]]],
            'tableTitle' => 'Access events ('.number_format($logs->count()).')',
            'columns' => [['label' => 'No', 'width' => '26px'], ['label' => 'When'], ['label' => 'User'], ['label' => 'Action'], ['label' => 'Diary / File'], ['label' => 'IP / Device']],
            'rows' => $rows,
            'emptyText' => 'No access events match these filters.',
        ];
    }
}

