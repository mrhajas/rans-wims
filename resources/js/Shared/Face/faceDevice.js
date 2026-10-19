// The device key for Face ID. A key pair is made in this browser; the private half can never be read out of it (WebCrypto "not
// extractable", kept in IndexedDB), so only this browser can sign a face sign-in. The server keeps the public half.
const DB = 'wims-face-device';
const STORE = 'keys';

function idb() {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(DB, 1);
    open.onupgradeneeded = () => open.result.createObjectStore(STORE);
    open.onsuccess = () => resolve(open.result);
    open.onerror = () => reject(open.error);
  });
}
async function tx(mode, fn) {
  const db = await idb();
  return new Promise((resolve, reject) => {
    const t = db.transaction(STORE, mode);
    const r = fn(t.objectStore(STORE));
    t.oncomplete = () => { db.close(); resolve(r && r.result); };
    t.onerror = () => { db.close(); reject(t.error); };
  });
}
const get = key => tx('readonly', s => s.get(key));
const put = (key, val) => tx('readwrite', s => s.put(val, key));
const del = key => tx('readwrite', s => s.delete(key));

const b64 = buf => btoa(String.fromCharCode(...new Uint8Array(buf)));

export function supported() {
  return !!(window.isSecureContext && window.crypto && crypto.subtle && window.indexedDB);
}

/** Every device id this browser holds a key for (sent with the sign-in request so the server can pick the right one). */
export async function knownIds() {
  try { return (await get('ids')) || []; } catch (e) { return []; }
}
async function remember(id) {
  const ids = await knownIds();
  if (!ids.includes(id)) await put('ids', [...ids, id].slice(-20));
}
export async function forget(id) {
  const ids = (await knownIds()).filter(x => x !== id);
  await put('ids', ids); await del('key:' + id);
}

/** A key pair waiting to be registered (made once, reused if the first try failed). Returns the public key to send to the server. */
export async function pendingPublicKey() {
  let pair = await get('pending');
  if (!pair) {
    pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
    await put('pending', pair);
  }
  return b64(await crypto.subtle.exportKey('spki', pair.publicKey));
}

/** The server accepted the pending key and named the device: keep the key under that name. */
export async function bind(deviceId) {
  const pair = await get('pending');
  if (!pair) return;
  await put('key:' + deviceId, pair);
  await del('pending');
  await remember(deviceId);
  try { if (navigator.storage && navigator.storage.persist) navigator.storage.persist(); } catch (e) { /* optional */ }
}

export async function hasKey(deviceId) {
  try { return !!(await get('key:' + deviceId)); } catch (e) { return false; }
}

/** Signs text with the device's private key. Returns base64 of the 64-byte signature. */
export async function sign(deviceId, text) {
  const pair = await get('key:' + deviceId);
  if (!pair) { const e = new Error('nokey'); e.code = 'nokey'; throw e; }
  const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, pair.privateKey, new TextEncoder().encode(text));
  return b64(sig);
}
