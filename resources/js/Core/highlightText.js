// Wraps the part of a text that matches a search in <mark>, after escaping it, so it can be shown with v-html safely.
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function highlightText(text, query) {
  const t = String(text == null ? '' : text);
  const q = String(query || '').trim();
  if (!q) return esc(t);
  const at = t.toLowerCase().indexOf(q.toLowerCase());
  if (at < 0) return esc(t);
  return esc(t.slice(0, at)) + '<mark>' + esc(t.slice(at, at + q.length)) + '</mark>' + esc(t.slice(at + q.length));
}
