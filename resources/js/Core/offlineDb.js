const DB_NAME = 'wims-offline';
// Bumped for the material_requests store — openDb()'s onupgradeneeded only
// ever adds missing stores (never drops existing ones), so this is a safe,
// additive migration for anyone with an existing v1 database.
const DB_VERSION = 2;

// One store per synced resource, matching OfflineSyncController's response
// keys exactly, plus a single-purpose `sync_metadata` store for the
// incremental-sync cursor and the cross-user cache-ownership check (see
// resources/js/Core/offlineSync.js). Line items for Checkin/Checkout/
// Transfer/Adjustment are embedded as a nested `items` array on each row
// (matching what the resources already nest server-side) rather than kept
// as separate stores — this stays a read-only, list+detail cache, not a
// relational mirror of the database.
const STORES = {
  items: { keyPath: 'id', indexes: [['name', 'name'], ['code', 'code'], ['sku', 'sku']] },
  categories: { keyPath: 'id', indexes: [['name', 'name'], ['parent_id', 'parent_id']] },
  warehouses: { keyPath: 'id', indexes: [['name', 'name']] },
  stock: { keyPath: 'id', indexes: [['warehouse_id', 'warehouse_id'], ['item_id', 'item_id']] },
  checkins: { keyPath: 'id', indexes: [['warehouse_id', 'warehouse_id'], ['date', 'date']] },
  checkouts: { keyPath: 'id', indexes: [['warehouse_id', 'warehouse_id'], ['date', 'date']] },
  transfers: { keyPath: 'id', indexes: [['from_warehouse_id', 'from_warehouse_id'], ['to_warehouse_id', 'to_warehouse_id'], ['date', 'date']] },
  adjustments: { keyPath: 'id', indexes: [['warehouse_id', 'warehouse_id'], ['date', 'date']] },
  'material-requests': { keyPath: 'id', indexes: [['warehouse_id', 'warehouse_id'], ['status', 'status'], ['date_requested', 'date_requested']] },
  sync_metadata: { keyPath: 'key' },
};

export const STORE_NAMES = Object.keys(STORES);

let dbPromise = null;

function openDb() {
  if (dbPromise) {
    return dbPromise;
  }

  dbPromise = new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) {
      reject(new Error('IndexedDB is not available in this browser.'));
      return;
    }

    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      for (const [name, config] of Object.entries(STORES)) {
        if (db.objectStoreNames.contains(name)) {
          continue;
        }
        const store = db.createObjectStore(name, { keyPath: config.keyPath });
        (config.indexes || []).forEach(([indexName, keyPath]) => {
          store.createIndex(indexName, keyPath, { unique: false });
        });
      }
    };

    request.onsuccess = () => {
      const db = request.result;
      // If another tab/context needs to upgrade the schema later, release
      // our connection immediately rather than blocking it — and, by the
      // same mechanism, avoid a stale connection blocking *this* page's own
      // future open() calls forever with no error and no pending network
      // activity (the classic IndexedDB hang this pairs with onblocked below
      // to close off).
      db.onversionchange = () => db.close();
      resolve(db);
    };
    request.onerror = () => reject(request.error);
    request.onblocked = () => reject(new Error('IndexedDB open blocked by another connection.'));
  });

  // Don't cache a dead end: a failed open should be retryable on the next
  // call rather than permanently returning the same rejected promise.
  dbPromise.catch(() => {
    dbPromise = null;
  });

  return dbPromise;
}

function withStore(storeName, mode, executor) {
  return openDb().then(
    db =>
      new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, mode);
        const store = tx.objectStore(storeName);
        const result = executor(store);

        tx.oncomplete = () => resolve(result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
      })
  );
}

function requestToPromise(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

export function putAll(storeName, rows) {
  if (!rows || !rows.length) {
    return Promise.resolve();
  }
  return withStore(storeName, 'readwrite', store => {
    rows.forEach(row => store.put(row));
  });
}

export function deleteAll(storeName, ids) {
  if (!ids || !ids.length) {
    return Promise.resolve();
  }
  return withStore(storeName, 'readwrite', store => {
    ids.forEach(id => store.delete(id));
  });
}

export function getAll(storeName) {
  return openDb().then(db => {
    const tx = db.transaction(storeName, 'readonly');
    return requestToPromise(tx.objectStore(storeName).getAll());
  });
}

export function get(storeName, id) {
  return openDb().then(db => {
    const tx = db.transaction(storeName, 'readonly');
    return requestToPromise(tx.objectStore(storeName).get(id));
  });
}

export function queryByIndex(storeName, indexName, value) {
  return openDb().then(db => {
    const tx = db.transaction(storeName, 'readonly');
    return requestToPromise(tx.objectStore(storeName).index(indexName).getAll(value));
  });
}

export function getMeta(key) {
  return get('sync_metadata', key).then(row => row?.value ?? null);
}

export function setMeta(key, value) {
  return putAll('sync_metadata', [{ key, value }]);
}

/**
 * Used on logout and on a detected user-switch (see offlineSync.js) — closes
 * the open connection and deletes the whole database, then drops the cached
 * connection promise so the next call to any of the functions above opens a
 * fresh, empty database rather than reusing a stale/closed handle.
 */
export function clearAllStores() {
  const closeAndDelete = db => {
    db?.close();
    return new Promise((resolve, reject) => {
      const request = indexedDB.deleteDatabase(DB_NAME);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
      request.onblocked = () => resolve(); // another tab still has it open; best-effort
    });
  };

  const pending = dbPromise ? dbPromise.then(closeAndDelete, () => closeAndDelete(null)) : closeAndDelete(null);

  return pending.finally(() => {
    dbPromise = null;
  });
}
