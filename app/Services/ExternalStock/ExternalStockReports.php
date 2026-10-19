<?php

namespace App\Services\ExternalStock;

use App\Models\Contact;
use App\Models\ExternalStockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/** External Stock reports in the WIMS report shape (title, filters, tiles, columns, rows, notes): balances, movement register, withdrawals with their paperwork, person statement. */
class ExternalStockReports
{
    public const LIMIT = 5000;

    public static function catalog(): array
    {
        return [
            ['key' => 'balances', 'title' => __('Stock held by External Personnel'), 'about' => __('What each person holds, by warehouse and item: in, out, balance and what is promised in waiting Stock Outs.'), 'filters' => ['contact_id', 'warehouse_id', 'item', 'as_of', 'only_stock']],
            ['key' => 'register', 'title' => __('Movement register'), 'about' => __('Every Stock In, Stock Out and opening stock in the period with its status.'), 'filters' => ['contact_id', 'warehouse_id', 'kind', 'status', 'from', 'to']],
            ['key' => 'withdrawals', 'title' => __('Stock Out with collector, driver and vehicle'), 'about' => __('Everything recorded when stock was taken: who collected it, the mobile, the driver and the vehicle, with the Gate Pass and Delivery Note.'), 'filters' => ['contact_id', 'warehouse_id', 'status', 'from', 'to']],
            ['key' => 'statement', 'title' => __('Statement of one person'), 'about' => __('All approved movements of one person with the running balance of each item. Choose the person.'), 'filters' => ['contact_id', 'warehouse_id', 'from', 'to']],
        ];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, array_column(self::catalog(), 'key'), true);
    }

    public static function build($user, string $type, array $f, int $limit = self::LIMIT): array
    {
        $f = self::normalize($f);
        $ids = accessible_warehouse_ids();
        $seeAll = $user->hasRole('Super Admin') || $user->view_all || $user->can('approve-external-stock');
        $f['warehouse_ids'] = $seeAll ? null : ($ids ?: []);
        [$cols, $rows, $title, $about, $totals] = match ($type) {
            'register' => self::register($f, $user, $seeAll),
            'withdrawals' => self::withdrawals($f, $user, $seeAll),
            'statement' => self::statement($f),
            default => self::balances($f),
        };
        $total = count($rows);
        $kept = array_slice($rows, 0, $limit);
        $display = array_map(function ($r) use ($cols) {
            $o = [];
            foreach ($cols as $c) {
                $v = $r[$c['key']] ?? '';
                $o[$c['key']] = ($c['type'] ?? '') === 'qty' && $v !== '' && $v !== null ? ExternalStockLedger::fmt((float) $v) : $v;
            }

            return $o;
        }, $kept);
        $cols = array_map(fn ($c) => $c + ['align' => ($c['type'] ?? '') === 'qty' ? 'right' : 'left'], $cols);
        $tiles = [['label' => __('Rows'), 'value' => $total]] + $totals;

        return ['signing' => ['type' => 'external_stock_report'], 'type' => $type, 'title' => $title, 'reference' => 'EXR-' . now()->format('Ymd-His'), 'subtitle' => $about, 'table_title' => $title, 'filters' => self::filterText($f), 'tiles' => array_values($tiles), 'columns' => $cols, 'rows' => $display, 'raw' => $kept,
            'total_rows' => $total, 'truncated' => $total > $limit, 'sections' => [], 'notes' => [__('This stock belongs to External Personnel and is only kept in our warehouses: it is not part of the company stock.'), __('Only approved movements change a balance. Waiting Stock Outs are shown as promised.'), __('Figures are drawn from the records at the time of generation.')]];
    }

    private static function normalize(array $f): array
    {
        $date = fn ($v) => ! empty($v) && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;

        return ['contact_id' => ctype_digit((string) ($f['contact_id'] ?? '')) && ($f['contact_id'] ?? '') !== '' ? (int) $f['contact_id'] : null, 'warehouse_id' => ctype_digit((string) ($f['warehouse_id'] ?? '')) && ($f['warehouse_id'] ?? '') !== '' ? (int) $f['warehouse_id'] : null,
            'item' => mb_substr(trim((string) ($f['item'] ?? '')), 0, 100), 'kind' => array_key_exists($f['kind'] ?? '', ExternalStockMovement::KINDS) ? $f['kind'] : null, 'status' => in_array($f['status'] ?? '', ExternalStockMovement::STATUSES, true) ? $f['status'] : null,
            'from' => $date($f['from'] ?? null), 'to' => $date($f['to'] ?? null), 'as_of' => $date($f['as_of'] ?? null), 'only_stock' => ! empty($f['only_stock']) && $f['only_stock'] !== '0'];
    }

    private static function filterText(array $f): array
    {
        $o = [];
        if ($f['contact_id']) {
            $o[__('Person')] = optional(Contact::withTrashed()->find($f['contact_id']))->name ?? '#' . $f['contact_id'];
        }
        if ($f['warehouse_id']) {
            $o[__('Warehouse')] = optional(Warehouse::withoutGlobalScopes()->find($f['warehouse_id']))->name ?? '#' . $f['warehouse_id'];
        }
        foreach (['item' => __('Item'), 'kind' => __('Kind'), 'status' => __('Status'), 'from' => __('From'), 'to' => __('To'), 'as_of' => __('As of')] as $k => $l) {
            if (! empty($f[$k])) {
                $o[$l] = $k === 'kind' ? __(ExternalStockMovement::KINDS[$f[$k]]) : $f[$k];
            }
        }
        if ($f['only_stock']) {
            $o[__('Show')] = __('Only stock still held');
        }

        return $o ?: [__('Filters') => __('None')];
    }

    private static function balances(array $f): array
    {
        $rows = ExternalStockLedger::balances(['contact_id' => $f['contact_id'], 'warehouse_id' => $f['warehouse_id'], 'item_search' => $f['item'], 'as_of' => $f['as_of'], 'only_stock' => $f['only_stock'], 'warehouse_ids' => $f['warehouse_ids']])
            ->map(fn ($r) => ['person' => $r->contact, 'warehouse' => $r->warehouse, 'code' => $r->code, 'item' => $r->item, 'in' => $r->in, 'out' => $r->out, 'balance' => $r->balance, 'reserved' => $r->reserved, 'available' => $r->available, 'last' => $r->last_date])->all();
        $cols = [['key' => 'person', 'label' => __('Person')], ['key' => 'warehouse', 'label' => __('Warehouse')], ['key' => 'code', 'label' => __('Code')], ['key' => 'item', 'label' => __('Item')], ['key' => 'in', 'label' => __('Brought'), 'type' => 'qty'], ['key' => 'out', 'label' => __('Taken'), 'type' => 'qty'],
            ['key' => 'balance', 'label' => __('Balance'), 'type' => 'qty'], ['key' => 'reserved', 'label' => __('Promised'), 'type' => 'qty'], ['key' => 'available', 'label' => __('Available'), 'type' => 'qty'], ['key' => 'last', 'label' => __('Last movement')]];
        $people = collect($rows)->pluck('person')->unique()->count();

        return [$cols, $rows, __('Stock held by External Personnel'), __('What each person holds, by warehouse and item.'), [['label' => __('People'), 'value' => $people], ['label' => __('Items'), 'value' => collect($rows)->pluck('item')->unique()->count()]]];
    }

    private static function movementQuery(array $f, $user, bool $seeAll)
    {
        return ExternalStockMovement::query()->visibleTo($user)->with(['contact:id,name', 'warehouse:id,name', 'items.item:id,name,code', 'items.unit:id,name'])
            ->when($f['contact_id'], fn ($q, $v) => $q->where('contact_id', $v))->when($f['warehouse_id'], fn ($q, $v) => $q->where('warehouse_id', $v))->when($f['kind'], fn ($q, $v) => $q->where('kind', $v))->when($f['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'], fn ($q, $v) => $q->whereDate('date', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('date', '<=', $v));
    }

    private static function register(array $f, $user, bool $seeAll): array
    {
        $ms = self::movementQuery($f, $user, $seeAll)->orderBy('date')->orderBy('id')->limit(self::LIMIT + 1)->get();
        $rows = $ms->map(fn ($m) => ['reference' => $m->reference, 'date' => $m->date->toDateString(), 'kind' => __($m->kindLabel()), 'status' => __($m->status), 'person' => optional($m->contact)->name, 'warehouse' => optional($m->warehouse)->name,
            'items' => $m->items->map(fn ($l) => optional($l->item)->name . ' x ' . ExternalStockLedger::fmt((float) $l->quantity))->implode('; '), 'qty' => $m->items->sum('quantity'), 'collector' => $m->collector_name, 'mobile' => $m->collector_phone])->all();
        $cols = [['key' => 'reference', 'label' => __('Reference')], ['key' => 'date', 'label' => __('Date')], ['key' => 'kind', 'label' => __('Kind')], ['key' => 'status', 'label' => __('Status')], ['key' => 'person', 'label' => __('Person')], ['key' => 'warehouse', 'label' => __('Warehouse')],
            ['key' => 'items', 'label' => __('Items')], ['key' => 'qty', 'label' => __('Total quantity'), 'type' => 'qty'], ['key' => 'collector', 'label' => __('Collected / brought by')], ['key' => 'mobile', 'label' => __('Mobile')]];

        return [$cols, $rows, __('Movement register'), __('Every movement in the period.'), [['label' => __('Stock Out'), 'value' => $ms->where('kind', 'withdrawal')->count()], ['label' => __('Stock In'), 'value' => $ms->where('kind', 'deposit')->count()], ['label' => __('Waiting'), 'value' => $ms->where('status', ExternalStockMovement::PENDING)->count()]]];
    }

    private static function withdrawals(array $f, $user, bool $seeAll): array
    {
        $f['kind'] = 'withdrawal';
        $ms = self::movementQuery($f, $user, $seeAll)->orderBy('date')->orderBy('id')->limit(self::LIMIT + 1)->get();
        $passes = \App\Models\GatePass::query()->where('source_type', 'external_stock')->whereIn('source_id', $ms->pluck('id'))->pluck('reference', 'source_id');
        $notes = \App\Models\DeliveryNote::query()->where('source_type', 'external_stock')->whereIn('source_id', $ms->pluck('id'))->pluck('reference', 'source_id');
        $trips = \App\Models\TransportRecord::query()->whereIn('external_movement_id', $ms->pluck('id'))->where('payment_status', '!=', 'Cancelled')->pluck('trip_number', 'external_movement_id');
        $driver = fn ($m) => $m->driver_contact_id ? optional(Contact::withTrashed()->find($m->driver_contact_id))->name : $m->driver_name;
        $plate = fn ($m) => $m->asset_id ? optional(\App\Models\Asset::withTrashed()->find($m->asset_id))->registration_number : $m->vehicle_plate;
        $rows = [];
        foreach ($ms as $m) {
            foreach ($m->items as $l) {
                $rows[] = ['date' => $m->date->toDateString(), 'reference' => $m->reference, 'status' => __($m->status), 'person' => optional($m->contact)->name, 'warehouse' => optional($m->warehouse)->name, 'item' => optional($l->item)->name, 'qty' => (float) $l->quantity, 'unit' => optional($l->unit)->name,
                    'collector' => $m->collector_name, 'mobile' => $m->collector_phone, 'driver' => $driver($m), 'vehicle' => $plate($m), 'gate' => $passes[$m->id] ?? '', 'note' => $notes[$m->id] ?? '', 'trip' => $trips[$m->id] ?? ''];
            }
        }
        $cols = [['key' => 'date', 'label' => __('Date')], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'status', 'label' => __('Status')], ['key' => 'person', 'label' => __('Person')], ['key' => 'warehouse', 'label' => __('Warehouse')], ['key' => 'item', 'label' => __('Item')], ['key' => 'qty', 'label' => __('Quantity'), 'type' => 'qty'], ['key' => 'unit', 'label' => __('Unit')],
            ['key' => 'collector', 'label' => __('Collected by')], ['key' => 'mobile', 'label' => __('Mobile')], ['key' => 'driver', 'label' => __('Driver')], ['key' => 'vehicle', 'label' => __('Vehicle')], ['key' => 'gate', 'label' => __('Gate Pass')], ['key' => 'note', 'label' => __('Delivery Note')], ['key' => 'trip', 'label' => __('Transport Route')]];

        return [$cols, $rows, __('Stock Out with collector, driver and vehicle'), __('Everything recorded when stock was taken.'), [['label' => __('Stock Out'), 'value' => $ms->count()], ['label' => __('Lines'), 'value' => count($rows)]]];
    }

    private static function statement(array $f): array
    {
        if (! $f['contact_id']) {
            return [[['key' => 'date', 'label' => __('Date')]], [], __('Statement of one person'), __('Choose the person.'), []];
        }
        $q = DB::table('external_stock_items as i')->join('external_stock_movements as m', 'm.id', '=', 'i.movement_id')->join('items as it', 'it.id', '=', 'i.item_id')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->whereNull('i.deleted_at')->whereNull('m.deleted_at')->where('m.status', ExternalStockMovement::APPROVED)->where('m.contact_id', $f['contact_id'])
            ->when($f['warehouse_id'], fn ($x, $v) => $x->where('m.warehouse_id', $v))->when($f['warehouse_ids'] !== null, fn ($x) => $x->whereIn('m.warehouse_id', $f['warehouse_ids'] ?: [0]))
            ->orderBy('m.date')->orderBy('m.id')->get(['m.date', 'm.reference', 'm.kind', 'w.name as warehouse', 'it.name as item', 'i.item_id', 'm.warehouse_id', 'i.quantity']);
        $run = [];
        $rows = [];
        foreach ($q as $r) {
            $k = $r->warehouse_id . '|' . $r->item_id;
            $in = $r->kind === 'withdrawal' ? 0.0 : (float) $r->quantity;
            $out = $r->kind === 'withdrawal' ? (float) $r->quantity : 0.0;
            $run[$k] = ($run[$k] ?? 0) + $in - $out;
            if (($f['from'] && $r->date < $f['from']) || ($f['to'] && $r->date > $f['to'])) {
                continue;
            }
            $rows[] = ['date' => $r->date, 'reference' => $r->reference, 'kind' => __(ExternalStockMovement::KINDS[$r->kind] ?? $r->kind), 'warehouse' => $r->warehouse, 'item' => $r->item, 'in' => $in ?: '', 'out' => $out ?: '', 'balance' => round($run[$k], 4)];
        }
        $cols = [['key' => 'date', 'label' => __('Date')], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'kind', 'label' => __('Kind')], ['key' => 'warehouse', 'label' => __('Warehouse')], ['key' => 'item', 'label' => __('Item')], ['key' => 'in', 'label' => __('Brought'), 'type' => 'qty'], ['key' => 'out', 'label' => __('Taken'), 'type' => 'qty'], ['key' => 'balance', 'label' => __('Balance'), 'type' => 'qty']];

        return [$cols, $rows, __('Statement of one person'), __('Approved movements with the running balance of each item.'), []];
    }
}
