<template>
  <travel-modal :show="show" :title="voucher ? $t('Make a Payment Voucher') : $t('Mark as Paid')" :subtitle="doc ? doc.reference + ' - ' + doc.currency + ' ' + money(total) : ''" :icon="voucher ? 'doc-text' : 'cash'" :tone="voucher ? 'amber' : undefined" :busy="busy" max-width="xl" @close="$emit('close')">
    <form id="wa-pay" class="wa-ui" @submit.prevent="go">
      <div class="wa-paysum">
        <span><small>{{ $t('Lines') }}</small><b>{{ count }}</b></span>
        <span><small>{{ $t('Amount') }}</small><b class="tr-tm">{{ doc ? doc.currency : '' }} {{ money(total) }}</b></span>
        <span v-if="mode === 'all'"><small>{{ $t('What it covers') }}</small><b>{{ $t('Everything left on the document') }}</b></span>
        <span v-else><small>{{ $t('What it covers') }}</small><b>{{ count === 1 ? $t('One line') : $t('The chosen lines') }}</b></span>
      </div>
      <div class="tr-fbar" style="margin-top: 12px">
        <div class="ac-f tr-wide"><label>{{ $t('Who is paid') }} <b class="wims-req">*</b></label><acct-select v-model="form.payee" :options="payees" :placeholder="$t('Search a user or a staff member...')" /><span class="ac-err">{{ err('payee') }}</span></div>
        <template v-if="!voucher">
          <div class="ac-f"><label>{{ $t('Date paid') }}</label><date-picker v-model="form.paid_on" /><span class="ac-err">{{ err('paid_on') }}</span></div>
          <div class="ac-f"><label>{{ $t('Method') }}</label><input v-model="form.method" list="wa-methods" maxlength="100" :placeholder="$t('Cash, bank transfer...')" /></div>
          <div class="ac-f tr-wide"><label>{{ $t('Reference') }}</label><input v-model="form.payment_reference" maxlength="190" :placeholder="$t('Receipt, transfer or cheque number (optional)')" /></div>
          <datalist id="wa-methods"><option v-for="m in methods" :key="m" :value="m"></option></datalist>
        </template>
        <label v-if="mode === 'all'" class="wa-toggle tr-wide" style="grid-column: 1 / -1"><input v-model="form.distribute" type="checkbox" /><span><b>{{ $t('This person gives it to the beneficiaries') }}</b><small>{{ $t('The whole amount is paid to this one person, who distributes it offline to the beneficiaries of the document. It is recorded as paid to them.') }}</small></span></label>
        <div class="ac-f tr-wide"><label>{{ $t('Notes') }}</label><input v-model="form.notes" maxlength="1000" :placeholder="$t('Optional')" /></div>
      </div>
      <span class="ac-err">{{ err('lines') }}</span>
      <p v-if="voucher" class="wa-note"><icons name="info" class="w-4 h-4" />{{ $t('A Draft Payment Voucher is made for this amount. Nothing counts as paid until the voucher is approved, authorized and marked paid in the Payment Vouchers module. Until then the amount is held for it.') }}</p>
      <p v-else class="wa-note"><icons name="info" class="w-4 h-4" />{{ $t('This is recorded at once, with your name and the date. A part payment leaves the rest of the line to be paid later. You can reverse it with a reason if it was a mistake.') }}</p>
    </form>
    <template #footer>
      <button type="button" class="lg-btn t-slate" :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</button>
      <button type="submit" form="wa-pay" class="lg-btn lg-btn-primary" :disabled="busy || !form.payee || !count"><icons :name="voucher ? 'doc-text' : 'tick'" class="w-4 h-4" />{{ busy ? $t('Working...') : (voucher ? $t('Make the voucher') : $t('Mark paid')) }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '../Travels/TravelModal.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

export default {
  components: { TravelModal, DatePicker, AcctSelect },
  props: { show: Boolean, doc: Object, voucher: Boolean, mode: String, lines: { type: Array, default: () => [] }, total: Number, count: Number, payees: { type: Array, default: () => [] }, methods: { type: Array, default: () => [] }, defaultPayee: String, today: String },
  emits: ['close'],
  data() { return { busy: false, form: { payee: '', paid_on: '', method: '', payment_reference: '', notes: '', distribute: false } }; },
  watch: { show(v) { if (v) this.form = { payee: this.defaultPayee || '', paid_on: this.today, method: '', payment_reference: '', notes: '', distribute: false }; } },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    go() {
      this.busy = true;
      const body = { mode: this.mode, payee: this.form.payee, notes: this.form.notes, lines: this.mode === 'lines' ? this.lines : [], distribute: this.mode === 'all' && this.form.distribute ? 1 : 0 };
      if (!this.voucher) Object.assign(body, { paid_on: this.form.paid_on, method: this.form.method, payment_reference: this.form.payment_reference });
      this.$inertia.post(route(this.voucher ? 'weekly-administration.payments.voucher' : 'weekly-administration.payments.store', this.doc.id), body, { preserveScroll: true, onSuccess: () => this.$emit('close'), onFinish: () => (this.busy = false) });
    },
  },
};
</script>
