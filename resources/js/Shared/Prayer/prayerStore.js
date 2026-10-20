// One shared clock + prayer schedule for the top bar pill, the dashboard card, the alert layer and the login notice.
// The server sends the schedule and its own clock; the browser keeps time from there (so a wrong PC clock does not matter).
import { reactive, computed, markRaw } from 'vue';

export const prayerState = reactive({ data: null, offset: 0, now: Date.now(), preview: null, drawer: false });

// One adhan player shared by the prayer notice and the top-bar drawer, so it can be stopped from either.
export const adhan = reactive({ playing: false, audio: null, blocked: false });

// The waves of the prayer notice follow the real sound: the playing audio is also routed through an analyser. This is only done when the browser lets the audio
// context run (never when it is suspended, because a suspended context would mute the adhan); otherwise the waves are drawn without the analyser.
let audioCtx = null;
let analyser = null;
let analyserSource = null;
export function adhanAnalyser() { return analyser; }
async function hookAnalyser(el) {
  try {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC || analyser) return;
    audioCtx = audioCtx || new AC();
    if (audioCtx.state !== 'running') { try { await audioCtx.resume(); } catch (e) { /* needs a click first */ } }
    if (audioCtx.state !== 'running' || adhan.audio !== el) return;
    const src = audioCtx.createMediaElementSource(el);
    const an = audioCtx.createAnalyser();
    an.fftSize = 512; an.smoothingTimeConstant = 0.8;
    src.connect(an); an.connect(audioCtx.destination);
    analyserSource = src; analyser = an;
  } catch (e) { analyser = null; }
}

export function playAdhan(force = false) {
  const a = prayerState.data && prayerState.data.adhan;
  if (!a || !a.url || (!a.enabled && !force)) return Promise.resolve();
  if (!adhan.audio) {
    const el = markRaw(new Audio(a.url));
    el.volume = Math.max(0, Math.min(1, (adhan.volume ?? a.volume ?? 70) / 100));
    el.addEventListener('ended', () => { adhan.playing = false; adhan.audio = null; try { if (analyserSource) analyserSource.disconnect(); } catch (e) { /* gone */ } analyser = null; analyserSource = null; });
    el.addEventListener('pause', () => { adhan.playing = false; });
    el.addEventListener('play', () => { adhan.playing = true; adhan.blocked = false; hookAnalyser(el); });
    adhan.audio = el;
  }
  return adhan.audio.play().catch(() => { adhan.playing = false; adhan.blocked = true; }); // the browser may need one click first
}

export function stopAdhan() {
  if (adhan.audio) { adhan.audio.pause(); try { adhan.audio.currentTime = 0; } catch (e) { /* not seekable */ } }
  adhan.audio = null;
  adhan.playing = false;
  try { if (analyserSource) analyserSource.disconnect(); } catch (e) { /* already gone */ }
  analyser = null; analyserSource = null;
}

export function setAdhanVolume(v) {
  adhan.volume = v;
  if (adhan.audio) adhan.audio.volume = Math.max(0, Math.min(1, v / 100));
}

let timer = null;
let pollTimer = null;
export function syncPrayer(data) {
  prayerState.data = data || null;
  prayerState.offset = data && data.server_now ? new Date(data.server_now).getTime() - Date.now() : 0;
  prayerState.now = Date.now() + prayerState.offset;
  if (!timer) timer = setInterval(() => { prayerState.now = Date.now() + prayerState.offset; }, 1000);
  startPolling();
}

// The schedule is loaded with the page. When somebody changes the Prayer Times settings (or the day changes) while a page is open,
// the page would keep the old schedule until it was refreshed, so signed-in pages ask the server for the schedule again every minute
// (a very small request) and when the tab becomes visible again.
function refreshPrayer() {
  const d = prayerState.data;
  if (!d || !('in_scope' in d) || !window.axios || document.hidden || prayerState.preview) return;
  window.axios.get('/prayer-times/state').then(r => {
    if (r.data && r.data.upcoming) {
      prayerState.data = r.data;
      prayerState.offset = new Date(r.data.server_now).getTime() - Date.now();
      prayerState.now = Date.now() + prayerState.offset;
    }
  }).catch(() => {});
}
function startPolling() {
  if (pollTimer || typeof window === 'undefined') return;
  pollTimer = setInterval(refreshPrayer, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshPrayer(); });
}

const ms = iso => new Date(iso).getTime();

// every prayer around now with its alert / prayer / lock / unlock moments (the server sends the next four)
export const events = computed(() => (prayerState.data ? prayerState.data.upcoming.map(e => ({ ...e, t: ms(e.at), alertT: ms(e.alert_at), noticeT: ms(e.notice_at), lockT: ms(e.lock_at), unlockT: ms(e.unlock_at) })) : []));

// where we are: none | alert | prayer | locked
export const phase = computed(() => {
  if (prayerState.preview) return prayerState.preview;
  const now = prayerState.now;
  for (const e of events.value) {
    if (now >= e.lockT && now < e.unlockT) return { phase: 'locked', e };
    if (now >= e.noticeT && now < e.lockT) return { phase: 'prayer', e };
    if (now >= e.alertT && now < e.noticeT) return { phase: 'alert', e };
  }
  return { phase: 'none', e: null };
});

export const nextPrayer = computed(() => events.value.find(e => e.t > prayerState.now) || null);

// today's prayers (not sunrise) with a flag for the one coming next, and the one that has just passed
export const today = computed(() => {
  const d = prayerState.data;
  if (!d) return [];
  const next = nextPrayer.value;
  return d.times.map(t => ({ ...t, t: ms(t.at), isNext: !!(next && t.key === next.key && Math.abs(ms(t.at) - next.t) < 60000), passed: ms(t.at) <= prayerState.now }));
});

export function pad(n) { return String(n).padStart(2, '0'); }
export function countdown(toMs, now = prayerState.now) {
  let s = Math.max(0, Math.floor((toMs - now) / 1000));
  const h = Math.floor(s / 3600); s -= h * 3600;
  const m = Math.floor(s / 60); s -= m * 60;
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}
export function words(toMs, now = prayerState.now) {
  const mins = Math.max(0, Math.round((toMs - now) / 60000));
  const h = Math.floor(mins / 60), m = mins % 60;
  return h > 0 ? `${h}h ${m}m` : `${m}m`;
}
// "13:45" -> "01:45 PM"
export function clock12(hhmm) {
  const [h, m] = hhmm.split(':').map(Number);
  return `${pad(h % 12 || 12)}:${pad(m)} ${h >= 12 ? 'PM' : 'AM'}`;
}
// the time in East Africa (Tanzania) from the shared clock
export function eatClock(withSeconds = true) {
  return new Date(prayerState.now).toLocaleTimeString('en-US', { timeZone: 'Africa/Dar_es_Salaam', hour: '2-digit', minute: '2-digit', second: withSeconds ? '2-digit' : undefined, hour12: true });
}
