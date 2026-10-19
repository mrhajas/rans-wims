import menus from '@/Core/menu';

// Hand-picked cold-start order, per the user's own requested defaults, extended
// with a few sensible fallbacks so a role that can't see all 5 primary defaults
// still gets a full set of 5 shortcuts.
export const DEFAULT_PRIORITY = [
  'dashboard',
  'items',
  'checkins',
  'checkouts',
  'reports',
  'material-requests',
  'adjustments',
  'transfers',
  'contacts',
  'categories',
];

// The sidebar's own icon choice isn't always legible at the quick-menu's small,
// single-color pill size (e.g. 'chart-bar' for Reports reads as an unrelated
// pin/blob at 20px). This overrides icon choice for the floating menu only —
// menu.js and the real sidebar are untouched.
const ICON_OVERRIDES = {
  reports: 'doc-text',
};

// Flattens the top-level menu.js entries into quick-menu candidates — one icon
// per SECTION (Items, Checkins, Reports, ...), not per sub-action (Create Item
// vs Items List). A parent entry (no own `route`, has `sub_menu`) resolves to
// its first child's route as the concrete destination.
export function buildCandidates() {
  const fromMenus = menus
    .filter(menu => !menu.hidden)
    .map(menu => {
      const route = menu.route || (menu.sub_menu && menu.sub_menu[0] ? menu.sub_menu[0].route : null);
      if (!route) {
        return null;
      }

      const key = route.split('.')[0];

      return {
        key,
        route,
        icon: ICON_OVERRIDES[key] || menu.icon,
        lang: menu.lang,
        permissions: menu.permissions || [],
        feature: menu.feature || null,
        roles: menu.roles || null,
      };
    })
    .filter(Boolean);

  // Hand-added, not derived from menu.js — the "New Case" shortcut points at
  // case-tracking.create specifically, not case-tracking's own top-level
  // entry (which resolves to the case list). Rendered with special-cased
  // link behavior in QuickAccessMenu.vue (opens in a new tab, carries the
  // page the user was on) so it lives here only for permission/feature gating.
  fromMenus.push({
    key: 'new-case',
    route: 'case-tracking.create',
    icon: 'plus',
    lang: { helper: 'create_x', main: 'Case' },
    permissions: ['create-case-tracking'],
    feature: 'case_tracking',
    roles: null,
  });

  return fromMenus;
}

// Maps a current route name's first segment to the matching entry in
// CaseTicket::wimsModules() (kept in sync by hand — that PHP list is the
// source of truth) so the "New Case" shortcut can prefill Module/Reference
// with the exact page the user was on. Falls back to 'Other' when the
// current page isn't in this map, matching the last entry of that list.
export const ROUTE_TO_WIMS_MODULE = {
  dashboard: 'Dashboard',
  checkins: 'Checkins',
  checkouts: 'Checkouts',
  adjustments: 'Adjustments',
  transfers: 'Transfers',
  'issue-returns': 'Issue & Return',
  'issue-materials': 'Issue & Return',
  'return-materials': 'Issue & Return',
  'issue-return-reports': 'Issue & Return',
  items: 'Items',
  contacts: 'Contacts',
  categories: 'Categories',
  units: 'Units',
  activities: 'Activities',
  'material-requests': 'Material Requests',
  'material-reallocations': 'Material Reallocation',
  staffs: 'RANS Staffs',
  assets: 'Assets',
  'compliance-records': 'Compliance',
  'digital-attendance': 'Digital Attendance',
  'digital-attendance-admin': 'Digital Attendance',
  'digital-attendance-reports': 'Digital Attendance',
  'digital-attendance-settings': 'Digital Attendance',
  'payment-vouchers': 'Payment Vouchers',
  labour: 'Labour',
  'workforce-agreements': 'Workforce Agreements',
  'case-tracking': 'Case Tracking',
  'supplier-records': 'Supplier Records',
  'project-certificates': 'Project Contracts',
  'daily-site-reports': 'Site Reports',
  'site-diary': 'Site Diary',
  'site-meetings': 'Site Meetings',
  'project-execution': 'Project Execution',
  'work-packages': 'Project Execution',
  'work-package-daily-updates': 'Project Execution',
  'work-package-issues': 'Project Execution',
  'notice-board': 'Notice Board',
  'task-reminders': 'Task Reminders',
  warehouses: 'Project(s) / Warehouse(s)',
  'my-warehouses': 'Project(s) / Warehouse(s)',
  users: 'Users',
  roles: 'Roles',
  impersonation: 'Users',
  'security-reminders': 'Security Reminders',
  reports: 'Reports',
  'item-category-report': 'Reports',
  'material-value': 'Material Value',
  'movement-value': 'Movement Value Report',
  'project-material-ledger': 'Project Material Ledger',
  'login-logs': 'Login Logs',
  'email-notifications': 'Email Notifications',
  'wims-connect': 'WIMS Connect',
  'executive-report': 'Executive Intelligence Report',
  'download-print-logs': 'Download / Print Logs',
  activity: 'System Logs',
  deleted: 'Deleted Records',
  backup: 'Backup',
};

export function wimsModuleForRoute(routeName) {
  const prefix = String(routeName || '').split('.')[0];
  return ROUTE_TO_WIMS_MODULE[prefix] || 'Other';
}

function priorityIndex(key) {
  const idx = DEFAULT_PRIORITY.indexOf(key);
  return idx === -1 ? DEFAULT_PRIORITY.length : idx;
}

/**
 * @param {Array} candidates - from buildCandidates()
 * @param {Object} gates - { canAccess(candidate): bool } — the caller decides what "permitted" means
 * @param {Object} visitCounts - { [sectionKey]: number }
 * @param {number} limit
 * @param {Array} pinnedKeys - fixed anchors, in order, placed before the usage-ranked
 *   items and never reordered by visit count. Defaults to just Dashboard (the original
 *   behavior); Super-Admin-only callers pass ['dashboard', 'wims-connect'] to also pin
 *   WIMS Connect as the second shortcut, per the user's explicit request — everyone
 *   else's menu is unaffected.
 */
export function resolveTopItems(candidates, gates, visitCounts, limit = 5, pinnedKeys = ['dashboard']) {
  const permitted = candidates.filter(item => gates.canAccess(item));

  const withCounts = permitted.map(item => ({ ...item, count: Number(visitCounts?.[item.key] || 0) }));
  const hasAnyUsage = withCounts.some(item => item.count > 0);

  const ranked = hasAnyUsage
    ? withCounts.sort((a, b) => b.count - a.count || priorityIndex(a.key) - priorityIndex(b.key))
    : [...permitted].sort((a, b) => priorityIndex(a.key) - priorityIndex(b.key));

  // Pinned items are pulled out of the ranked list (if the user can access
  // them) and placed at the front in the exact order given — fixed anchors,
  // not ranked by usage.
  const pinned = [];
  for (const key of pinnedKeys) {
    const idx = ranked.findIndex(item => item.key === key);
    if (idx !== -1) {
      pinned.push(ranked.splice(idx, 1)[0]);
    }
  }

  return [...pinned, ...ranked].slice(0, limit);
}
