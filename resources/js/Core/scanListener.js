// The background scanner. A handheld scanner types like a keyboard: a code arrives as a quick burst of keys ended by Enter. One listener (installed once,
// no timers, no polling, no requests until a scan happens) recognises such a burst when nothing that takes text has the cursor, and then
//   1. gives the code to the scan field of the page, if it has one (Check-in, Check-out, Transfer...), or
//   2. asks the server once what the code is (a WIMS QR code, an item barcode, an ID or pass number) and opens that record in a new tab.
// A person typing is never that fast, and a field that takes text keeps its own keys.

import i18n from './i18n';

const GAP = 100; // ms between two keys of one scan (a slow wireless scanner can be near 60)
const MIN = 4; // keys in a scan
let installed = false;
let buf = '';
let streak = 0;
let last = 0;

// the scan fields of the page (AutoComplete with `scan`) register here
export const scanFields = new Set();

function takesText(el) {
  if (!el || el === document.body) return false;
  const tag = el.tagName;
  if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (el.isContentEditable) return true;
  if (tag === 'INPUT') return !['button', 'submit', 'checkbox', 'radio', 'range', 'file', 'color', 'reset', 'image'].includes((el.type || 'text').toLowerCase());
  return false;
}

function toast(text, tone) {
  let el = document.getElementById('wims-scan-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'wims-scan-toast';
    el.setAttribute('role', 'status');
    el.style.cssText = 'position:fixed;left:50%;bottom:26px;transform:translate(-50%,20px);z-index:100001;max-width:min(92vw,460px);padding:11px 18px;border-radius:16px;font:700 14px/1.35 system-ui,sans-serif;color:#fff;box-shadow:0 18px 40px -16px rgba(15,37,64,.7);opacity:0;transition:opacity .2s,transform .25s;pointer-events:none;text-align:center';
    document.body.appendChild(el);
  }
  el.textContent = text;
  el.style.background = tone === 'ok' ? '#15803d' : tone === 'warn' ? '#b45309' : '#be123c';
  el.style.opacity = '1';
  el.style.transform = 'translate(-50%,0)';
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translate(-50%,20px)'; }, 2600);
}

// a field of the page that is on screen (the newest one first: Transfer keeps its waiting row last)
function pageField() {
  const all = [...scanFields].filter(f => f.$refs && f.$refs.input && f.$refs.input.getClientRects().length);
  return all.length ? all[all.length - 1] : null;
}

async function open(code) {
  // the app writes placeholders as :name
  const t = (k, p) => Object.entries(p || {}).reduce((out, [n, v]) => out.split(':' + n).join(v), String(i18n.global.t(k)));
  try {
    const { data } = await window.axios.post(window.route('scan.resolve'), { code }, { headers: { 'X-Quiet': '1' } });
    if (data.status === 'allowed' && data.go) {
      // the tab is opened by a key press a moment ago: the browser still allows it
      const w = window.open(data.go, '_blank', 'noopener');
      if (!w) toast(t('Allow pop-ups for WIMS to open scanned records in a new tab.'), 'warn');
      else toast((data.kind ? data.kind + ': ' : '') + t('opened in a new tab'), 'ok');
    } else if (data.status === 'no_permission') toast(t('You do not have permission to open this :kind.', { kind: (data.kind || t('record')).toLowerCase() }), 'warn');
    else if (data.status === 'not_found' || data.status === 'invalid') toast(t('No record matches the code :code.', { code }), 'bad');
    else toast(t('This code cannot be opened (:s).', { s: data.status }), 'warn');
  } catch (e) {
    toast(t('The code could not be checked. Check your connection and try again.'), 'bad');
  }
}

function onKey(e) {
  if (e.ctrlKey || e.metaKey || e.altKey || e.isComposing) return;
  const now = performance.now();
  if (e.key === 'Enter' || e.key === 'Tab') {
    const code = buf;
    const burst = streak >= MIN && now - last < 200 && code.length >= MIN;
    buf = '';
    streak = 0;
    if (!burst || !document.querySelector('.app-shell')) return;
    if (takesText(e.target)) return; // a field that takes text, and is not a scan field, keeps what is typed in it
    e.preventDefault(); // a focused button must not be pressed by the Enter of a scan
    e.stopPropagation();
    const field = pageField();
    if (field) {
      field.runScan(code, true);
      return;
    }
    open(code);
    return;
  }
  if (!e.key || e.key.length !== 1) return;
  if (now - last > GAP) {
    buf = '';
    streak = 0;
  }
  buf += e.key;
  streak++;
  last = now;
  // the keys of a scan that lands on nothing must not trigger the shortcuts of the page
  if (streak >= 3 && !takesText(e.target)) e.stopPropagation();
}

export function installScanListener() {
  if (installed) return;
  installed = true;
  document.addEventListener('keydown', onKey, true);
}
