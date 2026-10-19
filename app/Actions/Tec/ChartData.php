<?php

namespace App\Actions\Tec;

use Carbon\Carbon;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\Transfer;
use App\Models\Adjustment;
use App\Models\StockTrail;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ChartData
{
    public $month;

    public $year;

    private bool $isSiteEngineer = false;

    private ?array $warehouseScopeIds = null;

    public function __construct($month = null, $year = null)
    {
        $this->month = $month ?: date('n');
        $this->year = $year ?: date('Y');

        $user = auth()->user();
        $roleNames = $user ? $user->roles->pluck('name')->all() : [];
        $this->isSiteEngineer = ! empty(array_intersect(User::SITE_ENGINEER_ROLES, $roleNames));

        $scopeIds = warehouse_scope_ids();
        if (is_array($scopeIds)) {
            $scopeIds = array_values(array_map('intval', $scopeIds));
        }
        $this->warehouseScopeIds = $scopeIds;
    }

    public function month()
    {
        $this->month = $this->month > 9 ? $this->month : '0' . +$this->month;
        $end_date = Carbon::parse($this->year . '-' . $this->month . '-01')->endOfMonth();
        $start_date = Carbon::parse($this->year . '-' . $this->month . '-01')->startOfMonth();

        if (env('DB_CONNECTION') == 'sqlite') {
            $monthlyCheckins = Checkin::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckins);
            $monthlyCheckins = $monthlyCheckins
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();

            $monthlyCheckouts = Checkout::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckouts);
            $monthlyCheckouts = $monthlyCheckouts
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();

            $monthlyAdjustments = Adjustment::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyAdjustments);
            $monthlyAdjustments = $monthlyAdjustments
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();

            $monthlyTransfers = Transfer::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyTransferScopeToOrderQuery($monthlyTransfers);
            $monthlyTransfers = $monthlyTransfers
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();
        } else {
            $monthlyCheckins = Checkin::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckins);
            $monthlyCheckins = $monthlyCheckins
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();

            $monthlyCheckouts = Checkout::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckouts);
            $monthlyCheckouts = $monthlyCheckouts
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();

            $monthlyAdjustments = Adjustment::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyAdjustments);
            $monthlyAdjustments = $monthlyAdjustments
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();

            $monthlyTransfers = Transfer::active()->selectRaw(
                DB::Raw('date, COUNT(*) as total')
            );
            $this->applyTransferScopeToOrderQuery($monthlyTransfers);
            $monthlyTransfers = $monthlyTransfers
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy('date')->orderBy('date')->get();
        }

        return [
            'adjustment' => $this->prepareMonthArray($monthlyAdjustments, $start_date),
            'transfer'   => $this->prepareMonthArray($monthlyTransfers, $start_date),
            'checkin'    => $this->prepareMonthArray($monthlyCheckins, $start_date),
            'checkout'   => $this->prepareMonthArray($monthlyCheckouts, $start_date),
        ];
    }

    public function topProducts()
    {
        $this->month = $this->month > 9 ? $this->month : '0' . +$this->month;
        $end_date = Carbon::parse($this->year . '-' . $this->month . '-01')->endOfMonth();
        $start_date = Carbon::parse($this->year . '-' . $this->month . '-01')->startOfMonth();

        $topProducts = StockTrail::select(['item_id'])->selectRaw('SUM(quantity) as y')
            ->whereBetween('created_at', [$start_date, $end_date])
            ->when(is_array($this->warehouseScopeIds), function ($query) {
                if (empty($this->warehouseScopeIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereIn('warehouse_id', $this->warehouseScopeIds);
            })
            ->with('item:id,code,name,unit_id', 'item.unit:id,code')->orderByRaw('SUM(quantity) desc')->groupBy('item_id')
            ->take(10)->get()->transform(fn ($i) => [
                'name' => $i->item ? $i->item->code : '',
                'item_name' => optional($i->item)->name ?: '',
                'unit' => optional(optional($i->item)->unit)->code ?: '',
                'y' => +$i->y,
                'item_id' => $i->item_id,
            ]);

        if ($topProducts->isNotEmpty()) {
            return $this->withStockOnHand($topProducts);
        }

        return $this->fallbackTopProducts($start_date, $end_date);
    }

    private function withStockOnHand($rows)
    {
        $itemIds = $rows->pluck('item_id')->filter()->all();
        $stockByItem = empty($itemIds) ? [] : \App\Models\Stock::main()
            ->whereIn('item_id', $itemIds)
            ->when(is_array($this->warehouseScopeIds), function ($query) {
                if (empty($this->warehouseScopeIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereIn('warehouse_id', $this->warehouseScopeIds);
            })
            ->selectRaw('item_id, SUM(quantity) as qty')
            ->groupBy('item_id')
            ->pluck('qty', 'item_id')
            ->all();

        return $rows->map(function ($row) use ($stockByItem) {
            $row['stock_on_hand'] = (float) ($stockByItem[$row['item_id']] ?? 0);

            return $row;
        });
    }

    public function year()
    {
        $end_date = Carbon::parse($this->year . '-01-01')->endOfYear();
        $start_date = Carbon::parse($this->year . '-01-01')->startOfYear();

        if (env('DB_CONNECTION') == 'sqlite') {
            $monthlyCheckins = Checkin::active()->selectRaw(
                DB::Raw("strftime('%m', date) as month, strftime('%Y', date) as year, COUNT(*) as total")
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckins);
            $monthlyCheckins = $monthlyCheckins
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw("strftime('%Y', date), strftime('%m', date)"))
                ->orderBy(DB::raw("strftime('%Y-%m', date)"))->get();

            $monthlyCheckouts = Checkout::active()->selectRaw(
                DB::Raw("strftime('%m', date) as month, strftime('%Y', date) as year, COUNT(*) as total")
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckouts);
            $monthlyCheckouts = $monthlyCheckouts
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw("strftime('%Y', date), strftime('%m', date)"))
                ->orderBy(DB::raw("strftime('%Y-%m', date)"))->get();

            $monthlyAdjustments = Adjustment::active()->selectRaw(
                DB::Raw("strftime('%m', date) as month, strftime('%Y', date) as year, COUNT(*) as total")
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyAdjustments);
            $monthlyAdjustments = $monthlyAdjustments
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw("strftime('%Y', date), strftime('%m', date)"))
                ->orderBy(DB::raw("strftime('%Y-%m', date)"))->get();

            $monthlyTransfers = Transfer::active()->selectRaw(
                DB::Raw("strftime('%m', date) as month, strftime('%Y', date) as year, COUNT(*) as total")
            );
            $this->applyTransferScopeToOrderQuery($monthlyTransfers);
            $monthlyTransfers = $monthlyTransfers
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw("strftime('%Y', date), strftime('%m', date)"))
                ->orderBy(DB::raw("strftime('%Y-%m', date)"))->get();
        } else {
            $monthlyCheckins = Checkin::active()->selectRaw(
                DB::Raw('MONTH(date) as month, YEAR(date) as year, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckins);
            $monthlyCheckins = $monthlyCheckins
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw('YEAR(date), MONTH(date)'))
                ->orderBy('year')->orderBy('month')->get();

            $monthlyCheckouts = Checkout::active()->selectRaw(
                DB::Raw('MONTH(date) as month, YEAR(date) as year, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyCheckouts);
            $monthlyCheckouts = $monthlyCheckouts
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw('YEAR(date), MONTH(date)'))
                ->orderBy('year')->orderBy('month')->get();

            $monthlyAdjustments = Adjustment::active()->selectRaw(
                DB::Raw('MONTH(date) as month, YEAR(date) as year, COUNT(*) as total')
            );
            $this->applyWarehouseScopeToOrderQuery($monthlyAdjustments);
            $monthlyAdjustments = $monthlyAdjustments
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw('YEAR(date), MONTH(date)'))
                ->orderBy('year')->orderBy('month')->get();

            $monthlyTransfers = Transfer::active()->selectRaw(
                DB::Raw('MONTH(date) as month, YEAR(date) as year, COUNT(*) as total')
            );
            $this->applyTransferScopeToOrderQuery($monthlyTransfers);
            $monthlyTransfers = $monthlyTransfers
                ->whereBetween('date', [$start_date, $end_date])
                ->groupBy(DB::raw('YEAR(date), MONTH(date)'))
                ->orderBy('year')->orderBy('month')->get();
        }

        return [
            'adjustment' => $this->prepareYearArray($monthlyAdjustments, $start_date),
            'transfer'   => $this->prepareYearArray($monthlyTransfers, $start_date),
            'checkin'    => $this->prepareYearArray($monthlyCheckins, $start_date),
            'checkout'   => $this->prepareYearArray($monthlyCheckouts, $start_date),
        ];
    }

    private function prepareMonthArray($array, $date)
    {
        $days = $date->daysInMonth;
        $data = $array->mapWithKeys(function ($item) {
            return [$item['date'] => $item['total']];
        })->toArray();
        for ($i = 1; $i <= $days; $i++) {
            $key = Carbon::parse($date->format('Y-m-') . ($i < 10 ? '0' . ((int) $i) : $i))->format('Y-m-d');
            $data[$key] = Arr::has($data, $key) ? (0 + $data[$key]) : 0;
        }

        return $data;
    }

    private function prepareWeekArray($array)
    {
        $data = $array->mapWithKeys(function ($item) {
            return [$item['date'] => $item['total']];
        })->toArray();
        for ($i = 6; $i >= 0; $i--) {
            if ($i == 0) {
                $date = now();
            } elseif ($i == 1) {
                $date = now()->subDay();
            } else {
                $date = now()->subDays($i);
            }
            $key = $date->format('Y-m-d');
            $data[$key] = Arr::has($data, $key) ? (0 + $data[$key]) : 0;
        }

        return $data;
    }

    private function prepareYearArray($array, $date)
    {
        $data = $array->mapWithKeys(function ($item) {
            return [$item['year'] . ($item['month'] < 10 ? '-0' : '-') . ((int) $item['month']) => $item['total']];
        })->toArray();
        for ($i = 1; $i <= 12; $i++) {
            $key = Carbon::parse($date->format('Y-') . ($i < 10 ? '0' . $i : $i))->format('Y-m');
            $data[$key] = Arr::has($data, $key) ? (0 + $data[$key]) : 0;
        }

        return $data;
    }

    private function fallbackTopProducts(Carbon $start_date, Carbon $end_date)
    {
        $accountId = getAccountId(1);

        $checkinItems = DB::table('checkin_items as li')
            ->join('checkins as o', 'o.id', '=', 'li.checkin_id')
            ->where('li.account_id', $accountId)
            ->where('o.account_id', $accountId)
            ->whereNull('li.deleted_at')
            ->whereNull('o.deleted_at')
            ->when(is_array($this->warehouseScopeIds), function ($query) {
                if (empty($this->warehouseScopeIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereIn('o.warehouse_id', $this->warehouseScopeIds);
            })
            ->whereBetween('o.date', [$start_date, $end_date])
            ->where(function ($query) {
                $query->whereNull('o.draft')->orWhere('o.draft', 0);
            })
            ->selectRaw('li.item_id, SUM(li.quantity) as qty')
            ->groupBy('li.item_id');

        $checkoutItems = DB::table('checkout_items as li')
            ->join('checkouts as o', 'o.id', '=', 'li.checkout_id')
            ->where('li.account_id', $accountId)
            ->where('o.account_id', $accountId)
            ->whereNull('li.deleted_at')
            ->whereNull('o.deleted_at')
            ->when(is_array($this->warehouseScopeIds), function ($query) {
                if (empty($this->warehouseScopeIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereIn('o.warehouse_id', $this->warehouseScopeIds);
            })
            ->whereBetween('o.date', [$start_date, $end_date])
            ->where(function ($query) {
                $query->whereNull('o.draft')->orWhere('o.draft', 0);
            })
            ->selectRaw('li.item_id, SUM(li.quantity) as qty')
            ->groupBy('li.item_id');

        $transferItems = DB::table('transfer_items as li')
            ->join('transfers as o', 'o.id', '=', 'li.transfer_id')
            ->where('li.account_id', $accountId)
            ->where('o.account_id', $accountId)
            ->whereNull('li.deleted_at')
            ->whereNull('o.deleted_at')
            ->when(is_array($this->warehouseScopeIds), function ($query) {
                if (empty($this->warehouseScopeIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->where(function ($w) {
                    $w->whereIn('o.from_warehouse_id', $this->warehouseScopeIds)
                        ->orWhereIn('o.to_warehouse_id', $this->warehouseScopeIds);
                });
            })
            ->whereBetween('o.date', [$start_date, $end_date])
            ->where(function ($query) {
                $query->whereNull('o.draft')->orWhere('o.draft', 0);
            })
            ->selectRaw('li.item_id, SUM(li.quantity) as qty')
            ->groupBy('li.item_id');

        $adjustmentItems = DB::table('adjustment_items as li')
            ->join('adjustments as o', 'o.id', '=', 'li.adjustment_id')
            ->where('li.account_id', $accountId)
            ->where('o.account_id', $accountId)
            ->whereNull('li.deleted_at')
            ->whereNull('o.deleted_at')
            ->when(is_array($this->warehouseScopeIds), function ($query) {
                if (empty($this->warehouseScopeIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereIn('o.warehouse_id', $this->warehouseScopeIds);
            })
            ->whereBetween('o.date', [$start_date, $end_date])
            ->where(function ($query) {
                $query->whereNull('o.draft')->orWhere('o.draft', 0);
            })
            ->selectRaw('li.item_id, SUM(li.quantity) as qty')
            ->groupBy('li.item_id');

        $movementRows = DB::query()->fromSub(
            $checkinItems->unionAll($checkoutItems)->unionAll($transferItems)->unionAll($adjustmentItems),
            'moves'
        )
            ->leftJoin('items as i', 'i.id', '=', 'moves.item_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->where('i.account_id', $accountId)
            ->whereNull('i.deleted_at')
            ->selectRaw('moves.item_id, i.code as code, i.name as item_name, u.code as unit, SUM(moves.qty) as y')
            ->groupBy('moves.item_id', 'i.code', 'i.name', 'u.code')
            ->orderByDesc('y')
            ->take(10)
            ->get();

        $rows = $movementRows->transform(fn ($row) => [
            'name' => $row->code ?: '',
            'item_name' => $row->item_name ?: '',
            'unit' => $row->unit ?: '',
            'y' => (float) $row->y,
            'item_id' => $row->item_id,
        ]);

        return $this->withStockOnHand($rows);
    }

    private function applyWarehouseScopeToOrderQuery($query, string $column = 'warehouse_id')
    {
        if ($this->isSiteEngineer) {
            $query->withoutGlobalScope('mine');
        }

        if (! is_array($this->warehouseScopeIds)) {
            return $query;
        }

        if (empty($this->warehouseScopeIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $this->warehouseScopeIds);
    }

    private function applyTransferScopeToOrderQuery($query)
    {
        if ($this->isSiteEngineer) {
            $query->withoutGlobalScope('mine');
        }

        if (! is_array($this->warehouseScopeIds)) {
            return $query;
        }

        if (empty($this->warehouseScopeIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($w) {
            $w->whereIn('from_warehouse_id', $this->warehouseScopeIds)
                ->orWhereIn('to_warehouse_id', $this->warehouseScopeIds);
        });
    }
}
