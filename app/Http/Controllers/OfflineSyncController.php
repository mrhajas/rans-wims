<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\Category;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Server -> device, read-only sync for WIMS Offline Read Mode. Every query
 * below deliberately mirrors the exact scoping the matching live Inertia
 * controller already applies (warehouse_scope_ids()/withoutGlobalScope('mine')
 * for Checkin/Checkout/Transfer/Adjustment, whereHas('stock') for Item,
 * unscoped-but-account-scoped for Warehouse/Category, role-based for
 * MaterialRequest) — this endpoint is not a new authorization surface, it's
 * the same one already enforced on every Index page, replayed for the
 * client's local cache.
 */
class OfflineSyncController extends Controller
{
    private const TRANSACTION_WINDOW_DAYS = 90;

    private const CHUNK_SIZE = 500;

    // `stock` has no page/permission of its own — it's exposed today only as
    // part of an item's detail view, so it rides on `read-items` rather than
    // a `read-stock` permission that doesn't exist in this app.
    private const PERMISSION_OVERRIDES = ['stock' => 'read-items'];

    public function sync(Request $request)
    {
        $since = $request->query('since') ? Carbon::parse($request->query('since')) : null;
        $onlyResource = $request->query('resource');
        $cursor = max(0, (int) $request->query('cursor', 0));

        $scopeIds = warehouse_scope_ids();
        $windowStart = now()->subDays(self::TRANSACTION_WINDOW_DAYS)->startOfDay();

        $resources = [];
        foreach ($this->resourceBuilders($scopeIds, $windowStart, $request->user()) as $key => $builderFn) {
            if ($onlyResource && $onlyResource !== $key) {
                continue;
            }
            // `sync` isn't one of the method names Authorizable::callAction auto-checks
            // (see app/Traits/Authorizable.php), so this is the only permission gate
            // this endpoint gets — without it, a user with no read-{resource}
            // permission would still have that resource synced to their device.
            $permission = self::PERMISSION_OVERRIDES[$key] ?? "read-{$key}";
            if (! $request->user()->can($permission)) {
                continue;
            }
            $resources[$key] = $this->page($builderFn(), $since, $cursor);
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'since' => $since?->toIso8601String(),
            'resources' => $resources,
        ]);
    }

    /**
     * One closure per synced table, each returning a fresh, unexecuted query
     * builder scoped exactly like its live-UI counterpart.
     */
    private function resourceBuilders(?array $scopeIds, Carbon $windowStart, $user): array
    {
        return [
            // Warehouse/Category index pages apply no per-user scoping beyond
            // the automatic account scope — matched here as-is, not "fixed".
            'warehouses' => fn () => Warehouse::query(),

            'categories' => fn () => Category::query()->with('parent:id,name,code'),

            'items' => fn () => Item::query()
                ->with(['categories:id,code,name', 'unit'])
                ->when($scopeIds !== null, fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereIn('warehouse_id', $scopeIds))),

            'stock' => fn () => Stock::query()
                ->when($scopeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $scopeIds)),

            // items.variations/items.item/items.unit mirror the exact eager-load
            // chain each live show() action uses (see Checkin/Checkout/Transfer/
            // AdjustmentController::show) — the shared Details.vue components
            // these feed (reused as-is for the offline shell) read into that
            // nested shape unconditionally, e.g. `item.variations.length`, so a
            // shallower load here would crash them on cached data.
            'checkins' => fn () => Checkin::query()
                ->with(['items.variations', 'items.item', 'items.unit', 'contact:id,name', 'warehouse:id,name,code', 'user:id,name'])
                ->withoutGlobalScope('mine')
                ->when($scopeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $scopeIds))
                ->where('date', '>=', $windowStart),

            'checkouts' => fn () => Checkout::query()
                ->with(['items.variations', 'items.item', 'items.unit', 'contact:id,name', 'warehouse:id,name,code', 'user:id,name', 'workActivity:id,name'])
                ->withoutGlobalScope('mine')
                ->when($scopeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $scopeIds))
                ->where('date', '>=', $windowStart),

            'transfers' => fn () => Transfer::query()
                ->with(['items.variations', 'items.item', 'items.unit', 'fromWarehouse:id,name,code', 'toWarehouse:id,name,code', 'user:id,name'])
                ->withoutGlobalScope('mine')
                ->when($scopeIds !== null, fn ($q) => $q->where(
                    fn ($w) => $w->whereIn('from_warehouse_id', $scopeIds)->orWhereIn('to_warehouse_id', $scopeIds)
                ))
                ->where('date', '>=', $windowStart),

            'adjustments' => fn () => Adjustment::query()
                ->with(['items.variations', 'items.item', 'items.unit', 'warehouse:id,name,code', 'user:id,name', 'approver:id,name'])
                ->withoutGlobalScope('mine')
                ->when($scopeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $scopeIds))
                ->where('date', '>=', $windowStart),

            // Material requests aren't warehouse_scope_ids()-scoped like the
            // four transaction types above — they're scoped by the softcoded
            // the "View" / "View all" permissions (MaterialRequestVisibility), the very
            // same scope MaterialRequestController::index() applies.
            // No 90-day window: unlike checkins/checkouts/etc this isn't a
            // high-volume append-only log, and the live index() itself has no
            // default date filter — matching warehouses/categories/items in
            // staying unbounded.
            'material-requests' => function () use ($user) {
                $query = MaterialRequest::query()
                    ->with(['warehouse:id,name,code', 'user:id,name', 'clearedBy:id,name', 'items.item:id,name,code,symbology,unit_id', 'items.unit:id,name,code']);

                // Same visibility as the live list + show policy.
                \App\Support\MaterialRequestVisibility::scopeQueryFor($query, $user);

                return $query;
            },
        ];
    }

    /**
     * Applies the incremental `since` filter (changed rows) plus soft-delete
     * tombstones (ids only), and chunks via cursor for a large first sync.
     * Tombstones are only meaningful once a client already has a prior
     * snapshot to reconcile against, so a first sync (`since` null) omits them.
     */
    private function page($builder, ?Carbon $since, int $cursor): array
    {
        $liveQuery = clone $builder;
        $trashedQuery = (clone $builder)->onlyTrashed();

        if ($since) {
            $liveQuery->where('updated_at', '>', $since);
            $trashedQuery->where('deleted_at', '>', $since);
        }

        $rows = $liveQuery->orderBy('id')->skip($cursor)->take(self::CHUNK_SIZE)->get();
        $tombstones = $since ? $trashedQuery->orderBy('id')->pluck('id') : collect();

        return [
            'rows' => $rows,
            'tombstones' => $tombstones,
            'has_more' => $rows->count() === self::CHUNK_SIZE,
        ];
    }
}
