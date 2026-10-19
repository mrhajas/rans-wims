<template>
  <admin-layout :title="$t('Timely Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="calendar" :title="$t('Timely Reports')" :subtitle="$t('Every vehicle, its trips, totals, and outstanding balance for a chosen period.')" />

      <div class="lg-card relative z-20">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Period') }}</h2>
          </div>
        </header>
        <div class="lg-fields ttr-period-grid">
          <div><date-picker class="w-full" :label="$t('Date From')" v-model="form.date_from" /></div>
          <div><date-picker class="w-full" :label="$t('Date To')" v-model="form.date_to" /></div>
          <div class="ttr-period-btn">
            <button type="button" class="lg-btn lg-btn-soft w-full" @click="thisWeek">
              <icons name="refresh" class="w-4 h-4" /> {{ $t('This Week') }}
            </button>
          </div>
        </div>
      </div>

      <stat-tiles class="ttr-tiles ttr-tiles-routes" :items="tileItems" @select="onTile" />
      <stat-tiles v-if="loadingTileItems.length" class="ttr-tiles ttr-tiles-loading" :items="loadingTileItems" @select="onTile" />

      <div ref="carsSection" class="ttr-toolbar ttr-anchor">
        <div class="ttr-toolbar-title">
          <span class="lg-card-ico lg-ico-slate"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('All Cars') }}</h2>
            <p class="lg-card-sub">{{ filteredVehicles.length }} {{ $t('vehicle(s)') }}</p>
          </div>
        </div>
        <div class="ttr-toolbar-actions print:hidden">
          <Link v-if="canLoadingReport" :href="loadingReportUrl" class="lg-btn lg-btn-soft">
            <icons name="users" class="w-4 h-4" /> {{ $t('Loading & Unloading Report') }}
          </Link>
          <button type="button" class="lg-btn lg-btn-soft" @click="expandAll">
            <icons name="chevron-down" class="w-3.5 h-3.5" /> {{ $t('Expand All') }}
          </button>
          <button type="button" class="lg-btn lg-btn-soft" @click="collapseAll">
            <icons name="chevron-down" class="w-3.5 h-3.5 ttr-icon-up" /> {{ $t('Collapse All') }}
          </button>
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

      <div class="ttr-search-bar mb-4">
        <icons name="search" class="w-4 h-4 ttr-search-icon" />
        <input
          type="text"
          v-model="search"
          class="ttr-search-input"
          :placeholder="$t('Search by vehicle, trip #, driver, destination, or status…')"
        />
        <button v-if="search" type="button" class="ttr-search-clear" @click="search = ''" :aria-label="$t('Clear search')">
          <icons name="cross" class="w-3.5 h-3.5" />
        </button>
      </div>

      <div>
        <section v-for="vehicle in visibleVehicles" :key="vehicle.vehicle?.id" class="lg-card ttr-vehicle-card">
          <div class="ttr-vehicle-header" @click="toggleCollapse(vehicle)">
            <span class="lg-card-ico lg-ico-navy"><icons name="truck" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1 ttr-vehicle-id">
              <div class="ttr-vehicle-name">{{ vehicle.vehicle?.registration_number || vehicle.vehicle?.name }}</div>
              <div class="ttr-vehicle-sub" v-if="vehicle.vehicle?.registration_number">{{ vehicle.vehicle.name }}</div>
            </div>
            <div class="ttr-vehicle-chips">
              <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ vehicle.stats.total_trips }} {{ $t('trip(s)') }}</span>
              <span class="lg-chip lg-chip-violet" style="text-transform: none">{{ $money(vehicle.stats.total_amount) }}</span>
              <span class="lg-chip" :class="vehicle.stats.total_outstanding > 0 ? 'lg-chip-amber' : 'lg-chip-green'" style="text-transform: none">
                {{ $t('Outstanding') }}: {{ $money(vehicle.stats.total_outstanding) }}
              </span>
            </div>
            <button type="button" class="lg-btn lg-btn-soft ttr-collapse-btn" @click.stop="toggleCollapse(vehicle)">
              {{ isCollapsed(vehicle) ? $t('Show') : $t('Collapse') }}
              <icons name="chevron-down" class="w-3.5 h-3.5 inline-flex tr-activity-toggle-icon" :class="{ 'tr-activity-toggle-icon-up': !isCollapsed(vehicle) }" />
            </button>
          </div>

          <div v-show="!isCollapsed(vehicle)" class="ttr-vehicle-body">
            <div class="ttr-stat-strip">
              <div class="ttr-stat-strip-item">
                <span class="ttr-stat-strip-label">{{ $t('Total Amount') }}</span>
                <span class="ttr-stat-strip-value">{{ $money(vehicle.stats.total_amount) }}</span>
              </div>
              <div class="ttr-stat-strip-item">
                <span class="ttr-stat-strip-label">{{ $t('Total Paid') }}</span>
                <span class="ttr-stat-strip-value ttr-stat-strip-value-green">{{ $money(vehicle.stats.total_paid) }}</span>
              </div>
              <div class="ttr-stat-strip-item">
                <span class="ttr-stat-strip-label">{{ $t('Total Outstanding') }}</span>
                <span class="ttr-stat-strip-value ttr-stat-strip-value-amber">{{ $money(vehicle.stats.total_outstanding) }}</span>
              </div>
            </div>

            <div v-if="vehicle.trips.length === 0" class="lg-note lg-note-blue">
              <icons name="exclamation" class="w-4 h-4" />
              <div>{{ $t('No trips recorded for this vehicle in the selected period.') }}</div>
            </div>
            <div v-else class="lg-table-wrap">
              <table class="lg-table lg-responsive">
                <thead>
                  <tr>
                    <th class="w-12">{{ $t('No.') }}</th>
                    <th class="is-sortable" @click="toggleSort(vehicle, 'trip_number')"><span class="lg-sort">{{ $t('Trip #') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'trip_number') }}</span></span></th>
                    <th class="is-sortable" @click="toggleSort(vehicle, 'date')"><span class="lg-sort">{{ $t('Date') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'date') }}</span></span></th>
                    <th class="is-sortable" @click="toggleSort(vehicle, 'driver')"><span class="lg-sort">{{ $t('Driver') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'driver') }}</span></span></th>
                    <th class="is-sortable" @click="toggleSort(vehicle, 'destination')"><span class="lg-sort">{{ $t('Destination') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'destination') }}</span></span></th>
                    <th class="is-sortable num" @click="toggleSort(vehicle, 'amount')"><span class="lg-sort">{{ $t('Amount') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'amount') }}</span></span></th>
                    <th class="is-sortable num" @click="toggleSort(vehicle, 'paid')"><span class="lg-sort">{{ $t('Paid') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'paid') }}</span></span></th>
                    <th class="is-sortable" @click="toggleSort(vehicle, 'status')"><span class="lg-sort">{{ $t('Status') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'status') }}</span></span></th>
                    <th class="is-sortable" @click="toggleSort(vehicle, 'verified')"><span class="lg-sort">{{ $t('Verified') }}<span class="tr-sort-caret">{{ sortCaret(vehicle, 'verified') }}</span></span></th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(trip, index) in visibleTrips(vehicle)"
                    :key="rowVersion + '-' + trip.id"
                    class="is-click tr-row-in"
                    :style="{ animationDelay: Math.min(index * 25, 400) + 'ms' }"
                    @click="goto(trip.id)"
                  >
                    <td :data-label="$t('No.')" class="ttr-muted tm-tab">{{ index + 1 }}</td>
                    <td :data-label="$t('Trip #')"><span class="lg-main">{{ trip.trip_number }}</span></td>
                    <td :data-label="$t('Date')" class="tm-tab">{{ $date(trip.trip_date) }}</td>
                    <td :data-label="$t('Driver')">{{ trip.driver?.name || '-' }}</td>
                    <td :data-label="$t('Destination')">{{ trip.destination_label || '-' }}</td>
                    <td :data-label="$t('Amount')" class="num"><span class="lg-main">{{ $money(trip.amount) }}</span></td>
                    <td :data-label="$t('Paid')" class="num">{{ $money(trip.paid_amount) }}</td>
                    <td :data-label="$t('Status')">
                      <span class="lg-chip" :class="'tr-status-' + String(trip.payment_status).toLowerCase().replace(/\s+/g, '-')" style="text-transform: none">
                        {{ $t(trip.payment_status) }}
                      </span>
                    </td>
                    <td :data-label="$t('Verified')">
                      <span v-if="trip.verified_at" class="lg-chip lg-chip-green lg-tip tip-left" :data-tip="$date(trip.verified_at)" style="text-transform: none">
                        <icons name="tick" class="w-3 h-3 inline-flex" /> {{ $t('Verified') }}
                      </span>
                      <span v-else class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Unverified') }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div v-if="vehicle.trips.length > limit" class="ttr-more print:hidden">
                <button type="button" class="lg-btn lg-btn-soft" @click="toggleTrips(vehicle)">
                  <icons name="chevron-down" class="w-4 h-4" :class="{ 'ttr-icon-up': tripsOpen[vehicle.vehicle?.id] }" />
                  {{ tripsOpen[vehicle.vehicle?.id] ? $t('Show less') : $t('View More') + ' (' + (vehicle.trips.length - limit) + ')' }}
                </button>
              </div>
            </div>
          </div>
        </section>

        <div v-if="filteredVehicles.length > limit" class="ttr-more print:hidden">
          <button type="button" class="lg-btn lg-btn-soft" @click="showAllVehicles = !showAllVehicles">
            <icons name="chevron-down" class="w-4 h-4" :class="{ 'ttr-icon-up': showAllVehicles }" />
            {{ showAllVehicles ? $t('Show less') : $t('View More') + ' (' + (filteredVehicles.length - limit) + ' ' + $t('vehicle(s)') + ')' }}
          </button>
        </div>

        <div v-if="filteredVehicles.length === 0" class="lg-card lg-empty">
          <icons name="truck" class="w-8 h-8 mx-auto mb-2 opacity-50" />
          {{ vehicles.length === 0 ? $t('There is no data to display.') : $t('No vehicles match your search.') }}
        </div>
      </div>

      <section v-if="loading" ref="luSection" class="lg-card ttr-loading ttr-anchor">
        <div class="ttr-toolbar-title mb-3">
          <span class="lg-card-ico lg-ico-amber"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Loading & Unloading') }}</h2>
            <p class="lg-card-sub">{{ loading.rows.length }} {{ $t('worker(s)') }} · {{ $t('daily rate') }} {{ $money(loading.rate) }}</p>
          </div>
        </div>
        <div class="lg-table-wrap">
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
                <td :data-label="$t('No.')" class="ttr-muted tm-tab">{{ index + 1 }}</td>
                <td :data-label="$t('Worker')"><span class="lg-main">{{ w.name }}</span><div class="lg-subtext">{{ w.phone || '' }}</div></td>
                <td :data-label="$t('Previous balance')" class="num"><span :class="{ 'rp-neg': w.opening < 0 }">{{ w.opening ? $money(w.opening) : '-' }}</span></td>
                <td :data-label="$t('Days')" class="num">{{ w.days }}</td>
                <td :data-label="$t('Earned')" class="num"><span class="lg-main">{{ $money(w.earned) }}</span></td>
                <td :data-label="$t('Paid')" class="num">{{ $money(w.paid) }}</td>
                <td :data-label="$t('Balance')" class="num"><b :class="{ 'ttr-owed': w.balance > 0 }">{{ $money(w.balance) }}</b></td>
              </tr>
              <tr v-if="!loading.rows.length" class="lg-empty-row">
                <td class="lg-empty" colspan="7">
                  <icons name="users" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                  {{ $t('No loading or unloading work recorded for this period.') }}
                </td>
              </tr>
            </tbody>
            <tfoot v-if="loading.rows.length">
              <tr class="ttr-foot">
                <td></td><td><b>{{ $t('Total') }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.opening) }}</b></td>
                <td class="num"><b>{{ loading.totals.days }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.earned) }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.paid) }}</b></td>
                <td class="num"><b>{{ $money(loading.totals.balance) }}</b></td>
              </tr>
            </tfoot>
          </table>
          <div v-if="loading.rows.length > limit" class="ttr-more print:hidden">
            <button type="button" class="lg-btn lg-btn-soft" @click="showAllLoading = !showAllLoading">
              <icons name="chevron-down" class="w-4 h-4" :class="{ 'ttr-icon-up': showAllLoading }" />
              {{ showAllLoading ? $t('Show less') : $t('View More') + ' (' + (loading.rows.length - limit) + ')' }}
            </button>
          </div>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { Link, AdminLayout, DatePicker, PageHeader, StatTiles },

  props: {
    filters: Object,
    vehicles: Array,
    totals: Object,
    loading: { type: Object, default: null },
    canLoadingReport: { type: Boolean, default: false },
  },

  data() {
    return {
      form: {
        date_from: this.filters.date_from || '',
        date_to: this.filters.date_to || '',
      },
      // Explicit per-vehicle overrides only — anything not in here falls
      // back to defaultCollapsed() below (collapsed when a vehicle made no
      // trips this period, expanded otherwise), see isCollapsed().
      collapsed: {},
      sort: {},
      // Bumped on every sort so the row `:key` changes and the tr-row-in
      // entrance animation replays — same convention as Reports/Index.vue.
      rowVersion: 0,
      // Client-side only — filters the already-loaded vehicles/trips for
      // this date range, same way sort works on this page. Never re-queries
      // the server, so it stays instant regardless of how many vehicles the
      // period has.
      search: '',
      // Each section shows its first 15 records; View More reveals the rest.
      limit: 15,
      showAllVehicles: false,
      showAllLoading: false,
      tripsOpen: {},
      fleetSort: { key: null, dir: 'desc' },
      loadingSort: { key: null, dir: 'desc' },
    };
  },

  computed: {
    filteredVehicles() {
      const q = this.search.trim().toLowerCase();
      if (!q) return this.vehicles;

      return this.vehicles.filter((vehicle) => {
        const vehicleFields = [vehicle.vehicle?.registration_number, vehicle.vehicle?.name, vehicle.vehicle?.asset_number];
        if (vehicleFields.filter(Boolean).some((f) => f.toLowerCase().includes(q))) {
          return true;
        }

        return vehicle.trips.some((trip) => {
          const tripFields = [trip.trip_number, trip.driver?.name, trip.destination_label, trip.payment_status];
          return tripFields.filter(Boolean).some((f) => String(f).toLowerCase().includes(q));
        });
      });
    },
    orderedVehicles() {
      const { key, dir } = this.fleetSort;
      if (!key) return this.filteredVehicles;
      const m = dir === 'asc' ? 1 : -1;
      return [...this.filteredVehicles].sort((a, b) => (key === 'name'
        ? String(a.vehicle?.registration_number || a.vehicle?.name).localeCompare(String(b.vehicle?.registration_number || b.vehicle?.name))
        : Number(a.stats[key]) - Number(b.stats[key])) * m);
    },
    visibleVehicles() {
      // A search always shows every match; otherwise the first 15 vehicles until View More.
      return this.showAllVehicles || this.search.trim() ? this.orderedVehicles : this.orderedVehicles.slice(0, this.limit);
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
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    // The Loading & Unloading report opens for the same period as this page.
    loadingReportUrl() {
      return this.route('transport-loading.report', pickBy({ date_from: this.form.date_from, date_to: this.form.date_to }));
    },
    printUrl() {
      return this.route('transport-reports.timely.print') + this.queryString;
    },
    pdfUrl() {
      return this.route('transport-reports.timely.pdf') + this.queryString;
    },
    exportUrl() {
      return this.route('transport-reports.timely.export') + this.queryString;
    },
    // Two lines of cards: the trip money first, then the Loading & Unloading money.
    tileItems() {
      return this.statTiles.filter((t) => !t.key.startsWith('lu_')).map((t) => this.asTile(t));
    },
    loadingTileItems() {
      return this.statTiles.filter((t) => t.key.startsWith('lu_')).map((t) => this.asTile(t));
    },
    statTiles() {
      return [
        { key: 'vehicles', label: 'Total Vehicles', value: this.totals.total_vehicles, icon: 'truck', color: 'blue', glow: '96, 165, 250' },
        { key: 'trips', label: 'Total Trips', value: this.totals.total_trips, icon: 'calendar', color: 'indigo', glow: '129, 140, 248' },
        { key: 'amount', label: 'Total Routes Amount', value: this.$money(this.totals.total_amount), icon: 'cash', color: 'violet', glow: '167, 139, 250' },
        { key: 'paid', label: 'Total Paid', value: this.$money(this.totals.total_paid), icon: 'tick', color: 'emerald', glow: '52, 211, 153' },
        { key: 'outstanding', label: 'Total Outstanding', value: this.$money(this.totals.total_outstanding), icon: 'exclamation', color: 'amber', glow: '251, 191, 36' },
        ...(this.loading
          ? [
              { key: 'lu_total', label: 'Loading & Unloading Total', value: this.$money(this.loading.totals.earned), icon: 'users', color: 'indigo', glow: '129, 140, 248' },
              { key: 'lu_paid', label: 'Loading & Unloading Paid', value: this.$money(this.loading.totals.paid), icon: 'tick', color: 'emerald', glow: '52, 211, 153' },
              { key: 'lu_balance', label: 'Loading & Unloading Balance', value: this.$money(this.loading.totals.balance), icon: 'exclamation', color: 'amber', glow: '251, 191, 36' },
            ]
          : []),
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('transport-reports.timely', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
    vehicles() {
      this.rowVersion++;
      this.showAllVehicles = false;
      this.tripsOpen = {};
    },
    loading() {
      this.showAllLoading = false;
    },
  },

  methods: {
    // A card is a button: it sorts the list it belongs to by its own figure (click again to flip the order) and scrolls there.
    asTile(t) {
      const loading = t.key.startsWith('lu_');
      const sortKey = loading ? this.loadingTileKey(t.key) : this.vehicleTileKey(t.key);
      const current = loading ? this.loadingSort.key : this.fleetSort.key;
      return { key: t.key, group: loading ? 'loading' : 'cars', icon: t.icon, tone: t.color === 'blue' ? 'sky' : t.color, label: this.$t(t.label), value: t.value, active: !!sortKey && current === sortKey };
    },
    vehicleTileKey(key) {
      return { vehicles: 'name', trips: 'total_trips', amount: 'total_amount', paid: 'total_paid', outstanding: 'total_outstanding' }[key] || null;
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
      const key = this.vehicleTileKey(item.key);
      if (key) {
        this.fleetSort = this.fleetSort.key === key ? { key, dir: this.fleetSort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: key === 'name' ? 'asc' : 'desc' };
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
    visibleTrips(vehicle) {
      const all = this.sortedTrips(vehicle);
      return this.tripsOpen[vehicle.vehicle?.id] ? all : all.slice(0, this.limit);
    },
    toggleTrips(vehicle) {
      this.tripsOpen = { ...this.tripsOpen, [vehicle.vehicle?.id]: !this.tripsOpen[vehicle.vehicle?.id] };
    },
    thisWeek() {
      // Matches the controller's own default (now()->startOfWeek()/endOfWeek()) —
      // computed client-side purely so the pickers reflect it instantly.
      const now = new Date();
      const day = (now.getDay() + 6) % 7; // 0 = Monday
      const monday = new Date(now);
      monday.setDate(now.getDate() - day);
      const sunday = new Date(monday);
      sunday.setDate(monday.getDate() + 6);
      const fmt = (d) => d.toISOString().slice(0, 10);
      this.form.date_from = fmt(monday);
      this.form.date_to = fmt(sunday);
    },
    defaultCollapsed(vehicle) {
      return vehicle.stats.total_trips === 0;
    },
    isCollapsed(vehicle) {
      // Plain property read, not hasOwnProperty() — Vue 3's reactive proxy
      // only tracks this as a dependency through the `get` trap, which a
      // hasOwnProperty() check bypasses. On first render every vehicle's id
      // is absent from `collapsed`, so that check short-circuits to
      // defaultCollapsed() without ever touching `this.collapsed[id]` —
      // meaning this render never subscribes to `collapsed` at all, and
      // later mutations (toggleCollapse/expandAll/collapseAll) silently
      // fail to trigger a re-render. Reading the value directly (undefined
      // when absent) always goes through `get`, so it's tracked correctly.
      const value = this.collapsed[vehicle.vehicle?.id];
      return value !== undefined ? value : this.defaultCollapsed(vehicle);
    },
    toggleCollapse(vehicle) {
      this.collapsed[vehicle.vehicle?.id] = !this.isCollapsed(vehicle);
    },
    expandAll() {
      this.filteredVehicles.forEach((v) => { this.collapsed[v.vehicle.id] = false; });
    },
    collapseAll() {
      this.filteredVehicles.forEach((v) => { this.collapsed[v.vehicle.id] = true; });
    },
    vehicleSort(vehicle) {
      return this.sort[vehicle.vehicle?.id] || { key: null, dir: 'asc' };
    },
    toggleSort(vehicle, key) {
      const id = vehicle.vehicle?.id;
      const current = this.vehicleSort(vehicle);
      this.sort[id] = current.key === key ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' };
      this.rowVersion++;
    },
    sortCaret(vehicle, key) {
      const s = this.vehicleSort(vehicle);
      if (s.key !== key) return '';
      return s.dir === 'asc' ? '▲' : '▼';
    },
    tripSortValue(trip, key) {
      switch (key) {
        case 'trip_number': return trip.trip_number;
        case 'date': return trip.trip_date;
        case 'driver': return trip.driver?.name ?? null;
        case 'destination': return trip.destination_label ?? null;
        case 'amount': return Number(trip.amount);
        case 'paid': return Number(trip.paid_amount);
        case 'status': return trip.payment_status;
        case 'verified': return trip.verified_at ? 1 : 0;
        default: return null;
      }
    },
    sortedTrips(vehicle) {
      const s = this.vehicleSort(vehicle);
      if (!s.key) return vehicle.trips;
      const dir = s.dir === 'asc' ? 1 : -1;
      return [...vehicle.trips].sort((a, b) => {
        const av = this.tripSortValue(a, s.key);
        const bv = this.tripSortValue(b, s.key);
        if (av == null && bv == null) return 0;
        if (av == null) return 1;
        if (bv == null) return -1;
        if (typeof av === 'string') return av.localeCompare(bv) * dir;
        return (av - bv) * dir;
      });
    },
    goto(id) {
      this.$inertia.visit(this.route('transport-records.show', id));
    },
  },
};
</script>

<style scoped>
.rp-neg { color: #dc2626; font-weight: 800; }
.ttr-more { display: flex; justify-content: center; padding: 12px; }
.ttr-loading { margin-top: 2rem; }
.ttr-anchor { scroll-margin-top: 84px; }
.ttr-tiles-routes :deep(.lg-stat) { background: linear-gradient(135deg, rgba(79, 70, 229, 0.16), rgba(14, 165, 233, 0.10) 60%, rgba(255, 255, 255, 0.55)); border-color: rgba(99, 102, 241, 0.38); }
.ttr-tiles-routes :deep(.lg-stat-ico) { background: linear-gradient(135deg, #4f46e5, #0ea5e9); box-shadow: 0 10px 18px -10px rgba(79, 70, 229, 0.8); }
.ttr-tiles-routes :deep(.lg-stat-value) { color: #312e81; }
.ttr-tiles-loading :deep(.lg-stat) { background: linear-gradient(135deg, rgba(245, 158, 11, 0.20), rgba(244, 63, 94, 0.11) 60%, rgba(255, 255, 255, 0.55)); border-color: rgba(245, 158, 11, 0.45); }
.ttr-tiles-loading :deep(.lg-stat-ico) { background: linear-gradient(135deg, #f59e0b, #e11d48); box-shadow: 0 10px 18px -10px rgba(225, 29, 72, 0.7); }
.ttr-tiles-loading :deep(.lg-stat-value) { color: #9a3412; }
html.dark .ttr-tiles-routes :deep(.lg-stat) { background: linear-gradient(135deg, rgba(79, 70, 229, 0.30), rgba(14, 165, 233, 0.16) 70%, rgba(15, 23, 42, 0.6)); }
html.dark .ttr-tiles-loading :deep(.lg-stat) { background: linear-gradient(135deg, rgba(245, 158, 11, 0.30), rgba(244, 63, 94, 0.18) 70%, rgba(15, 23, 42, 0.6)); }
html.dark .ttr-tiles-routes :deep(.lg-stat-value), html.dark .ttr-tiles-loading :deep(.lg-stat-value) { color: #f8fafc; }
.ttr-tiles-routes :deep(.lg-stat.is-active), .ttr-tiles-loading :deep(.lg-stat.is-active) { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.35), var(--lg-shadow); }
/* Cards wide enough for the biggest amount, so every value fits on its own line */
.ttr-tiles { grid-template-columns: repeat(auto-fit, minmax(min(215px, 100%), 1fr)); margin-bottom: 0.75rem; }
.ttr-owed { color: #b45309; }
.ttr-foot td { border-top: 2px solid var(--lg-line, #e3e8f0); background: var(--lg-soft, #f4f6fb); }
.tm-tab { font-variant-numeric: tabular-nums; }
.ttr-period-grid { grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); align-items: end; }
.ttr-period-btn { display: flex; align-items: flex-end; }
.ttr-period-btn .lg-btn { min-height: 42px; }

.ttr-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 0.9rem; }
.ttr-toolbar-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
.ttr-toolbar-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.ttr-icon-up { transform: rotate(180deg); }

.ttr-search-bar {
  display: flex; align-items: center; gap: 0.5rem; padding: 0 0.9rem; height: 2.75rem; border-radius: 9999px;
  background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.ttr-search-bar:focus-within { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); }
.ttr-search-icon { flex: none; color: var(--lg-faint); }
.ttr-search-input { flex: 1; min-width: 0; border: none; background: transparent; font-size: 0.85rem; color: var(--lg-ink); outline: none; box-shadow: none; }
.ttr-search-input:focus { outline: none; box-shadow: none; }
.ttr-search-input::placeholder { color: var(--lg-faint); }
.ttr-search-clear {
  flex: none; display: flex; align-items: center; justify-content: center; width: 1.4rem; height: 1.4rem; border-radius: 9999px;
  color: var(--lg-mute); background: var(--lg-soft); transition: background 0.15s ease, color 0.15s ease;
}
.ttr-search-clear:hover { color: var(--lg-ink); }

.tr-sort-caret { display: inline-block; margin-left: 2px; font-size: 0.6rem; color: #818cf8; }
.lg-table th.num .lg-sort { justify-content: flex-end; }
.tr-activity-toggle-icon { transition: transform 0.15s ease; }
.tr-activity-toggle-icon-up { transform: rotate(180deg); }
.tr-row-in { animation: tr-row-in 0.35s cubic-bezier(0.16, 1, 0.3, 1) both; }
@keyframes tr-row-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
.ttr-muted { color: var(--lg-faint); }

/* vehicle cards */
.ttr-vehicle-card { padding: 0; overflow: hidden; }
.ttr-vehicle-header { display: flex; align-items: center; gap: 0.8rem; padding: 0.95rem 1.25rem; cursor: pointer; flex-wrap: wrap; transition: background 0.15s ease; }
.ttr-vehicle-header:hover { background: var(--lg-soft); }
.ttr-vehicle-name { font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); }
.ttr-vehicle-sub { font-size: 0.74rem; color: var(--lg-mute); }
.ttr-vehicle-chips { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
.ttr-collapse-btn { flex: none; }
.ttr-vehicle-body { padding: 0 1.25rem 1.25rem; border-top: 1px solid var(--lg-line); }
.ttr-stat-strip { display: flex; flex-wrap: wrap; gap: 0.6rem 2rem; padding: 0.95rem 0; }
.ttr-stat-strip-item { display: flex; flex-direction: column; gap: 2px; }
.ttr-stat-strip-label { font-size: 0.64rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--lg-faint); }
.ttr-stat-strip-value { font-size: 1rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.ttr-stat-strip-value-green { color: #047857; }
.ttr-stat-strip-value-amber { color: #b45309; }
html.dark .ttr-stat-strip-value-green { color: #6ee7b7; }
html.dark .ttr-stat-strip-value-amber { color: #fcd34d; }

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
  .ttr-vehicle-header { padding: 0.9rem 1rem; }
  .ttr-vehicle-body { padding: 0 1rem 1rem; }
  .ttr-toolbar-actions .lg-btn { flex: 1 1 auto; }
}
@media (prefers-reduced-motion: reduce) { .tr-row-in { animation: none; } }
</style>
