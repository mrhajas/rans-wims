<template>
  <admin-layout :title="account.name">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="account.group === 'bank' ? 'cash' : 'in'" :title="account.name" :subtitle="account.code + ' - ' + account.kind_label + (account.institution ? ' - ' + account.institution : '')">
        <Link :href="route('acct.accounts.index', { group: account.group })" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <Link v-if="can.update && account.status !== 'closed'" :href="route('acct.accounts.edit', account.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
              <acct-export type="account_statement" :params="{ account_id: account.id }" />
      </page-header>

      <div class="ac-actions" v-if="account.status === 'active'">
        <Link v-if="can.inflow" :href="route('acct.transactions.create', { direction: 'in', kind: 'receipt', account_id: account.id })" class="lg-btn lg-btn-green"><icons name="in" class="w-4 h-4" />{{ $t('Money In') }}</Link>
        <Link v-if="can.outflow" :href="route('acct.transactions.create', { direction: 'out', kind: 'payment', account_id: account.id })" class="lg-btn lg-btn-red"><icons name="out" class="w-4 h-4" />{{ $t('Money Out') }}</Link>
        <Link v-if="can.transfer" :href="route('acct.transfers.create', { from_account_id: account.id })" class="lg-btn lg-btn-primary"><icons name="refresh" class="w-4 h-4" />{{ $t('Transfer') }}</Link>
        <Link v-if="can.reconcile" :href="route('acct.reconciliations.create', { account_id: account.id, kind: account.group === 'bank' ? 'bank' : 'cash' })" class="lg-btn lg-btn-soft"><icons name="tick" class="w-4 h-4" />{{ account.group === 'bank' ? $t('Reconcile with statement') : $t('Count cash') }}</Link>
        <Link v-if="can.reports" :href="route('acct.reports.index', { type: 'account_statement', account_id: account.id })" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" />{{ $t('Statement') }}</Link>
      </div>
      <div v-else class="lg-note lg-note-amber" style="margin-bottom: 1rem">{{ $t('This account is :s. New transactions are not accepted.', { s: $t(account.status) }) }} <span v-if="account.closed_reason">({{ account.closed_reason }})</span></div>

      <section class="ac-grid">
        <div class="ac-cur"><div class="ac-cur-code">{{ $t('Balance now') }}</div><div class="ac-cur-total" :class="{ 'ac-neg': neg(account.balance) }">{{ mc(account.balance, account.currency) }}</div><div class="ac-split"><span class="lg-chip" :class="statusChip(account.status)">{{ $t(account.status) }}</span><span v-if="account.low" class="ac-out"><b>{{ $t('Below minimum') }}</b></span></div></div>
        <div class="ac-cur"><div class="ac-cur-code">{{ $t('Waiting to be posted') }}</div><div class="ac-split" v-if="pendingHere"><span>{{ $t('In') }} <b class="ac-in">{{ mc(pendingHere.in, account.currency) }}</b></span><span>{{ $t('Out') }} <b class="ac-out">{{ mc(pendingHere.out, account.currency) }}</b></span><span>{{ pending.count }} {{ $t('items') }}, {{ pending.transfers }} {{ $t('transfers') }}</span></div><div class="ac-split" v-else>{{ $t('Nothing is waiting.') }}</div><div class="ac-split" style="margin-top: 6px">{{ $t('These are not in the balance until they are posted.') }}</div></div>
        <div class="ac-cur"><div class="ac-cur-code">{{ $t('Last reconciliation') }}</div><template v-if="last_recon"><div class="ac-cur-total" style="font-size: 1.05rem">{{ $date(last_recon.period_to) }}</div><div class="ac-split"><span>{{ last_recon.reference }}</span><span :class="{ 'ac-out': Number(last_recon.difference) !== 0 }">{{ $t('Difference') }} <b>{{ mc(last_recon.difference, account.currency) }}</b></span></div></template><div v-else class="ac-split">{{ $t('Never reconciled') }}</div><div v-if="open_recon" class="ac-split" style="margin-top: 6px"><Link :href="route('acct.reconciliations.show', open_recon.id)">{{ open_recon.reference }} ({{ $t(open_recon.status) }})</Link></div></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="calendar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Historical Balance') }}</h2><p class="lg-card-sub">{{ $t('Rebuilt from the ledger, so it is always the figure that was true that day.') }}</p></div></header>
        <form class="ac-filters" @submit.prevent="asOf"><div class="ac-f"><label>{{ $t('Date') }}</label><date-picker v-model="date" :max="today" /></div><div class="ac-f"><button type="submit" class="lg-btn lg-btn-soft"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Show') }}</button></div></form>
        <p v-if="as_of" class="ac-cur-total" style="margin-top: 10px">{{ $date(as_of) }}: {{ mc(as_of_balance, account.currency) }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="chart-bar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Balance Breakdown') }}</h2></div></header>
        <dl class="ac-kv">
          <div><dt>{{ $t('Opening balance') }}</dt><dd>{{ mc(figures.opening, account.currency) }}</dd></div>
          <div><dt>{{ $t('Posted money in') }}</dt><dd class="ac-in">{{ mc(figures.in, account.currency) }}</dd></div>
          <div><dt>{{ $t('Posted money out') }}</dt><dd class="ac-out">{{ mc(figures.out, account.currency) }}</dd></div>
          <div><dt>{{ $t('of which transfers in') }}</dt><dd>{{ mc(figures.transfers_in, account.currency) }}</dd></div>
          <div><dt>{{ $t('of which transfers out') }}</dt><dd>{{ mc(figures.transfers_out, account.currency) }}</dd></div>
          <div><dt>{{ $t('Balance from the ledger') }}</dt><dd>{{ mc(figures.ledger_balance, account.currency) }}</dd></div>
        </dl>
        <div v-if="!figures.in_sync" class="lg-note lg-note-red" style="margin-top: 8px">{{ $t('The stored balance does not match the ledger.') }} <tec-confirms-password v-if="can.repair" :content="$t('This rewrites the stored balance from the ledger. Please confirm your password to continue.')" @confirmed="$inertia.post(route('acct.accounts.repair', account.id))"><button type="button" class="lg-btn t-violet"><icons name="refresh" class="w-4 h-4" />{{ $t('Check balance') }}</button></tec-confirms-password></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv">
          <div v-if="account.account_holder"><dt>{{ $t('Account holder') }}</dt><dd>{{ account.account_holder }}</dd></div>
          <div v-if="account.masked || account.account_number"><dt>{{ $t('Account number') }}</dt><dd>{{ account.account_number || account.masked }} <small v-if="account.sensitive_hidden && account.masked" class="lg-subtext">{{ $t('masked') }}</small></dd></div>
          <div v-if="account.branch"><dt>{{ $t('Branch') }}</dt><dd>{{ account.branch }}</dd></div>
          <div v-if="account.swift"><dt>SWIFT</dt><dd>{{ account.swift }}</dd></div>
          <div v-if="account.iban"><dt>IBAN</dt><dd>{{ account.iban }}</dd></div>
          <div v-if="account.legal_entity"><dt>{{ $t('Legal entity') }}</dt><dd>{{ account.legal_entity }}</dd></div>
          <div><dt>{{ $t('Custodian') }}</dt><dd>{{ account.custodian || '-' }}</dd></div>
          <div v-if="account.warehouse"><dt>{{ $t('Project / warehouse') }}</dt><dd>{{ account.warehouse }}</dd></div>
          <div v-if="account.department"><dt>{{ $t('Department') }}</dt><dd>{{ account.department }}</dd></div>
          <div v-if="account.location"><dt>{{ $t('Location') }}</dt><dd>{{ account.location }}</dd></div>
          <div><dt>{{ $t('Opened') }}</dt><dd>{{ account.opening_date ? $date(account.opening_date) : '-' }}</dd></div>
          <div v-if="account.min_balance"><dt>{{ $t('Minimum balance') }}</dt><dd>{{ mc(account.min_balance, account.currency) }}</dd></div>
          <div v-if="account.max_balance"><dt>{{ $t('Maximum balance') }}</dt><dd>{{ mc(account.max_balance, account.currency) }}</dd></div>
          <div v-if="account.txn_limit"><dt>{{ $t('Limit for one transaction') }}</dt><dd>{{ mc(account.txn_limit, account.currency) }}</dd></div>
          <div><dt>{{ $t('Negative balance') }}</dt><dd>{{ account.allow_negative ? $t('Allowed') : $t('Not allowed') }}</dd></div>
          <div><dt>{{ $t('Approval') }}</dt><dd>{{ account.requires_approval ? $t('Always needed') : $t('By amount rules') }}</dd></div>
          <div v-if="account.reconcile_every_days"><dt>{{ $t('Reconcile every') }}</dt><dd>{{ account.reconcile_every_days }} {{ $t('days') }}</dd></div>
          <div v-if="account.fund_account_id"><dt>{{ $t('Funds Allocation') }}</dt><dd>{{ $t('Linked') }}</dd></div>
        </dl>
        <p v-if="account.notes" class="lg-sub" style="margin-top: 8px">{{ account.notes }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Recent Transactions') }}</h2></div><Link :href="route('acct.transactions.index', { account_id: account.id })" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All') }}</Link></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Description') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th></tr></thead>
          <tbody>
            <tr v-for="t in recent" :key="t.id" class="is-click" @click="$inertia.visit(route('acct.transactions.show', t.id))">
              <td :data-label="$t('Reference')" class="lg-main">{{ t.reference }}</td><td :data-label="$t('Date')">{{ $date(t.date) }}</td><td :data-label="$t('Description')"><span class="ac-clip">{{ t.description }}</span></td>
              <td :data-label="$t('Amount')" class="ac-num" :class="t.direction === 'in' ? 'ac-in' : 'ac-out'">{{ t.direction === 'in' ? '+' : '-' }}{{ mc(t.amount, account.currency) }}</td><td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(t.status)">{{ $t(t.status) }}</span></td>
            </tr>
            <tr v-if="!recent.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No transactions yet.') }}</td></tr>
          </tbody></table></div>
      </section>

      <section v-if="can.status || can.close" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="cog" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Account Status') }}</h2><p class="lg-card-sub">{{ $t('Closing needs a zero balance and nothing pending. A closed account stays in the records.') }}</p></div></header>
        <div class="ac-actions">
          <button v-if="account.status !== 'active' && account.status !== 'closed' && can.status" type="button" class="lg-btn lg-btn-green" @click="change('activate')"><icons name="tick" class="w-4 h-4" />{{ $t('Activate') }}</button>
          <button v-if="account.status === 'active' && can.status" type="button" class="lg-btn t-amber" @click="ask = 'deactivate'"><icons name="pause" class="w-4 h-4" />{{ $t('Deactivate') }}</button>
          <button v-if="account.status === 'active' && can.status" type="button" class="lg-btn t-amber" @click="ask = 'suspend'"><icons name="pause" class="w-4 h-4" />{{ $t('Suspend') }}</button>
          <button v-if="account.status !== 'closed' && can.close" type="button" class="lg-btn lg-btn-red" @click="ask = 'close'"><icons name="x-circle" class="w-4 h-4" />{{ $t('Close account') }}</button>
        </div>
        <form v-if="ask" class="ac-reason" @submit.prevent="change(ask)"><input v-model="reason" :placeholder="$t('Reason (required)')" required /><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="ask = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
      </section>

      <section v-if="history && history.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="ac-timeline"><li v-for="(h, i) in history" :key="i"><b>{{ h.event }}</b> - {{ h.user || '-' }} <small>{{ h.at }}</small><div v-if="h.reason">{{ h.reason }}</div></li></ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import TecConfirmsPassword from '@/Jetstream/ConfirmsPassword.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, DatePicker, AdminLayout, PageHeader, Link, TecConfirmsPassword },
  mixins: [acct],
  props: { cur: Object, account: Object, as_of: String, as_of_balance: String, figures: Object, pending: Object, last_recon: Object, open_recon: Object, recent: Array, attachments: Array, history: Array, can: Object },
  data() { return { date: this.as_of || '', ask: '', reason: '', today: new Date().toISOString().slice(0, 10) }; },
  computed: { pendingHere() { return this.pending && this.pending.by ? this.pending.by[this.account.currency] : null; } },
  methods: {
    asOf() { this.$inertia.get(route('acct.accounts.show', this.account.id), { as_of: this.date || undefined }, { preserveState: true, preserveScroll: true }); },
    change(action) { this.$inertia.post(route('acct.accounts.status', this.account.id), { action, reason: this.reason }, { preserveScroll: true, onSuccess: () => { this.ask = ''; this.reason = ''; } }); },
  },
};
</script>
