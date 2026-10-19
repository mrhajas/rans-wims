<template>
  <admin-layout :title="$t('Transactions')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="list" :title="$t('Transactions')" :subtitle="$t('The ledger: every receipt, payment, transfer leg and adjustment. Only posted entries change a balance.')">
        <Link v-if="can.inflow" :href="route('acct.transactions.create', { direction: 'in', kind: 'receipt' })" class="lg-btn lg-btn-green"><icons name="in" class="w-4 h-4" /><span>{{ $t('Money In') }}</span></Link>
        <Link v-if="can.outflow" :href="route('acct.transactions.create', { direction: 'out', kind: 'payment' })" class="lg-btn lg-btn-red"><icons name="out" class="w-4 h-4" /><span>{{ $t('Money Out') }}</span></Link>
        <a v-if="can.export" :href="exportUrl('xlsx')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>Excel</span></a>
        <a v-if="can.export" :href="exportUrl('pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>PDF</span></a>
      </page-header>

      <sc-tabs :tabs="tabs.map(t => ({ key: t.key, label: t.label, icon: t.icon, tone: t.tone, count: t.key && byStatus[t.key] ? byStatus[t.key] : undefined }))" :model-value="f.status || ''" @update:model-value="setStatus" />

      <section v-if="sums.length" class="ac-grid">
        <div v-for="s in sums" :key="s.currency" class="ac-cur"><div class="ac-cur-code">{{ s.currency }} {{ $t('posted in these results') }}</div><div class="ac-split"><span>{{ $t('In') }} <b class="ac-in">{{ m(s.in, s.currency) }}</b></span><span>{{ $t('Out') }} <b class="ac-out">{{ m(s.out, s.currency) }}</b></span><span>{{ $t('Net') }} <b :class="{ 'ac-neg': neg(s.net) }">{{ m(s.net, s.currency) }}</b></span></div></div>
      </section>

      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters">
          <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Reference, description, receipt or invoice number, person')" /></div>
          <div class="ac-f"><label>{{ $t('Account') }}</label><acct-select v-model="f.account_id" :options="accounts" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('In / out') }}</label><acct-select v-model="f.direction" :options="[{ value: 'in', label: $t('Money in') }, { value: 'out', label: $t('Money out') }]" :placeholder="$t('Both')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Kind') }}</label><acct-select v-model="f.kind" :options="(['receipt', 'payment', 'transfer', 'fee', 'adjustment', 'reversal']).map(k => ({ value: k, label: $t(k) }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="f.category_id" :options="categories" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="curOptions" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Payment method') }}</label><acct-select v-model="f.method" :options="methods" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Project') }}</label><acct-select v-model="f.warehouse_id" :options="projects" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Created by') }}</label><acct-select v-model="f.creator" :options="users" :placeholder="$t('Anyone')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Reconciled') }}</label><acct-select v-model="f.reconciled" :options="[{ value: 'yes', label: $t('Yes') }, { value: 'no', label: $t('Not yet') }]" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button></div>
          <div class="ac-f"><button type="button" class="lg-btn t-slate" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
      </form>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead><tr>
            <th><a href="#" @click.prevent="sortBy('reference')">{{ $t('Reference') }}</a></th><th><a href="#" @click.prevent="sortBy('txn_date')">{{ $t('Date') }}</a></th><th>{{ $t('Account') }}</th><th>{{ $t('Description') }}</th><th>{{ $t('Method') }}</th>
            <th class="ac-num"><a href="#" @click.prevent="sortBy('amount')">{{ $t('Amount') }}</a></th><th><a href="#" @click.prevent="sortBy('status')">{{ $t('Status') }}</a></th><th>{{ $t('By') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="t in rows.data" :key="t.id" class="is-click" @click="$inertia.visit(route('acct.transactions.show', t.id))">
              <td :data-label="$t('Reference')"><span class="lg-main">{{ t.reference }}</span><div class="lg-subtext" v-if="t.kind !== 'receipt' && t.kind !== 'payment'">{{ $t(t.kind) }}</div></td>
              <td :data-label="$t('Date')">{{ $date(t.date) }}</td><td :data-label="$t('Account')">{{ t.account }}</td>
              <td :data-label="$t('Description')"><span class="ac-clip">{{ t.description }}</span><div v-if="t.counterparty" class="lg-subtext">{{ t.counterparty }}</div></td>
              <td :data-label="$t('Method')">{{ t.method || '-' }}</td>
              <td :data-label="$t('Amount')" class="ac-num" :class="t.direction === 'in' ? 'ac-in' : 'ac-out'">{{ t.direction === 'in' ? '+' : '-' }}{{ mc(t.amount, t.currency) }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(t.status)">{{ t.status_label }}</span><span v-if="t.reconciled" class="lg-chip lg-chip-cyan" style="margin-left: 4px">{{ $t('Reconciled') }}</span></td>
              <td :data-label="$t('By')">{{ t.creator || '-' }}</td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '../../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '../Pager.vue';
import AcctSelect from '../AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import { acct } from '../acct';

export default {
  components: { ScTabs, DatePicker, AcctSelect, AdminLayout, PageHeader, Pager, Link },
  mixins: [acct],
  props: { cur: Object, rows: Object, filters: Object, sums: Array, byStatus: Object, accounts: Array, categories: Array, statuses: Array, methods: Array, projects: Array, users: Array, can: Object },
  data() { return { f: { ...this.filters } }; },
  computed: {
    tabs() { return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy' }, { key: 'draft', label: this.$t('Draft'), icon: 'edit', tone: 'slate' }, { key: 'submitted', label: this.$t('Waiting'), icon: 'clock', tone: 'amber' }, { key: 'approved', label: this.$t('Approved'), icon: 'tick', tone: 'sky' }, { key: 'posted', label: this.$t('Posted'), icon: 'lock-closed', tone: 'emerald' }, { key: 'rejected', label: this.$t('Rejected'), icon: 'x-circle', tone: 'rose' }, { key: 'reversed', label: this.$t('Reversed'), icon: 'refresh', tone: 'violet' }, { key: 'cancelled', label: this.$t('Cancelled'), icon: 'x-circle', tone: 'slate' }]; },
  },
  methods: {
    query() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k] !== '' && this.f[k] !== null && this.f[k] !== undefined) q[k] = this.f[k]; }); return q; },
    go() { this.$inertia.get(route('acct.transactions.index'), this.query(), { preserveState: true }); },
    setStatus(s) { this.f.status = s; this.go(); },
    sortBy(c) { this.f.sort = c; this.f.dir = this.f.sort === c && this.f.dir === 'asc' ? 'desc' : 'asc'; this.go(); },
    reset() { this.f = {}; this.go(); },
    exportUrl(fmt) { return route('acct.reports.' + fmt, { type: 'transaction_register', account_id: this.f.account_id || undefined, from: this.f.from || undefined, to: this.f.to || undefined, currency: this.f.currency || undefined, direction: this.f.direction || undefined, status: this.f.status || undefined, user_id: this.f.creator || undefined }); },
  },
};
</script>
