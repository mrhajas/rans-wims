<template>
  <admin-layout :title="$t('Visitor Register')">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="list" :title="$t('Visitor Register')" :subtitle="$t('Every visit: who came, who they saw, when they arrived and left.')">
        <Link v-if="can.create" :href="route('visitor-logs.visits.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Visitor Log') }}</span></Link>
        <Link :href="route('visitor-logs.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <Link v-if="can.reports" :href="route('visitor-logs.reports.index', { type: 'daily_register' })" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
      </page-header>

      <div v-if="error" class="lg-note lg-note-red" role="alert" style="margin-bottom: 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ error }}</span></div>
      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />

      <section class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Visitor, company, reference, person visited or purpose')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('Project') }}</label><acct-select v-model="f.warehouse_id" :options="warehouses.map(w => ({ value: String(w.id), label: w.name }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Person visited') }}</label><acct-select v-model="f.host_id" :options="hosts" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Department') }}</label><acct-select v-model="f.department" :options="departments.map(d => ({ value: d, label: d }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Purpose') }}</label><acct-select v-model="f.purpose" :options="purposes.map(d => ({ value: d, label: d }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n visit(s) found', { n: rows.total !== undefined ? rows.total : rows.data.length }) }}</span></div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr>
            <th class="tr-no">{{ $t('No') }}</th>
            <th><button type="button" class="vl-sort" @click="sort('reference')">{{ $t('Visit') }}<i>{{ arrow('reference') }}</i></button></th>
            <th>{{ $t('Visitor') }}</th>
            <th>{{ $t('Attended from') }}</th>
            <th><button type="button" class="vl-sort" @click="sort('host')">{{ $t('Person visited') }}<i>{{ arrow('host') }}</i></button></th>
            <th><button type="button" class="vl-sort" @click="sort('purpose')">{{ $t('Purpose') }}<i>{{ arrow('purpose') }}</i></button></th>
            <th class="tr-nw"><button type="button" class="vl-sort" @click="sort('check_in_at')">{{ $t('Check-in') }}<i>{{ arrow('check_in_at') }}</i></button></th>
            <th class="tr-nw"><button type="button" class="vl-sort" @click="sort('check_out_at')">{{ $t('Check-out') }}<i>{{ arrow('check_out_at') }}</i></button></th>
            <th>{{ $t('Duration') }}</th>
            <th><button type="button" class="vl-sort" @click="sort('status')">{{ $t('Status') }}<i>{{ arrow('status') }}</i></button></th>
            <th>{{ $t('Receptionist') }}</th>
            <th class="lg-sticky-end"></th>
          </tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('visitor-logs.visits.show', r.id))">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Visit')"><b class="lg-main lg-mono">{{ r.reference }}</b></td>
              <td :data-label="$t('Visitor')" class="tr-wrap"><div class="tr-cell"><b>{{ r.visitor }}</b><small v-if="r.company">{{ r.company }}</small></div></td>
              <td :data-label="$t('Attended from')" class="tr-wrap">{{ r.project || '-' }}</td>
              <td :data-label="$t('Person visited')" class="tr-wrap"><div class="tr-cell"><b>{{ r.host || '-' }}</b><small v-if="r.department">{{ r.department }}</small></div></td>
              <td :data-label="$t('Purpose')" class="tr-wrap">{{ r.purpose || '-' }}</td>
              <td :data-label="$t('Check-in')" class="tr-nw">{{ $datetime(r.check_in_at) }}</td>
              <td :data-label="$t('Check-out')" class="tr-nw">{{ r.check_out_at ? $datetime(r.check_out_at) : '-' }}</td>
              <td :data-label="$t('Duration')" class="tr-nw"><span :class="{ 'vl-long': isLong(r) }">{{ r.duration || '-' }}</span><small v-if="r.status === 'Checked In'" class="lg-subtext"> {{ $t('so far') }}</small></td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r.status)" style="text-transform: none">{{ $t(r.status) }}</span></td>
              <td :data-label="$t('Receptionist')" class="tr-wrap">{{ r.registered_by || '-' }}</td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop>
                <span class="tr-acts">
                  <button v-if="r.status === 'Checked In' && can.checkout" type="button" class="lg-btn lg-btn-primary vl-out" :disabled="busyId === r.id" @click="checkout(r)"><icons name="out" class="w-4 h-4" /><span>{{ $t('Check Out') }}</span></button>
                  <Link :href="route('visitor-logs.visits.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></Link>
                </span>
              </td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="12">{{ hasFilters ? $t('No visit matches these filters.') : $t('No visits have been registered yet.') }}</td></tr>
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
import { visitChip } from '../Parts/vl.js';

export default {
  components: { ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager },
  props: { rows: Object, filters: Object, warehouses: { type: Array, default: () => [] }, statuses: Array, purposes: Array, departments: Array, hosts: Array, stats: Object, can: Object, overstayHours: Number },
  data() { return { f: { ...this.filters }, busy: false, timer: null, busyId: null, error: '' }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    tabs() {
      const s = this.stats;
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.total }, { key: 'Checked In', label: this.$t('On premises'), icon: 'in', tone: 'emerald', count: s.in }, { key: 'Checked Out', label: this.$t('Checked out'), icon: 'out', tone: 'slate', count: s.out }, { key: 'Cancelled', label: this.$t('Cancelled'), icon: 'x-circle', tone: 'rose', count: s.cancelled }];
    },
    hasFilters() { return ['search', 'status', 'from', 'to', 'host_id', 'department', 'purpose', 'warehouse_id'].some(k => this.f[k]); },
  },
  methods: {
    chip: visitChip,
    isLong(r) { return r.status === 'Checked In' && this.overstayHours > 0 && r.minutes >= this.overstayHours * 60; },
    queue() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k] && !(k === 'sort' && this.f[k] === 'check_in_at') && !(k === 'dir' && this.f[k] === 'desc')) q[k] = this.f[k]; }); this.$inertia.get(route('visitor-logs.visits.index'), q, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) }); },
    setStatus(s) { this.f = { ...this.f, status: this.f.status === s && s ? '' : s }; },
    reset() { this.f = { sort: this.f.sort, dir: this.f.dir }; },
    sort(k) { this.f = { ...this.f, sort: k, dir: this.f.sort === k && this.f.dir === 'asc' ? 'desc' : 'asc' }; },
    arrow(k) { return this.f.sort === k ? (this.f.dir === 'asc' ? ' ▲' : ' ▼') : ''; },
    checkout(r) {
      this.busyId = r.id; this.error = '';
      this.$inertia.post(route('visitor-logs.visits.checkout', r.id), {}, { preserveScroll: true, onError: e => (this.error = e.checkout || Object.values(e)[0]), onFinish: () => (this.busyId = null) });
    },
  },
};
</script>

<style scoped>
.vl-sort { background: none; border: 0; padding: 0; font: inherit; color: inherit; text-transform: inherit; letter-spacing: inherit; cursor: pointer; white-space: nowrap; }
.vl-sort i { font-style: normal; font-size: 0.7em; }
.vl-long { color: #b45309; font-weight: 800; }
.vl-out { min-height: 34px; padding: 0 12px; white-space: nowrap; }
</style>
