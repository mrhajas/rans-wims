<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ArchiveRecord extends Model
{
    use SoftDeletes;

    public const CATEGORIES = ['Government', 'Financial', 'Legal', 'Project', 'Correspondence', 'Contracts', 'Technical', 'Human Resources', 'Other'];

    public const SOURCES = ['Government', 'Clients', 'Banks', 'Projects', 'Suppliers', 'Internal', 'Other'];

    public const DOCUMENT_TYPES = ['Letter', 'Contract', 'Invoice', 'Receipt', 'Certificate', 'Permit / Licence', 'Report', 'Drawing', 'Minutes', 'Statement', 'Notice', 'Other'];

    public const CONFIDENTIALITY = ['Public', 'Internal', 'Confidential', 'Restricted'];

    public const RESTRICTED_LEVELS = ['Confidential', 'Restricted'];

    public const STATUSES = ['Active', 'Archived', 'Superseded'];

    /** Request sort key => column. Whitelisted so a crafted ?sort= can never reach the SQL. */
    public const SORTABLE = [
        'reference' => 'archive_records.reference',
        'title' => 'archive_records.title',
        'category' => 'archive_records.category',
        'source_type' => 'archive_records.source_type',
        'document_date' => 'archive_records.document_date',
        'received_date' => 'archive_records.received_date',
        'files_count' => 'archive_records.files_count',
        'storage_bytes' => 'archive_records.storage_bytes',
        'created_at' => 'archive_records.created_at',
        'updated_at' => 'archive_records.updated_at',
    ];

    protected $guarded = [];

    protected $casts = [
        'document_date' => 'date',
        'received_date' => 'date',
        'expiry_date' => 'date',
        'files_count' => 'integer',
        'original_bytes' => 'integer',
        'storage_bytes' => 'integer',
    ];

    public function files()
    {
        return $this->hasMany(ArchiveFile::class);
    }

    public function project()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function getIsRestrictedAttribute(): bool
    {
        return in_array($this->confidentiality, self::RESTRICTED_LEVELS, true);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function getExpiresSoonAttribute(): bool
    {
        return $this->expiry_date && ! $this->expiry_date->isPast() && $this->expiry_date->lte(now()->addDays(30));
    }

    /** Can this user open the record at all? (Confidential / Restricted need their own permission.) */
    public function isAccessibleBy(?User $user): bool
    {
        if (! $user || ! $user->can('read-archive')) {
            return false;
        }

        return ! $this->is_restricted || $user->can('read-archive-confidential');
    }

    /** ARC-2026-000125 — year + running number, collision-safe at the DB level by the unique index (see store()). */
    public static function nextReference(): string
    {
        $prefix = 'ARC-' . now()->format('Y') . '-';
        $last = static::withTrashed()->where('reference', 'like', $prefix . '%')->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /** Re-derive the per-record counters from its files with one UPDATE — no PHP loop, no storage scan. */
    public function refreshTotals(): void
    {
        DB::table('archive_records')->where('id', $this->id)->update([
            'files_count' => DB::raw('(select count(*) from archive_files where archive_files.archive_record_id = archive_records.id and archive_files.deleted_at is null)'),
            'original_bytes' => DB::raw('(select coalesce(sum(size_bytes), 0) from archive_files where archive_files.archive_record_id = archive_records.id and archive_files.deleted_at is null)'),
            'storage_bytes' => DB::raw('(select coalesce(sum(coalesce(optimized_size, size_bytes) + coalesce(thumbnail_size, 0)), 0) from archive_files where archive_files.archive_record_id = archive_records.id and archive_files.deleted_at is null)'),
        ]);
    }

    public function tagNames(): array
    {
        return DB::table('archive_record_tags')->where('archive_record_id', $this->id)->orderBy('tag')->pluck('tag')->all();
    }

    public function syncTags(array $tags): void
    {
        $tags = collect($tags)->map(fn ($t) => mb_substr(trim((string) $t), 0, 60))->filter()->unique(fn ($t) => mb_strtolower($t))->values();

        DB::table('archive_record_tags')->where('archive_record_id', $this->id)->delete();
        if ($tags->isNotEmpty()) {
            DB::table('archive_record_tags')->insert($tags->map(fn ($t) => ['archive_record_id' => $this->id, 'tag' => $t])->all());
        }
    }

    // ---- query scopes -------------------------------------------------------------------------------------------

    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && $user->can('read-archive-confidential')) {
            return $query;
        }

        return $query->whereNotIn('archive_records.confidentiality', self::RESTRICTED_LEVELS);
    }

    /**
     * Fast server-side search. A reference prefix hits its index; descriptive text, filenames and tags use
     * word-prefix FULLTEXT (so "Ministry of Fin" finds "Ministry of Finance"); very short input falls back to LIKE.
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY))->filter(fn ($w) => mb_strlen($w) >= 3)->values();
        $boolean = $words->map(fn ($w) => '+' . $w . '*')->implode(' ');

        // Each way a record can match is its own indexed lookup (reference prefix, tag prefix, FULLTEXT on the text
        // columns, FULLTEXT on attachment filenames); the record must be in the UNION of them. One big OR across
        // all of these would force a full table scan.
        $ids = DB::table('archive_records')->select('id')->where('reference', 'like', $like . '%')
            ->union(DB::table('archive_records')->select('id')->where('reference_number', 'like', $like . '%'))
            ->union(DB::table('archive_record_tags')->select('archive_record_id as id')->where('tag', 'like', $like . '%'));

        if ($words->isEmpty()) {
            $ids->union(DB::table('archive_records')->select('id')->where('title', 'like', $like . '%'));
        } else {
            $ids->union(DB::table('archive_records')->select('id')->whereRaw('MATCH(title, description, keywords, organization, sender, recipient, reference_number) AGAINST (? IN BOOLEAN MODE)', [$boolean]))
                ->union(DB::table('archive_files')->select('archive_record_id as id')->whereNull('deleted_at')->whereRaw('MATCH(original_name) AGAINST (? IN BOOLEAN MODE)', [$boolean]));
        }

        // Joined as a derived table (materialised once, then matched by primary key) — far faster than IN (subquery).
        return $query->joinSub($ids, 'archive_hits', 'archive_hits.id', '=', 'archive_records.id');
    }

    /** Every advanced filter, combinable. `q` (search) is handled by scopeSearch(). */
    public function scopeFilter($query, array $f)
    {
        $exact = ['category', 'subcategory', 'document_type', 'source_type', 'organization', 'department', 'confidentiality', 'status'];
        foreach ($exact as $col) {
            $query->when($f[$col] ?? null, fn ($q, $v) => $q->where('archive_records.' . $col, $v));
        }

        return $query
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('archive_records.warehouse_id', $v))
            ->when($f['contact_id'] ?? null, fn ($q, $v) => $q->where('archive_records.contact_id', $v))
            ->when($f['created_by_user_id'] ?? null, fn ($q, $v) => $q->where('archive_records.created_by_user_id', $v))
            ->when($f['updated_by_user_id'] ?? null, fn ($q, $v) => $q->where('archive_records.updated_by_user_id', $v))
            ->when($f['document_from'] ?? null, fn ($q, $v) => $q->where('archive_records.document_date', '>=', $v))
            ->when($f['document_to'] ?? null, fn ($q, $v) => $q->where('archive_records.document_date', '<=', $v))
            ->when($f['received_from'] ?? null, fn ($q, $v) => $q->where('archive_records.received_date', '>=', $v))
            ->when($f['received_to'] ?? null, fn ($q, $v) => $q->where('archive_records.received_date', '<=', $v))
            ->when($f['expiry_from'] ?? null, fn ($q, $v) => $q->where('archive_records.expiry_date', '>=', $v))
            ->when($f['expiry_to'] ?? null, fn ($q, $v) => $q->where('archive_records.expiry_date', '<=', $v))
            ->when($f['expiry'] ?? null, function ($q, $v) {
                if ($v === 'expired') {
                    $q->where('archive_records.expiry_date', '<', now()->toDateString());
                } elseif ($v === 'expiring') {
                    $q->whereBetween('archive_records.expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()]);
                }
            })
            ->when($f['file_type'] ?? null, fn ($q, $v) => $q->whereIn('archive_records.id', DB::table('archive_files')->select('archive_record_id')->whereNull('deleted_at')->where('file_type', $v)))
            ->when($f['tag'] ?? null, fn ($q, $v) => $q->whereIn('archive_records.id', DB::table('archive_record_tags')->select('archive_record_id')->where('tag', $v)))
            ->search($f['q'] ?? null);
    }

    public function scopeSortBy($query, ?string $sort, ?string $direction)
    {
        $column = self::SORTABLE[$sort] ?? null;
        if (! $column) {
            return $query->orderByDesc('archive_records.id');
        }

        return $query->orderBy($column, strtolower((string) $direction) === 'asc' ? 'asc' : 'desc')->orderByDesc('archive_records.id');
    }
}
