<template>
  <admin-layout :title="$t('Visitor Reports')">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Visitor Reports')" :subtitle="$t('Choose a report, set the filters and print or download it. The printed report shows who generated it and when.')">
        <Link :href="route('visitor-logs.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
      </page-header>
      <div class="vl-pick-wide"><sc-tabs :tabs="tabs" :model-value="t" @update:model-value="pick" /></div>
      <div class="vl-pick-narrow ac-f"><label>{{ $t('Report') }}</label><acct-select :model-value="t" :options="tabs.map(x => ({ value: x.key, label: x.label }))" @update:model-value="pick" /></div>
      <section v-if="current" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ current.title }}</h2><p class="lg-card-sub">{{ current.about }}</p></div></header>
        <div class="tr-fbar">
          <div v-if="uses('search')" class="ac-f"><label>{{ $t('Search') }}</label><input v-model="f.search" :placeholder="$t('Name, company, reference...')" /></div>
          <div v-if="uses('visitor_id')" class="ac-f"><label>{{ $t('Visitor') }} <b>*</b></label><acct-select v-model="f.visitor_id" :options="visitors" :placeholder="$t('Choose the visitor')" clearable /></div>
          <div v-if="uses('warehouse_id')" class="ac-f"><label>{{ $t('Project') }}</label><acct-select v-model="f.warehouse_id" :options="warehouses.map(w => ({ value: String(w.id), label: w.name }))" :placeholder="$t('All projects')" clearable /></div>
          <div v-if="uses('host_id')" class="ac-f"><label>{{ $t('Person visited') }}</label><acct-select v-model="f.host_id" :options="hosts" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('recipient_id')" class="ac-f"><label>{{ $t('Recipient') }}</label><acct-select v-model="f.recipient_id" :options="hosts" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('department')" class="ac-f"><label>{{ $t('Department') }}</label><acct-select v-model="f.department" :options="departments.map(d => ({ value: d, label: d }))" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('purpose')" class="ac-f"><label>{{ $t('Purpose') }}</label><acct-select v-model="f.purpose" :options="purposes.map(d => ({ value: d, label: d }))" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('status') && t !== 'notifications'" class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statuses.map(s => ({ value: s, label: s }))" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('status') && t === 'notifications'" class="ac-f"><label>{{ $t('Outcome') }}</label><acct-select v-model="f.status" :options="outcomes" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('item_status')" class="ac-f"><label>{{ $t('Item status') }}</label><acct-select v-model="f.item_status" :options="itemStatuses.map(s => ({ value: s, label: s }))" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('item_type')" class="ac-f"><label>{{ $t('Item type') }}</label><acct-select v-model="f.item_type" :options="itemTypes.map(s => ({ value: s, label: s }))" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('channel')" class="ac-f"><label>{{ $t('Channel') }}</label><acct-select v-model="f.channel" :options="channels" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('event')" class="ac-f"><label>{{ $t('What happened') }}</label><acct-select v-model="f.event" :options="events" :placeholder="$t('Any')" clearable /></div>
          <div v-if="uses('from')" class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div v-if="uses('to')" class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
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
        <div v-if="report.tiles.length > 1" class="vl-tiles"><div v-for="x in report.tiles" :key="x.label"><small>{{ x.label }}</small><b>{{ x.value }}</b></div></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ 'tr-num': c.align === 'right', 'tr-no': c.key === 'no' }">{{ c.label }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in report.rows" :key="i"><td v-for="c in report.columns" :key="c.key" :data-label="c.label" :class="{ 'tr-num': c.align === 'right', 'tr-no': c.key === 'no' }">{{ row[c.key] }}</td></tr>
            <tr v-if="!report.rows.length" class="lg-empty-row"><td class="lg-empty" :colspan="report.columns.length">{{ t === 'person_history' && !f.visitor_id ? $t('Choose the visitor for this report.') : $t('Nothing was recorded for this report.') }}</td></tr>
          </tbody></table></div>
        <p v-if="report.truncated" class="lg-sub" style="margin-top: 6px">{{ $t('Showing the first :n of :t rows. Export to get all of them.', { n: report.rows.length, t: report.total_rows }) }}</p>
        <ul class="lg-sub" style="margin-top: 10px; padding-left: 18px; list-style: disc"><li v-for="n in report.notes" :key="n">{{ n }}</li></ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '@/Pages/Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

export default {
  components: { ScTabs, Link, AdminLayout, PageHeader, DatePicker, AcctSelect },
  props: { catalog: Array, type: String, filters: Object, warehouses: { type: Array, default: () => [] }, statuses: Array, itemStatuses: Array, itemTypes: Array, purposes: Array, departments: Array, hosts: Array, visitors: { type: Array, default: () => [] }, can: Object },
  data() { return { t: this.type, report: null, loading: false, f: { ...this.filters }, timer: null }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    current() { return this.catalog.find(c => c.key === this.t); },
    hasFilters() { return Object.keys(this.f).some(k => this.f[k]); },
    channels() { return [{ value: 'bell', label: this.$t('In-app') }, { value: 'sms', label: 'SMS' }, { value: 'email', label: 'Email' }, { value: 'whatsapp', label: 'WhatsApp' }]; },
    outcomes() { return ['SENT', 'DELIVERED', 'FAILED', 'READ'].map(s => ({ value: s, label: s })); },
    events() { return ['checked_in', 'checked_out', 'edited', 'corrected', 'cancelled', 'received', 'status_changed', 'handed_over', 'created', 'updated', 'deactivated', 'reactivated'].map(e => ({ value: e, label: e.replace(/_/g, ' ') })); },
    tabs() {
      const look = { daily_register: ['list', 'sky'], person_history: ['user', 'emerald'], by_company: ['building', 'teal'], by_host: ['users', 'emerald'], by_department: ['group', 'violet'], on_premises: ['in', 'emerald'], checkin_out: ['refresh', 'sky'], durations: ['clock', 'amber'],
        letters: ['email', 'indigo'], packages: ['bag', 'amber'], awaiting: ['clock', 'amber'], delivered: ['tick', 'emerald'], overdue: ['exclamation', 'rose'], notifications: ['bell', 'violet'], audit: ['lock-closed', 'slate'] };
      return this.catalog.map(c => ({ key: c.key, label: c.title, icon: (look[c.key] || ['doc', 'navy'])[0], tone: (look[c.key] || ['doc', 'navy'])[1] }));
    },
  },
  mounted() { this.run(); },
  methods: {
    uses(k) { return this.current && this.current.filters.includes(k); },
    params() { const q = { type: this.t }; (this.current ? this.current.filters : []).forEach(k => { if (this.f[k] !== '' && this.f[k] !== null && this.f[k] !== undefined) q[k] = this.f[k]; }); return q; },
    url(fmt) { return route('visitor-logs.reports.' + fmt, this.params()); },
    pick(k) { this.t = k; this.report = null; history.replaceState(null, '', route('visitor-logs.reports.index', { type: k })); this.run(); },
    queue() { clearTimeout(this.timer); this.loading = true; this.timer = setTimeout(this.run, 380); },
    clear() { this.f = {}; },
    async run() {
      if (!this.current) return;
      clearTimeout(this.timer); this.loading = true;
      try { const r = await window.axios.get(route('visitor-logs.reports.index'), { params: { ...this.params(), preview: 1 } }); this.report = r.data; } catch (e) { this.report = { filters: {}, tiles: [], columns: [], rows: [], notes: [this.$t('This report could not be built.')], truncated: false }; } finally { this.loading = false; }
    },
  },
};
</script>

<style scoped>
.vl-pick-narrow { display: none; margin-bottom: 14px; }
@media (max-width: 767.98px) { .vl-pick-wide { display: none; } .vl-pick-narrow { display: grid; } }
.vl-tiles { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.vl-tiles > div { display: grid; gap: 2px; padding: 10px 16px; border-radius: 14px; background: var(--lg-soft, #f1f5f9); min-width: 120px; }
.vl-tiles small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.vl-tiles b { font-size: 1.15rem; font-variant-numeric: tabular-nums; }
</style>
