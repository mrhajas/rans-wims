<template>
  <admin-layout :title="$t('Accounts Dashboard')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cash" :title="$t('Accounts Dashboard')" :subtitle="$t('Money held, money moving and what needs attention. Each currency is shown in its own money; the reporting view is a separate, labelled conversion.')">
        <Link v-if="can.inflow" :href="route('acct.transactions.create', { direction: 'in', kind: 'receipt' })" class="lg-btn lg-btn-green"><icons name="in" class="w-4 h-4" /><span>{{ $t('Money In') }}</span></Link>
        <Link v-if="can.outflow" :href="route('acct.transactions.create', { direction: 'out', kind: 'payment' })" class="lg-btn lg-btn-red"><icons name="out" class="w-4 h-4" /><span>{{ $t('Money Out') }}</span></Link>
        <Link v-if="can.transfer" :href="route('acct.transfers.create')" class="lg-btn lg-btn-primary"><icons name="refresh" class="w-4 h-4" /><span>{{ $t('Transfer') }}</span></Link>
              <acct-export type="account_balances" :params="{ account_id: f.account_id, currency: f.currency }" />
      </page-header>

      <form class="lg-card" @submit.prevent="apply">
        <div class="ac-filters">
          <div class="ac-f"><label>{{ $t('Account') }}</label><acct-select v-model="f.account_id" :options="accountsList" :placeholder="$t('All accounts')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="curOptions" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Type') }}</label><acct-select v-model="f.type" :options="[{ value: 'in', label: $t('Money in') }, { value: 'out', label: $t('Money out') }]" :placeholder="$t('In and out')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Apply') }}</button></div>
        </div>
      </form>

      <section class="lg-card" :aria-label="$t('Balances by currency')">
        <header class="lg-card-head"><div class="min-w-0 flex-1"><h2 class="lg-card-title" style="font-size: 1.15rem">{{ $t('Money held') }}</h2><p class="lg-card-sub">{{ $t('Each currency in its own money. Pending amounts are not yet posted.') }}</p></div></header>
        <div class="sc-over">
          <overview-card v-for="(total, code, ci) in native.totals" :key="code" :variant="ci === 0 ? 'hero' : ''" icon="cash" :title="code + ' ' + $t('held')" :sub="splitText(code)" :value="m(total, code)" :delta="pendingText(code)" delta-tone="flat" :foot="$t('View Accounts')" :to="can.accounts ? route('acct.accounts.index') : null" />
          <div v-if="!Object.keys(native.totals).length" class="sc-ov"><div class="sc-ov-top"><div class="sc-ov-title">{{ $t('No accounts yet') }}</div><Link :href="route('acct.accounts.index')">{{ $t('Open the accounts register') }}</Link></div></div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Quick access') }}</h2></div></header>
        <div class="sc-quick"><Link v-for="t in tiles" :key="t.key" :href="t.to" class="sc-qt lg-tip tip-left" :class="'c-' + t.tone" :data-tip="t.tip"><i><icons :name="t.icon" class="w-6 h-6" /></i>{{ t.label }}</Link></div>
      </section>

      <section v-if="reporting && Object.keys(native.totals).length > 1" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="refresh" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Reporting view in :c', { c: reporting.currency }) }}</h2><p class="lg-card-sub">{{ $t('A conversion for comparison only, at approved rates, valued :t. It does not change any account.', { t: reporting.valued_at }) }}</p></div></header>
        <div v-if="reporting.missing.length" class="lg-note lg-note-amber">{{ $t('No approved exchange rate to :r for: :c. The total below leaves them out.', { r: reporting.currency, c: reporting.missing.join(', ') }) }} <Link v-if="$page.props.auth.user" :href="route('acct.currencies.index')">{{ $t('Add a rate') }}</Link></div>
        <div class="lg-table-wrap" style="margin-top: 8px">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Currency') }}</th><th class="ac-num">{{ $t('Held') }}</th><th class="ac-num">{{ $t('Rate') }}</th><th class="ac-num">{{ $t('In :c', { c: reporting.currency }) }}</th><th>{{ $t('Rate type') }}</th></tr></thead>
            <tbody>
              <tr v-for="l in reporting.lines" :key="l.currency">
                <td :data-label="$t('Currency')" class="lg-main">{{ l.currency }}</td><td :data-label="$t('Held')" class="ac-num">{{ m(l.amount, l.currency) }}</td>
                <td :data-label="$t('Rate')" class="ac-num">{{ l.rate ? (l.derived ? $t('derived') + ' ' : '') + trim(l.rate) : $t('missing') }}</td>
                <td :data-label="$t('In :c', { c: reporting.currency })" class="ac-num">{{ l.converted !== null && l.converted !== undefined ? m(l.converted, reporting.currency) : '-' }}</td><td :data-label="$t('Rate type')">{{ l.rate_type || '-' }}</td>
              </tr>
              <tr><td class="lg-main" :data-label="$t('Total')">{{ $t('Total') }}</td><td></td><td></td><td class="ac-num lg-main" :data-label="$t('Total')">{{ m(reporting.total, reporting.currency) }}</td><td></td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lg-card" :aria-label="$t('Needs attention')">
        <header class="lg-card-head"><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Needs attention') }}</h2></div></header>
        <div class="sc-q"><Link v-for="(c, i) in queue" :key="c.key" :href="c.to" class="sc-qc" :class="'q-' + ((i % 4) + 1)"><b>{{ c.n }}</b><span>{{ c.label }}</span><small><icons :name="c.icon" class="w-4 h-4" />{{ c.hint }}</small></Link></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="chart-bar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Cash Flow') }}</h2><p class="lg-card-sub">{{ $t('Posted transactions only. Transfers between your own accounts are not counted as income or expense.') }}</p></div></header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Period') }}</th><th>{{ $t('Currency') }}</th><th class="ac-num">{{ $t('In') }}</th><th class="ac-num">{{ $t('Out') }}</th><th class="ac-num">{{ $t('Net') }}</th></tr></thead>
            <tbody>
              <template v-for="p in periods" :key="p.key">
                <tr v-for="(v, code) in period[p.key] || {}" :key="p.key + code">
                  <td :data-label="$t('Period')" class="lg-main">{{ p.label }}</td><td :data-label="$t('Currency')">{{ code }}</td>
                  <td :data-label="$t('In')" class="ac-num ac-in">{{ m(v.in, code) }}</td><td :data-label="$t('Out')" class="ac-num ac-out">{{ m(v.out, code) }}</td><td :data-label="$t('Net')" class="ac-num" :class="{ 'ac-neg': neg(v.net) }">{{ m(v.net, code) }}</td>
                </tr>
                <tr v-if="!Object.keys(period[p.key] || {}).length"><td :data-label="$t('Period')" class="lg-main">{{ p.label }}</td><td colspan="4" class="lg-empty" style="padding: 10px 16px !important; text-align: left">{{ $t('No posted movement') }}</td></tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="can.transactions" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Recent Activity') }}</h2></div><Link v-if="can.transactions" :href="route('acct.transactions.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All transactions') }}</Link></header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Account') }}</th><th>{{ $t('Description') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-for="t in recent" :key="t.id" class="is-click" @click="$inertia.visit(route('acct.transactions.show', t.id))">
                <td :data-label="$t('Reference')" class="lg-main">{{ t.reference }}</td><td :data-label="$t('Date')">{{ $date(t.date) }}</td><td :data-label="$t('Account')">{{ t.account }}</td><td :data-label="$t('Description')"><span class="ac-clip">{{ t.description }}</span></td>
                <td :data-label="$t('Amount')" class="ac-num" :class="t.direction === 'in' ? 'ac-in' : 'ac-out'">{{ t.direction === 'in' ? '+' : '-' }}{{ mc(t.amount, t.currency) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(t.status)">{{ $t(t.status) }}</span></td>
              </tr>
              <tr v-if="!recent.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from './AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from './AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import OverviewCard from '../Subcontracts/OverviewCard.vue';
import { acct } from './acct';

export default {
  components: { AcctExport, OverviewCard, DatePicker, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, reporting: Object, native: Object, period: Object, filters: Object, pending: [Object, Array], pendingTransfers: Number, waiting: Number, low: Array, due: Array, unusual: Number, diffs: Number, alerts: Object, recent: Array, accountsList: Array, pendingRates: Number, can: Object },
  data() { return { f: { ...this.filters } }; },
  computed: {
    curTones() { return ['tone-navy', 'tone-teal', 'tone-violet', 'tone-emerald', 'tone-sky']; },
    isSuper() { return (this.$page.props.auth.user.roles || []).some(r => r.name === 'Super Admin'); },
    tiles() {
      const c = this.can; const t = [];
      if (c.accounts) t.push({ key: 'ac', label: this.$t('Accounts'), tip: this.$t('Bank, cash and other accounts'), icon: 'cash', tone: 'blue', to: route('acct.accounts.index') });
      if (c.transactions) t.push({ key: 'tx', label: this.$t('Transactions'), tip: this.$t('Every money in and money out record'), icon: 'list', tone: 'sky', to: route('acct.transactions.index') });
      if (c.transfers) t.push({ key: 'tr', label: this.$t('Transfers'), tip: this.$t('Moves between your own accounts'), icon: 'refresh', tone: 'violet', to: route('acct.transfers.index') });
      if (c.approve) t.push({ key: 'ap', label: this.$t('Approvals'), tip: this.$t('Items waiting for a decision'), icon: 'tick', tone: 'green', to: route('acct.approvals.index') });
      if (c.recon) t.push({ key: 'rc', label: this.$t('Reconcile'), tip: this.$t('Match accounts against bank statements'), icon: 'tick', tone: 'teal', to: route('acct.reconciliations.index') });
      if (c.currencies) t.push({ key: 'cu', label: this.$t('Rates'), tip: this.$t('Currencies and exchange rates'), icon: 'adjustments', tone: 'amber', to: route('acct.currencies.index') });
      if (c.alerts) t.push({ key: 'al', label: this.$t('Alerts'), tip: this.$t('Financial alerts that need a look'), icon: 'bell', tone: 'rose', to: route('acct.alerts.index') });
      return t;
    },
    queue() {
      const c = this.can; const o = [];
      if (c.approve) o.push({ key: 'w', n: this.waiting || 0, label: this.$t('Waiting for Approval'), hint: this.$t('Decide Now'), icon: 'clock', to: route('acct.approvals.index') });
      if (c.transactions || c.transfers) o.push({ key: 'p', n: this.pendingCount, label: this.$t('Pending'), hint: this.pendingTransfers ? '+ ' + this.pendingTransfers + ' ' + this.$t('transfers') : this.$t('Not Posted'), icon: 'send', to: route('acct.transactions.index', { status: 'submitted' }) });
      if (c.accounts) o.push({ key: 'l', n: this.low.length, label: this.$t('Low Balance'), hint: this.$t('Below Limit'), icon: 'exclamation', to: route('acct.accounts.index') });
      if (c.recon) o.push({ key: 'r', n: this.due.length, label: this.$t('Reconciliation Due'), hint: this.$t('To Match'), icon: 'tick', to: route('acct.reconciliations.index') });
      if (c.alerts) {
        o.push({ key: 'u', n: this.unusual, label: this.$t('Unusual'), hint: this.$t('Check Now'), icon: 'exclamation', to: route('acct.alerts.index', { category: 'transaction' }) });
        o.push({ key: 'd', n: this.diffs, label: this.$t('Differences'), hint: this.$t('To Review'), icon: 'cash', to: route('acct.alerts.index', { category: 'reconciliation' }) });
        o.push({ key: 'a', n: this.alerts.open, label: this.$t('Open Alerts'), hint: this.alerts.critical ? this.alerts.critical + ' ' + this.$t('high') : this.$t('None High'), icon: 'bell', to: route('acct.alerts.index') });
      }
      if (this.pendingRates && c.currencies) o.push({ key: 'x', n: this.pendingRates, label: this.$t('Rates Pending'), hint: this.$t('Approve Rates'), icon: 'refresh', to: route('acct.currencies.index', { status: 'pending' }) });
      return o;
    },
    pendingCount() { return this.pending ? this.pending.count || 0 : 0; },
    periods() {
      const p = [{ key: 'today', label: this.$t('Today') }, { key: 'week', label: this.$t('This week') }, { key: 'month', label: this.$t('This month') }];
      if (this.period.custom) p.push({ key: 'custom', label: this.f.from + ' - ' + this.f.to });
      return p;
    },
  },
  methods: {
    splitText(code) { const g = this.native.groups; return ['bank', 'cash', 'other'].filter(k => g[k][code] !== undefined).map(k => this.$t({ bank: 'Bank', cash: 'Cash', other: 'Other' }[k]) + ' ' + this.m(g[k][code], code)).join(' · '); },
    pendingText(code) { const p = this.pending.by && this.pending.by[code]; return p ? this.$t('Out') + ' ' + this.m(p.out, code) + ' ' + this.$t('pending') : ''; },
    trim(r) { return String(r).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''); },
    apply() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('acct.dashboard'), q, { preserveState: true }); },
  },
};
</script>
