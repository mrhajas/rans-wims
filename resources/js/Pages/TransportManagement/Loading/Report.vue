<template>
  <admin-layout :title="$t('Loading & Unloading Report')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Loading & Unloading Report')" :subtitle="$t('Attendance, earnings, payments and balances of the loading and unloading personnel.')">
        <Link :href="route('transport-loading.index', { tab: 'balances' })" class="lg-btn lg-btn-soft"><icons name="users" class="w-4 h-4" />{{ $t('Loading & Unloading') }}</Link>
      </page-header>

      <section class="lg-card relative z-20">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Filters') }}</h2></div>
        </header>

        <div class="lr-grid">
          <div><auto-complete id="lr_type" :label="$t('Report type')" v-model="form.report_type" :suggestions="typeOptions" /></div>
          <div v-if="form.report_type !== 'sheet'"><date-picker :label="$t('Date From')" v-model="form.date_from" :max="form.date_to || undefined" /></div>
          <div><date-picker :label="form.report_type === 'sheet' ? $t('Day') : $t('Date To')" v-model="form.date_to" :min="form.report_type === 'sheet' ? undefined : (form.date_from || undefined)" /></div>
          <div><auto-complete id="lr_worker" :label="$t('Personnel')" v-model="form.contact_id" :suggestions="workerOptions" clearable :placeholder="$t('All personnel')" /></div>
          <div v-if="form.report_type === 'summary' || form.report_type === 'attendance'"><auto-complete id="lr_project" :label="$t('Project / site')" v-model="form.warehouse_id" :suggestions="projectOptions" clearable :placeholder="$t('All projects / sites')" /></div>
          <div v-if="form.report_type === 'attendance'"><auto-complete id="lr_place" :label="$t('Other place')" v-model="form.place" :suggestions="placeOptions" clearable :placeholder="$t('Any other place')" /></div>
          <div v-if="statusOptions.length"><auto-complete id="lr_status" :label="$t('Status')" v-model="form.status" :suggestions="statusOptions" clearable :placeholder="$t('Any status')" /></div>
          <div class="lr-switches">
            <label v-if="form.report_type === 'attendance'" class="lr-check"><input v-model="form.custom_rate" type="checkbox" /> {{ $t('Custom daily rates only') }}</label>
            <label v-if="form.report_type === 'summary'" class="lr-check"><input v-model="form.only_owing" type="checkbox" /> {{ $t('Only personnel still owed') }}</label>
            <button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </div>
      </section>

      <stat-tiles class="lr-tiles" :items="tiles" />

      <div class="lr-toolbar">
        <div class="lr-toolbar-title">
          <span class="lg-card-ico lg-ico-amber"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ typeLabel }}</h2>
            <p class="lg-card-sub">{{ rows.length }} {{ $t('row(s)') }} · {{ $t('standard daily rate') }} {{ $money(rate) }}</p>
          </div>
        </div>
        <div class="lr-actions print:hidden">
          <a v-if="can.print" :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a v-if="can.print" :href="url('pdf')" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download PDF') }}</a>
          <a v-if="can.export" :href="url('export')" class="lg-btn lg-btn-green"><icons name="table" class="w-4 h-4" />{{ $t('Export Excel') }}</a>
        </div>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr v-if="filters.report_type === 'summary'">
              <th class="w-12">{{ $t('No.') }}</th>
              <th class="is-sortable" @click="sortBy('name')"><span class="lg-sort">{{ $t('Personnel') }}<span class="tr-sort-caret">{{ caret('name') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('opening')"><span class="lg-sort">{{ $t('Previous balance') }}<span class="tr-sort-caret">{{ caret('opening') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('days')"><span class="lg-sort">{{ $t('Days') }}<span class="tr-sort-caret">{{ caret('days') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('earned')"><span class="lg-sort">{{ $t('Earned') }}<span class="tr-sort-caret">{{ caret('earned') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('paid')"><span class="lg-sort">{{ $t('Paid') }}<span class="tr-sort-caret">{{ caret('paid') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('pending')"><span class="lg-sort">{{ $t('On voucher') }}<span class="tr-sort-caret">{{ caret('pending') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('balance')"><span class="lg-sort">{{ $t('Balance') }}<span class="tr-sort-caret">{{ caret('balance') }}</span></span></th>
            </tr>
            <tr v-else-if="filters.report_type === 'sheet'">
              <th class="w-12">{{ $t('No.') }}</th>
              <th class="is-sortable" @click="sortBy('name')"><span class="lg-sort">{{ $t('Personnel') }}<span class="tr-sort-caret">{{ caret('name') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('rate')"><span class="lg-sort">{{ $t('Daily Rate') }}<span class="tr-sort-caret">{{ caret('rate') }}</span></span></th>
              <th>{{ $t('Status') }}</th>
              <th class="is-sortable num" @click="sortBy('amount')"><span class="lg-sort">{{ $t('Earned') }}<span class="tr-sort-caret">{{ caret('amount') }}</span></span></th>
            </tr>
            <tr v-else-if="filters.report_type === 'attendance'">
              <th class="w-12">{{ $t('No.') }}</th>
              <th class="is-sortable" @click="sortBy('date')"><span class="lg-sort">{{ $t('Date') }}<span class="tr-sort-caret">{{ caret('date') }}</span></span></th>
              <th class="is-sortable" @click="sortBy('name')"><span class="lg-sort">{{ $t('Personnel') }}<span class="tr-sort-caret">{{ caret('name') }}</span></span></th>
              <th class="is-sortable" @click="sortBy('status')"><span class="lg-sort">{{ $t('Status') }}<span class="tr-sort-caret">{{ caret('status') }}</span></span></th>
              <th>{{ $t('Project / site') }}</th>
              <th class="is-sortable num" @click="sortBy('rate')"><span class="lg-sort">{{ $t('Daily Rate') }}<span class="tr-sort-caret">{{ caret('rate') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('amount')"><span class="lg-sort">{{ $t('Earned') }}<span class="tr-sort-caret">{{ caret('amount') }}</span></span></th>
            </tr>
            <tr v-else>
              <th class="w-12">{{ $t('No.') }}</th>
              <th class="is-sortable" @click="sortBy('date')"><span class="lg-sort">{{ $t('Date') }}<span class="tr-sort-caret">{{ caret('date') }}</span></span></th>
              <th class="is-sortable" @click="sortBy('name')"><span class="lg-sort">{{ $t('Personnel') }}<span class="tr-sort-caret">{{ caret('name') }}</span></span></th>
              <th class="is-sortable num" @click="sortBy('amount')"><span class="lg-sort">{{ $t('Amount') }}<span class="tr-sort-caret">{{ caret('amount') }}</span></span></th>
              <th>{{ $t('Status') }}</th>
              <th>{{ $t('Method / reference') }}</th>
              <th>{{ $t('Payment Voucher') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="filters.report_type === 'summary'">
              <tr v-for="(r, i) in visibleRows" :key="r.contact_id" class="is-click" @click="$inertia.visit(route('transport-loading.show', r.contact_id))">
                <td :data-label="$t('No.')" class="lr-muted">{{ i + 1 }}</td>
                <td :data-label="$t('Personnel')"><span class="lg-main">{{ r.name }}</span><div class="lg-subtext">{{ r.phone || '' }}</div></td>
                <td :data-label="$t('Previous balance')" class="num"><span :class="{ 'lr-neg': r.opening < 0 }">{{ r.opening ? $money(r.opening) : '-' }}</span></td>
                <td :data-label="$t('Days')" class="num">{{ r.days }}</td>
                <td :data-label="$t('Earned')" class="num"><span class="lg-main">{{ $money(r.earned) }}</span></td>
                <td :data-label="$t('Paid')" class="num">{{ $money(r.paid) }}</td>
                <td :data-label="$t('On voucher')" class="num">{{ r.pending ? $money(r.pending) : '-' }}</td>
                <td :data-label="$t('Balance')" class="num"><b :class="{ 'lr-owed': r.balance > 0 }">{{ $money(r.balance) }}</b></td>
              </tr>
            </template>
            <template v-else-if="filters.report_type === 'sheet'">
              <tr v-for="(r, i) in visibleRows" :key="r.contact_id">
                <td :data-label="$t('No.')" class="lr-muted">{{ i + 1 }}</td>
                <td :data-label="$t('Personnel')"><span class="lg-main">{{ r.name }}</span><div class="lg-subtext">{{ r.phone || '' }}</div></td>
                <td :data-label="$t('Daily Rate')" class="num">
                  <span v-if="r.custom" class="lg-tip lr-custom" :data-tip="$t('Custom rate: :rate was used for this day instead of the standard :std.', { rate: $money(r.rate), std: $money(r.standard) })">{{ $money(r.rate) }}<icons name="exclamation" class="w-3 h-3 inline-flex lr-custom-ico" /></span>
                  <template v-else>{{ $money(r.rate) }}</template>
                </td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="r.status === 'Present' ? 'lg-chip-green' : r.status === 'Absent' ? 'lg-chip-red' : 'lg-chip-slate'" style="text-transform: none">{{ r.status ? $t(r.status) : $t('Not marked') }}</span></td>
                <td :data-label="$t('Earned')" class="num"><b>{{ $money(r.amount) }}</b></td>
              </tr>
            </template>
            <template v-else-if="filters.report_type === 'attendance'">
              <tr v-for="(r, i) in visibleRows" :key="r.id">
                <td :data-label="$t('No.')" class="lr-muted">{{ i + 1 }}</td>
                <td :data-label="$t('Date')">{{ $date(r.date) }}</td>
                <td :data-label="$t('Personnel')"><span class="lg-main">{{ r.name }}</span></td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="r.status === 'Present' ? 'lg-chip-green' : 'lg-chip-red'" style="text-transform: none">{{ $t(r.status) }}</span></td>
                <td :data-label="$t('Project / site')">{{ r.project || '-' }}</td>
                <td :data-label="$t('Daily Rate')" class="num">
                  <span v-if="r.custom" class="lg-tip lr-custom" :data-tip="$t('Custom rate: :rate was used for this day instead of the standard :std.', { rate: $money(r.rate), std: $money(r.standard) })">{{ $money(r.rate) }}<icons name="exclamation" class="w-3 h-3 inline-flex lr-custom-ico" /></span>
                  <template v-else>{{ $money(r.rate) }}</template>
                </td>
                <td :data-label="$t('Earned')" class="num"><b>{{ $money(r.amount) }}</b></td>
              </tr>
            </template>
            <template v-else>
              <tr v-for="(r, i) in visibleRows" :key="r.id">
                <td :data-label="$t('No.')" class="lr-muted">{{ i + 1 }}</td>
                <td :data-label="$t('Date')">{{ $date(r.date) }}</td>
                <td :data-label="$t('Personnel')"><span class="lg-main">{{ r.name }}</span></td>
                <td :data-label="$t('Amount')" class="num"><b>{{ $money(r.amount) }}</b></td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="r.status === 'Paid' ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">{{ r.status === 'Paid' ? $t('Paid') : $t('Waiting on voucher') }}</span></td>
                <td :data-label="$t('Method / reference')">{{ r.method || '-' }}<div v-if="r.reference" class="lg-subtext">{{ r.reference }}</div></td>
                <td :data-label="$t('Payment Voucher')"><a v-if="r.voucher" :href="route('payment-vouchers.show', r.voucher_id)" target="_blank" rel="noopener" class="lr-pv">{{ r.voucher }}</a><template v-else>-</template></td>
              </tr>
            </template>
            <tr v-if="!rows.length" class="lg-empty-row">
              <td class="lg-empty" colspan="8"><icons name="chart-bar" class="w-8 h-8 mx-auto mb-2 opacity-50" />{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
          <tfoot v-if="filters.report_type === 'summary' && rows.length">
            <tr class="lr-foot">
              <td></td><td><b>{{ $t('Total') }}</b></td>
              <td class="num"><b>{{ $money(totals.opening) }}</b></td><td class="num"><b>{{ totals.days }} {{ $t('Day(s)') }}</b></td><td class="num"><b>{{ $money(totals.earned) }}</b></td><td class="num"><b>{{ $money(totals.paid) }}</b></td>
              <td class="num"><b>{{ $money(totals.pending) }}</b></td><td class="num"><b>{{ $money(totals.balance) }}</b></td>
            </tr>
          </tfoot>
        </table>
        <div v-if="rows.length > limit" class="lr-more print:hidden">
          <button type="button" class="lg-btn lg-btn-soft" @click="showAll = !showAll">
            <icons name="chevron-down" class="w-4 h-4" :class="{ 'lr-up': showAll }" />{{ showAll ? $t('Show less') : $t('View More') + ' (' + (rows.length - limit) + ')' }}
          </button>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { Link, AdminLayout, PageHeader, StatTiles, DatePicker, AutoComplete },

  props: { filters: Object, rows: Array, totals: Object, cards: Array, rate: Number, workers: Array, projects: Array, places: { type: Array, default: () => [] }, can: Object },

  data() {
    const f = this.filters;
    return {
      form: { report_type: f.report_type, date_from: f.date_from, date_to: f.date_to, contact_id: f.contact_id || null, warehouse_id: f.warehouse_id || null, place: f.place || null, status: f.status || null, custom_rate: !!f.custom_rate, only_owing: !!f.only_owing },
      sort: { key: null, dir: 'asc' },
      limit: 15,
      showAll: false,
    };
  },

  computed: {
    typeOptions() {
      return [
        { value: 'summary', label: this.$t('Summary by personnel') },
        { value: 'attendance', label: this.$t('Daily attendance') },
        { value: 'payments', label: this.$t('Payments') },
        { value: 'sheet', label: this.$t('Daily attendance sheet (one day, to print and sign)') },
      ];
    },
    typeLabel() { return this.typeOptions.find(o => o.value === this.filters.report_type)?.label; },
    workerOptions() { return this.workers.map(w => ({ label: w.name, value: w.id })); },
    projectOptions() { return this.projects.map(p => ({ label: p.name, value: p.id })); },
    placeOptions() { return this.places.map(p => ({ label: p, value: p })); },
    statusOptions() {
      if (this.form.report_type === 'attendance') return [{ value: 'Present', label: this.$t('Present') }, { value: 'Absent', label: this.$t('Absent') }];
      if (this.form.report_type === 'payments') return [{ value: 'Paid', label: this.$t('Paid') }, { value: 'Pending Voucher', label: this.$t('Waiting on voucher') }];
      return [];
    },
    tiles() {
      return this.cards.map(c => ({ icon: c.icon, tone: c.tone, label: this.$t(c.label), value: c.money ? this.$money(c.value) : c.value }));
    },
    sortedRows() {
      const { key, dir } = this.sort;
      if (!key) return this.rows;
      const m = dir === 'asc' ? 1 : -1;
      return [...this.rows].sort((a, b) => (typeof a[key] === 'string' ? String(a[key]).localeCompare(String(b[key])) : Number(a[key]) - Number(b[key])) * m);
    },
    visibleRows() { return this.showAll ? this.sortedRows : this.sortedRows.slice(0, this.limit); },
    query() {
      const q = pickBy({ ...this.form, custom_rate: this.form.custom_rate ? 1 : '', only_owing: this.form.only_owing ? 1 : '' }, v => v !== '' && v !== null && v !== undefined);
      return q;
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.get(this.route('transport-loading.report'), this.query, { preserveState: true, preserveScroll: true, replace: true });
      }, 300),
      deep: true,
    },
    'form.report_type'() {
      this.form.status = null;
      this.sort = { key: null, dir: 'asc' };
    },
    rows() { this.showAll = false; },
  },

  methods: {
    url(kind) {
      return this.route('transport-loading.report.' + kind, this.query);
    },
    sortBy(key) {
      this.sort = this.sort.key === key ? { key, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: key === 'name' || key === 'date' || key === 'status' ? 'asc' : 'desc' };
    },
    caret(key) { return this.sort.key === key ? (this.sort.dir === 'asc' ? '▲' : '▼') : ''; },
    reset() {
      const today = new Date().toLocaleDateString('en-CA');
      this.form = { report_type: this.form.report_type, date_from: today.slice(0, 8) + '01', date_to: today, contact_id: null, warehouse_id: null, place: null, status: null, custom_rate: false, only_owing: false };
    },
  },
};
</script>

<style scoped>
.lr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 10px 14px; align-items: start; }
.lr-switches { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; min-height: 43px; padding-top: 22px; }
.lr-check { display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 700; color: #475569; }
.lr-tiles { grid-template-columns: repeat(auto-fit, minmax(min(215px, 100%), 1fr)); margin-bottom: 0.75rem; }
.lr-tiles :deep(.lg-stat) { background: linear-gradient(135deg, rgba(245, 158, 11, 0.18), rgba(79, 70, 229, 0.10) 65%, rgba(255, 255, 255, 0.55)); border-color: rgba(245, 158, 11, 0.4); }
.lr-tiles :deep(.lg-stat-value) { color: #7c2d12; }
html.dark .lr-tiles :deep(.lg-stat) { background: linear-gradient(135deg, rgba(245, 158, 11, 0.28), rgba(79, 70, 229, 0.2) 70%, rgba(15, 23, 42, 0.6)); }
html.dark .lr-tiles :deep(.lg-stat-value) { color: #f8fafc; }
.lr-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 0.5rem 0 0.75rem; }
.lr-toolbar-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
.lr-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.lr-muted { color: var(--lg-faint, #94a3b8); font-weight: 800; width: 1%; }
.lr-owed { color: #b45309; }
.lr-pv { color: #1d4ed8; font-weight: 700; text-decoration: underline dotted; text-underline-offset: 2px; }
.lr-custom { color: #92400e; font-weight: 800; padding: 2px 8px; border-radius: 8px; background: rgba(245, 158, 11, 0.16); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.25); cursor: help; }
.lr-custom-ico { color: #d97706; margin-left: 4px; }
.lr-neg { color: #dc2626; font-weight: 800; }
.lr-foot td { border-top: 2px solid #5f1414; background: #7f1d1d; color: #fff; font-weight: 800; }
.lr-foot td b { color: #fff; font-weight: 800; }
.lr-more { display: flex; justify-content: center; padding: 12px; }
.lr-up { transform: rotate(180deg); }
</style>
