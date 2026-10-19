// Fire-and-forget usage tracking for the floating quick-access menu.
// Section key = first dot-segment of the current named route (e.g. "items.index" -> "items"),
// mirroring the exact convention FeatureManager::featureForRoute() and
// Authorizable::getAbility() already use server-side — no separate mapping table to maintain.
let lastKey = null;

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

  window.axios?.post(route('quick-menu.track'), { section: key }).catch(() => {});
}
