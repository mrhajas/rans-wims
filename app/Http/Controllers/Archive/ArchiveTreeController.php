<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveFile;
use App\Models\ArchiveRecord;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ArchiveAccessLogger;
use App\Services\ArchiveRestrictedAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Archive Tree: records laid out as folders you can browse from a left-hand panel.
 *
 * A folder is a path of up to two levels, depending on how the tree is grouped:
 *   category > subcategory · source > organisation · project > category · year > category
 * A folder that holds Confidential / Restricted records the user may see is LOCKED: its contents are only sent after
 * the user re-enters their own password (kept for UNLOCK_MINUTES, per folder, in the session). A folder without such
 * records opens straight away. The usual permissions (read-archive, read-archive-confidential, …) apply on top.
 */
class ArchiveTreeController extends Controller
{
    public const MODES = ['category', 'source', 'project', 'year'];

    public const UNLOCK_MINUTES = 15;

    private const NONE = '__none__';

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $user = $this->authorizeTree($request);

        return Inertia::render('Archive/Tree', [
            'modes' => [
                ['key' => 'category', 'label' => __('By Category')],
                ['key' => 'source', 'label' => __('By Source')],
                ['key' => 'project', 'label' => __('By Project')],
                ['key' => 'year', 'label' => __('By Year')],
            ],
            'options' => [
                'statuses' => ArchiveRecord::STATUSES,
                'documentTypes' => ArchiveRecord::DOCUMENT_TYPES,
                'confidentiality' => $user->can('read-archive-confidential') ? ArchiveRecord::CONFIDENTIALITY : ['Public', 'Internal'],
                'fileTypes' => ArchiveFile::TYPES,
            ],
            'unlockMinutes' => self::UNLOCK_MINUTES,
            'can' => [
                'create' => $user->can('create-archive'),
                'storage' => $user->can('view-archive-storage'),
                'preview' => $user->can('preview-archive-files'),
                'download' => $user->can('download-archive-files'),
            ],
        ]);
    }

    /** Folders for one level: no `path` = top level, one value = its sub-folders. */
    public function nodes(Request $request)
    {
        $user = $this->authorizeTree($request);
        [$mode, $path] = $this->parse($request);
        abort_if(count($path) > 1, 422);

        $level = count($path);
        $column = $this->column($mode, $level);

        $query = $this->base($user)->toBase();
        if ($level === 1) {
            $this->applyFolder($query, $mode, $path);
        }

        $restricted = "'" . implode("','", ArchiveRecord::RESTRICTED_LEVELS) . "'";
        $rows = $query->selectRaw("$column as value, count(*) as total, coalesce(sum(archive_records.confidentiality in ($restricted)), 0) as restricted")
            ->groupBy(DB::raw($column))
            ->orderBy('value')
            ->limit(500)
            ->get();

        $names = $mode === 'project'
            ? ($level === 0 ? Warehouse::query()->whereIn('id', $rows->pluck('value')->filter())->pluck('name', 'id') : collect())
            : collect();

        $nodes = $rows->map(function ($r) use ($mode, $level, $names, $path, $user) {
            $empty = $r->value === null || $r->value === '';
            $value = $empty ? self::NONE : (string) $r->value;
            $folder = array_merge($path, [$value]);

            return [
                'key' => $mode . '|' . implode('|', $folder),
                'value' => $value,
                'label' => $empty ? $this->emptyLabel($mode, $level) : ($mode === 'project' && $level === 0 ? ($names[$r->value] ?? '#' . $r->value) : (string) $r->value),
                'count' => (int) $r->total,
                'locked' => (int) $r->restricted > 0 && ! $this->isUnlocked($mode, $folder),
                'restricted' => (int) $r->restricted > 0,
                'has_children' => $level === 0,
            ];
        })->values();

        // Newest year first reads better than ascending.
        if ($mode === 'year' && $level === 0) {
            $nodes = $nodes->sortByDesc('label')->values();
        }

        return response()->json(['data' => $nodes]);
    }

    /** Records inside one folder (paginated), or the top-level overview when no folder is chosen. */
    public function records(Request $request)
    {
        $user = $this->authorizeTree($request);
        [$mode, $path] = $this->parse($request);

        $query = $this->base($user);
        $restrictedHere = false;

        if ($path) {
            $this->applyFolder($query, $mode, $path);
            $restrictedHere = (clone $query)->whereIn('archive_records.confidentiality', ArchiveRecord::RESTRICTED_LEVELS)->exists();

            if ($restrictedHere && ! $this->isUnlocked($mode, $path)) {
                return response()->json(['locked' => true, 'folder' => implode(' › ', $path), 'minutes' => self::UNLOCK_MINUTES], 423);
            }
        } else {
            // Without a folder only the unrestricted recent records are listed: restricted ones live behind their folders.
            $query->whereNotIn('archive_records.confidentiality', ArchiveRecord::RESTRICTED_LEVELS);
        }

        $filters = array_filter($request->only(['q', 'status', 'confidentiality', 'document_type', 'file_type', 'document_from', 'document_to', 'expiry']), fn ($v) => $v !== null && $v !== '');
        $perPage = in_array((int) $request->query('per_page'), [12, 24, 48], true) ? (int) $request->query('per_page') : 24;
        $sort = array_key_exists($request->query('sort'), ArchiveRecord::SORTABLE) ? $request->query('sort') : null;

        $records = $query->select(['archive_records.id', 'reference', 'title', 'category', 'document_type', 'source_type', 'document_date', 'expiry_date',
                'files_count', 'storage_bytes', 'confidentiality', 'status', 'archive_records.updated_at'])
            ->filter($filters)
            ->sortBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $canStorage = $user->can('view-archive-storage');

        return response()->json([
            'locked' => false,
            'restricted' => $restrictedHere,
            'data' => $records->getCollection()->map(fn (ArchiveRecord $r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'title' => $r->title,
                'category' => $r->category,
                'document_type' => $r->document_type,
                'source_type' => $r->source_type,
                'document_date' => optional($r->document_date)->toDateString(),
                'is_expired' => $r->is_expired,
                'expires_soon' => $r->expires_soon,
                'files_count' => $r->files_count,
                'storage_bytes' => $canStorage ? $r->storage_bytes : null,
                'confidentiality' => $r->confidentiality,
                'status' => $r->status,
                'updated_at' => optional($r->updated_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
                'per_page' => $records->perPage(),
            ],
        ]);
    }

    public function unlock(Request $request)
    {
        $user = $this->authorizeTree($request);
        $request->validate(['password' => ['required', 'string', 'max:255']]);
        [$mode, $path] = $this->parse($request);
        abort_unless($path, 422);

        $label = implode(' › ', $path);

        if (! ArchiveRestrictedAccess::attempt($request, $user, $label)) {
            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        $unlocked = (array) $request->session()->get('archive_unlocked', []);
        $unlocked = array_filter($unlocked, fn ($until) => $until > time());
        $unlocked[$this->folderKey($mode, $path)] = time() + self::UNLOCK_MINUTES * 60;
        $request->session()->put('archive_unlocked', $unlocked);

        return response()->json(['success' => true, 'minutes' => self::UNLOCK_MINUTES]);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    private function authorizeTree(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->can('read-archive') && $user->can('read-archive-tree'), 403);

        return $user;
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function parse(Request $request): array
    {
        $mode = in_array($request->input('mode'), self::MODES, true) ? $request->input('mode') : 'category';
        $path = array_values(array_filter(array_map('strval', (array) $request->input('path', [])), fn ($v) => $v !== ''));

        return [$mode, array_slice($path, 0, 2)];
    }

    private function base(User $user)
    {
        return ArchiveRecord::query()->visibleTo($user);
    }

    /** SQL expression for the folder column at this level (table columns only — never user input). */
    private function column(string $mode, int $level): string
    {
        return match ($mode) {
            'category' => $level === 0 ? 'archive_records.category' : 'archive_records.subcategory',
            'source' => $level === 0 ? 'archive_records.source_type' : 'archive_records.organization',
            'project' => $level === 0 ? 'archive_records.warehouse_id' : 'archive_records.category',
            'year' => $level === 0 ? 'year(archive_records.document_date)' : 'archive_records.category',
        };
    }

    private function applyFolder($query, string $mode, array $path): void
    {
        foreach ($path as $level => $value) {
            $column = $this->column($mode, $level);

            if ($value === self::NONE) {
                $query->where(fn ($q) => $q->whereNull(DB::raw($column))->orWhere(DB::raw($column), ''));
            } else {
                $query->where(DB::raw($column), $value);
            }
        }
    }

    private function emptyLabel(string $mode, int $level): string
    {
        return match (true) {
            $mode === 'category' => __('General'),
            $mode === 'source' && $level === 0 => __('No source'),
            $mode === 'source' => __('No organisation'),
            $mode === 'project' && $level === 0 => __('No project'),
            $mode === 'year' && $level === 0 => __('Undated'),
            default => __('Uncategorised'),
        };
    }

    private function folderKey(string $mode, array $path): string
    {
        return md5($mode . '|' . implode('|', $path));
    }

    /** Unlocked when this folder, or the folder above it, was unlocked within the last UNLOCK_MINUTES. */
    private function isUnlocked(string $mode, array $path): bool
    {
        $unlocked = (array) session('archive_unlocked', []);

        for ($i = 1; $i <= count($path); $i++) {
            $until = $unlocked[$this->folderKey($mode, array_slice($path, 0, $i))] ?? 0;
            if ($until > time()) {
                return true;
            }
        }

        return false;
    }
}
