<template>
  <admin-layout :title="$t('Incoming Mail & Deliveries')">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="email" :title="$t('Incoming Mail & Deliveries')" :subtitle="$t('Letters, documents, packages and parcels received at reception, and who they are for.')">
        <Link v-if="can.create" :href="route('visitor-logs.items.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Register an Item') }}</span></Link>
        <Link :href="route('visitor-logs.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <Link v-if="can.reports" :href="route('visitor-logs.reports.index', { type: 'awaiting' })" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
      </page-header>

      <sc-tabs :tabs="tabs" :model-value="f.when || ''" @update:model-value="setWhen" />

      <section class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Reference, sender, subject, recipient or tracking number')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('Project') }}</label><acct-select v-model="f.warehouse_id" :options="warehouses.map(w => ({ value: String(w.id), label: w.name }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Recipient') }}</label><acct-select v-model="f.recipient_id" :options="recipients" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statuses.map(s => ({ value: s, label: s }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Type') }}</label><acct-select v-model="f.type" :options="types.map(s => ({ value: s, label: s }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n item(s) found', { n: rows.total !== undefined ? rows.total : rows.data.length }) }}</span></div>
      </section>

      <div v-if="error" class="lg-note lg-note-red" role="alert" style="margin-bottom: 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ error }}</span></div>
      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr>
            <th class="tr-no">{{ $t('No') }}</th>
            <th><button type="button" class="vl-sort" @click="sort('reference')">{{ $t('Item') }}<i>{{ arrow('reference') }}</i></button></th>
            <th class="tr-nw"><button type="button" class="vl-sort" @click="sort('received_at')">{{ $t('Received') }}<i>{{ arrow('received_at') }}</i></button></th>
            <th>{{ $t('Type') }}</th>
            <th>{{ $t('Attended from') }}</th>
            <th><button type="button" class="vl-sort" @click="sort('sender')">{{ $t('Sender') }}<i>{{ arrow('sender') }}</i></button></th>
            <th><button type="button" class="vl-sort" @click="sort('recipient')">{{ $t('Recipient') }}<i>{{ arrow('recipient') }}</i></button></th>
            <th><button type="button" class="vl-sort" @click="sort('status')">{{ $t('Status') }}<i>{{ arrow('status') }}</i></button></th>
            <th>{{ $t('Received by') }}</th>
            <th class="tr-nw"><button type="button" class="vl-sort" @click="sort('status_changed_at')">{{ $t('Last change') }}<i>{{ arrow('status_changed_at') }}</i></button></th>
            <th class="lg-sticky-end"></th>
          </tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('visitor-logs.items.show', r.id))">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Item')" class="tr-wrap"><div class="tr-cell"><b class="lg-main lg-mono">{{ r.reference }}</b><div class="tr-line"><span v-if="r.confidential" class="lg-chip lg-chip-red" style="text-transform: none"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Confidential') }}</span><small v-if="r.count > 1">{{ r.count }} {{ $t('items') }}</small></div></div></td>
              <td :data-label="$t('Received')" class="tr-nw">{{ $datetime(r.received_at) }}</td>
              <td :data-label="$t('Type')">{{ r.type || '-' }}</td>
              <td :data-label="$t('Attended from')" class="tr-wrap">{{ r.project || '-' }}</td>
              <td :data-label="$t('Sender')" class="tr-wrap"><div class="tr-cell"><b v-if="r.masked" class="vl-mask"><icons name="lock-closed" class="w-3 h-3" /> {{ $t('Hidden') }}</b><template v-else><b>{{ r.sender || '-' }}</b><small v-if="r.subject">{{ r.subject }}</small></template></div></td>
              <td :data-label="$t('Recipient')" class="tr-wrap"><div class="tr-cell"><b>{{ r.recipient || '-' }}</b><small v-if="r.department">{{ r.department }}</small></div></td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r)" style="text-transform: none">{{ r.status }}</span><small v-if="r.waiting_days !== null && r.waiting_days >= 1" class="lg-subtext vl-wait"> {{ $t(':n day(s)', { n: r.waiting_days }) }}</small></td>
              <td :data-label="$t('Received by')" class="tr-wrap">{{ r.received_by || '-' }}</td>
              <td :data-label="$t('Last change')" class="tr-nw">{{ r.status_changed_at ? $datetime(r.status_changed_at) : '-' }}</td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop>
                <span class="tr-acts">
                  <button v-if="r.open && !r.masked && can.status && readyName && r.flag !== 'ready'" type="button" class="lg-btn lg-btn-soft vl-go" :disabled="busyId === r.id" @click="ready(r)"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Mark Ready') }}</span></button>
                  <Link :href="route('visitor-logs.items.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></Link>
                </span>
              </td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="11">{{ hasFilters ? $t('No item matches these filters.') : $t('No item has been registered yet.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '@/Pages/Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';
import { itemChip } from '../Parts/vl.js';

export default {
  components: { ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager },
  props: { rows: Object, filters: Object, warehouses: { type: Array, default: () => [] }, statuses: Array, types: Array, recipients: Array, stats: Object, collectionDays: Number, readyStatus: String, can: Object },
  data() { return { f: { ...this.filters }, busy: false, timer: null, busyId: null, error: '' }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    tabs() {
      const s = this.stats;
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.total }, { key: 'open', label: this.$t('Waiting'), icon: 'clock', tone: 'amber', count: s.open }, { key: 'closed', label: this.$t('Finished'), icon: 'tick', tone: 'emerald', count: s.closed },
        ...(this.collectionDays > 0 ? [{ key: 'overdue', label: this.$t('Waiting too long'), icon: 'exclamation', tone: 'rose', count: s.overdue }] : [])];
    },
    hasFilters() { return ['search', 'status', 'type', 'from', 'to', 'recipient_id', 'when', 'warehouse_id'].some(k => this.f[k]); },
    readyName() { return this.readyStatus || ''; },
  },
  methods: {
    chip(r) { return itemChip(r.flag, r.open); },
    queue() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k] && !(k === 'sort' && this.f[k] === 'received_at') && !(k === 'dir' && this.f[k] === 'desc')) q[k] = this.f[k]; }); this.$inertia.get(route('visitor-logs.items.index'), q, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) }); },
    setWhen(s) { this.f = { ...this.f, when: this.f.when === s && s ? '' : s }; },
    reset() { this.f = { sort: this.f.sort, dir: this.f.dir }; },
    sort(k) { this.f = { ...this.f, sort: k, dir: this.f.sort === k && this.f.dir === 'asc' ? 'desc' : 'asc' }; },
    arrow(k) { return this.f.sort === k ? (this.f.dir === 'asc' ? ' ▲' : ' ▼') : ''; },
    ready(r) { this.busyId = r.id; this.error = ''; this.$inertia.post(route('visitor-logs.items.status', r.id), { status: this.readyName }, { preserveScroll: true, onError: e => (this.error = e.status || Object.values(e)[0]), onFinish: () => (this.busyId = null) }); },
  },
};
</script>

<style scoped>
.vl-sort { background: none; border: 0; padding: 0; font: inherit; color: inherit; text-transform: inherit; letter-spacing: inherit; cursor: pointer; white-space: nowrap; }
.vl-sort i { font-style: normal; font-size: 0.7em; }
.vl-mask { display: inline-flex; align-items: center; gap: 4px; color: var(--lg-mute, #64748b); }
.vl-wait { font-weight: 700; }
.vl-go { min-height: 34px; padding: 0 12px; white-space: nowrap; }
</style>
