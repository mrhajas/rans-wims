<template>
  <admin-layout :title="t ? $t('Edit :r', { r: t.reference }) : $t('New Transfer')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="refresh" :title="t ? $t('Edit :r', { r: t.reference }) : $t('New Transfer')" :subtitle="$t('Move money from one of your accounts to another. Different currencies need an approved exchange rate.')">
        <Link :href="t ? route('acct.transfers.show', t.id) : route('acct.transfers.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>
      <form @submit.prevent="save(false)">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="refresh" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Transfer Details') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('From account') }} <b class="wims-req">*</b></label><acct-select v-model="form.from_account_id" :options="accounts.filter(a => a.value !== form.to_account_id)" :placeholder="$t('Choose...')" clearable /><span class="ac-err">{{ err('from_account_id') }}</span><small v-if="from" class="lg-subtext">{{ $t('Balance now') }}: {{ mc(from.balance, from.currency) }}</small></div>
            <div class="ac-f"><label>{{ $t('To account') }} <b class="wims-req">*</b></label><acct-select v-model="form.to_account_id" :options="accounts.filter(a => a.value !== form.from_account_id)" :placeholder="$t('Choose...')" clearable /><span class="ac-err">{{ err('to_account_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Amount sent') }} * <b v-if="from">({{ from.currency }})</b></label><input v-model="form.from_amount" inputmode="decimal" required /><span class="ac-err">{{ err('from_amount') }}</span></div>
            <div class="ac-f"><label>{{ $t('Transfer fee') }} <b v-if="from">({{ from.currency }})</b></label><input v-model="form.fee_amount" inputmode="decimal" placeholder="0" /><small class="lg-subtext">{{ $t('Charged to the sending account') }}</small><span class="ac-err">{{ err('fee_amount') }}</span></div>
            <div class="ac-f"><label>{{ $t('Date') }} <b class="wims-req">*</b></label><date-picker v-model="form.transfer_date" :max="today" :clearable="false" /></div>
          </div>
        </section>

        <section v-if="cross" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Currency Conversion') }}</h2><p class="lg-card-sub">{{ $t('From :a to :b. The rate must be approved; enter the amount that is actually credited and any difference is recorded as an exchange difference.', { a: from.currency, b: to.currency }) }}</p></div></header>
          <div v-if="!rateOptions.length" class="lg-note lg-note-amber">{{ $t('There is no approved exchange rate between :a and :b. A transfer cannot be saved without one.', { a: from.currency, b: to.currency }) }} <Link :href="route('acct.currencies.index')">{{ $t('Add a rate') }}</Link></div>
          <div class="lg-fields" v-else>
            <div class="ac-f"><label>{{ $t('Exchange rate') }} <b class="wims-req">*</b></label><acct-select v-model="form.rate_id" :options="(rateOptions).map(r => ({ value: r.id, label: '1 {{ r.base }} = {{ r.rate }} {{ r.quote }} ({{ r.type }}, {{ $t(\'from\') }} {{ r.from }})' }))" :placeholder="$t('Choose...')" clearable /><span class="ac-err">{{ err('rate_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Amount credited') }} * ({{ to.currency }})</label><input v-model="form.to_amount" inputmode="decimal" required /><span class="ac-err">{{ err('to_amount') }}</span><small v-if="expected" class="lg-subtext">{{ $t('Amount x rate') }} = {{ mc(expected, to.currency) }}<template v-if="difference"> - {{ $t('difference') }} {{ mc(difference, to.currency) }}</template></small></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Reason') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Reason for the transfer') }}</label><input v-model="form.reason" maxlength="255" /></div>
            <div class="ac-f"><label>{{ $t('Bank / external reference') }}</label><input v-model="form.external_ref" /></div>
            <div class="ac-f"><label>{{ $t('Project') }}</label><acct-select v-model="form.warehouse_id" :options="projects" :placeholder="'-'" clearable /></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Notes') }}</label><textarea v-model="form.notes"></textarea><span class="ac-err">{{ err('notes') }}</span></div>
          </div>
        </section>

        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ t ? t.reference : $t('Saved as a draft first') }}</span><div class="lg-actionbar-btns">
          <Link :href="t ? route('acct.transfers.show', t.id) : route('acct.transfers.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link>
          <button type="submit" class="lg-btn lg-btn-soft" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save draft') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="form.processing" @click="save(true)"><icons name="send" class="w-4 h-4" />{{ $t('Save and submit') }}</button></div></div>
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
import { acct, decDivRound, decIsZero, decMulRound, decRound, decSub } from '../acct';

export default {
  components: { DatePicker, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, t: Object, accounts: Array, rates: Array, allowInverse: Boolean, tolerance: [String, Number], idem: String, projects: Array, preset: Object, can: Object },
  data() {
    const t = this.t || {};
    return { today: new Date().toISOString().slice(0, 10), form: this.$inertia.form({
      from_account_id: t.from_account_id || (this.preset && this.preset.from) || null, to_account_id: t.to_account_id || null, from_amount: t.from_amount || '', to_amount: t.to_amount || '', fee_amount: t.fee_amount && Number(t.fee_amount) ? t.fee_amount : '', rate_id: t.rate_id || null,
      transfer_date: t.transfer_date || new Date().toISOString().slice(0, 10), reason: t.reason || '', external_ref: t.external_ref || '', warehouse_id: t.warehouse_id || null, notes: t.notes || '', idempotency_key: this.t ? undefined : this.idem, submit: false,
    }) };
  },
  computed: {
    from() { return this.accounts.find(a => a.value === this.form.from_account_id); },
    to() { return this.accounts.find(a => a.value === this.form.to_account_id); },
    cross() { return this.from && this.to && this.from.currency !== this.to.currency; },
    rateOptions() {
      if (!this.cross) return [];
      return this.rates.filter(r => (r.base === this.from.currency && r.quote === this.to.currency) || (this.allowInverse && r.base === this.to.currency && r.quote === this.from.currency));
    },
    chosen() { return this.rateOptions.find(r => r.id === this.form.rate_id); },
    // amount x rate, only to help the person: the server recalculates and enforces everything. Worked out on the digits (exact, half up), never in floating point.
    expected() {
      if (!this.chosen || !this.form.from_amount) return null;
      const p = this.cur[this.to.currency] ? this.cur[this.to.currency].precision : 2;
      const amt = String(this.form.from_amount).trim();
      return this.chosen.base === this.from.currency ? decMulRound(amt, this.chosen.rate, p) : decDivRound(amt, this.chosen.rate, p);
    },
    difference() {
      if (this.expected === null || this.form.to_amount === '') return null;
      const d = decSub(String(this.form.to_amount).trim(), this.expected);
      if (d === null || decIsZero(d)) return null;
      return decRound(d, this.cur[this.to.currency] ? this.cur[this.to.currency].precision : 2);
    },
  },
  watch: {
    cross(v) { if (!v) { this.form.rate_id = null; this.form.to_amount = ''; } },
    // money cannot be moved to the same account: choosing the other side's account empties this side
    'form.from_account_id'(v) { this.form.rate_id = null; if (v && v === this.form.to_account_id) this.form.to_account_id = null; },
    'form.to_account_id'(v) { this.form.rate_id = null; if (v && v === this.form.from_account_id) this.form.from_account_id = null; },
  },
  methods: {
    save(submit) {
      if (this.form.from_account_id && this.form.from_account_id === this.form.to_account_id) { this.form.setError('to_account_id', this.$t('The two accounts must be different.')); return; }
      this.form.submit = submit;
      if (this.t) this.form.put(route('acct.transfers.update', this.t.id), { preserveScroll: true });
      else this.form.post(route('acct.transfers.store'), { preserveScroll: true });
    },
  },
};
</script>
