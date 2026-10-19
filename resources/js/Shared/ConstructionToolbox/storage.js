const FAVORITES_KEY = 'wims-toolbox-favorites';
const HISTORY_KEY = 'wims-toolbox-history';
const SESSION_KEY = 'wims-toolbox-session';
const HISTORY_LIMIT = 20;

function readJson(storage, key, fallback) {
  try {
    const raw = storage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch (e) {
    return fallback;
  }
}

function writeJson(storage, key, value) {
  try {
    storage.setItem(key, JSON.stringify(value));
  } catch (e) {
    // storage unavailable (private browsing, quota) — fail silently, feature degrades gracefully
  }
}

export function getFavorites() {
  return readJson(window.localStorage, FAVORITES_KEY, []);
}

export function isFavorite(toolId) {
  return getFavorites().includes(toolId);
}

export function toggleFavorite(toolId) {
  const favorites = getFavorites();
  const index = favorites.indexOf(toolId);
  if (index === -1) {
    favorites.push(toolId);
  } else {
    favorites.splice(index, 1);
  }
  writeJson(window.localStorage, FAVORITES_KEY, favorites);
  return favorites;
}

export function moveFavorite(toolId, direction) {
  const favorites = getFavorites();
  const index = favorites.indexOf(toolId);
  const target = index + direction;
  if (index === -1 || target < 0 || target >= favorites.length) return favorites;
  [favorites[index], favorites[target]] = [favorites[target], favorites[index]];
  writeJson(window.localStorage, FAVORITES_KEY, favorites);
  return favorites;
}

export function getHistory() {
  return readJson(window.localStorage, HISTORY_KEY, []);
}

export function pushHistory(entry) {
  const history = getHistory();
  history.unshift({ ...entry, timestamp: Date.now() });
  writeJson(window.localStorage, HISTORY_KEY, history.slice(0, HISTORY_LIMIT));
}

export function clearHistory() {
  writeJson(window.localStorage, HISTORY_KEY, []);
}

export function updateLatestHistoryEntry(patch) {
  const history = getHistory();
  if (history.length === 0) return;
  history[0] = { ...history[0], ...patch };
  writeJson(window.localStorage, HISTORY_KEY, history);
}

export function getSessionState() {
  return readJson(window.sessionStorage, SESSION_KEY, {});
}

export function setSessionState(partial) {
  writeJson(window.sessionStorage, SESSION_KEY, { ...getSessionState(), ...partial });
}
