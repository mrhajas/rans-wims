// Small helpers shared by the Funds Allocation pages (Index / Form / Show).

/** 1,234,567 (no decimals) or 1,234.50 (two decimals when there are cents). */
export function fmt(value) {
  const n = Math.round((Number(value) || 0) * 100) / 100;
  const whole = Number.isInteger(n);
  return n.toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 });
}

/** Always two decimals (used for rates / foreign currency). */
export function fmt2(value) {
  return (Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function round2(value) {
  return Math.round((Number(value) || 0) * 100) / 100;
}

export function num(value) {
  const n = parseFloat(value);
  return isNaN(n) ? 0 : n;
}

export const STATUS_CHIPS = {
  Draft: 'lg-chip-slate',
  'Pending Approval': 'lg-chip-amber',
  Approved: 'lg-chip-blue',
  'In Progress': 'lg-chip-violet',
  Completed: 'lg-chip-cyan',
  Cleared: 'lg-chip-green',
  Rejected: 'lg-chip-red',
  Cancelled: 'lg-chip-slate',
};

export function statusChip(status) {
  return STATUS_CHIPS[status] || 'lg-chip-slate';
}

export function initials(name) {
  return (name || '?')
    .split(/\s+/)
    .slice(0, 2)
    .map(p => p.charAt(0).toUpperCase())
    .join('');
}
