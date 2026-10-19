<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\Transfer;
use App\Models\Contact;
use App\Models\Category;
use App\Models\Adjustment;
use App\Models\MaterialRequest;
use App\Models\DailySiteReport;

class HoverPreviewController extends Controller
{
    // Named `preview`, not `show` — the base Controller's Authorizable trait
    // auto-derives a permission name from CRUD-style method names (index/show/
    // edit/update/create/store/destroy/...) combined with the route name, and
    // silently requires it via $this->authorize(). A method named `show` here
    // would auto-check a nonexistent "read-hover-preview" permission that only
    // Super Admin (who bypasses all authorization) could ever pass. This
    // controller already does its own explicit per-category permission check
    // below, so it deliberately avoids the reserved method names.
    public function preview(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|string|max:50',
            'id' => 'required|integer',
        ]);

        $registry = $this->registry();
        if (! isset($registry[$data['category']])) {
            abort(404);
        }

        $entry = $registry[$data['category']];
        abort_unless($request->user() && collect((array) $entry['permission'])->contains(fn ($permission) => $request->user()->can($permission)), 403);

        $model = $entry['find']($data['id']);
        abort_unless($model, 404);

        $response = [
            'icon' => $entry['icon'],
            'title' => $entry['title']($model),
            'fields' => array_values(array_filter($entry['fields']($model), fn ($f) => filled($f[1] ?? null))),
        ];

        if (isset($entry['lines'])) {
            $lines = $entry['lines']($model);
            $response['lines'] = array_slice($lines, 0, 6);
            $response['linesTotal'] = count($lines);
        }

        return response()->json($response);
    }

    /**
     * Transfer has 3 real states, not 2 — unlike Checkin/Checkout/Adjustment
     * (draft vs. everything-else-is-"Confirmed"), a non-draft Transfer is
     * "Approved" (released, including transfers that never needed approval)
     * until received_at. Same precedence as ProjectMaterialLedgerController's
     * transfer status CASE and the transfers.print blade.
     */
    private function transferStatus(Transfer $m): string
    {
        return match (true) {
            (bool) $m->draft => 'Draft',
            (bool) $m->received_at => 'Received',
            default => 'Approved',
        };
    }

    private function lineItems($model): array
    {
        return $model->items->map(fn ($line) => [
            'name' => optional($line->item)->name ?: '—',
            'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ''), '0'), '.'),
            'unit' => optional($line->unit)->name,
        ])->all();
    }

    private function registry(): array
    {
        return [
            'checkins' => [
                'permission' => 'read-checkins',
                'icon' => 'in',
                'find' => fn ($id) => Checkin::withoutGlobalScope('mine')->with(['warehouse', 'contact', 'items.item', 'items.unit'])->withCount('items')->find($id),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'fields' => fn ($m) => [
                    ['Date', optional($m->date)->format('Y-m-d')],
                    ['Warehouse', optional($m->warehouse)->name],
                    ['Contact', optional($m->contact)->name],
                    ['Items', $m->items_count],
                    ['Status', $m->draft ? 'Draft' : 'Confirmed'],
                ],
                'lines' => fn ($m) => $this->lineItems($m),
            ],
            'checkouts' => [
                'permission' => 'read-checkouts',
                'icon' => 'out',
                'find' => fn ($id) => Checkout::withoutGlobalScope('mine')->with(['warehouse', 'contact', 'items.item', 'items.unit'])->withCount('items')->find($id),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'fields' => fn ($m) => [
                    ['Date', optional($m->date)->format('Y-m-d')],
                    ['Warehouse', optional($m->warehouse)->name],
                    ['Contact', optional($m->contact)->name],
                    ['Items', $m->items_count],
                    ['Status', $m->draft ? 'Draft' : 'Confirmed'],
                ],
                'lines' => fn ($m) => $this->lineItems($m),
            ],
            'transfers' => [
                'permission' => 'read-transfers',
                'icon' => 'refresh',
                'find' => fn ($id) => Transfer::withoutGlobalScope('mine')->with(['fromWarehouse', 'toWarehouse', 'items.item', 'items.unit'])->withCount('items')->find($id),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'fields' => fn ($m) => [
                    ['Date', optional($m->date)->format('Y-m-d')],
                    ['From', optional($m->fromWarehouse)->name],
                    ['To', optional($m->toWarehouse)->name],
                    ['Items', $m->items_count],
                    ['Status', $this->transferStatus($m)],
                ],
                'lines' => fn ($m) => $this->lineItems($m),
            ],
            'adjustments' => [
                'permission' => 'read-adjustments',
                'icon' => 'adjustments',
                'find' => fn ($id) => Adjustment::withoutGlobalScope('mine')->with(['warehouse'])->withCount('items')->find($id),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'fields' => fn ($m) => [
                    ['Date', optional($m->date)->format('Y-m-d')],
                    ['Warehouse', optional($m->warehouse)->name],
                    ['Type', $m->type ? ucfirst($m->type) : null],
                    ['Items', $m->items_count],
                    ['Status', $m->draft ? 'Draft' : 'Confirmed'],
                ],
            ],
            'contacts' => [
                'permission' => 'read-contacts',
                'icon' => 'users',
                'find' => fn ($id) => Contact::find($id),
                'title' => fn ($m) => $m->name,
                'fields' => fn ($m) => [
                    ['Category', $m->category],
                    ['Email', $m->email],
                    ['Phone', $m->phone],
                ],
            ],
            'material-requests' => [
                'permission' => ['read-material-requests', 'view-all-material-requests'],
                'icon' => 'clip',
                // Same visibility as the list/show (a user who cannot open the request must not preview it either).
                'find' => fn ($id) => \App\Support\MaterialRequestVisibility::scopeQueryFor(
                    MaterialRequest::with(['warehouse', 'user'])->withCount('items'),
                    auth()->user()
                )->find($id),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'fields' => fn ($m) => [
                    ['Date Requested', optional($m->date_requested)->format('Y-m-d')],
                    ['Warehouse', optional($m->warehouse)->name],
                    ['Requested By', optional($m->user)->name],
                    ['Status', $m->status],
                    ['Items', $m->items_count],
                ],
            ],
            'items' => [
                'permission' => 'read-items',
                'icon' => 'bag',
                'find' => fn ($id) => Item::with(['categories', 'unit'])->find($id),
                'title' => fn ($m) => $m->name,
                'fields' => fn ($m) => [
                    ['Code', $m->code],
                    ['Category', $m->categories->pluck('name')->implode(', ') ?: null],
                    ['Unit', optional($m->unit)->name],
                    ['Price', $m->price !== null ? number_format((float) $m->price, 2) : null],
                    ['Stock on Hand', $m->stock()->sum('quantity')],
                ],
            ],
            'categories' => [
                'permission' => 'read-categories',
                'icon' => 'folder',
                'find' => fn ($id) => Category::with(['parent'])->withCount('items')->find($id),
                'title' => fn ($m) => $m->name,
                'fields' => fn ($m) => [
                    ['Code', $m->code],
                    ['Items', $m->items_count],
                    ['Parent', optional($m->parent)->name],
                ],
            ],
            'daily-site-reports' => [
                'permission' => 'read-daily-site-reports',
                'icon' => 'doc-text',
                'find' => fn ($id) => DailySiteReport::with(['warehouse', 'submittedBy'])->find($id),
                'title' => fn ($m) => $m->report_number ?: ('#' . $m->id),
                'fields' => fn ($m) => [
                    ['Date', optional($m->report_date)->format('Y-m-d')],
                    ['Type', $m->report_type],
                    ['Priority', $m->priority],
                    ['Warehouse', optional($m->warehouse)->name],
                    ['Submitted By', optional($m->submittedBy)->name],
                ],
            ],
        ];
    }
}
