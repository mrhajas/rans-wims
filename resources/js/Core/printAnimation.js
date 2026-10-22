import { reactive } from 'vue';

/*
 * PRINT ANIMATION (one function for every module).
 *
 *   import { printWithAnimation } from '@/Core/printAnimation';
 *   printWithAnimation({ title: 'COMPACTOR', code: 'RSI-1139', rows: [{ k: 'Category', v: 'General' }, ...], footer: 'Item sheet' }, () => window.print());
 *
 * A calm printer card shows the REAL data of the record on a sheet that rises out of the printer, then the callback runs (normally window.print()).
 * Cancel on the card stops it. After it finishes, `printUrlInFrame(url)` is the usual callback: the browser's print dialog opens on that report page. The card itself lives once in AdminLayout (Shared/PrintAnimationHost.vue); nothing else has to be added to a page.
 * `itemPrintSheet(vm, item)` builds the sheet of an Item; other modules build their own { title, code, rows, footer }.
 */
export const printState = reactive({ show: false, sheet: { report: '', title: '', code: '', rows: [], footer: '' }, cb: null });

export function printWithAnimation(sheet, cb) {
  printState.sheet = { report: '', title: '', code: '', rows: [], footer: '', ...(sheet || {}) };
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

/**
 * Opens the browser's print dialog for a report page, without leaving the page and without a pop-up (a pop-up opened after the animation, more than a few seconds
 * after the click, is blocked by the browser). The report is loaded in a hidden frame (?embed=1: it does not print itself) and printed from here once it is ready.
 */
export function printUrlInFrame(url) {
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none';
  const done = () => setTimeout(() => frame.remove(), 1000);
  let fired = false;
  frame.onload = () => {
    if (fired) return;
    fired = true;
    // let the report's images (logo, barcode, QR) settle, then open the print dialog
    setTimeout(() => {
      try {
        frame.contentWindow.focus();
        frame.contentWindow.addEventListener('afterprint', done);
        frame.contentWindow.print();
      } catch (e) {
        window.open(url, '_blank'); // the frame could not print: open the report itself
        done();
      }
      setTimeout(() => frame.remove(), 120000); // never leave it behind
    }, 500);
  };
  frame.src = url + (url.includes('?') ? '&' : '?') + 'embed=1';
  document.body.appendChild(frame);
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

  return { report: vm.$t('Item'), title: it.name, code: it.code, rows, footer: vm.$t('Item sheet') + ' · ' + vm.$date(new Date().toISOString().slice(0, 10)) };
}

/* ---- the sheets of the other documents (Check-in, Check-out, Transfer, Material Request, Gate Pass, Delivery Note) ----
 * Each builds { report, title, code, rows, footer } from what the page already holds (a list row has fewer fields than a detail page; a line that is not known is left out).
 * The animation shows only the first lines of a long document. */
const clean = rows => rows.filter(r => r && r.v !== undefined && r.v !== null && String(r.v).trim() !== '' && r.v !== '-');
const footerOf = (vm, report) => report + ' ' + vm.$t('sheet') + ' · ' + vm.$date(new Date().toISOString().slice(0, 10));
function lineTotals(items) {
  let qty = 0;
  let value = 0;
  (items || []).forEach(i => {
    const vars = i.variations || [];
    if (vars.length) vars.forEach(v => { qty += Number((v.pivot || {}).quantity || 0); });
    else { qty += Number(i.quantity || 0); value += Number(i.quantity || 0) * Number(i.price || 0); }
  });
  return { count: (items || []).length, qty, value };
}

/** A Check-in or a Check-out (kind = 'Check-in' | 'Check-out'). */
export function stockDocumentPrintSheet(vm, doc, kind) {
  if (!doc) return { report: kind, rows: [] };
  const t = lineTotals(doc.items);
  const report = vm.$t(kind);
  return {
    report, title: report, code: doc.reference,
    rows: clean([
      { k: vm.$t('Date'), v: doc.date ? vm.$date(doc.date) : '' },
      { k: vm.$t('Contact'), v: doc.contact ? doc.contact.name : '' },
      { k: vm.$t('Warehouse'), v: doc.warehouse ? doc.warehouse.name : '' },
      { k: vm.$t('Items'), v: doc.items ? String(t.count) : '' },
      { k: vm.$t('Quantity'), v: doc.items ? vm.$number(t.qty) : '' },
      { k: vm.$t('Total'), v: doc.items && t.value ? vm.$number(t.value) + ' Tsh' : '' },
    ]),
    footer: footerOf(vm, report),
  };
}

export function transferPrintSheet(vm, tr) {
  if (!tr) return { report: vm.$t('Transfer'), rows: [] };
  const t = lineTotals(tr.items);
  const report = vm.$t('Transfer');
  return {
    report, title: report, code: tr.reference,
    rows: clean([
      { k: vm.$t('Date'), v: tr.date ? vm.$date(tr.date) : '' },
      { k: vm.$t('From'), v: tr.from_warehouse ? tr.from_warehouse.name : '' },
      { k: vm.$t('To'), v: tr.to_warehouse ? tr.to_warehouse.name : '' },
      { k: vm.$t('Items'), v: tr.items ? String(t.count) : '' },
      { k: vm.$t('Quantity'), v: tr.items ? vm.$number(t.qty) : '' },
      { k: vm.$t('Status'), v: tr.approval_status || '' },
    ]),
    footer: footerOf(vm, report),
  };
}

export function materialRequestPrintSheet(vm, mr) {
  const report = vm.$t('Material Request');
  if (!mr) return { report, rows: [] };
  return {
    report, title: report, code: mr.reference,
    rows: clean([
      { k: vm.$t('Date Requested'), v: mr.date_requested ? vm.$date(mr.date_requested) : '' },
      { k: vm.$t('Project(s) / Warehouse(s)'), v: mr.warehouse ? mr.warehouse.name : '' },
      { k: vm.$t('Requested By'), v: mr.user ? mr.user.name : '' },
      { k: vm.$t('Status'), v: mr.status_label || mr.status || '' },
      { k: vm.$t('Items'), v: mr.items ? String(mr.items.length) : '' },
    ]),
    footer: footerOf(vm, report),
  };
}

/** A Gate Pass or a Delivery Note, from the state its button holds (state.note, state.record_reference, state.details). */
export function paperworkPrintSheet(vm, state, kind) {
  const report = vm.$t(kind);
  const d = (state && state.details) || {};
  return {
    report, title: report, code: state && state.note ? state.note.reference : '',
    rows: clean([
      { k: vm.$t('For'), v: state ? state.record_reference : '' },
      { k: vm.$t('Driver'), v: (d.driver_label || '').trim() },
      { k: vm.$t('Vehicle'), v: (d.vehicle_label || '').trim() },
    ]),
    footer: footerOf(vm, report),
  };
}
