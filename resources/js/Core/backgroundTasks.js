import { reactive } from 'vue';

// Background Tasks (long READ-ONLY reports made by a separate process). One shared state, drawn by Shared/BackgroundTaskToasts.vue (mounted once, outside the
// page tree, so a task keeps showing while the person moves between pages). The browser asks the server about its tasks once when a page opens and then every
// few seconds ONLY while a task is running and the tab is in front; the server decides everything (who may start what, whether the same job is already running).
export const bg = reactive({ tasks: [], on: true, failedToReach: false });

let timer = null;
let starting = false;
let booted = false;
let bootedFor = null;

const running = t => t.status === 'queued' || t.status === 'running';

function schedule() {
  clearTimeout(timer);
  timer = null;
  if (!bg.on || !bg.tasks.some(running)) return;
  timer = setTimeout(() => {
    if (typeof document !== 'undefined' && document.hidden) return schedule(); // a hidden tab does not ask
    refresh();
  }, 2500);
}

export async function refresh() {
  if (!bg.on) return;
  try {
    const { data } = await window.axios.get(window.route('background-tasks.active'));
    const before = bg.tasks;
    bg.tasks = data.tasks || [];
    bg.failedToReach = false;
    if (data.enabled === false) bg.on = false;
    // a task that was running and has now ended is announced once (the toast turns into its "ready" or "failed" card)
    bg.tasks.filter(t => !running(t) && before.some(b => b.id === t.id && running(b))).forEach(t => notifyEnded(t));
  } catch (e) {
    const status = e && e.response && e.response.status;
    if (status === 401 || status === 403 || status === 419) bg.on = false; // signed out, or the feature is switched off for this person
    else bg.failedToReach = true;
  }
  schedule();
}

const listeners = [];
export function onTaskEnded(fn) { listeners.push(fn); }
function notifyEnded(t) { listeners.forEach(fn => { try { fn(t); } catch (e) { /* a listener must never stop the others */ } }); }

/** Called once when the app starts: looks at the person's tasks (cheap) and keeps looking while one is running. */
export function initBackgroundTasks(enabled, userId = null) {
  // called at start and after every page change: it starts only once per signed-in person, and only with the feature on.
  // A different person (or nobody) on the same open tab never sees the previous person's cards.
  if (booted && String(bootedFor) !== String(userId)) {
    clearTimeout(timer);
    timer = null;
    bg.tasks = [];
    bg.on = false;
    booted = false;
  }
  if (booted || !enabled) return;
  booted = true;
  bootedFor = userId;
  bg.on = true;
  setTimeout(refresh, 1200);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && bg.tasks.some(running)) refresh(); });
}

/** True when a task of this kind is queued or running (the page's button then says "Already running"). */
export function isTaskRunning(kind) {
  return bg.on && bg.tasks.some(t => t.kind === kind && running(t));
}

function say(vm, type, text) {
  if (vm && vm.$page && vm.$page.props && vm.$page.props.flash) vm.$page.props.flash[type] = text;
}

/**
 * Starts a report in the background. Returns true when the matter is handled (started, or already running, or refused with a message the person has been shown),
 * and false when the page should make the report the usual way (the feature is off, or the server could not be reached).
 */
export async function startBackgroundTask(vm, kind, params = {}) {
  if (!bg.on || starting) return !bg.on ? false : true;
  starting = true;
  try {
    const { data } = await window.axios.post(window.route('background-tasks.store'), { kind, params });
    upsert(data.task);
    schedule();
    if (data.already_running) say(vm, 'message', data.message);
    return true;
  } catch (e) {
    const r = e && e.response;
    if (!r) return false; // no answer: the usual way still works
    if (r.status === 409 && r.data && r.data.disabled) { bg.on = false; return false; }
    if (r.status >= 500 || r.status === 419) return false;
    // 403, 422, 423: the person is told why (their permission, a choice that is not valid, Login Logs locked again)
    const first = r.data && r.data.errors ? Object.values(r.data.errors)[0] : null;
    const msg = (Array.isArray(first) ? first[0] : first) || (r.data && r.data.message) || (vm && vm.$t ? vm.$t('This report could not be started.') : 'This report could not be started.');
    say(vm, 'error', msg);
    return true;
  } finally {
    starting = false;
  }
}

function upsert(task) {
  if (!task) return;
  const i = bg.tasks.findIndex(t => t.id === task.id);
  if (i >= 0) bg.tasks.splice(i, 1, task);
  else bg.tasks.push(task);
}

export async function dismissTask(id) {
  bg.tasks = bg.tasks.filter(t => t.id !== id);
  try { await window.axios.post(window.route('background-tasks.dismiss', id)); } catch (e) { /* the card is gone from this page either way */ }
}
