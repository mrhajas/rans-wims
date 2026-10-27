<?php

namespace App\Http\Controllers\SupplierRecords\Concerns;

use App\Models\Contact;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;

trait SharedLookups
{
    /**
     * Suppliers are real Contact records (category "Suppliers") — not a
     * mock entity. Financial/bank fields live in Contact's existing
     * extra_attributes schemaless bag (see SupplierDetails helper), so
     * nothing here needs a new table or column.
     */
    protected function supplierOptions(): array
    {
        return Contact::where('category', 'Suppliers')->orderBy('name')->get(['id', 'name', 'extra_attributes'])
            ->map(function ($c) {
                $details = \App\Support\SupplierRecords\SupplierDetails::get($c);

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'currency' => $details['currency'],
                    'tax_percentage' => (float) $details['tax_percentage'],
                ];
            })
            ->values()->all();
    }

    protected function currencyOptions(): array
    {
        return \App\Support\SupplierRecords\Currencies::all();
    }

    protected function warehouseOptions(): array
    {
        return Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at'])
            ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'completed_at' => $w->completed_at, 'status' => $w->status])->values()->all();
    }

    protected function itemOptions(): array
    {
        return Item::query()->with('unit:id,name,code')->orderBy('name')->limit(5000)->get(['id', 'name', 'code', 'unit_id', 'price']) // every item: it was cut at the first 300 by name, so the rest could not be picked on these forms
            ->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'code' => $i->code,
                'unit_label' => $i->unit->code ?? $i->unit->name ?? null,
                'price' => $i->price,
            ])->values()->all();
    }

    protected function userOptions(): array
    {
        return User::query()->orderBy('name')->limit(2000)->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all();
    }

    /**
     * Request-scoped memoization for supplierName()/userName() — both get
     * called once per row across every Index/Show/Explorer/Dashboard method
     * in this module (25+ call sites), which without this was a fresh
     * `find()` query every single time, even when the same handful of
     * suppliers/users repeat across dozens of rows. Static arrays here are
     * safe (not stale) because this app boots fresh per request (no
     * Octane/Swoole persistent workers) — each request gets its own empty
     * cache.
     */
    private static array $supplierNameCache = [];

    private static array $userNameCache = [];

    protected function supplierName(?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return self::$supplierNameCache[$id] ??= Contact::find($id)?->name;
    }

    protected function userName(?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return self::$userNameCache[$id] ??= User::find($id)?->name;
    }

    /**
     * The "where did this come from" chain shown on GRN/Invoice
     * forms/show pages: Purchase Request (if any) -> Quotation (if any) ->
     * Purchase Order, each with who created it and when — so nothing about
     * a delivery or invoice's origin is hidden behind a bare ID.
     */
    protected function traceForPurchaseOrder(PurchaseOrder $po): array
    {
        $po->loadMissing(['purchaseRequest', 'quotation']);
        $pr = $po->purchaseRequest;
        $quotation = $po->quotation;

        return [
            'purchase_request' => $pr ? [
                'id' => $pr->id, 'reference' => $pr->reference, 'status' => $pr->status,
                'created_by_name' => $this->userName($pr->user_id), 'created_at' => optional($pr->created_at)->toIso8601String(),
                'route' => 'supplier-records.purchase-requests.show',
            ] : null,
            'quotation' => $quotation ? [
                'id' => $quotation->id, 'reference' => $quotation->reference, 'status' => $quotation->status,
                'created_by_name' => $this->userName($quotation->created_by), 'created_at' => optional($quotation->created_at)->toIso8601String(),
                'route' => 'supplier-records.quotations.show',
            ] : null,
            'purchase_order' => [
                'id' => $po->id, 'reference' => $po->reference, 'status' => $po->status,
                'created_by_name' => $this->userName($po->user_id), 'created_at' => optional($po->created_at)->toIso8601String(),
                'route' => 'supplier-records.purchase-orders.show',
            ],
        ];
    }
}
