// What a file looks like in Accounts Tracking: an icon, a colour tone and a short name, from its name / mime type. Also the size text.
export function fileKind(name, mime) {
  const ext = String(name || '').split('.').pop().toLowerCase();
  if (ext === 'pdf' || mime === 'application/pdf') return { key: 'pdf', icon: 'doc-text', tone: 'rose', label: 'PDF' };
  if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext) || String(mime || '').startsWith('image/')) return { key: 'image', icon: 'camera', tone: 'sky', label: 'Image' };
  if (['xls', 'xlsx'].includes(ext)) return { key: 'sheet', icon: 'table', tone: 'emerald', label: 'Excel' };
  if (ext === 'csv') return { key: 'csv', icon: 'table', tone: 'teal', label: 'CSV' };
  if (['doc', 'docx'].includes(ext)) return { key: 'word', icon: 'doc', tone: 'navy', label: 'Word' };
  return { key: 'other', icon: 'doc-text', tone: 'slate', label: ext ? ext.toUpperCase() : 'File' };
}

export function fileSize(bytes) {
  const b = Number(bytes) || 0;
  if (b < 1024) return b + ' B';
  if (b < 1024 * 1024) return (b / 1024).toFixed(b < 10240 ? 1 : 0) + ' KB';
  return (b / 1024 / 1024).toFixed(1) + ' MB';
}

export const isImage = (name, mime) => fileKind(name, mime).key === 'image';
