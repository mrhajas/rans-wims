export const CATEGORY_ALIASES = {
  items: ['item', 'items'],
  warehouses: ['warehouse', 'warehouses', 'project', 'projects'],
  checkins: ['checkin', 'checkins'],
  checkouts: ['checkout', 'checkouts'],
  adjustments: ['adjustment', 'adjustments'],
  transfers: ['transfer', 'transfers'],
  'material-requests': ['material-request', 'material-requests', 'materialrequest', 'materialrequests', 'mr'],
  contacts: ['contact', 'contacts'],
  categories: ['category', 'categories'],
  units: ['unit', 'units'],
  'daily-site-reports': ['daily-site-report', 'daily-site-reports', 'site-report', 'site-reports', 'dsr'],
  users: ['user', 'users'],
  'site-meetings': ['site-meeting', 'site-meetings', 'meeting', 'meetings'],
  'transport-records': ['transport', 'transport-record', 'transport-records', 'trip', 'trips', 'route', 'routes'],
  'visitor-visits': ['visit', 'visits', 'visitor-visit', 'visitor-visits'],
  visitors: ['visitor', 'visitors'],
  'incoming-items': ['incoming', 'incoming-item', 'incoming-items', 'parcel', 'parcels', 'letter', 'letters', 'mail'],
  'loading-personnel': ['loading', 'unloading', 'loader', 'loaders', 'personnel', 'loading-personnel'],
};

export function resolveCategory(prefix) {
  const p = prefix.trim().toLowerCase().replace(/\s+/g, '-');
  if (!p) return null;

  for (const [key, aliases] of Object.entries(CATEGORY_ALIASES)) {
    if (aliases.includes(p)) return key;
  }

  if (p.length >= 2) {
    for (const [key, aliases] of Object.entries(CATEGORY_ALIASES)) {
      if (aliases.some(a => a.startsWith(p))) return key;
    }
  }

  return null;
}
