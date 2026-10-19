<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDepreciationEntry;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * Builds the data for the WIMS register-style PDF/print of company assets (reports/register-list.blade.php).
 *
 *  - 'list'      what the Asset Register table currently shows (same filters, same sort, or just the ticked rows)
 *  - 'full'      the complete register, nothing filtered out
 *  - 'dashboard' the Asset Dashboard cards first, then every asset
 */
class AssetRegisterReport
{
    public const MODE_LIST = 'list';

    public const MODE_FULL = 'full';

    public const MODE_DASHBOARD = 'dashboard';

    private const LAND_BUILDINGS = 'Land & Buildings';

    private const SORT_LABELS = [
        'asset_number' => 'Asset No.', 'category' => 'Category', 'asset_type' => 'Asset Type', 'location' => 'Location',
        'responsible' => 'Responsible', 'status' => 'Status', 'book_value' => 'Book Value',
    ];

    public static function build(User $user, array $query, string $mode, bool $isPdf): array
    {
        $hideValue = $user->hasRole(User::SITE_ENGINEER_ROLES);
        $scopeWarehouseIds = $hideValue ? (warehouse_scope_ids() ?? []) : null;

        $filters = [
            'ids' => array_values(array_filter((array) ($query['ids'] ?? []))),
            'asset_category_id' => $query['asset_category_id'] ?? null,
            'warehouse_id' => $query['warehouse_id'] ?? null,
            'asset_type' => in_array($query['asset_type'] ?? null, Asset::ASSET_TYPES, true) ? $query['asset_type'] : null,
            'status' => $query['status'] ?? null,
            'condition' => $query['condition'] ?? null,
            'search' => trim((string) ($query['search'] ?? '')) ?: null,
        ];

        $rowsQuery = Asset::query()
            ->inAssetRegister()
            ->with(['category:id,name,parent_id', 'category.parent:id,name', 'currentWarehouse:id,name', 'assignedUser:id,name'])
            ->when($mode === self::MODE_LIST, fn ($q) => $q->filter($filters))
            ->when($mode === self::MODE_LIST && empty($query['include_disposed']), fn ($q) => $q->active())
            ->when($scopeWarehouseIds !== null, fn ($q) => $q->whereIn('current_warehouse_id', $scopeWarehouseIds))
            ->when(
                $mode === self::MODE_LIST,
                fn ($q) => $q->sortBy($query['sort'] ?? null, $query['direction'] ?? null, 'id', 'desc'),
                fn ($q) => $q->orderBy('asset_number')
            );

        $assets = $rowsQuery->limit(10000)->get();
        $latest = self::latestDepreciation($assets);

        $bookValue = fn (Asset $a) => max(
            isset($latest[$a->id]) ? (float) $latest[$a->id]->closing_book_value : (float) $a->total_capitalised_cost,
            (float) $a->residual_value
        );

        $sumCost = (float) $assets->sum('total_capitalised_cost');
        $sumBook = (float) $assets->sum(fn (Asset $a) => $bookValue($a));
        $sumDepr = (float) $assets->sum(fn (Asset $a) => isset($latest[$a->id]) ? (float) $latest[$a->id]->accumulated_depreciation : 0);

        $money = fn ($v) => 'TSH ' . number_format((float) $v, 2);
        $num = fn ($v) => number_format((float) $v, 2);

        $inFamily = fn (Asset $a, string $name) => optional($a->category)->name === $name || optional(optional($a->category)->parent)->name === $name;
        $isLandBuilding = fn (Asset $a) => $inFamily($a, self::LAND_BUILDINGS);
        $isLand = fn (Asset $a) => optional($a->category)->name === 'Land';

        $count = fn (callable $fn) => $assets->filter($fn)->count();
        $landBuildings = $assets->filter($isLandBuilding);
        $tools = $assets->filter(fn (Asset $a) => $inFamily($a, 'Tools & Equipment'));
        $vehicles = $assets->filter(fn (Asset $a) => $inFamily($a, Asset::TRANSPORT_VEHICLE_CATEGORY_NAME));
        $landOnly = $assets->filter($isLand);

        $classCards = [
            ['label' => 'Total Assets', 'value' => number_format($assets->count()), 'tone' => 'navy', 'icon' => 'box'],
            ['label' => 'Fixed Assets', 'value' => number_format($count(fn ($a) => $a->asset_type === Asset::ASSET_TYPE_FIXED)), 'tone' => 'indigo', 'icon' => 'building'],
            ['label' => 'UnFixed Assets', 'value' => number_format($count(fn ($a) => $a->asset_type === Asset::ASSET_TYPE_UNFIXED)), 'tone' => 'teal', 'icon' => 'truck'],
            ['label' => 'Tools & Equipments', 'value' => number_format($tools->count()), 'sub' => $hideValue ? null : $money($tools->sum('total_capitalised_cost')), 'tone' => 'violet', 'icon' => 'wrench'],
        ];

        $valueCards = $hideValue ? [] : [
            ['label' => 'Total Cost (TSH)', 'value' => $num($sumCost), 'tone' => 'slate', 'icon' => 'cash'],
            ['label' => 'Total Book Value (TSH)', 'value' => $num($sumBook), 'tone' => 'maroon', 'icon' => 'trend'],
        ];

        $groups = [];
        if ($mode === self::MODE_LIST) {
            $groups[] = ['title' => 'Summary of the listed assets', 'cards' => array_merge($classCards, $valueCards)];
        } else {
            $groups[] = ['title' => 'Asset classes', 'cards' => array_merge($classCards, [
                ['label' => 'Land', 'value' => number_format($landOnly->count()), 'sub' => $hideValue ? null : $money($landOnly->sum('total_capitalised_cost')), 'tone' => 'emerald', 'icon' => 'pin'],
                ['label' => 'Buildings', 'value' => number_format($landBuildings->count() - $landOnly->count()), 'sub' => $hideValue ? null : $money($landBuildings->sum('total_capitalised_cost') - $landOnly->sum('total_capitalised_cost')), 'tone' => 'sky', 'icon' => 'building'],
                ['label' => 'Vehicles', 'value' => number_format($vehicles->count()), 'sub' => $hideValue ? null : $money($vehicles->sum('total_capitalised_cost')), 'tone' => 'maroon', 'icon' => 'car'],
            ])];

            $status = $assets->groupBy('status')->map->count();
            $sum = fn (array $names) => (int) collect($names)->sum(fn ($n) => $status[$n] ?? 0);
            $groups[] = ['title' => 'Status overview', 'cards' => [
                ['label' => 'Active', 'value' => number_format($count(fn ($a) => ! in_array($a->status, Asset::DISPOSED_STATUSES, true))), 'tone' => 'emerald', 'icon' => 'check'],
                ['label' => 'Assigned', 'value' => number_format($sum(['Assigned'])), 'tone' => 'sky', 'icon' => 'users'],
                ['label' => 'Available', 'value' => number_format($sum(['Available'])), 'tone' => 'teal', 'icon' => 'box'],
                ['label' => 'Under Maintenance', 'value' => number_format($sum(['Under Maintenance', 'Under Repair'])), 'tone' => 'amber', 'icon' => 'wrench'],
                ['label' => 'Lost / Stolen', 'value' => number_format($sum(['Lost', 'Stolen'])), 'tone' => 'rose', 'icon' => 'alert'],
                ['label' => 'Disposed', 'value' => number_format($sum(['Disposed', 'Sold', 'Written Off', 'Retired'])), 'tone' => 'slate', 'icon' => 'archive'],
            ]];

            if (! $hideValue) {
                $groups[] = ['title' => 'Asset value', 'cards' => [
                    ['label' => 'Total Acquisition Value (TSH)', 'value' => $num($sumCost), 'tone' => 'slate', 'icon' => 'cash'],
                    ['label' => 'Current Book Value (TSH)', 'value' => $num($sumBook), 'tone' => 'maroon', 'icon' => 'trend'],
                    ['label' => 'Accumulated Depreciation (TSH)', 'value' => $num($sumDepr), 'tone' => 'amber', 'icon' => 'trend'],
                ]];
            }
        }

        $breakdown = null;
        if ($mode !== self::MODE_LIST && $assets->isNotEmpty()) {
            $byGroup = $assets->groupBy(fn (Asset $a) => optional(optional($a->category)->parent)->name ?? optional($a->category)->name ?? 'Uncategorised')->sortKeys();
            $breakdown = [
                'title' => 'Assets by category group',
                'columns' => $hideValue ? ['Category group', 'Assets'] : ['Category group', 'Assets', 'Total Cost', 'Book Value'],
                'rows' => $byGroup->map(fn (Collection $g, $name) => $hideValue
                    ? [$name, number_format($g->count())]
                    : [$name, number_format($g->count()), number_format($g->sum('total_capitalised_cost'), 2), number_format($g->sum(fn ($a) => $bookValue($a)), 2)]
                )->values()->all(),
            ];
        }

        $columns = [
            ['label' => 'No', 'width' => '26px'],
            ['label' => 'Asset'],
            ['label' => 'Category'],
            ['label' => 'Type'],
            ['label' => 'Location'],
            ['label' => 'Responsible'],
            ['label' => 'Status'],
            ['label' => 'Acquired'],
        ];
        if (! $hideValue) {
            $columns[] = ['label' => 'Cost', 'align' => 'right'];
            $columns[] = ['label' => 'Book Value', 'align' => 'right'];
        }

        $rows = [];
        foreach ($assets as $i => $a) {
            $row = [
                $i + 1,
                ['text' => $a->asset_number, 'strong' => true, 'sub' => $a->name],
                ['text' => optional($a->category)->name, 'sub' => optional(optional($a->category)->parent)->name],
                $a->asset_type ? ['text' => $a->asset_type, 'tone' => $a->asset_type === Asset::ASSET_TYPE_FIXED ? 'indigo' : 'teal'] : '-',
                optional($a->currentWarehouse)->name ?: $a->current_location_label,
                optional($a->assignedUser)->name,
                ['text' => $a->status, 'tone' => self::statusTone($a->status), 'sub' => $a->condition],
                optional($a->acquisition_date)->format('d M Y'),
            ];
            if (! $hideValue) {
                $row[] = ['text' => number_format((float) $a->total_capitalised_cost, 2)];
                $row[] = ['text' => number_format($bookValue($a), 2), 'strong' => true, 'color' => '#7f1d1d'];
            }
            $rows[] = $row;
        }

        $titles = [
            self::MODE_LIST => 'Asset Register',
            self::MODE_FULL => 'Asset Register - Complete Report',
            self::MODE_DASHBOARD => 'Asset Dashboard Report',
        ];

        return [
            'title' => $titles[$mode],
            'isPdf' => $isPdf,
            'autoPrint' => ! $isPdf,
            'companyName' => optional($user->account)->name ?? 'RANS Company Limited',
            'generatedBy' => $user->name,
            'generatedAt' => now(),
            'filters' => $mode === self::MODE_LIST ? self::filterChips($filters, $query) : [],
            'scopeNote' => match (true) {
                $mode === self::MODE_FULL => 'Complete register: every asset, including disposed and lost items, in Asset No. order.',
                $mode === self::MODE_DASHBOARD => 'Dashboard figures first, followed by every asset in the register.',
                ! empty($filters['ids']) => 'Only the assets ticked on screen are listed.',
                default => 'Exactly the assets listed on screen for the filters above, nothing more.',
            },
            'groups' => $groups,
            'breakdown' => $breakdown,
            'tableOnNewPage' => $mode !== self::MODE_LIST,
            'tableTitle' => 'Assets (' . number_format($assets->count()) . ')',
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $hideValue ? null : ['span' => 8, 'label' => 'Total', 'cells' => [
                number_format($sumCost, 2),
                ['text' => number_format($sumBook, 2), 'color' => '#7f1d1d'],
            ]],
            'emptyText' => 'No assets found.',
        ];
    }

    /** Latest depreciation entry per asset (same "latest period_end" rule as Asset::getCurrentBookValueAttribute()). */
    private static function latestDepreciation(Collection $assets): Collection
    {
        if ($assets->isEmpty()) {
            return collect();
        }

        $latestIds = AssetDepreciationEntry::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('asset_id', $assets->pluck('id'))
            ->groupBy('asset_id');

        return AssetDepreciationEntry::query()->whereIn('id', $latestIds)->get()->keyBy('asset_id');
    }

    private static function filterChips(array $filters, array $query): array
    {
        $chips = [];
        if ($filters['search']) {
            $chips[] = ['Search', $filters['search']];
        }
        if ($filters['asset_category_id']) {
            $chips[] = ['Category', AssetCategory::query()->whereKey($filters['asset_category_id'])->value('name') ?? $filters['asset_category_id']];
        }
        if ($filters['warehouse_id']) {
            $chips[] = ['Project / Warehouse', Warehouse::query()->whereKey($filters['warehouse_id'])->value('name') ?? $filters['warehouse_id']];
        }
        foreach (['asset_type' => 'Asset Type', 'status' => 'Status', 'condition' => 'Condition'] as $key => $label) {
            if ($filters[$key]) {
                $chips[] = [$label, $filters[$key]];
            }
        }
        if (! empty($query['include_disposed'])) {
            $chips[] = ['Disposed', 'Included'];
        }
        if (in_array($query['sort'] ?? null, Asset::SORTABLE, true)) {
            $chips[] = ['Sorted by', self::SORT_LABELS[$query['sort']] . (strtolower((string) ($query['direction'] ?? '')) === 'asc' ? ' (ascending)' : ' (descending)')];
        }
        if (! empty($filters['ids'])) {
            $chips[] = ['Selected', count($filters['ids']) . ' asset(s)'];
        }

        return $chips;
    }

    private static function statusTone(?string $status): string
    {
        return match ($status) {
            'Active', 'Available' => 'green',
            'Assigned', 'In Use' => 'blue',
            'Under Maintenance', 'Under Repair', 'In Transit' => 'amber',
            'Lost', 'Stolen', 'Damaged' => 'red',
            default => 'slate',
        };
    }
}
