import { reactive } from 'vue';

// State of the "making an ID" animation (shown by Pages/IdPasses/Parts/IdMakeOverlay.vue). One shared object, so any page can start it: issuing a card, printing, downloading a PDF, a batch.
// The animation always plays at least MIN ms so it reads as a moment and never as a flash; the file itself is opened/downloaded the moment it is ready after that.
export const idMake = reactive({ open: false, phase: 'idle', mode: 'issue', title: '', color: '#1565c0', count: 1, percent: null, done: 0, total: 0, message: '', openUrl: '', hideable: false, startedAt: 0 });

const MIN = 1500;
let closeTimer = null;
let doneTimer = null;
const sleep = ms => new Promise(r => setTimeout(r, ms));

export function idMakeStart(o = {}) {
  clearTimeout(closeTimer); clearTimeout(doneTimer);
  Object.assign(idMake, { open: true, phase: 'working', mode: 'issue', title: '', color: '#1565c0', count: 1, percent: null, done: 0, total: 0, message: '', openUrl: '', hideable: false, startedAt: Date.now() }, o);
}

export function idMakeProgress(percent, done = 0, total = 0) {
  if (!idMake.open || idMake.phase !== 'working') return;
  idMake.percent = percent; idMake.done = done; idMake.total = total;
}

export function idMakeClose() {
  clearTimeout(closeTimer); clearTimeout(doneTimer);
  idMake.open = false; idMake.phase = 'idle';
  if (idMake.openUrl) { const u = idMake.openUrl; idMake.openUrl = ''; setTimeout(() => URL.revokeObjectURL(u), 60000); }
}

export function idMakeDone(message = '', o = {}) {
  const wait = Math.max(0, MIN - (Date.now() - idMake.startedAt));
  clearTimeout(doneTimer);
  doneTimer = setTimeout(() => {
    if (!idMake.open) return;
    Object.assign(idMake, { phase: 'done', message, percent: idMake.percent === null ? null : 100 }, o);
    if (!idMake.openUrl) closeTimer = setTimeout(idMakeClose, 1700);
  }, wait);
}

export function idMakeFail(message) {
  clearTimeout(doneTimer);
  Object.assign(idMake, { open: true, phase: 'error', message: message || '' });
}

// the message of a failed request that was asked for as a file (the error body is a Blob that holds JSON)
async function errorText(e, fallback) {
  try {
    const d = e && e.response && e.response.data;
    if (d instanceof Blob) {
      const j = JSON.parse(await d.text());
      return j.message || Object.values(j.errors || {}).flat().join(' ') || fallback;
    }
    if (d && d.message) return d.message;
  } catch (x) { /* use the fallback */ }
  return fallback;
}

/** Asks the server for a PDF while the animation plays, then opens it (print) or saves it (pdf). Returns true when the file was delivered. */
export async function idMakeFile(url, { mode = 'pdf', color, count = 1, title = '', doneText = '', fail = 'The file could not be made.' } = {}) {
  idMakeStart({ mode, color: color || '#1565c0', count, title });
  const t0 = Date.now();
  try {
    const r = await window.axios.get(url, { responseType: 'blob', headers: { 'X-Quiet': '1' } });
    const m = /filename="?([^";]+)"?/.exec(r.headers['content-disposition'] || '');
    const name = m ? m[1] : 'rans-id.pdf';
    const href = URL.createObjectURL(new Blob([r.data], { type: 'application/pdf' }));
    await sleep(Math.max(0, MIN - (Date.now() - t0)));
    if (mode === 'print') {
      const w = window.open(href, '_blank');
      if (w) { try { w.opener = null; } catch (x) { /* ignore */ } idMakeDone(doneText); setTimeout(() => URL.revokeObjectURL(href), 120000); }
      else idMakeDone(doneText, { openUrl: href });      // the browser blocked the new tab: one more click opens it
    } else {
      const a = document.createElement('a'); a.href = href; a.download = name; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(href), 60000); idMakeDone(doneText);
    }
    return true;
  } catch (e) {
    idMakeFail(await errorText(e, fail));
    return false;
  }
}
