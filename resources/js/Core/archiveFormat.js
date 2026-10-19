// Shared Archive formatting helpers (sizes, file-type look).
export function formatBytes(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return n + ' B';
  const units = ['KB', 'MB', 'GB', 'TB'];
  let value = n / 1024;
  let i = 0;
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024;
    i++;
  }
  return value.toLocaleString(undefined, { maximumFractionDigits: value >= 100 ? 0 : 1 }) + ' ' + units[i];
}

// Same value, but in MB with thousands separators — the dashboard shows both MB and GB.
export function formatMb(bytes) {
  return (Math.round(((Number(bytes) || 0) / 1048576) * 10) / 10).toLocaleString() + ' MB';
}

// cls = the fixed categorical colour slot of this file type (see .arx in app.css); tone is the older icon-gradient name.
export const FILE_TYPE_LOOK = {
  PDF: { icon: 'doc-text', tone: 'rose', cls: 'pdf', label: 'PDF' },
  Image: { icon: 'image', tone: 'emerald', cls: 'image', label: 'Images' },
  Office: { icon: 'doc', tone: 'sky', cls: 'office', label: 'Documents' },
  Archive: { icon: 'collection', tone: 'amber', cls: 'archive', label: 'Archives' },
  Text: { icon: 'doc-text', tone: 'violet', cls: 'text', label: 'Text' },
  Other: { icon: 'clip', tone: 'slate', cls: 'other', label: 'Other' },
};

export const FOLDER_TONES = ['amber', 'sky', 'emerald', 'violet', 'rose', 'indigo', 'orange', 'teal'];

// A stable folder colour for a name, so the same folder is always the same colour wherever it shows.
export function folderTone(name) {
  let h = 0;
  for (const ch of String(name)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return FOLDER_TONES[h % FOLDER_TONES.length];
}

export function confidentialityChip(level) {
  return { Public: 'lg-chip-green', Internal: 'lg-chip-slate', Confidential: 'lg-chip-amber', Restricted: 'lg-chip-red' }[level] || 'lg-chip-slate';
}

export function statusChip(status) {
  return { Active: 'lg-chip-green', Archived: 'lg-chip-slate', Superseded: 'lg-chip-amber' }[status] || 'lg-chip-slate';
}
