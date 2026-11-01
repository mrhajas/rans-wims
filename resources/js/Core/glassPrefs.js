import { reactive } from 'vue';

// The Glass look has one personal setting kept on this device: how strong the glass is (Soft, Medium or Strong). It is changed from the notch on the right edge.
const KEY = 'wims_glass_level';
export const LEVELS = ['soft', 'medium', 'strong'];

function load() {
  try { const v = window.localStorage.getItem(KEY); return LEVELS.includes(v) ? v : 'medium'; } catch (e) { return 'medium'; }
}

const HIDE_KEY = 'wims_glass_autohide';
function loadHide() { try { return window.localStorage.getItem(HIDE_KEY) === '1'; } catch (e) { return false; } }

// `autohide`: the menu hides itself and shows when the pointer rests on its handle (kept on this device; after a reload it starts hidden)
export const glassPrefs = reactive({ level: load(), autohide: loadHide() });

export function setGlassAutohide(on) {
  glassPrefs.autohide = !!on;
  try { window.localStorage.setItem(HIDE_KEY, on ? '1' : '0'); } catch (e) { /* no storage */ }
}

export function setGlassLevel(v) {
  glassPrefs.level = LEVELS.includes(v) ? v : 'medium';
  try { window.localStorage.setItem(KEY, glassPrefs.level); } catch (e) { /* no storage */ }
}

export function cycleGlassLevel() {
  setGlassLevel(LEVELS[(LEVELS.indexOf(glassPrefs.level) + 1) % LEVELS.length]);
}
