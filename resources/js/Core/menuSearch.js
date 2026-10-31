import menus from './menu';

function flatten(list) {
  const out = [];
  for (const menu of list) {
    if (menu.hidden) continue;
    if (menu.sub_menu && menu.sub_menu.length) {
      // The parent's own name (e.g. "WIMS Connect", "Reports") previously
      // wasn't searchable at all — only its children were, since a parent
      // with sub_menu has no route of its own. Index it too, resolved to
      // its first visible child's route (same convention quickMenu.js
      // already uses for the floating menu), so searching the group name
      // itself finds something rather than nothing.
      const firstChild = menu.sub_menu.find(sm => !sm.hidden && sm.route);
      if (menu.lang && firstChild) {
        out.push({ ...menu, route: firstChild.route });
      }
      for (const sm of menu.sub_menu) {
        if (sm.hidden || !sm.route) continue;
        // a child is only reachable when its group is: carry the group's feature, role and permission limits down so the Launcher
        // never lists a destination the sidebar would not show.
        out.push({ ...sm, parentFeature: menu.feature || null, parentRoles: menu.roles || null, parentPermissions: menu.permissions || null, parentEntry: { lang: menu.lang, name: menu.name } });
      }
    } else if (menu.route && !menu.sub_menu) {
      out.push(menu);
    }
  }
  return out;
}

const FLAT_ENTRIES = flatten(menus);

// Account-level actions that live in the navbar's user dropdown rather than the
// sidebar menu tree, but should still be reachable from Rauncher.
const ACCOUNT_ENTRIES = [
  { route: 'profile.show', title: 'Profile', icon: 'user' },
  { route: 'logout', title: 'Log Out', icon: 'out', action: 'logout' },
];

// The look of the main menu: Classic or Pro (kept on the person's own settings; offered only when the feature is on and the role has the permission)
const MENU_STYLE_ENTRIES = [
  { title: 'Switch to the Pro menu', words: 'pro menu style rail', icon: 'collection', action: 'menu-style:pro' },
  { title: 'Switch to the Classic menu', words: 'classic menu style sidebar', icon: 'list', action: 'menu-style:classic' },
];

// Backup/restore aliases — all point at the same page since the actual
// download/restore/create actions are only ever performed there, behind the
// existing password confirmation and (for restore) the "type RESTORE" gate.
// Rauncher never triggers these actions directly, only navigates to them.
const BACKUP_ENTRIES = [
  { route: 'backup.index', title: 'Backup & Restore', icon: 'refresh', permission: 'manage-backup' },
  { route: 'backup.index', title: 'Restore Database Backup', icon: 'upload', permission: 'manage-backup' },
  { route: 'backup.index', title: 'Create System Backup', icon: 'download', permission: 'manage-backup' },
];

// Settings > Performance & Data (Super Admin only), reachable by what a person would type
const PERFORMANCE_ENTRIES = [
  { route: 'performance.index', title: 'Performance & Data', icon: 'chart-bar' },
  { route: 'performance.index', title: 'Activity Archive', icon: 'archive-box', hash: '#pf-archive' },
  { route: 'performance.index', title: 'Download Activity Archive', icon: 'download', hash: '#pf-archive' },
  { route: 'performance.index', title: 'Slow Queries', icon: 'clock', hash: '#pf-slow' },
  { route: 'performance.index', title: 'Send Messages in the Background (Queue)', icon: 'send', hash: '#pf-queue' },
  { route: 'performance.index', title: 'Data Retention (months kept)', icon: 'archive-box', hash: '#pf-retention' },
];

// Launcher visibility mirrors the sidebar and Global Feature Control: a destination whose feature is switched off, or that is limited to
// roles the person does not hold, is not offered. (Hiding is a convenience only. Every route is still authorised on the server.)
function allowedHere(entry, ctx) {
  const features = ctx.features || {};
  for (const key of [entry.feature, entry.parentFeature]) {
    if (key && features[key] === false) return false;
  }
  const roles = ctx.roles || [];
  for (const need of [entry.roles, entry.parentRoles]) {
    if (need && need.length && !need.some(r => roles.includes(r))) return false;
  }
  return true;
}

function labelFor(entry, t) {
  return entry.lang && entry.lang.helper ? t(entry.lang.helper, { x: t(entry.lang.main) }) : t((entry.lang || {}).main || entry.name);
}

/**
 * Client-side fuzzy search over every page/report already defined in menu.js,
 * plus account-level actions (Profile, Log Out).
 * `ctx` = { t: this.$t, can: this.$can, route: this.route, isAdmin: this.$admin }
 */
export function searchMenu(query, ctx) {
  const q = query.trim().toLowerCase();
  if (!q) return [];

  const results = [];
  for (const entry of FLAT_ENTRIES) {
    if (entry.admin && !ctx.isAdmin) continue;
    if (!ctx.can(entry.permissions || [])) continue;
    if (!allowedHere(entry, ctx)) continue;

    const title = labelFor(entry, ctx.t);
    const haystack = title.toLowerCase();
    if (!haystack.includes(q)) continue;

    // a page that lives in a module shows the module in brackets, so the many "... Report" pages can be told apart
    const module = entry.parentEntry ? labelFor(entry.parentEntry, ctx.t) : '';
    const shown = module && module.toLowerCase() !== title.toLowerCase() ? `${title} (${module})` : title;

    results.push({
      category: 'page',
      label: 'Pages',
      icon: entry.icon || 'link',
      title: shown,
      subtitle: '',
      url: ctx.route(entry.route),
      rank: haystack.startsWith(q) ? 0 : 1,
    });
  }

  for (const entry of ACCOUNT_ENTRIES) {
    const haystack = entry.title.toLowerCase();
    if (!haystack.includes(q)) continue;

    results.push({
      category: 'account',
      label: 'Account',
      icon: entry.icon,
      title: entry.title,
      subtitle: '',
      url: entry.action ? null : ctx.route(entry.route),
      action: entry.action || null,
      rank: haystack.startsWith(q) ? 0 : 1,
    });
  }

  if ((ctx.features || {}).pro_menu !== false && ctx.can(['use-pro-menu'])) {
    for (const entry of MENU_STYLE_ENTRIES) {
      const haystack = (entry.title + ' ' + entry.words).toLowerCase();
      if (!haystack.includes(q)) continue;
      results.push({ category: 'account', label: 'Account', icon: entry.icon, title: ctx.t(entry.title), subtitle: '', url: null, action: entry.action, rank: entry.title.toLowerCase().includes(q) ? 0 : 1 });
    }
  }

  for (const entry of BACKUP_ENTRIES) {
    if (!ctx.can([entry.permission])) continue;

    const haystack = entry.title.toLowerCase();
    if (!haystack.includes(q)) continue;

    results.push({
      category: 'backup',
      label: 'System',
      icon: entry.icon,
      title: entry.title,
      subtitle: '',
      url: ctx.route(entry.route),
      rank: haystack.startsWith(q) ? 0 : 1,
    });
  }

  if (ctx.isAdmin) {
    for (const entry of PERFORMANCE_ENTRIES) {
      const haystack = entry.title.toLowerCase();
      if (!haystack.includes(q)) continue;

      results.push({
        category: 'system',
        label: 'System',
        icon: entry.icon,
        title: entry.title,
        subtitle: '',
        url: ctx.route(entry.route) + (entry.hash || ''),
        rank: haystack.startsWith(q) ? 0 : 1,
      });
    }
  }

  results.sort((a, b) => a.rank - b.rank || a.title.localeCompare(b.title));
  return results.slice(0, 14);
}
