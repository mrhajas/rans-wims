// Display names for the trip departure / destination types. The stored value stays "Warehouse" (so nothing in the
// database or the reports changes); on screen it is called "Main Warehouses", because that is the list it picks from.
export const MIXED_TYPE = 'Mixed Main Warehouses';

// Plain text on purpose: the translation file maps "Main Warehouses" to "Main Project(s) / Warehouse(s)" for other pages.
export const MAIN_WAREHOUSES = 'Main Warehouses';

export function transportTypeLabel(t, type) {
  if (type === 'Warehouse') return MAIN_WAREHOUSES;
  if (type === MIXED_TYPE) return 'Mixed MainWarehouses';

  return t(type);
}
