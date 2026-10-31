import { reactive } from 'vue';

// The pop-up toasts for new alerts. The bell's poll (IconMenus.vue) hands the newest alerts to showAlertToasts(); AlertToasts.vue draws them.
// `list` is what is on screen; `pending` waits its turn (at most `max` show at one time, the next come as the others leave).
export const alertToasts = reactive({ list: [], pending: [] });

export const TOAST_DEFAULTS = { enabled: true, seconds: 5, max: 3 };
let cfg = { ...TOAST_DEFAULTS };
const seen = new Set();

// the person's own settings (Profile > Toast Alerts), with the defaults for anything not set
export function toastSettings(user) {
  const s = (user && user.extra_attributes && user.extra_attributes.toast_alerts) || {};
  return {
    enabled: s.enabled !== false,
    seconds: Math.min(30, Math.max(2, Number(s.seconds) || TOAST_DEFAULTS.seconds)),
    max: Math.min(5, Math.max(1, Number(s.max) || TOAST_DEFAULTS.max)),
  };
}

// desktop and tablet only (a phone keeps just the bell and the chime)
const wide = () => !!window.matchMedia && window.matchMedia('(min-width: 768px)').matches;

function fill() {
  while (alertToasts.list.length < cfg.max && alertToasts.pending.length) {
    const n = alertToasts.pending.shift();
    alertToasts.list.push({ ...n, key: String(n.id) + '-' + Date.now(), ms: cfg.seconds * 1000 });
  }
}

export function showAlertToasts(items, settings) {
  cfg = { ...TOAST_DEFAULTS, ...(settings || {}) };
  if (!cfg.enabled || !wide() || !Array.isArray(items)) return;
  items.forEach(n => {
    if (!n || seen.has(n.id)) return;
    seen.add(n.id);
    if (seen.size > 200) seen.delete(seen.values().next().value);
    if (alertToasts.pending.length < 30) alertToasts.pending.push(n);
  });
  fill();
}

export function dismissAlertToast(key) {
  const i = alertToasts.list.findIndex(t => t.key === key);
  if (i > -1) alertToasts.list.splice(i, 1);
  fill();
}

// Escape or a click outside: everything on screen goes, and nothing more is shown from the waiting ones
export function dismissAllAlertToasts() {
  alertToasts.pending.splice(0, alertToasts.pending.length);
  alertToasts.list.splice(0, alertToasts.list.length);
}
