<template>
  <admin-layout :title="$t('Administration Reports')">
    <div class="lg-page ac sc-ui tr-ui wa-ui px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Administration Reports')" :subtitle="$t('Choose a report and set the filters: an item month by month, the period between two dates, the beneficiary, the status, the charity and more. Print or download it with its QR code.')">
        <Link :href="route('weekly-administration.index')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Documents') }}</span></Link>
      </page-header>
      <sc-tabs :tabs="tabs" :model-value="t" @update:model-value="pick" />
      <section v-if="current" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ current.title }}</h2><p class="lg-card-sub">{{ current.about }}</p></div></header>
        <div class="tr-fbar">
          <div v-if="uses('search')" class="ac-f"><label>{{ $t('Search') }}</label><input v-model="f.search" :placeholder="$t('Description, beneficiary, reference')" /></div>
          <div v-if="uses('item_id')" class="ac-f"><label>{{ $t('Item') }}</label><acct-select v-model="f.item_id" :options="items" :placeholder="$t('All items')" clearable /></div>
          <div v-if="uses('category')" class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="f.category" :options="categories.map(c => ({ value: c, label: c }))" :placeholder="$t('All categories')" clearable /></div>
          <div v-if="uses('staff_id')" class="ac-f"><label>{{ $t('Staff member') }}</label><acct-select v-model="f.staff_id" :options="staffOptions" :placeholder="$t('All staff')" clearable /></div>
          <div v-if="uses('beneficiary')" class="ac-f"><label>{{ $t('Beneficiary') }}</label><input v-model="f.beneficiary" :placeholder="$t('Part of the name')" /></div>
          <div v-if="uses('user_id')" class="ac-f"><label>{{ $t('Requested by') }}</label><acct-select v-model="f.user_id" :options="users" :placeholder="$t('Anyone')" clearable /></div>
          <div v-if="uses('section')" class="ac-f"><label>{{ $t('Section') }}</label><acct-select v-model="f.section" :options="sections" :placeholder="$t('Both')" clearable /></div>
          <div v-if="uses('charity')" class="ac-f"><label>{{ $t('Charity section') }}</label><acct-select v-model="f.charity" :options="charityOpts" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('status')" class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statuses" :placeholder="money ? $t('Authorized only') : $t('Any')" clearable /></div>
          <div v-if="uses('pay')" class="ac-f"><label>{{ $t('Payment') }}</label><acct-select v-model="f.pay" :options="payStatuses" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('pstatus')" class="ac-f"><label>{{ $t('Payment status') }}</label><acct-select v-model="f.pstatus" :options="paymentStatuses" :placeholder="$t('Paid')" clearable /></div>
          <div v-if="uses('from')" class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div v-if="uses('to')" class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div v-if="uses('amount_min')" class="ac-f"><label>{{ $t('Amount from') }}</label><input v-model="f.amount_min" type="number" min="0" step="0.01" inputmode="decimal" /></div>
          <div v-if="uses('amount_max')" class="ac-f"><label>{{ $t('Amount up to') }}</label><input v-model="f.amount_max" type="number" min="0" step="0.01" inputmode="decimal" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="clear"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="wa-adds" style="margin-top: 10px">
          <button v-for="q in quick" :key="q.k" type="button" class="lg-btn t-slate" @click="setRange(q.k)"><icons name="calendar" class="w-4 h-4" />{{ q.label }}</button>
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
        <div class="ac-attn" v-if="report.tiles.length > 1"><div v-for="x in report.tiles" :key="x.label" class="ac-tile ac-glow tone-navy"><div><div class="n" :class="{ 'tr-tm': isMoney(x.value) }" style="font-size: 1rem">{{ x.value }}</div><div class="l">{{ x.label }}</div></div></div></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ 'tr-num': c.align === 'right', 'tr-no': c.key === 'no' }">{{ c.label }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in report.rows" :key="i"><td v-for="c in report.columns" :key="c.key" :data-label="c.label" :class="[{ 'tr-num': c.align === 'right', 'tr-no': c.key === 'no' }, tone(c)]">{{ row[c.key] === null || row[c.key] === undefined || row[c.key] === '' ? (c.type === 'money' ? '' : '-') : row[c.key] }}</td></tr>
            <tr v-if="!report.rows.length" class="lg-empty-row"><td class="lg-empty" :colspan="report.columns.length">{{ $t('Nothing was recorded for this report.') }}</td></tr>
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

const iso = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

export default {
  components: { ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect },
  props: { catalog: Array, type: String, filters: Object, statuses: Array, categories: { type: Array, default: () => [] }, staffOptions: { type: Array, default: () => [] }, payStatuses: Array, paymentStatuses: Array, items: Array, users: Array, can: Object },
  data() { return { t: this.type, report: null, loading: false, f: { ...this.filters }, timer: null }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    current() { return this.catalog.find(c => c.key === this.t); },
    money() { return ['by_item', 'item_monthly', 'lines', 'by_month', 'by_beneficiary', 'charity', 'charity_people', 'payments', 'by_category', 'category_monthly', 'by_staff'].includes(this.t); },
    hasFilters() { return Object.keys(this.f).some(k => this.f[k]); },
    sections() { return [{ value: 'items', label: this.$t('Lines only') }, { value: 'charity', label: this.$t('Charity only') }]; },
    charityOpts() { return [{ value: '1', label: this.$t('With charity') }, { value: '0', label: this.$t('Without charity') }]; },
    quick() { return [{ k: 'month', label: this.$t('This month') }, { k: 'last', label: this.$t('Last month') }, { k: 'quarter', label: this.$t('This quarter') }, { k: 'year', label: this.$t('This year') }]; },
    tabs() {
      const look = { register: ['list', 'sky'], payments: ['cash', 'emerald'], by_category: ['collection', 'indigo'], category_monthly: ['calendar', 'violet'], by_staff: ['user', 'teal'], by_item: ['collection', 'teal'], item_monthly: ['calendar', 'emerald'], lines: ['table', 'indigo'], by_month: ['chart-bar', 'violet'], by_beneficiary: ['users', 'amber'], charity: ['heart', 'rose'], charity_people: ['group', 'rose'] };
      return this.catalog.map(c => ({ key: c.key, label: c.title, icon: (look[c.key] || ['doc', 'navy'])[0], tone: (look[c.key] || ['doc', 'navy'])[1] }));
    },
  },
  mounted() { this.run(); },
  methods: {
    isMoney(v) { return /^([A-Z]{3} )?-?[\d,]+\.\d{2}$/.test(String(v)); },
    tone(c) { if (c.type !== 'money') return ''; if (c.key === 'paid') return 'tr-g'; if (c.key === 'balance') return 'tr-o'; return ['total'].includes(c.key) ? 'tr-tm' : 'tr-m'; },
    uses(k) { return this.current && this.current.filters.includes(k); },
    params() { const q = { type: this.t }; (this.current ? this.current.filters : []).forEach(k => { if (this.f[k] !== '' && this.f[k] !== null && this.f[k] !== undefined) q[k] = this.f[k]; }); return q; },
    url(fmt) { return route('weekly-administration.reports.' + fmt, this.params()); },
    pick(k) { this.t = k; this.report = null; history.replaceState(null, '', route('weekly-administration.reports.index', { type: k })); this.run(); },
    setRange(k) {
      const n = new Date(); let a; let b;
      if (k === 'month') { a = new Date(n.getFullYear(), n.getMonth(), 1); b = new Date(n.getFullYear(), n.getMonth() + 1, 0); }
      if (k === 'last') { a = new Date(n.getFullYear(), n.getMonth() - 1, 1); b = new Date(n.getFullYear(), n.getMonth(), 0); }
      if (k === 'quarter') { const q = Math.floor(n.getMonth() / 3) * 3; a = new Date(n.getFullYear(), q, 1); b = new Date(n.getFullYear(), q + 3, 0); }
      if (k === 'year') { a = new Date(n.getFullYear(), 0, 1); b = new Date(n.getFullYear(), 11, 31); }
      this.f = { ...this.f, from: iso(a), to: iso(b) };
    },
    queue() { clearTimeout(this.timer); this.loading = true; this.timer = setTimeout(this.run, 380); },
    clear() { this.f = {}; },
    async run() { if (!this.current) return; clearTimeout(this.timer); this.loading = true; try { const r = await window.axios.get(route('weekly-administration.reports.index'), { params: { ...this.params(), preview: 1 } }); this.report = r.data; } catch (e) { this.report = null; } finally { this.loading = false; } },
  },
};
</script>
