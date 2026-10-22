// Small helpers shared by the Visitor Logs pages.
export const visitChip = s => ({ 'Checked In': 'lg-chip-green', 'Checked Out': 'lg-chip-slate', Cancelled: 'lg-chip-red' }[s] || 'lg-chip-slate');
export const itemChip = (flag, open) => (flag === 'handed' ? 'lg-chip-green' : flag === 'closed' ? 'lg-chip-slate' : flag === 'ready' ? 'lg-chip-cyan' : open ? 'lg-chip-amber' : 'lg-chip-slate');

// "1h 05m" from minutes
export const durationText = m => {
  if (m === null || m === undefined || m === '') return '';
  const h = Math.floor(m / 60);
  const r = m % 60;
  return h > 0 ? h + 'h ' + String(r).padStart(2, '0') + 'm' : r + 'm';
};

// A readable line for one history event of a visit or an item.
export const eventLabel = (t, e) => ({
  checked_in: t('Checked in'), checked_out: t('Checked out'), edited: t('Details changed'), corrected: t('Corrected'), cancelled: t('Cancelled'), received: t('Item received'), status_changed: t('Status changed'), handed_over: t('Handed over'),
  file_added: t('Files added'), file_opened: t('File opened'), long_stay_alert: t('Still-on-premises reminder sent'), overdue_alert: t('Waiting-too-long reminder sent'), created: t('Added'), updated: t('Updated'), deactivated: t('Deactivated'), reactivated: t('Reactivated'),
}[e] || e);

export const eventIcon = e => ({ checked_in: 'in', checked_out: 'out', corrected: 'edit', cancelled: 'x-circle', received: 'email', status_changed: 'refresh', handed_over: 'tick', edited: 'edit', file_added: 'clip', file_opened: 'eye-flat', long_stay_alert: 'clock', overdue_alert: 'clock' }[e] || 'info');

// a compact "from -> to" sentence of what changed in an event
export const changeText = (t, e) => {
  const o = e.old || {};
  const n = e.new || {};
  const keys = Object.keys(n).filter(k => !['time_set_by_hand', 'minutes', 'confidential', 'duplicate_override'].includes(k));
  const names = { check_in_at: 'check-in', check_out_at: 'check-out', host: 'person visited', host_user_id: 'person visited', handed_to: 'handed to', handed_at: 'handed at', released_by: 'released by', purpose_other: 'purpose', appointment_ref: 'appointment', recipient_user_id: 'recipient', recipient_name: 'recipient', is_confidential: 'confidential' };
  const label = k => t(names[k] || k.replace(/_/g, ' '));
  return keys.map(k => (k in o && o[k] !== n[k] ? label(k) + ': ' + (o[k] ?? '-') + ' -> ' + (n[k] ?? '-') : label(k) + ': ' + (n[k] ?? '-'))).join(' · ');
};
