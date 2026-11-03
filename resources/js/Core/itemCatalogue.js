import { router } from '@inertiajs/vue3';
import { itemText } from '@/Core/textCase';

/**
 * The item list of the pickers, kept in the browser's memory so a search costs no request.
 *
 * What it is: the same items the server search returns, fetched once (a small compressed answer) from `items.catalogue`, searched here by the same
 * rules as `Item::scopeSearch` (every word of the search must be found in the code, the name, the SKU or a category; a word that is the number of an item code,
 * or "RSI 562", also finds that exact code; the item whose code was typed comes first).
 *
 * What keeps it safe and right:
 *  - It lives only in this module's memory: never in localStorage, IndexedDB or the HTTP cache (the server also sends no-store), so closing the tab or
 *    signing out leaves nothing behind. It is thrown away the moment a different person is signed in (it is keyed by the user id).
 *  - It stays up to date: at most every 30 seconds of use it asks the server "has anything changed?" (the version stamp in If-None-Match; the usual answer is
 *    an empty 304), after every page visit it asks again on the next use, and once every 15 minutes it is simply fetched afresh.
 *  - Anything it cannot answer is answered by the server as before: the list is not loaded yet, nothing was found here (a brand-new item is not in it yet),
 *    the request failed, or the catalogue is too large to hold (the server then says so and the browser never keeps it).
 *  - Every result is a fresh copy, because the forms change the item they receive (selected variations, quantities...).
 */

const CHECK_EVERY_MS = 30 * 1000;
const FULL_RELOAD_MS = 15 * 60 * 1000;
const RETRY_AFTER_FAIL_MS = 60 * 1000;
const MAX_WORDS = 8;
const MAX_CHARS = 100;

const state = { key: null, version: null, entries: null, loadedAt: 0, checkedAt: 0, failedAt: 0, tooLarge: false, pending: null };

// "as typed" without accents or capitals, the way the database compares it
const fold = s => String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** "562", "RSI 562", "rsi-0562" -> "RSI-0562" (the same rule as Item::canonicalCode on the server), otherwise null */
export function canonicalCode(s) {
  const m = /^(?:RSI)?[\s\-_.]*0*(\d{1,6})$/i.exec(String(s == null ? '' : s).trim());

  return m ? 'RSI-' + m[1].padStart(4, '0') : null;
}

export function resetCatalogue() {
  state.key = null;
  state.version = null;
  state.entries = null;
  state.loadedAt = 0;
  state.checkedAt = 0;
  state.failedAt = 0;
  state.tooLarge = false;
  state.pending = null;
}

// after any page visit the list is asked about again the next time a picker is used (an item may have been added or changed on that page)
try {
  router.on('navigate', () => {
    state.checkedAt = 0;
  });
} catch (e) { /* the router is not there (tests) */ }

export function catalogueReady(userId) {
  return !!userId && state.key === userId && !!state.entries && !state.tooLarge;
}

/** true when this browser should not try (too many items, or no signed-in person) */
export function catalogueUnavailable(userId) {
  return !userId || (state.key === userId && state.tooLarge);
}

function build(items) {
  return items.map(row => {
    const cat = row.cat || '';
    delete row.cat; // the search text is kept apart; the item itself stays exactly what the picker always received
    return { id: row.id, code: row.code || '', hay: fold([row.code, row.name, row.sku, cat].join('\u0001')), row };
  });
}

/**
 * Loads the list the first time and keeps it fresh afterwards. Resolves true when the list can be used. Never throws; many callers share one request.
 */
export function ensureCatalogue(userId) {
  if (!userId) return Promise.resolve(false);
  if (state.key !== userId) {
    resetCatalogue();
    state.key = userId;
  }
  if (state.tooLarge) return Promise.resolve(false);

  const now = Date.now();
  if (state.entries && now - state.checkedAt < CHECK_EVERY_MS) return Promise.resolve(true);
  if (state.pending) return state.pending;
  if (!state.entries && state.failedAt && now - state.failedAt < RETRY_AFTER_FAIL_MS) return Promise.resolve(false);

  const headers = {};
  if (state.entries && state.version && now - state.loadedAt < FULL_RELOAD_MS) headers['If-None-Match'] = '"' + state.version + '"';

  state.pending = window.axios
    .get(route('items.catalogue'), { headers, validateStatus: s => s === 200 || s === 304 })
    .then(res => {
      if (state.key !== userId) return false; // someone else signed in meanwhile
      state.checkedAt = Date.now();
      state.failedAt = 0;
      if (res.status === 304) return !!state.entries;
      const data = res.data || {};
      if (data.too_large || !Array.isArray(data.items)) {
        state.tooLarge = true;
        state.entries = null;
        return false;
      }
      state.entries = build(data.items);
      state.version = data.version;
      state.loadedAt = Date.now();
      return true;
    })
    .catch(() => {
      state.failedAt = Date.now();
      return !!state.entries; // keep using what is already here
    })
    .finally(() => {
      state.pending = null;
    });

  return state.pending;
}

const copy = row => JSON.parse(JSON.stringify(row));

/**
 * The picker's list for what was typed. Returns null when the list is not ready (the caller asks the server), else an array of at most `limit` fresh items.
 * `labelled` adds the "NAME (CODE)" label and lists by name (the Transfer form); the other pickers list by item number, as before.
 */
export function searchCatalogue(userId, term, { labelled = false, limit = 25 } = {}) {
  if (!catalogueReady(userId)) return null;

  const raw = String(term == null ? '' : term);
  const words = raw.trim().slice(0, MAX_CHARS).split(/\s+/u).filter(Boolean).slice(0, MAX_WORDS);
  const whole = canonicalCode(raw);

  let found;
  if (!words.length) {
    found = state.entries;
  } else {
    const needles = words.map(fold);
    const codes = words.map(canonicalCode);
    found = state.entries.filter(e => {
      let ok = true;
      for (let i = 0; i < needles.length; i++) {
        if (!(e.hay.includes(needles[i]) || (codes[i] && e.code === codes[i]))) {
          ok = false;
          break;
        }
      }
      return ok || (!!whole && words.length > 1 && e.code === whole);
    });
  }

  // entries arrive by name; the other pickers list by item number
  let list = labelled ? found : found.slice().sort((a, b) => a.id - b.id);
  if (whole) {
    const exact = list.filter(e => e.code === whole);
    if (exact.length) list = exact.concat(list.filter(e => e.code !== whole));
  }

  return list.slice(0, limit).map(e => {
    const row = copy(e.row);
    if (labelled) row.label = itemText(row.name, row.code);

    return row;
  });
}
