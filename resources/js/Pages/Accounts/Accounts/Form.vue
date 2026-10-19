<template>
  <admin-layout :title="account ? $t('Edit Account') : $t('New Account')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cash" :title="account ? $t('Edit :n', { n: account.name }) : $t('New Account')" :subtitle="$t('Bank accounts, cash in hand and other accounts all work the same way: one currency per account, a balance built from posted transactions.')">
        <Link :href="account ? route('acct.accounts.show', account.id) : route('acct.accounts.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>
      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Account Details') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Name') }} *</label><input v-model="form.name" required :disabled="isLinked" /><span class="ac-err">{{ err('name') }}</span></div>
            <div class="ac-f"><label>{{ $t('Type') }} *</label><acct-select v-model="form.kind" :options="kinds" :disabled="locked" /><span class="ac-err">{{ err('kind') }}</span></div>
            <div class="ac-f"><label>{{ $t('Currency') }} *</label><acct-select v-model="form.currency" :options="curOptionsActive" :disabled="locked || isLinked" /><span class="ac-err">{{ err('currency') }}</span><small v-if="locked" class="lg-subtext">{{ $t('Type and currency cannot change once the account has transactions.') }}</small></div>
            <div class="ac-f"><label>{{ $t('Code') }}</label><input v-model="form.code" :placeholder="$t('Made automatically')" /><span class="ac-err">{{ err('code') }}</span></div>
            <div class="ac-f"><label>{{ $t('Custodian') }}</label><acct-select v-model="form.custodian_user_id" :options="users" :placeholder="'-'" clearable /></div>
            <div class="ac-f"><label>{{ $t('Project / warehouse') }}</label><acct-select v-model="form.warehouse_id" :options="projects" :placeholder="'-'" clearable /></div>
            <div class="ac-f"><label>{{ $t('Department') }}</label><input v-model="form.department" /></div>
            <div class="ac-f"><label>{{ $t('Location') }}</label><input v-model="form.location" :placeholder="$t('Safe, office, site ...')" /></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Description') }}</label><textarea v-model="form.description"></textarea></div>
          </div>
        </section>

        <section v-if="isBank" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="key" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Bank Details') }}</h2><p v-if="isLinked" class="lg-card-sub">{{ $t('The name, currency and account number come from Funds Allocation settings > Accounts. Change them there.') }}</p><p v-else class="lg-card-sub">{{ $t('The account number is stored encrypted and shown masked unless you hold the sensitive-details permission.') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Bank') }}</label><input v-model="form.institution" /></div>
            <div class="ac-f"><label>{{ $t('Branch') }}</label><input v-model="form.branch" /></div>
            <div class="ac-f"><label>{{ $t('Account holder') }}</label><input v-model="form.account_holder" /></div>
            <div class="ac-f"><label>{{ $t('Account number') }}</label><input v-model="form.account_number" :disabled="isLinked || (!can.sensitive && !!account)" autocomplete="off" :placeholder="!can.sensitive && account ? $t('Hidden') : ''" /><span class="ac-err">{{ err('account_number') }}</span></div>
            <div class="ac-f"><label>SWIFT / BIC</label><input v-model="form.swift" :disabled="!can.sensitive && !!account" /></div>
            <div class="ac-f"><label>IBAN</label><input v-model="form.iban" :disabled="!can.sensitive && !!account" /></div>
            <div class="ac-f"><label>{{ $t('Legal entity') }}</label><input v-model="form.legal_entity" /></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Opening Balance and Limits') }}</h2><p class="lg-card-sub">{{ $t('The opening balance is the starting point. After the first transaction it can no longer be changed: corrections are made with an adjustment.') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Opening balance') }}</label><input v-model="form.opening_balance" inputmode="decimal" :disabled="locked" /><span class="ac-err">{{ err('opening_balance') }}</span></div>
            <div class="ac-f"><label>{{ $t('Opening date') }}</label><date-picker v-model="form.opening_date" :disabled="locked" /></div>
            <div class="ac-f"><label>{{ $t('Alert when below') }}</label><input v-model="form.min_balance" inputmode="decimal" /><span class="ac-err">{{ err('min_balance') }}</span></div>
            <div class="ac-f"><label>{{ $t('Alert when above') }}</label><input v-model="form.max_balance" inputmode="decimal" :placeholder="isBank ? '' : $t('Cash holding limit')" /></div>
            <div class="ac-f"><label>{{ $t('Limit for one transaction') }}</label><input v-model="form.txn_limit" inputmode="decimal" /></div>
            <div class="ac-f"><label>{{ $t('Reconcile every (days)') }}</label><input v-model="form.reconcile_every_days" type="number" min="1" max="365" /></div>
            <label class="ac-check"><input v-model="form.allow_negative" type="checkbox" />{{ $t('Allow a negative balance (overdraft)') }}</label>
            <label class="ac-check"><input v-model="form.requires_approval" type="checkbox" />{{ $t('Every transaction on this account needs approval') }}</label>
            <label class="ac-check"><input v-model="form.is_primary" type="checkbox" />{{ $t('Main account') }}</label>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Access and Notifications') }}</h2><p class="lg-card-sub">{{ $t('People with the "view all accounts" permission see every account. Others see only accounts they look after or are named here.') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Also allowed to see this account') }}</label><acct-multi v-model="form.allowed_user_ids" :options="users" /></div>
            <div class="ac-f"><label>{{ $t('Told about low balance and differences') }}</label><acct-multi v-model="form.alert_user_ids" :options="users" /></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Notes') }}</label><textarea v-model="form.notes"></textarea></div>
          </div>
        </section>

        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ account ? account.code : $t('A code is made when you save') }}</span><div class="lg-actionbar-btns"><Link :href="account ? route('acct.accounts.show', account.id) : route('acct.accounts.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save') }}</button></div></div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '../AcctSelect.vue';
import AcctMulti from '../AcctMulti.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import { acct } from '../acct';

export default {
  components: { DatePicker, AcctMulti, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, account: Object, kind: String, kinds: Array, users: Array, projects: Array, locked: Boolean, can: Object },
  data() {
    const a = this.account || {};
    return { form: this.$inertia.form({
      name: a.name || '', kind: a.kind || this.kind, currency: a.currency || Object.keys(this.cur)[0], code: a.code && !String(a.code).startsWith('ACC-') ? a.code : (a.code || ''), custodian_user_id: a.custodian_user_id || null, warehouse_id: a.warehouse_id || null, department: a.department || '', location: a.location || '', description: a.description || '',
      institution: a.institution || '', branch: a.branch || '', account_holder: a.account_holder || '', account_number: a.account_number || '', swift: a.swift || '', iban: a.iban || '', legal_entity: a.legal_entity || '',
      opening_balance: a.opening_balance ? this.trim(a.opening_balance) : '0', opening_date: a.opening_date || new Date().toISOString().slice(0, 10), min_balance: a.min_balance ? this.trim(a.min_balance) : '', max_balance: a.max_balance ? this.trim(a.max_balance) : '', txn_limit: a.txn_limit ? this.trim(a.txn_limit) : '',
      reconcile_every_days: a.reconcile_every_days || '', allow_negative: !!a.allow_negative, requires_approval: !!a.requires_approval, is_primary: !!a.is_primary, allowed_user_ids: a.allowed_user_ids || [], alert_user_ids: a.alert_user_ids || [], notes: a.notes || '',
    }) };
  },
  computed: { isBank() { return this.form.kind === 'bank_account'; }, isLinked() { return !!(this.account && this.account.fund_account_id); } },
  methods: {
    trim(v) { return String(v).includes('.') ? String(v).replace(/0+$/, '').replace(/\.$/, '') : String(v); },
    submit() { this.account ? this.form.put(route('acct.accounts.update', this.account.id)) : this.form.post(route('acct.accounts.store')); },
  },
};
</script>
