<?php

namespace App\Models;

use Illuminate\Support\Facades\Cache;

/**
 * One entry of a Visitor Logs list a Super Admin keeps: visit purposes, item types, item statuses, departments, visitor categories. Nothing here is a business rule:
 * a flag only tells the module what an entry means (a purpose that asks for a description, a status that is "ready for collection" or "handed over").
 */
class VisitorOption extends Model
{
    public const KINDS = [
        'purpose' => 'Visit purposes', 'item_type' => 'Item types', 'item_status' => 'Item statuses', 'department' => 'Departments', 'visitor_category' => 'Visitor categories',
    ];

    /** What each kind's flag can be (value => label). */
    public const FLAGS = [
        'purpose' => ['other' => 'Asks for a description'],
        'item_type' => ['document' => 'A letter / document', 'package' => 'A package / parcel'],
        'item_status' => ['initial' => 'The first status of a new item', 'ready' => 'Ready for collection', 'handed' => 'Handed over (delivered / collected)', 'closed' => 'Closed without a hand-over (returned...)'],
    ];

    protected $table = 'visitor_options';

    protected $fillable = ['account_id', 'kind', 'name', 'flag', 'is_active', 'sort_order'];

    public $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted()
    {
        parent::booted();
        $flush = fn () => Cache::forget('visitor_options.' . getAccountId(1));
        static::saved($flush);
        static::deleted($flush);
    }

    /** The active entries of a kind, in order (a few rows, kept in the cache). @return array<int,array{id:int,name:string,flag:?string,is_active:bool,sort_order:int}> */
    public static function listOf(string $kind, bool $onlyActive = true): array
    {
        $all = Cache::remember('visitor_options.' . getAccountId(1), 600, fn () => static::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'kind', 'name', 'flag', 'is_active', 'sort_order'])->groupBy('kind')->map(fn ($g) => $g->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'flag' => $o->flag, 'is_active' => (bool) $o->is_active, 'sort_order' => (int) $o->sort_order])->values()->all())->all());
        $rows = $all[$kind] ?? [];

        return $onlyActive ? array_values(array_filter($rows, fn ($r) => $r['is_active'])) : $rows;
    }

    public static function names(string $kind, bool $onlyActive = true): array
    {
        return array_column(self::listOf($kind, $onlyActive), 'name');
    }

    /** The flag of the entry with this name ("" when there is none), so a renamed or removed entry never breaks an old record. */
    public static function flagOf(string $kind, ?string $name): ?string
    {
        foreach (self::listOf($kind, false) as $r) {
            if (mb_strtolower($r['name']) === mb_strtolower((string) $name)) {
                return $r['flag'];
            }
        }

        return null;
    }

    public static function firstWithFlag(string $kind, string $flag): ?string
    {
        foreach (self::listOf($kind) as $r) {
            if ($r['flag'] === $flag) {
                return $r['name'];
            }
        }

        return null;
    }

    /**
     * A person typed a value that is not in the list (a new department, purpose or item type): it is added to the list now, so it is offered next time.
     * Returns the name as the list spells it. An entry an administrator switched off is not brought back by typing it.
     */
    public static function ensure(string $kind, string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        foreach (self::listOf($kind, false) as $r) {
            if (mb_strtolower($r['name']) === mb_strtolower($name)) {
                if (! $r['is_active']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([self::FIELD[$kind] ?? 'name' => __('":n" was switched off by an administrator, so it cannot be used.', ['n' => $r['name']])]);
                }

                return $r['name'];
            }
        }
        $o = static::create(['kind' => $kind, 'name' => mb_substr($name, 0, 120), 'flag' => null, 'is_active' => true, 'sort_order' => (int) static::query()->where('kind', $kind)->max('sort_order') + 1]);
        log_activity('Visitor Logs list entry added by a user', ['list' => $kind, 'name' => $o->name], $o, 'Visitor Logs');

        return $o->name;
    }

    private const FIELD = ['purpose' => 'purpose', 'department' => 'department', 'item_type' => 'item_type'];
}
