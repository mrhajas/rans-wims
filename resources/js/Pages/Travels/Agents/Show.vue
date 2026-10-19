<template>
  <admin-layout :title="agent.name">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cash" :title="agent.name" :subtitle="[agent.category, agent.phone, agent.location].filter(Boolean).join(' · ') || $t('Ticket agent account')">
        <Link :href="route('travels.agents.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('All agents') }}</span></Link>
        <a v-if="agent.contact_url" :href="agent.contact_url" class="lg-btn lg-btn-soft"><icons name="user" class="w-4 h-4" /><span>{{ $t('Contact') }}</span></a>
        <button v-if="can.pay" type="button" class="lg-btn lg-btn-primary" @click="openPay"><icons name="cash" class="w-4 h-4" /><span>{{ $t('Make a payment') }}</span></button>
        <button v-if="can.manage" type="button" class="lg-btn lg-btn-soft" @click="openOpening()"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Opening balance') }}</span></button>
        <travel-export type="agent_statement" :params="{ contact_id: agent.id, currency: filters.currency, from: filters.from, to: filters.to }" />
      </page-header>

      <p v-if="$page.props.flash && $page.props.flash.message" class="lg-note lg-note-green" role="status" style="margin-bottom: 12px"><icons name="tick" class="w-4 h-4" /><span>{{ $page.props.flash.message }}</span></p>

      <!-- the balance, one card per currency -->
      <div v-if="balances.length" class="ag-cards">
        <div v-for="b in balances" :key="b.currency" class="ag-card">
          <span class="ag-cur">{{ b.currency }}</span>
          <div class="ag-big"><small>{{ $t('Balance owed to the agent') }}</small><b :class="{ 'ac-neg': Number(b.balance) < 0 }">{{ money(b.balance) }}</b><em v-if="Number(b.balance) < 0">{{ $t('paid in advance') }}</em></div>
          <div class="ag-grid">
            <div><small>{{ $t('Opening balance') }}</small><b>{{ money(b.opening) }}</b><span v-if="b.opening_date">{{ $t('as of :d', { d: dt(b.opening_date) }) }}</span></div>
            <div><small>{{ $t('Tickets') }}</small><b>{{ money(b.ticket) }}</b></div>
            <div><small>{{ $t('Visas') }}</small><b>{{ money(b.visa) }}</b></div>
            <div><small>{{ $t('Paid') }}</small><b>{{ money(b.paid) }}</b><span>{{ b.payments }} {{ $t('payment(s)') }}</span></div>
            <div v-if="Number(b.reserved)"><small>{{ $t('In waiting vouchers') }}</small><b>{{ money(b.reserved) }}</b></div>
            <div v-if="Number(b.pending)"><small>{{ $t('Pending approval') }}</small><b>{{ money(b.pending) }}</b></div>
          </div>
          <button v-if="can.pay && Number(b.available) > 0" type="button" class="ag-pay" @click="openPay(b.currency)"><icons name="cash" class="w-4 h-4" />{{ $t('Pay up to :a', { a: b.currency + ' ' + money(b.available) }) }}</button>
        </div>
      </div>
      <div v-else class="lg-card"><p class="lg-sub">{{ $t('Nothing has been recorded for this agent yet. Set an opening balance for what was owed before, or record a travel and approve it.') }}</p></div>

      <sc-tabs :tabs="tabs" :model-value="tab" @update:model-value="k => (tab = k)" />

      <!-- statement -->
      <section v-show="tab === 'statement'" class="lg-card">
        <form class="ac-filters ag-filters" @submit.prevent="filter">
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencyOptions" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f"><label aria-hidden="true">&nbsp;</label><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Show') }}</button></div>
        </form>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Date') }}</th><th>{{ $t('Reference') }}</th><th>{{ $t('Details') }}</th><th>{{ $t('Cur.') }}</th><th class="ac-num">{{ $t('Owed') }}</th><th class="ac-num">{{ $t('Paid') }}</th><th class="ac-num">{{ $t('Balance') }}</th></tr></thead>
          <tbody>
            <tr v-for="(l, i) in statement" :key="i" :class="{ 'ag-memo': l.memo }">
              <td :data-label="$t('Date')">{{ $date(l.date) }}</td>
              <td :data-label="$t('Reference')"><Link v-if="l.travel_id && !l.payment_id" :href="route('travels.show', l.travel_id)" class="lg-main">{{ l.reference }}</Link><Link v-else-if="l.payment_id" :href="route('travels.agents.payments.show', l.payment_id)" class="lg-main">{{ l.reference }}</Link><span v-else>{{ l.reference || '-' }}</span></td>
              <td :data-label="$t('Details')">{{ l.text }}<span v-if="l.memo" class="lg-chip lg-chip-slate" style="margin-left: 6px; text-transform: none">{{ $t('not counted') }}</span><div v-if="l.memo" class="lg-subtext">{{ l.currency }} {{ money(l.paid) }}</div></td>
              <td :data-label="$t('Cur.')">{{ l.currency }}</td>
              <td :data-label="$t('Owed')" class="ac-num">{{ l.owed ? money(l.owed) : '' }}</td>
              <td :data-label="$t('Paid')" class="ac-num"><b v-if="l.paid && !l.memo" class="ac-in">{{ money(l.paid) }}</b></td>
              <td :data-label="$t('Balance')" class="ac-num"><b :class="{ 'ac-neg': Number(l.balance) < 0 }">{{ money(l.balance) }}</b></td>
            </tr>
            <tr v-if="!statement.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('Nothing in this period.') }}</td></tr>
          </tbody>
        </table></div>
        <p class="lg-sub" style="margin-top: 8px">{{ $t('A payment counts only when its Payment Voucher is marked paid. A voucher still waiting, or one that was not paid, is listed but is not counted.') }}</p>
      </section>

      <!-- payment history: every voucher -->
      <section v-show="tab === 'payments'" class="lg-card">
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Payment') }}</th><th>{{ $t('Asked on') }}</th><th>{{ $t('Paid on') }}</th><th>{{ $t('Payment Voucher') }}</th><th>{{ $t('Voucher status') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th class="ac-num">{{ $t('Balance after') }}</th><th>{{ $t('Asked by') }}</th><th>{{ $t('Paid by') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="p in payments" :key="p.id" class="is-click" @click="$inertia.visit(route('travels.agents.payments.show', p.id))">
              <td :data-label="$t('Payment')"><span class="lg-main">{{ p.reference }}</span><div class="lg-subtext"><span class="lg-chip" :class="payChip(p.status)" style="text-transform: none">{{ p.status_label }}</span><template v-if="p.travel"> · {{ p.travel }}</template></div></td>
              <td :data-label="$t('Asked on')">{{ $date(p.requested_on) }}</td>
              <td :data-label="$t('Paid on')">{{ p.paid_on ? $date(p.paid_on) : '-' }}</td>
              <td :data-label="$t('Payment Voucher')" @click.stop><a v-if="p.voucher_id && can.vouchers" :href="route('payment-vouchers.show', p.voucher_id)" target="_blank" rel="noopener" class="lg-main">{{ p.pv_number }}</a><span v-else>{{ p.pv_number || '-' }}</span></td>
              <td :data-label="$t('Voucher status')">{{ p.voucher_status || '-' }}</td>
              <td :data-label="$t('Amount')" class="ac-num"><b class="amt">{{ p.currency }} {{ money(p.amount) }}</b></td>
              <td :data-label="$t('Balance after')" class="ac-num">{{ p.balance_after !== null && p.status === 'Paid' ? money(p.balance_after) : '-' }}</td>
              <td :data-label="$t('Asked by')">{{ p.by || '-' }}</td>
              <td :data-label="$t('Paid by')">{{ p.paid_by || '-' }}</td>
              <td class="lg-sticky-end" @click.stop><Link :href="route('travels.agents.payments.show', p.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Details')" :aria-label="$t('Details')"><icons name="doc" class="w-4 h-4" /></Link></td>
            </tr>
            <tr v-if="!payments.length" class="lg-empty-row"><td class="lg-empty" colspan="10">{{ $t('No payment has been asked for yet. Use Make a payment to start a Payment Voucher.') }}</td></tr>
          </tbody>
        </table></div>
      </section>

      <!-- the travels they issued -->
      <section v-show="tab === 'travels'" class="lg-card">
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Travel') }}</th><th>{{ $t('Traveller(s)') }}</th><th>{{ $t('To') }}</th><th>{{ $t('Departs') }}</th><th>{{ $t('Cur.') }}</th><th class="ac-num">{{ $t('Ticket') }}</th><th class="ac-num">{{ $t('Visa') }}</th><th>{{ $t('Status') }}</th></tr></thead>
          <tbody>
            <tr v-for="t in travels" :key="t.id" class="is-click" @click="$inertia.visit(route('travels.show', t.id))">
              <td :data-label="$t('Travel')" class="lg-main">{{ t.reference }}</td><td :data-label="$t('Traveller(s)')">{{ t.travellers }}</td><td :data-label="$t('To')">{{ t.to_place }}<div v-if="t.to_country" class="lg-subtext">{{ t.to_country }}</div></td>
              <td :data-label="$t('Departs')">{{ $date(t.depart_date) }}</td><td :data-label="$t('Cur.')">{{ t.currency }}</td><td :data-label="$t('Ticket')" class="ac-num">{{ Number(t.ticket) ? money(t.ticket) : '-' }}</td><td :data-label="$t('Visa')" class="ac-num">{{ Number(t.visa) ? money(t.visa) : '-' }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(t.status)">{{ t.status_label }}</span></td>
            </tr>
            <tr v-if="!travels.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('No travel was issued by this agent yet.') }}</td></tr>
          </tbody>
        </table></div>
      </section>

      <!-- opening balances -->
      <section v-show="tab === 'opening'" class="lg-card">
        <p class="lg-sub" style="margin-bottom: 10px">{{ $t('What the agent was owed before Travels started, per currency. Travels booked before the date are already inside it and are not counted again.') }}</p>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Currency') }}</th><th class="ac-num">{{ $t('Amount owed') }}</th><th>{{ $t('As of') }}</th><th>{{ $t('Notes') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="o in openings" :key="o.id"><td :data-label="$t('Currency')">{{ o.currency }}</td><td :data-label="$t('Amount owed')" class="ac-num"><b>{{ money(o.amount) }}</b></td><td :data-label="$t('As of')">{{ $date(o.as_of_date) }}</td><td :data-label="$t('Notes')">{{ o.notes || '-' }}</td>
              <td class="lg-sticky-end"><template v-if="can.manage"><button type="button" class="lg-icon-btn" :aria-label="$t('Change')" @click="openOpening(o)"><icons name="edit" class="w-4 h-4" /></button><button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="removeOpening(o)"><icons name="trash" class="w-4 h-4" /></button></template></td></tr>
            <tr v-if="!openings.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No opening balance. This agent starts at zero.') }}</td></tr>
          </tbody>
        </table></div>
      </section>
    </div>

    <!-- make a payment: starts a Payment Voucher -->
    <tec-dialog-modal :show="paying" max-width="lg" @close="paying = false">
      <template #title><span class="ag-title"><icons name="cash" class="w-5 h-5" />{{ $t('Make a payment to :a', { a: agent.name }) }}</span></template>
      <template #content>
        <div class="ac sc-ui">
        <p class="lg-note lg-note-blue" style="margin-bottom: 12px"><icons name="info" class="w-4 h-4" /><span>{{ $t('This starts a Payment Voucher in the Payment Vouchers module. It waits there for approval and is marked paid there. The balance of the agent falls only when the voucher is paid.') }}</span></p>
        <div class="lg-fields">
          <div class="ac-f"><label>{{ $t('Currency') }} *</label><acct-select v-model="pay.currency" :options="currencyOptions" /></div>
          <div class="ac-f"><label>{{ $t('Amount') }} ({{ pay.currency }}) *</label><input v-model="pay.amount" inputmode="decimal" /><span class="ac-err">{{ perr('amount') }}</span><small class="lg-subtext">{{ $t('Up to :a', { a: pay.currency + ' ' + money(availableOf(pay.currency)) }) }}</small></div>
          <div v-if="pay.currency !== 'TZS'" class="ac-f"><label>{{ $t('Exchange rate (TZS per :c)', { c: pay.currency }) }} *</label><input v-model="pay.exchange_rate" inputmode="decimal" /><span class="ac-err">{{ perr('exchange_rate') }}</span><small class="lg-subtext">{{ $t('The voucher is in TZS: :t', { t: 'TZS ' + money((Number(pay.amount) || 0) * (Number(pay.exchange_rate) || 0)) }) }}</small></div>
          <div class="ac-f"><label>{{ $t('For a travel (optional)') }}</label><acct-select v-model="pay.travel_id" :options="travelOptions" :placeholder="$t('Not for one travel')" clearable /><span class="ac-err">{{ perr('travel_id') }}</span></div>
          <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Notes') }}</label><input v-model="pay.notes" /></div>
        </div>
        </div>
      </template>
      <template #footer><div class="flex items-center justify-end gap-2"><button type="button" class="lg-btn lg-btn-soft" @click="paying = false">{{ $t('Cancel') }}</button><button type="button" class="lg-btn lg-btn-primary" :disabled="paying_busy || !Number(pay.amount)" @click="requestPayment"><icons name="send" class="w-4 h-4" />{{ paying_busy ? $t('Starting...') : $t('Start the Payment Voucher') }}</button></div></template>
    </tec-dialog-modal>

    <!-- opening balance -->
    <tec-dialog-modal :show="opening.open" max-width="md" @close="opening.open = false">
      <template #title><span class="ag-title"><icons name="clock" class="w-5 h-5" />{{ $t('Opening balance of :a', { a: agent.name }) }}</span></template>
      <template #content>
        <div class="ac sc-ui">
        <div class="lg-fields">
          <div class="ac-f"><label>{{ $t('Currency') }} *</label><acct-select v-model="opening.currency" :options="currencyOptions" /></div>
          <div class="ac-f"><label>{{ $t('Amount owed to the agent') }} *</label><input v-model="opening.amount" inputmode="decimal" /><span class="ac-err">{{ perr('amount') }}</span></div>
          <div class="ac-f"><label>{{ $t('As of') }} *</label><date-picker v-model="opening.as_of_date" :clearable="false" /><span class="ac-err">{{ perr('as_of_date') }}</span></div>
          <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Notes') }}</label><input v-model="opening.notes" :placeholder="$t('Where the figure comes from')" /></div>
        </div>
        </div>
      </template>
      <template #footer><div class="flex items-center justify-end gap-2"><button type="button" class="lg-btn lg-btn-soft" @click="opening.open = false">{{ $t('Cancel') }}</button><button type="button" class="lg-btn lg-btn-primary" :disabled="opening.busy" @click="saveOpening"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></div></template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import TravelExport from '../TravelExport.vue';
import ScTabs from '../../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';

export default {
  components: { TravelExport, ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, TecDialogModal },
  props: { agent: Object, balances: Array, statement: Array, travels: Array, payments: Array, openings: Array, filters: Object, currencies: Array, today: String, can: Object },
  data() {
    return {
      tab: 'statement', f: { ...this.filters }, paying: false, paying_busy: false,
      pay: { currency: this.currencies[0] || 'TZS', amount: '', exchange_rate: '', travel_id: null, notes: '' },
      opening: { open: false, busy: false, currency: this.currencies[0] || 'TZS', amount: '', as_of_date: this.today, notes: '' },
    };
  },
  computed: {
    tabs() { return [{ key: 'statement', label: this.$t('Statement'), icon: 'list', tone: 'navy' }, { key: 'payments', label: this.$t('Payments'), icon: 'cash', tone: 'emerald', count: this.payments.length }, { key: 'travels', label: this.$t('Travels'), icon: 'plane', tone: 'sky', count: this.travels.length }, { key: 'opening', label: this.$t('Opening balance'), icon: 'clock', tone: 'amber' }]; },
    currencyOptions() { return [...new Set([...this.balances.map(b => b.currency), ...this.currencies])].map(c => ({ value: c, label: c })); },
    travelOptions() { return this.travels.filter(t => t.status === 'Approved').map(t => ({ value: t.id, label: t.reference + ' - ' + t.to_place + ' (' + this.dt(t.depart_date) + ')' })); },
  },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    dt(v) { try { return this.$date(v); } catch (e) { return v; } },
    perr(k) { return (this.$page.props.errors || {})[k]; },
    statusChip(s) { return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    payChip(s) { return { Paid: 'lg-chip-green', 'Pending Voucher': 'lg-chip-amber', Released: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    availableOf(c) { const b = this.balances.find(x => x.currency === c); return b ? Math.max(0, Number(b.available)) : 0; },
    filter() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('travels.agents.show', this.agent.id), q, { preserveState: true, preserveScroll: true }); },
    openPay(cur) { const c = typeof cur === 'string' ? cur : (this.balances.find(b => Number(b.available) > 0) || {}).currency || this.currencies[0] || 'TZS'; this.pay = { currency: c, amount: String(this.availableOf(c) || ''), exchange_rate: '', travel_id: null, notes: '' }; this.paying = true; },
    requestPayment() {
      this.paying_busy = true;
      this.$inertia.post(route('travels.agents.payments.store', this.agent.id), { ...this.pay, travel_id: this.pay.travel_id || undefined }, { preserveScroll: true, onFinish: () => (this.paying_busy = false), onSuccess: () => (this.paying = false) });
    },
    openOpening(o) { this.opening = o ? { open: true, busy: false, currency: o.currency, amount: String(o.amount), as_of_date: o.as_of_date, notes: o.notes || '' } : { open: true, busy: false, currency: this.currencies[0] || 'TZS', amount: '', as_of_date: this.today, notes: '' }; },
    saveOpening() { this.opening.busy = true; this.$inertia.post(route('travels.agents.opening.store', this.agent.id), { currency: this.opening.currency, amount: this.opening.amount, as_of_date: this.opening.as_of_date, notes: this.opening.notes }, { preserveScroll: true, onFinish: () => (this.opening.busy = false), onSuccess: () => (this.opening.open = false) }); },
    removeOpening(o) { if (window.confirm(this.$t('Remove the opening balance in :c?', { c: o.currency }))) this.$inertia.delete(route('travels.agents.opening.destroy', [this.agent.id, o.id]), { preserveScroll: true }); },
  },
};
</script>

<style scoped>
.ag-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; margin-bottom: 14px; }
.ag-card { position: relative; display: grid; gap: 12px; padding: 16px 18px; border-radius: 22px; background: linear-gradient(135deg, rgba(14, 116, 144, 0.12), rgba(79, 70, 229, 0.07)), var(--sc-card, #fff); box-shadow: 0 0 0 1px var(--sc-line, #e3e9f2), 0 16px 26px -20px rgba(14, 116, 144, 0.75); }
.ag-cur { position: absolute; top: 14px; right: 16px; padding: 3px 11px; border-radius: 999px; background: #0e7490; color: #fff; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; }
.ag-big { display: grid; gap: 2px; } .ag-big small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--sc-mute, #64748b); } .ag-big b { font-size: 1.7rem; font-variant-numeric: tabular-nums; } .ag-big em { font-style: normal; font-size: 0.74rem; font-weight: 700; color: #b45309; }
.ag-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px 14px; }
.ag-grid > div { display: grid; gap: 1px; min-width: 0; } .ag-grid small { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase; color: var(--sc-mute, #64748b); } .ag-grid b { font-variant-numeric: tabular-nums; } .ag-grid span { font-size: 0.7rem; color: var(--sc-mute, #64748b); }
.ag-pay { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; border-radius: 14px; border: 0; background: linear-gradient(135deg, #0e7490, #155e75); color: #fff; font-size: 0.84rem; font-weight: 800; cursor: pointer; }
.ag-filters { grid-template-columns: repeat(4, minmax(0, 1fr)); align-items: end; margin-bottom: 10px; } @media (max-width: 760px) { .ag-filters { grid-template-columns: 1fr 1fr; } }
.ag-memo td { color: var(--sc-mute, #64748b); font-style: italic; }
.ag-title { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; }
</style>
