<template>
  <admin-layout :title="$t('Transport Routes Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Transport Routes Reports')" :subtitle="$t('Trips, costs, and payment status across vehicles, drivers, projects, and destinations.')" />

      <div class="lg-card relative z-20">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
          </div>
        </header>

        <div class="mb-4">
          <label class="tr-select-label">{{ $t('Report Type') }}</label>
          <auto-complete id="report_type" class="w-full sm:w-72" :suggestions="reportTypeOptions" v-model="form.report_type" />
        </div>

        <div class="lg-fields rp-filter-grid">
          <div><date-picker class="w-full" :label="$t('Date From')" v-model="form.date_from" /></div>
          <div><date-picker class="w-full" :label="$t('Date To')" v-model="form.date_to" /></div>
          <div><auto-complete id="asset_id" :label="$t('Vehicle')" v-model="form.asset_id" class="w-full" :suggestions="vehicleOptions" clearable /></div>
          <div><auto-complete id="driver_contact_id" :label="$t('Driver')" v-model="form.driver_contact_id" class="w-full" :suggestions="driverOptions" clearable /></div>
          <div><auto-complete id="warehouse_id" :label="$t('Project / Warehouse')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseOptions" clearable /></div>
          <div>
            <label class="tr-select-label">{{ $t('Destination Type') }}</label>
            <auto-complete id="report_destination_type" class="w-full" :suggestions="destinationTypeOptions" v-model="form.destination_type" :placeholder="$t('All Destination Types')" clearable />
          </div>
          <div>
            <label class="tr-select-label">{{ $t('Payment Status') }}</label>
            <auto-complete id="report_payment_status" class="w-full" :suggestions="paymentStatusOptions" v-model="form.payment_status" :placeholder="$t('All Payment Statuses')" clearable />
          </div>
          <div class="rp-reset">
            <button type="button" class="lg-btn lg-btn-soft w-full" @click="reset">
              <icons name="refresh" class="w-4 h-4" /> {{ $t('Reset') }}
            </button>
          </div>
        </div>
      </div>

      <stat-tiles class="rp-tiles rp-tiles-routes" :items="tileItems" @select="onTile" />
      <stat-tiles v-if="extraTileItems.length" class="rp-tiles rp-tiles-routes" :items="extraTileItems" />
      <stat-tiles v-if="loadingTileItems.length" class="rp-tiles rp-tiles-loading" :items="loadingTileItems" @select="onTile" />

      <div ref="carsSection" class="rp-toolbar rp-anchor">
        <div class="rp-toolbar-title">
          <span class="lg-card-ico lg-ico-slate"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('All Cars') }}</h2>
            <p class="lg-card-sub">{{ rows.length }} {{ $t('result(s)') }}</p>
          </div>
        </div>
        <div class="rp-toolbar-actions print:hidden">
          <Link v-if="canLoadingReport" :href="loadingReportUrl" class="lg-btn lg-btn-soft">
            <icons name="users" class="w-4 h-4" /> {{ $t('Loading & Unloading Report') }}
          </Link>
          <a :href="printUrl" target="_blank" class="lg-btn lg-btn-primary">
            <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
          </a>
          <a :href="pdfUrl" class="lg-btn lg-btn-dark">
            <icons name="download" class="w-4 h-4" /> {{ $t('Download PDF') }}
          </a>
          <a :href="exportUrl" class="lg-btn lg-btn-green">
            <icons name="table" class="w-4 h-4" /> {{ $t('Export Excel') }}
          </a>
        </div>
      </div>

      <div v-if="filters.report_type === 'cost_summary'" class="lg-card lg-empty">
        <icons name="chart-bar" class="w-8 h-8 mx-auto mb-2 opacity-50" />
        {{ $t('This report shows totals only, see the summary above.') }}
      </div>

      <div v-else class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <template v-if="isGrouped">
            <thead>
              <tr>
                <th class="w-12">{{ $t('No.') }}</th>
                <th class="is-sortable" @click="toggleSort('group')"><span class="lg-sort">{{ groupColumnLabel }}<span class="tr-sort-caret">{{ sortCaret('group') }}</span></span></th>
                <th class="is-sortable num" @click="toggleSort('trip_count')"><span class="lg-sort">{{ $t('Trip Count') }}<span class="tr-sort-caret">{{ sortCaret('trip_count') }}</span></span></th>
                <th class="is-sortable num" @click="toggleSort('total_amount')"><span class="lg-sort">{{ $t('Total Amount') }}<span class="tr-sort-caret">{{ sortCaret('total_amount') }}</span></span></th>
                <th class="is-sortable num" @click="toggleSort('total_paid')"><span class="lg-sort">{{ $t('Total Paid') }}<span class="tr-sort-caret">{{ sortCaret('total_paid') }}</span></span></th>
                <th class="is-sortable num" @click="toggleSort('outstanding')"><span class="lg-sort">{{ $t('Outstanding') }}<span class="tr-sort-caret">{{ sortCaret('outstanding') }}</span></span></th>
                <th class="rp-share-h">{{ $t('Share of cost') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, index) in visibleRows"
                :key="rowVersion + '-' + index"
                class="tr-row-in"
                :style="{ animationDelay: Math.min(index * 25, 400) + 'ms' }"
              >
                <td :data-label="$t('No.')" class="rp-muted">{{ index + 1 }}</td>
                <td :data-label="groupColumnLabel"><span class="lg-main">{{ groupLabel(row) }}</span></td>
                <td :data-label="$t('Trip Count')" class="num">{{ row.trip_count }}</td>
                <td :data-label="$t('Total Amount')" class="num"><span class="lg-main">{{ $money(row.total_amount) }}</span></td>
                <td :data-label="$t('Total Paid')" class="num">{{ $money(row.total_paid) }}</td>
                <td :data-label="$t('Outstanding')" class="num"><b :class="{ 'rp-owed': outstandingOf(row) > 0 }">{{ $money(outstandingOf(row)) }}</b></td>
                <td :data-label="$t('Share of cost')" class="rp-share"><span class="rp-share-bar"><i :style="{ width: shareOf(row) + '%' }"></i></span><em>{{ shareOf(row) }}%</em></td>
              </tr>
              <tr v-if="rows.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="7">
                  <icons name="chart-bar" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                  {{ $t('There is no data to display.') }}
                </td>
              </tr>
            </tbody>
            <tfoot v-if="rows.length">
              <tr class="rp-foot">
                <td></td><td><b>{{ $t('Total') }}</b></td>
                <td class="num"><b>{{ groupedTotals.trips }}</b></td>
                <td class="num"><b>{{ $money(groupedTotals.amount) }}</b></td>
                <td class="num"><b>{{ $money(groupedTotals.paid) }}</b></td>
                <td class="num"><b>{{ $money(groupedTotals.amount - groupedTotals.paid) }}</b></td>
                <td></td>
              </tr>
            </tfoot>
          </template>
          <template v-else>
            <thead>
              <tr>
                <th class="w-12">{{ $t('No.') }}</th>
                <th class="is-sortable" @click="toggleSort('group')"><span class="lg-sort">{{ groupColumnLabel }}<span class="tr-sort-caret">{{ sortCaret('group') }}</span></span></th>
                <th class="is-sortable" @click="toggleSort('date')"><span class="lg-sort">{{ $t('Date') }}<span class="tr-sort-caret">{{ sortCaret('date') }}</span></span></th>
                <th class="is-sortable" @click="toggleSort('driver')"><span class="lg-sort">{{ $t('Driver') }}<span class="tr-sort-caret">{{ sortCaret('driver') }}</span></span></th>
                <th class="is-sortable" @click="toggleSort('destination')"><span class="lg-sort">{{ $t('Destination') }}<span class="tr-sort-caret">{{ sortCaret('destination') }}</span></span></th>
                <th class="is-sortable num" @click="toggleSort('amount')"><span class="lg-sort">{{ $t('Amount') }}<span class="tr-sort-caret">{{ sortCaret('amount') }}</span></span></th>
                <th class="is-sortable num" @click="toggleSort('paid')"><span class="lg-sort">{{ $t('Paid') }}<span class="tr-sort-caret">{{ sortCaret('paid') }}</span></span></th>
                <th class="is-sortable num" @click="toggleSort('outstanding')"><span class="lg-sort">{{ $t('Outstanding') }}<span class="tr-sort-caret">{{ sortCaret('outstanding') }}</span></span></th>
                <th class="is-sortable" @click="toggleSort('status')"><span class="lg-sort">{{ $t('Status') }}<span class="tr-sort-caret">{{ sortCaret('status') }}</span></span></th>
                <th class="is-sortable" @click="toggleSort('verified')"><span class="lg-sort">{{ $t('Verified') }}<span class="tr-sort-caret">{{ sortCaret('verified') }}</span></span></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, index) in visibleRows"
                :key="rowVersion + '-' + row.id"
                class="is-click tr-row-in"
                :style="{ animationDelay: Math.min(index * 25, 400) + 'ms' }"
                @click="goto(row.id)"
              >
                <td :data-label="$t('No.')" class="rp-muted">{{ index + 1 }}</td>
                <td :data-label="groupColumnLabel">
                  <span class="lg-main">{{ row.trip_number }}</span>
                  <a v-if="row.transfer" :href="route('transfers.show', row.transfer.id)" target="_blank" rel="noopener" class="rp-transfer lg-tip" :data-tip="$t('Generated from Transfer') + ' ' + row.transfer.reference + ' · ' + $t('opens in a new tab')" @click.stop>
                    <icons name="truck" class="w-3 h-3" />{{ row.transfer.reference }}
                  </a>
                </td>
                <td :data-label="$t('Date')" class="rp-tab">{{ $date(row.trip_date) }}</td>
                <td :data-label="$t('Driver')">{{ row.driver?.name || '-' }}</td>
                <td :data-label="$t('Destination')">{{ row.destination_label || '-' }}</td>
                <td :data-label="$t('Amount')" class="num"><span class="lg-main">{{ $money(row.amount) }}</span></td>
                <td :data-label="$t('Paid')" class="num">{{ $money(row.paid_amount) }}</td>
                <td :data-label="$t('Outstanding')" class="num"><b :class="{ 'rp-owed': rowOutstanding(row) > 0 }">{{ $money(rowOutstanding(row)) }}</b></td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="'tr-status-' + String(row.payment_status).toLowerCase().replace(/\s+/g, '-')" style="text-transform: none">
                    {{ $t(row.payment_status) }}
                  </span>
                </td>
                <td :data-label="$t('Verified')">
                  <span v-if="row.verified_at" class="lg-chip lg-chip-green lg-tip tip-left" :data-tip="$date(row.verified_at)" style="text-transform: none">
                    <icons name="tick" class="w-3 h-3 inline-flex" /> {{ $t('Verified') }}
                  </span>
                  <span v-else class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Unverified') }}</span>
                </td>
              </tr>
              <tr v-if="rows.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="10">
                  <icons name="chart-bar" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                  {{ $t('There is no data to display.') }}
                </td>
              </tr>
            </tbody>
            <tfoot v-if="rows.length">
              <tr class="rp-foot">
                <td></td><td colspan="4"><b>{{ $t('Total of the :n trips shown', { n: rows.length }) }}</b></td>
                <td class="num"><b>{{ $money(rawTotals.amount) }}</b></td>
                <td class="num"><b>{{ $money(rawTotals.paid) }}</b></td>
                <td class="num"><b>{{ $money(rawTotals.amount - rawTotals.paid) }}</b></td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </template>
        </table>
        <div v-if="rows.length > limit" class="rp-more print:hidden">
          <button type="button" class="lg-btn lg-btn-soft" @click="showAllTrips = !showAllTrips">
            <icons name="chevron-down" class="w-4 h-4" :class="{ 'rp-up': showAllTrips }" />
            {{ showAllTrips ? $t('Show less') : $t('View More') + ' (' + (rows.length - limit) + ')' }}
          </button>
        </div>
      </div>

      <template v-if="loading">
        <div ref="luSection" class="rp-toolbar rp-section-gap rp-anchor">
          <div class="rp-toolbar-title">
            <span class="lg-card-ico lg-ico-amber"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Loading & Unloading') }}</h2>
              <p class="lg-card-sub">{{ loading.rows.length }} {{ $t('worker(s)') }} · {{ $t('daily rate') }} {{ $money(loading.rate) }}</p>
            </div>
          </div>
        </div>

        <div class="lg-table-wrap -mx-4 md:mx-0">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-12">{{ $t('No.') }}</th>
                <th class="is-sortable" @click="sortLoading('name')"><span class="lg-sort">{{ $t('Worker') }}<span class="tr-sort-caret">{{ loadingCaret('name') }}</span></span></th>
                <th class="is-sortable num" @click="sortLoading('opening')"><span class="lg-sort">{{ $t('Previous balance') }}<span class="tr-sort-caret">{{ loadingCaret('opening') }}</span></span></th>
                <th class="is-sortable num" @click="sortLoading('days')"><span class="lg-sort">{{ $t('Days') }}<span class="tr-sort-caret">{{ loadingCaret('days') }}</span></span></th>
                <th class="is-sortable num" @click="sortLoading('earned')"><span class="lg-sort">{{ $t('Earned') }}<span class="tr-sort-caret">{{ loadingCaret('earned') }}</span></span></th>
                <th class="is-sortable num" @click="sortLoading('paid')"><span class="lg-sort">{{ $t('Paid') }}<span class="tr-sort-caret">{{ loadingCaret('paid') }}</span></span></th>
                <th class="is-sortable num" @click="sortLoading('balance')"><span class="lg-sort">{{ $t('Balance') }}<span class="tr-sort-caret">{{ loadingCaret('balance') }}</span></span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(w, index) in visibleLoading" :key="w.contact_id" class="is-click" @click="$inertia.visit(route('transport-loading.show', w.contact_id))">
                <td :data-label="$t('No.')" class="rp-muted">{{ index + 1 }}</td>
                <td :data-label="$t('Worker')"><span class="lg-main">{{ w.name }}</span><div class="lg-subtext">{{ w.phone || '' }}</div></td>
                <td :data-label="$t('Previous balance')" class="num"><span :class="{ 'rp-neg': w.opening < 0 }">{{ w.opening ? $money(w.opening) : '-' }}</span></td>
                <td :data-label="$t('Days')" class="num">{{ w.days }}</td>
                <td :data-label="$t('Earned')" class="num"><span class="lg-main">{{ $money(w.earned) }}</span></td>
                <td :data-label="$t('Paid')" class="num">{{ $money(w.paid) }}</td>
                <td :data-label="$t('Balance')" class="num"><b :class="{ 'rp-owed': w.balance > 0 }">{{ $money(w.balance) }}</b></td>
              </tr>
              <tr v-if="!loading.rows.length" class="lg-empty-row">
                <td class="lg-empty" colspan="7">
                  <icons name="users" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                  {{ $t('No loading or unloading work recorded for this period.') }}
                </td>
              </tr>
            </tbody>
            <tfoot v-if="loading.rows.length">
              <tr class="rp-foot">
                <td></td><td><b>{{ $t('Total') }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.opening) }}</b></td>
                <td class="num"><b>{{ loading.totals.days }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.earned) }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.paid) }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.balance) }}</b></td>
              </tr>
            </tfoot>
          </table>
          <div v-if="loading.rows.length > limit" class="rp-more print:hidden">
            <button type="button" class="lg-btn lg-btn-soft" @click="showAllLoading = !showAllLoading">
              <icons name="chevron-down" class="w-4 h-4" :class="{ 'rp-up': showAllLoading }" />
              {{ showAllLoading ? $t('Show less') : $t('View More') + ' (' + (loading.rows.length - limit) + ')' }}
            </button>
          </div>
        </div>
      </template>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import { transportTypeLabel } from '@/Core/transportTypes';
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

const REPORT_TYPE_LABELS = {
  records: 'Transport Routes Records',
  daily: 'Daily Transport Routes Report',
  monthly: 'Monthly Transport Routes Report',
  vehicle: 'Vehicle Transport Routes Report',
  driver: 'Driver Transport Routes Report',
  project: 'Project Transport Routes Report',
  warehouse: 'Warehouse Transport Routes Report',
  destination: 'Destination Report',
  paid: 'Paid Transport Routes Report',
  unpaid: 'Unpaid Transport Routes Report',
  outstanding: 'Outstanding Transport Routes Report',
  cost_summary: 'Transport Routes Cost Summary',
  vehicle_activity: 'Vehicle Activity Report',
};

// 'vehicle_activity' is NOT raw — TransportReportController groups it by
// asset_id exactly like 'vehicle' (see the controller's $grouped match) — it
// used to be listed here by mistake, which rendered the raw-trip table
// against grouped {asset_id, trip_count, total_amount, total_paid} rows:
// every trip_number/trip_date/amount/payment_status cell was undefined, row
// :key was undefined for every row, and clicking a row visited
// transport-records/undefined.
const RAW_ROW_TYPES = ['records', 'paid', 'unpaid', 'outstanding'];

export default {
  components: { Link, AdminLayout, AutoComplete, DatePicker, PageHeader, StatTiles },

  props: {
    filters: Object,
    reportTypes: Array,
    rows: Array,
    totals: Object,
    loading: { type: Object, default: null },
    canLoadingReport: { type: Boolean, default: false },
    vehicles: Array,
    drivers: Array,
    warehouses: Array,
    destinationTypes: Array,
    paymentStatuses: Array,
  },

  data() {
    return {
      form: {
        report_type: this.filters.report_type || 'records',
        date_from: this.filters.date_from || '',
        date_to: this.filters.date_to || '',
        asset_id: this.filters.asset_id || null,
        driver_contact_id: this.filters.driver_contact_id || null,
        warehouse_id: this.filters.warehouse_id || null,
        destination_type: this.filters.destination_type || null,
        payment_status: this.filters.payment_status || null,
      },
      sort: { key: null, dir: 'asc' },
      loadingSort: { key: null, dir: 'desc' },
      // Bumped whenever the row set changes shape (new data from the server,
      // or a re-sort reordering it) so the `:key` on each <tr> below changes
      // too — Vue then remounts every row fresh instead of patching existing
      // nodes in place, which is what lets the tr-row-in entrance animation
      // replay as a staggered "filling in" sweep every time, not just once
      // on first page load.
      rowVersion: 0,
      // Each section shows its first 15 records; View More reveals the rest.
      limit: 15,
      showAllTrips: false,
      showAllLoading: false,
    };
  },

  computed: {
    vehicleOptions() {
      return this.vehicles.map(v => ({ label: (v.registration_number ? v.registration_number + ' - ' : '') + v.name, value: v.id }));
    },
    driverOptions() {
      return this.drivers.map(d => ({ label: d.name, value: d.id }));
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ label: w.name, value: w.id }));
    },
    reportTypeOptions() {
      return this.reportTypes.map(rt => ({ value: rt, label: this.reportTypeLabel(rt) }));
    },
    destinationTypeOptions() {
      return this.destinationTypes.map(d => ({ value: d, label: transportTypeLabel(this.$t, d) }));
    },
    paymentStatusOptions() {
      return this.paymentStatuses.map(s => ({ value: s, label: this.$t(s) }));
    },
    isGrouped() {
      return !RAW_ROW_TYPES.includes(this.filters.report_type) && this.filters.report_type !== 'cost_summary';
    },
    groupedTotals() {
      return this.rows.reduce((t, r) => ({ trips: t.trips + Number(r.trip_count || 0), amount: t.amount + Number(r.total_amount || 0), paid: t.paid + Number(r.total_paid || 0) }), { trips: 0, amount: 0, paid: 0 });
    },
    rawTotals() {
      return this.rows.reduce((t, r) => ({ amount: t.amount + Number(r.amount || 0), paid: t.paid + Number(r.paid_amount || 0) }), { amount: 0, paid: 0 });
    },
    groupColumnLabel() {
      switch (this.filters.report_type) {
        case 'vehicle':
        case 'vehicle_activity':
          return this.$t('Vehicle');
        case 'driver':
          return this.$t('Driver');
        case 'project':
        case 'warehouse':
          return this.$t('Project / Warehouse');
        case 'destination':
          return this.$t('Destination');
        case 'daily':
          return this.$t('Date');
        case 'monthly':
          return this.$t('Month');
        default:
          return this.$t('Trip #');
      }
    },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    // Carries the on-screen sort into Print/PDF too — without it, re-sorting
    // a column client-side (sortedRows below) only ever changed what this
    // page renders, while Print/Download kept re-querying the backend's own
    // default order (e.g. Total Amount desc), silently printing something
    // other than what was on screen.
    sortQueryString() {
      if (!this.sort.key) return '';
      const query = pickBy({ ...this.form, sort: this.sort.key, direction: this.sort.dir });
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    // The Loading & Unloading report opens for the same period as this page.
    loadingReportUrl() {
      return this.route('transport-loading.report', pickBy({ date_from: this.form.date_from, date_to: this.form.date_to }));
    },
    printUrl() {
      return this.route('transport-reports.print') + (this.sort.key ? this.sortQueryString : this.queryString);
    },
    pdfUrl() {
      return this.route('transport-reports.pdf') + (this.sort.key ? this.sortQueryString : this.queryString);
    },
    exportUrl() {
      return this.route('transport-reports.export') + this.queryString;
    },
    // Two lines of cards: the trip money first, then the Loading & Unloading money.
    tileItems() {
      return this.statTiles.filter((t) => !t.key.startsWith('lu_') && !t.key.startsWith('x_')).map((t) => this.asTile(t));
    },
    extraTileItems() {
      return this.statTiles.filter((t) => t.key.startsWith('x_')).map((t) => this.asTile(t));
    },
    loadingTileItems() {
      return this.statTiles.filter((t) => t.key.startsWith('lu_')).map((t) => this.asTile(t));
    },
    statTiles() {
      return [
        { key: 'trips', label: 'Total Trips', value: this.totals.total_trips, icon: 'truck', color: 'blue', glow: '96, 165, 250' },
        { key: 'amount', label: 'Total Routes Amount', value: this.$money(this.totals.total_amount), icon: 'cash', color: 'violet', glow: '167, 139, 250' },
        { key: 'paid', label: 'Total Paid', value: this.$money(this.totals.total_paid), icon: 'tick', color: 'emerald', glow: '52, 211, 153' },
        { key: 'outstanding', label: 'Total Outstanding', value: this.$money(this.totals.total_outstanding), icon: 'exclamation', color: 'amber', glow: '251, 191, 36' },
        { key: 'x_pending', label: this.$t('Waiting for Verification') + ' (' + this.totals.pending_records + ')', value: this.$money(this.totals.pending_amount), icon: 'clock', color: 'slate', glow: '148, 163, 184' },
        { key: 'x_transfers', label: this.$t('Generated from Transfers') + ' (' + this.totals.transfer_records + ')', value: this.$money(this.totals.transfer_amount), icon: 'truck', color: 'indigo', glow: '129, 140, 248' },
        ...(this.loading
          ? [
              { key: 'lu_total', label: 'Loading & Unloading Total', value: this.$money(this.loading.totals.earned), icon: 'users', color: 'indigo', glow: '129, 140, 248' },
              { key: 'lu_paid', label: 'Loading & Unloading Paid', value: this.$money(this.loading.totals.paid), icon: 'tick', color: 'emerald', glow: '52, 211, 153' },
              { key: 'lu_balance', label: 'Loading & Unloading Balance', value: this.$money(this.loading.totals.balance), icon: 'exclamation', color: 'amber', glow: '251, 191, 36' },
            ]
          : []),
      ];
    },
    // Client-side only — the report already caps rows server-side (200-500),
    // so a full re-sort on every header click is cheap and avoids a round
    // trip. Sort state is reset by reset()/report_type changes below since
    // the two table shapes (grouped vs raw) have completely different keys.
    visibleRows() {
      return this.showAllTrips ? this.sortedRows : this.sortedRows.slice(0, this.limit);
    },
    sortedLoading() {
      if (!this.loading) return [];
      const { key, dir } = this.loadingSort;
      if (!key) return this.loading.rows;
      const m = dir === 'asc' ? 1 : -1;
      return [...this.loading.rows].sort((a, b) => (key === 'name' ? String(a.name).localeCompare(String(b.name)) : Number(a[key]) - Number(b[key])) * m);
    },
    visibleLoading() {
      return this.showAllLoading ? this.sortedLoading : this.sortedLoading.slice(0, this.limit);
    },
    sortedRows() {
      if (!this.sort.key) return this.rows;
      const dir = this.sort.dir === 'asc' ? 1 : -1;
      return [...this.rows].sort((a, b) => {
        const av = this.sortValue(a, this.sort.key);
        const bv = this.sortValue(b, this.sort.key);
        if (av == null && bv == null) return 0;
        if (av == null) return 1;
        if (bv == null) return -1;
        if (typeof av === 'string') return av.localeCompare(bv) * dir;
        return (av - bv) * dir;
      });
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('transport-reports.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
    'form.report_type'() {
      // The two table shapes (grouped vs raw) use entirely different sort
      // keys — carrying a sort over would silently sort by a column that
      // no longer exists.
      this.sort = { key: null, dir: 'asc' };
    },
    rows() {
      this.rowVersion++;
      this.showAllTrips = false;
    },
    loading() {
      this.showAllLoading = false;
    },
  },

  methods: {
    outstandingOf(row) { return Math.max(0, Number(row.total_amount || 0) - Number(row.total_paid || 0)); },
    rowOutstanding(row) { return row.payment_status === 'Cancelled' ? 0 : Math.max(0, Number(row.amount || 0) - Number(row.paid_amount || 0)); },
    shareOf(row) { const t = this.groupedTotals.amount; return t > 0 ? Math.round((Number(row.total_amount || 0) / t) * 100) : 0; },
    // A card is a button: it sorts the table it belongs to by its own figure (click again to flip the order) and scrolls there.
    asTile(t) {
      const loading = t.key.startsWith('lu_');
      const sortKey = loading ? this.loadingTileKey(t.key) : this.tripTileKey(t.key);
      const current = loading ? this.loadingSort.key : this.sort.key;
      return { key: t.key, group: loading ? 'loading' : 'trips', icon: t.icon, tone: t.color === 'blue' ? 'sky' : t.color, label: t.key.startsWith('x_') ? t.label : this.$t(t.label), value: t.value, active: !!sortKey && current === sortKey };
    },
    tripTileKey(key) {
      if (this.filters.report_type === 'cost_summary') return null;
      const g = this.isGrouped;
      return { trips: g ? 'trip_count' : 'date', amount: g ? 'total_amount' : 'amount', paid: g ? 'total_paid' : 'paid', outstanding: 'outstanding' }[key] || null;
    },
    loadingTileKey(key) {
      return { lu_total: 'earned', lu_paid: 'paid', lu_balance: 'balance' }[key] || null;
    },
    onTile(item) {
      if (item.group === 'loading') {
        const key = this.loadingTileKey(item.key);
        if (key) this.loadingSort = this.loadingSort.key === key ? { key, dir: this.loadingSort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'desc' };
        this.scrollTo('luSection');
        return;
      }
      const key = this.tripTileKey(item.key);
      if (key) {
        this.sort = this.sort.key === key ? { key, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'desc' };
        this.rowVersion++;
      }
      this.scrollTo('carsSection');
    },
    scrollTo(ref) {
      this.$nextTick(() => this.$refs[ref]?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }));
    },
    sortLoading(key) {
      this.loadingSort = this.loadingSort.key === key ? { key, dir: this.loadingSort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: key === 'name' ? 'asc' : 'desc' };
    },
    loadingCaret(key) {
      return this.loadingSort.key === key ? (this.loadingSort.dir === 'asc' ? '▲' : '▼') : '';
    },
    reportTypeLabel(value) {
      return this.$t(REPORT_TYPE_LABELS[value] || value.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()));
    },
    groupLabel(row) {
      switch (this.filters.report_type) {
        case 'vehicle':
        case 'vehicle_activity':
          return row.asset ? (row.asset.registration_number ? row.asset.registration_number + ' - ' : '') + row.asset.name : '—';
        case 'driver':
          return row.driver?.name ?? '—';
        case 'project':
        case 'warehouse':
          return row.warehouse?.name ?? this.$t('Company-wide');
        case 'destination':
          return `${row.destination_label} (${row.destination_type})`;
        case 'daily':
          return this.$date(row.trip_day);
        case 'monthly':
          return row.trip_month;
        default:
          return '—';
      }
    },
    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.report_type = 'records';
      this.form.date_from = '';
      this.form.date_to = '';
      this.sort = { key: null, dir: 'asc' };
    },
    goto(id) {
      this.$inertia.visit(this.route('transport-records.show', id));
    },
    sortValue(row, key) {
      switch (key) {
        case 'group':
          return this.isGrouped ? String(this.groupLabel(row)) : row.trip_number;
        case 'trip_count':
          return Number(row.trip_count);
        case 'total_amount':
          return Number(row.total_amount);
        case 'total_paid':
          return Number(row.total_paid);
        case 'outstanding':
          return this.isGrouped ? Number(row.total_amount) - Number(row.total_paid) : Number(row.outstanding_amount ?? Number(row.amount) - Number(row.paid_amount));
        case 'date':
          return row.trip_date;
        case 'driver':
          return row.driver?.name ?? null;
        case 'destination':
          return row.destination_label ?? null;
        case 'amount':
          return Number(row.amount);
        case 'paid':
          return Number(row.paid_amount);
        case 'status':
          return row.payment_status;
        case 'verified':
          return row.verified_at ? 1 : 0;
        default:
          return null;
      }
    },
    toggleSort(key) {
      if (this.sort.key === key) {
        this.sort.dir = this.sort.dir === 'asc' ? 'desc' : 'asc';
      } else {
        this.sort = { key, dir: 'asc' };
      }
      this.rowVersion++;
    },
    sortCaret(key) {
      if (this.sort.key !== key) return '';
      return this.sort.dir === 'asc' ? '▲' : '▼';
    },
  },
};
</script>

<style scoped>
.rp-neg { color: #dc2626; font-weight: 800; }
.rp-more { display: flex; justify-content: center; padding: 12px; }
.rp-section-gap { margin-top: 2rem; }
.rp-anchor { scroll-margin-top: 84px; }
.rp-tiles-routes :deep(.lg-stat) { background: linear-gradient(135deg, rgba(79, 70, 229, 0.16), rgba(14, 165, 233, 0.10) 60%, rgba(255, 255, 255, 0.55)); border-color: rgba(99, 102, 241, 0.38); }
.rp-tiles-routes :deep(.lg-stat-ico) { background: linear-gradient(135deg, #4f46e5, #0ea5e9); box-shadow: 0 10px 18px -10px rgba(79, 70, 229, 0.8); }
.rp-tiles-routes :deep(.lg-stat-value) { color: #312e81; }
.rp-tiles-loading :deep(.lg-stat) { background: linear-gradient(135deg, rgba(245, 158, 11, 0.20), rgba(244, 63, 94, 0.11) 60%, rgba(255, 255, 255, 0.55)); border-color: rgba(245, 158, 11, 0.45); }
.rp-tiles-loading :deep(.lg-stat-ico) { background: linear-gradient(135deg, #f59e0b, #e11d48); box-shadow: 0 10px 18px -10px rgba(225, 29, 72, 0.7); }
.rp-tiles-loading :deep(.lg-stat-value) { color: #9a3412; }
html.dark .rp-tiles-routes :deep(.lg-stat) { background: linear-gradient(135deg, rgba(79, 70, 229, 0.30), rgba(14, 165, 233, 0.16) 70%, rgba(15, 23, 42, 0.6)); }
html.dark .rp-tiles-loading :deep(.lg-stat) { background: linear-gradient(135deg, rgba(245, 158, 11, 0.30), rgba(244, 63, 94, 0.18) 70%, rgba(15, 23, 42, 0.6)); }
html.dark .rp-tiles-routes :deep(.lg-stat-value), html.dark .rp-tiles-loading :deep(.lg-stat-value) { color: #f8fafc; }
.rp-tiles-routes :deep(.lg-stat.is-active), .rp-tiles-loading :deep(.lg-stat.is-active) { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.35), var(--lg-shadow); }
/* Cards wide enough for the biggest amount, so every value fits on its own line */
.rp-tiles { grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); margin-bottom: 0.75rem; }
.rp-up { transform: rotate(180deg); }
.rp-owed { color: #b45309; }
.rp-share-h { min-width: 8rem; }
.rp-share { display: flex; align-items: center; gap: 8px; min-width: 8rem; }
.rp-share em { font-style: normal; font-size: 0.72rem; font-weight: 700; color: #475569; font-variant-numeric: tabular-nums; }
.rp-share-bar { flex: 1; height: 6px; border-radius: 9999px; background: #e8edf5; overflow: hidden; }
.rp-share-bar i { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #60a5fa, #6366f1); }
.rp-transfer { display: flex; align-items: center; gap: 4px; width: fit-content; margin-top: 3px; padding: 1px 8px; border-radius: 9999px; font-size: 0.66rem; font-weight: 700; color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; text-decoration: none; white-space: nowrap; }
.rp-transfer:hover { background: #bbf7d0; }
.rp-foot td { border-top: 2px solid var(--lg-line, #e3e8f0); background: var(--lg-soft, #f4f6fb); }
.rp-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); align-items: end; }
.rp-reset { display: flex; align-items: flex-end; }
.rp-reset .lg-btn { min-height: 42px; }
.tr-select-label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); margin-bottom: 0.3rem; }

.rp-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 0.9rem; }
.rp-toolbar-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
.rp-toolbar-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.rp-muted { color: var(--lg-faint); font-variant-numeric: tabular-nums; }
.rp-tab { font-variant-numeric: tabular-nums; }

.tr-sort-caret { display: inline-block; margin-left: 2px; font-size: 0.6rem; color: #818cf8; }
.lg-table th.num .lg-sort { justify-content: flex-end; }

/* Staggered per-row reveal, replayed whenever the row set changes (see :key above) */
.tr-row-in { animation: tr-row-in 0.35s cubic-bezier(0.16, 1, 0.3, 1) both; }
@keyframes tr-row-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { .tr-row-in { animation: none; } }

/* payment status chips */
.tr-status-paid { color: #047857; background: rgba(16, 185, 129, 0.14); }
.tr-status-unpaid { color: #be123c; background: rgba(244, 63, 94, 0.13); }
.tr-status-partial, .tr-status-partially-paid { color: #92400e; background: rgba(245, 158, 11, 0.17); }
.tr-status-outstanding { color: #c2410c; background: rgba(249, 115, 22, 0.15); }
.tr-status-pending, .tr-status-pending-payment { color: #1d4ed8; background: rgba(59, 130, 246, 0.13); }
.tr-status-cancelled { color: #475569; background: rgba(100, 116, 139, 0.14); }
html.dark .tr-status-paid { color: #6ee7b7; } html.dark .tr-status-unpaid { color: #fda4af; }
html.dark .tr-status-partial, html.dark .tr-status-partially-paid { color: #fcd34d; }
html.dark .tr-status-outstanding { color: #fdba74; }
html.dark .tr-status-pending, html.dark .tr-status-pending-payment { color: #93c5fd; }
html.dark .tr-status-cancelled { color: #cbd5e1; }

@media (max-width: 767.98px) {
  .rp-toolbar-actions .lg-btn { flex: 1 1 auto; }
}
</style>
