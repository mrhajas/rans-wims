<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One Site Diary: a project (warehouse row), a date, who recorded it, remarks, and its photos/videos
 * (metadata only — the files live in Google Drive). Photo/video counters are denormalised for fast lists.
 */
class SiteDiary extends Model
{
    public const SORTABLE = [
        'reference' => 'site_diaries.reference',
        'project' => 'warehouses.name',
        'date' => 'site_diaries.diary_date',
        'recorded_by' => 'users.name',
        'photos' => 'site_diaries.photos_count',
        'videos' => 'site_diaries.videos_count',
        'size' => 'site_diaries.media_bytes',
        'created' => 'site_diaries.created_at',
        'updated' => 'site_diaries.updated_at',
    ];

    // Everything that happens to a diary is written explicitly by SiteDiaryLogger (with IP / device), so the generic
    // attribute auditing is switched off to keep the System Logs readable.
    protected static function eventsToBeRecorded(): \Illuminate\Support\Collection
    {
        return collect();
    }

    protected $table = 'site_diaries';

    public $casts = [
        'diary_date' => 'date',
        'last_media_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function media()
    {
        return $this->hasMany(SiteDiaryMedia::class)->orderBy('id');
    }

    public function photos()
    {
        return $this->media()->where('media_type', 'photo');
    }

    public function videos()
    {
        return $this->media()->where('media_type', 'video');
    }

    public static function nextReference(): string
    {
        $prefix = 'SDY-'.now()->format('Y').'-';
        // only numbered references count (a hand-typed or imported one such as SDY-2026-DEMO must not restart the series)
        $last = static::withTrashed()->where('reference', 'like', $prefix.'%')->whereRaw('reference REGEXP ?', ['^'.$prefix.'[0-9]+$'])->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    // ---- access ---------------------------------------------------------------------------------------------

    /** Project ids the user may work in (null = every project). */
    public static function projectIdsFor(User $user): ?array
    {
        if ($user->hasRole(['Super Admin', 'Admin']) && ! $user->warehouses()->exists()) {
            return null;
        }
        $ids = $user->warehouses()->pluck('warehouses.id')->all();

        return $ids ?: ($user->warehouse_id ? [$user->warehouse_id] : []);
    }

    public static function canSeeAll(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('view-all-site-diaries');
    }

    public function scopeVisibleTo($query, User $user)
    {
        $ids = static::projectIdsFor($user);
        if ($ids !== null) {
            $query->whereIn('site_diaries.warehouse_id', $ids ?: [0]);
        }
        if (! static::canSeeAll($user)) {
            $query->where('site_diaries.recorded_by', $user->id);
        }

        return $query;
    }

    public function isVisibleTo(User $user): bool
    {
        if (! $user->can('read-site-diary')) {
            return false;
        }
        $ids = static::projectIdsFor($user);
        if ($ids !== null && ! in_array((int) $this->warehouse_id, array_map('intval', $ids), true)) {
            return false;
        }

        // a draft (no attachment yet) belongs to the person who started it
        if ($this->status === 'Draft') {
            return (int) $this->recorded_by === (int) $user->id;
        }

        return static::canSeeAll($user) || (int) $this->recorded_by === (int) $user->id;
    }

    public function canEditBy(User $user): bool
    {
        if (! $this->isVisibleTo($user)) {
            return false;
        }

        return $user->can('update-any-site-diary') || ($user->can('update-site-diary') && (int) $this->recorded_by === (int) $user->id);
    }

    public function canDeleteBy(User $user): bool
    {
        if (! $this->isVisibleTo($user)) {
            return false;
        }

        return $user->can('delete-any-site-diary') || ($user->can('delete-site-diary') && (int) $this->recorded_by === (int) $user->id);
    }

    // ---- listing ----------------------------------------------------------------------------------------------

    public function scopeFilter($query, array $f)
    {
        $query
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
                $q->where(function ($q) use ($like) {
                    $q->where('site_diaries.reference', 'like', $like)
                        ->orWhere('site_diaries.remarks', 'like', $like)
                        ->orWhere('warehouses.name', 'like', $like)
                        ->orWhere('warehouses.code', 'like', $like)
                        ->orWhere('users.name', 'like', $like)
                        ->orWhere('users.username', 'like', $like);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($like, '%'))) {
                        $q->orWhere('site_diaries.diary_date', trim($like, '%'));
                    }
                });
            })
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('site_diaries.warehouse_id', $v))
            ->when($f['recorded_by'] ?? null, fn ($q, $v) => $q->where('site_diaries.recorded_by', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('site_diaries.diary_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('site_diaries.diary_date', '<=', $v))
            ->when($f['created_from'] ?? null, fn ($q, $v) => $q->where('site_diaries.created_at', '>=', $v.' 00:00:00'))
            ->when($f['created_to'] ?? null, fn ($q, $v) => $q->where('site_diaries.created_at', '<=', $v.' 23:59:59'))
            ->when(($f['has_photos'] ?? '') === '1', fn ($q) => $q->where('site_diaries.photos_count', '>', 0))
            ->when(($f['has_photos'] ?? '') === '0', fn ($q) => $q->where('site_diaries.photos_count', 0))
            ->when(($f['has_videos'] ?? '') === '1', fn ($q) => $q->where('site_diaries.videos_count', '>', 0))
            ->when(($f['has_videos'] ?? '') === '0', fn ($q) => $q->where('site_diaries.videos_count', 0));

        return $query;
    }

    /** Joined query used by the list, reports and exports (project + recorder columns come from the joins). */
    public static function listQuery(User $user, array $filters, bool $trashed = false, bool $withOwnDrafts = false)
    {
        $q = static::withoutGlobalScope('account')
            ->join('warehouses', 'warehouses.id', '=', 'site_diaries.warehouse_id')
            ->join('users', 'users.id', '=', 'site_diaries.recorded_by')
            ->select('site_diaries.*', 'warehouses.name as project_name', 'warehouses.code as project_code', 'warehouses.status as project_status', 'users.name as recorder_name')
            ->where('site_diaries.account_id', $user->account_id)
            ->visibleTo($user)
            ->filter($filters)
            // diaries still waiting for their first photo / video are drafts: never in reports or counts; only the owner sees them in the list
            ->where(function ($q) use ($user, $withOwnDrafts) {
                $q->where('site_diaries.status', '!=', 'Draft');
                if ($withOwnDrafts) {
                    $q->orWhere('site_diaries.recorded_by', $user->id);
                }
            });
        if ($trashed) {
            $q->onlyTrashed();
        }

        $sort = $filters['sort'] ?? null;
        $dir = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        if ($sort && isset(self::SORTABLE[$sort])) {
            $q->orderBy(self::SORTABLE[$sort], $dir)->orderByDesc('site_diaries.id');
        } else {
            $q->orderByDesc('site_diaries.diary_date')->orderByDesc('site_diaries.id');
        }

        return $q;
    }
}
