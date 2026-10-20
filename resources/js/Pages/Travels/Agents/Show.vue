<template>
  <admin-layout :title="agent.name">
    <div class="lg-page ac sc-ui tr-ui px-4 md:px-0">
      <page-header icon="cash" :title="agent.name" :subtitle="[agent.category, agent.phone, agent.location].filter(Boolean).join(' · ') || $t('Ticket agent account')">
        <Link :href="route('travels.agents.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('All agents') }}</span></Link>
        <a v-if="agent.contact_url" :href="agent.contact_url" class="lg-btn lg-btn-soft"><icons name="user" class="w-4 h-4" /><span>{{ $t('Contact') }}</span></a>
        <button v-if="can.pay" type="button" class="lg-btn lg-btn-primary" @click="openPay"><icons name="cash" class="w-4 h-4" /><span>{{ $t('Make a payment') }}</span></button>
        <button v-if="can.manage" type="button" class="lg-btn lg-btn-soft" @click="openOpening()"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Opening balance') }}</span></button>
      </page-header>

      <p v-if="$page.props.flash && $page.props.flash.message" class="lg-note lg-note-green" role="status" style="margin-bottom: 12px"><icons name="tick" class="w-4 h-4" /><span>{{ $page.props.flash.message }}</span></p>

      <!-- the balance, one card per currency; label row and value row line up across every figure -->
      <div v-if="balances.length" class="ag-cards">
        <div v-for="b in balances" :key="b.currency" class="ag-card">
          <span class="ag-cur">{{ b.currency }}</span>
          <div class="ag-big"><small>{{ $t('Balance owed to the agent') }}</small><b class="tr-tm" :class="{ 'tr-neg': Number(b.balance) < 0 }">{{ b.currency }} {{ money(b.balance) }}</b><em v-if="Number(b.balance) < 0">{{ $t('paid in advance') }}</em></div>
          <div class="ag-stats">
            <div class="ag-stat is-maroon" style="--i: 0"><span class="ag-ico"><icons name="doc-text" class="w-5 h-5" /></span><div><small>{{ $t('Opening balance') }}</small><b class="tr-tm">{{ b.currency }} {{ money(b.opening) }}</b><em>{{ b.opening_date ? $t('as of :d', { d: dt(b.opening_date) }) : $t('before Travels') }}</em></div></div>
            <div class="ag-stat is-maroon" style="--i: 1"><span class="ag-ico"><icons name="plane" class="w-5 h-5" /></span><div><small>{{ $t('Tickets') }}</small><b class="tr-m">{{ b.currency }} {{ money(b.ticket) }}</b><em>{{ b.travels }} {{ $t('travel(s)') }}</em></div></div>
            <div class="ag-stat is-maroon" style="--i: 2"><span class="ag-ico"><icons name="globe" class="w-5 h-5" /></span><div><small>{{ $t('Visas') }}</small><b class="tr-m">{{ b.currency }} {{ money(b.visa) }}</b><em>{{ $t('issued') }}</em></div></div>
            <div class="ag-stat is-maroon" style="--i: 3"><span class="ag-ico"><icons name="building" class="w-5 h-5" /></span><div><small>{{ $t('Other costs') }}</small><b class="tr-m">{{ b.currency }} {{ money(b.other) }}</b><em>{{ $t('hotels, cars, more') }}</em></div></div>
            <div class="ag-stat is-green" style="--i: 4"><span class="ag-ico"><icons name="tick" class="w-5 h-5" /></span><div><small>{{ $t('Paid') }}</small><b class="tr-g">{{ b.currency }} {{ money(b.paid) }}</b><em>{{ b.payments }} {{ $t('payment(s)') }}</em></div></div>
            <div class="ag-stat is-orange" style="--i: 5"><span class="ag-ico"><icons name="clock" class="w-5 h-5" /></span><div><small>{{ $t('In waiting vouchers') }}</small><b class="tr-o">{{ b.currency }} {{ money(b.reserved) }}</b><em>{{ $t('not paid yet') }}</em></div></div>
            <div class="ag-stat is-orange" style="--i: 6"><span class="ag-ico"><icons name="refresh" class="w-5 h-5" /></span><div><small>{{ $t('Pending approval') }}</small><b class="tr-o">{{ b.currency }} {{ money(b.pending) }}</b><em>{{ $t('travels waiting') }}</em></div></div>
            <div class="ag-stat is-orange" style="--i: 7"><span class="ag-ico"><icons name="cash" class="w-5 h-5" /></span><div><small>{{ $t('Available to pay') }}</small><b class="tr-o">{{ b.currency }} {{ money(b.available) }}</b><em>{{ $t('can be asked for') }}</em></div></div>
          </div>
          <button v-if="can.pay && Number(b.available) > 0" type="button" class="ag-pay" @click="openPay(b.currency)"><icons name="cash" class="w-4 h-4" />{{ $t('Pay up to :a', { a: b.currency + ' ' + money(b.available) }) }}</button>
        </div>
      </div>
      <div v-else class="lg-card"><p class="lg-sub">{{ $t('Nothing has been recorded for this agent yet. Set an opening balance for what was owed before, or record a travel and approve it.') }}</p></div>

      <sc-tabs :tabs="tabs" :model-value="tab" @update:model-value="k => (tab = k)" />

      <!-- statement -->
      <section v-show="tab === 'statement'" class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencyOptions" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!(f.currency || f.from || f.to)" @click="f = { currency: null, from: null, to: null }"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin-top: 14px">
          <label class="tr-sbox"><icons name="search" /><input v-model="q.statement" type="search" :placeholder="$t('Search this statement')" autocomplete="off" /></label>
          <span class="tr-count">{{ $t(':n line(s)', { n: statementShown.length }) }}</span>
          <travel-export type="agent_statement" :params="{ contact_id: agent.id, currency: f.currency, from: f.from, to: f.to, search: q.statement }" />
        </div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive tr-table">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th class="tr-nw">{{ $t('Date') }}</th><th class="tr-nw">{{ $t('Reference') }}</th><th>{{ $t('Details') }}</th><th class="tr-c">{{ $t('Cur.') }}</th><th class="tr-num">{{ $t('Rate agreed') }}</th><th class="tr-num">{{ $t('Owed') }}</th><th class="tr-num">{{ $t('Paid') }}</th><th class="tr-num">{{ $t('Balance') }}</th></tr></thead>
          <tbody>
            <tr v-for="(l, i) in statementShown" :key="i" :class="{ 'tr-memo': l.memo }">
              <td :data-label="$t('No')" class="tr-no">{{ i + 1 }}</td>
              <td :data-label="$t('Date')" class="tr-nw">{{ $date(l.date) }}</td>
              <td :data-label="$t('Reference')" class="tr-nw"><Link v-if="l.payment_id" :href="route('travels.agents.payments.show', l.payment_id)" class="lg-main">{{ l.reference }}</Link><Link v-else-if="l.travel_id" :href="route('travels.show', l.travel_id)" class="lg-main">{{ l.reference }}</Link><span v-else>-</span></td>
              <td :data-label="$t('Details')" class="tr-wrap">{{ l.text }}<span v-if="l.memo" class="lg-chip lg-chip-slate" style="margin-left: 6px; text-transform: none">{{ $t('not counted') }}</span><small v-if="l.memo" class="lg-subtext" style="display: block">{{ l.currency }} {{ money(l.paid) }}</small></td>
              <td :data-label="$t('Cur.')" class="tr-c">{{ l.currency }}</td>
              <td :data-label="$t('Rate agreed')" class="tr-num">{{ l.rate || '-' }}</td>
              <td :data-label="$t('Owed')" class="tr-num"><span v-if="l.owed" class="tr-m">{{ money(l.owed) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Paid')" class="tr-num"><span v-if="l.paid && !l.memo" class="tr-g">{{ money(l.paid) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Balance')" class="tr-num"><b class="tr-tm" :class="{ 'tr-neg': Number(l.balance) < 0 }">{{ money(l.balance) }}</b></td>
            </tr>
            <tr v-if="!statementShown.length" class="lg-empty-row"><td class="lg-empty" colspan="9">{{ $t('Nothing matches.') }}</td></tr>
          </tbody>
        </table></div>
        <p class="lg-sub" style="margin-top: 8px">{{ $t('A payment counts only when its Payment Voucher is marked paid. A voucher still waiting, or one that was not paid, is listed but is not counted.') }}</p>
      </section>

      <!-- payment history: every voucher -->
      <section v-show="tab === 'payments'" class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f"><label>{{ $t('Payment status') }}</label><acct-select v-model="payStatus" :options="payStatuses" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="payCurrency" :options="currencyOptions" :placeholder="$t('All')" clearable /></div>
        </div>
        <div class="tr-bar" style="margin-top: 14px">
          <label class="tr-sbox"><icons name="search" /><input v-model="q.payments" type="search" :placeholder="$t('Search payments, vouchers, people')" autocomplete="off" /></label>
          <span class="tr-count">{{ $t(':n payment(s)', { n: paymentsShown.length }) }}</span>
          <travel-export type="payments" :params="{ contact_id: agent.id, currency: payCurrency, status_payment: payStatus, search: q.payments }" />
        </div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Payment') }}</th><th class="tr-nw">{{ $t('Asked on') }}</th><th class="tr-nw">{{ $t('Paid on') }}</th><th>{{ $t('Payment Voucher') }}</th><th class="tr-num">{{ $t('Rate agreed') }}</th><th class="tr-num">{{ $t('Amount') }}</th><th class="tr-num">{{ $t('Balance after') }}</th><th>{{ $t('Asked by') }}</th><th>{{ $t('Paid by') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(p, n) in paymentsShown" :key="p.id" class="is-click" @click="$inertia.visit(route('travels.agents.payments.show', p.id))">
              <td :data-label="$t('No')" class="tr-no">{{ n + 1 }}</td>
              <td :data-label="$t('Payment')"><div class="tr-cell"><b class="lg-main">{{ p.reference }}</b><div class="tr-line"><span class="lg-chip" :class="payChip(p.status)" style="text-transform: none">{{ p.status_label }}</span><small v-if="p.travel">{{ p.travel }}</small></div></div></td>
              <td :data-label="$t('Asked on')" class="tr-nw">{{ $date(p.requested_on) }}</td>
              <td :data-label="$t('Paid on')" class="tr-nw">{{ p.paid_on ? $date(p.paid_on) : '-' }}</td>
              <td :data-label="$t('Payment Voucher')" @click.stop><div class="tr-cell"><a v-if="p.voucher_id && can.vouchers" :href="route('payment-vouchers.show', p.voucher_id)" target="_blank" rel="noopener" class="lg-main">{{ p.pv_number }}</a><b v-else>{{ p.pv_number || '-' }}</b><small>{{ p.voucher_status || '' }}</small></div></td>
              <td :data-label="$t('Rate agreed')" class="tr-num">{{ p.rate || '-' }}</td>
              <td :data-label="$t('Amount')" class="tr-num"><b :class="p.status === 'Paid' ? 'tr-g' : 'tr-o'">{{ p.currency }} {{ money(p.amount) }}</b></td>
              <td :data-label="$t('Balance after')" class="tr-num"><b v-if="p.balance_after !== null && p.status === 'Paid'" class="tr-tm">{{ p.currency }} {{ money(p.balance_after) }}</b><span v-else>-</span></td>
              <td :data-label="$t('Asked by')">{{ p.by || '-' }}</td>
              <td :data-label="$t('Paid by')">{{ p.paid_by || '-' }}</td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop><span class="tr-acts"><Link :href="route('travels.agents.payments.show', p.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Details')" :aria-label="$t('Details')"><icons name="doc" class="w-4 h-4" /></Link></span></td>
            </tr>
            <tr v-if="!paymentsShown.length" class="lg-empty-row"><td class="lg-empty" colspan="11">{{ payments.length ? $t('Nothing matches.') : $t('No payment has been asked for yet. Use Make a payment to start a Payment Voucher.') }}</td></tr>
          </tbody>
        </table></div>
      </section>

      <!-- the travels they issued -->
      <section v-show="tab === 'travels'" class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="travelStatus" :options="travelStatuses" :placeholder="$t('All')" clearable /></div>
        </div>
        <div class="tr-bar" style="margin-top: 14px">
          <label class="tr-sbox"><icons name="search" /><input v-model="q.travels" type="search" :placeholder="$t('Search travels, travellers, destinations')" autocomplete="off" /></label>
          <span class="tr-count">{{ $t(':n travel(s)', { n: travelsShown.length }) }}</span>
          <travel-export type="register" :params="{ contact_id: agent.id, status: travelStatus, search: q.travels }" />
        </div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive tr-table">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Travel') }}</th><th>{{ $t('Traveller(s)') }}</th><th>{{ $t('To') }}</th><th class="tr-nw">{{ $t('Departs') }}</th><th class="tr-c">{{ $t('Cur.') }}</th><th class="tr-num">{{ $t('Rate agreed') }}</th><th class="tr-num">{{ $t('Ticket') }}</th><th class="tr-num">{{ $t('Visa') }}</th><th class="tr-num">{{ $t('Other costs') }}</th></tr></thead>
          <tbody>
            <tr v-for="(t, n) in travelsShown" :key="t.id" class="is-click" @click="$inertia.visit(route('travels.show', t.id))">
              <td :data-label="$t('No')" class="tr-no">{{ n + 1 }}</td>
              <td :data-label="$t('Travel')"><div class="tr-cell"><b class="lg-main">{{ t.reference }}</b><div class="tr-line"><span class="lg-chip" :class="statusChip(t.status)" style="text-transform: none">{{ t.status_label }}</span></div></div></td>
              <td :data-label="$t('Traveller(s)')" class="tr-wrap">{{ t.travellers }}</td>
              <td :data-label="$t('To')" class="tr-wrap"><div class="tr-cell"><b>{{ t.to_place }}</b><small v-if="t.to_country">{{ t.to_country }}</small></div></td>
              <td :data-label="$t('Departs')" class="tr-nw">{{ $date(t.depart_date) }}</td>
              <td :data-label="$t('Cur.')" class="tr-c">{{ t.currency }}</td>
              <td :data-label="$t('Rate agreed')" class="tr-num"><span v-if="t.currency !== 'TZS'" class="tr-o">{{ Number(t.rate).toLocaleString(undefined, { maximumFractionDigits: 6 }) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Ticket')" class="tr-num"><span v-if="Number(t.ticket)" class="tr-m">{{ money(t.ticket) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Visa')" class="tr-num"><span v-if="Number(t.visa)" class="tr-m">{{ money(t.visa) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Other costs')" class="tr-num"><template v-if="t.other.length"><div v-for="(o, k) in t.other" :key="k" class="tr-nw"><small>{{ o.label }}</small> <span class="tr-m">{{ o.currency }} {{ money(o.amount) }}</span></div></template><template v-else>-</template></td>
            </tr>
            <tr v-if="!travelsShown.length" class="lg-empty-row"><td class="lg-empty" colspan="10">{{ travels.length ? $t('Nothing matches.') : $t('No travel was issued by this agent yet.') }}</td></tr>
          </tbody>
        </table></div>
      </section>

      <!-- opening balances -->
      <section v-show="tab === 'opening'" class="lg-card">
        <p class="lg-sub" style="margin-bottom: 12px">{{ $t('What the agent was owed before Travels started, per currency. Travels booked before the date are already inside it and are not counted again.') }}</p>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive tr-table">
          <thead><tr><th class="tr-c">{{ $t('Currency') }}</th><th class="tr-num">{{ $t('Amount owed') }}</th><th class="tr-nw">{{ $t('As of') }}</th><th>{{ $t('Notes') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="o in openings" :key="o.id"><td :data-label="$t('Currency')" class="tr-c">{{ o.currency }}</td><td :data-label="$t('Amount owed')" class="tr-num"><b>{{ money(o.amount) }}</b></td><td :data-label="$t('As of')" class="tr-nw">{{ $date(o.as_of_date) }}</td><td :data-label="$t('Notes')" class="tr-wrap">{{ o.notes || '-' }}</td>
              <td class="lg-sticky-end tr-acts-cell"><span v-if="can.manage" class="tr-acts"><button type="button" class="lg-icon-btn" :aria-label="$t('Change')" @click="openOpening(o)"><icons name="edit" class="w-4 h-4" /></button><button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="dropping = o"><icons name="trash" class="w-4 h-4" /></button></span></td></tr>
            <tr v-if="!openings.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No opening balance. This agent starts at zero.') }}</td></tr>
          </tbody>
        </table></div>
      </section>
    </div>

    <!-- make a payment: starts a Payment Voucher -->
    <travel-modal :show="paying" :title="$t('Make a payment')" :subtitle="agent.name" icon="cash" max-width="lg" :busy="paying_busy" @close="paying = false">
      <p class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('This starts a Payment Voucher in the Payment Vouchers module. It waits there for approval and is marked paid there. The balance of the agent falls only when the voucher is paid.') }}</span></p>
      <div class="tm-grid">
        <div class="ac-f"><label>{{ $t('Currency') }} *</label><acct-select v-model="pay.currency" :options="currencyOptions" /></div>
        <div class="ac-f"><label>{{ $t('Amount') }} ({{ pay.currency }}) *</label><input v-model="pay.amount" inputmode="decimal" placeholder="0.00" /><span class="ac-err">{{ perr('amount') }}</span><small>{{ $t('Up to :a', { a: pay.currency + ' ' + money(availableOf(pay.currency)) }) }} · <a href="#" @click.prevent="pay.amount = String(availableOf(pay.currency))">{{ $t('use all') }}</a></small></div>
        <div v-if="pay.currency !== 'TZS'" class="ac-f"><label>{{ $t('Exchange rate (TZS per :c)', { c: pay.currency }) }} *</label><input v-model="pay.exchange_rate" inputmode="decimal" :placeholder="$t('e.g. 2600')" /><span class="ac-err">{{ perr('exchange_rate') }}</span><small v-if="rates[pay.currency]">{{ $t('Offered from this agent\'s travels (rate :r). You can change it; the voucher uses the rate you confirm.', { r: rates[pay.currency] }) }}</small></div>
        <div class="ac-f" :class="{ 'tm-full': pay.currency === 'TZS' }"><label>{{ $t('For a travel (optional)') }}</label><acct-select v-model="pay.travel_id" :options="travelOptions" :placeholder="$t('Not for one travel')" clearable /><span class="ac-err">{{ perr('travel_id') }}</span></div>
        <div class="ac-f tm-full"><label>{{ $t('Notes') }}</label><input v-model="pay.notes" :placeholder="$t('Shown on the voucher')" /></div>
      </div>
      <div class="tm-sum"><span>{{ pay.currency === 'TZS' ? $t('The voucher will be for') : $t('The voucher will be for (in TZS)') }}</span><b>TZS {{ money(voucherTotal) }}</b></div>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="paying_busy" @click="paying = false">{{ $t('Cancel') }}</button>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="paying_busy || !Number(pay.amount)" @click="requestPayment"><icons name="send" class="w-4 h-4" />{{ paying_busy ? $t('Starting...') : $t('Start the Payment Voucher') }}</button>
      </template>
    </travel-modal>

    <!-- opening balance -->
    <travel-modal :show="opening.open" :title="$t('Opening balance')" :subtitle="agent.name" icon="clock" tone="amber" max-width="md" :busy="opening.busy" @close="opening.open = false">
      <p class="lg-note lg-note-amber"><icons name="info" class="w-4 h-4" /><span>{{ $t('What the agent was owed before Travels started. Travels booked before the date below are already inside it.') }}</span></p>
      <div class="tm-grid">
        <div class="ac-f"><label>{{ $t('Currency') }} *</label><acct-select v-model="opening.currency" :options="currencyOptions" /></div>
        <div class="ac-f"><label>{{ $t('Amount owed to the agent') }} *</label><input v-model="opening.amount" inputmode="decimal" placeholder="0.00" /><span class="ac-err">{{ perr('amount') }}</span></div>
        <div class="ac-f tm-full"><label>{{ $t('As of') }} *</label><date-picker v-model="opening.as_of_date" :clearable="false" /><span class="ac-err">{{ perr('as_of_date') }}</span></div>
        <div class="ac-f tm-full"><label>{{ $t('Notes') }}</label><input v-model="opening.notes" :placeholder="$t('Where the figure comes from')" /></div>
      </div>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="opening.busy" @click="opening.open = false">{{ $t('Cancel') }}</button>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="opening.busy" @click="saveOpening"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
      </template>
    </travel-modal>

    <!-- remove an opening balance -->
    <travel-modal :show="!!dropping" :title="$t('Remove the opening balance?')" :subtitle="agent.name" icon="trash" tone="red" max-width="sm" @close="dropping = null">
      <p v-if="dropping" class="lg-note lg-note-red"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('The opening balance of :c :m (as of :d) is removed. What the agent is owed is then worked out from the travels and payments only.', { c: dropping.currency, m: money(dropping.amount), d: dt(dropping.as_of_date) }) }}</span></p>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" @click="dropping = null">{{ $t('Keep it') }}</button>
        <button type="button" class="lg-btn lg-btn-red" @click="removeOpening"><icons name="trash" class="w-4 h-4" />{{ $t('Remove') }}</button>
      </template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import TravelExport from '../TravelExport.vue';
import TravelModal from '../TravelModal.vue';
import ScTabs from '../../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

export default {
  components: { TravelExport, TravelModal, ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect },
  props: { agent: Object, rates: { type: Object, default: () => ({}) }, balances: Array, statement: Array, travels: Array, payments: Array, openings: Array, filters: Object, currencies: Array, today: String, can: Object },
  data() {
    return {
      tab: 'statement', f: { currency: this.filters.currency || null, from: this.filters.from || null, to: this.filters.to || null }, timer: null,
      q: { statement: '', payments: '', travels: '' }, payStatus: null, payCurrency: null, travelStatus: null,
      dropping: null, paying: false, paying_busy: false, pay: { currency: this.currencies[0] || 'TZS', amount: '', exchange_rate: '', travel_id: null, notes: '' },
      opening: { open: false, busy: false, currency: this.currencies[0] || 'TZS', amount: '', as_of_date: this.today, notes: '' },
    };
  },
  watch: {
    f: { deep: true, handler() { clearTimeout(this.timer); this.timer = setTimeout(this.reload, 350); } },
    // a payment in a foreign currency is offered the rate of the agent's travels (or of the chosen travel); the person may leave it or change it
    'pay.currency'(c) { this.pay.exchange_rate = c === 'TZS' ? '' : (this.rates[c] || ''); },
    'pay.travel_id'(id) { const t = id ? this.travels.find(x => x.id === id) : null; if (t && t.currency === this.pay.currency && t.currency !== 'TZS') this.pay.exchange_rate = String(Number(t.rate)); },
  },
  computed: {
    tabs() { return [{ key: 'statement', label: this.$t('Statement'), icon: 'list', tone: 'navy' }, { key: 'payments', label: this.$t('Payments'), icon: 'cash', tone: 'emerald', count: this.payments.length }, { key: 'travels', label: this.$t('Travels'), icon: 'plane', tone: 'sky', count: this.travels.length }, { key: 'opening', label: this.$t('Opening balance'), icon: 'clock', tone: 'amber' }]; },
    currencyOptions() { return [...new Set([...this.balances.map(b => b.currency), ...this.currencies])].map(c => ({ value: c, label: c })); },
    payStatuses() { return [{ value: 'Paid', label: this.$t('Paid') }, { value: 'Pending Voucher', label: this.$t('Pending Voucher') }, { value: 'Released', label: this.$t('Released') }]; },
    travelStatuses() { return [{ value: 'Approved', label: this.$t('Approved') }, { value: 'Pending Approval', label: this.$t('Pending Approval') }, { value: 'Rejected', label: this.$t('Rejected') }, { value: 'Cancelled', label: this.$t('Cancelled') }]; },
    travelOptions() { return this.travels.filter(t => t.status === 'Approved').map(t => ({ value: t.id, label: t.reference + ' - ' + t.to_place + ' (' + this.dt(t.depart_date) + ')' })); },
    voucherTotal() { const a = Number(this.pay.amount) || 0; return this.pay.currency === 'TZS' ? a : a * (Number(this.pay.exchange_rate) || 0); },
    statementShown() { return this.grep(this.statement, this.q.statement, l => [l.date, l.reference, l.text, l.currency, l.owed, l.paid, l.balance]); },
    paymentsShown() { return this.grep(this.payments.filter(p => (!this.payStatus || p.status === this.payStatus) && (!this.payCurrency || p.currency === this.payCurrency)), this.q.payments, p => [p.reference, p.pv_number, p.status_label, p.voucher_status, p.currency, p.amount, p.by, p.paid_by, p.travel, p.method, p.payment_reference, p.requested_on, p.paid_on]); },
    travelsShown() { return this.grep(this.travels.filter(t => !this.travelStatus || t.status === this.travelStatus), this.q.travels, t => [t.reference, t.travellers, t.to_place, t.to_country, t.status_label, t.currency, t.depart_date]); },
  },
  mounted() {
    const want = new URLSearchParams(window.location.search).get('pay');
    if (want && this.can.pay) this.$nextTick(() => this.openPay(want.toUpperCase()));
  },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    dt(v) { try { return this.$date(v); } catch (e) { return v; } },
    perr(k) { return (this.$page.props.errors || {})[k]; },
    // instant search: every word typed must be found somewhere in the row
    grep(rows, text, pick) { const w = (text || '').toLowerCase().split(/\s+/).filter(Boolean); if (!w.length) return rows; return rows.filter(r => { const hay = pick(r).filter(v => v !== null && v !== undefined).join(' ').toLowerCase(); return w.every(x => hay.includes(x)); }); },
    statusChip(s) { return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    payChip(s) { return { Paid: 'lg-chip-green', 'Pending Voucher': 'lg-chip-amber', Released: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    availableOf(c) { const b = this.balances.find(x => x.currency === c); return b ? Math.max(0, Number(b.available)) : 0; },
    reload() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('travels.agents.show', this.agent.id), q, { only: ['statement', 'filters'], preserveState: true, preserveScroll: true, replace: true }); },
    openPay(cur) { const c = typeof cur === 'string' ? cur : (this.balances.find(b => Number(b.available) > 0) || {}).currency || this.currencies[0] || 'TZS'; this.pay = { currency: c, amount: String(this.availableOf(c) || ''), exchange_rate: c === 'TZS' ? '' : (this.rates[c] || ''), travel_id: null, notes: '' }; this.paying = true; },
    requestPayment() {
      this.paying_busy = true;
      this.$inertia.post(route('travels.agents.payments.store', this.agent.id), { ...this.pay, travel_id: this.pay.travel_id || undefined }, { preserveScroll: true, onFinish: () => (this.paying_busy = false), onSuccess: () => (this.paying = false) });
    },
    openOpening(o) { this.opening = o ? { open: true, busy: false, currency: o.currency, amount: String(o.amount), as_of_date: o.as_of_date, notes: o.notes || '' } : { open: true, busy: false, currency: this.currencies[0] || 'TZS', amount: '', as_of_date: this.today, notes: '' }; },
    saveOpening() { this.opening.busy = true; this.$inertia.post(route('travels.agents.opening.store', this.agent.id), { currency: this.opening.currency, amount: this.opening.amount, as_of_date: this.opening.as_of_date, notes: this.opening.notes }, { preserveScroll: true, onFinish: () => (this.opening.busy = false), onSuccess: () => (this.opening.open = false) }); },
    removeOpening() { const o = this.dropping; this.$inertia.delete(route('travels.agents.opening.destroy', [this.agent.id, o.id]), { preserveScroll: true, onSuccess: () => (this.dropping = null) }); },
  },
};
</script>

<style scoped>
.ag-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); gap: 14px; margin-bottom: 16px; }
.ag-card { position: relative; display: grid; gap: 14px; padding: 18px 20px; border-radius: 22px; background: linear-gradient(135deg, rgba(14, 116, 144, 0.12), rgba(79, 70, 229, 0.07)), var(--sc-card, #fff); box-shadow: 0 0 0 1px var(--sc-line, #e3e9f2), 0 16px 26px -20px rgba(14, 116, 144, 0.75); }
.ag-cur { position: absolute; top: 16px; right: 18px; padding: 3px 11px; border-radius: 999px; background: #0e7490; color: #fff; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; }
.ag-big { display: grid; gap: 2px; } .ag-big small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--sc-mute, #64748b); } .ag-big b { font-size: 1.8rem; line-height: 1.15; font-variant-numeric: tabular-nums; } .ag-big em { font-style: normal; font-size: 0.74rem; font-weight: 700; color: #b45309; }
.ag-grid small { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase; color: var(--sc-mute, #64748b); }
.ag-pay { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 16px; border-radius: 14px; border: 0; background: linear-gradient(135deg, #0e7490, #155e75); color: #fff; font-size: 0.86rem; font-weight: 800; cursor: pointer; }
.ag-pay:hover { filter: brightness(1.08); }
</style>
