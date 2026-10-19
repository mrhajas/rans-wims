// Main modules are listed alphabetically by their displayed (translated) label.
// Dashboard stays first and the "Misc" group (System Logs onwards) keeps its
// fixed order at the bottom.
export default function sortMenus(menus, label) {
  const miscStart = menus.findIndex((m) => m.heading === 'Misc');
  if (miscStart < 1) return menus;
  const main = menus
    .slice(1, miscStart)
    .map((m) => ({ m, k: String(label(m) || '') }))
    .sort((a, b) => a.k.localeCompare(b.k, undefined, { sensitivity: 'base' }))
    .map((x) => x.m);
  return [menus[0], ...main, ...menus.slice(miscStart)];
}
