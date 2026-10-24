import { reactive } from 'vue';
import i18n from '@/Core/i18n';

// The small centred "working" indicator: it appears when a save, update, add or delete is sent, turns into a "done" (or "problem") label with the server's
// message, and fades away. It replaces the old corner notification. Reads are not shown (the top progress line already covers moving between pages).
export const progress = reactive({ state: 'idle', label: '', text: '', kind: '', done: '', anim: '', long: false });

const t = key => {
  try {
    return i18n.global.t(key);
  } catch (e) {
    return key;
  }
};

const SHOW_AFTER_MS = 140; // a save that answers faster than this never shows a spinner
const DONE_MS = 2200; // the tick stays long enough to be seen
const ERROR_MS = 4800;

let showTimer = null;
let hideTimer = null;
let started = 0;
let outcome = null;
let lastKey = '';
let lastAt = 0;
let activeVisits = 0;
let safetyTimer = null;
let current = null; // the write that is being sent right now

// while a long job runs (backup, restore) a refresh or closing the tab would break it: the browser asks first
const guard = e => {
  e.preventDefault();
  e.returnValue = '';
  return '';
};
let guarded = false;
function setGuard(on) {
  if (on === guarded || typeof window === 'undefined') return;
  guarded = on;
  window[on ? 'addEventListener' : 'removeEventListener']('beforeunload', guard);
}

/** True while a save is being sent: the result is then shown by the visit itself, not by the page's flash message. */
export const isBusy = () => !!current;

const workingLabel = method => ({ delete: t('Deleting...'), put: t('Updating...'), patch: t('Updating...') }[method] || t('Saving...'));
const doneLabel = method => ({ delete: t('Deleted'), put: t('Updated'), patch: t('Updated') }[method] || t('Saved'));

function clearTimers() {
  setGuard(false);
  clearTimeout(showTimer);
  clearTimeout(hideTimer);
  clearTimeout(safetyTimer);
  showTimer = hideTimer = safetyTimer = null;
}

export function begin(method, label, long = false, done = '', anim = '') {
  activeVisits += 1;
  outcome = null;
  started = Date.now();
  clearTimers();
  progress.label = label || workingLabel(method);
  progress.done = done || '';
  progress.long = !!long;
  setGuard(!!long);
  progress.anim = anim || ''; // 'backup' or 'restore': files flying into / out of a database instead of the small spinner
  progress.text = '';
  progress.kind = method;
  showTimer = setTimeout(() => {
    progress.state = 'working';
  }, SHOW_AFTER_MS);
  // never leave the page covered: a request that never answers lets go after a minute
  // (a long job such as restoring the database is given 30 minutes)
  safetyTimer = setTimeout(() => {
    progress.state = 'idle';
    current = null;
    setGuard(false);
  }, long ? 1800000 : 60000);
}

/** For work that does not go through a form post (a file download, a fetch): show the working pill, then call endTask. */
export function beginTask(label, done = '', anim = '') {
  begin('post', label, true, done, anim);
}

export function endTask(ok, text, done) {
  if (ok) result('success', text || '', done || progress.done || undefined);
  else result('error', text || '');
}

/** Shows the result label (kind: 'success' or 'error') for a moment. The same label twice within a second is shown once. */
export function result(kind, text, fallback) {
  const key = kind + '|' + text;
  const now = Date.now();
  if (key === lastKey && now - lastAt < 1000) return;
  lastKey = key;
  lastAt = now;
  clearTimers();
  progress.state = kind === 'error' ? 'error' : 'done';
  progress.anim = '';
  progress.long = false;
  progress.label = kind === 'error' ? t('Not saved') : fallback || t('Saved');
  progress.text = text || '';
  hideTimer = setTimeout(() => {
    progress.state = 'idle';
  }, kind === 'error' ? ERROR_MS : DONE_MS);
}

export function dismiss() {
  current = null;
  progress.anim = '';
  progress.long = false;
  clearTimers();
  progress.state = 'idle';
}

const strip = s => String(s || '').replace(/<[^>]*>/g, '');

export function initSaveProgress(router, getPage) {
  const isQuiet = visit => !!(visit && visit.headers && visit.headers['X-Quiet']);
  const writes = visit => visit && visit.method && visit.method !== 'get' && !isQuiet(visit);

  router.on('start', event => {
    const visit = event.detail.visit;
    if (!writes(visit)) return;
    current = visit;
    const h = visit.headers || {};
    begin(visit.method, h['X-Progress-Label'], !!h['X-Progress-Long'], h['X-Progress-Done'], h['X-Progress-Anim']);
  });
  router.on('success', event => {
    if (!current) return;
    const flash = (event.detail.page.props || {}).flash || {};
    outcome = flash.error ? { kind: 'error', text: strip(flash.error) } : { kind: 'success', text: flash.message || '' };
  });
  router.on('error', event => {
    if (!current) return;
    const n = Object.keys(event.detail.errors || {}).length;
    outcome = { kind: 'error', text: n === 1 ? t('There is one form error.') : t('There are :n form errors.').replace(':n', n) };
  });
  router.on('invalid', () => {
    if (current) dismiss();
    current = null;
  });
  router.on('exception', () => {
    if (current) dismiss();
    current = null;
  });
  router.on('finish', event => {
    const visit = event.detail.visit;
    // (Inertia hands over a copy of the visit here, so it is told apart by being a write, not by identity)
    if (!current || !writes(visit)) {
      return;
    }
    const method = current.method;
    current = null;
    activeVisits = Math.max(0, activeVisits - 1);
    if (visit.cancelled || visit.interrupted) {
      if (!activeVisits) dismiss();
      return;
    }
    if (!outcome) {
      dismiss();
      return;
    }
    result(outcome.kind, outcome.text, outcome.kind === 'success' ? progress.done || doneLabel(method) : undefined);
  });
}
