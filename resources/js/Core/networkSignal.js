// How good the connection of this device is right now, as a percentage that follows what is measured: the time a tiny request to WIMS takes to come back (and how much that time
// jumps about), how many requests are lost, and what the browser says about the link (speed class, and, where the browser tells, Wi-Fi / cable / mobile data).
// Nothing is guessed from the picture of the icon: the number is the measurement. The request is a two-byte static file the web server answers by itself (no PHP, no
// session, no database), so the time is the time of the connection and not of the application; if that file is missing the cheap health call is used instead.
// The first readings come quickly: three requests leave at once when the page opens, then one every 1.5 seconds until there are a few answers, then every 15 seconds
// while the page is in front, every 2 seconds while the status window is open, and none while the tab is hidden.
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
  trend: 'flat', // 'up' (getting stronger) | 'down' (getting weaker) | 'flat'
  changedAt: 0, // when the level last changed (the window flashes)
});
const pHist = [];

const KEEP = 24;
let timer = null;
let fast = 0;
let started = false;
let useStatic = true; // false once the static file answered with an error: the health route measures instead

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
  // while the status window is open only the last few answers count, so the reading follows the person as they walk closer to or away from the router
  const win = fast > 0 ? net.samples.slice(-6) : net.samples;
  const ok = win.filter(v => typeof v === 'number');
  const lost = win.length - ok.length;
  net.loss = win.length ? Math.round((lost / win.length) * 100) : 0;
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
  const before = net.level;
  net.level = net.percent >= 85 ? 'excellent' : net.percent >= 65 ? 'good' : net.percent >= 45 ? 'fair' : net.percent >= 25 ? 'weak' : 'poor';
  if (before !== net.level && before !== 'unknown') net.changedAt = Date.now();
  // getting stronger or weaker: the latest reading against the average of the three before it
  if (pHist.length >= 3) {
    const prev = pHist.slice(-3).reduce((a, b) => a + b, 0) / 3;
    net.trend = net.percent - prev > 4 ? 'up' : net.percent - prev < -4 ? 'down' : 'flat';
  }
  pHist.push(net.percent);
  if (pHist.length > 8) pHist.shift();
}

async function ping(limit = 8000) {
  const t0 = performance.now();
  let ms = null;
  try {
    const ctrl = new AbortController();
    const stop = setTimeout(() => ctrl.abort(), limit);
    const url = (useStatic ? '/ping.txt' : '/offline/health') + '?_=' + Date.now() + Math.floor(Math.random() * 1000);
    const res = await fetch(url, { cache: 'no-store', credentials: useStatic ? 'omit' : 'same-origin', signal: ctrl.signal, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: useStatic ? 'text/plain' : 'application/json' } });
    clearTimeout(stop);
    if (useStatic && !res.ok) {
      // the static file is not on this server: measure with the health route from now on (and measure this one again at once)
      useStatic = false;
      return ping(limit);
    }
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
  // while there are only a few answers the next one is quick, so the first number settles within seconds
  const wait = fast > 0 ? 2000 : net.samples.length < 4 ? 1500 : 15000;
  timer = setTimeout(async () => {
    if (typeof document === 'undefined' || !document.hidden) await ping();
    schedule();
  }, wait);
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
  // three requests at once: the first answer gives the first number, the others make it steadier a moment later
  ping(4000);
  setTimeout(() => ping(4000), 250);
  setTimeout(() => ping(4000), 600);
  schedule();
}

/** The status window asks for quicker measuring while it is open. */
export function watchNetworkClosely(on) {
  fast = Math.max(0, fast + (on ? 1 : -1));
  if (on) { ping(); schedule(); }
}
