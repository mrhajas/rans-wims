// The small answers every page's header or dashboard asks the server for (attendance widget, online people ...) are kept here for a short time, in the browser's memory.
// This module stays loaded while a person moves from page to page, so a page that is opened a moment after another one shows what was just fetched instead of asking again.
// Nothing is stored on disk and nothing outlives the browser tab; a person's own action (clocking in, for example) always asks the server again.
const kept = {};

/** The answer kept under `key` if it is younger than `ttlMs`, else null. */
export function fresh(key, ttlMs) {
  const e = kept[key];

  return e && Date.now() - e.at < ttlMs ? e.data : null;
}

export function keep(key, data) {
  kept[key] = { at: Date.now(), data };
}

export function forget(key) {
  delete kept[key];
}
