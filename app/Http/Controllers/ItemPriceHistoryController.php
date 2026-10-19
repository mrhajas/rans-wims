<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemPriceHistory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ItemPriceHistoryController extends Controller
{
    public function index(Request $request, Item $item)
    {
        $this->authorizePriceHistoryAccess();

        $item->load(['priceHistories.user:id,name']);

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $columns = ['Effective From', 'Effective To', 'Price', 'Remarks', 'Added By'];

            $rows = $item->priceHistories->map(fn ($row) => [
                optional($row->effective_from)->format('Y-m-d'),
                optional($row->effective_to)->format('Y-m-d') ?: '—',
                number_format((float) $row->price, 2),
                $row->remarks ?: '—',
                optional($row->user)->name ?: '—',
            ])->all();

            $data = [
                'title'       => 'Price History – ' . $item->name,
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => $columns,
                'rows'        => $rows,
                'meta'        => [
                    'Item'          => $item->name . ' (' . $item->code . ')',
                    'Current Price' => number_format((float) $item->price, 2),
                ],
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)
                    ->download('item-price-history-' . $item->code . '-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Item/PriceHistory', [
            'item' => $item->only(['id', 'name', 'code', 'price']),
            'priceHistories' => $item->priceHistories->map(fn ($row) => [
                'id' => $row->id,
                'price' => $row->price,
                'effective_from' => optional($row->effective_from)->format('Y-m-d'),
                'effective_to' => optional($row->effective_to)->format('Y-m-d'),
                'remarks' => $row->remarks,
                'added_by' => optional($row->user)->name,
                'created_at' => optional($row->created_at)->format('Y-m-d H:i'),
            ])->values(),
        ]);
    }

    public function store(Request $request, Item $item)
    {
        $this->authorizePriceHistoryAccess();

        $data = $request->validate([
            'price' => 'required|numeric|min:0',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'remarks' => 'nullable|string',
        ]);

        $item->priceHistories()->create($data);
        \App\Services\ItemNotifier::fire('price_history_changed', __('Price History Changed'), \App\Services\ItemNotifier::label($item) . ': price ' . number_format((float) $data['price'], 2) . ' added from ' . $data['effective_from'] . \App\Services\ItemNotifier::by() . '.', $item, ['skip_actor' => true]);

        return back()->with('message', __choice('action_text', ['record' => 'Price History', 'action' => 'created']));
    }

    public function applyPrice(Request $request, Item $item, ItemPriceHistory $price_history)
    {
        $this->authorizePriceHistoryAccess();

        abort_unless((int) $price_history->item_id === (int) $item->id, 404);

        $oldPrice = $item->price;
        $item->update(['price' => $price_history->price]);

        log_activity(
            'Item price changed from ' . number_format((float) $oldPrice, 2) . ' to ' . number_format((float) $price_history->price, 2) . ' (applied from price history dated ' . optional($price_history->effective_from)->format('Y-m-d') . ')',
            ['old_price' => $oldPrice, 'new_price' => $price_history->price, 'price_history_id' => $price_history->id],
            $item,
            'Item'
        );

        \App\Services\ItemNotifier::fire('price_applied', __('Item Price Applied'), \App\Services\ItemNotifier::label($item) . ': price changed from ' . number_format((float) $oldPrice, 2) . ' to ' . number_format((float) $price_history->price, 2) . \App\Services\ItemNotifier::by() . '.', $item, ['skip_actor' => true]);

        return back()->with('message', 'Item price updated to ' . number_format((float) $price_history->price, 2) . '.');
    }

    public function update(Request $request, Item $item, ItemPriceHistory $price_history)
    {
        $this->authorizePriceHistoryAccess();

        abort_unless((int) $price_history->item_id === (int) $item->id, 404);

        $data = $request->validate([
            'price' => 'required|numeric|min:0',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'remarks' => 'nullable|string',
        ]);

        $price_history->update($data);
        \App\Services\ItemNotifier::fire('price_history_changed', __('Price History Changed'), \App\Services\ItemNotifier::label($item) . ': a price history entry was edited to ' . number_format((float) $data['price'], 2) . \App\Services\ItemNotifier::by() . '.', $item, ['skip_actor' => true]);

        return back()->with('message', __choice('action_text', ['record' => 'Price History', 'action' => 'updated']));
    }

    public function destroy(Item $item, ItemPriceHistory $price_history)
    {
        $this->authorizePriceHistoryAccess();

        abort_unless((int) $price_history->item_id === (int) $item->id, 404);

        $price_history->delete();
        \App\Services\ItemNotifier::fire('price_history_changed', __('Price History Changed'), \App\Services\ItemNotifier::label($item) . ': a price history entry (' . number_format((float) $price_history->price, 2) . ') was removed' . \App\Services\ItemNotifier::by() . '.', $item, ['skip_actor' => true]);

        return back()->with('message', __choice('action_text', ['record' => 'Price History', 'action' => 'deleted']));
    }

    private function authorizePriceHistoryAccess(): void
    {
        abort_unless(
            auth()->user() && auth()->user()->hasRole([
                'Super Admin', 'Admin', 'Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger',
            ]),
            403,
            'Only Super Admin, Admin, Project Manager, or Procurement Manager can access price history.'
        );
    }
}
