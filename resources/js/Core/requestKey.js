// A random key made when a create form opens and sent with its submission (`request_key`). The server remembers which form already created its record, so a
// double click or a retry after a lost answer cannot create it twice. The same form keeps the same key for every retry; a new form gets a new one.
export function newRequestKey() {
  try {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
      const b = crypto.getRandomValues(new Uint8Array(16));
      return Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
    }
  } catch (e) {
    // fall through
  }

  return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
}
