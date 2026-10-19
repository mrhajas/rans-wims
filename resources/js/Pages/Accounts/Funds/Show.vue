<template>
  <admin-layout :title="a.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="collection" :title="a.reference" :subtitle="a.title || $t('Funds allocation')">
        <Link :href="route('acct.funds.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <Link v-if="can.receive" :href="route('acct.transactions.create', { direction: 'in', kind: 'receipt', fund_allocation_id: a.id })" class="lg-btn lg-btn-green"><icons name="in" class="w-4 h-4" /><span>{{ $t('Record cash received') }}</span></Link>
              <acct-export type="funds_allocation" />
      </page-header>
      <div class="lg-note lg-note-blue" style="margin-bottom: 1rem"><icons name="info" class="w-4 h-4" /><span>{{ $t('Allocated is the plan (:p). It does not change any balance. Money is counted only when a receipt or payment is posted against this allocation.', { p: a.allocated + ' ' + $t('in the sheet currencies') }) }}</span></div>
      <section class="ac-grid">
        <div v-for="f in flows" :key="f.currency" class="ac-cur" :style="f.overspent ? 'box-shadow: 0 0 0 1.5px #e11d48' : ''">
          <div class="ac-cur-code">{{ f.currency }}</div>
          <div class="ac-split"><span>{{ $t('Received') }} <b class="ac-in">{{ m(f.received, f.currency) }}</b></span><span>{{ $t('Spent') }} <b class="ac-out">{{ m(f.spent, f.currency) }}</b></span></div>
          <div class="ac-cur-total" :class="{ 'ac-neg': neg(f.remaining) }">{{ m(f.remaining, f.currency) }}</div><div class="ac-split"><span>{{ $t('Remaining') }}</span><span v-if="Number(f.pending)">{{ $t('Pending') }} <b>{{ m(f.pending, f.currency) }}</b></span><span v-if="f.overspent" class="lg-chip lg-chip-red">{{ $t('Spent more than received') }}</span></div>
        </div>
        <div v-if="!flows.length" class="ac-cur"><div class="ac-cur-code">{{ $t('No money recorded yet') }}</div></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="out" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Paid Lines') }}</h2><p class="lg-card-sub">{{ $t('A line marked paid in the allocation can be recorded once as a payment from one of your accounts.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('For') }}</th><th>{{ $t('Method') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Paid') }}</th><th>{{ $t('In accounts') }}</th></tr></thead>
          <tbody>
            <tr v-for="l in lines" :key="l.id">
              <td :data-label="$t('For')"><span class="ac-clip lg-main">{{ l.description }}</span><div class="lg-subtext">{{ l.beneficiary }}</div></td><td :data-label="$t('Method')">{{ l.method }}</td><td :data-label="$t('Amount')" class="ac-num">{{ mc(l.amount, l.currency) }}</td>
              <td :data-label="$t('Paid')"><span class="lg-chip" :class="l.paid ? 'lg-chip-green' : 'lg-chip-slate'">{{ l.paid ? $t('Paid') : $t('Not paid') }}</span></td>
              <td :data-label="$t('In accounts')">
                <template v-if="l.posted.length"><Link v-for="p in l.posted" :key="p.id" :href="route('acct.transactions.show', p.id)" style="display: block">{{ p.reference }} ({{ $t(p.status) }})</Link></template>
                <template v-else-if="l.paid && can.post"><form class="ac-reason" style="margin: 0" @submit.prevent="record(l)"><acct-select v-model="pick[l.id]" :options="accounts.filter(z => z.currency === l.currency)" :placeholder="$t('Pay from...')" clearable style="min-width: 220px" /><button type="submit" class="lg-btn lg-btn-soft"><icons name="tick" class="w-4 h-4" />{{ $t('Record') }}</button></form></template>
                <span v-else class="lg-subtext">-</span>
              </td>
            </tr>
            <tr v-if="!lines.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('There are no payment lines.') }}</td></tr>
          </tbody></table></div>
      </section>

      <section v-if="transactions.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="in" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Other Entries on this Allocation') }}</h2></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Account') }}</th><th>{{ $t('Description') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th></tr></thead>
          <tbody><tr v-for="t in transactions" :key="t.id" class="is-click" @click="$inertia.visit(route('acct.transactions.show', t.id))"><td :data-label="$t('Reference')" class="lg-main">{{ t.reference }}</td><td :data-label="$t('Date')">{{ $date(t.txn_date) }}</td><td :data-label="$t('Account')">{{ t.account }}</td><td :data-label="$t('Description')">{{ t.description }}</td>
            <td :data-label="$t('Amount')" class="ac-num" :class="t.direction === 'in' ? 'ac-in' : 'ac-out'">{{ t.direction === 'in' ? '+' : '-' }}{{ mc(t.amount, t.currency) }}</td><td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(t.status)">{{ $t(t.status) }}</span></td></tr></tbody></table></div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '../AcctSelect.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, a: Object, flows: Array, lines: Array, transactions: Array, accounts: Array, today: String, can: Object },
  data() { return { pick: {} }; },
  methods: { record(l) { this.$inertia.post(route('acct.funds.post-line', [this.a.id, l.id]), { account_id: this.pick[l.id], txn_date: this.today }, { preserveScroll: true }); } },
};
</script>
