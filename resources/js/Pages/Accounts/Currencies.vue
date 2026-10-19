<template>
  <admin-layout :title="$t('Currencies and Rates')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="adjustments" :title="$t('Currencies and Rates')" :subtitle="$t('Which currencies you hold, and the approved exchange rates used to convert between them. A rate is used only after it is approved.')">
        <acct-export type="exchange_rates" />
      </page-header>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Currencies') }}</h2><p class="lg-card-sub">{{ $t('Decimal places are set per currency and cannot change once it has transactions.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Code') }}</th><th>{{ $t('Name') }}</th><th>{{ $t('Symbol') }}</th><th class="ac-num">{{ $t('Decimals') }}</th><th class="ac-num">{{ $t('Accounts') }}</th><th>{{ $t('Status') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="c in currencies" :key="c.id">
              <td :data-label="$t('Code')" class="lg-main">{{ c.code }} <span v-if="c.is_reporting" class="lg-chip lg-chip-cyan">{{ $t('Reporting') }}</span></td><td :data-label="$t('Name')">{{ c.name }}</td><td :data-label="$t('Symbol')">{{ c.symbol }}</td><td :data-label="$t('Decimals')" class="ac-num">{{ c.precision }}</td><td :data-label="$t('Accounts')" class="ac-num">{{ c.accounts }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="c.is_active ? 'lg-chip-green' : 'lg-chip-slate'">{{ c.is_active ? $t('Active') : $t('Inactive') }}</span></td>
              <td class="lg-sticky-end"><div class="lg-actions" v-if="can.manage">
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="edit(c)"><icons name="edit" class="w-4 h-4" /></button>
                <tec-confirms-password v-if="!c.is_reporting && c.is_active" :content="$t('Changing the reporting currency changes every converted total. Please confirm your password to continue.')" @confirmed="$inertia.post(route('acct.currencies.reporting', c.id), {}, { preserveScroll: true })"><button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Use for reporting')" :aria-label="$t('Use for reporting')"><icons name="chart-bar" class="w-4 h-4" /></button></tec-confirms-password></div></td>
            </tr>
          </tbody></table></div>
        <form v-if="can.manage" class="ac-filters" style="margin-top: 12px" @submit.prevent="saveCurrency">
          <div class="ac-f"><label>{{ $t('Code') }} (ISO)</label><input v-model="cf.code" maxlength="3" :disabled="!!editing" style="text-transform: uppercase" required /><span class="ac-err">{{ err('code') }}</span></div>
          <div class="ac-f"><label>{{ $t('Name') }}</label><input v-model="cf.name" required /></div>
          <div class="ac-f"><label>{{ $t('Symbol') }}</label><input v-model="cf.symbol" maxlength="10" required /></div>
          <div class="ac-f"><label>{{ $t('Decimals') }}</label><input v-model="cf.precision" type="number" min="0" max="6" required /><span class="ac-err">{{ err('precision') }}</span></div>
          <label v-if="editing" class="ac-check"><input v-model="cf.is_active" type="checkbox" />{{ $t('Active') }}</label>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ editing ? $t('Save') : $t('Add currency') }}</button></div>
          <div class="ac-f" v-if="editing"><button type="button" class="lg-btn t-slate" @click="editing = null; resetCf()"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
        </form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="refresh" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Current Exchange Rates') }}</h2><p class="lg-card-sub">{{ $t('Approved rates only. A missing rate is shown as missing, never guessed.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('From') }}</th><th>{{ $t('To') }}</th><th class="ac-num">{{ $t('Rate') }}</th><th>{{ $t('Type') }}</th></tr></thead>
          <tbody><tr v-for="r in current" :key="r.from + r.to"><td :data-label="$t('From')" class="lg-main">1 {{ r.from }}</td><td :data-label="$t('To')">{{ r.to }}</td><td :data-label="$t('Rate')" class="ac-num" :class="{ 'ac-neg': !r.rate }">{{ r.rate ? (r.derived ? $t('derived') + ' ' : '') + r.rate : $t('missing') }}</td><td :data-label="$t('Type')">{{ r.type || '-' }}</td></tr>
          <tr v-if="!current.length" class="lg-empty-row"><td colspan="4" class="lg-empty">{{ $t('Add a second currency to see rates.') }}</td></tr></tbody></table></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Exchange Rate Register') }}</h2></div></header>
        <form v-if="can.create" class="ac-filters" @submit.prevent="saveRate">
          <div class="ac-f"><label>{{ $t('1 unit of') }}</label><acct-select v-model="rf.base_currency" :options="(currencies).map(c => ({ value: c.code, label: c.code }))" /></div>
          <div class="ac-f"><label>{{ $t('equals (rate)') }}</label><input v-model="rf.rate" inputmode="decimal" required placeholder="2500" /><span class="ac-err">{{ err('rate') }}</span></div>
          <div class="ac-f"><label>{{ $t('of') }}</label><acct-select v-model="rf.quote_currency" :options="(currencies).map(c => ({ value: c.code, label: c.code }))" /><span class="ac-err">{{ err('quote_currency') }}</span></div>
          <div class="ac-f"><label>{{ $t('Type') }}</label><acct-select v-model="rf.rate_type" :options="rateTypes" /></div>
          <div class="ac-f"><label>{{ $t('Effective from') }}</label><date-picker v-model="rf.effective_from" :clearable="false" /></div>
          <div class="ac-f"><label>{{ $t('Until') }}</label><date-picker v-model="rf.effective_to" /></div>
          <div class="ac-f"><label>{{ $t('Source') }}</label><input v-model="rf.source_name" :placeholder="$t('Bank, website, management ...')" /></div>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('Add rate') }}</button></div>
        </form>
        <div class="ac-filters" style="margin: 10px 0">
          <div class="ac-f"><label>{{ $t('Show') }}</label><acct-select v-model="ff.status" :options="[{ value: 'pending', label: $t('Waiting for approval') }, { value: 'approved', label: $t('Approved') }, { value: 'rejected', label: $t('Rejected') }]" :placeholder="$t('All')" clearable @change="filter" /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="ff.currency" :options="currencies.map(c => ({ value: c.code, label: c.code }))" :placeholder="$t('Any')" clearable @change="filter" /></div>
        </div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Pair') }}</th><th class="ac-num">{{ $t('Rate') }}</th><th>{{ $t('Type') }}</th><th>{{ $t('Applies') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Entered by') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rates.data" :key="r.id">
              <td :data-label="$t('Pair')" class="lg-main">{{ r.base }}/{{ r.quote }}</td><td :data-label="$t('Rate')" class="ac-num">{{ r.rate }}</td><td :data-label="$t('Type')">{{ r.type_label }}<div class="lg-subtext" v-if="r.source_name">{{ r.source_name }}</div></td>
              <td :data-label="$t('Applies')">{{ $date(r.from) }}<span v-if="r.to"> - {{ $date(r.to) }}</span></td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status)">{{ $t(r.status) }}</span><div v-if="r.reject_reason" class="lg-subtext">{{ r.reject_reason }}</div><div v-if="r.approved_by" class="lg-subtext">{{ r.approved_by }}</div></td>
              <td :data-label="$t('Entered by')">{{ r.entered_by }}<div class="lg-subtext">{{ r.rate_at }}</div></td>
              <td class="lg-sticky-end"><div class="lg-actions" v-if="r.status === 'pending' && can.approve">
                <button type="button" class="lg-btn lg-btn-green" @click="$inertia.post(route('acct.rates.approve', r.id), {}, { preserveScroll: true })"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
                <button type="button" class="lg-btn t-red" @click="rejecting = r.id"><icons name="x-circle" class="w-4 h-4" />{{ $t('Reject') }}</button></div></td>
            </tr>
            <tr v-if="!rates.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
          </tbody></table></div>
        <form v-if="rejecting" class="ac-reason" @submit.prevent="reject"><input v-model="reason" :placeholder="$t('Reason (required)')" required /><button type="submit" class="lg-btn lg-btn-primary"><icons name="x-circle" class="w-4 h-4" />{{ $t('Reject rate') }}</button><button type="button" class="lg-btn t-slate" @click="rejecting = null"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <pager :rows="rates" />
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from './AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from './Pager.vue';
import AcctSelect from './AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import TecConfirmsPassword from '@/Jetstream/ConfirmsPassword.vue';
import { acct } from './acct';

export default {
  components: { AcctExport, DatePicker, AcctSelect, AdminLayout, PageHeader, Pager, Link, TecConfirmsPassword },
  mixins: [acct],
  props: { cur: Object, currencies: Array, rates: Object, filters: Object, rateTypes: Array, current: Array, can: Object },
  data() {
    return {
      editing: null, rejecting: null, reason: '', ff: { status: this.filters.status || '', currency: this.filters.currency || '' },
      cf: this.blank(), rf: { base_currency: 'USD', quote_currency: 'TZS', rate: '', rate_type: 'management', effective_from: new Date().toISOString().slice(0, 10), effective_to: '', source: 'manual', source_name: '' },
    };
  },
  methods: {
    blank() { return { code: '', name: '', symbol: '', precision: 2, is_active: true }; },
    resetCf() { this.cf = this.blank(); },
    edit(c) { this.editing = c.id; this.cf = { code: c.code, name: c.name, symbol: c.symbol, precision: c.precision, is_active: !!c.is_active }; },
    saveCurrency() {
      const opts = { preserveScroll: true, onSuccess: () => { this.editing = null; this.resetCf(); } };
      this.editing ? this.$inertia.put(route('acct.currencies.update', this.editing), this.cf, opts) : this.$inertia.post(route('acct.currencies.store'), this.cf, opts);
    },
    saveRate() { this.$inertia.post(route('acct.rates.store'), this.rf, { preserveScroll: true, onSuccess: () => (this.rf.rate = '') }); },
    reject() { this.$inertia.post(route('acct.rates.reject', this.rejecting), { reason: this.reason }, { preserveScroll: true, onSuccess: () => { this.rejecting = null; this.reason = ''; } }); },
    filter() { const q = {}; Object.keys(this.ff).forEach(k => { if (this.ff[k]) q[k] = this.ff[k]; }); this.$inertia.get(route('acct.currencies.index'), q, { preserveState: true, preserveScroll: true }); },
  },
};
</script>
