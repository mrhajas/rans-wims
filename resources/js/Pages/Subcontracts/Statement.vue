<template>
  <admin-layout :title="$t('Account Statement')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Account Statement')" :subtitle="who.title">
        <Link :href="account" class="lg-btn lg-btn-soft"><icons name="arrow-left" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <template v-if="can.print"><a :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft t-slate lg-tip" :data-tip="$t('Open the printable page in a new tab')"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a><a :href="url('pdf')" class="lg-btn lg-btn-soft t-teal lg-tip" :data-tip="$t('Download as a PDF file')"><icons name="download" class="w-4 h-4" /><span>PDF</span></a><a :href="url('xlsx')" class="lg-btn lg-btn-soft t-teal lg-tip" :data-tip="$t('Download as an Excel workbook')"><icons name="download" class="w-4 h-4" /><span>Excel</span></a></template>
      </page-header>
      <form class="lg-card" @submit.prevent="go"><div class="ac-filters"><div class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}</label><acct-select v-model="f.warehouse_id" :options="projects" :placeholder="$t('All')" clearable /></div><div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies" :placeholder="$t('All')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Line type') }}</label><acct-select v-model="f.kind" :options="kinds" :placeholder="$t('All')" clearable /></div><div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div><div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
        <div class="ac-f"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div></div></form>
      <div class="sc-over sc-reg-cards">
        <overview-card icon="list" :title="$t('Lines')" :sub="$t('Contract, variations and payments')" :value="String(report.total_rows)" :foot="shown.length === report.rows.length ? $t('Show All') : $t('Matching Search')" :class="{ 'is-on': !tq && !onlyPaid }" role="button" tabindex="0" @click="showAll" @keydown.enter="showAll" />
        <overview-card v-if="cards.paid" variant="hero" icon="cash" :title="$t('Paid')" :sub="cards.paidOthers" :value="cards.paid" :foot="onlyPaid ? $t('Show All Lines') : $t('Show Payments Only')" :class="{ 'is-on': onlyPaid }" role="button" tabindex="0" @click="onlyPaid = !onlyPaid" @keydown.enter="onlyPaid = !onlyPaid" />
        <overview-card v-if="cards.left" variant="amber" icon="clock" :title="$t('Remaining')" :sub="cards.leftOthers" :value="cards.left" :foot="$t('Open The Account')" :to="account" />
      </div>

      <search-bar v-model="tq" :placeholder="$t('Search this statement by reference, agreement or description...')" :count="shown.length" />

      <section class="lg-card sc-stm">
        <div class="lg-table-wrap sc-rep-scroll"><table class="lg-table lg-responsive sc-rep-table">
          <thead><tr>
            <th><span class="sc-th"><icons name="calendar" class="w-4 h-4" />{{ $t('Date') }}</span></th>
            <th><span class="sc-th"><icons name="tick" class="w-4 h-4" />{{ $t('Type') }}</span></th>
            <th><span class="sc-th"><icons name="doc-text" class="w-4 h-4" />{{ $t('Agreement') }}</span></th>
            <th><span class="sc-th"><icons name="hash" class="w-4 h-4" />{{ $t('Reference') }}</span></th>
            <th><span class="sc-th"><icons name="info" class="w-4 h-4" />{{ $t('Description') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-rev"><icons name="doc-text" class="w-4 h-4" />{{ $t('Contract') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-appr"><icons name="send" class="w-4 h-4" />{{ $t('Approved') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Paid') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-out"><icons name="clock" class="w-4 h-4" />{{ $t('Remaining') }}</span></th>
            <th v-if="hasLinks"></th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in shown" :key="i" :class="['sc-stm-' + kindOf(row), { 'sc-legacy': row.source !== 'WIMS', 'is-click': href(row) }]" :tabindex="href(row) ? 0 : null" @click="open(row)" @keydown.enter="open(row)">
              <td :data-label="$t('Date')" class="sc-nowrap">{{ row.date }}</td>
              <td :data-label="$t('Type')"><span class="sc-type" :class="'ty-' + kindOf(row)"><icons :name="icon(kindOf(row))" class="w-3.5 h-3.5" />{{ row.type }}</span><div class="lg-subtext">{{ row.source === 'WIMS' ? 'WIMS' : $t('Historical') }}</div></td>
              <td :data-label="$t('Agreement')"><mark-hit :text="row.agreement" :q="tq" /></td>
              <td :data-label="$t('Reference')"><mark-hit :text="row.reference" :q="tq" /></td>
              <td :data-label="$t('Description')" class="sc-desc"><mark-hit :text="row.description" :q="tq" /></td>
              <td :data-label="$t('Contract')" class="ac-num"><span class="amt-rev">{{ dash(row.commitment, row) }}</span></td>
              <td :data-label="$t('Approved')" class="ac-num"><span class="amt-appr">{{ dash(row.approved, row) }}</span></td>
              <td :data-label="$t('Paid')" class="ac-num"><span class="amt-paid">{{ dash(row.paid, row) }}</span></td>
              <td :data-label="$t('Remaining')" class="ac-num"><b :class="neg(row.balance) ? 'amt-neg' : 'amt-out'">{{ dash(row.balance, row) }}</b></td>
              <td v-if="hasLinks" class="lg-sticky-end" @click.stop><Link v-if="href(row)" :href="href(row)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
            </tr>
            <tr v-if="!shown.length" class="lg-empty-row"><td class="lg-empty" :colspan="hasLinks ? 10 : 9">{{ report.rows.length ? $t('No rows match your search.') : $t('Nothing was recorded for this statement.') }}</td></tr>
          </tbody>
        </table></div>
        <ul class="lg-sub" style="margin-top: 10px; padding-left: 18px; list-style: disc"><li v-for="n in report.notes" :key="n">{{ n }}</li></ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import OverviewCard from './OverviewCard.vue';
import SearchBar from './SearchBar.vue';
import { sc, tableFind } from './sc';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

const MarkHit = {
  props: { text: [String, Number], q: String },
  render() {
    const t = String(this.text === null || this.text === undefined ? '' : this.text); const q = String(this.q || '').trim();
    const i = q ? t.toLowerCase().indexOf(q.toLowerCase()) : -1;
    if (i < 0) return t;
    return [t.slice(0, i), h('mark', { class: 'sc-mark' }, t.slice(i, i + q.length)), t.slice(i + q.length)];
  },
};

export default {
  components: { MarkHit, SearchBar, OverviewCard, AdminLayout, PageHeader, Link, DatePicker, AcctSelect },
  mixins: [sc, tableFind],
  props: { type_keys: Object, who: Object, report: Object, filters: Object, currencies: Array, projects: Array, can: Object },
  data() { return { f: { ...this.filters }, onlyPaid: false }; },
  computed: {
    shown() {
      return this.report.rows.filter(r => (!this.onlyPaid || (r.paid !== '' && r.paid !== null && r.paid !== undefined)) && this.has(r.reference, r.agreement, r.description, r.type, r.date));
    },
    account() { return this.who.kind === 'contact' ? route('subcontracts.register.show', this.who.id) : route('subcontracts.agreements.show', this.who.id); },
    hasLinks() { return this.shown.some(r => this.href(r)); },
    // paid and remaining per currency: the paid tiles of the report, and the balance on the last line of each currency
    cards() {
      const tiles = this.report.tiles.filter(t => /^Paid|^Payé/i.test(t.label) || t.label.indexOf('(') > 0);
      const paid = tiles.map(t => (t.label.match(/\(([A-Z]{3})\)/) || [])[1] ? (t.label.match(/\(([A-Z]{3})\)/)[1] + ' ' + t.value) : t.value);
      const last = {}; this.report.rows.forEach(r => { last[r.currency] = r.balance; });
      const left = Object.keys(last).map(c => c + ' ' + last[c]);
      return { paid: paid[0] || '', paidOthers: paid.slice(1).join(' · '), left: left[0] || '', leftOthers: left.slice(1).join(' · ') };
    }, kinds() { return [['contract', 'Contract'], ['variation', 'Variation'], ['request', 'Request approved'], ['legacy_payment', 'Payment (historical)'], ['payment', 'Payment'], ['voucher', 'Voucher']].map(([value, label]) => ({ value, label: this.$t(label) })); } },
  methods: {
    kindOf(row) { return (this.type_keys || {})[row.type] || 'other'; },
    icon(k) { return { contract: 'doc-text', variation: 'adjustments', request: 'send', legacy_payment: 'archive-box', payment: 'cash', voucher: 'doc' }[k] || 'info'; },
    showAll() { this.tq = ''; this.onlyPaid = false; },
    reset() { this.f = {}; this.go(); },
    // the record a line opens, only when the person may open that kind of record
    href(row) {
      const l = row && row._link; const ok = this.can && this.can.open;
      if (!l || !ok || !ok[l.type]) return null;
      return { agreement: () => route('subcontracts.agreements.show', l.id), request: () => route('subcontracts.requests.show', l.id), variation: () => route('subcontracts.variations.show', l.id), voucher: () => route('payment-vouchers.show', l.id) }[l.type]?.() || null;
    },
    open(row) { const u = this.href(row); if (u) this.$inertia.visit(u); },
    dash(v, row) { return v === null || v === undefined || v === '' ? '-' : ((row && row.currency ? row.currency + ' ' : '') + v); },
    qs() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); return q; },
    go() { const r = this.who.kind === 'contact' ? 'subcontracts.statements.contact' : 'subcontracts.statements.agreement'; this.$inertia.get(route(r, this.who.id), this.qs(), { preserveState: true }); },
    url(fmt) { return route('subcontracts.statements.' + fmt, { kind: this.who.kind, id: this.who.id, ...this.qs() }); },
  },
};
</script>

<style scoped>
.sc-legacy td { background: rgba(124, 58, 237, 0.04); }
.sc-nowrap { white-space: nowrap; }
.sc-desc { max-width: 340px; white-space: normal !important; }
</style>
