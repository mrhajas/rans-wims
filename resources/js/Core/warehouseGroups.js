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
    out.find(g => g.key === groupOfStatus(w.status)).items.push({ ...w, tag: tagOfStatus(w.status) });
  });
  out.forEach(g => g.items.sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' })));

  return out.filter(g => g.items.length);
}
