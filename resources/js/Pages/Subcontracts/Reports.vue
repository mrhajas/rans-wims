<template>
  <admin-layout :title="$t('Subcontract Reports')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Subcontract Reports')" :subtitle="$t('Contracts, variations, requests, vouchers and balances, in the WIMS report layout. Print, PDF, Excel and CSV; every export is recorded.')">
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
      </page-header>
      <section class="sc-rep">
        <div v-for="g in groups" :key="g.name" class="sc-rg">
          <div class="sc-rg-name">{{ $t(g.name) }}</div>
          <div class="sc-rep-grid"><button v-for="c in g.items" :key="c.key" type="button" class="sc-rep-card" :class="['t-' + g.tone, { 'is-on': t === c.key }]" :data-tip="c.title + ': ' + c.about" :aria-label="c.title" :aria-pressed="t === c.key" @click="pick(c.key)"><i><icons :name="g.icon" class="w-4 h-4" /></i><span>{{ label(c) }}</span></button></div>
        </div>
      </section>
      <section v-if="current" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ current.title }}</h2><p class="lg-card-sub">{{ current.about }}</p></div></header>
        <form id="sc-rep-form" class="ac-filters sc-rep-filters" @submit.prevent="run">
          <div v-if="uses('contact_id')" class="ac-f"><label>{{ $t('Subcontractor') }}</label><acct-select v-model="f.contact_id" :options="contacts" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('agreement_id')" class="ac-f"><label>{{ $t('Agreement') }}<b v-if="t === 'agreement_detail'"> *</b></label><acct-select v-model="f.agreement_id" :options="agreements" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('warehouse_id')" class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}</label><acct-select v-model="f.warehouse_id" :options="projects" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('status')" class="ac-f"><label>{{ $t('Status') }}</label><input v-model="f.status" :placeholder="$t('e.g. Approved')" /></div>
          <div v-if="uses('currency')" class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('kind')" class="ac-f"><label>{{ $t('Line type') }}</label><acct-select v-model="f.kind" :options="kinds" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('from')" class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div v-if="uses('to')" class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
        </form>
        <div class="sc-rep-bar">
          <button type="submit" form="sc-rep-form" class="lg-btn lg-btn-primary" :disabled="loading"><icons :name="loading ? 'refresh' : 'search'" class="w-4 h-4" :class="{ 'animate-spin': loading }" />{{ loading ? $t('Loading...') : $t('Show report') }}</button>
          <span v-if="can.print && report" class="sc-rep-out">
            <a :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft t-slate lg-tip" :data-tip="$t('Open the printable report in a new tab')"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
            <a :href="url('pdf')" class="lg-btn lg-btn-soft t-teal lg-tip" :data-tip="$t('Download as a PDF file')"><icons name="download" class="w-4 h-4" />PDF</a>
            <a :href="url('xlsx')" class="lg-btn lg-btn-soft t-teal lg-tip" :data-tip="$t('Download as an Excel workbook')"><icons name="download" class="w-4 h-4" />Excel</a>
            <a :href="url('csv')" class="lg-btn lg-btn-soft t-teal lg-tip" :data-tip="$t('Download as a CSV file')"><icons name="download" class="w-4 h-4" />CSV</a>
          </span>
        </div>
        <p v-if="error" class="ac-err" style="margin-top: 8px">{{ error }}</p>
      </section>

      <section v-if="loading && !report" class="lg-card sc-rep-wait"><icons name="refresh" class="w-5 h-5 animate-spin" /><span>{{ $t('Loading...') }}</span></section>

      <section v-if="report" class="lg-card">
        <div class="ac-split" style="margin-bottom: 8px"><span v-for="(v, k) in report.filters" :key="k">{{ k }}: <b>{{ v }}</b></span></div>
        <div v-if="report.tiles.length > 1" class="ac-attn sc-rep-tiles"><div v-for="x in report.tiles" :key="x.label" class="ac-tile ac-glow tone-navy" :class="{ 'sc-tile-click': true, 'is-on': tileCur(x) ? curSel === tileCur(x) : (!curSel && x === report.tiles[0]) }" role="button" tabindex="0" :title="tileCur(x) ? $t('Show only :c rows', { c: tileCur(x) }) : $t('Show all rows')" @click="tileClick(x)" @keydown.enter="tileClick(x)"><div><div class="n" style="font-size: 1rem">{{ x.value }}</div><div class="l">{{ x.label }}</div></div></div></div>

        <search-bar v-model="tq" :placeholder="$t('Find in these results...')" :count="shown.length" />

        <div class="sc-sortbar"><label>{{ $t('Sort by') }}</label><css-select :value="sort.key || ''" @change="sortKey($event.target.value)"><option value="">{{ $t('Original order') }}</option><option v-for="c in report.columns" :key="c.key" :value="c.key">{{ c.label }}</option></css-select><button type="button" class="lg-btn t-slate" :disabled="!sort.key" @click="sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'">{{ sort.dir === 'asc' ? $t('Ascending') : $t('Descending') }}</button></div>
        <div class="lg-table-wrap sc-rep-scroll"><table class="lg-table lg-responsive sc-rep-table">
          <thead><tr class="sc-hrow"><sort-th v-for="(c, ci) in report.columns" :key="c.key" :col="c.key" :tone="tone(c, ci)" :num="c.align === 'right'" :sort="sort.key" :dir="sort.dir" @sort="sortBy({ key: $event })">{{ c.label }}</sort-th><th v-if="hasLinks" class="hd-end"></th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in sorted" :key="i" :class="{ 'is-click': href(row) }" :tabindex="href(row) ? 0 : null" @click="open(row)" @keydown.enter="open(row)">
              <td v-for="c in report.columns" :key="c.key" :data-label="c.label" :class="{ 'ac-num': c.align === 'right' }"><span v-if="c.type === 'money' && !empty(row[c.key])" :class="amtClass(c, row[c.key])">{{ withCur(row, row[c.key]) }}</span><mark-hit v-else :text="cell(row[c.key])" :q="tq" /></td>
              <td v-if="hasLinks" class="lg-sticky-end" @click.stop><Link v-if="href(row)" :href="href(row)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
            </tr>
            <tr v-if="!shown.length" class="lg-empty-row"><td class="lg-empty" :colspan="report.columns.length + (hasLinks ? 1 : 0)"><div class="sc-empty"><icons name="search" class="w-6 h-6" /><span>{{ report.rows.length ? $t('No rows match your search.') : $t('Nothing was recorded for this report.') }}</span><button v-if="report.rows.length" type="button" class="lg-btn lg-btn-soft" @click="clearFind"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></td></tr>
          </tbody>
          <tfoot v-if="totals.length"><tr v-for="x in totals" :key="x.cur" class="sc-tot">
            <td v-for="(c, ci) in report.columns" :key="c.key" :data-label="c.label" :class="{ 'ac-num': c.align === 'right', 'sc-tot-empty': x.vals[c.key] === undefined && c.key !== 'currency' && ci !== 0 }"><template v-if="x.vals[c.key] !== undefined"><span :class="amtClass(c, x.vals[c.key])">{{ x.cur && hasCurrency ? x.cur + ' ' : '' }}{{ x.vals[c.key] }}</span></template><template v-else-if="c.key === 'currency'">{{ x.cur }}</template><template v-else-if="ci === 0"><b>{{ $t('Total') }}</b></template></td>
            <td v-if="hasLinks"></td>
          </tr></tfoot>
        </table></div>
        <p v-if="totals.length" class="lg-sub sc-tot-note">{{ $t('Totals add up the rows shown, per currency.') }}</p>
        <p v-if="report.truncated" class="lg-sub" style="margin-top: 6px">{{ $t('Showing the first :n of :t rows. Export to get all of them.', { n: report.rows.length, t: report.total_rows }) }}</p>
        <ul class="lg-sub" style="margin-top: 10px; padding-left: 18px; list-style: disc"><li v-for="n in report.notes" :key="n">{{ n }}</li></ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import SearchBar from './SearchBar.vue';
import SortTh from './SortTh.vue';
import { tableFind } from './sc';

const SHORT = { opening_balances: 'Opening', register: 'Register', statement: 'Statement', financial_summary: 'Finances', consolidated: 'Consolidated', agreements: 'Agreements', agreement_detail: 'Breakdown', work_schedule: 'Work Items', group_summary: 'Work Groups', original_revised: 'Revised Value', variations: 'Variations', completed: 'Closed', requests: 'Requests', pending: 'Pending', approved_unpaid: 'To Pay', completed_eligible: 'Eligible', claims: 'Contract Requests', claim_allocations: 'Allocations', voucher_history: 'Vouchers', payments: 'Payments', payments_project: 'By Project', payments_location: 'By Location', deductions: 'Deductions', currency: 'Currencies', commitments: 'Commitments', payables: 'Payables', reconciliation: 'Reconcile', import: 'Imports', audit: 'Audit' };

// the part of a text that matches the find field is marked
const MarkHit = {
  props: { text: [String, Number], q: String },
  render() {
    const t = String(this.text === null || this.text === undefined ? '' : this.text); const q = String(this.q || '').trim();
    const i = q ? t.toLowerCase().indexOf(q.toLowerCase()) : -1;
    if (i < 0) return t;
    return [t.slice(0, i), h('mark', { class: 'sc-mark' }, t.slice(i, i + q.length)), t.slice(i + q.length)];
  },
};

// money for the totals row is added as whole cents (BigInt), never through floating point
const cents = v => {
  const s = String(v === null || v === undefined ? '' : v).replace(/,/g, '').trim();
  if (!/^-?\d+(\.\d+)?$/.test(s)) return null;
  const neg = s[0] === '-'; const [i, f = ''] = s.replace('-', '').split('.');
  const c = BigInt(i) * BigInt(100) + BigInt((f + '00').slice(0, 2));
  return neg ? -c : c;
};
const fromCents = c => {
  const neg = c < BigInt(0); const a = neg ? -c : c;
  return (neg ? '-' : '') + (a / BigInt(100)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (a % BigInt(100)).toString().padStart(2, '0');
};

export default {
  components: { SortTh, MarkHit, SearchBar, AdminLayout, PageHeader, Link, DatePicker, AcctSelect },
  mixins: [tableFind],
  props: { catalog: Array, type: String, filters: Object, contacts: Array, agreements: Array, projects: Array, currencies: Array, can: Object },
  data() { return { t: this.type, report: null, loading: false, error: '', f: { ...this.filters }, curSel: '', sort: { key: '', dir: 'asc' } }; },
  computed: {
    current() { return this.catalog.find(c => c.key === this.t); },
    kinds() { return [['contract', 'Contract'], ['variation', 'Variation'], ['request', 'Request approved'], ['legacy_payment', 'Payment (historical)'], ['payment', 'Payment'], ['voucher', 'Voucher']].map(([value, label]) => ({ value, label: this.$t(label) })); },
    groups() {
      const look = { Subcontractors: ['users', 'navy'], Agreements: ['doc-text', 'emerald'], Payments: ['cash', 'sky'], Balances: ['collection', 'amber'], Control: ['clock', 'slate'] };
      const out = [];
      this.catalog.forEach(c => { let g = out.find(x => x.name === c.group); if (!g) { g = { name: c.group, icon: (look[c.group] || ['doc', 'navy'])[0], tone: (look[c.group] || ['doc', 'navy'])[1], items: [] }; out.push(g); } g.items.push(c); });
      return out;
    },
    hasCurrency() { return !!this.report && this.report.columns.some(c => c.key === 'currency'); },
    // the rows after the currency tile and the find field
    shown() {
      if (!this.report) return [];
      const keys = this.report.columns.map(c => c.key);
      return this.report.rows.filter(r => (!this.curSel || !this.hasCurrency || r.currency === this.curSel) && this.has(...keys.map(k => r[k])));
    },
    // the rows in the order chosen by clicking a heading (the totals below always add up the rows shown, whatever the order)
    sorted() {
      const k = this.sort.key; if (!k || !this.report) return this.shown;
      const c = this.report.columns.find(x => x.key === k); if (!c) return this.shown;
      const kind = this.sortKind(c); const dir = this.sort.dir === 'desc' ? -1 : 1;
      const val = (r) => { const v = r[k]; if (this.empty(v)) return null; if (kind === 'money') return cents(v); if (kind === 'num') { const n = parseFloat(String(v).replace(/,/g, '')); return isNaN(n) ? null : n; } return String(v).toLowerCase(); };
      return this.shown.map((r, i) => [r, i]).sort((a, b) => {
        const x = val(a[0]), y = val(b[0]);
        if (x === null && y === null) return a[1] - b[1];
        if (x === null) return 1; if (y === null) return -1;     // empty values always last
        const d = kind === 'text' || kind === 'date' ? String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' }) : (x < y ? -1 : x > y ? 1 : 0);
        return d === 0 ? a[1] - b[1] : d * dir;
      }).map(p => p[0]);
    },
    hasLinks() { return this.shown.some(r => this.href(r)); },
    // one totals row per currency (a report without a currency column has one): money columns only, the running balance of a statement is not added up
    totals() {
      if (!this.report || !this.shown.length) return [];
      const cols = this.report.columns.filter(c => c.type === 'money' && !(this.t === 'statement' && c.key === 'balance'));
      if (!cols.length) return [];
      const groups = {}; const order = [];
      this.shown.forEach(r => {
        const g = this.hasCurrency ? String(r.currency || '') : '';
        if (!groups[g]) { groups[g] = {}; order.push(g); }
        cols.forEach(c => { const v = cents(r[c.key]); if (v !== null) groups[g][c.key] = (groups[g][c.key] === undefined ? BigInt(0) : groups[g][c.key]) + v; });
      });
      return order.filter(g => Object.keys(groups[g]).length).map(g => ({ cur: g, vals: Object.fromEntries(Object.entries(groups[g]).map(([k, v]) => [k, fromCents(v)])) }));
    },
  },
  mounted() { this.run(); },
  methods: {
    label(c) { return this.$t(SHORT[c.key] || c.title); },
    // how a column is read for sorting and which colour its heading wears
    sortKind(c) { if (c.type === 'money') return 'money'; if (c.type === 'num') return 'num'; return /date|_at$|^as_at|^since|^from$|^to$/.test(c.key) ? 'date' : 'text'; },
    tone(c, i) { if (c.type === 'money') return 'money'; if (c.type === 'num') return 'num'; if (this.sortKind(c) === 'date') return 'date'; if (/status|state|kind|type|currency/.test(c.key)) return 'state'; return i === 0 ? 'key' : 'text'; },
    // click: ascending, again: descending, again: back to the original order
    sortBy(c) { if (this.sort.key !== c.key) this.sort = { key: c.key, dir: 'asc' }; else if (this.sort.dir === 'asc') this.sort.dir = 'desc'; else this.sort = { key: '', dir: 'asc' }; },
    sortKey(k) { this.sort = { key: k, dir: this.sort.dir || 'asc' }; },
    uses(k) { return this.current && this.current.filters.includes(k); },
    params() { const q = { type: this.t }; (this.current ? this.current.filters : []).forEach(k => { if (this.f[k] !== '' && this.f[k] !== null && this.f[k] !== undefined) q[k] = this.f[k]; }); return q; },
    url(fmt) { return route('subcontracts.reports.' + fmt, this.params()); },
    pick(k) { this.t = k; this.report = null; this.error = ''; this.tq = ''; this.curSel = ''; history.replaceState(null, '', route('subcontracts.reports.index', { type: k })); this.run(); },
    empty(v) { return v === null || v === undefined || v === ''; },
    cell(v) { return this.empty(v) ? '-' : v; },
    // an amount with its currency: the row's own, else the one the report was filtered to
    withCur(row, v) { const c = row.currency || (this.f && this.f.currency) || (this.report && this.report.currency) || ''; return (c ? c + ' ' : '') + v; },
    // amount colours as in the Register: contract blue, paid green, outstanding amber, approved sky, negative red
    amtClass(c, v) {
      if (c.type !== 'money') return '';
      if (v !== undefined && String(v).startsWith('-')) return 'amt-neg';
      const k = c.key;
      if (/unpaid|outstanding|left|balance|in_process/.test(k)) return 'amt-out';
      if (/paid|live|legacy/.test(k)) return 'amt-paid';
      if (/approved|dated/.test(k)) return 'amt-appr';
      if (/revised|original|contract|commitment|requested|variations|^up$|^down$/.test(k)) return 'amt-rev';
      return '';
    },
    // a tile such as "Paid (USD)" shows only the USD rows when a report has a currency column; the first tile shows every row
    tileCur(x) { const m = String(x.label || '').match(/\(([A-Z]{3})\)\s*$/); return this.hasCurrency && m ? m[1] : ''; },
    tileClick(x) { this.curSel = this.tileCur(x) && this.curSel !== this.tileCur(x) ? this.tileCur(x) : ''; },
    clearFind() { this.tq = ''; this.curSel = ''; },
    // the record a row opens, only when the person may open that kind of record
    href(row) {
      const l = row && row._link; const ok = this.can && this.can.open;
      if (!l || !ok || !ok[l.type]) return null;
      return { agreement: () => route('subcontracts.agreements.show', l.id), request: () => route('subcontracts.requests.show', l.id), claim: () => route('subcontracts.claims.show', l.id), variation: () => route('subcontracts.variations.show', l.id), contact: () => route('subcontracts.register.show', l.id), voucher: () => route('payment-vouchers.show', l.id), import: () => route('subcontracts.import.show', l.id) }[l.type]?.() || null;
    },
    open(row) { const u = this.href(row); if (u) this.$inertia.visit(u); },
    async run() {
      if (!this.current) return;
      this.loading = true; this.error = ''; this.curSel = ''; this.sort = { key: '', dir: 'asc' };
      try { const r = await window.axios.get(route('subcontracts.reports.index'), { params: { ...this.params(), preview: 1 } }); this.report = r.data; } catch (e) { this.report = null; this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The report could not be made.'); } finally { this.loading = false; }
    },
  },
};
</script>
