<?php

namespace App\Http\Controllers\VisitorLogs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VisitorLogs\Concerns\Guards;
use App\Models\Role;
use App\Models\VisitorOption;
use App\Services\VisitorLogs\VisitorSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Visitor Logs settings, soft-coded: the lists (purposes, item types, item statuses, departments, visitor categories), the thresholds, the optional visitor fields, who can be visited and the retention note. */
class SettingsController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->needAny(['manage-visitor-options', 'manage-visitor-settings']);
        $me = $request->user();
        $lists = [];
        foreach (VisitorOption::KINDS as $kind => $label) {
            $lists[$kind] = VisitorOption::listOf($kind, false);
        }

        return Inertia::render('VisitorLogs/Settings', [
            'lists' => $lists, 'kinds' => collect(VisitorOption::KINDS)->map(fn ($l, $k) => ['key' => $k, 'label' => __($l), 'flags' => collect(VisitorOption::FLAGS[$k] ?? [])->map(fn ($fl, $v) => ['value' => $v, 'label' => __($fl)])->values()])->values(),
            'values' => ['overstay_hours' => VisitorSettings::overstayHours(), 'collection_days' => VisitorSettings::collectionDays(), 'retention_months' => VisitorSettings::retentionMonths(), 'location' => VisitorSettings::location(), 'fields' => VisitorSettings::fields(),
                'host_roles' => VisitorSettings::hostRoles(), 'require_phone' => VisitorSettings::requirePhone(), 'default_warehouse' => VisitorSettings::receptionWarehouseId()],
            'warehouses' => \App\Models\Warehouse::query()->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($w) => ['value' => (string) $w->id, 'label' => $w->name . ($w->code ? ' (' . $w->code . ')' : '')])->values(),
            'optionalFields' => collect(VisitorSettings::OPTIONAL_FIELDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l), 'sensitive' => in_array($k, \App\Models\Visitor::SENSITIVE, true)])->values(),
            'roles' => Role::ofAccount()->orderBy('name')->pluck('name'), 'can' => ['options' => $me->can('manage-visitor-options'), 'settings' => $me->can('manage-visitor-settings')],
        ]);
    }

    public function update(Request $request)
    {
        $this->need('manage-visitor-settings');
        $d = $request->validate([
            'overstay_hours' => 'required|integer|min:0|max:72', 'collection_days' => 'required|integer|min:0|max:365', 'retention_months' => 'nullable|integer|min:1|max:600', 'location' => 'nullable|string|max:160',
            'fields' => 'nullable|array', 'fields.*' => 'string|in:' . implode(',', array_keys(VisitorSettings::OPTIONAL_FIELDS)), 'host_roles' => 'nullable|array', 'host_roles.*' => 'string|max:100', 'require_phone' => 'nullable|boolean', 'default_warehouse' => 'nullable|integer',
        ], ['overstay_hours.required' => __('Type the hours (0 switches the reminder off).'), 'collection_days.required' => __('Type the days (0 switches the reminder off).')]);
        $roles = array_values(array_intersect($d['host_roles'] ?? [], Role::ofAccount()->pluck('name')->all()));
        $values = ['visitor_overstay_hours' => (string) $d['overstay_hours'], 'visitor_collection_days' => (string) $d['collection_days'], 'visitor_retention_months' => isset($d['retention_months']) ? (string) $d['retention_months'] : '', 'visitor_reception_location' => trim((string) ($d['location'] ?? '')),
            'visitor_fields' => implode(', ', $d['fields'] ?? []), 'visitor_host_roles' => implode(', ', $roles), 'visitor_require_phone' => ! empty($d['require_phone']) ? '1' : '0',
            'visitor_default_warehouse' => ! empty($d['default_warehouse']) && \App\Models\Warehouse::query()->whereKey((int) $d['default_warehouse'])->exists() ? (string) (int) $d['default_warehouse'] : ''];
        $before = collect($values)->map(fn ($v, $k) => VisitorSettings::get($k))->all();
        VisitorSettings::save($values, $request->user()->account_id);
        log_activity('Visitor Logs settings saved', ['before' => $before, 'after' => $values], null, 'Visitor Logs');

        return back()->with('message', __('Visitor Logs settings saved.'));
    }

    private function validFlag(string $kind, ?string $flag): ?string
    {
        $flag = trim((string) $flag);
        if ($flag === '') {
            return null;
        }
        if (! array_key_exists($flag, VisitorOption::FLAGS[$kind] ?? [])) {
            throw ValidationException::withMessages(['flag' => __('That meaning is not available here.')]);
        }

        return $flag;
    }

    /** A status list must keep a first status and a "handed over" status, or no item could be registered or finished. */
    private function guardStatuses(string $kind, int $exceptId = 0, ?array $changed = null): void
    {
        if ($kind !== 'item_status') {
            return;
        }
        $rows = collect(VisitorOption::listOf('item_status', false))->map(fn ($r) => $r['id'] === $exceptId && $changed ? array_merge($r, $changed) : $r)->where('is_active', true);
        foreach (['initial' => __('the first status of a new item'), 'handed' => __('a "handed over" status')] as $flag => $what) {
            if (! $rows->contains('flag', $flag)) {
                throw ValidationException::withMessages(['flag' => __('Keep at least one active status that is :w.', ['w' => $what])]);
            }
        }
    }

    public function storeOption(Request $request)
    {
        $this->need('manage-visitor-options');
        $d = $request->validate(['kind' => 'required|string|in:' . implode(',', array_keys(VisitorOption::KINDS)), 'name' => 'required|string|max:120', 'flag' => 'nullable|string|max:24'], ['name.required' => __('Type the name.')]);
        $name = trim($d['name']);
        if (collect(VisitorOption::listOf($d['kind'], false))->contains(fn ($r) => mb_strtolower($r['name']) === mb_strtolower($name))) {
            throw ValidationException::withMessages(['name' => __('This is already in the list.')]);
        }
        $flag = $this->validFlag($d['kind'], $d['flag'] ?? null);
        $o = VisitorOption::create(['kind' => $d['kind'], 'name' => $name, 'flag' => $flag, 'is_active' => true, 'sort_order' => (int) VisitorOption::query()->where('kind', $d['kind'])->max('sort_order') + 1]);
        log_activity('Visitor Logs list entry added', ['list' => $d['kind'], 'name' => $name, 'flag' => $flag], $o, 'Visitor Logs');

        return back()->with('message', __(':n added.', ['n' => $name]));
    }

    public function updateOption(Request $request, int $option)
    {
        $this->need('manage-visitor-options');
        $o = VisitorOption::query()->findOrFail($option);
        $d = $request->validate(['name' => 'required|string|max:120', 'flag' => 'nullable|string|max:24', 'is_active' => 'required|boolean'], ['name.required' => __('Type the name.')]);
        $name = trim($d['name']);
        if (collect(VisitorOption::listOf($o->kind, false))->contains(fn ($r) => $r['id'] !== $o->id && mb_strtolower($r['name']) === mb_strtolower($name))) {
            throw ValidationException::withMessages(['name' => __('This is already in the list.')]);
        }
        $flag = $this->validFlag($o->kind, $d['flag'] ?? null);
        $this->guardStatuses($o->kind, $o->id, ['flag' => $flag, 'is_active' => (bool) $d['is_active']]);
        $old = ['name' => $o->name, 'flag' => $o->flag, 'is_active' => (bool) $o->is_active];
        $o->update(['name' => $name, 'flag' => $flag, 'is_active' => (bool) $d['is_active']]);
        log_activity('Visitor Logs list entry changed', ['list' => $o->kind, 'before' => $old, 'after' => ['name' => $name, 'flag' => $flag, 'is_active' => (bool) $d['is_active']]], $o, 'Visitor Logs');

        return back()->with('message', __('Saved.'));
    }

    public function reorderOptions(Request $request)
    {
        $this->need('manage-visitor-options');
        $d = $request->validate(['kind' => 'required|string|in:' . implode(',', array_keys(VisitorOption::KINDS)), 'ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $own = VisitorOption::query()->where('kind', $d['kind'])->pluck('id')->all();
        $i = 0;
        foreach (array_values(array_unique(array_map('intval', $d['ids']))) as $id) {
            if (in_array($id, $own, true)) {
                VisitorOption::query()->whereKey($id)->update(['sort_order' => ++$i]);
            }
        }
        \Illuminate\Support\Facades\Cache::forget('visitor_options.' . getAccountId(1));
        log_activity('Visitor Logs list reordered', ['list' => $d['kind']], null, 'Visitor Logs');

        return back()->with('message', __('Order saved.'));
    }
}
