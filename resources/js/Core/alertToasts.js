import { reactive } from 'vue';

// The pop-up toasts for new alerts. The bell's poll (IconMenus.vue) hands the newest alerts to showAlertToasts(); AlertToasts.vue draws them.
export const alertToasts = reactive({ list: [] });

const SHOW_MS = 3000;
const MAX = 3;
const seen = new Set();

// desktop and tablet only (a phone keeps just the bell and the chime)
const wide = () => !!window.matchMedia && window.matchMedia('(min-width: 768px)').matches;

export function showAlertToasts(items) {
  if (!wide() || !Array.isArray(items)) return;
  items.forEach(n => {
    if (!n || seen.has(n.id)) return;
    seen.add(n.id);
    if (seen.size > 200) seen.delete(seen.values().next().value);
    alertToasts.list.push({ ...n, key: String(n.id) + '-' + Date.now(), ms: SHOW_MS });
    while (alertToasts.list.length > MAX) alertToasts.list.shift();
  });
}

export function dismissAlertToast(key) {
  const i = alertToasts.list.findIndex(t => t.key === key);
  if (i > -1) alertToasts.list.splice(i, 1);
}

export function dismissAllAlertToasts() {
  alertToasts.list.splice(0, alertToasts.list.length);
}
