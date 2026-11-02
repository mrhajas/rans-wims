// How good the connection of this device is right now, as a percentage that follows what is measured: the time a tiny request to WIMS takes to come back (and how much that time
// jumps about), how many requests are lost, and what the browser says about the link (speed class, and, where the browser tells, Wi-Fi / cable / mobile data).
// Nothing is guessed from the picture of the icon: the number is the measurement. The request is the same cheap health call WIMS already makes every minute; it is sent every
// 15 seconds while the page is in front, every 3 seconds while the status window is open, and not at all while the tab is hidden.
import { reactive } from 'vue';

export const net = reactive({
  percent: null, // 0-100, null until the first answer
  rtt: null, // typical answer time in ms
  jitter: 0,
  loss: 0, // % of the last requests that did not come back
  downlink: null, // Mbit/s the browser estimates
  effective: null, // '4g', '3g' ...
  type: 'unknown', // 'wifi' | 'ethernet' | 'cellular' | 'other' | 'unknown'
  samples: [], // last answer times (null = lost)
  level: 'unknown', // excellent | good | fair | weak | poor | offline | unknown
});

const KEEP = 24;
let timer = null;
let fast = 0;
let started = false;

const conn = () => (typeof navigator !== 'undefined' ? navigator.connection || navigator.mozConnection || navigator.webkitConnection : null);

export const LEVELS = {
  excellent: { min: 85, color: '#16a34a' },
  good: { min: 65, color: '#65a30d' },
  fair: { min: 45, color: '#f59e0b' },
  weak: { min: 25, color: '#f97316' },
  poor: { min: 0, color: '#ef4444' },
  offline: { min: -1, color: '#64748b' },
  unknown: { min: -1, color: '#94a3b8' },
};

function readLink() {
  const c = conn();
  net.downlink = c && typeof c.downlink === 'number' ? c.downlink : null;
  net.effective = c && c.effectiveType ? c.effectiveType : null;
  const t = c && c.type ? String(c.type).toLowerCase() : '';
  net.type = ['wifi', 'ethernet', 'cellular'].includes(t) ? t : t && t !== 'unknown' && t !== 'none' ? 'other' : 'unknown';
}

function compute() {
  const ok = net.samples.filter(v => typeof v === 'number');
  const lost = net.samples.length - ok.length;
  net.loss = net.samples.length ? Math.round((lost / net.samples.length) * 100) : 0;
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    net.percent = 0;
    net.level = 'offline';
    return;
  }
  if (!ok.length) {
    net.percent = net.samples.length ? 0 : null;
    net.level = net.samples.length ? 'poor' : 'unknown';
    return;
  }
  const sorted = [...ok].sort((a, b) => a - b);
  const mid = sorted[Math.floor(sorted.length / 2)];
  const mean = ok.reduce((s, v) => s + v, 0) / ok.length;
  const sd = Math.sqrt(ok.reduce((s, v) => s + (v - mean) ** 2, 0) / ok.length);
  net.rtt = Math.round(mid);
  net.jitter = Math.round(sd);
  // 40 ms or less is full marks, 1.2 s or more is none; jumpiness and lost requests take points off; a slow link (under 1 Mbit/s by the browser's estimate) takes some more
  let p = 100 - ((Math.max(40, Math.min(1200, mid)) - 40) / 1160) * 100;
  p -= Math.min(20, (sd / 250) * 20);
  p -= net.loss * 0.7;
  if (net.downlink !== null && net.downlink < 1) p -= (1 - net.downlink) * 12;
  net.percent = Math.max(0, Math.min(100, Math.round(p)));
  net.level = net.percent >= 85 ? 'excellent' : net.percent >= 65 ? 'good' : net.percent >= 45 ? 'fair' : net.percent >= 25 ? 'weak' : 'poor';
}

async function ping() {
  const t0 = performance.now();
  let ms = null;
  try {
    const ctrl = new AbortController();
    const stop = setTimeout(() => ctrl.abort(), 8000);
    await fetch('/offline/health?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin', signal: ctrl.signal, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
    clearTimeout(stop);
    ms = performance.now() - t0; // any answer, even an error page, shows the link works
  } catch (e) {
    ms = null;
  }
  net.samples = [...net.samples, ms].slice(-KEEP);
  readLink();
  compute();
}

function schedule() {
  clearTimeout(timer);
  timer = setTimeout(async () => {
    if (typeof document === 'undefined' || !document.hidden) await ping();
    schedule();
  }, fast > 0 ? 3000 : 15000);
}

/** Starts measuring (once). */
export function startNetworkSignal() {
  if (started || typeof window === 'undefined') return;
  started = true;
  readLink();
  const c = conn();
  if (c && c.addEventListener) c.addEventListener('change', () => { readLink(); compute(); });
  window.addEventListener('online', () => { ping(); });
  window.addEventListener('offline', () => { net.samples = [...net.samples, null].slice(-KEEP); compute(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) ping(); });
  ping();
  schedule();
}

/** The status window asks for quicker measuring while it is open. */
export function watchNetworkClosely(on) {
  fast = Math.max(0, fast + (on ? 1 : -1));
  if (on) { ping(); schedule(); }
}
