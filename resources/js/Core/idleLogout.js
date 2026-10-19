import { reactive } from 'vue';
import { route } from 'ziggy-js';
import { router } from '@inertiajs/vue3';
import { clearAllStores } from '@/Core/offlineDb';

/**
 * Auto logout after inactivity of the WHOLE login session.
 *  - "Activity" is a real action of the person (mouse, keys, touch, scroll) in ANY tab: the time of the last one is kept in localStorage, which all tabs share.
 *    A tab that is just open, hidden or refreshing itself in the background is not activity.
 *  - Every request to the server carries how long the person has been idle (X-Idle-Ms), so the server keeps the true idle time of the session and signs the
 *    person out itself if the browser was closed or asleep (see App\Http\Middleware\EnforceIdleTimeout).
 *  - The limit comes from the person's own setting (profile page); 0 means "do not log me out".
 */
export const COUNTDOWN_SECONDS = 30; // seconds shown in the warning before auto logout
const IDLE_STORAGE_KEY = 'wims-last-activity';
const LOGOUT_STORAGE_KEY = 'wims-idle-logout';
const CHECK_EVERY_MS = 5000;
const PING_EVERY_MS = 60000;

export const idleLogoutState = reactive({
  visible: false,
  secondsLeft: COUNTDOWN_SECONDS,
});

const state = {
  checkId: null,
  countdownId: null,
  pingId: null,
  loggingOut: false,
  currentComponent: null,
  currentProps: {},
  memLast: Date.now(), // used when localStorage is not available
  lastWrite: 0,
};

const isAuthPage = component => component?.startsWith('Auth/');
const isAuthenticated = () => !!state.currentProps?.auth?.user;
const limitMs = () => Math.max(0, Number(state.currentProps?.idle?.minutes || 0)) * 60 * 1000;
const shouldTrackIdle = () => isAuthenticated() && !isAuthPage(state.currentComponent) && limitMs() > 0;

function lastActivity() {
  let stored = 0;
  try {
    stored = Number(localStorage.getItem(IDLE_STORAGE_KEY)) || 0;
  } catch (e) {
    // storage unavailable: this tab's own clock is all there is
  }

  return Math.max(stored, state.memLast);
}

export function idleMs() {
  return Math.max(0, Date.now() - lastActivity());
}

function recordActivity() {
  const now = Date.now();
  state.memLast = now;
  try {
    localStorage.setItem(IDLE_STORAGE_KEY, String(now));
  } catch (e) {
    // best effort
  }
}

function stopCountdown() {
  if (state.countdownId) {
    clearInterval(state.countdownId);
    state.countdownId = null;
  }
}

function hideWarning() {
  stopCountdown();
  idleLogoutState.visible = false;
}

function toLogin() {
  window.location.href = route('login');
}

function performLogout() {
  if (state.loggingOut) {
    return;
  }
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return; // cannot sign out without the server: tried again when the connection is back
  }
  state.loggingOut = true;
  hideWarning();
  try {
    localStorage.setItem(LOGOUT_STORAGE_KEY, String(Date.now())); // the other tabs of this browser follow
  } catch (e) {
    // best effort
  }
  clearAllStores();

  router.post(route('logout'), {}, {
    onError: toLogin,
    onFinish: () => {
      state.loggingOut = false;
    },
  });
}

function secondsLeftNow() {
  return Math.max(0, Math.ceil((limitMs() - idleMs()) / 1000));
}

function showWarning() {
  idleLogoutState.secondsLeft = Math.min(COUNTDOWN_SECONDS, secondsLeftNow());
  idleLogoutState.visible = true;
  if (!state.countdownId) {
    state.countdownId = setInterval(() => {
      idleLogoutState.secondsLeft = secondsLeftNow();
      if (idleLogoutState.secondsLeft <= 0) {
        performLogout();
      }
    }, 1000);
  }
}

// One check every few seconds, worked out from the clock (so a sleeping laptop or a throttled background tab is still right when it wakes up).
function check() {
  if (!shouldTrackIdle()) {
    hideWarning();
    return;
  }
  const left = limitMs() - idleMs();
  if (left <= 0) {
    performLogout();
  } else if (left <= COUNTDOWN_SECONDS * 1000) {
    showWarning();
  } else if (idleLogoutState.visible) {
    hideWarning(); // activity in another tab: the session is alive
  }
}

function handleLocalActivity() {
  if (idleLogoutState.visible) {
    return; // while the warning is up only an explicit "stay signed in" counts
  }
  const now = Date.now();
  if (now - state.lastWrite < 1000) {
    return;
  }
  state.lastWrite = now;
  recordActivity();
}

function handleStorage(event) {
  if (event.key === LOGOUT_STORAGE_KEY && event.newValue && isAuthenticated()) {
    clearAllStores();
    toLogin();
  } else if (event.key === IDLE_STORAGE_KEY) {
    check();
  }
}

// Keeps the server's idea of the session in step while the person is really working (the request itself carries X-Idle-Ms).
function keepAlive() {
  if (!shouldTrackIdle() || idleMs() > PING_EVERY_MS - 5000 || !window.axios) {
    return;
  }
  window.axios.post(route('idle.ping')).catch(() => {});
}

export function stayLoggedIn() {
  hideWarning();
  state.lastWrite = Date.now();
  recordActivity();
  keepAlive();
}

export function logoutNow() {
  performLogout();
}

function installRequestHeader() {
  if (!window.axios) {
    return;
  }
  window.axios.interceptors.request.use(config => {
    try {
      const url = new URL(config.url || '', window.location.href);
      if (url.origin === window.location.origin) {
        config.headers = config.headers || {};
        config.headers['X-Idle-Ms'] = String(Math.round(idleMs()));
      }
    } catch (e) {
      // not a URL we can read: sent without the header
    }

    return config;
  });
  window.axios.interceptors.response.use(
    response => response,
    error => {
      if (error?.response?.status === 401 && error.response.data?.idle_logout) {
        toLogin();
      }

      return Promise.reject(error);
    }
  );
}

export function initIdleLogout(initialPage) {
  state.currentComponent = initialPage?.component;
  state.currentProps = initialPage?.props || {};

  installRequestHeader();
  recordActivity(); // opening or reloading a page is something the person did

  ['mousemove', 'mousedown', 'keydown', 'scroll', 'wheel', 'touchstart', 'click'].forEach(eventName => {
    window.addEventListener(eventName, handleLocalActivity, { passive: true });
  });
  window.addEventListener('storage', handleStorage);
  window.addEventListener('online', check);
  window.addEventListener('focus', check);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      check();
    }
  });

  // Pages change on their own (polling, partial reloads): that is not activity, it only refreshes the person's limit from the props.
  document.addEventListener('inertia:navigate', event => {
    const page = event?.detail?.page;
    if (!page) return;
    state.currentComponent = page.component;
    state.currentProps = page.props || {};
    check();
  });

  state.checkId = setInterval(check, CHECK_EVERY_MS);
  state.pingId = setInterval(keepAlive, PING_EVERY_MS);
  check();

  // Manual trigger for testing: open devtools console and run window.triggerIdleLogoutWarning()
  window.triggerIdleLogoutWarning = showWarning;
}
