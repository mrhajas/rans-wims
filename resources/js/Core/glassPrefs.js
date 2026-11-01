import { reactive } from 'vue';

// The Glass look has one personal setting kept on this device: how strong the glass is (Soft, Medium or Strong). It is changed from the notch on the right edge.
const KEY = 'wims_glass_level';
export const LEVELS = ['soft', 'medium', 'strong'];

function load() {
  try { const v = window.localStorage.getItem(KEY); return LEVELS.includes(v) ? v : 'medium'; } catch (e) { return 'medium'; }
}

export const glassPrefs = reactive({ level: load() });

export function setGlassLevel(v) {
  glassPrefs.level = LEVELS.includes(v) ? v : 'medium';
  try { window.localStorage.setItem(KEY, glassPrefs.level); } catch (e) { /* no storage */ }
}

export function cycleGlassLevel() {
  setGlassLevel(LEVELS[(LEVELS.indexOf(glassPrefs.level) + 1) % LEVELS.length]);
}
