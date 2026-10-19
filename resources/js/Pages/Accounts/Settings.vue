<template>
  <admin-layout :title="$t('Accounts Settings')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cog" :title="$t('Accounts Settings')" :subtitle="$t('Approval rules, limits, alert thresholds and categories. Nothing here is fixed in the program.')" />

      <section v-if="can.settings" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="lock-closed" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Who Can Approve and Which Period Is Closed') }}</h2><p class="lg-card-sub">{{ $t('Only a Super Admin can change these, and the password is asked again.') }}</p></div></header>
        <div v-if="can.controls" class="lg-fields">
          <label class="ac-check"><input v-model="c.maker_checker" type="checkbox" />{{ $t('A person can never approve what they created or submitted') }}</label>
          <label class="ac-check"><input v-model="c.super_admin_self_approval" type="checkbox" />{{ $t('Allow a Super Admin to approve their own entries (recorded as an exception)') }}</label>
          <div class="ac-f"><label>{{ $t('Period closed through') }}</label><date-picker v-model="c.closed_through" /><small class="lg-subtext">{{ $t('Nothing dated on or before this day can be posted or reversed.') }}</small></div>
        </div>
        <div v-else class="lg-fields">
          <p class="lg-subtext">{{ settings.maker_checker ? $t('A person can never approve what they created or submitted') : $t('A person may approve what they created or submitted') }}</p>
          <p class="lg-subtext">{{ settings.super_admin_self_approval ? $t('A Super Admin may approve their own entries (recorded as an exception)') : $t('A Super Admin cannot approve their own entries') }}</p>
          <p class="lg-subtext">{{ settings.closed_through ? $t('Period closed through') + ': ' + settings.closed_through : $t('No period is closed') }}</p>
        </div>
        <div v-if="can.controls" class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Changes apply to new activity only') }}</span><div class="lg-actionbar-btns">
          <tec-confirms-password :content="$t('These settings decide who may approve money movements and which dates are locked. Please confirm your password to continue.')" @confirmed="saveControls"><button type="button" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></tec-confirms-password>
        </div></div>
      </section>

      <form v-if="can.settings" @submit.prevent="save">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="key" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Approval and Control') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Balance on a past date uses') }}</label><acct-select v-model="s.date_rule" :options="[{ value: 'txn_date', label: $t('The transaction date') }, { value: 'value_date', label: $t('The value date (when set)') }]" /></div>
            <label class="ac-check"><input v-model="s.allow_inverse_rates" type="checkbox" />{{ $t('Allow an approved rate to be used in reverse (1 / rate)') }}</label>
            <div class="ac-f"><label>{{ $t('Credited amount may differ from amount x rate by (%)') }}</label><input v-model="s.rate_tolerance_pct" inputmode="decimal" /></div>
          </div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="bell" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Limits and Alerts') }}</h2><p class="lg-card-sub">{{ $t('Amounts are set for each currency separately. Leave a currency empty for no limit.') }}</p></div></header>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Currency') }}</th><th>{{ $t('Large transaction alert from') }}</th><th>{{ $t('Document required above') }}</th><th>{{ $t('Two approvers for a difference above') }}</th></tr></thead>
            <tbody><tr v-for="c in currencyCodes" :key="c"><td class="lg-main" :data-label="$t('Currency')">{{ c }}</td>
              <td :data-label="$t('Large transaction alert from')"><input v-model="s.large_txn[c]" class="ac-input" inputmode="decimal" /></td><td :data-label="$t('Document required above')"><input v-model="s.attachment_required_over[c]" class="ac-input" inputmode="decimal" /></td><td :data-label="$t('Two approvers for a difference above')"><input v-model="s.discrepancy_dual_over[c]" class="ac-input" inputmode="decimal" /></td></tr></tbody></table></div>
          <div class="lg-fields" style="margin-top: 12px">
            <div class="ac-f"><label>{{ $t('Warn when an item waits for approval (hours)') }}</label><input v-model="s.pending_alert_hours" type="number" min="1" /></div>
            <div class="ac-f"><label>{{ $t('Warn when a transfer waits (hours)') }}</label><input v-model="s.transfer_pending_hours" type="number" min="1" /></div>
            <div class="ac-f"><label>{{ $t('Unusual payment: times the account average') }}</label><input v-model="s.unusual_factor" inputmode="decimal" /></div>
            <div class="ac-f"><label>{{ $t('Tell again about the same alert after (hours)') }}</label><input v-model="s.alert_cooldown_hours" type="number" min="1" /></div>
            <div class="ac-f"><label>{{ $t('Reconciliation overdue after (days)') }}</label><input v-model="s.recon_overdue_days" type="number" min="1" /></div>
          </div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Notes and coins') }}</h2><p class="lg-card-sub">{{ $t('The faces offered when counting cash, separated by commas.') }}</p></div></header>
          <div class="lg-fields"><div v-for="c in currencyCodes" :key="c" class="ac-f"><label>{{ c }}</label><input :value="(s.denominations[c] || []).join(', ')" @change="s.denominations[c] = $event.target.value.split(',').map(x => Number(x.trim())).filter(x => x > 0)" /></div></div>
        </section>
        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Changes apply to new activity only') }}</span><div class="lg-actionbar-btns"><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Save settings') }}</button></div></div>
      </form>

      <section v-if="can.rules" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="tick" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Approval Rules') }}</h2><p class="lg-card-sub">{{ $t('How many approvals a submission needs. The strictest rule that matches wins. An amount range needs a currency.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Name') }}</th><th>{{ $t('Applies to') }}</th><th>{{ $t('Amount') }}</th><th>{{ $t('Account') }}</th><th class="ac-num">{{ $t('Approvals') }}</th><th>{{ $t('Active') }}</th><th></th></tr></thead>
          <tbody><tr v-for="r in rules" :key="r.id"><td class="lg-main" :data-label="$t('Name')">{{ r.name }}</td><td :data-label="$t('Applies to')">{{ labels[r.applies_to] }}</td>
            <td :data-label="$t('Amount')">{{ r.currency ? r.currency + ' ' + trim(r.min_amount) + (r.max_amount ? ' - ' + trim(r.max_amount) : ' +') : $t('Any') }}</td><td :data-label="$t('Account')">{{ acctName(r.account_id) }}</td><td :data-label="$t('Approvals')" class="ac-num">{{ r.approvals_required }}</td>
            <td :data-label="$t('Active')"><span class="lg-chip" :class="r.is_active ? 'lg-chip-green' : 'lg-chip-slate'">{{ r.is_active ? $t('Yes') : $t('No') }}</span></td>
            <td class="lg-sticky-end"><div class="lg-actions"><button type="button" class="lg-icon-btn" :aria-label="$t('Edit')" @click="editRule(r)"><icons name="edit" class="w-4 h-4" /></button><button type="button" class="lg-icon-btn" :aria-label="$t('Delete')" @click="removeRule(r)"><icons name="trash" class="w-4 h-4" /></button></div></td></tr></tbody></table></div>
        <form class="ac-filters" style="margin-top: 12px" @submit.prevent="saveRule">
          <div class="ac-f"><label>{{ $t('Name') }}</label><input v-model="rf.name" required /></div>
          <div class="ac-f"><label>{{ $t('Applies to') }}</label><acct-select v-model="rf.applies_to" :options="Object.entries(labels).map(([k, l]) => ({ value: k, label: l }))" /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="rf.currency" :options="(currencyCodes).map(c => ({ value: c, label: c }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From amount') }}</label><input v-model="rf.min_amount" inputmode="decimal" /></div>
          <div class="ac-f"><label>{{ $t('Up to (empty = no limit)') }}</label><input v-model="rf.max_amount" inputmode="decimal" /></div>
          <div class="ac-f"><label>{{ $t('Account (optional)') }}</label><acct-select v-model="rf.account_id" :options="accounts" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Approvals needed (at least 1)') }}</label><input v-model="rf.approvals_required" type="number" min="1" max="5" required /></div>
          <label class="ac-check"><input v-model="rf.is_active" type="checkbox" />{{ $t('Active') }}</label>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ rfId ? $t('Save rule') : $t('Add rule') }}</button></div><div v-if="rfId" class="ac-f"><button type="button" class="lg-btn t-slate" @click="resetRule"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
        </form>
        <p v-if="Object.keys($page.props.errors || {}).length" class="ac-err" style="margin-top: 6px">{{ Object.values($page.props.errors)[0] }}</p>
      </section>

      <section v-if="can.settings" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="folder" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Categories') }}</h2><p class="lg-card-sub">{{ $t('Built-in categories are used by transfers and corrections and cannot be changed.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Name') }}</th><th>{{ $t('For') }}</th><th>{{ $t('Active') }}</th><th></th></tr></thead>
          <tbody><tr v-for="c in categories" :key="c.id"><td class="lg-main" :data-label="$t('Name')">{{ c.name }} <span v-if="c.is_system" class="lg-chip lg-chip-slate">{{ $t('Built in') }}</span></td><td :data-label="$t('For')">{{ { in: $t('Money in'), out: $t('Money out'), both: $t('Both') }[c.direction] }}</td><td :data-label="$t('Active')">{{ c.is_active ? $t('Yes') : $t('No') }}</td>
            <td class="lg-sticky-end"><button v-if="!c.is_system" type="button" class="lg-icon-btn" :aria-label="$t('Edit')" @click="editCat(c)"><icons name="edit" class="w-4 h-4" /></button></td></tr></tbody></table></div>
        <form class="ac-filters" style="margin-top: 12px" @submit.prevent="saveCat">
          <div class="ac-f"><label>{{ $t('Name') }}</label><input v-model="cf.name" required /></div><div class="ac-f"><label>{{ $t('For') }}</label><acct-select v-model="cf.direction" :options="[{ value: 'in', label: $t('Money in') }, { value: 'out', label: $t('Money out') }, { value: 'both', label: $t('Both') }]" /></div>
          <label class="ac-check"><input v-model="cf.is_active" type="checkbox" />{{ $t('Active') }}</label><div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ cfId ? $t('Save') : $t('Add category') }}</button></div><div v-if="cfId" class="ac-f"><button type="button" class="lg-btn t-slate" @click="resetCat"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
        </form>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from './AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import TecConfirmsPassword from '@/Jetstream/ConfirmsPassword.vue';
import { acct } from './acct';

export default {
  components: { DatePicker, AcctSelect, AdminLayout, PageHeader, TecConfirmsPassword },
  mixins: [acct],
  props: { cur: Object, settings: Object, rules: Array, accounts: Array, categories: Array, currencyCodes: Array, can: Object },
  data() {
    const s = JSON.parse(JSON.stringify(this.settings));
    ['large_txn', 'attachment_required_over', 'discrepancy_dual_over', 'denominations'].forEach(k => { s[k] = s[k] || {}; });
    const c = { maker_checker: !!s.maker_checker, super_admin_self_approval: !!s.super_admin_self_approval, closed_through: s.closed_through || null };
    return { s, c, rfId: null, cfId: null, rf: this.blankRule(), cf: this.blankCat() };
  },
  computed: { labels() { return { outflow: this.$t('Payments (money out)'), inflow: this.$t('Receipts (money in)'), transfer: this.$t('Transfers'), adjustment: this.$t('Adjustments') }; } },
  methods: {
    trim(v) { return String(v).includes('.') ? String(v).replace(/0+$/, '').replace(/\.$/, '') : String(v); },
    acctName(id) { const a = this.accounts.find(x => x.value === id); return a ? a.label : this.$t('Any'); },
    blankRule() { return { name: '', applies_to: 'outflow', currency: null, min_amount: 0, max_amount: null, approvals_required: 1, account_id: null, is_active: true, sort_order: 0 }; },
    blankCat() { return { name: '', direction: 'both', is_active: true }; },
    save() { const { maker_checker, super_admin_self_approval, closed_through, ...rest } = this.s; this.$inertia.put(route('acct.settings.save'), rest, { preserveScroll: true }); },
    saveControls() { this.$inertia.put(route('acct.settings.controls'), this.c, { preserveScroll: true }); },
    editRule(r) { this.rfId = r.id; this.rf = { name: r.name, applies_to: r.applies_to, currency: r.currency, min_amount: this.trim(r.min_amount), max_amount: r.max_amount ? this.trim(r.max_amount) : null, approvals_required: r.approvals_required, account_id: r.account_id, is_active: !!r.is_active, sort_order: r.sort_order }; window.scrollTo({ top: document.body.scrollHeight / 2, behavior: 'smooth' }); },
    resetRule() { this.rfId = null; this.rf = this.blankRule(); },
    saveRule() { const o = { preserveScroll: true, onSuccess: () => this.resetRule() }; this.rfId ? this.$inertia.put(route('acct.settings.rules.update', this.rfId), this.rf, o) : this.$inertia.post(route('acct.settings.rules.store'), this.rf, o); },
    removeRule(r) { if (confirm(this.$t('Remove this rule?'))) this.$inertia.delete(route('acct.settings.rules.delete', r.id), { preserveScroll: true }); },
    editCat(c) { this.cfId = c.id; this.cf = { name: c.name, direction: c.direction, is_active: !!c.is_active }; },
    resetCat() { this.cfId = null; this.cf = this.blankCat(); },
    saveCat() { const o = { preserveScroll: true, onSuccess: () => this.resetCat() }; this.cfId ? this.$inertia.put(route('acct.settings.categories.update', this.cfId), this.cf, o) : this.$inertia.post(route('acct.settings.categories.store'), this.cf, o); },
  },
};
</script>
