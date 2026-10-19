<template>
  <admin-layout :title="$t('Travel Reports')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Travel Reports')" :subtitle="$t('Every travel and every ticket agent: choose a report, set the filters (country, local or international, agent, dates) and print or download it. The printed report shows who generated it, when, the filters and a QR code, and every export is recorded.')">
        <Link :href="route('travels.index')" class="lg-btn lg-btn-soft"><icons name="plane" class="w-4 h-4" /><span>{{ $t('Travels') }}</span></Link>
      </page-header>
      <sc-tabs :tabs="tabs" :model-value="t" @update:model-value="pick" />
      <section v-if="current" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ current.title }}</h2><p class="lg-card-sub">{{ current.about }}</p></div></header>
        <form class="ac-filters" @submit.prevent="run">
          <div v-if="uses('search')" class="ac-f"><label>{{ $t('Search') }}</label><input v-model="f.search" :placeholder="$t('Traveller, destination, flight, reference')" /></div>
          <div v-if="uses('contact_id')" class="ac-f"><label>{{ $t('Ticket agent') }}<b v-if="t === 'agent_statement'"> *</b></label><acct-select v-model="f.contact_id" :options="agents" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('scope')" class="ac-f"><label>{{ $t('Local / international') }}</label><acct-select v-model="f.scope" :options="scopes" :placeholder="$t('Both')" clearable /></div>
          <div v-if="uses('country')" class="ac-f"><label>{{ $t('Country') }}</label><acct-select v-model="f.country" :options="countryOptions" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('currency')" class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies.map(c => ({ value: c, label: c }))" :placeholder="$t('All')" clearable /></div>
          <div v-if="uses('status')" class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statuses" :placeholder="defaultStatus" clearable /></div>
          <div v-if="uses('status_payment')" class="ac-f"><label>{{ $t('Payment status') }}</label><acct-select v-model="f.status_payment" :options="paymentStatuses" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('from')" class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div v-if="uses('to')" class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div v-if="uses('as_of')" class="ac-f"><label>{{ $t('As of') }}</label><date-picker v-model="f.as_of" /></div>
          <label v-if="uses('only_owing')" class="ac-check"><input v-model="f.only_owing" type="checkbox" true-value="1" false-value="" />{{ $t('Only agents we owe') }}</label>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary" :disabled="loading"><icons name="search" class="w-4 h-4" />{{ loading ? $t('Loading...') : $t('Show report') }}</button></div>
        </form>
        <div v-if="can.print && report" class="ac-actions" style="margin-top: 10px">
          <a :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a :href="url('pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />PDF</a>
          <a :href="url('xlsx')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />Excel</a>
          <a :href="url('csv')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />CSV</a>
        </div>
      </section>
      <section v-if="report" class="lg-card">
        <div class="ac-split" style="margin-bottom: 8px"><span v-for="(v, k) in report.filters" :key="k">{{ k }}: <b>{{ v }}</b></span></div>
        <div class="ac-attn" v-if="report.tiles.length > 1"><div v-for="x in report.tiles" :key="x.label" class="ac-tile ac-glow tone-navy"><div><div class="n" style="font-size: 1rem">{{ x.value }}</div><div class="l">{{ x.label }}</div></div></div></div>
        <div class="lg-table-wrap"><table class="lg-table">
          <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ 'ac-num': c.align === 'right' }">{{ c.label }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in report.rows" :key="i"><td v-for="c in report.columns" :key="c.key" :class="{ 'ac-num': c.align === 'right' }">{{ row[c.key] === null || row[c.key] === undefined || row[c.key] === '' ? '-' : row[c.key] }}</td></tr>
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
  props: { catalog: Array, type: String, filters: Object, currencies: Array, agents: Array, statuses: Array, paymentStatuses: Array, scopes: Array, countries: Array, homeCountry: String, can: Object },
  data() { return { t: this.type, report: null, loading: false, f: { ...this.filters } }; },
  computed: {
    current() { return this.catalog.find(c => c.key === this.t); },
    countryOptions() { return [...new Set([this.homeCountry, ...(this.countries || [])].filter(Boolean))].map(c => ({ value: c, label: c })); },
    defaultStatus() { return ['by_traveller', 'by_destination', 'by_country'].includes(this.t) ? this.$t('Approved only') : this.$t('Any'); },
    tabs() {
      const look = { register: ['list', 'sky'], upcoming: ['plane', 'indigo'], by_country: ['globe', 'violet'], by_destination: ['building', 'teal'], by_traveller: ['users', 'emerald'], agent_balances: ['cash', 'amber'], agent_statement: ['doc-text', 'navy'], payments: ['cash', 'rose'] };
      return this.catalog.map(c => ({ key: c.key, label: c.title, icon: (look[c.key] || ['doc', 'navy'])[0], tone: (look[c.key] || ['doc', 'navy'])[1] }));
    },
  },
  mounted() { this.run(); },
  methods: {
    uses(k) { return this.current && this.current.filters.includes(k); },
    params() { const q = { type: this.t }; (this.current ? this.current.filters : []).forEach(k => { if (this.f[k] !== '' && this.f[k] !== null && this.f[k] !== undefined) q[k] = this.f[k]; }); return q; },
    url(fmt) { return route('travels.reports.' + fmt, this.params()); },
    pick(k) { this.t = k; this.report = null; history.replaceState(null, '', route('travels.reports.index', { type: k })); this.run(); },
    async run() { if (!this.current) return; this.loading = true; try { const r = await window.axios.get(route('travels.reports.index'), { params: { ...this.params(), preview: 1 } }); this.report = r.data; } catch (e) { this.report = null; } finally { this.loading = false; } },
  },
};
</script>
