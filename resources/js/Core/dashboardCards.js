import menus from '@/Core/menu';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';

/*
 * The dashboard shortcut cards. The catalogue is the side menu itself: every top-level entry (a main module or a dashboard, never a
 * page inside a module such as "Create item") becomes one possible card, so a new module appears here by itself and the permission,
 * feature and role rules are exactly the ones the side menu uses.
 */
export const MAX_CARDS = 10;

// What a person sees when they have not chosen their own cards (the standard stack; the dashboard keeps its older role rules for these).
export const DEFAULT_KEYS = ['warehouses', 'reports', 'notice-board', 'backup', 'users', 'project-execution', 'material-reallocations', 'daily-site-reports', 'material-value', 'material-requests'];

// For the roles that never had the standard stack (site engineers, store managers): the everyday modules first. Only the ones the person can
// open are shown, so each role gets its own set.
export const FIELD_DEFAULT_KEYS = ['material-requests', 'items', 'checkins', 'checkouts', 'transfers', 'adjustments', 'issue-returns', 'contacts', 'reports', 'my-warehouses', 'notice-board', 'task-reminders', 'categories', 'units'];

const DESCRIPTIONS = {
  branch: 'Branch dashboard and figures',
  checkins: 'Record stock coming in',
  checkouts: 'Record stock going out',
  adjustments: 'Correct stock quantities',
  transfers: 'Move stock between locations',
  'issue-returns': 'Issue and return materials',
  items: 'Items and stock levels',
  contacts: 'Suppliers, clients and contacts',
  categories: 'Group items by category',
  units: 'Units of measure',
  activities: 'Recent activity',
  'my-warehouses': 'Your projects and warehouses',
  'material-requests': 'Request materials for a project',
  'material-reallocations': 'Move stock between projects',
  staffs: 'Staff records and contracts',
  archive: 'Documents and records',
  'site-diary': 'Daily site photos and notes',
  'site-meetings': 'Weekly site meetings',
  signatories: 'Digital signatures on reports',
  'responsive-audit': 'Check every page on phone and tablet',
  'smart-brief': 'Your day and your team, from the records',
  weather: 'Weather and site risk for your projects',
  wimschat: 'Chat with your team',
  'fund-allocations': 'Plan and pay allocations',
  salary: 'Salary periods and payments',
  assets: 'Assets and equipment',
  'transport-management': 'Trips, vehicles and routes',
  'compliance-records': 'Licences, permits and renewals',
  'digital-attendance': 'Clock in and out',
  'payment-vouchers': 'Prepare and approve vouchers',
  labour: 'Labour lists and payments',
  'case-tracking': 'Cases and tickets',
  'task-manager': 'Team tasks and boards',
  'supplier-records': 'Suppliers, orders and ledgers',
  'project-certificates': 'Project contracts and certificates',
  'daily-site-reports': 'Daily site reports',
  'project-execution': 'Project execution management',
  'notice-board': 'Notice board',
  'task-reminders': 'Your reminders',
  warehouses: 'Manage project(s) / warehouse(s)',
  users: 'Manage users',
  'security-reminders': 'Security reminder campaigns',
  reports: 'View all reports',
  'material-value': 'Stock value by material',
  'movement-value': 'Value of stock movements',
  'project-material-ledger': 'Material ledger per project',
  'login-logs': 'Who signed in and when',
  'prayer-times': 'Prayer times settings',
  'email-notifications': 'Low stock alert settings',
  'wims-connect': 'SMS, email and WhatsApp',
  'executive-report': 'Weekly executive report',
  'download-print-logs': 'Downloads and prints',
  activity: 'System activity log',
  deleted: 'Restore deleted records',
  backup: 'Database backup & restore',
};

let cache = null;

/** Every possible card: [{ key, route, icon, title (English key for $t), description, permissions, feature, roles }]. */
export function cardCatalog() {
  if (cache) return cache;
  cache = menus
    .filter(m => !m.hidden && m.name !== 'dashboard')
    .map(m => {
      const route = m.route || (m.sub_menu && m.sub_menu[0] ? m.sub_menu[0].route : null);
      if (!route) return null;
      return {
        key: m.name,
        route,
        icon: m.icon,
        title: (m.lang && m.lang.main) || m.name,
        description: DESCRIPTIONS[m.name] || 'Open this module',
        permissions: m.permissions || [],
        feature: m.feature || null,
        roles: m.roles || null,
        admin: !!m.admin,
      };
    })
    .filter(Boolean);
  return cache;
}

/** Can this person open the module of that card? Same rules as the side menu: permission, feature switch and role. */
export function canOpenCard(vm, card) {
  if (!vm.$can(card.permissions)) return false;
  if (card.admin && !vm.$admin) return false;
  if (card.feature && vm.$page.props.enabledFeatures?.[card.feature] === false && !vm.$super) return false;
  if (card.roles && card.roles.length) {
    const mine = (vm.$page.props.auth?.user?.roles || []).map(r => r.name);
    if (!card.roles.some(r => mine.includes(r))) return false;
  }
  return true;
}

/** The person's own saved stack (array of keys) or null when they never changed it. */
export function savedCardKeys(vm) {
  const keys = vm.$page.props.auth?.user?.extra_attributes?.dashboard_card_keys;
  return Array.isArray(keys) ? keys : null;
}

/** Site engineers and store managers do not get the standard stack (its modules are not theirs); they start from the everyday modules. */
export function usesFieldDefault(vm) {
  const roles = (vm.$page.props.auth?.user?.roles || []).map(r => r.name);
  return roles.some(name => [...SITE_ENGINEER_ROLES, 'Store Manager'].includes(name));
}

/** The keys a person starts with when they never chose their own stack. */
export function defaultKeysFor(vm) {
  return usesFieldDefault(vm) ? FIELD_DEFAULT_KEYS : DEFAULT_KEYS;
}
