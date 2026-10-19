<template>
  <admin-layout :title="$t('Accounts Reports')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Accounts Reports')" :subtitle="$t('Choose a report, set the filters and see it here. Print, PDF, Excel and CSV use the standard WIMS report layout and every export is recorded.')" />
      <div class="ac-grid-2" style="grid-template-columns: minmax(240px, 320px) minmax(0, 1fr); align-items: start">
        <aside class="ac-rmenu" :aria-label="$t('Reports')">
          <section v-for="g in groups" :key="g.key" class="ac-rgroup" :class="'tone-' + (tones[g.key] || 'navy')">
            <header class="ac-rgroup-head"><span class="ac-rico"><icons :name="groupIcons[g.key] || 'collection'" class="w-5 h-5" /></span><h3>{{ g.label }}</h3><span class="ac-rcount">{{ catalog.filter(x => x.group === g.key).length }}</span></header>
            <div class="ac-ritems">
              <a v-for="c in catalog.filter(x => x.group === g.key)" :key="c.key" href="#" class="ac-ritem" :class="{ 'is-on': c.key === type }" @click.prevent="pick(c)">
                <span class="ac-rico is-sm"><icons :name="reportIcons[c.key] || 'doc-text'" class="w-4 h-4" /></span><span class="ac-rname">{{ c.title }}</span>
              </a>
            </div>
          </section>
        </aside>
        <div class="min-w-0">
          <section v-if="current" class="lg-card" style="margin-top: 0">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ current.title }}</h2><p class="lg-card-sub">{{ current.about }}</p></div></header>
            <form class="ac-filters" @submit.prevent="run">
              <div v-if="uses('account_id')" class="ac-f"><label>{{ $t('Account') }}</label><acct-select v-model="f.account_id" :options="accounts" :placeholder="$t('All')" clearable /></div>
              <div v-if="uses('from')" class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
              <div v-if="uses('to')" class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
              <div v-if="uses('as_of')" class="ac-f"><label>{{ $t('As of') }}</label><date-picker v-model="f.as_of" /></div>
              <div v-if="uses('currency')" class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencyOptions" :placeholder="$t('All')" clearable /></div>
              <div v-if="uses('direction')" class="ac-f"><label>{{ $t('In / out') }}</label><acct-select v-model="f.direction" :options="[{ value: 'in', label: $t('Money in') }, { value: 'out', label: $t('Money out') }]" :placeholder="$t('Both')" clearable /></div>
              <div v-if="uses('status')" class="ac-f"><label>{{ $t('Status') }}</label><input v-model="f.status" :placeholder="$t('e.g. posted, pending')" /></div>
              <div v-if="uses('days')" class="ac-f"><label>{{ $t('Days without movement') }}</label><input v-model="f.days" type="number" min="1" /></div>
              <div v-if="uses('user_id')" class="ac-f"><label>{{ $t('User') }}</label><acct-select v-model="f.user_id" :options="users" :placeholder="$t('Anyone')" clearable /></div>
              <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary" :disabled="loading"><icons name="search" class="w-4 h-4" />{{ loading ? $t('Loading...') : $t('Show report') }}</button></div>
            </form>
            <div v-if="current.key === 'account_statement' && !f.account_id" class="lg-note lg-note-amber" style="margin-top: 8px">{{ $t('Choose the account for this statement.') }}</div>
            <div v-if="can.export && report" class="ac-actions" style="margin-top: 10px">
              <a :href="url('print')" target="_blank" class="lg-btn lg-btn-soft"><icons name="doc" class="w-4 h-4" />{{ $t('Print') }}</a>
              <a :href="url('pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />PDF</a>
              <a :href="url('xlsx')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />Excel</a>
              <a :href="url('csv')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />CSV</a>
            </div>
          </section>
          <section v-else class="lg-card" style="margin-top: 0; text-align: center">{{ $t('Choose a report on the left.') }}</section>

          <section v-if="report" class="lg-card">
            <div class="ac-split" style="margin-bottom: 8px"><span v-for="(v, k) in report.filters" :key="k">{{ k }}: <b>{{ v }}</b></span></div>
            <div class="ac-attn" v-if="report.tiles.length > 1"><div v-for="t in report.tiles" :key="t.label" class="ac-tile"><div><div class="n" style="font-size: 1rem">{{ t.value }}</div><div class="l">{{ t.label }}</div></div></div></div>
            <div class="lg-table-wrap"><table class="lg-table">
              <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ 'ac-num': c.align === 'right' }">{{ $t(c.label) }}</th></tr></thead>
              <tbody>
                <tr v-for="(row, i) in report.rows" :key="i"><td v-for="c in report.columns" :key="c.key" :class="{ 'ac-num': c.align === 'right' }"><span :class="c.type === 'text' || !c.type ? 'ac-clip' : ''">{{ row[c.key] === null || row[c.key] === undefined || row[c.key] === '' ? '-' : row[c.key] }}</span></td></tr>
                <tr v-if="!report.rows.length" class="lg-empty-row"><td class="lg-empty" :colspan="report.columns.length">{{ $t('Nothing was recorded for this report in the chosen period.') }}</td></tr>
              </tbody></table></div>
            <p v-if="report.truncated" class="lg-sub" style="margin-top: 6px">{{ $t('Showing the first :n of :t rows. Export to get all of them.', { n: report.rows.length, t: report.total_rows }) }}</p>
            <ul class="lg-sub" style="margin-top: 10px; padding-left: 18px; list-style: disc"><li v-for="n in report.notes" :key="n">{{ n }}</li></ul>
          </section>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from './AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import { acct } from './acct';

export default {
  components: { DatePicker, AcctSelect, AdminLayout, PageHeader },
  mixins: [acct],
  props: { cur: Object, catalog: Array, groups: Array, type: String, filters: Object, accounts: Array, categories: Array, users: Array, currencyOptions: Array, can: Object },
  data() {
    return {
      tones: { balances: 'navy', ledger: 'emerald', transfers: 'violet', control: 'amber', reconciliation: 'teal', accounts: 'sky' },
      groupIcons: { balances: 'cash', ledger: 'list', transfers: 'refresh', control: 'tick', reconciliation: 'doc-text', accounts: 'folder' },
      reportIcons: { account_balances: 'cash', balances_by_currency: 'collection', balances_by_type: 'chart-pie', balances_as_of: 'calendar', account_statement: 'doc-text', transaction_register: 'list', inflows_by_category: 'in', outflows_by_category: 'out', income_expense: 'chart-bar', payments_by_method: 'send', daily_cash: 'clock', transfers_register: 'refresh', cross_currency_transfers: 'adjustments', exchange_differences: 'exclamation', exchange_rates: 'chart-bar', pending_approvals: 'clock', rejected_cancelled: 'x-circle', reversals: 'refresh', adjustments: 'adjustments', large_transactions: 'exclamation', user_activity: 'users', approval_turnaround: 'clock', audit_log: 'key', reconciliation_summary: 'tick', cash_discrepancies: 'exclamation', unreconciled: 'doc-text', bank_exceptions: 'x-circle', cash_position: 'in', low_balance: 'exclamation', dormant_accounts: 'clock', accounts_register: 'folder', funds_allocation: 'collection' },
      report: null, loading: false, f: { account_id: '', currency: '', direction: '', status: '', days: 60, user_id: '', as_of: new Date().toISOString().slice(0, 10), ...this.filters }, t: this.type }; },
  computed: { current() { return this.catalog.find(c => c.key === this.t); } },
  mounted() { if (this.current) this.run(); },
  methods: {
    uses(k) { return this.current && this.current.filters.includes(k); },
    params() { const q = { type: this.t }; (this.current ? this.current.filters : []).forEach(k => { if (this.f[k] !== '' && this.f[k] !== null && this.f[k] !== undefined) q[k] = this.f[k]; }); return q; },
    url(fmt) { return route('acct.reports.' + fmt, this.params()); },
    pick(c) { this.t = c.key; this.report = null; history.replaceState(null, '', route('acct.reports.index', { type: c.key })); if (!(c.key === 'account_statement' && !this.f.account_id)) this.run(); },
    async run() {
      if (!this.current) return;
      this.loading = true;
      try { const r = await window.axios.get(route('acct.reports.index'), { params: { ...this.params(), preview: 1 } }); this.report = r.data; } catch (e) { this.report = null; alert(this.$t('The report could not be produced.')); } finally { this.loading = false; }
    },
  },
};
</script>
