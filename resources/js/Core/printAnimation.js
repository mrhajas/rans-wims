import { reactive } from 'vue';

/*
 * PRINT ANIMATION (one function for every module).
 *
 *   import { printWithAnimation } from '@/Core/printAnimation';
 *   printWithAnimation({ title: 'COMPACTOR', code: 'RSI-1139', rows: [{ k: 'Category', v: 'General' }, ...], footer: 'Item sheet' }, () => window.print());
 *
 * A calm printer card shows the REAL data of the record on a sheet that rises out of the printer, then the callback runs (normally window.print()).
 * Cancel on the card stops it. The card itself lives once in AdminLayout (Shared/PrintAnimationHost.vue); nothing else has to be added to a page.
 * `itemPrintSheet(vm, item)` builds the sheet of an Item; other modules build their own { title, code, rows, footer }.
 */
export const printState = reactive({ show: false, sheet: { title: '', code: '', rows: [], footer: '' }, cb: null });

export function printWithAnimation(sheet, cb) {
  printState.sheet = { title: '', code: '', rows: [], footer: '', ...(sheet || {}) };
  printState.cb = typeof cb === 'function' ? cb : null;
  printState.show = true;
}

export function printAnimationDone() {
  const cb = printState.cb;
  printState.show = false;
  printState.cb = null;
  // the card has to be gone before the browser's print dialog freezes the page
  if (cb) setTimeout(cb, 80);
}

export function printAnimationCancel() {
  printState.show = false;
  printState.cb = null;
}

/** The sheet of an Item, with the figures worked out exactly as the Item Details page shows them (vm = any component, for $t / $number / $date). */
export function itemPrintSheet(vm, it) {
  if (!it) return { title: '', rows: [] };
  const groups = Object.values(it.stock || {}).filter(w => (Array.isArray(w) ? w : [w]).some(s => Number(s.quantity) > 0 || (it.track_weight && Number(s.weight) > 0)));
  const qty = groups.reduce((sum, w) => sum + (Array.isArray(w) ? w : [w]).reduce((a, s) => a + Number(s.quantity || 0), 0), 0);
  const price = Number(it.price || 0);
  const unit = it.unit ? it.unit.code || it.unit.name || '' : '';
  const rows = [
    { k: vm.$t('Category'), v: it.categories && it.categories.length ? it.categories.map(c => c.name).join(' / ') : '-' },
    { k: vm.$t('Unit'), v: unit || '-' },
    { k: vm.$t('Item Price'), v: vm.$number(price) + ' Tsh' },
  ];
  if (it.track_quantity) {
    rows.push({ k: vm.$t('Quantity'), v: vm.$number(qty) + (unit ? ' ' + unit : '') });
    rows.push({ k: vm.$t('Stock Value'), v: vm.$number(qty * price) + ' Tsh' });
    rows.push({ k: vm.$t('Project(s) / Warehouse(s)'), v: String(groups.length) });
  }

  return { title: it.name, code: it.code, rows, footer: vm.$t('Item sheet') + ' · ' + vm.$date(new Date().toISOString().slice(0, 10)) };
}
