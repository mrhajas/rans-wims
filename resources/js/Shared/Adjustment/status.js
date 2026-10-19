// How an Adjustment's approval status is shown everywhere (list, details, form): chip colour, icon and wording.
// The status itself (Pending / Approved / Rejected) always comes from the server.
export const adjustmentStatus = {
  chip: s => ({ Pending: 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red' }[s] || 'lg-chip-slate'),
  icon: s => ({ Pending: 'clock', Approved: 'tick', Rejected: 'cross' }[s] || 'info'),
  label: s => ({ Pending: 'Pending approval', Approved: 'Approved', Rejected: 'Rejected' }[s] || s || ''),
};

// Drop into a component's `methods` to get statusChip / statusIcon / statusLabel.
export const adjustmentStatusMethods = {
  statusChip: s => adjustmentStatus.chip(s),
  statusIcon: s => adjustmentStatus.icon(s),
  statusLabel: s => adjustmentStatus.label(s),
};
