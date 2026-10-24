<template>
  <admin-layout :title="$t('Digital Attendance Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Digital Attendance Reports')" :subtitle="$t('Daily, weekly, custom-range, location and device views of attendance.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="pdfUrl" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
        <a :href="exportUrl" class="lg-btn lg-btn-primary"><icons name="doc-text" class="w-4 h-4" />{{ $t('Excel') }}</a>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="rep-toolbar">
        <div class="rep-tabs">
          <button v-for="t in tabs" :key="t.key" type="button" class="rep-tab" :class="{ 'rep-tab-active': tab === t.key }" @click="tab = t.key">
            <icons :name="t.icon" class="w-3.5 h-3.5" />{{ $t(t.label) }}
          </button>
        </div>
        <div class="rep-search">
          <icons name="search" class="w-4 h-4 rep-search-icon" />
          <input v-model="search" type="text" :placeholder="$t('Search employee…')" class="rep-search-input" />
          <button v-if="search" type="button" class="rep-search-clear" @click="search = ''"><icons name="cross" class="w-3 h-3" /></button>
        </div>
      </div>

      <!-- Daily -->
      <div v-if="tab === 'daily'" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Daily') }}</div>
          </div>
          <date-picker v-model="dailyDate" class="ml-auto" @change="loadDaily" flush />
        </div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th><th>{{ $t('In') }}</th><th>{{ $t('Out') }}</th><th>{{ $t('Hours') }}</th><th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredDailyRows" :key="r.id">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ r.user }}</span></td>
                <td :data-label="$t('In')">{{ r.clock_in_label || '—' }}</td>
                <td :data-label="$t('Out')">{{ r.clock_out_label || '—' }}</td>
                <td :data-label="$t('Hours')"><span class="lg-main rep-hours">{{ r.worked_label }}</span></td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusClass(r.status)">{{ r.status.replace('_', ' ') }}</span></td>
              </tr>
              <tr v-if="!filteredDailyRows.length" class="lg-empty-row"><td colspan="5" class="lg-empty">{{ search ? $t('No employees match your search.') : $t('No records for this date.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Weekly -->
      <div v-if="tab === 'weekly'" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Weekly') }}</div>
            <div class="lg-card-sub">{{ weekly.start }} {{ $t('to') }} {{ weekly.end }}</div>
          </div>
        </div>
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th>
                <th v-for="d in weekly.days" :key="d" class="text-center">{{ d.slice(5) }}</th>
                <th class="text-center">{{ $t('Total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredWeeklyRows" :key="row.user?.id">
                <td><span class="lg-main">{{ row.user?.name }}</span></td>
                <td
                  v-for="(d, i) in row.daily"
                  :key="i"
                  class="text-center"
                  :class="{ 'lg-tip': d, 'tip-left': d && i < 3 }"
                  :data-tip="d ? cellTooltip(d) : null"
                >
                  <template v-if="d">
                    {{ d.hours }}h<span v-if="d.sessions_count > 1" class="rep-cell-sessions">×{{ d.sessions_count }}</span>
                  </template>
                  <span v-else class="lg-subtext" style="display: inline">—</span>
                </td>
                <td class="text-center"><span class="lg-main rep-hours">{{ row.total_hours }}h</span></td>
              </tr>
              <tr v-if="!filteredWeeklyRows.length" class="lg-empty-row"><td :colspan="weekly.days.length + 2" class="lg-empty">{{ search ? $t('No employees match your search.') : $t('No records for this week.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Custom -->
      <div v-if="tab === 'custom'" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="adjustments" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Custom Range') }}</div>
          </div>
        </div>
        <div class="rep-range">
          <date-picker v-model="customFrom" flush />
          <date-picker v-model="customTo" flush />
          <button type="button" class="lg-btn lg-btn-primary" @click="loadCustom"><icons name="refresh" class="w-4 h-4" />{{ $t('Run Report') }}</button>
        </div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th><th>{{ $t('Days') }}</th><th>{{ $t('Total') }}</th><th>{{ $t('Avg/Day') }}</th><th>{{ $t('Late') }}</th><th>{{ $t('Missing') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredCustomRows" :key="row.user?.id">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ row.user?.name }}</span></td>
                <td :data-label="$t('Days')">{{ row.attendance_days }}</td>
                <td :data-label="$t('Total')"><span class="lg-main rep-hours">{{ row.total_label }}</span></td>
                <td :data-label="$t('Avg/Day')">{{ row.average_minutes }}m</td>
                <td :data-label="$t('Late')"><span class="lg-chip" :class="row.late_count ? 'lg-chip-amber' : 'lg-chip-slate'">{{ row.late_count }}</span></td>
                <td :data-label="$t('Missing')"><span class="lg-chip" :class="row.missing_count ? 'lg-chip-red' : 'lg-chip-slate'">{{ row.missing_count }}</span></td>
              </tr>
              <tr v-if="!filteredCustomRows.length" class="lg-empty-row"><td colspan="6" class="lg-empty">{{ search && customRows.length ? $t('No employees match your search.') : $t('Choose a date range and run the report.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Location -->
      <div v-if="tab === 'location'" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="map-pin" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Location') }}</div>
          </div>
        </div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Location') }}</th><th>{{ $t('Coordinates') }}</th><th>{{ $t('Accuracy') }}</th><th>{{ $t('IP') }}</th><th>{{ $t('Device') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredLocationRows" :key="r.id">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ r.user }}</span></td>
                <td :data-label="$t('Date')">{{ r.date }}</td>
                <td :data-label="$t('Location')">
                  <a
                    v-if="r.clock_in_latitude"
                    :href="mapsUrl(r.clock_in_latitude, r.clock_in_longitude)"
                    target="_blank"
                    rel="noopener"
                    class="rep-map-link"
                  >
                    <icons name="map-pin" class="w-3.5 h-3.5" />
                    {{ r.clock_in_location_label || $t('Nearby location') }}
                  </a>
                  <span v-else>—</span>
                </td>
                <td :data-label="$t('Coordinates')"><span class="lg-mono lg-subtext" style="display: inline">{{ r.clock_in_location || '—' }}</span></td>
                <td :data-label="$t('Accuracy')">{{ r.clock_in_accuracy ? Math.round(r.clock_in_accuracy) + 'm' : '—' }}</td>
                <td :data-label="$t('IP')"><span class="lg-mono">{{ r.clock_in_ip || '—' }}</span></td>
                <td :data-label="$t('Device')">{{ r.clock_in_device }}</td>
              </tr>
              <tr v-if="!filteredLocationRows.length" class="lg-empty-row"><td colspan="7" class="lg-empty">{{ search ? $t('No employees match your search.') : $t('No location data.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Device -->
      <div v-if="tab === 'device'" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="mobile" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Device') }}</div>
          </div>
        </div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th><th>{{ $t('Browser') }}</th><th>{{ $t('OS') }}</th><th>{{ $t('Device') }}</th><th>{{ $t('Last Seen') }}</th><th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in filteredDeviceRows" :key="i">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ r.user }}</span></td>
                <td :data-label="$t('Browser')">{{ r.browser }}</td>
                <td :data-label="$t('OS')">{{ r.os }}</td>
                <td :data-label="$t('Device')">{{ r.device }}</td>
                <td :data-label="$t('Last Seen')">{{ r.last_seen || '—' }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusClass(r.status)">{{ r.status.replace('_', ' ') }}</span></td>
              </tr>
              <tr v-if="!filteredDeviceRows.length" class="lg-empty-row"><td colspan="6" class="lg-empty">{{ search ? $t('No employees match your search.') : $t('No device data.') }}</td></tr>
            </tbody>
          </table>
        </div>
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
  props: { users: Array },
  data() {
    const today = new Date().toISOString().slice(0, 10);
    return {
      tab: 'daily',
      tabs: [
        { key: 'daily', label: 'Daily', icon: 'calendar' },
        { key: 'weekly', label: 'Weekly', icon: 'table' },
        { key: 'custom', label: 'Custom Range', icon: 'adjustments' },
        { key: 'location', label: 'Location', icon: 'map-pin' },
        { key: 'device', label: 'Device', icon: 'mobile' },
      ],
      dailyDate: today,
      dailyRows: [],
      weekly: { start: '', end: '', days: [], rows: [] },
      customFrom: today,
      customTo: today,
      customRows: [],
      locationRows: [],
      deviceRows: [],
      search: '',
    };
  },
  computed: {
    printUrl() { return this.route('digital-attendance-reports.print'); },
    pdfUrl() { return this.route('digital-attendance-reports.pdf'); },
    exportUrl() { return this.route('digital-attendance-reports.export'); },
    statTiles() {
      const sum = (rows, fn) => rows.reduce((t, r) => t + (Number(fn(r)) || 0), 0);
      if (this.tab === 'daily' && this.dailyRows.length) {
        const rows = this.dailyRows;
        return [
          { icon: 'users', tone: 'navy', label: this.$t('Employee'), value: rows.length },
          { icon: 'lock-open', tone: 'emerald', label: this.$t('Clocked In'), value: rows.filter(r => r.status === 'CLOCKED_IN').length },
          { icon: 'lock-closed', tone: 'slate', label: this.$t('Clocked Out'), value: rows.filter(r => r.status === 'CLOCKED_OUT').length },
          { icon: 'exclamation', tone: 'rose', label: this.$t('Missing Clock-Out'), value: rows.filter(r => r.status === 'MISSING_CLOCK_OUT').length },
        ];
      }
      if (this.tab === 'weekly' && this.weekly.rows.length) {
        const rows = this.weekly.rows;
        const total = sum(rows, r => r.total_hours);
        return [
          { icon: 'users', tone: 'navy', label: this.$t('Employee'), value: rows.length },
          { icon: 'clock', tone: 'emerald', label: this.$t('Total'), value: `${Math.round(total * 10) / 10}h` },
          { icon: 'chart-bar', tone: 'teal', label: this.$t('Avg/Day'), value: `${Math.round((total / rows.length) * 10) / 10}h` },
          { icon: 'exclamation', tone: 'amber', label: this.$t('Late'), value: sum(rows, r => r.late_count) },
        ];
      }
      if (this.tab === 'custom' && this.customRows.length) {
        const rows = this.customRows;
        return [
          { icon: 'users', tone: 'navy', label: this.$t('Employee'), value: rows.length },
          { icon: 'calendar', tone: 'emerald', label: this.$t('Days'), value: sum(rows, r => r.attendance_days) },
          { icon: 'exclamation', tone: 'amber', label: this.$t('Late'), value: sum(rows, r => r.late_count) },
          { icon: 'exclamation', tone: 'rose', label: this.$t('Missing'), value: sum(rows, r => r.missing_count) },
        ];
      }
      if (this.tab === 'location' && this.locationRows.length) {
        const rows = this.locationRows;
        return [
          { icon: 'list', tone: 'navy', label: this.$t('Records'), value: rows.length },
          { icon: 'map-pin', tone: 'emerald', label: this.$t('Location Captured'), value: rows.filter(r => r.clock_in_latitude).length },
        ];
      }
      if (this.tab === 'device' && this.deviceRows.length) {
        return [{ icon: 'mobile', tone: 'violet', label: this.$t('Device'), value: this.deviceRows.length }];
      }
      return [];
    },
    searchTerm() { return this.search.trim().toLowerCase(); },
    filteredDailyRows() {
      if (!this.searchTerm) return this.dailyRows;
      return this.dailyRows.filter(r => (r.user || '').toLowerCase().includes(this.searchTerm));
    },
    filteredWeeklyRows() {
      if (!this.searchTerm) return this.weekly.rows;
      return this.weekly.rows.filter(row => (row.user?.name || '').toLowerCase().includes(this.searchTerm));
    },
    filteredCustomRows() {
      if (!this.searchTerm) return this.customRows;
      return this.customRows.filter(row => (row.user?.name || '').toLowerCase().includes(this.searchTerm));
    },
    filteredLocationRows() {
      if (!this.searchTerm) return this.locationRows;
      return this.locationRows.filter(r => (r.user || '').toLowerCase().includes(this.searchTerm));
    },
    filteredDeviceRows() {
      if (!this.searchTerm) return this.deviceRows;
      return this.deviceRows.filter(r => (r.user || '').toLowerCase().includes(this.searchTerm));
    },
  },
  watch: {
    tab(val) {
      if (val === 'weekly' && !this.weekly.rows.length) this.loadWeekly();
      if (val === 'location' && !this.locationRows.length) this.loadLocation();
      if (val === 'device' && !this.deviceRows.length) this.loadDevice();
    },
  },
  mounted() {
    this.loadDaily();
  },
  methods: {
    loadDaily() {
      axios.get(this.route('digital-attendance-reports.daily'), { params: { date: this.dailyDate } }).then(res => (this.dailyRows = res.data.rows));
    },
    loadWeekly() {
      axios.get(this.route('digital-attendance-reports.weekly')).then(res => (this.weekly = res.data));
    },
    loadCustom() {
      axios.get(this.route('digital-attendance-reports.custom'), { params: { from: this.customFrom, to: this.customTo } }).then(res => (this.customRows = res.data.rows));
    },
    loadLocation() {
      axios.get(this.route('digital-attendance-reports.location')).then(res => (this.locationRows = res.data.rows));
    },
    loadDevice() {
      axios.get(this.route('digital-attendance-reports.device')).then(res => (this.deviceRows = res.data.rows));
    },
    statusClass(status) {
      if (status === 'CLOCKED_IN') return 'lg-chip-green';
      if (status === 'CLOCKED_OUT') return 'lg-chip-blue';
      if (status === 'MISSING_CLOCK_OUT') return 'lg-chip-red';
      return 'lg-chip-slate';
    },
    cellTooltip(d) {
      const parts = [];
      parts.push(`${this.$t('In')}: ${d.clock_in || '—'}`);
      parts.push(`${this.$t('Out')}: ${d.clock_out || '—'}`);
      parts.push(`${this.$t('Worked')}: ${d.worked_label}`);
      parts.push(d.status.replace('_', ' '));
      if (d.sessions_count > 1) parts.push(`${d.sessions_count} ${this.$t('sessions')}`);
      return parts.join(' · ');
    },
    mapsUrl(lat, lng) {
      return `https://www.google.com/maps?q=${lat},${lng}`;
    },
  },
};
</script>

<style scoped>
.rep-hours { color: #047857; }
.lg-table th.text-center, .lg-table td.text-center { text-align: center; }
html.dark .rep-hours { color: #6ee7b7; }
.rep-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 1rem; }

.rep-tabs { display: inline-flex; gap: 0.25rem; max-width: 100%; padding: 0.25rem; overflow-x: auto; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.rep-tab { display: inline-flex; align-items: center; flex: none; gap: 0.35rem; padding: 0.45rem 0.95rem; border: 0; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; color: var(--lg-mute); background: transparent; transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease; }
.rep-tab:hover:not(.rep-tab-active) { background: var(--lg-soft); color: var(--lg-ink); }
.rep-tab-active { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 8px 16px -10px rgba(15, 37, 64, 0.9); }

.rep-search { display: flex; align-items: center; gap: 0.5rem; min-width: 15rem; height: 2.6rem; padding: 0 0.85rem; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
@media (max-width: 480px) { .rep-search { min-width: 0; width: 100%; } }
.rep-search:focus-within { border-color: #6ee7b7; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.14); }
.rep-search-icon { flex: none; color: var(--lg-faint); }
.rep-search-input { flex: 1; min-width: 0; padding: 0 !important; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent !important; font-size: 0.82rem; color: var(--lg-ink); }
.rep-search-input::placeholder { color: var(--lg-faint); }
.rep-search-clear { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 1.2rem; height: 1.2rem; border-radius: 9999px; background: var(--lg-soft); color: var(--lg-mute); }
.rep-search-clear:hover { background: var(--lg-line); }

.rep-input { height: 2.5rem; padding: 0 0.85rem; font-size: 0.83rem; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 9999px; }
.rep-input:focus { outline: none; border-color: #34d399; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
.rep-range { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 1rem; }

.rep-cell-sessions { display: inline-block; margin-left: 0.2rem; font-size: 0.66rem; font-weight: 800; color: #0369a1; }
.rep-map-link { display: inline-flex; align-items: center; gap: 0.3rem; color: #2563eb; font-weight: 600; }
.rep-map-link:hover { text-decoration: underline; }
html.dark .rep-map-link { color: #93c5fd; }
</style>
