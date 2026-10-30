<template>
  <admin-layout :title="kindNow === 'cash' ? $t('Count Cash') : $t('Reconcile Bank')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="kindNow === 'cash' ? 'in' : 'cash'" :title="kindNow === 'cash' ? $t('Count Cash') : $t('Reconcile Bank')" :subtitle="kindNow === 'cash' ? $t('Count the notes and coins physically in the box. Do it with a witness.') : $t('Enter the closing balance on the bank statement, then import or enter its lines to match them.')">
        <Link :href="route('acct.reconciliations.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>
      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Reconciliation Details') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Type') }}</label><acct-select v-model="form.kind" :options="[{ value: 'cash', label: $t('Cash count') }, { value: 'bank', label: $t('Bank statement') }]" /></div>
            <div class="ac-f"><label>{{ $t('Account') }} <b class="wims-req">*</b></label><acct-select v-model="form.account_id" :options="options" :placeholder="$t('Choose...')" clearable /><span class="ac-err">{{ err('account_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('As of (end of day)') }} <b class="wims-req">*</b></label><date-picker v-model="form.period_to" :max="today" :clearable="false" /><span class="ac-err">{{ err('period_to') }}</span></div>
            <div class="ac-f" v-if="form.kind === 'bank'"><label>{{ $t('Statement from') }}</label><date-picker v-model="form.period_from" /></div>
            <div class="ac-f"><label>{{ $t('WIMS says the balance is') }}</label><input :value="expected === null ? '...' : mc(expected, currency)" readonly /></div>
          </div>
        </section>

        <section v-if="form.kind === 'cash'" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Cash Count by Denomination') }}</h2><p class="lg-card-sub">{{ $t('Enter how many of each. The total is worked out for you; or enter only the total below.') }}</p></div></header>
          <div class="ac-bills">
            <label v-for="d in faces" :key="d">{{ currency }} {{ d.toLocaleString() }}<input v-model.number="counts[d]" type="number" min="0" inputmode="numeric" placeholder="0" /></label>
          </div>
          <div class="lg-fields" style="margin-top: 12px">
            <div class="ac-f"><label>{{ $t('Total counted') }} <b class="wims-req">*</b></label><input v-model="form.counted_balance" inputmode="decimal" required /><span class="ac-err">{{ err('counted_balance') }}</span><small v-if="denomTotal !== null" class="lg-subtext">{{ $t('Notes and coins add up to') }} {{ mc(denomTotal, currency) }}</small></div>
            <div class="ac-f"><label>{{ $t('Witness (name)') }}</label><input v-model="form.witness_name" /></div>
          </div>
        </section>
        <section v-else class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Bank Statement Balance') }}</h2></div></header>
          <div class="lg-fields"><div class="ac-f"><label>{{ $t('Closing balance on the statement') }} <b class="wims-req">*</b></label><input v-model="form.counted_balance" inputmode="decimal" required /><span class="ac-err">{{ err('counted_balance') }}</span></div></div>
        </section>

        <section class="lg-card" v-if="diff !== null && Number(diff) !== 0">
          <div class="lg-note" :class="Math.abs(Number(diff)) > 0 ? 'lg-note-amber' : ''"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('This is :d :c :w than WIMS says.', { d: mc(abs(diff), currency), c: '', w: Number(diff) > 0 ? $t('more') : $t('less') }) }} {{ $t('Say why below. Nothing is changed in the balance: a correcting entry is proposed only after approval.') }}</span></div>
          <div class="ac-f" style="margin-top: 10px"><label>{{ $t('Explanation of the difference') }}</label><textarea v-model="form.explanation"></textarea></div>
        </section>

        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Saved as a draft; you submit it when ready') }}</span><div class="lg-actionbar-btns"><Link :href="route('acct.reconciliations.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></div></div>
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
import { acct, decAdd, decMul, decRound, decSub, isDec } from '../acct';

export default {
  components: { DatePicker, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, accounts: Array, account: Object, kind: String, denominations: Object, today: String },
  data() {
    return { counts: {}, expected: null, form: this.$inertia.form({ account_id: this.account ? this.account.id : null, kind: this.kind, period_from: '', period_to: this.today, counted_balance: '', denominations: {}, witness_name: '', explanation: '' }) };
  },
  computed: {
    kindNow() { return this.form.kind; },
    options() { return this.accounts.filter(a => (this.form.kind === 'bank') === (a.kind === 'bank_account')); },
    currency() { const a = this.accounts.find(x => x.value === this.form.account_id); return a ? a.currency : 'TZS'; },
    faces() { return (this.denominations[this.currency] || []).slice().sort((a, b) => b - a); },
    // the total of notes and coins, added up on the digits (exact): a double would drift on large counts and odd face values
    denomTotal() {
      const e = Object.entries(this.counts).filter(([, n]) => Number(n) > 0);
      if (!e.length) return null;
      let sum = '0';
      for (const [d, n] of e) sum = decAdd(sum, decMul(d, String(Math.floor(Number(n))))) ?? sum;
      return sum;
    },
    diff() { if (this.expected === null || this.form.counted_balance === '' || !isDec(String(this.form.counted_balance).trim())) return null; return decRound(decSub(String(this.form.counted_balance).trim(), this.expected), 4); },
  },
  watch: {
    denomTotal(v) { if (v !== null && this.form.kind === 'cash') this.form.counted_balance = v; },
    'form.account_id'() { this.loadExpected(); }, 'form.period_to'() { this.loadExpected(); }, 'form.kind'() { this.form.account_id = this.options.find(a => a.value === this.form.account_id) ? this.form.account_id : null; },
    currency() { this.counts = {}; },
  },
  mounted() { this.loadExpected(); },
  methods: {
    abs(v) { return String(v).replace('-', ''); },
    async loadExpected() {
      this.expected = null;
      if (!this.form.account_id) return;
      try { const r = await window.axios.get(route('acct.reconciliations.expected'), { params: { account_id: this.form.account_id, date: this.form.period_to } }); this.expected = r.data.expected; } catch (e) { this.expected = null; }
    },
    submit() {
      this.form.transform(d => ({ ...d, denominations: d.kind === 'cash' && this.denomTotal !== null ? Object.fromEntries(Object.entries(this.counts).filter(([, n]) => Number(n) > 0)) : undefined, period_from: d.period_from || undefined })).post(route('acct.reconciliations.store'));
    },
  },
};
</script>
