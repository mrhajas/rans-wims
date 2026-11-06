// Fire-and-forget usage tracking for the floating quick-access menu.
// Section key = first dot-segment of the current named route (e.g. "items.index" -> "items"),
// mirroring the exact convention FeatureManager::featureForRoute() and
// Authorizable::getAbility() already use server-side — no separate mapping table to maintain.
//
// Visits are counted here and sent together: one request every 30 seconds at most (and when the page is left or hidden), instead of one request for every page opened.
// The quick menu is ordered by how often a section is used, so a delay of half a minute changes nothing a person can see.
let lastKey = null;
let pending = {};
let timer = null;
const FLUSH_MS = 30000;

function body() {
  const visits = pending;
  pending = {};

  return Object.keys(visits).length ? { visits } : null;
}

function flush(leaving = false) {
  clearTimeout(timer);
  timer = null;
  const data = body();
  if (!data || typeof route !== 'function') {
    return;
  }
  if (leaving) {
    // the page is going away: a keep-alive request still reaches the server
    const token = decodeURIComponent((document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
    try {
      fetch(route('quick-menu.track'), { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token }, body: JSON.stringify(data) }).catch(() => {});
    } catch (e) {
      /* nothing to do */
    }

    return;
  }
  window.axios?.post(route('quick-menu.track'), data).catch(() => {});
}

if (typeof window !== 'undefined') {
  window.addEventListener('pagehide', () => flush(true));
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(true); });
}

export function trackSectionVisit() {
  if (typeof route !== 'function') {
    return;
  }

  const current = route().current();
  if (!current) {
    return;
  }

  const key = current.split('.')[0];
  if (!key || key === lastKey) {
    return;
  }

  lastKey = key;
  pending[key] = (pending[key] || 0) + 1;
  if (!timer) {
    timer = setTimeout(() => flush(false), FLUSH_MS);
  }
}
