// Install WIMS as an app (PWA): one shared place that knows whether the app is already installed on this device, whether the browser
// can show its own install prompt, and what to tell people whose browser cannot (iPhone, Firefox, Safari...).
//   standalone  = this window IS the installed app
//   installed   = WIMS is installed on this device (seen installed here, or the browser says so) but this is a normal browser tab
//   canPrompt   = the browser handed us its install prompt, which also proves WIMS is NOT installed (browsers only offer it then)
import { reactive } from 'vue';

const FLAG = 'wims-pwa-installed';
const MODES = ['standalone', 'fullscreen', 'minimal-ui', 'window-controls-overlay'];

export const pwa = reactive({ ready: false, standalone: false, installed: false, canPrompt: false });
let started = false;

const store = {
  get() { try { return window.localStorage.getItem(FLAG) === '1'; } catch (e) { return false; } },
  set(on) { try { on ? window.localStorage.setItem(FLAG, '1') : window.localStorage.removeItem(FLAG); } catch (e) { /* no storage */ } },
};

const isStandalone = () => MODES.some(m => window.matchMedia('(display-mode: ' + m + ')').matches) || window.navigator.standalone === true;

function offered(event) {
  event.preventDefault();
  window.__wimsInstallPrompt = event;
  pwa.canPrompt = true;
  // the browser only offers its prompt when the app is not installed, so a leftover "installed" note is out of date (app was removed)
  pwa.installed = false;
  store.set(false);
}

function markInstalled() {
  store.set(true);
  window.__wimsInstallPrompt = null;
  pwa.canPrompt = false;
  pwa.installed = true;
}

export function startPwaWatch() {
  if (started || typeof window === 'undefined') return;
  started = true;
  pwa.standalone = isStandalone();
  if (pwa.standalone) store.set(true); // storage is shared with the browser tab on most systems, so the tab learns it too
  pwa.installed = pwa.standalone || store.get();
  if (window.__wimsInstallPrompt) offered(window.__wimsInstallPrompt);

  window.addEventListener('beforeinstallprompt', offered);
  window.addEventListener('wims-install-prompt-ready', () => { if (window.__wimsInstallPrompt) offered(window.__wimsInstallPrompt); });
  window.addEventListener('appinstalled', markInstalled);
  MODES.forEach(m => {
    const q = window.matchMedia('(display-mode: ' + m + ')');
    if (q.addEventListener) q.addEventListener('change', () => { pwa.standalone = isStandalone(); if (pwa.standalone) markInstalled(); });
  });

  // Chrome / Edge can say whether this site's app is installed even from a normal tab
  try {
    if (navigator.getInstalledRelatedApps) {
      navigator.getInstalledRelatedApps().then(apps => { if (apps && apps.length && !pwa.canPrompt) { pwa.installed = true; store.set(true); } }).catch(() => {});
    }
  } catch (e) { /* not supported */ }
  pwa.ready = true;
}

/** What kind of device and browser this is (for the instructions). */
export function environment() {
  const ua = window.navigator.userAgent || '';
  const touchMac = /Macintosh/.test(ua) && window.navigator.maxTouchPoints > 1;
  const ios = /iPhone|iPad|iPod/.test(ua) || touchMac;
  const android = /Android/.test(ua);
  const browser = /SamsungBrowser/i.test(ua) ? 'samsung'
    : /FxiOS|Firefox/i.test(ua) ? 'firefox'
    : /EdgiOS|EdgA|Edg\//i.test(ua) ? 'edge'
    : /OPR|Opera|OPiOS/i.test(ua) ? 'opera'
    : /CriOS|Chrome/i.test(ua) ? 'chrome'
    : /Safari/i.test(ua) ? 'safari' : 'other';
  return { ios, android, mobile: ios || android, browser, secure: window.isSecureContext };
}

/**
 * The how-to for browsers that cannot show an install prompt on their own: a title, what kind of device it is, and the steps (each with
 * an icon name the dialog draws). The caller translates every text.
 */
export function installGuide() {
  const e = environment();
  const g = (title, device, steps, where) => ({ title, device, where, steps: steps.map(([icon, text]) => ({ icon, text })) });
  if (!e.secure) return g('Secure connection needed', 'laptop', [['lock', 'Installing the app needs a secure (https) address. Open WIMS with its https link and try again.']], 'This browser');
  if (e.ios) {
    return e.browser === 'safari'
      ? g('Add WIMS to your home screen', 'phone', [['share', 'Tap the Share button (the square with an arrow) in Safari.'], ['plus', 'Scroll down and tap "Add to Home Screen".'], ['tick', 'Tap "Add". WIMS now opens from your home screen like an app.']], 'iPhone / iPad · Safari')
      : g('Open WIMS in Safari first', 'phone', [['compass', 'On iPhone and iPad, apps can only be added from Safari. Open this same page in Safari.'], ['share', 'Tap the Share button, then "Add to Home Screen".']], 'iPhone / iPad');
  }
  if (e.browser === 'firefox') {
    return e.android
      ? g('Install WIMS from Firefox', 'phone', [['dots', 'Tap the menu (three dots).'], ['download', 'Tap "Install" (or "Add to Home screen").']], 'Android · Firefox')
      : g('Use Chrome or Edge to install', 'laptop', [['compass', 'Firefox on a computer cannot install web apps.'], ['download', 'Open WIMS in Chrome or Edge, and install it from there.']], 'Computer · Firefox');
  }
  if (e.browser === 'safari') return g('Add WIMS to your Dock', 'laptop', [['menu', 'In Safari open the File menu and choose "Add to Dock" (macOS Sonoma or newer).'], ['compass', 'On an older Mac, open WIMS in Chrome or Edge and install it there.']], 'Mac · Safari');
  if (e.browser === 'samsung') return g('Install WIMS', 'phone', [['menu', 'Tap the menu (three lines) at the bottom.'], ['plus', 'Tap "Add page to" and choose "Home screen" (or "Install").']], 'Samsung Internet');
  if (e.android) return g('Install WIMS', 'phone', [['dots', 'Tap the browser menu (three dots).'], ['download', 'Tap "Install app" or "Add to Home screen".']], 'Android');
  return g('Install WIMS', 'laptop', [['install', 'Look for the install icon at the right end of the address bar and click it.'], ['dots', 'Or open the browser menu (three dots) and choose "Install WIMS" (in Chrome it can be under "Cast, save and share").']], 'Computer');
}

/**
 * Starts the install. Resolves to one of:
 *   'standalone' | 'installed'  nothing to do, the app is already there
 *   'accepted' | 'dismissed'    the browser's own prompt was shown
 *   'manual'                    this browser needs the steps from manualSteps()
 */
export async function installApp() {
  startPwaWatch();
  if (pwa.standalone) return 'standalone';
  const event = window.__wimsInstallPrompt;
  if (event && pwa.canPrompt) {
    event.prompt();
    let choice = null;
    try { choice = await event.userChoice; } catch (e) { /* prompt closed */ }
    window.__wimsInstallPrompt = null;
    pwa.canPrompt = false; // a prompt can be shown once; the browser offers a new one later if the app is still not installed
    if (choice && choice.outcome === 'accepted') { markInstalled(); return 'accepted'; }
    return 'dismissed';
  }
  if (pwa.installed) return 'installed';
  return 'manual';
}
