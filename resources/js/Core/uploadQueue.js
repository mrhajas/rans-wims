// Sends files one request at a time so every attachment has its own real progress bar (a single multipart request can
// only report one combined number). Each item is a reactive object: { file, name, size, status, progress, error }.
export function makeItem(file, extra = {}) {
  return { uid: Math.random().toString(36).slice(2) + Date.now().toString(36), file, name: file.name, size: file.size, status: 'queued', progress: 0, error: null, ...extra };
}

export function firstError(e, fallback) {
  const r = e && e.response;
  if (!r) return fallback + ' (connection lost)';
  if (r.status === 413) return 'The file is too large for the server.';
  const d = r.data || {};
  if (d.errors) return Object.values(d.errors)[0][0];
  return d.message || fallback;
}

/** Uploads every queued / failed item to `url` (field name `files[]`). Resolves with { done, failed }. */
export async function uploadSequential(items, url, { field = 'files[]', extra = {}, fallback = 'Upload failed.' } = {}) {
  const list = items.filter(i => i.status === 'queued' || i.status === 'failed');
  let done = 0;
  let failed = 0;
  for (const item of list) {
    item.status = 'uploading';
    item.progress = 0;
    item.error = null;
    const fd = new FormData();
    fd.append(field, item.file);
    Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
    try {
      await window.axios.post(url, fd, {
        headers: { 'Content-Type': 'multipart/form-data', Accept: 'application/json' },
        timeout: 0,
        onUploadProgress: e => { if (e.total) item.progress = Math.min(99, Math.round((e.loaded / e.total) * 100)); },
      });
      item.progress = 100;
      item.status = 'uploaded';
      done++;
    } catch (e) {
      item.status = 'failed';
      item.error = firstError(e, fallback);
      failed++;
    }
  }
  return { done, failed };
}

/** 0-100 across every item, weighted by file size. */
export function overallPercent(items) {
  const total = items.reduce((s, i) => s + (i.size || 1), 0);
  if (!total) return 0;
  return Math.round(items.reduce((s, i) => s + (i.size || 1) * (i.status === 'uploaded' ? 100 : i.progress || 0), 0) / total);
}
