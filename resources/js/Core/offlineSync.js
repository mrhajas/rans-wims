import axios from 'axios';
import { STORE_NAMES, putAll, deleteAll, getMeta, setMeta, clearAllStores } from '@/Core/offlineDb';
import { markSyncing, markSyncComplete } from '@/Core/offlineStatus';

// Must match OfflineSyncController::CHUNK_SIZE — only affects how many
// round-trips a first sync takes, not correctness (has_more still drives
// the loop either way), but keeping them equal avoids pointless extra pages.
const CHUNK_SIZE = 500;

let syncing = false;

function fetchPage(params) {
  return axios.get('/offline/sync', { params }).then(res => res.data);
}

function applyResourcePage(resourceKey, page) {
  return Promise.all([putAll(resourceKey, page.rows || []), deleteAll(resourceKey, page.tombstones || [])]);
}

async function syncResourceFully(resourceKey) {
  let cursor = 0;
  let serverTime = null;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    const data = await fetchPage({ resource: resourceKey, cursor });
    serverTime = data.server_time;
    const page = data.resources[resourceKey];
    // Absent means the user has no read-{resource} permission (see
    // OfflineSyncController::PERMISSION_OVERRIDES / the read-{key} gate) —
    // nothing to cache, not an error, stop paging this resource.
    if (!page) {
      break;
    }
    await applyResourcePage(resourceKey, page);
    if (!page.has_more) {
      break;
    }
    cursor += CHUNK_SIZE;
  }

  return serverTime;
}

/**
 * Compares the cache's recorded owner against the currently authenticated
 * user and wipes it on a mismatch — the only thing standing between one
 * user's cached data and the next person who logs into the same browser
 * when the JS-driven logout hooks below didn't get a chance to run (e.g.
 * the tab was closed mid-session). See resources/js/Shared/Top/Navbar.vue's
 * logout() and resources/js/Core/idleLogout.js's performLogout() for the
 * complementary clear-on-logout half of this boundary.
 */
export async function ensureCacheOwnership(currentUserId) {
  const ownerId = await getMeta('owner_user_id');
  if (ownerId != null && String(ownerId) !== String(currentUserId)) {
    await clearAllStores();
  }
  await setMeta('owner_user_id', currentUserId);
}

export async function runSync() {
  if (syncing) {
    return false;
  }
  syncing = true;
  markSyncing(true);

  try {
    const since = await getMeta('since');
    let serverTime = null;

    if (!since) {
      for (const resourceKey of STORE_NAMES) {
        if (resourceKey === 'sync_metadata') {
          continue;
        }
        serverTime = await syncResourceFully(resourceKey);
      }
    } else {
      const data = await fetchPage({ since });
      serverTime = data.server_time;
      await Promise.all(Object.entries(data.resources).map(([resourceKey, page]) => applyResourcePage(resourceKey, page)));
    }

    if (serverTime) {
      await setMeta('since', serverTime);
    }

    markSyncComplete();
    return true;
  } catch (e) {
    console.error('WIMS offline sync failed', e);
    markSyncing(false);
    return false;
  } finally {
    syncing = false;
  }
}

/**
 * Wires automatic sync to the same "when it makes sense" triggers the
 * status module already fires 'wims-connection-changed' for: app start,
 * reconnect after being offline, and tab-visibility regain that finds the
 * server reachable again — every one of those transitions ends at status
 * 'online', so listening for that single event covers all three per the
 * plan's "startup / reconnect / tab regain / manual" trigger list, without
 * a second independent timer.
 */
export function initOfflineSync(currentUserId) {
  window.addEventListener('wims-connection-changed', event => {
    if (event?.detail?.to === 'online') {
      runSync();
    }
  });

  ensureCacheOwnership(currentUserId).catch(e => {
    console.error('WIMS offline cache ownership check failed', e);
  });
}
