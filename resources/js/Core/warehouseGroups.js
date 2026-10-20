// How the Project(s) / Warehouse(s) lists are sectioned everywhere: Main Warehouses first, then Projects (Government and Private together), then Others (every other category).
// One place decides it, from the warehouse "status" (its category), so every dropdown groups the same way.
export const WAREHOUSE_GROUPS = [
  { key: 'main', label: 'Main Warehouses', icon: 'home', tone: 'blue' },
  { key: 'projects', label: 'Projects', icon: 'building', tone: 'emerald' },
  { key: 'others', label: 'Others', icon: 'folder', tone: 'slate' },
];

const clean = s => String(s || '').trim().toLowerCase();

export function groupOfStatus(status) {
  const s = clean(status);
  if (s === 'main warehouse' || s === 'main warehouses') return 'main';
  if (s === 'government' || s === 'private') return 'projects';
  return 'others';
}

/** A small tag shown beside a project: Government or Private (nothing for the other groups). */
export function tagOfStatus(status) {
  const s = clean(status);
  return s === 'government' ? 'Government' : s === 'private' ? 'Private' : '';
}

/**
 * Splits a list of warehouses ({ id, name, status }) into the sections above, in order, each sorted by name; empty sections are left out.
 * `query` keeps only the warehouses whose name or category contains it.
 */
export function groupWarehouses(list, query = '') {
  const q = clean(query);
  const out = WAREHOUSE_GROUPS.map(g => ({ ...g, items: [] }));
  (list || []).forEach(w => {
    if (q && !clean(w.name).includes(q) && !clean(w.status).includes(q)) return;
    out.find(g => g.key === groupOfStatus(w.status)).items.push({ ...w, tag: tagOfStatus(w.status), category: String(w.status || '').trim() });
  });
  // A to Z, case-insensitive, numbers in natural order (Container 2 before Container 10)
  out.forEach(g => g.items.sort((a, b) => String(a.name || '').trim().localeCompare(String(b.name || '').trim(), undefined, { numeric: true, sensitivity: 'base' })));

  return out.filter(g => g.items.length);
}

const GROUP_ORDER = Object.fromEntries(WAREHOUSE_GROUPS.map((g, i) => [g.key, i]));

/** Does this option look like a warehouse / project? (an object that carries a category in `status`) */
const hasCategory = o => o !== null && typeof o === 'object' && typeof o.status === 'string' && o.status.trim() !== '';

/**
 * A "full" list holds Main Warehouses AND Projects (Government / Private). Only those lists are shown in sections: a list with only main warehouses,
 * only projects, or no category data at all stays exactly as it was.
 */
export function isFullWarehouseList(list) {
  if (!Array.isArray(list) || list.length < 2) return false;
  let main = false;
  let projects = false;
  for (const o of list) {
    if (!hasCategory(o)) continue;
    const g = groupOfStatus(o.status);
    if (g === 'main') main = true;
    else if (g === 'projects') projects = true;
    if (main && projects) return true;
  }

  return false;
}

/** The same list in section order (Main Warehouses, Projects, Others), each section A to Z with numbers in natural order. Does not change the original array. */
export function orderWarehouses(list) {
  const name = o => String((o && (o.label !== undefined ? o.label : o.name)) || '').trim();

  return [...list].sort((a, b) => {
    const ga = hasCategory(a) ? GROUP_ORDER[groupOfStatus(a.status)] : 2;
    const gb = hasCategory(b) ? GROUP_ORDER[groupOfStatus(b.status)] : 2;

    return ga - gb || name(a).localeCompare(name(b), undefined, { numeric: true, sensitivity: 'base' });
  });
}

export function groupKeyOf(o) {
  return hasCategory(o) ? groupOfStatus(o.status) : 'others';
}

export function groupMeta(key) {
  return WAREHOUSE_GROUPS.find(g => g.key === key) || WAREHOUSE_GROUPS[2];
}

/** The progress indicator of a project: green when it is finished, orange while it is still running. Main warehouses and other categories have none. */
export function progressOf(o) {
  if (o === null || typeof o !== 'object' || !('completed_at' in o)) return null;
  if (hasCategory(o) && groupOfStatus(o.status) !== 'projects') return null;

  return o.completed_at ? 'done' : 'running';
}
