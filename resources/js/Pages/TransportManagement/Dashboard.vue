<template>
  <admin-layout :title="$t('Transport Routes Dashboard')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="truck" :title="$t('Transport Routes Dashboard')" :subtitle="$t('Routes activities, destinations and cost breakdowns for Transport Routes Records.')" />

      <!-- 13 tiles in three tidy lines: fleet & trips (5), money & unpaid (4), verification & destinations (4) -->
      <div v-for="(row, i) in tileRows" :key="i" class="td-row" :style="{ '--td-cols': row.length }">
        <stat-tiles :items="row" @select="(item) => goToCard(item.key)" />
      </div>

      <div class="dash-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="truck" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Most Active Vehicles') }}</h2>
            </div>
          </header>
          <div v-if="mostActiveVehicles.length" class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th class="w-12">{{ $t('No') }}</th>
                  <th>{{ $t('Vehicle') }}</th>
                  <th class="num">{{ $t('Trips') }}</th>
                  <th class="num">{{ $t('Amount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, idx) in mostActiveVehicles" :key="row.asset_id">
                  <td :data-label="$t('No')" class="dash-no">{{ idx + 1 }}</td>
                  <td :data-label="$t('Vehicle')">
                    <span class="lg-main">{{ row.asset ? row.asset.name : $t('Unknown Vehicle') }}</span>
                    <span v-if="row.asset && row.asset.registration_number" class="lg-subtext dash-reg">{{ row.asset.registration_number }}</span>
                  </td>
                  <td :data-label="$t('Trips')" class="num">{{ row.trip_count }}</td>
                  <td :data-label="$t('Amount')" class="num"><span class="lg-main">{{ $money(row.total_amount) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="lg-empty dash-empty">{{ $t('No data yet.') }}</div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="map-pin" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Most Frequent Destinations') }}</h2>
            </div>
          </header>
          <div v-if="mostFrequentDestinations.length" class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th class="w-12">{{ $t('No') }}</th>
                  <th>{{ $t('Destination') }}</th>
                  <th>{{ $t('Type') }}</th>
                  <th class="num">{{ $t('Trips') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in mostFrequentDestinations" :key="row.destination_label + i">
                  <td :data-label="$t('No')" class="dash-no">{{ i + 1 }}</td>
                  <td :data-label="$t('Destination')"><span class="lg-main">{{ row.destination_label }}</span></td>
                  <td :data-label="$t('Type')">
                    <span v-if="row.destination_type" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ $t(row.destination_type) }}</span>
                    <span v-else class="lg-subtext">—</span>
                  </td>
                  <td :data-label="$t('Trips')" class="num">{{ row.trip_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="lg-empty dash-empty">{{ $t('No data yet.') }}</div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="folder" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Cost by Project / Warehouse') }}</h2>
            </div>
          </header>
          <div v-if="costByProjectWarehouse.length" class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th class="w-12">{{ $t('No') }}</th>
                  <th>{{ $t('Project / Warehouse') }}</th>
                  <th class="num">{{ $t('Trips') }}</th>
                  <th class="num">{{ $t('Amount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, idx) in costByProjectWarehouse" :key="row.warehouse_id">
                  <td :data-label="$t('No')" class="dash-no">{{ idx + 1 }}</td>
                  <td :data-label="$t('Project / Warehouse')">
                    <span class="lg-main">{{ row.warehouse ? row.warehouse.name : $t('Unknown') }}</span>
                  </td>
                  <td :data-label="$t('Trips')" class="num">{{ row.trip_count }}</td>
                  <td :data-label="$t('Amount')" class="num"><span class="lg-main">{{ $money(row.total_amount) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="lg-empty dash-empty">{{ $t('No data yet.') }}</div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Drivers Routes Cost') }}</h2>
            </div>
          </header>
          <div v-if="costByDriver.length" class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th class="w-12">{{ $t('No') }}</th>
                  <th>{{ $t('Driver') }}</th>
                  <th class="num">{{ $t('Trips') }}</th>
                  <th class="num">{{ $t('Amount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, idx) in costByDriver" :key="row.driver_contact_id">
                  <td :data-label="$t('No')" class="dash-no">{{ idx + 1 }}</td>
                  <td :data-label="$t('Driver')">
                    <span class="lg-main">{{ row.driver ? row.driver.name : $t('Unknown Driver') }}</span>
                  </td>
                  <td :data-label="$t('Trips')" class="num">{{ row.trip_count }}</td>
                  <td :data-label="$t('Amount')" class="num"><span class="lg-main">{{ $money(row.total_amount) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="lg-empty dash-empty">{{ $t('No data yet.') }}</div>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles },

  props: {
    cards: Object,
    mostActiveVehicles: { type: Array, default: () => [] },
    mostFrequentDestinations: { type: Array, default: () => [] },
    costByProjectWarehouse: { type: Array, default: () => [] },
    costByDriver: { type: Array, default: () => [] },
  },

  data() {
    return {
      cardsList: [
        { key: 'total_vehicles', label: 'Total Vehicles', icon: 'truck', color: 'blue' },
        { key: 'active_vehicles', label: 'Active Vehicles', icon: 'tick', color: 'emerald' },
        { key: 'total_trips', label: 'Total Trips', icon: 'list', color: 'violet' },
        { key: 'trips_today', label: 'Trips Today', icon: 'calendar', color: 'cyan' },
        { key: 'trips_this_month', label: 'Trips This Month', icon: 'calendar', color: 'cyan' },
        { key: 'total_amount', label: 'Total Amount', icon: 'cash', color: 'emerald', money: true },
        { key: 'total_paid', label: 'Total Paid', icon: 'cash', color: 'emerald', money: true },
        { key: 'total_outstanding', label: 'Total Outstanding', icon: 'cash', color: 'rose', money: true },
        { key: 'unpaid_trips', label: 'Unpaid Trips', icon: 'exclamation', color: 'amber' },
        { key: 'unverified_trips', label: 'Unverified Trips', icon: 'tick', color: 'violet' },
        { key: 'trips_to_projects', label: 'Trips to Projects', icon: 'map-pin', color: 'blue' },
        { key: 'trips_to_warehouses', label: 'Trips to Warehouses', icon: 'truck', color: 'cyan' },
        { key: 'trips_to_other', label: 'Trips to Other', icon: 'folder', color: 'slate' },
      ],
      glowRgbMap: {
        blue: '96, 165, 250',
        cyan: '34, 211, 238',
        emerald: '52, 211, 153',
        amber: '251, 191, 36',
        violet: '167, 139, 250',
        rose: '251, 113, 133',
        slate: '148, 163, 184',
      },
    };
  },

  computed: {
    tileRows() {
      const rows = [
        ['total_vehicles', 'active_vehicles', 'total_trips', 'trips_today', 'trips_this_month'],
        ['total_amount', 'total_paid', 'total_outstanding', 'unpaid_trips'],
        ['unverified_trips', 'trips_to_projects', 'trips_to_warehouses', 'trips_to_other'],
      ];
      return rows.map(keys => keys.map(k => this.tiles.find(t => t.key === k)).filter(Boolean));
    },
    // Clickable stat tiles (StatTiles emits `select` with the item; goToCard drills in).
    tiles() {
      const toneMap = { blue: 'navy', cyan: 'sky' };
      return this.cardsList.map((c) => {
        const raw = c.money ? this.$money((this.cards || {})[c.key] || 0) : '';
        // Longer amounts get a smaller font so they always fit their tile on one line.
        const size = String(raw).length > 17 ? 'td-m-xl' : String(raw).length > 14 ? 'td-m-l' : '';
        return {
        key: c.key,
        icon: c.icon,
        tone: toneMap[c.color] || c.color,
        label: this.$t(c.label),
        value: c.money ? this.$money((this.cards || {})[c.key] || 0) : (this.cards || {})[c.key] || 0,
        valueClass: [c.key === 'total_amount' ? 'is-good' : c.key === 'total_outstanding' ? 'is-bad' : '', c.money ? 'td-money' : '', size].filter(Boolean).join(' ') || undefined,
        active: false,
        };
      });
    },
  },

  methods: {
    // Staggers each card's one-time entrance, then starts its glow pulse
    // shortly after it's finished appearing — tinted to the card's own
    // icon color via the --glow-rgb custom property (see .dash-tile below).
    cardStyle(index, color) {
      const delay = Math.min(index * 45, 500);
      return {
        '--glow-rgb': this.glowRgbMap[color] || this.glowRgbMap.slate,
        animationDelay: `${delay}ms, ${600 + delay}ms`,
      };
    },
    // Overrides the generic dark-slate value color for the two money tiles
    // the user specifically called out — a dark green for money coming in,
    // a maroon for money still owed — everything else keeps the default.
    valueColorFor(key) {
      if (key === 'total_amount') return { color: '#065f46' };
      if (key === 'total_outstanding') return { color: '#800020' };
      return {};
    },
    // Each tile drills into the page that actually answers "why is this
    // number what it is" — vehicle tiles go to the Vehicles register, money/
    // status tiles go to the Reports page (report_type mirrors exactly how
    // this card's own total was computed server-side, see
    // TransportDashboardController::index()), and date/destination tiles go
    // to the Records list pre-filtered the same way.
    cardHref(key) {
      const today = new Date();
      const toDateStr = (d) => d.toISOString().slice(0, 10);
      const todayStr = toDateStr(today);
      const firstOfMonthStr = toDateStr(new Date(today.getFullYear(), today.getMonth(), 1));

      const map = {
        total_vehicles: () => this.route('transport-vehicles.index'),
        active_vehicles: () => this.route('transport-vehicles.index', { status: 'Active' }),
        total_trips: () => this.route('transport-reports.index', { report_type: 'records' }),
        trips_today: () => this.route('transport-records.index', { date_from: todayStr, date_to: todayStr }),
        trips_this_month: () => this.route('transport-records.index', { date_from: firstOfMonthStr, date_to: todayStr }),
        total_amount: () => this.route('transport-reports.index', { report_type: 'records' }),
        total_paid: () => this.route('transport-reports.index', { report_type: 'paid' }),
        total_outstanding: () => this.route('transport-reports.index', { report_type: 'outstanding' }),
        unpaid_trips: () => this.route('transport-reports.index', { report_type: 'unpaid' }),
        unverified_trips: () => this.route('transport-records.index'),
        trips_to_projects: () => this.route('transport-records.index', { destination_type: 'Project' }),
        trips_to_warehouses: () => this.route('transport-records.index', { destination_type: 'Warehouse' }),
        trips_to_other: () => this.route('transport-records.index'),
      };
      return map[key] ? map[key]() : null;
    },
    goToCard(key) {
      const href = this.cardHref(key);
      if (href) this.$inertia.visit(href);
    },
  },
};
</script>

<style scoped>
/* Three tile lines; values stay on one line and shrink with the screen so they always fit. */
.td-row :deep(.lg-stats) { grid-template-columns: repeat(var(--td-cols), minmax(0, 1fr)); margin-bottom: 12px; }
.td-row :deep(.lg-stat) { padding: 12px 14px; gap: 10px; }
.td-row :deep(.lg-stat-value) { font-size: clamp(1rem, 1.7vw, 1.35rem); white-space: nowrap; }
.td-row :deep(.lg-stat-value.td-money) { font-size: clamp(0.8rem, 1.25vw, 1.1rem); }
.td-row :deep(.lg-stat-value.td-m-l) { font-size: clamp(0.7rem, 1.05vw, 0.95rem); }
.td-row :deep(.lg-stat-value.td-m-xl) { font-size: clamp(0.58rem, 0.86vw, 0.8rem); }
.td-row :deep(.lg-stat-label) { letter-spacing: 0.03em; }
@media (max-width: 767.98px) {
  .td-row :deep(.lg-stats) { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 8px; }
}
.dash-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; align-items: start; }
.dash-grid > .lg-card { margin-bottom: 0; min-width: 0; }
.dash-reg { display: block; font-variant-numeric: tabular-nums; }
.dash-empty { padding: 28px 16px !important; font-size: 0.84rem; }
@media (max-width: 1023.98px) {
  .dash-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
