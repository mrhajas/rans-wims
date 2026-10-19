<template>
  <admin-layout :title="r.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="send" :title="r.reference" :subtitle="r.contact + ' - ' + r.agreement">
        <Link :href="route('subcontracts.requests.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Requests') }}</span></Link>
        <Link :href="route('subcontracts.agreements.show', r.agreement_id)" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ r.agreement }}</span></Link>
        <Link v-if="can.edit" :href="route('subcontracts.requests.edit', r.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
              <export-buttons type="request_detail" :params="{ request_id: r.id }" :sheets="false" needs-money :money="money" />
      </page-header>

      <div v-if="balance_note" class="lg-note lg-note-red" role="alert" style="margin-bottom: 10px"><icons name="exclamation" class="w-4 h-4" /><span>{{ balance_note }}</span></div>
      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div>
            <span class="lg-chip" :class="chip(r.status)">{{ r.status_label }}</span> <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ r.kind_label }}</span> <span v-if="r.override" class="lg-chip lg-chip-red" style="text-transform: none">{{ $t('Approved with an override') }}</span>
            <div v-if="money" class="ac-cur-total" style="margin-top: 6px">{{ mc(r.requested, r.currency) }} <small class="lg-subtext">{{ $t('requested') }}<template v-if="r.approved !== null"> · {{ $t('approved') }} {{ m(r.approved) }}</template></small></div>
          </div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="doit('submit')"><icons name="send" class="w-4 h-4" />{{ $t('Submit') }}</button>
            <button v-if="can.review" type="button" class="lg-btn lg-btn-soft t-teal" @click="doit('review')"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Take into review') }}</button>
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="approving = !approving"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.return" type="button" class="lg-btn lg-btn-soft t-amber" @click="ask('return', $t('What must be corrected? (required)'))"><icons name="refresh" class="w-4 h-4" />{{ $t('Return for correction') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject', $t('Why is it rejected? (required)'))"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.cancel" type="button" class="lg-btn lg-btn-soft t-slate" @click="ask('cancel', $t('Why is it cancelled? (required)'))"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel it') }}</button>
            <button v-if="can.delete" type="button" class="lg-btn lg-btn-soft t-red" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Delete draft') }}</button>
          </div>
        </div>
        <form v-if="approving" class="sc-approve" @submit.prevent="approve">
          <div class="ac-filters"><div v-if="money" class="ac-f"><label>{{ $t('Amount to approve') }} ({{ r.currency }})</label><input v-model="ap.approved_amount" inputmode="decimal" /><span class="ac-err">{{ err('approved_amount') }}</span></div>
            <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Remarks') }}</label><input v-model="ap.reason" /></div>
            <div v-if="can.override" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Override reason (only if this goes above the remaining contract value)') }}</label><input v-model="ap.override_reason" :placeholder="$t('Required to approve above the remaining value')" /><small class="lg-subtext">{{ $t('An override is recorded in the audit log and alerts the administrators.') }}</small></div></div>
          <div class="ac-actions"><button type="submit" class="lg-btn lg-btn-green" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="approving = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
        </form>
        <form v-if="asking" class="ac-reason" @submit.prevent="send"><input v-model="reason" :placeholder="askLabel" required /><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="asking = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="r.decision_remarks && ['Returned', 'Rejected', 'Cancelled'].includes(r.status)" class="lg-note lg-note-amber" style="margin-top: 10px">{{ $t(r.status) }}: {{ r.decision_remarks }}</div>
        <div v-if="r.override" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Override') }}: {{ r.override_reason }}</div>
        <div v-if="r.status === 'Approved'" class="lg-note lg-note-blue" style="margin-top: 10px"><icons name="info" class="w-4 h-4" /><span>{{ $t('Approved is not paid. Start a Payment Voucher below; the money counts as paid only when that voucher is authorised and marked paid.') }}</span></div>
      </section>

      <section v-if="figures" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('This request') }} ({{ r.currency }})</h2></div></header>
        <div class="sc-figs"><div class="sc-fig"><small>{{ $t('Approved') }}</small><b class="amt-appr">{{ m(figures.approved) }}</b></div><div class="sc-fig is-good"><small>{{ $t('Paid (vouchers paid)') }}</small><b class="amt-paid">{{ m(figures.paid) }}</b></div><div class="sc-fig"><small>{{ $t('In a voucher, not paid yet') }}</small><b class="amt-appr">{{ m(figures.in_process) }}</b></div><div class="sc-fig is-warn"><small>{{ $t('Still to start') }}</small><b class="amt-out">{{ m(figures.left) }}</b></div></div>
        <div v-if="agreement_figures" class="sc-figs" style="margin-top: 10px"><div v-for="t in agFigs" :key="t.k" class="sc-fig is-link" role="link" tabindex="0" :title="$t('Open the agreement')" @click="openAgreement" @keydown.enter="openAgreement"><small>{{ t.label }}</small><b :class="t.b">{{ m(t.v) }}</b></div></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Payment Vouchers') }}</h2><p class="lg-card-sub">{{ $t('Each payment is a voucher of the existing Payment Vouchers module, with its own approvals.') }}</p></div>
          <button v-if="can.voucher" type="button" class="lg-btn lg-btn-primary" @click="starting = !starting"><icons name="plus" class="w-4 h-4" />{{ $t('Start Payment Voucher') }}</button></header>
        <form v-if="starting && can.voucher" class="sc-approve" @submit.prevent="startVoucher">
          <div class="ac-filters"><div class="ac-f"><label>{{ $t('Amount to pay now') }} ({{ r.currency }})</label><input v-model="vf.amount" inputmode="decimal" /><span class="ac-err">{{ err('amount') }}</span><small class="lg-subtext">{{ $t('At most :l (what is left on this request).', { l: figures ? m(figures.left) : '' }) }}</small></div>
            <template v-if="!currency_is_tzs"><div class="ac-f"><label>{{ $t('Exchange rate to TZS') }} *</label><input v-model="vf.exchange_rate" inputmode="decimal" /><span class="ac-err">{{ err('exchange_rate') }}</span></div><div class="ac-f"><label>{{ $t('Rate date') }} *</label><date-picker v-model="vf.rate_date" /></div></template></div>
          <div class="ac-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="vf.processing"><icons name="send" class="w-4 h-4" />{{ $t('Continue to the voucher') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="starting = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
        </form>
        <div v-if="payments.length > 6" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Find a voucher by number or state...')" :count="payRows.length" /></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th><span class="sc-th"><icons name="doc-text" class="w-4 h-4" />{{ $t('Voucher') }}</span></th><th>{{ $t('State') }}</th><th v-if="money" class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Amount') }}</span></th><th v-if="money && !currency_is_tzs" class="ac-num"><span class="sc-th amt-appr"><icons name="cash" class="w-4 h-4" />{{ $t('Voucher (TZS)') }}</span></th><th><span class="sc-th"><icons name="calendar" class="w-4 h-4" />{{ $t('Paid on') }}</span></th><th></th></tr></thead>
          <tbody><tr v-for="p in payRows" :key="p.id" :class="{ 'is-click': canOpen(p) }" :tabindex="canOpen(p) ? 0 : null" @click="openPay(p)" @keydown.enter="openPay(p)"><td :data-label="$t('Voucher')"><Link v-if="canOpen(p)" :href="route('payment-vouchers.show', p.voucher_id)" class="lg-main" @click.stop>{{ p.voucher }}</Link><span v-else>{{ p.voucher || '-' }}</span></td>
            <td :data-label="$t('State')"><span class="lg-chip" :class="chip(p.voucher_status)">{{ p.voucher_status }}</span></td><td v-if="money" :data-label="$t('Amount')" class="ac-num"><span :class="p.voucher_status === 'Paid' ? 'amt-paid' : 'amt-appr'">{{ mc(p.amount, p.currency) }}</span></td><td v-if="money && !currency_is_tzs" :data-label="$t('Voucher (TZS)')" class="ac-num"><span class="amt-appr">{{ m(p.amount_tzs, 'TZS') }}</span> <small class="lg-subtext">@ {{ p.rate }}</small></td><td :data-label="$t('Paid on')">{{ p.paid_at ? $date(p.paid_at) : '-' }}</td>
            <td class="lg-sticky-end" @click.stop><template v-if="p.open"><a v-if="can.voucher" :href="route('payment-vouchers.create', { subcontract_payment: p.id })" class="lg-btn lg-btn-soft t-green"><icons name="send" class="w-4 h-4" />{{ $t('Continue') }}</a> <button v-if="can.voucher" type="button" class="lg-btn lg-btn-soft t-red" @click="cancelPay(p)"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></template></td></tr>
            <tr v-if="!payments.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('No voucher has been started.') }}</td></tr>
            <tr v-else-if="!payRows.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv"><div><dt>{{ $t('Agreement') }}</dt><dd><Link :href="route('subcontracts.agreements.show', r.agreement_id)">{{ r.agreement }}</Link> - {{ r.agreement_title }}</dd></div><div><dt>{{ $t('Subcontractor') }}</dt><dd><Link :href="route('subcontracts.register.show', r.contact_id)">{{ r.contact }}</Link></dd></div><div><dt>{{ $t('Project(s) / Warehouse(s)') }}</dt><dd>{{ r.project || '-' }}</dd></div><div><dt>{{ $t('Request date') }}</dt><dd>{{ r.date ? $date(r.date) : '-' }}</dd></div><div><dt>{{ $t('Requested by') }}</dt><dd>{{ r.by || '-' }}</dd></div><div v-if="r.approved_at"><dt>{{ $t('Approved on') }}</dt><dd>{{ r.approved_at }}</dd></div><div v-if="r.claim"><dt>{{ $t('Contract Payment Request') }}</dt><dd><Link v-if="r.claim.url" :href="r.claim.url">{{ r.claim.reference }}</Link><template v-else>{{ r.claim.reference }}</template> <span class="lg-chip" :class="chip(r.claim.status)">{{ $t(r.claim.status) }}</span></dd></div><custom-view :defs="fields.custom" :values="custom" /></dl>
        <p v-if="r.description" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Description') }}:</b> {{ r.description }}</p>
        <div v-if="items.length" class="lg-table-wrap" style="margin-top: 10px"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Work item') }}</th><th>{{ $t('Note') }}</th><th v-if="money" class="ac-num"><span class="sc-th amt-appr"><icons name="cash" class="w-4 h-4" />{{ $t('Amount') }}</span></th></tr></thead><tbody><tr v-for="i in items" :key="i.id"><td :data-label="$t('Work item')">{{ i.work || '-' }}</td><td :data-label="$t('Note')">{{ i.description || '' }}</td><td v-if="money" :data-label="$t('Amount')" class="ac-num"><span class="amt-appr">{{ m(i.amount) }}</span></td></tr></tbody></table></div>
      </section>

      <section v-if="can.documents" class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Supporting documents') }}</h2></div></header>
        <acct-file-list :files="attachments" :download-url="f => route('subcontracts.requests.download', [r.id, f.id])" :removable="['Draft', 'Returned'].includes(r.status)" @remove="removeFile" />
        <form v-if="can.attach" class="ac-attach" @submit.prevent="upload"><acct-dropzone v-model="up.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" /><div><button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.attachments.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ $t('Attach :n file(s)', { n: up.attachments.length }) }}</button></div></form></section>
      <section v-if="history.length" class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="sc-list"><li v-for="(h, i) in history" :key="i"><b>{{ h.description }}</b> <small>{{ h.causer }} · {{ String(h.created_at).slice(0, 16) }}</small></li></ul></section>
    </div>
  </admin-layout>
</template>

<script>
import ExportButtons from '../ExportButtons.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import CustomView from '../CustomView.vue';
import SearchBar from '../SearchBar.vue';
import { sc, tableFind } from '../sc';

export default {
  components: { SearchBar, CustomView, ExportButtons, AdminLayout, PageHeader, Link, DatePicker, AcctDropzone, AcctFileList },
  mixins: [sc, tableFind],
  props: { balance_note: String, fields: Object, custom: Object, r: Object, items: Array, figures: Object, agreement_figures: Object, payments: Array, attachments: Array, history: Array, money: Boolean, can: Object, settings: Object, currency_is_tzs: Boolean, today: String },
  data() {
    return { asking: '', reason: '', busy: false, approving: false, starting: false, ap: { approved_amount: this.r.requested, reason: '', override_reason: '' }, up: this.$inertia.form({ attachments: [] }), vf: this.$inertia.form({ amount: this.figures ? this.figures.left : '', exchange_rate: '', rate_date: this.today }) };
  },
  computed: {
    payRows() { return this.payments.filter(p => this.has(p.voucher, p.voucher_status)); },
    agFigs() { const f = this.agreement_figures; return [{ k: 'r', label: this.$t('Revised contract value'), v: f.revised, b: 'amt-rev' }, { k: 'p', label: this.$t('Agreement paid so far'), v: f.paid_total, b: 'amt-paid' }, { k: 'h', label: this.$t('Still open for approval'), v: f.headroom, b: this.neg(f.headroom) ? 'amt-neg' : 'amt-out' }]; },
    askLabel() { return { return: this.$t('What must be corrected? (required)'), reject: this.$t('Why is it rejected? (required)'), cancel: this.$t('Why is it cancelled? (required)') }[this.asking] || ''; },
  },
  methods: {
    openAgreement() { this.$inertia.visit(route('subcontracts.agreements.show', this.r.agreement_id)); },
    canOpen(p) { return !!(p.voucher_id && this.can.vouchers); },
    openPay(p) { if (this.canOpen(p)) this.$inertia.visit(route('payment-vouchers.show', p.voucher_id)); },
    doit(action, extra) { this.busy = true; this.$inertia.post(route('subcontracts.requests.transition', [this.r.id, action]), extra || {}, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    ask(a) { this.asking = a; this.reason = ''; }, send() { const a = this.asking; this.asking = ''; this.doit(a, { reason: this.reason }); },
    approve() { this.doit('approve', { approved_amount: this.ap.approved_amount, reason: this.ap.reason, override_reason: this.ap.override_reason }); },
    remove() { this.$confirm(this.$t('Delete this draft request?')).then(ok => { if (ok) this.$inertia.delete(route('subcontracts.requests.destroy', this.r.id)); }); },
    startVoucher() { this.vf.post(route('subcontracts.requests.voucher', this.r.id), { preserveScroll: true }); },
    cancelPay(p) { this.$confirm(this.$t('Cancel this started payment?')).then(ok => { if (ok) this.$inertia.post(route('subcontracts.requests.payments.cancel', [this.r.id, p.id]), {}, { preserveScroll: true }); }); },
    upload() { this.up.post(route('subcontracts.requests.attach', this.r.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('attachments') }); },
    removeFile(f) { this.$inertia.delete(route('subcontracts.requests.remove-file', [this.r.id, f.id]), { preserveScroll: true }); },
  },
};
</script>

<style scoped>
.sc-figs { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
.sc-fig { padding: 12px 14px; border-radius: 16px; background: var(--lg-card); box-shadow: 0 12px 20px -16px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); display: grid; gap: 2px; min-width: 0; }
.sc-fig small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); } .sc-fig b { font-size: 1.15rem; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.sc-approve { padding: 12px; border-radius: 16px; background: rgba(148, 163, 184, 0.08); box-shadow: inset 0 0 0 1px var(--lg-line); margin: 10px 0; }
.sc-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; } .sc-list li { font-size: 0.86rem; } .sc-list small { color: var(--lg-mute); margin-left: 6px; }
</style>
