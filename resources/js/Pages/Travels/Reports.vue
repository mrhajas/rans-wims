<template>
  <admin-layout :title="$t('Travel Reports')">
    <div class="lg-page ac sc-ui tr-ui px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Travel Reports')" :subtitle="$t('Every travel and every ticket agent: choose a report, set the filters (country, local or international, agent, dates) and print or download it. The printed report shows who generated it, when, the filters and a QR code, and every export is recorded.')">
        <Link :href="route('travels.index')" class="lg-btn lg-btn-soft"><icons name="plane" class="w-4 h-4" /><span>{{ $t('Travels') }}</span></Link>
      </page-header>
      <sc-tabs :tabs="tabs" :model-value="t" @update:model-value="pick" />
      <section v-if="current" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ current.title }}</h2><p class="lg-card-sub">{{ current.about }}</p></div></header>
        <div class="tr-fbar">
          <div v-if="uses('search')" class="ac-f"><label>{{ $t('Search') }}</label><input v-model="f.search" :placeholder="$t('Traveller, destination, flight, reference')" /></div>
          <div v-if="uses('section')" class="ac-f"><label>{{ $t('Section') }}</label><acct-select v-model="f.section" :options="sections" :placeholder="$t('Both')" clearable /></div>
          <div v-if="uses('category')" class="ac-f"><label>{{ $t('Type of cost') }}</label><acct-select v-model="f.category" :options="categories.map(c => ({ value: c, label: c }))" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('contact_id')" class="ac-f"><label>{{ $t('Agent') }}<b v-if="t === 'agent_statement'"> *</b></label><acct-select v-model="f.contact_id" :options="agents" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('scope')" class="ac-f"><label>{{ $t('Local / international') }}</label><acct-select v-model="f.scope" :options="scopes" :placeholder="$t('Both')" clearable /></div>
          <div v-if="uses('country')" class="ac-f"><label>{{ $t('Country') }}</label><acct-select v-model="f.country" :options="countryOptions" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('currency')" class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies.map(c => ({ value: c, label: c }))" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('status')" class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statuses" :placeholder="defaultStatus" clearable /></div>
          <div v-if="uses('status_payment')" class="ac-f"><label>{{ $t('Payment status') }}</label><acct-select v-model="f.status_payment" :options="paymentStatuses" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('from')" class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div v-if="uses('to')" class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div v-if="uses('as_of')" class="ac-f"><label>{{ $t('As of') }}</label><date-picker v-model="f.as_of" /></div>
          <label v-if="uses('only_owing')" class="tr-check"><input v-model="f.only_owing" type="checkbox" true-value="1" false-value="" />{{ $t('Only agents we owe') }}</label>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="clear"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': loading }"><i></i>{{ loading ? $t('Updating...') : $t('The report updates as you type or choose') }}</span></div>
        <div v-if="can.print && report" class="ac-actions" style="margin-top: 10px">
          <a :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a :href="url('pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />PDF</a>
          <a :href="url('xlsx')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />Excel</a>
          <a :href="url('csv')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />CSV</a>
        </div>
      </section>
      <section v-if="report" class="lg-card">
        <div class="ac-split" style="margin-bottom: 8px"><span v-for="(v, k) in report.filters" :key="k">{{ k }}: <b>{{ v }}</b></span></div>
        <div class="ac-attn" v-if="report.tiles.length > 1"><div v-for="x in report.tiles" :key="x.label" class="ac-tile ac-glow tone-navy"><div><div class="n" :class="isMoney(x.value) ? (/^All costs/.test(x.label) ? 'tr-g' : 'tr-tm') : ''" style="font-size: 1rem">{{ x.value }}</div><div class="l">{{ x.label }}</div></div></div></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ 'tr-num': c.align === 'right', 'tr-no': c.key === 'no' }">{{ c.label }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in report.rows" :key="i"><td v-for="c in report.columns" :key="c.key" :data-label="c.label" :class="[{ 'tr-num': c.align === 'right', 'tr-no': c.key === 'no', 'tr-dates': c.key === 'dates' }, tone(c)]"><span v-if="c.key === 'status' && row._status" class="tr-st" :class="statusTone(row._status)"><i></i>{{ row[c.key] }}</span><template v-else>{{ row[c.key] === null || row[c.key] === undefined || row[c.key] === '' ? '-' : row[c.key] }}</template></td></tr>
            <tr v-if="!report.rows.length" class="lg-empty-row"><td class="lg-empty" :colspan="report.columns.length">{{ t === 'agent_statement' && !f.contact_id ? $t('Choose the ticket agent for this statement.') : $t('Nothing was recorded for this report.') }}</td></tr>
          </tbody></table></div>
        <p v-if="report.truncated" class="lg-sub" style="margin-top: 6px">{{ $t('Showing the first :n of :t rows. Export to get all of them.', { n: report.rows.length, t: report.total_rows }) }}</p>
        <ul class="lg-sub" style="margin-top: 10px; padding-left: 18px; list-style: disc"><li v-for="n in report.notes" :key="n">{{ n }}</li></ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

export default {
  components: { ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect },
  props: { catalog: Array, categories: { type: Array, default: () => [] }, type: String, filters: Object, currencies: Array, agents: Array, statuses: Array, paymentStatuses: Array, scopes: Array, countries: Array, homeCountry: String, can: Object },
  data() { return { t: this.type, report: null, loading: false, f: { ...this.filters }, timer: null }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    current() { return this.catalog.find(c => c.key === this.t); },
    hasFilters() { return Object.keys(this.f).some(k => this.f[k]); },
    sections() { return [{ value: 'agents', label: this.$t('Owed to agents (ticket, visa, agent items)') }, { value: 'extras', label: this.$t('Company expenses (no agent)') }]; },
    countryOptions() { return [...new Set([this.homeCountry, ...(this.countries || [])].filter(Boolean))].map(c => ({ value: c, label: c })); },
    defaultStatus() { return ['by_traveller', 'by_destination', 'by_country'].includes(this.t) ? this.$t('Approved only') : this.$t('Any'); },
    tabs() {
      const look = { register: ['list', 'sky'], travel_costs: ['cash', 'emerald'], cost_summary: ['chart-bar', 'violet'], upcoming: ['plane', 'indigo'], by_country: ['globe', 'violet'], by_destination: ['building', 'teal'], by_traveller: ['users', 'emerald'], agent_balances: ['cash', 'amber'], agent_statement: ['doc-text', 'navy'], payments: ['cash', 'rose'] };
      return this.catalog.map(c => ({ key: c.key, label: c.title, icon: (look[c.key] || ['doc', 'navy'])[0], tone: (look[c.key] || ['doc', 'navy'])[1] }));
    },
  },
  mounted() { this.run(); },
  methods: {
    // amounts wear a colour: maroon = owed / cost, green = paid, orange = balance, pending and TZS equivalents
    // a tile that holds money (a currency and an amount) is a total: maroon
    // a travel's status as a soft coloured label (the key comes from the server, so it still works when the shown word is translated)
    statusTone(key) { return { 'Travel Request': 'tr-st-pending', Approved: 'tr-st-approved', Rejected: 'tr-st-rejected', Cancelled: 'tr-st-cancelled' }[key] || 'tr-st-cancelled'; },
    isMoney(v) { return /^([A-Z]{3} )?-?[\d,]+\.\d{2}$/.test(String(v)); },
    tone(c) {
      if (c.type !== 'money' && c.key !== 'rate') return '';
      if (c.key === 'all_tzs') return 'tr-g'; // "All costs" is green; every other column keeps its own colour
      if (['balance', 'balance_after', 'total', 'all_tzs'].includes(c.key)) return 'tr-tm';
      if (c.key === 'rate' || ['tzs', 'pending'].includes(c.key)) return 'tr-o';
      if (c.key === 'paid' || (this.t === 'payments' && c.key === 'amount')) return 'tr-g';
      return 'tr-m';
    },
    uses(k) { return this.current && this.current.filters.includes(k); },
    params() { const q = { type: this.t }; (this.current ? this.current.filters : []).forEach(k => { if (this.f[k] !== '' && this.f[k] !== null && this.f[k] !== undefined) q[k] = this.f[k]; }); return q; },
    url(fmt) { return route('travels.reports.' + fmt, this.params()); },
    pick(k) { this.t = k; this.report = null; history.replaceState(null, '', route('travels.reports.index', { type: k })); this.run(); },
    // the report follows every change by itself (typing waits a moment)
    queue() { clearTimeout(this.timer); this.loading = true; this.timer = setTimeout(this.run, 380); },
    clear() { this.f = {}; },
    async run() { if (!this.current) return; clearTimeout(this.timer); this.loading = true; try { const r = await window.axios.get(route('travels.reports.index'), { params: { ...this.params(), preview: 1 } }); this.report = r.data; } catch (e) { this.report = null; } finally { this.loading = false; } },
  },
};
</script>
