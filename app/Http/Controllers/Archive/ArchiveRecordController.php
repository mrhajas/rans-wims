<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveFile;
use App\Models\ArchiveRecord;
use App\Models\Contact;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ArchiveAccessLogger;
use App\Models\ArchiveNotificationRule;
use App\Services\ArchiveFileStore;
use App\Services\ArchiveNotifier;
use App\Services\ArchiveRestrictedAccess;
use App\Services\ArchiveStats;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ArchiveRecordController extends Controller
{
    public const PAGE_SIZES = [25, 50, 100];

    private const FILTER_KEYS = [
        'q', 'category', 'subcategory', 'document_type', 'source_type', 'organization', 'warehouse_id', 'contact_id', 'department',
        'document_from', 'document_to', 'received_from', 'received_to', 'expiry_from', 'expiry_to', 'expiry',
        'status', 'confidentiality', 'file_type', 'created_by_user_id', 'updated_by_user_id', 'tag',
    ];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-archive'), 403);

        $filters = array_filter($request->only(self::FILTER_KEYS), fn ($v) => $v !== null && $v !== '');
        $perPage = in_array((int) $request->query('per_page'), self::PAGE_SIZES, true) ? (int) $request->query('per_page') : 25;
        $sort = array_key_exists($request->query('sort'), ArchiveRecord::SORTABLE) ? $request->query('sort') : null;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $canSeeStorage = $user->can('view-archive-storage');
        $trashed = $request->query('trashed') === 'only' && $user->can('delete-archive');

        // Only the columns the table shows — no description/keywords blobs, no attachments (just the count).
        $records = ArchiveRecord::query()
            ->select(['archive_records.id', 'reference', 'title', 'category', 'document_type', 'source_type', 'warehouse_id', 'document_date', 'received_date',
                'expiry_date', 'files_count', 'storage_bytes', 'confidentiality', 'status', 'created_by_user_id', 'archive_records.updated_at', 'archive_records.created_at', 'archive_records.deleted_at'])
            ->with(['creator:id,name', 'project:id,name'])
            ->visibleTo($user)
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->filter($filters)
            ->sortBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        $records->getCollection()->transform(fn (ArchiveRecord $r) => [
            'id' => $r->id,
            'reference' => $r->reference,
            'title' => $r->title,
            'category' => $r->category,
            'document_type' => $r->document_type,
            'source_type' => $r->source_type,
            'project' => optional($r->project)->name,
            'document_date' => optional($r->document_date)->toDateString(),
            'received_date' => optional($r->received_date)->toDateString(),
            'expiry_date' => optional($r->expiry_date)->toDateString(),
            'is_expired' => $r->is_expired,
            'expires_soon' => $r->expires_soon,
            'files_count' => $r->files_count,
            'storage_bytes' => $canSeeStorage ? $r->storage_bytes : null,
            'confidentiality' => $r->confidentiality,
            'status' => $r->status,
            'created_by' => optional($r->creator)->name,
            'updated_at' => optional($r->updated_at)->toIso8601String(),
            'deleted_at' => optional($r->deleted_at)->toIso8601String(),
        ]);

        return Inertia::render('Archive/Index', [
            'records' => paginated_response($records),
            'filters' => $filters + ['sort' => $sort, 'direction' => $sort ? $direction : null, 'per_page' => $perPage, 'trashed' => $trashed ? 'only' : null],
            'options' => $this->filterOptions(),
            'pageSizes' => self::PAGE_SIZES,
            'can' => $this->abilities($user),
        ]);
    }

    /** Type-ahead for the quick search box: six best matches, minimal columns, same visibility rules as the list. */
    public function quick(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-archive'), 403);

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $rows = ArchiveRecord::query()
            ->select(['archive_records.id', 'reference', 'title', 'category', 'files_count'])
            ->visibleTo($user)
            ->search($term)
            ->orderByDesc('archive_records.id')
            ->limit(6)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('create-archive'), 403);

        return Inertia::render('Archive/Form', ['record' => null] + $this->formProps($request->user()));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('create-archive'), 403);

        $data = $this->validated($request, $user);
        $record = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $record = DB::transaction(function () use ($data, $user, $request) {
                    $record = ArchiveRecord::create(array_merge($this->recordAttributes($data), [
                        'reference' => ArchiveRecord::nextReference(),
                        'created_by_user_id' => $user->id,
                        'updated_by_user_id' => $user->id,
                    ]));
                    $record->syncTags($data['tags'] ?? []);

                    return $record;
                });
                break;
            } catch (QueryException $e) {
                // Two people saving at the same second can draw the same next reference — the unique index catches it, we retry.
                if ((string) $e->getCode() !== '23000' || ! str_contains($e->getMessage(), 'archive_records_reference_unique') || $attempt === 4) {
                    throw $e;
                }
            }
        }

        ArchiveAccessLogger::log($user, $record, 'CREATE');
        ArchiveNotifier::event($record->is_restricted ? ArchiveNotificationRule::RESTRICTED_RECORD_CREATED : ArchiveNotificationRule::RECORD_CREATED, $user, $record);
        $uploaded = $this->attachUploads($request, $record, $user);
        $record->refreshTotals();
        ArchiveStats::flush();

        if (self::jsonMode($request)) {
            // the browser now sends the files one by one (with a progress bar for each), against this new record
            if ($record->is_restricted) {
                $request->session()->put('archive_fresh_upload', ['id' => $record->id, 'until' => now()->addMinutes(30)->timestamp]);
            }

            return response()->json(['id' => $record->id, 'show_url' => route('archive.records.show', $record->id), 'upload_url' => route('archive.records.files.store', $record->id)]);
        }

        return redirect()->route('archive.records.show', $record->id)
            ->with('message', $uploaded ? __('Archive record created with :n file(s).', ['n' => $uploaded]) : __('Archive record created.'));
    }

    /** A plain XHR (axios) call, as opposed to an Inertia visit: answered with JSON so the form can drive its own progress UI. */
    public static function jsonMode(Request $request): bool
    {
        return $request->wantsJson() && ! $request->header('X-Inertia');
    }

    public function show(Request $request, ArchiveRecord $archive_record)
    {
        $user = $request->user();
        $record = $this->accessible($archive_record, $user, 'read-archive');

        if (self::restrictedBlocked($record)) {
            // Contents (details, files, history) stay hidden until the user confirms their password.
            return Inertia::render('Archive/Show', [
                'locked' => true,
                'minutes' => ArchiveRestrictedAccess::UNLOCK_MINUTES,
                'record' => ['id' => $record->id, 'reference' => $record->reference, 'confidentiality' => $record->confidentiality],
                'files' => null, 'changeHistory' => [], 'accessHistory' => null,
                'can' => $this->abilities($user),
            ]);
        }

        ArchiveAccessLogger::log($user, $record, 'VIEW');
        $record->load(['creator:id,name', 'updater:id,name', 'project:id,name', 'contact:id,name,category']);

        return Inertia::render('Archive/Show', [
            'locked' => false,
            'minutes' => ArchiveRestrictedAccess::UNLOCK_MINUTES,
            'record' => $this->presentDetail($record, $user),
            'files' => ArchiveFileController::page($record, $user, 1),
            'changeHistory' => ArchiveAccessLogger::changeHistory($record, 50),
            'accessHistory' => $user->can('view-archive-access-logs') ? ArchiveAccessLogger::accessHistory($record, 100) : null,
            'can' => $this->abilities($user),
        ]);
    }

    public function edit(Request $request, ArchiveRecord $archive_record)
    {
        $user = $request->user();
        $record = $this->accessible($archive_record, $user, 'update-archive');
        if (self::restrictedBlocked($record)) {
            return self::lockedResponse($request, $record);
        }

        return Inertia::render('Archive/Form', ['record' => $this->presentForm($record)] + $this->formProps($user));
    }

    public function update(Request $request, ArchiveRecord $archive_record)
    {
        $user = $request->user();
        $record = $this->accessible($archive_record, $user, 'update-archive');
        if (self::restrictedBlocked($record)) {
            return self::lockedResponse($request, $record);
        }
        $data = $this->validated($request, $user);

        $before = $record->only(['category', 'warehouse_id', 'confidentiality', 'expiry_date']);
        $before['expiry_date'] = optional($record->expiry_date)->toDateString();

        $record->fill($this->recordAttributes($data) + ['updated_by_user_id' => $user->id]);
        $dirty = $record->isDirty();
        $record->save();
        $record->syncTags($data['tags'] ?? []);

        ArchiveAccessLogger::log($user, $record, 'UPDATE', null, ['changes' => array_keys($record->getChanges())]);
        if (($before['category'] ?? null) !== $record->category) {
            ArchiveAccessLogger::log($user, $record, 'CATEGORY_CHANGE', null, ['from' => $before['category'], 'to' => $record->category]);
        }
        if ((int) $before['warehouse_id'] !== (int) $record->warehouse_id) {
            ArchiveAccessLogger::log($user, $record, 'PROJECT_CHANGE', null, ['from' => $before['warehouse_id'], 'to' => $record->warehouse_id]);
        }
        if (($before['confidentiality'] ?? null) !== $record->confidentiality) {
            ArchiveAccessLogger::log($user, $record, 'CONFIDENTIALITY_CHANGE', null, ['from' => $before['confidentiality'], 'to' => $record->confidentiality]);
            ArchiveNotifier::event(ArchiveNotificationRule::CONFIDENTIALITY_CHANGED, $user, $record, ['from' => $before['confidentiality'], 'to' => $record->confidentiality]);
        }
        if (($before['expiry_date'] ?? null) !== optional($record->expiry_date)->toDateString()) {
            ArchiveAccessLogger::log($user, $record, 'RETENTION_CHANGE', null, ['from' => $before['expiry_date'], 'to' => optional($record->expiry_date)->toDateString()]);
        }

        $changedFields = array_values(array_diff(array_keys($record->getChanges()), ['updated_at', 'updated_by_user_id']));
        if ($changedFields) {
            ArchiveNotifier::event(ArchiveNotificationRule::RECORD_UPDATED, $user, $record, ['changes' => implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $changedFields))]);
        }

        $uploaded = $this->attachUploads($request, $record, $user);
        if ($uploaded) {
            ArchiveNotifier::event(ArchiveNotificationRule::FILE_ADDED, $user, $record, ['filename' => trans_choice(':n file|:n files', $uploaded, ['n' => $uploaded])]);
        }
        $record->refreshTotals();
        ArchiveStats::flush();

        if (self::jsonMode($request)) {
            return response()->json(['id' => $record->id, 'show_url' => route('archive.records.show', $record->id), 'upload_url' => route('archive.records.files.store', $record->id)]);
        }

        return redirect()->route('archive.records.show', $record->id)->with('message', __('Archive record updated.'));
    }

    public function destroy(Request $request, ArchiveRecord $archive_record)
    {
        $user = $request->user();
        $record = $this->accessible($archive_record, $user, 'delete-archive');
        if (self::restrictedBlocked($record)) {
            return self::lockedResponse($request, $record);
        }

        $record->delete();
        ArchiveAccessLogger::log($user, $record, 'DELETE');
        ArchiveNotifier::event(ArchiveNotificationRule::RECORD_DELETED, $user, $record);
        ArchiveStats::flush();

        return redirect()->route('archive.records.index')->with('message', __('Archive record deleted. It can be restored from the "Deleted" filter.'));
    }

    public function restore(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user->can('delete-archive'), 403);

        $record = ArchiveRecord::onlyTrashed()->findOrFail($id);
        abort_unless($record->isAccessibleBy($user), 404);
        if (self::restrictedBlocked($record)) {
            return self::lockedResponse($request, $record);
        }

        $record->restore();
        ArchiveAccessLogger::log($user, $record, 'RESTORE');
        ArchiveNotifier::event(ArchiveNotificationRule::RECORD_RESTORED, $user, $record);
        ArchiveStats::flush();

        return back()->with('message', __('Archive record restored.'));
    }

    public function print(Request $request, ArchiveRecord $archive_record)
    {
        $user = $request->user();
        abort_unless($user->can('print-archive'), 403);
        $record = $this->accessible($archive_record, $user, 'read-archive');
        if (self::restrictedBlocked($record)) {
            return self::lockedResponse($request, $record);
        }

        ArchiveAccessLogger::log($user, $record, 'PRINT');
        $record->load(['creator:id,name', 'project:id,name', 'contact:id,name']);

        return view('archive.record-print', [
            'record' => $record,
            'tags' => $record->tagNames(),
            'files' => $record->files()->orderBy('id')->limit(200)->get(),
            'companyName' => optional($user->account)->name ?? 'RANS Company Limited',
            'generatedBy' => $user->name,
            'isPdf' => false,
        ]);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** 403 for a missing permission; 404 (not 403) for a record this user may not see, so existence is not leaked. */
    public static function accessible(ArchiveRecord $record, User $user, string $permission): ArchiveRecord
    {
        abort_unless($user->can($permission), 403);
        abort_unless($record->isAccessibleBy($user), 404);

        return $record;
    }

    /** Confidential / Restricted content is closed until the user has confirmed their password recently. */
    public static function restrictedBlocked(ArchiveRecord $record): bool
    {
        return $record->is_restricted && ! ArchiveRestrictedAccess::isUnlocked();
    }

    /** XHR callers get a 423 they can react to; page navigations land back on the record page, which asks for the password. */
    public static function lockedResponse(Request $request, ArchiveRecord $record)
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['locked' => true, 'message' => __('Enter your password to open confidential content.')], 423);
        }

        return redirect()->route('archive.records.show', $record->id)->with('error', __('Enter your password to open this confidential record.'));
    }

    /** Password prompt for restricted records (the Archive Tree has its own per-folder prompt as well). */
    public function unlockRestricted(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-archive'), 403);
        $request->validate(['password' => ['required', 'string', 'max:255']]);

        if (! ArchiveRestrictedAccess::attempt($request, $user)) {
            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        return response()->json(['success' => true, 'minutes' => ArchiveRestrictedAccess::UNLOCK_MINUTES]);
    }

    private function abilities(User $user): array
    {
        return [
            'create' => $user->can('create-archive'),
            'update' => $user->can('update-archive'),
            'delete' => $user->can('delete-archive'),
            'preview' => $user->can('preview-archive-files'),
            'download' => $user->can('download-archive-files'),
            'print' => $user->can('print-archive'),
            'confidential' => $user->can('read-archive-confidential'),
            'access' => $user->can('view-archive-access-logs'),
            'storage' => $user->can('view-archive-storage'),
            'tree' => $user->can('read-archive-tree'),
        ];
    }

    private function validated(Request $request, User $user): array
    {
        $levels = $user->can('read-archive-confidential') ? ArchiveRecord::CONFIDENTIALITY : ['Public', 'Internal'];

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(ArchiveRecord::CATEGORIES)],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'document_type' => ['nullable', 'string', 'max:60'],
            'source_type' => ['nullable', Rule::in(ArchiveRecord::SOURCES)],
            'organization' => ['nullable', 'string', 'max:190'],
            'sender' => ['nullable', 'string', 'max:190'],
            'recipient' => ['nullable', 'string', 'max:190'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'department' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date'],
            'received_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'confidentiality' => ['required', Rule::in($levels)],
            'status' => ['required', Rule::in(ArchiveRecord::STATUSES)],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:' . ArchiveFileStore::MAX_KB, 'extensions:' . implode(',', ArchiveFileStore::allowedExtensions())],
        ]);

        ArchiveFileStore::assertContent((array) $request->file('files', []));

        return $data;
    }

    private function recordAttributes(array $data): array
    {
        return collect($data)->except(['tags', 'files'])->map(fn ($v) => $v === '' ? null : $v)->all();
    }

    private function attachUploads(Request $request, ArchiveRecord $record, User $user): int
    {
        $count = 0;
        foreach ((array) $request->file('files', []) as $upload) {
            $file = ArchiveFileStore::store($upload, $record, $user);
            ArchiveAccessLogger::log($user, $record, 'UPLOAD', $file, ['size_bytes' => $file->size_bytes]);
            $count++;
        }

        return $count;
    }

    private function formProps(User $user): array
    {
        return [
            'options' => [
                'categories' => ArchiveRecord::CATEGORIES,
                'sources' => ArchiveRecord::SOURCES,
                'documentTypes' => ArchiveRecord::DOCUMENT_TYPES,
                'confidentiality' => $user->can('read-archive-confidential') ? ArchiveRecord::CONFIDENTIALITY : ['Public', 'Internal'],
                'statuses' => ArchiveRecord::STATUSES,
                'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['label' => $w->name, 'value' => $w->id, 'status' => $w->status])->all(),
                'contacts' => Contact::query()->orderBy('name')->get(['id', 'name', 'category'])->map(fn ($c) => ['label' => $c->name . ($c->category ? ' (' . $c->category . ')' : ''), 'value' => $c->id])->all(),
                'tags' => DB::table('archive_record_tags')->select('tag')->groupBy('tag')->orderByRaw('count(*) desc')->limit(100)->pluck('tag')->all(),
            ],
            'extensions' => ArchiveFileStore::allowedExtensions(),
            'maxMb' => (int) (ArchiveFileStore::MAX_KB / 1024),
        ];
    }

    private function presentForm(ArchiveRecord $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'title' => $r->title,
            'description' => $r->description,
            'category' => $r->category,
            'subcategory' => $r->subcategory,
            'document_type' => $r->document_type,
            'source_type' => $r->source_type,
            'organization' => $r->organization,
            'sender' => $r->sender,
            'recipient' => $r->recipient,
            'reference_number' => $r->reference_number,
            'keywords' => $r->keywords,
            'warehouse_id' => $r->warehouse_id,
            'contact_id' => $r->contact_id,
            'department' => $r->department,
            'document_date' => optional($r->document_date)->toDateString(),
            'received_date' => optional($r->received_date)->toDateString(),
            'expiry_date' => optional($r->expiry_date)->toDateString(),
            'confidentiality' => $r->confidentiality,
            'status' => $r->status,
            'tags' => $r->tagNames(),
        ];
    }

    private function presentDetail(ArchiveRecord $r, User $user): array
    {
        return $this->presentForm($r) + [
            'project' => optional($r->project)->name,
            'contact' => optional($r->contact)->name,
            'files_count' => $r->files_count,
            'original_bytes' => $user->can('view-archive-storage') ? $r->original_bytes : null,
            'storage_bytes' => $user->can('view-archive-storage') ? $r->storage_bytes : null,
            'is_expired' => $r->is_expired,
            'expires_soon' => $r->expires_soon,
            'created_by' => optional($r->creator)->name,
            'updated_by' => optional($r->updater)->name,
            'created_at' => optional($r->created_at)->toIso8601String(),
            'updated_at' => optional($r->updated_at)->toIso8601String(),
        ];
    }

    /** Dropdown lists for the Advanced Filters. Cached briefly: they only change when records do. */
    private function filterOptions(): array
    {
        $version = Cache::get('archive_stats_version', 1);

        return Cache::remember('archive_filter_options:' . $version, 600, fn () => [
            'categories' => ArchiveRecord::CATEGORIES,
            'sources' => ArchiveRecord::SOURCES,
            'documentTypes' => ArchiveRecord::DOCUMENT_TYPES,
            'confidentiality' => ArchiveRecord::CONFIDENTIALITY,
            'statuses' => ArchiveRecord::STATUSES,
            'fileTypes' => ArchiveFile::TYPES,
            'projects' => Warehouse::query()->whereIn('id', DB::table('archive_records')->select('warehouse_id')->whereNotNull('warehouse_id'))->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['label' => $w->name, 'value' => $w->id, 'status' => $w->status])->all(),
            'contacts' => Contact::query()->whereIn('id', DB::table('archive_records')->select('contact_id')->whereNotNull('contact_id'))->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['label' => $c->name, 'value' => $c->id])->all(),
            'organizations' => DB::table('archive_records')->whereNotNull('organization')->where('organization', '!=', '')->distinct()->orderBy('organization')->limit(300)->pluck('organization')->all(),
            'departments' => DB::table('archive_records')->whereNotNull('department')->where('department', '!=', '')->distinct()->orderBy('department')->limit(100)->pluck('department')->all(),
            'subcategories' => DB::table('archive_records')->whereNotNull('subcategory')->where('subcategory', '!=', '')->distinct()->orderBy('subcategory')->limit(200)->pluck('subcategory')->all(),
            'users' => User::query()->whereIn('id', DB::table('archive_records')->select('created_by_user_id')->whereNotNull('created_by_user_id'))->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['label' => $u->name, 'value' => $u->id])->all(),
            'tags' => DB::table('archive_record_tags')->select('tag')->groupBy('tag')->orderByRaw('count(*) desc')->limit(100)->pluck('tag')->all(),
        ]);
    }
}
