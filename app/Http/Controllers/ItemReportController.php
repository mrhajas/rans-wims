<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * The Item report: one sheet per item (details, barcode, QR code, stock in every warehouse). Print (opens the browser's print dialog) and PDF download share
 * one view. Both routes are named so the Download / Print log records every use, and both need the right to view items.
 */
class ItemReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function print(Request $request, int $item)
    {
        return view('items.report', $this->data($request, $item, false));
    }

    public function pdf(Request $request, int $item)
    {
        $data = $this->data($request, $item, true);

        return Pdf::loadView('items.report', $data)->setPaper('a4')->download('item-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $data['item']->code) . '-' . now()->format('Ymd-His') . '.pdf');
    }

    /** ?embed=1: the page is loaded in a hidden frame and printed by the page that asked for it, so it must not print itself. */
    private function data(Request $request, int $id, bool $isPdf): array
    {
        abort_unless($request->user() && $request->user()->can('read-items'), 403);
        $item = Item::with(['categories:id,code,name', 'unit', 'allStock.warehouse:id,code,name'])->findOrFail($id);
        $stock = $item->allStock->groupBy('warehouse_id')->map(fn ($rows) => [
            'warehouse' => optional($rows->first()->warehouse)->name ?: __('Unknown Warehouse'),
            'code' => optional($rows->first()->warehouse)->code,
            'quantity' => (float) $rows->sum('quantity'),
            'weight' => (float) $rows->sum('weight'),
        ])->filter(fn ($w) => $w['quantity'] > 0 || ($item->track_weight && $w['weight'] > 0))->sortBy('warehouse')->values();
        $price = (float) ($item->price ?? 0);
        $qty = (float) $stock->sum('quantity');

        return [
            'isPdf' => $isPdf, 'autoPrint' => ! $isPdf && ! $request->boolean('embed'), 'companyName' => optional($request->user()->account ?? null)->name,
            'item' => $item, 'stock' => $stock, 'price' => $price, 'qty' => $qty, 'value' => $qty * $price,
            'printedBy' => $request->user()->name, 'printedAt' => now()->format('d M Y H:i'),
        ];
    }
}
