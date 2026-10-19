import { getAll, get, queryByIndex } from '@/Core/offlineDb';

// Which fields instant client-side search matches per resource — mirrors
// the indexes actually created in offlineDb.js (name/code/sku) plus a
// couple of obvious extras that don't need their own index for a plain
// substring scan over an already-small, already-in-memory array.
const SEARCHABLE_FIELDS = {
  items: ['name', 'code', 'sku'],
  categories: ['name', 'code'],
  warehouses: ['name', 'code'],
  checkins: ['reference'],
  checkouts: ['reference'],
  transfers: ['reference'],
  adjustments: ['reference'],
  'material-requests': ['reference'],
};

function matches(row, fields, query) {
  const q = query.trim().toLowerCase();
  if (!q) {
    return true;
  }
  return fields.some(field => String(row?.[field] ?? '').toLowerCase().includes(q));
}

/**
 * Read-only replacement for an Inertia list visit while offline. Shaped to
 * be assignable directly to whatever local field an Index.vue page reads
 * instead of its `resource.data` prop (see Item/Index.vue's `displayItems`
 * for the reference pattern) — deliberately NOT paginated server-side,
 * since offline has no server round-trip to paginate against; the caller
 * gets the full matching set and can slice/paginate client-side if needed.
 */
export async function loadIndexData(resourceKey, { search } = {}) {
  const rows = await getAll(resourceKey);
  const fields = SEARCHABLE_FIELDS[resourceKey] || ['name'];
  const filtered = search ? rows.filter(row => matches(row, fields, search)) : rows;
  // Matches the `orderByDesc('id')` every live Index controller already uses
  // (see CheckinController/ItemController/WarehouseController@index) — id
  // descending is this app's existing "latest first" convention, so the
  // offline list order stays consistent with what the same page shows online.
  const data = [...filtered].sort((a, b) => b.id - a.id);

  return { data, meta: { offline: true, total: data.length } };
}

export function loadDetail(resourceKey, id) {
  return get(resourceKey, id);
}

/**
 * Stock is its own store (not embedded on the item row — see offlineDb.js),
 * so an item's offline "detail" view needs a second lookup by the
 * `item_id` index to reconstruct the per-warehouse breakdown the live
 * items.show endpoint returns via allStock.
 */
export function loadItemStock(itemId) {
  return queryByIndex('stock', 'item_id', itemId);
}

/**
 * Matches the shape Item/Details.vue's `availableStock` computed already
 * expects (item.stock grouped by warehouse_id, each stock row carrying its
 * own nested `warehouse` object) — Stock's `$with = ['warehouse:id,code,name']`
 * default eager-load means every cached stock row already has that nested
 * object, so no extra join is needed here, just the grouping.
 */
export function groupStockByWarehouse(stockRows) {
  const grouped = {};
  (stockRows || []).forEach(row => {
    const key = row.warehouse_id;
    if (!grouped[key]) {
      grouped[key] = [];
    }
    grouped[key].push(row);
  });
  return grouped;
}

/**
 * Offline replacement for ReportController::generalListing() — same shape
 * ([{id, name, code, total, items: [{id, code, name, unit, quantity, price,
 * total}]}]), rebuilt entirely from what's already synced (items, stock,
 * warehouses) rather than a dedicated report cache.
 *
 * Fidelity note: the live endpoint iterates every *active, permission-scoped*
 * warehouse and always shows it (even with zero stock). Offline, the
 * permission scope isn't synced as its own list — it's implicit in which
 * warehouse_ids a non-admin's `stock` rows were already filtered to during
 * sync (see OfflineSyncController's `warehouse_scope_ids()` scoping). So
 * here the report is built from warehouses that actually have at least one
 * synced stock row, which matches online for every warehouse with real
 * stock; the one gap is an assigned-but-currently-empty warehouse, which
 * online still shows as an empty section and offline simply omits — an
 * acceptable, documented boundary for a read-only cache, not a bug to chase.
 */
export async function loadGeneralReport() {
  const [items, stock, warehouses] = await Promise.all([getAll('items'), getAll('stock'), getAll('warehouses')]);

  const itemsById = {};
  items.forEach(item => {
    itemsById[item.id] = item;
  });
  const warehousesById = {};
  warehouses.forEach(w => {
    warehousesById[w.id] = w;
  });

  const byWarehouse = {};
  stock
    .filter(s => !s.variation_id && Number(s.quantity) > 0)
    .forEach(s => {
      const item = itemsById[s.item_id];
      const warehouse = warehousesById[s.warehouse_id];
      if (!item || !warehouse || !warehouse.active) {
        return;
      }

      const price = Number(item.price || 0);
      const quantity = Number(s.quantity || 0);
      const line = {
        id: item.id,
        code: item.code,
        name: item.name,
        unit: item.unit?.name || item.unit?.code || '-',
        quantity,
        price,
        total: quantity * price,
      };

      if (!byWarehouse[warehouse.id]) {
        byWarehouse[warehouse.id] = { id: warehouse.id, name: warehouse.name, code: warehouse.code, items: [] };
      }
      byWarehouse[warehouse.id].items.push(line);
    });

  const listing = Object.values(byWarehouse)
    .map(w => ({ ...w, total: w.items.reduce((sum, i) => sum + i.total, 0) }))
    .sort((a, b) => (a.name || '').localeCompare(b.name || ''));

  return { listing, grandTotal: listing.reduce((sum, w) => sum + w.total, 0) };
}
