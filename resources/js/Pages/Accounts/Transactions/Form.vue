<template>
  <admin-layout :title="pageTitle">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="form.direction === 'in' ? 'in' : 'out'" :title="pageTitle" :subtitle="form.direction === 'in' ? $t('Money coming into one of your accounts.') : $t('Money going out of one of your accounts.')">
        <Link :href="t ? route('acct.transactions.show', t.id) : route('acct.transactions.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <form @submit.prevent="save(false)">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico" :class="form.direction === 'in' ? 'lg-ico-emerald' : 'lg-ico-rose'"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ detailsTitle }}</h2><p class="lg-card-sub">{{ $t('Account, amount, date, category and payment method') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Type') }}</label><acct-select v-model="form.direction" :options="[...(can.inflow || can.adjust ? [{ value: 'in', label: $t('Money in') }] : []), ...(can.outflow || can.adjust ? [{ value: 'out', label: $t('Money out') }] : [])]" :disabled="!!t" @change="onDir" /></div>
            <div class="ac-f" v-if="can.adjust"><label>{{ $t('Kind') }}</label><acct-select v-model="form.kind" :options="[{ value: form.direction === 'in' ? 'receipt' : 'payment', label: form.direction === 'in' ? $t('Receipt') : $t('Payment') }, { value: 'adjustment', label: $t('Adjustment (needs a reason)') }]" :disabled="!!t" /></div>
            <div class="ac-f"><label>{{ $t('Account') }} *</label><acct-select v-model="form.account_id" :options="accounts" :placeholder="$t('Choose...')" clearable /><span class="ac-err">{{ err('account_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Amount') }} * <b v-if="account">({{ account.currency }})</b></label><input v-model="form.amount" inputmode="decimal" required placeholder="0" /><span class="ac-err">{{ err('amount') }}</span><small v-if="account && form.direction === 'out' && !account.allow_negative" class="lg-subtext">{{ $t('Balance now') }}: {{ mc(account.balance, account.currency) }}</small></div>
            <div class="ac-f"><label>{{ $t('Date') }} *</label><date-picker v-model="form.txn_date" :max="today" :clearable="false" /><span class="ac-err">{{ err('txn_date') }}</span></div>
            <div class="ac-f"><label>{{ $t('Time') }}</label><input v-model="form.txn_time" type="time" /></div>
            <div class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="form.category_id" :options="catOptions" :placeholder="'-'" clearable /></div>
            <div class="ac-f"><label>{{ $t('Payment method') }}</label><acct-select v-model="form.payment_method" :options="methods" :placeholder="'-'" clearable /></div>
          </div>
          <div v-if="form.payment_method === 'cheque'" class="lg-fields" style="margin-top: 10px"><div class="ac-f"><label>{{ $t('Cheque number') }} *</label><input v-model="form.method_details.cheque_number" /></div><div class="ac-f"><label>{{ $t('Cheque date') }} *</label><date-picker v-model="form.method_details.cheque_date" /></div></div>
          <div v-if="form.payment_method === 'bank_transfer'" class="lg-fields" style="margin-top: 10px"><div class="ac-f"><label>{{ $t('Bank reference') }} *</label><input v-model="form.method_details.bank_reference" /><small class="lg-subtext">{{ $t('Or fill the external reference below.') }}</small></div></div>
          <div v-if="form.payment_method === 'mobile_money'" class="lg-fields" style="margin-top: 10px"><div class="ac-f"><label>{{ $t('Mobile money reference') }} *</label><input v-model="form.method_details.mobile_reference" /><small class="lg-subtext">{{ $t('Or fill the external reference below.') }}</small></div><div class="ac-f"><label>{{ $t('Phone number') }}</label><input v-model="form.method_details.phone" /></div></div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Description and References') }}</h2><p class="lg-card-sub">{{ $t('Purpose, counterparty and document numbers') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Description') }} *</label><input v-model="form.description" required maxlength="255" /><span class="ac-err">{{ err('description') }}</span></div>
            <div class="ac-f"><label>{{ form.direction === 'in' ? $t('Received from') : $t('Paid to') }}</label><input v-model="form.counterparty_name" /></div>
            <div class="ac-f"><label>{{ $t('Receipt number') }}</label><input v-model="form.receipt_no" /></div>
            <div class="ac-f"><label>{{ $t('Invoice number') }}</label><input v-model="form.invoice_no" /></div>
            <div class="ac-f"><label>{{ $t('Voucher number') }}</label><input v-model="form.voucher_no" /></div>
            <div class="ac-f"><label>{{ $t('External reference') }}</label><input v-model="form.external_ref" /></div>
            <div class="ac-f"><label>{{ $t('Project') }}</label><acct-select v-model="form.warehouse_id" :options="projects" :placeholder="'-'" clearable /></div>
            <div class="ac-f"><label>{{ $t('Department') }}</label><input v-model="form.department" /></div>
            <div class="ac-f"><label>{{ $t('Cost centre') }}</label><input v-model="form.cost_centre" /></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Reason / notes') }} <b v-if="form.kind === 'adjustment'">*</b></label><textarea v-model="form.reason" :required="form.kind === 'adjustment'"></textarea><span class="ac-err">{{ err('reason') }}</span></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Supporting Documents') }}</h2><p class="lg-card-sub">{{ $t('Receipts, invoices, vouchers or bank confirmations. PDF, images and office files up to 8 MB. Large payments cannot be submitted without one.') }}</p></div></header>
          <acct-file-list v-if="attachments.length" :files="attachments" :download-url="f => route('acct.transactions.download', [t.id, f.id])" :doc-types="doc_types" style="margin-bottom: 10px" />
          <div class="lg-fields" style="margin-bottom: 10px"><div class="ac-f"><label>{{ $t('Document type') }}</label><acct-select v-model="form.doc_type" :options="doc_types" /></div></div>
          <acct-dropzone v-model="form.files" :hint="$t('Receipts, invoices, vouchers or bank confirmations. PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ err('files') || err('files.0') }}</span>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ account ? account.label : $t('Choose an account') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="t ? route('acct.transactions.show', t.id) : route('acct.transactions.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-soft" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save draft') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="form.processing" @click="save(true)"><icons name="send" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save and submit') }}</button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '../AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctDropzone from '../AcctDropzone.vue';
import AcctFileList from '../AcctFileList.vue';
import { acct } from '../acct';

export default {
  components: { AcctDropzone, AcctFileList, DatePicker, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, t: Object, direction: String, kind: String, accounts: Array, categories: Array, methods: Array, projects: Array, doc_types: Array, preset: Object, idem: String, can: Object, attachments: Array },
  data() {
    const t = this.t || {};
    return { today: new Date().toISOString().slice(0, 10), form: this.$inertia.form({
      direction: this.direction, kind: this.kind === 'adjustment' ? 'adjustment' : (this.direction === 'in' ? 'receipt' : 'payment'), account_id: t.account_id || this.preset.account_id || null, amount: t.amount || '', txn_date: t.txn_date || new Date().toISOString().slice(0, 10), txn_time: t.txn_time ? String(t.txn_time).slice(0, 5) : '',
      category_id: t.category_id || null, payment_method: t.payment_method || null, method_details: { ...(t.method_details || {}) }, description: t.description || '', counterparty_name: t.counterparty_name || '', receipt_no: t.receipt_no || '', invoice_no: t.invoice_no || '', voucher_no: t.voucher_no || '', external_ref: t.external_ref || '',
      warehouse_id: t.warehouse_id || null, department: t.department || '', cost_centre: t.cost_centre || '', reason: t.reason || '', notes: t.notes || '', fund_allocation_id: t.fund_allocation_id || this.preset.fund_allocation_id || null, doc_type: 'receipt', files: [], idempotency_key: this.t ? undefined : this.idem, submit: false, post: false,
    }) };
  },
  computed: {
    detailsTitle() { return this.form.kind === 'adjustment' ? this.$t('Adjustment Details') : this.form.direction === 'in' ? this.$t('Receipt Details') : this.$t('Payment Details'); },
    pageTitle() { return this.t ? this.$t('Edit :r', { r: this.t.reference }) : this.form.direction === 'in' ? this.$t('Record Money In') : this.$t('Record Money Out'); },
    account() { const a = this.accounts.find(x => x.value === this.form.account_id); return a ? { ...a, allow_negative: false } : null; },
    catOptions() { return this.categories.filter(c => c.direction === 'both' || c.direction === this.form.direction); },
  },
  methods: {
    onDir() { this.form.kind = this.form.direction === 'in' ? 'receipt' : 'payment'; this.form.category_id = null; },
    save(submit) {
      this.form.submit = submit;
      this.form.post = false;
      const opts = { forceFormData: true, preserveScroll: true };
      if (this.t) this.form.transform(d => ({ ...d, _method: 'put' })).post(route('acct.transactions.update', this.t.id), opts);
      else this.form.post(route('acct.transactions.store'), opts);
    },
  },
};
</script>
