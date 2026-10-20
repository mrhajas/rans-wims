<template>
  <admin-layout :title="$t('Administration')">
    <div class="lg-page ac sc-ui tr-ui wa-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Administration')" :subtitle="$t('The weekly administration document: numbered lines with the beneficiary and the amount, an optional Charity section, and three stages: requested, approved and authorized.')">
        <Link v-if="can.create" :href="route('weekly-administration.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Document') }}</span></Link>
        <Link v-if="can.items" :href="route('weekly-administration.items.index')" class="lg-btn lg-btn-soft"><icons name="collection" class="w-4 h-4" /><span>{{ $t('Template and Items') }}</span></Link>
        <Link v-if="can.charity" :href="route('weekly-administration.charity.index')" class="lg-btn lg-btn-soft"><icons name="users" class="w-4 h-4" /><span>{{ $t('Charity') }}</span></Link>
        <Link :href="route('weekly-administration.reports.index')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
      </page-header>

      <div class="wa-stats">
        <button type="button" class="wa-stat is-amber" :class="{ 'is-on': f.status === 'Requested' }" @click="setStatus('Requested')"><icons name="clock" class="w-5 h-5" /><span><b>{{ stats.requested }}</b>{{ $t('Waiting for approval') }}</span></button>
        <button type="button" class="wa-stat is-sky" :class="{ 'is-on': f.status === 'Approved' }" @click="setStatus('Approved')"><icons name="tick" class="w-5 h-5" /><span><b>{{ stats.approved }}</b>{{ $t('Waiting for authorization') }}</span></button>
        <button type="button" class="wa-stat is-green" :class="{ 'is-on': f.status === 'Authorized' }" @click="setStatus('Authorized')"><icons name="check-double" class="w-5 h-5" /><span><b>{{ stats.authorized }}</b>{{ $t('Authorized') }}</span></button>
        <button v-if="can.charity" type="button" class="wa-stat" :class="{ 'is-on': f.charity === '1' }" @click="toggle('charity', '1')"><icons name="heart" class="w-5 h-5" /><span><b>{{ totals.currency }} {{ money(totals.charity) }}</b>{{ $t('Charity in what is listed') }}</span></button>
      </div>
      <div class="wa-totals">
        <div class="wa-tot"><small>{{ $t('Authorized') }}</small><b class="tr-tm">{{ totals.currency }} {{ money(totals.authorized) }}</b></div>
        <div class="wa-tot"><small>{{ $t('Paid out') }}</small><b class="tr-g">{{ totals.currency }} {{ money(totals.paid) }}</b></div>
        <div class="wa-tot"><small>{{ $t('Authorized, still to pay') }}</small><b class="tr-m">{{ totals.currency }} {{ money(totals.to_pay) }}</b></div>
        <div class="wa-tot"><small>{{ $t('Still waiting') }}</small><b class="tr-o">{{ totals.currency }} {{ money(totals.waiting) }}</b></div>
        <div class="wa-tot"><small>{{ $t('All listed') }}</small><b class="tr-tm">{{ totals.currency }} {{ money(totals.all) }}</b></div>
      </div>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />

      <section class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Reference, requested by, description or beneficiary of a line')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('Headquarters') }}</label><acct-select v-model="f.hq" :options="hqs.map(h => ({ value: h, label: h }))" :placeholder="$t('Every headquarters')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Requested by') }}</label><acct-select v-model="f.beneficiary_id" :options="users" :placeholder="$t('Anyone')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Item') }}</label><acct-select v-model="f.item_id" :options="items" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="f.category" :options="categories.map(c => ({ value: c, label: c }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Staff member') }}</label><acct-select v-model="f.staff_id" :options="staffOptions" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Payment') }}</label><acct-select v-model="f.pay" :options="payOptions" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Date from') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('Date to') }}</label><date-picker v-model="f.to" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n document(s) found', { n: rows.total !== undefined ? rows.total : rows.data.length }) }}</span></div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Document') }}</th><th>{{ $t('Requested by') }}</th><th>{{ $t('The three stages') }}</th><th class="tr-num">{{ $t('Administration lines') }}</th><th class="tr-num">{{ $t('Charity') }}</th><th class="tr-num">{{ $t('Total') }}</th><th class="lg-sticky-end"></th></tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('weekly-administration.show', r.id))">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Document')" class="tr-wrap"><div class="tr-cell"><b class="lg-main">{{ r.reference }}</b><div class="tr-line"><span class="wa-hqchip"><icons name="building" class="w-3 h-3" />{{ r.hq }}</span><span class="lg-chip" :class="chip(r.status)" style="text-transform: none">{{ r.status_label }}</span><span v-if="r.payment_status" class="lg-chip" :class="payChip(r.payment_status)" style="text-transform: none">{{ $t(r.payment_status) }}</span></div><small>{{ $date(r.doc_date) }}<template v-if="r.source === 'duplicate'"> · {{ $t('duplicate') }}</template></small></div></td>
              <td :data-label="$t('Requested by')" class="tr-wrap"><div class="tr-cell"><b>{{ r.beneficiary || '-' }}</b><small>{{ $t('prepared by') }} {{ r.by || '-' }}</small></div></td>
              <td :data-label="$t('The three stages')" class="tr-wrap"><div class="tr-cell"><span><small>{{ $t('Approved') }}</small> {{ r.approved_by || '-' }}</span><span><small>{{ $t('Authorized') }}</small> {{ r.authorized_by || '-' }}</span></div></td>
              <td :data-label="$t('Administration lines')" class="tr-num">{{ r.lines_count }}</td>
              <td :data-label="$t('Charity')" class="tr-num"><span v-if="r.include_charity" class="tr-o">{{ money(r.charity_total) }} <small>({{ r.charity_count }})</small></span><span v-else>-</span></td>
              <td :data-label="$t('Total')" class="tr-num"><div class="tr-money"><b class="tr-tm">{{ r.currency }} {{ money(r.total_amount) }}</b><small v-if="r.payment_status" class="tr-g">{{ $t('paid') }} {{ money(r.paid_total) }}</small><small v-if="r.payment_status && Number(r.balance)" class="tr-m">{{ $t('balance') }} {{ money(r.balance) }}</small></div></td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop><span class="tr-acts">
                <a v-if="can.print" :href="route('weekly-administration.print', r.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a>
                <a v-if="can.print" :href="route('weekly-administration.pdf', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
                <button v-if="can.send && r.status !== 'Cancelled'" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Send by email')" :aria-label="$t('Send by email')" @click="sendDoc = r"><icons name="send" class="w-4 h-4" /></button>
                <qr-record-button :record="{ type: 'admin_document', id: r.id, label: r.reference, print: can.print }" compact />
                <button v-if="can.duplicate && can.create" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Duplicate')" :aria-label="$t('Duplicate')" @click="dupDoc = r"><icons name="duplicate" class="w-4 h-4" /></button>
              </span></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ hasFilters ? $t('No document matches these filters.') : $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
      <send-dialog :show="!!sendDoc" :doc="sendDoc" @close="sendDoc = null" />
      <duplicate-dialog :show="!!dupDoc" :doc="dupDoc" @close="dupDoc = null" />
    </div>
  </admin-layout>
</template>

<script>
import SendDialog from './SendDialog.vue';
import QrRecordButton from '@/Shared/Qr/QrRecordButton.vue';
import DuplicateDialog from './DuplicateDialog.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';

export default {
  components: { QrRecordButton, SendDialog, DuplicateDialog, ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager },
  props: { rows: Object, filters: Object, statuses: Array, users: Array, hqs: { type: Array, default: () => [] }, items: Array, categories: { type: Array, default: () => [] }, staffOptions: { type: Array, default: () => [] }, stats: Object, payStats: Object, totals: Object, can: Object },
  data() { return { f: { ...this.filters }, busy: false, timer: null, sendDoc: null, dupDoc: null }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    tabs() {
      const s = this.stats;
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.total }, { key: 'Requested', label: this.$t('Requested'), icon: 'clock', tone: 'amber', count: s.requested }, { key: 'Approved', label: this.$t('Approved'), icon: 'tick', tone: 'sky', count: s.approved },
        { key: 'Authorized', label: this.$t('Authorized'), icon: 'check-double', tone: 'emerald', count: s.authorized }, { key: 'Rejected', label: this.$t('Rejected'), icon: 'x-circle', tone: 'rose', count: s.rejected }, { key: 'Cancelled', label: this.$t('Cancelled'), icon: 'x-circle', tone: 'slate', count: s.cancelled }];
    },
    payOptions() { return ['Unpaid', 'Partially Paid', 'Paid'].map(x => ({ value: x, label: this.$t(x) + ' (' + this.payStats[x] + ')' })); },
    hasFilters() { return Object.keys(this.f).some(k => this.f[k]); },
  },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    payChip(s) { return { Paid: 'lg-chip-green', 'Partially Paid': 'lg-chip-amber', Unpaid: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    chip(s) { return { Requested: 'lg-chip-amber', Approved: 'lg-chip-blue', Authorized: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    queue() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('weekly-administration.index'), q, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) }); },
    setStatus(s) { this.f = { ...this.f, status: this.f.status === s && s ? '' : s }; },
    toggle(k, v) { this.f = { ...this.f, [k]: this.f[k] === v ? '' : v }; },
    reset() { this.f = {}; },
  },
};
</script>
