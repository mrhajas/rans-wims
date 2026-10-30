// How a status of an ID is shown (colour of the chip) and worded; shared by every ID page.
export const statusChip = s => ({ Active: 'lg-chip-green', Expired: 'lg-chip-amber', 'Pending Approval': 'lg-chip-amber', Suspended: 'lg-chip-orange', Revoked: 'lg-chip-red', Rejected: 'lg-chip-red', Replaced: 'lg-chip-slate' }[s] || 'lg-chip-slate');
export const statusIcon = s => ({ Active: 'tick', Expired: 'clock', 'Pending Approval': 'clock', Suspended: 'pause', Revoked: 'x-circle', Rejected: 'x-circle', Replaced: 'refresh' }[s] || 'info');
