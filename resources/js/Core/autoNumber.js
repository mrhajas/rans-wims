// Every data table of the app gets a "No" (row number) column, whichever page it is on, unless it already has one.
//
// It works on the finished page, not on each template: a data table is one whose header row has two or more header cells and that has at least one
// ordinary row. The number cell is inserted in front of every row (and "No" in the header), rows that are not data rows (a total, a group title, "nothing to
// show") get an empty cell so the columns stay lined up. Numbers continue across the pages of a paginated list (page 2 starts at 16). It never touches a table
// that already starts with No / # / S/N, a table marked data-no-number, a table with a sticky first column, or a calendar.
import i18n from '@/Core/i18n';

const NUMBER_HEAD = /^\s*(no\.?|#|s\/n|sn|sr\.?|sl\.?|number)\s*$/i;
const WEEKDAYS = /^(mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday|m|t|w|f|s)$/i;
const SKIP = '[data-no-number], .no-auto-no, [class*="calendar"], [class*="-cal-"], .dp-panel, .dp-grid';
const NOT_DATA_ROW = /(total|subtotal|group|cat-row|section|summary|grand|lg-empty-row|is-group|tr-group)/i;

let offsets = []; // [{ count, from }] the paginated lists of the page: how many rows they show and the number of their first row
let queued = false;
const touched = new Set();
let observer = null;

const label = () => {
  try {
    return i18n.global.t('No');
  } catch (e) {
    return 'No';
  }
};

const cellsOf = row => Array.from(row.children).filter(c => c.tagName === 'TD' || c.tagName === 'TH');
const span = c => Math.max(1, parseInt(c.getAttribute('colspan') || '1', 10) || 1);
const isOurs = c => c && c.dataset && c.dataset.autono === '1';

function headerRow(table) {
  const head = table.tHead && table.tHead.rows.length ? table.tHead : null;
  const rows = head ? Array.from(head.rows) : table.rows.length ? [table.rows[0]] : [];
  const r = rows.find(x => cellsOf(x).some(c => c.tagName === 'TH'));
  return r ? { row: r, inHead: !!head, headRows: rows.length } : null;
}

function shouldSkip(table) {
  if (table.closest(SKIP) || table.matches('[class*="sticky"]') || table.querySelector('table')) return true;
  const hr = headerRow(table);
  if (!hr) return true;
  const hc = cellsOf(hr.row).filter(c => !isOurs(c));
  if (hc.length < 2 || hc.some(c => c.tagName !== 'TH')) return true;
  const first = hc[0];
  if (NUMBER_HEAD.test(first.textContent) || first.classList.contains('idx') || first.classList.contains('txn-no-th')) return true;
  if (hc.every(c => WEEKDAYS.test(c.textContent.trim()))) return true;
  return false;
}

// the rows that are neither in the head nor in the foot (a row written straight under <table> has no <tbody> when Vue builds it, so tBodies is not enough)
function dataRows(table) {
  const hr = headerRow(table);
  return Array.from(table.rows).filter(r => r.parentElement.tagName !== 'THEAD' && r.parentElement.tagName !== 'TFOOT' && (!hr || r !== hr.row));
}

function isDataRow(row, cols) {
  if (NOT_DATA_ROW.test(row.className || '')) return false;
  const cells = cellsOf(row).filter(c => !isOurs(c));
  // a few tables have one more or one less cell than header cells (an actions cell with no title): those rows are still data rows
  if (cells.length < 2 || cells.length < cols - 1 || cells.length > cols + 1) return false;
  return cells.every(c => c.tagName === 'TD' && span(c) === 1 && !c.classList.contains('lg-empty'));
}

function makeCell(tag, text) {
  const c = document.createElement(tag);
  c.dataset.autono = '1';
  c.className = 'autono-' + (tag === 'th' ? 'th' : 'td');
  c.setAttribute('data-label', label());
  c.textContent = text;
  return c;
}

// a row may carry data-no-suffix="Code: RSI-1139": on a phone the number cell then reads "1 (Code: RSI-1139)" (see .autono-td[data-suffix] in app.css)
function syncSuffix(row) {
  const cell = row.firstElementChild;
  if (!isOurs(cell)) return;
  const text = row.dataset ? row.dataset.noSuffix : '';
  if (text) {
    if (cell.dataset.suffix !== text) cell.dataset.suffix = text;
  } else if (cell.dataset.suffix) {
    delete cell.dataset.suffix;
  }
}

function offsetFor(count) {
  const i = offsets.findIndex(o => o.count === count && !o.used);
  if (i < 0) return 0;
  offsets[i].used = true;

  return Math.max(0, offsets[i].from - 1);
}

function process(table) {
  if (!table.isConnected || shouldSkip(table)) return;
  const rows = dataRows(table);
  const hr = headerRow(table);
  const cols = cellsOf(hr.row).filter(c => !isOurs(c)).reduce((s, c) => s + span(c), 0);
  const dRows = rows.filter(r => isDataRow(r, cols) || (isOurs(r.firstElementChild) && r.firstElementChild.textContent !== '' && cellsOf(r).length - 1 === cols));
  if (!dRows.length) return;

  // header
  if (!isOurs(hr.row.firstElementChild)) {
    const th = makeCell('th', label());
    if (hr.inHead && hr.headRows > 1 && hr.row === table.tHead.rows[0]) th.setAttribute('rowspan', String(hr.headRows));
    hr.row.insertBefore(th, hr.row.firstElementChild);
  }
  // rows
  const offset = offsetFor(dRows.length);
  let n = offset;
  rows.forEach(r => {
    const data = isDataRow(r, cols) || dRows.includes(r);
    const first = r.firstElementChild;
    if (!isOurs(first)) {
      r.insertBefore(makeCell('td', data ? String(++n) : ''), first);
      syncSuffix(r);
      return;
    }
    if (data) {
      n += 1;
      if (first.textContent !== String(n)) first.textContent = String(n);
    } else if (first.textContent !== '') {
      first.textContent = '';
    }
    syncSuffix(r);
  });
  // footer rows
  if (table.tFoot) {
    Array.from(table.tFoot.rows).forEach(r => {
      if (!isOurs(r.firstElementChild)) r.insertBefore(makeCell('td', ''), r.firstElementChild);
    });
  }
}

function run() {
  queued = false;
  offsets.forEach(o => (o.used = false));
  if (observer) observer.disconnect();
  try {
    const tables = new Set();
    touched.forEach(n => {
      const t = n.nodeType === 1 ? (n.tagName === 'TABLE' ? n : n.closest && n.closest('table')) : n.parentElement && n.parentElement.closest('table');
      if (t) tables.add(t);
      if (n.nodeType === 1 && n.querySelectorAll) n.querySelectorAll('table').forEach(x => tables.add(x));
    });
    touched.clear();
    tables.forEach(process);
  } finally {
    if (observer) observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  }
}

function schedule() {
  if (queued) return;
  queued = true;
  // a hidden tab never fires animation frames, so a timer runs it as well (whichever comes first)
  let done = false;
  const once = () => {
    if (done) return;
    done = true;
    run();
  };
  requestAnimationFrame(once);
  setTimeout(once, 120);
}

// the paginated lists of the page (Laravel paginators): how many rows they show and where their numbering starts
export function setPagination(props) {
  offsets = [];
  const seen = new Set();
  const walk = (v, depth) => {
    if (!v || typeof v !== 'object' || depth > 3 || seen.has(v)) return;
    seen.add(v);
    const meta = v.meta && typeof v.meta === 'object' ? v.meta : v;
    if (Array.isArray(v.data) && Number.isFinite(meta.from) && Number.isFinite(meta.current_page)) {
      offsets.push({ count: v.data.length, from: meta.from, used: false });
      return;
    }
    Object.values(v).forEach(x => walk(x, depth + 1));
  };
  walk(props, 0);
  document.querySelectorAll('table').forEach(t => touched.add(t));
  schedule();
}

export function initAutoNumber() {
  if (observer || typeof MutationObserver === 'undefined') return;
  observer = new MutationObserver(muts => {
    for (const m of muts) {
      if (m.target && m.target.closest && m.target.closest('[data-autono="1"]')) continue;
      if (m.type === 'characterData') {
        touched.add(m.target);
      } else {
        touched.add(m.target);
        m.addedNodes.forEach(n => n.nodeType === 1 && !isOurs(n) && touched.add(n));
      }
    }
    if (touched.size) schedule();
  });
  observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  document.querySelectorAll('table').forEach(t => touched.add(t));
  schedule();
}
