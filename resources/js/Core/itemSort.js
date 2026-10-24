// The order the item lines of a Super Adjustment are shown in. The server uses the same keys for the value report, the print, the PDF and the Excel file
// (App\Support\ItemSort): az = item name A-Z, category = by category then name, high = largest physical count first, low = smallest first.
export const SORT_KEYS = ['az', 'category', 'high', 'low'];

const natural = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), undefined, { numeric: true, sensitivity: 'base' });

export function sortLines(list, key, get = {}) {
  if (!SORT_KEYS.includes(key)) return list;
  const name = get.name || (l => l.name);
  const category = get.category || (l => l.category);
  const qty = get.qty || (l => l.physical_quantity);
  const byName = (a, b) => natural(name(a), name(b));
  return [...list].sort((a, b) => {
    if (key === 'az') return byName(a, b);
    if (key === 'category') {
      const ca = category(a);
      const cb = category(b);
      if (!ca && cb) return 1;
      if (ca && !cb) return -1;
      return natural(ca, cb) || byName(a, b);
    }
    const d = Number(qty(a) ?? 0) - Number(qty(b) ?? 0);
    return (key === 'high' ? -d : d) || byName(a, b);
  });
}
