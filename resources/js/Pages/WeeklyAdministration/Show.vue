<template>
  <admin-layout :title="d.reference">
    <div class="lg-page ac sc-ui tr-ui wa-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="d.reference" :subtitle="d.department + ' - ' + d.hq + ' - ' + $date(d.doc_date) + ' - ' + (d.beneficiary || '')">
        <Link :href="route('weekly-administration.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <a v-if="can.print" :href="route('weekly-administration.print', d.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
        <a v-if="can.print" :href="route('weekly-administration.pdf', d.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
        <button v-if="can.send" type="button" class="lg-btn lg-btn-soft" @click="sendOpen = true"><icons name="send" class="w-4 h-4" /><span>{{ $t('Send') }}</span></button>
        <button v-if="can.duplicate" type="button" class="lg-btn lg-btn-soft" @click="dupOpen = true"><icons name="duplicate" class="w-4 h-4" /><span>{{ $t('Duplicate') }}</span></button>
        <Link v-if="can.edit" :href="route('weekly-administration.edit', d.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
      </page-header>

      <section class="lg-card">
        <div class="wa-stages">
          <div v-for="s in stages" :key="s.key" class="wa-stage" :class="s.cls">
            <span class="wa-node"><icons :name="s.icon" class="w-5 h-5" /></span>
            <b>{{ s.label }}</b>
            <small>{{ s.who || (s.cls === 'is-now' ? $t('Waiting') : '-') }}</small>
            <em v-if="s.at">{{ s.at }}</em>
          </div>
        </div>
        <div class="ac-actions" style="justify-content: flex-end; margin-top: 8px">
          <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="ask('approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
          <button v-if="can.authorize" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="ask('authorize')"><icons name="check-double" class="w-4 h-4" />{{ $t('Authorize') }}</button>
          <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
          <button v-if="can.cancel" type="button" class="lg-btn t-red" @click="ask('cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel it') }}</button>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="send">
          <input v-model="reason" :placeholder="placeholder" :required="asking === 'reject' || asking === 'cancel'" maxlength="1000" />
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button>
          <button type="button" class="lg-btn t-slate" @click="asking = ''">{{ $t('Not now') }}</button>
        </form>
        <div v-if="d.status === 'Requested'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Waiting for approval (stage 2 of 3).') }}</span></div>
        <div v-if="d.status === 'Approved'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Approved. Waiting for authorization (stage 3 of 3).') }}</span></div>
        <div v-if="d.status === 'Rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}<template v-if="d.rejected_by"> {{ $t('by') }} {{ d.rejected_by }}</template>: {{ d.rejection_reason }}</div>
        <div v-if="d.status === 'Cancelled'" class="lg-note lg-note-amber" style="margin-top: 10px">{{ $t('Cancelled') }}: {{ d.cancel_reason }}</div>
        <p v-if="d.approval_remarks" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Approval remarks') }}:</b> {{ d.approval_remarks }}</p>
        <p v-if="d.authorization_remarks" class="lg-sub"><b>{{ $t('Authorization remarks') }}:</b> {{ d.authorization_remarks }}</p>
      </section>

      <section class="lg-card">
        <div class="wa-meta">
          <div><small>{{ $t('Headquarters') }}</small><b>{{ d.hq }}</b></div>
          <div><small>{{ $t('Department') }}</small><b>{{ d.department }}</b></div>
          <div><small>{{ $t('Date') }}</small><b>{{ $date(d.doc_date) }}</b></div>
          <div><small>{{ $t('Requested by') }}</small><b>{{ d.beneficiary || '-' }}</b></div>
          <div><small>{{ $t('Prepared by') }}</small><b>{{ d.by || '-' }}</b></div>
          <div v-if="d.duplicated_from"><small>{{ $t('Duplicated from') }}</small><b><Link :href="route('weekly-administration.show', d.duplicated_from.id)">{{ d.duplicated_from.reference }}</Link></b></div>
          <div v-if="d.sent_count"><small>{{ $t('Sent by email') }}</small><b>{{ d.sent_count }} {{ $t('time(s)') }}</b></div>
        </div>
        <p v-if="d.notes" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Notes') }}:</b> {{ d.notes }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Administration') }}</h2></div></header>
        <div class="wa-paper"><table>
          <thead><tr><th class="no">{{ $t('No') }}</th><th>{{ $t('Description') }}</th><th class="hide-sm">{{ $t('Beneficiary') }}</th><th class="num">{{ $t('Amount') }} ({{ d.currency }})</th></tr></thead>
          <tbody>
            <tr v-for="l in d.lines" :key="l.no"><td class="no">{{ l.no }}</td><td>{{ l.description }}<div v-if="l.category || l.person" class="lg-subtext"><span v-if="l.category">{{ l.category }}</span><span v-if="l.person"> · {{ l.person }}</span></div></td><td class="hide-sm">{{ l.beneficiary || '-' }}</td><td class="num tr-m">{{ money(l.amount) }}</td></tr>
            <tr v-if="!d.lines.length"><td colspan="4" class="lg-empty" style="text-align: center">{{ $t('No lines.') }}</td></tr>
          </tbody>
          <tfoot><tr><td colspan="2" style="text-align: right">{{ $t('Total') }} {{ d.currency }}</td><td class="hide-sm"></td><td class="num tr-tm">{{ money(d.items_total) }}</td></tr></tfoot>
        </table></div>
      </section>

      <section v-if="d.include_charity" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="heart" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Charity') }}</h2></div></header>
        <div class="wa-paper is-charity"><table>
          <thead><tr><th class="no">{{ $t('No') }}</th><th>{{ $t('Charity') }}</th><th class="num">{{ $t('Amount') }} ({{ d.currency }})</th></tr></thead>
          <tbody>
            <tr v-for="c in d.charity_lines" :key="c.no"><td class="no">{{ c.no }}</td><td>{{ c.description }}</td><td class="num tr-m">{{ money(c.amount) }}<div v-if="Number(c.monthly_amount) && Number(c.monthly_amount) !== Number(c.amount)" class="lg-subtext">{{ $t('monthly') }} {{ money(c.monthly_amount) }}</div></td></tr>
            <tr v-if="!d.charity_lines.length"><td colspan="3" class="lg-empty" style="text-align: center">{{ $t('No charity lines.') }}</td></tr>
          </tbody>
          <tfoot><tr><td colspan="2" style="text-align: right">{{ $t('Total') }} {{ d.currency }}</td><td class="num tr-tm">{{ money(d.charity_total) }}</td></tr></tfoot>
        </table></div>
      </section>

      <div class="wa-grand" style="margin-bottom: 12px"><span>{{ d.include_charity ? $t('Total of the document with the charity') : $t('Total of the document') }}</span><b>{{ d.currency }} {{ money(d.total_amount) }}</b></div>

      <!-- payment -->
      <section v-if="pay" ref="payBox" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Payment') }} <span class="lg-chip" :class="payChip(d.payment_status)" style="text-transform: none; margin-left: 6px">{{ $t(d.payment_status || 'Unpaid') }}</span></h2><p class="lg-card-sub">{{ $t('Mark the document paid to a user or staff member, all at once, line by line or part of a line, or make a Payment Voucher for it.') }}</p></div></header>
        <div class="wa-prog">
          <div class="wa-bar-track"><span class="wa-bar-held" :style="{ width: pct(Number(d.paid_total) + Number(d.reserved_total)) + '%' }"></span><span class="wa-bar-paid" :style="{ width: pct(d.paid_total) + '%' }"></span></div>
          <div class="wa-prog-nums"><span>{{ $t('Paid') }} <b class="tr-g">{{ d.currency }} {{ money(d.paid_total) }}</b></span><span v-if="Number(d.reserved_total)">{{ $t('Waiting on a voucher') }} <b class="tr-o">{{ money(d.reserved_total) }}</b></span><span>{{ $t('Balance') }} <b class="tr-m">{{ d.currency }} {{ money(Math.max(0, Number(d.total_amount) - Number(d.paid_total))) }}</b></span><span>{{ $t('Total') }} <b>{{ money(d.total_amount) }}</b></span></div>
        </div>
        <div class="wa-paper wa-paytable"><table>
          <thead><tr><th v-if="canPay" class="chk"><input type="checkbox" :checked="allChosen" :disabled="!openRows.length" :aria-label="$t('Choose all')" @change="chooseAll($event.target.checked)" /></th><th class="no">{{ $t('No') }}</th><th>{{ $t('Description') }}</th><th class="num hide-sm">{{ $t('Amount') }}</th><th class="num hide-sm">{{ $t('Paid') }}</th><th class="num">{{ $t('Balance') }}</th><th>{{ $t('Status') }}</th><th v-if="canPay" class="num">{{ $t('Pay now') }}</th><th v-if="canPay"></th></tr></thead>
          <tbody>
            <template v-for="(r, i) in pay.lines" :key="r.key">
              <tr v-if="i === 0 || pay.lines[i - 1].section !== r.section" class="sec"><td :colspan="canPay ? 9 : 6">{{ r.section === 'Charity' ? $t('Charity') : $t('Administration') }}</td></tr>
              <tr :class="{ 'is-sel': chosen[r.key], 'is-done': Number(r.balance) <= 0 }">
                <td v-if="canPay" class="chk"><input type="checkbox" :checked="!!chosen[r.key]" :disabled="Number(r.balance) <= 0" :aria-label="$t('Choose this line')" @change="choose(r, $event.target.checked)" /></td>
                <td class="no">{{ r.no }}</td>
                <td>{{ r.description }}<div v-if="r.beneficiary" class="lg-subtext">{{ r.beneficiary }}</div></td>
                <td class="num hide-sm">{{ money(r.amount) }}</td>
                <td class="num hide-sm tr-g">{{ Number(r.paid) ? money(r.paid) : '-' }}</td>
                <td class="num tr-m">{{ money(r.balance) }}<div v-if="Number(r.reserved)" class="lg-subtext tr-o">{{ $t('held') }} {{ money(r.reserved) }}</div></td>
                <td><span class="lg-chip" :class="payChip(r.status)" style="text-transform: none">{{ $t(r.status) }}</span><div v-if="r.vouchers && r.vouchers.length" class="wa-vrefs"><a v-for="v in r.vouchers" :key="v.id" :href="route('payment-vouchers.show', v.id)" target="_blank" rel="noopener" class="wa-vref" :title="$t('Open this voucher in a new tab') + ' (' + v.status + ')'"><icons name="doc-text" class="w-3 h-3" />{{ v.pv_number }}</a></div></td>
                <td v-if="canPay" class="num"><input v-if="chosen[r.key]" v-model="amounts[r.key]" class="wa-pin" type="number" min="0" step="0.01" :max="r.balance" inputmode="decimal" :aria-label="$t('Amount to pay now')" /></td>
                <td v-if="canPay" class="num"><button v-if="Number(r.balance) > 0" type="button" class="lg-btn t-slate sc-sm" @click="payOne(r)">{{ $t('Pay') }}</button></td>
              </tr>
            </template>
          </tbody>
        </table></div>
        <div v-if="canPay && floatPay" class="wa-payacts-gap" :style="{ height: barH + 'px' }" aria-hidden="true"></div>
        <div v-if="canPay" ref="payBar" class="wa-payacts" :class="{ 'is-float': floatPay }">
          <!-- the total of the chosen lines, centered above the buttons (nothing chosen, no total) -->
          <div v-if="selectedRows.length" class="wa-paytotal" role="status"><span>{{ $t(':n line(s) chosen', { n: selectedRows.length }) }}</span><b>{{ $t('Total') }} {{ d.currency }} {{ money(selectedTotal) }}</b></div>
          <div class="wa-payline">
          <span v-if="!selectedRows.length" class="wa-seltxt">{{ $t('Tick the lines to pay, or pay everything left.') }}</span>
          <span v-if="!selectedRows.length" class="grow"></span>
          <button v-if="can.pay" type="button" class="lg-btn lg-btn-green" :disabled="!selectedRows.length" @click="open(false, 'lines')"><icons name="tick" class="w-4 h-4" />{{ $t('Mark chosen paid') }}</button>
          <button v-if="can.pay" type="button" class="lg-btn lg-btn-primary" :disabled="!openRows.length" @click="open(false, 'all')"><icons name="check-double" class="w-4 h-4" />{{ $t('Mark all paid') }}</button>
          <button v-if="can.voucher" type="button" class="lg-btn lg-btn-soft" :disabled="!selectedRows.length" @click="open(true, 'lines')"><icons name="doc-text" class="w-4 h-4" />{{ $t('Voucher for chosen') }}</button>
          <button v-if="can.voucher" type="button" class="lg-btn lg-btn-soft" :disabled="!openRows.length" @click="open(true, 'all')"><icons name="doc-text" class="w-4 h-4" />{{ $t('Voucher for all left') }}</button>
          <button v-if="can.voucher" type="button" class="lg-btn lg-btn-primary" :disabled="!openRows.length" @click="openBulk(selectedRows.length ? 'lines' : 'all')"><icons name="collection" class="w-4 h-4" />{{ selectedRows.length ? $t('Vouchers per beneficiary (chosen)') : $t('Vouchers per beneficiary') }}</button>
        </div>
        </div>
        <template v-if="pay.payments.length">
          <h3 class="lg-sub" style="margin: 16px 0 8px; font-weight: 800">{{ $t('Payments') }}</h3>
          <div class="wa-payhist">
            <div v-for="p in pay.payments" :key="p.id" class="wa-payrow" :class="{ 'is-off': p.status === 'Released' || p.status === 'Reversed' }">
              <div class="who"><b>{{ p.reference }} <span class="lg-chip" :class="payStatusChip(p.status)" style="text-transform: none">{{ $t(p.status) }}</span></b>
                <small>{{ $t('to') }} <b>{{ p.to }}</b> ({{ p.to_type === 'staff' ? $t('staff') : $t('user') }}) · {{ p.paid_on ? $date(p.paid_on) : $date(p.requested_on) }} · {{ p.lines }} {{ $t('line(s)') }}<template v-if="p.method"> · {{ p.method }}</template><template v-if="p.payment_reference"> · {{ p.payment_reference }}</template> · {{ $t('by') }} {{ p.by }}</small>
                <small v-if="p.voucher">{{ $t('Payment Voucher') }}: <a :href="route('payment-vouchers.show', p.voucher.id)" target="_blank" rel="noopener">{{ p.voucher.pv_number }}</a> ({{ p.voucher.status }})<template v-if="p.batch"> · <a :href="route('payment-vouchers.batches.show', p.batch)" target="_blank" rel="noopener">{{ $t('Batch') }}</a></template></small>
                <small v-if="p.distribute"><span class="lg-chip lg-chip-violet" style="text-transform: none">{{ $t('Gives it to the beneficiaries') }}</span></small>
                <small v-if="p.notes">{{ p.notes }}</small><small v-if="p.release_reason">{{ $t('Released') }}: {{ p.release_reason }}</small><small v-if="p.reverse_reason">{{ $t('Reversed') }}: {{ p.reverse_reason }}</small></div>
              <div class="amt">{{ d.currency }} {{ money(p.amount) }}<div v-if="p.voucher"><a :href="route('payment-vouchers.show', p.voucher.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft sc-sm wa-pvbtn" :title="$t('Open this voucher in a new tab') + ' (' + p.voucher.status + ')'"><icons name="doc-text" class="w-4 h-4" />{{ $t('Payment Voucher') }}</a></div><div v-if="can.reverse && p.can_reverse"><button type="button" class="lg-btn t-red sc-sm" @click="reversing = p; why = ''">{{ $t('Reverse') }}</button></div></div>
            </div>
          </div>
        </template>
        <bulk-voucher-dialog :show="bulkOpen" :doc="d" :mode="bulkMode" :rows="openRows" :amounts="chosenAmounts" @close="bulkOpen = false; chosen = {}; amounts = {}" />
        <pay-dialog :show="payOpen" :doc="d" :voucher="payVoucher" :mode="payMode" :lines="payLines" :total="payTotal" :count="payCount" :payees="pay.payees" :methods="pay.methods" :default-payee="pay.default_payee" :today="today" @close="payOpen = false; chosen = {}; amounts = {}" />
        <travel-modal :show="!!reversing" :title="$t('Reverse this payment?')" :subtitle="reversing ? reversing.reference + ' - ' + d.currency + ' ' + money(reversing.amount) : ''" icon="refresh" tone="amber" :busy="busy" @close="reversing = null">
          <div class="ac-f"><label>{{ $t('Why is it reversed? (required)') }}</label><input v-model="why" maxlength="1000" /><span class="ac-err">{{ ($page.props.errors || {}).reason }}</span></div>
          <template #footer><button type="button" class="lg-btn t-slate" @click="reversing = null">{{ $t('Cancel') }}</button><button type="button" class="lg-btn lg-btn-red" :disabled="busy || !why.trim()" @click="reverse"><icons name="refresh" class="w-4 h-4" />{{ $t('Reverse') }}</button></template>
        </travel-modal>
      </section>

      <section v-if="sends.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="send" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Sent by email') }}</h2></div></header>
        <ul class="wa-act"><li v-for="s in sends" :key="s.id"><small>{{ s.at }}</small><span><b>{{ s.by }}</b> {{ $t('to') }} {{ s.recipients.join(', ') }} <span class="lg-chip" :class="s.status === 'Sent' ? 'lg-chip-green' : 'lg-chip-red'" style="text-transform: none">{{ s.status }}</span><small v-if="s.error"> {{ s.error }}</small></span></li></ul>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="wa-act"><li v-for="a in activity" :key="a.id"><small>{{ a.at }}</small><span>{{ a.text }}<template v-if="a.by"> - <b>{{ a.by }}</b></template></span></li><li v-if="!activity.length"><span class="lg-sub">{{ $t('Nothing yet.') }}</span></li></ul>
      </section>

      <send-dialog :show="sendOpen" :doc="d" @close="sendOpen = false" />
      <duplicate-dialog :show="dupOpen" :doc="d" @close="dupOpen = false" />
    </div>
  </admin-layout>
</template>

<script>
import SendDialog from './SendDialog.vue';
import DuplicateDialog from './DuplicateDialog.vue';
import PayDialog from './PayDialog.vue';
import BulkVoucherDialog from './BulkVoucherDialog.vue';
import TravelModal from '../Travels/TravelModal.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { SendDialog, DuplicateDialog, PayDialog, BulkVoucherDialog, TravelModal, AdminLayout, PageHeader, Link },
  props: { d: Object, sends: Array, activity: Array, can: Object, users: Array, pay: Object },
  mounted() { this.onScroll = () => { if (this.tick) return; this.tick = requestAnimationFrame(() => { this.tick = 0; this.placePayBar(); }); }; document.addEventListener('scroll', this.onScroll, true); window.addEventListener('resize', this.onScroll); this.$nextTick(this.placePayBar); },
  beforeUnmount() { document.removeEventListener('scroll', this.onScroll, true); window.removeEventListener('resize', this.onScroll); if (this.tick) cancelAnimationFrame(this.tick); },
  watch: {
    // the bar grows when the total line appears: keep the space it leaves in the page in step
    'selectedRows.length'() { this.$nextTick(() => { if (this.floatPay && this.$refs.payBar) this.barH = this.$refs.payBar.offsetHeight + 12; }); },
  },
  data() { return { asking: '', reason: '', busy: false, sendOpen: false, dupOpen: false, chosen: {}, amounts: {}, payOpen: false, bulkOpen: false, bulkMode: 'all', payVoucher: false, payMode: 'all', floatPay: false, barH: 0, reversing: null, why: '', today: new Date(new Date().getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10) }; },
  computed: {
    canPay() { return !!(this.can.pay || this.can.voucher); },
    openRows() { return this.pay ? this.pay.lines.filter(r => Number(r.balance) > 0) : []; },
    allChosen() { return this.openRows.length > 0 && this.openRows.every(r => this.chosen[r.key]); },
    selectedRows() { return this.openRows.filter(r => this.chosen[r.key]); },
    selectedTotal() { return this.selectedRows.reduce((s, r) => s + Math.round((parseFloat(this.amounts[r.key]) || 0) * 100), 0) / 100; },
    chosenAmounts() { const o = {}; this.selectedRows.forEach(r => { o[r.key] = this.amounts[r.key]; }); return o; },
    payLines() { return this.payMode === 'all' ? [] : this.selectedRows.map(r => ({ key: r.key, amount: this.amounts[r.key] })); },
    payCount() { return this.payMode === 'all' ? this.openRows.length : this.selectedRows.length; },
    payTotal() { return this.payMode === 'all' ? this.openRows.reduce((s, r) => s + Math.round(parseFloat(r.balance) * 100), 0) / 100 : this.selectedTotal; },
    stages() {
      const d = this.d; const order = ['Requested', 'Approved', 'Authorized']; const at = order.indexOf(d.status);
      const dead = d.status === 'Rejected' || d.status === 'Cancelled';
      const mk = (key, label, icon, who, when, i) => ({ key, label, icon, who, at: when, cls: dead ? (i === 0 ? 'is-done' : '') : (i <= at ? 'is-done' : i === at + 1 ? 'is-now' : '') }); // a stage the document has reached is done; the next one is the one it waits for
      const s = [mk('Requested', this.$t('Requested'), 'doc-text', d.beneficiary, this.$date(d.doc_date), 0), mk('Approved', this.$t('Approved'), 'tick', d.approved_by, d.approved_at, 1), mk('Authorized', this.$t('Authorized'), 'check-double', d.authorized_by, d.authorized_at, 2)];
      if (dead) { const i = d.status === 'Rejected' ? (d.approved_by ? 2 : 1) : 1; s[i].cls = 'is-bad'; s[i].who = d.status === 'Rejected' ? this.$t('Rejected') : this.$t('Cancelled'); s[i].at = d.status === 'Rejected' ? d.rejected_by : ''; s[i].icon = 'cross'; }
      if (d.status === 'Authorized') s[2].cls = 'is-done';
      return s;
    },
    placeholder() { return { reject: this.$t('Why is it rejected? (required)'), cancel: this.$t('Why is it cancelled? (required)'), approve: this.$t('Remarks (optional)'), authorize: this.$t('Remarks (optional)') }[this.asking]; },
  },
  methods: {
    pct(v) { const t = Number(this.d.total_amount) || 0; return t ? Math.min(100, Math.round((Number(v) / t) * 10000) / 100) : 0; },
    payChip(s) { return { Paid: 'lg-chip-green', 'Partially Paid': 'lg-chip-amber', 'Pending Voucher': 'lg-chip-blue', Unpaid: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    payStatusChip(s) { return { Paid: 'lg-chip-green', 'Pending Voucher': 'lg-chip-blue', Released: 'lg-chip-slate', Reversed: 'lg-chip-red' }[s] || 'lg-chip-slate'; },
    choose(r, on) { this.chosen = { ...this.chosen, [r.key]: on }; if (on && !this.amounts[r.key]) this.amounts = { ...this.amounts, [r.key]: r.balance }; },
    chooseAll(on) { const c = {}; const a = { ...this.amounts }; this.openRows.forEach(r => { c[r.key] = on; if (on && !a[r.key]) a[r.key] = r.balance; }); this.chosen = c; this.amounts = a; },
    payOne(r) { this.chosen = { [r.key]: true }; this.amounts = { ...this.amounts, [r.key]: this.amounts[r.key] || r.balance }; this.open(false, 'lines'); },
    openBulk(mode) { this.bulkMode = mode; this.bulkOpen = true; },
    // the payment buttons float at the bottom of the screen from the moment the Payment section is reached while scrolling down, until it is scrolled back above (or past)
    placePayBar() {
      const box = this.$refs.payBox; const bar = this.$refs.payBar;
      if (!box || !bar) { this.floatPay = false; return; }
      const r = box.getBoundingClientRect(); const vh = window.innerHeight || document.documentElement.clientHeight;
      const on = r.top < vh - 90 && r.bottom > 140;
      if (on && !this.floatPay) this.barH = bar.offsetHeight + 12;
      this.floatPay = on;
    },
    open(voucher, mode) { this.payVoucher = voucher; this.payMode = mode; this.payOpen = true; },
    reverse() { this.busy = true; this.$inertia.post(route('weekly-administration.payments.reverse', this.reversing.id), { reason: this.why }, { preserveScroll: true, onSuccess: () => { this.reversing = null; }, onFinish: () => (this.busy = false) }); },
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    ask(a) { this.asking = a; this.reason = ''; },
    send() {
      const a = this.asking; this.busy = true;
      const body = a === 'reject' || a === 'cancel' ? { reason: this.reason } : { remarks: this.reason, seen: this.d.content_hash };
      this.$inertia.post(route('weekly-administration.' + a, this.d.id), body, { preserveScroll: true, onSuccess: () => { this.asking = ''; }, onFinish: () => (this.busy = false) });
    },
  },
};
</script>
