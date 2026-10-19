import { reactive } from 'vue';
import axios from 'axios';

// States: 'checking' | 'online' | 'offline' | 'syncing' | 'degraded'.
// 'degraded' = server reachable but the session isn't valid (spec's own
// "connection exists -> server reachable -> auth valid -> online" chain) —
// treated distinctly from 'offline' so a stale/expired session doesn't get
// mislabeled as a network problem.
export const offlineState = reactive({
  status: 'checking',
  lastOnlineAt: null,
  lastCheckedAt: null,
  lastSyncAt: null,
  serverTime: null,
  slow: false,
  checking: false,
});

const POLL_INTERVAL_MS = 60000;
// A slow connection is not an offline one. The health call is given a long time to answer, a late or failed answer
// is retried a few times, and WIMS only switches to Read Mode when the browser itself says it has no network, or
// when several attempts in a row could not reach the server at all.
const HEALTH_TIMEOUT_MS = 25000;
const FAILS_BEFORE_OFFLINE = 3;
const RETRY_DELAY_MS = 4000;

let pollTimerId = null;
let retryTimerId = null;
let inFlight = false;
let interceptorInstalled = false;
let consecutiveFailures = 0;

function setStatus(next) {
  const prev = offlineState.status;
  offlineState.status = next;
  if (prev !== next) {
    window.dispatchEvent(new CustomEvent('wims-connection-changed', { detail: { from: prev, to: next } }));
  }
}

async function probe() {
  if (inFlight) {
    return;
  }
  inFlight = true;
  clearTimeout(retryTimerId);

  // Never flicker through 'checking' once the status is known: a background check on a slow line must not look like
  // a change of state anywhere in the app. Only the spinner on the manual refresh button reacts (offlineState.checking).
  offlineState.checking = true;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), HEALTH_TIMEOUT_MS);
  const startedAt = Date.now();

  try {
    const res = await axios.get('/offline/health', { signal: controller.signal });
    consecutiveFailures = 0;
    offlineState.lastCheckedAt = new Date().toISOString();
    offlineState.serverTime = res.data.server_time;
    offlineState.slow = Date.now() - startedAt > 6000;

    if (!res.data.authenticated) {
      setStatus('degraded');
    } else {
      offlineState.lastOnlineAt = offlineState.lastCheckedAt;
      setStatus('online');
    }
  } catch (e) {
    offlineState.lastCheckedAt = new Date().toISOString();
    // The server ANSWERED (any HTTP status, e.g. a slow gateway returning 502/504): the network is fine.
    const answered = !!e?.response;
    if (answered) {
      consecutiveFailures = 0;
      offlineState.slow = true;
      if (offlineState.status === 'checking') {
        setStatus('online');
      }
    } else {
      consecutiveFailures += 1;
      offlineState.slow = true;
      const browserOffline = typeof navigator !== 'undefined' && navigator.onLine === false;
      if (browserOffline || consecutiveFailures >= FAILS_BEFORE_OFFLINE) {
        setStatus('offline');
      } else {
        // Not proven offline yet: keep the current status and look again shortly.
        if (offlineState.status === 'checking') {
          setStatus('online');
        }
        retryTimerId = setTimeout(probe, RETRY_DELAY_MS);
      }
    }
  } finally {
    clearTimeout(timeoutId);
    inFlight = false;
    offlineState.checking = false;
  }
}

function installAxiosOfflineHeader() {
  if (interceptorInstalled) {
    return;
  }
  interceptorInstalled = true;

  // Inertia v1 wraps this same axios module for its own visits/form posts —
  // one interceptor covers every outgoing mutation app-wide, no per-call-site
  // wiring needed. Defense-in-depth only: see RejectOfflineMutations.php.
  axios.interceptors.request.use(config => {
    if (offlineState.status === 'offline') {
      config.headers['X-Wims-Offline'] = '1';
    }
    return config;
  });
}

export function initOfflineStatus() {
  installAxiosOfflineHeader();

  probe();
  // Only look while the tab is on screen: a background tab does not need to know, and it spares the connection.
  pollTimerId = setInterval(() => {
    if (document.visibilityState === 'visible') {
      probe();
    }
  }, POLL_INTERVAL_MS);

  window.addEventListener('online', () => {
    consecutiveFailures = 0;
    probe();
  });
  // The browser's own "offline" event is trusted only after a second look: some networks fire it briefly.
  window.addEventListener('offline', () => {
    offlineState.lastCheckedAt = new Date().toISOString();
    setTimeout(() => {
      if (navigator.onLine === false) {
        setStatus('offline');
      } else {
        probe();
      }
    }, 3000);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      probe();
    }
  });
}

export function refreshConnectionStatus() {
  return probe();
}

export function markSyncing(isSyncing) {
  offlineState.status = isSyncing ? 'syncing' : offlineState.status;
}

export function markSyncComplete() {
  offlineState.lastSyncAt = new Date().toISOString();
  offlineState.status = 'online';
}

// Manual trigger for testing: open devtools console and run window.__wimsProbeConnection()
window.__wimsProbeConnection = probe;
