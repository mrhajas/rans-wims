<template>
  <admin-layout :title="$t('Digital Attendance Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Digital Attendance Reports')" :subtitle="$t('Daily, weekly, custom-range, location and device views of attendance.')">
        <button v-if="canEmail" type="button" class="lg-btn lg-btn-soft" @click="emailOpen = true"><icons name="email" class="w-4 h-4" />{{ $t('Email') }}</button>
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="pdfUrl" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
        <a :href="exportUrl" class="lg-btn lg-btn-primary"><icons name="doc-text" class="w-4 h-4" />{{ $t('Excel') }}</a>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="rep-toolbar">
        <div ref="tabBar" class="rep-tabs" role="tablist">
          <span class="rep-glider" :class="'tone-' + activeTone" :style="glider" aria-hidden="true"></span>
          <button v-for="t in tabs" :key="t.key" :ref="el => (tabEls[t.key] = el)" type="button" role="tab" :aria-selected="tab === t.key" class="rep-tab" :class="['tone-' + t.tone, { 'rep-tab-active': tab === t.key }]" @click="tab = t.key">
            <span class="rep-tab-ico"><icons :name="t.icon" class="w-3.5 h-3.5" /></span>{{ $t(t.label) }}
          </button>
        </div>
        <div class="rep-filters">
        <auto-complete v-model="userId" class="rep-employee" :suggestions="userOptions" :placeholder="$t('All employees')" searchable clearable />
        <div class="rep-search">
          <icons name="search" class="w-4 h-4 rep-search-icon" />
          <input v-model="search" type="text" :placeholder="$t('Search employee…')" class="rep-search-input" />
          <button v-if="search" type="button" class="rep-search-clear" @click="search = ''"><icons name="cross" class="w-3 h-3" /></button>
        </div>
        </div>
      </div>

      <div class="rep-scope">
        <span class="rep-scope-lead"><icons name="info" class="w-3.5 h-3.5" />{{ $t('Print, PDF, Excel and Email follow what you see here:') }}</span>
        <span class="rep-scope-chip"><icons name="calendar" class="w-3.5 h-3.5" />{{ periodText }}</span>
        <span class="rep-scope-chip"><icons name="user" class="w-3.5 h-3.5" />{{ employee ? employee.name : $t('All employees') }}</span>
        <span v-if="search.trim()" class="rep-scope-chip"><icons name="search" class="w-3.5 h-3.5" />{{ search.trim() }}</span>
      </div>

      <transition name="rep-swap" mode="out-in">

      <!-- Daily -->
      <div v-if="tab === 'daily'" key="daily" class="lg-card rep-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Daily') }}</div>
            <div class="lg-card-sub">{{ dayName(dailyDate) }} &middot; {{ filteredDailyRows.length }} {{ $t('records') }} &middot; {{ dailyTotal }}</div>
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
              <template v-if="loading.daily && !dailyRows.length"><tr v-for="n in 4" :key="'s' + n" class="rep-skel-row"><td v-for="c in 5" :key="c"><span class="rep-skel"></span></td></tr></template>
              <tr v-for="r in filteredDailyRows" :key="r.id">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ r.user }}</span></td>
                <td :data-label="$t('In')">{{ r.clock_in_label || '-' }}</td>
                <td :data-label="$t('Out')">{{ r.clock_out_label || '-' }}</td>
                <td :data-label="$t('Hours')"><span class="lg-main rep-hours">{{ r.worked_label }}</span></td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</span></td>
              </tr>
              <tr v-if="!filteredDailyRows.length && !loading.daily" class="lg-empty-row"><td colspan="5" class="lg-empty">{{ loading.daily ? $t('Loading...') : search ? $t('No employees match your search.') : $t('No records for this date.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Weekly -->
      <div v-else-if="tab === 'weekly'" key="weekly" class="lg-card rep-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Weekly') }}</div>
            <div class="lg-card-sub">{{ weekly.start }} {{ $t('to') }} {{ weekly.end }}</div>
          </div>
          <div class="rep-week ml-auto">
            <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Previous week')" :aria-label="$t('Previous week')" @click="moveWeek(-1)"><icons name="chevron-down" class="w-4 h-4 rep-prev" /></button>
            <button type="button" class="lg-btn lg-btn-soft" @click="moveWeek(0)">{{ $t('This week') }}</button>
            <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Next week')" :aria-label="$t('Next week')" @click="moveWeek(1)"><icons name="chevron-down" class="w-4 h-4 rep-next" /></button>
          </div>
        </div>
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th>
                <th v-for="d in weekly.days" :key="d" class="text-center rep-day" :class="{ 'is-weekend': isWeekend(d), 'is-today': d === todayStr }">
                  <span>{{ dayName(d, true) }}</span><small>{{ d.slice(8) }}/{{ d.slice(5, 7) }}</small>
                </th>
                <th class="text-center">{{ $t('Total') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-if="loading.weekly && !weekly.rows.length"><tr v-for="n in 4" :key="'s' + n" class="rep-skel-row"><td v-for="c in 9" :key="c"><span class="rep-skel"></span></td></tr></template>
              <tr v-for="row in filteredWeeklyRows" :key="row.user?.id">
                <td><span class="lg-main">{{ row.user?.name }}</span></td>
                <td
                  v-for="(d, i) in row.daily"
                  :key="i"
                  class="text-center"
                  :class="{ 'lg-tip': d, 'tip-left': d && i < 3, 'is-weekend': isWeekend(weekly.days[i]), 'is-today': weekly.days[i] === todayStr, 'rep-late': d && d.late, 'rep-miss': d && d.status === 'MISSING_CLOCK_OUT' }"
                  :data-tip="d ? cellTooltip(d) : null"
                >
                  <span v-if="d" class="rep-heat" :class="heatClass(d.hours)">{{ d.hours }}h<span v-if="d.sessions_count > 1" class="rep-cell-sessions">×{{ d.sessions_count }}</span></span>
                  <span v-else class="lg-subtext" style="display: inline">-</span>
                </td>
                <td class="text-center"><span class="lg-main rep-hours">{{ row.total_hours }}h</span></td>
              </tr>
              <tr v-if="!filteredWeeklyRows.length && !loading.weekly" class="lg-empty-row"><td :colspan="weekly.days.length + 2" class="lg-empty">{{ search ? $t('No employees match your search.') : $t('No records for this week.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Custom -->
      <div v-else-if="tab === 'custom'" key="custom" class="lg-card rep-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="adjustments" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Custom Range') }}</div>
          </div>
        </div>
        <div class="rep-range">
          <div class="rep-range-f"><label class="rep-range-l">{{ $t('From') }}</label><date-picker v-model="customFrom" flush /></div>
          <span class="rep-range-arrow" aria-hidden="true"><icons name="forward" class="w-4 h-4" /></span>
          <div class="rep-range-f"><label class="rep-range-l">{{ $t('To') }}</label><date-picker v-model="customTo" flush /></div>
          <button type="button" class="lg-btn lg-btn-primary rep-run" :disabled="loading.custom" @click="loadCustom"><icons name="refresh" class="w-4 h-4" :class="{ 'rep-spin': loading.custom }" />{{ loading.custom ? $t('Loading...') : $t('Run Report') }}</button>
        </div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th><th>{{ $t('Days') }}</th><th>{{ $t('Total') }}</th><th>{{ $t('Avg/Day') }}</th><th>{{ $t('Late') }}</th><th>{{ $t('Missing') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-if="loading.custom"><tr v-for="n in 4" :key="'s' + n" class="rep-skel-row"><td v-for="c in 6" :key="c"><span class="rep-skel"></span></td></tr></template>
              <tr v-for="row in filteredCustomRows" v-else :key="row.user?.id">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ row.user?.name }}</span></td>
                <td :data-label="$t('Days')">{{ row.attendance_days }}</td>
                <td :data-label="$t('Total')"><span class="lg-main rep-hours">{{ row.total_label }}</span></td>
                <td :data-label="$t('Avg/Day')">{{ row.average_label }}</td>
                <td :data-label="$t('Late')"><span class="lg-chip" :class="row.late_count ? 'lg-chip-amber' : 'lg-chip-slate'">{{ row.late_count }}</span></td>
                <td :data-label="$t('Missing')"><span class="lg-chip" :class="row.missing_count ? 'lg-chip-red' : 'lg-chip-slate'">{{ row.missing_count }}</span></td>
              </tr>
              <tr v-if="!filteredCustomRows.length && !loading.custom" class="lg-empty-row"><td colspan="6" class="lg-empty">{{ search && customRows.length ? $t('No employees match your search.') : $t('Choose a date range and run the report.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Location -->
      <div v-else-if="tab === 'location'" key="location" class="lg-card rep-card">
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
              <template v-if="loading.location && !locationRows.length"><tr v-for="n in 4" :key="'s' + n" class="rep-skel-row"><td v-for="c in 7" :key="c"><span class="rep-skel"></span></td></tr></template>
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
                  <span v-else>-</span>
                </td>
                <td :data-label="$t('Coordinates')"><span class="lg-mono lg-subtext" style="display: inline">{{ r.clock_in_location || '-' }}</span></td>
                <td :data-label="$t('Accuracy')">{{ r.clock_in_accuracy ? Math.round(r.clock_in_accuracy) + 'm' : '-' }}</td>
                <td :data-label="$t('IP')"><span class="lg-mono">{{ r.clock_in_ip || '-' }}</span></td>
                <td :data-label="$t('Device')">{{ r.clock_in_device }}</td>
              </tr>
              <tr v-if="!filteredLocationRows.length && !loading.location" class="lg-empty-row"><td colspan="7" class="lg-empty">{{ search ? $t('No employees match your search.') : $t('No location data.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Device -->
      <div v-else-if="tab === 'device'" key="device" class="lg-card rep-card">
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
              <template v-if="loading.device && !deviceRows.length"><tr v-for="n in 4" :key="'s' + n" class="rep-skel-row"><td v-for="c in 6" :key="c"><span class="rep-skel"></span></td></tr></template>
              <tr v-for="(r, i) in filteredDeviceRows" :key="i">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ r.user }}</span></td>
                <td :data-label="$t('Browser')">{{ r.browser }}</td>
                <td :data-label="$t('OS')">{{ r.os }}</td>
                <td :data-label="$t('Device')">{{ r.device }}</td>
                <td :data-label="$t('Last Seen')">{{ r.last_seen || '-' }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</span></td>
              </tr>
              <tr v-if="!filteredDeviceRows.length && !loading.device" class="lg-empty-row"><td colspan="6" class="lg-empty">{{ search ? $t('No employees match your search.') : $t('No device data.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      </transition>
    </div>
    <email-report-modal :show="emailOpen" :query="reportQuery" :employee="employee" :period-text="periodText" @close="emailOpen = false" />
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import EmailReportModal from './EmailReportModal.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, AutoComplete, EmailReportModal },
  props: { users: Array, canEmail: { type: Boolean, default: false } },
  data() {
    const today = new Date().toISOString().slice(0, 10);
    return {
      tab: 'daily',
      tabs: [
        { key: 'daily', label: 'Daily', icon: 'calendar', tone: 'emerald' },
        { key: 'weekly', label: 'Weekly', icon: 'table', tone: 'indigo' },
        { key: 'custom', label: 'Custom Range', icon: 'adjustments', tone: 'amber' },
        { key: 'location', label: 'Location', icon: 'map-pin', tone: 'sky' },
        { key: 'device', label: 'Device', icon: 'mobile', tone: 'violet' },
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
      userId: null,
      weekStart: null,
      emailOpen: false,
      loading: { daily: false, weekly: false, custom: false, location: false, device: false },
      glider: { width: '0px', transform: 'translateX(0px)', opacity: 0 },
    };
  },
  computed: {
    // the filters of the tab on screen: the print, PDF, Excel and email of the report all use them
    reportQuery() {
      const q = {};
      if (this.userId) q.user_id = this.userId;
      if (this.search.trim()) q.search = this.search.trim();
      if (this.tab === 'daily') q.date = this.dailyDate;
      else if (this.tab === 'weekly' && this.weekly.start) Object.assign(q, { date_from: this.weekly.start, date_to: this.weekly.end });
      else if (this.tab === 'custom') Object.assign(q, { date_from: this.customFrom, date_to: this.customTo });
      return q;
    },
    printUrl() { return this.route('digital-attendance-reports.print', this.reportQuery); },
    pdfUrl() { return this.route('digital-attendance-reports.pdf', this.reportQuery); },
    exportUrl() { return this.route('digital-attendance-reports.export', this.reportQuery); },
    userOptions() { return (this.users || []).map(u => ({ id: u.id, label: u.name + (u.username ? ' (' + u.username + ')' : '') })); },
    employee() { return this.userId ? (this.users || []).find(u => u.id === this.userId) || null : null; },
    periodText() {
      const q = this.reportQuery;
      if (q.date) return q.date;
      if (q.date_from && q.date_to) return q.date_from === q.date_to ? q.date_from : q.date_from + ' ' + this.$t('to') + ' ' + q.date_to;
      return this.$t('All dates');
    },
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
    activeTone() { return (this.tabs.find(t => t.key === this.tab) || {}).tone || 'emerald'; },
    todayStr() { return new Date().toISOString().slice(0, 10); },
    dailyTotal() {
      const m = this.filteredDailyRows.reduce((t, r) => t + (r.minutes || 0), 0);
      return Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm';
    },
    weeklyDayTotals() {
      return (this.weekly.days || []).map((_, i) => this.filteredWeeklyRows.reduce((t, row) => t + (row.daily[i] ? Number(row.daily[i].hours) : 0), 0));
    },
    weeklyGrand() {
      return Math.round(this.filteredWeeklyRows.reduce((t, row) => t + Number(row.total_hours || 0), 0) * 10) / 10;
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
    // another employee: every tab that was already loaded is loaded again for them
    userId() {
      this.loadDaily();
      if (this.weekly.start || this.tab === 'weekly') this.loadWeekly();
      if (this.customRows.length) this.loadCustom();
      if (this.locationRows.length || this.tab === 'location') this.loadLocation();
      if (this.deviceRows.length || this.tab === 'device') this.loadDevice();
    },
    tab(val) {
      this.$nextTick(this.moveGlider);
      if (val === 'weekly' && !this.weekly.rows.length) this.loadWeekly();
      if (val === 'location' && !this.locationRows.length) this.loadLocation();
      if (val === 'device' && !this.deviceRows.length) this.loadDevice();
    },
  },
  created() {
    // the tab buttons, for measuring the highlight; deliberately not reactive
    this.tabEls = {};
  },
  mounted() {
    this.loadDaily();
    this.$nextTick(this.moveGlider);
    window.addEventListener('resize', this.moveGlider, { passive: true });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(this.moveGlider);
  },
  beforeUnmount() {
    window.removeEventListener('resize', this.moveGlider);
  },
  methods: {
    loadDaily() {
      this.loading.daily = true;
      axios.get(this.route('digital-attendance-reports.daily'), { params: { date: this.dailyDate, user_id: this.userId || undefined } }).then(res => (this.dailyRows = res.data.rows)).finally(() => (this.loading.daily = false));
    },
    loadWeekly() {
      this.loading.weekly = true;
      axios.get(this.route('digital-attendance-reports.weekly'), { params: { start: this.weekStart || undefined, user_id: this.userId || undefined } }).then(res => (this.weekly = res.data)).finally(() => (this.loading.weekly = false));
    },
    // a week back (-1), this week (0), a week on (1)
    moveWeek(step) {
      const base = this.weekly.start ? new Date(this.weekly.start + 'T00:00:00') : new Date();
      if (step === 0) this.weekStart = null;
      else {
        base.setDate(base.getDate() + step * 7);
        this.weekStart = base.toISOString().slice(0, 10);
      }
      this.loadWeekly();
    },
    loadCustom() {
      this.loading.custom = true;
      axios.get(this.route('digital-attendance-reports.custom'), { params: { from: this.customFrom, to: this.customTo, user_id: this.userId || undefined } }).then(res => (this.customRows = res.data.rows)).finally(() => (this.loading.custom = false));
    },
    loadLocation() {
      this.loading.location = true;
      axios.get(this.route('digital-attendance-reports.location'), { params: { user_id: this.userId || undefined } }).then(res => (this.locationRows = res.data.rows)).finally(() => (this.loading.location = false));
    },
    loadDevice() {
      this.loading.device = true;
      axios.get(this.route('digital-attendance-reports.device'), { params: { user_id: this.userId || undefined } }).then(res => (this.deviceRows = res.data.rows)).finally(() => (this.loading.device = false));
    },
    // slides the highlight under the active tab (and keeps that tab in view when the bar scrolls on phones)
    moveGlider() {
      const el = this.tabEls[this.tab];
      if (!el) return;
      this.glider = { width: el.offsetWidth + 'px', transform: `translateX(${el.offsetLeft}px)`, opacity: 1 };
      const bar = this.$refs.tabBar;
      if (bar && bar.scrollWidth > bar.clientWidth) bar.scrollTo({ left: el.offsetLeft - (bar.clientWidth - el.offsetWidth) / 2, behavior: 'smooth' });
    },
    // weekly cell shade: short, part and full days
    heatClass(hours) {
      const h = Number(hours) || 0;
      if (h >= 8) return 'rep-heat-full';
      if (h >= 4) return 'rep-heat-mid';
      return 'rep-heat-low';
    },
    // 2026-11-07 -> "Saturday" (short: "Sat")
    dayName(iso, short = false) {
      if (!iso) return '';
      return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { weekday: short ? 'short' : 'long' });
    },
    isWeekend(iso) {
      const d = iso ? new Date(iso + 'T00:00:00').getDay() : 1;
      return d === 0 || d === 6;
    },
    // CLOCKED_IN -> Clocked in
    statusLabel(status) {
      const t = String(status || '').toLowerCase().replace(/_/g, ' ');
      return t.charAt(0).toUpperCase() + t.slice(1);
    },
    statusClass(status) {
      if (status === 'CLOCKED_IN') return 'lg-chip-green';
      if (status === 'CLOCKED_OUT') return 'lg-chip-blue';
      if (status === 'MISSING_CLOCK_OUT') return 'lg-chip-red';
      return 'lg-chip-slate';
    },
    cellTooltip(d) {
      const parts = [];
      parts.push(`${this.$t('In')}: ${d.clock_in || '-'}`);
      parts.push(`${this.$t('Out')}: ${d.clock_out || '-'}`);
      parts.push(`${this.$t('Worked')}: ${d.worked_label}`);
      parts.push(this.statusLabel(d.status) + (d.late ? ' (' + this.$t('Late') + ')' : ''));
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

.rep-tabs { position: relative; display: inline-flex; gap: 0.25rem; max-width: 100%; padding: 0.3rem; overflow-x: auto; scrollbar-width: none; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.rep-tabs::-webkit-scrollbar { display: none; }
.tone-emerald { --tone: #059669; --tone-2: #34d399; }
.tone-indigo { --tone: #4f46e5; --tone-2: #818cf8; }
.tone-amber { --tone: #d97706; --tone-2: #fbbf24; }
.tone-sky { --tone: #0284c7; --tone-2: #38bdf8; }
.tone-violet { --tone: #7c3aed; --tone-2: #a78bfa; }
.rep-glider { position: absolute; top: 0.3rem; left: 0; bottom: 0.3rem; z-index: 0; border-radius: 9999px; background: linear-gradient(145deg, var(--tone-2), var(--tone)); box-shadow: 0 8px 18px -10px var(--tone); pointer-events: none; transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1), width 0.45s cubic-bezier(0.22, 1, 0.36, 1), background 0.3s, box-shadow 0.3s, opacity 0.2s; }
.rep-tab { position: relative; z-index: 1; display: inline-flex; align-items: center; flex: none; gap: 0.45rem; padding: 0.35rem 0.95rem 0.35rem 0.4rem; border: 0; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; color: var(--lg-mute); background: transparent; transition: color 0.25s ease, background 0.2s ease; }
.rep-tab-ico { display: grid; place-items: center; width: 1.7rem; height: 1.7rem; border-radius: 9999px; color: var(--tone); background: color-mix(in srgb, var(--tone) 13%, transparent); transition: background 0.25s, color 0.25s, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.rep-tab:hover:not(.rep-tab-active) { background: var(--lg-soft); color: var(--lg-ink); }
.rep-tab:hover .rep-tab-ico { transform: scale(1.08); }
.rep-tab:active .rep-tab-ico { transform: scale(0.92); }
.rep-tab:focus-visible { outline: 2px solid var(--tone); outline-offset: 1px; }
.rep-tab-active { color: #fff; }
.rep-tab-active .rep-tab-ico { color: #fff; background: rgba(255, 255, 255, 0.2); }
html.dark .rep-tab-ico { color: var(--tone-2); }
html.dark .rep-tab-active .rep-tab-ico { color: #fff; }

.rep-swap-enter-active, .rep-swap-leave-active { transition: opacity 0.2s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.rep-swap-enter-from { opacity: 0; transform: translateY(10px); }
.rep-swap-leave-to { opacity: 0; transform: translateY(-6px); }

.rep-skel-row td { padding-top: 0.85rem !important; padding-bottom: 0.85rem !important; }
.rep-skel { display: block; height: 0.7rem; width: 70%; border-radius: 9999px; background: linear-gradient(90deg, var(--lg-soft) 25%, var(--lg-line) 50%, var(--lg-soft) 75%); background-size: 200% 100%; animation: rep-shimmer 1.2s linear infinite; }
.rep-skel-row td:first-child .rep-skel { width: 85%; }
@keyframes rep-shimmer { to { background-position: -200% 0; } }

.rep-heat { display: inline-flex; align-items: center; justify-content: center; min-width: 3rem; padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.rep-heat-low { color: #b45309; background: rgba(245, 158, 11, 0.12); }
.rep-heat-mid { color: #0369a1; background: rgba(14, 165, 233, 0.12); }
.rep-heat-full { color: #047857; background: rgba(16, 185, 129, 0.14); }
html.dark .rep-heat-low { color: #fcd34d; }
html.dark .rep-heat-mid { color: #7dd3fc; }
html.dark .rep-heat-full { color: #6ee7b7; }
.rep-spin { animation: rep-rotate 0.8s linear infinite; }
@keyframes rep-rotate { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
  .rep-glider, .rep-tab, .rep-tab-ico { transition: none; }
  .rep-swap-enter-active, .rep-swap-leave-active { transition: none; }
  .rep-skel, .rep-spin { animation: none; }
}

.rep-filters { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.rep-employee { min-width: 15rem; margin: 0; }
.rep-employee :deep(.mb-2) { margin-bottom: 0 !important; }
.rep-scope { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 8px; margin: -0.35rem 0 1rem; padding: 0.5rem 0.75rem; border-radius: 16px; font-size: 0.78rem; color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.rep-scope-lead { display: inline-flex; align-items: center; gap: 6px; }
.rep-scope-chip { display: inline-flex; align-items: center; gap: 5px; padding: 0.2rem 0.6rem; border-radius: 9999px; font-weight: 700; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); animation: rep-chip-in 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
@keyframes rep-chip-in { from { opacity: 0; transform: scale(0.9); } }
.rep-week { display: inline-flex; align-items: center; gap: 6px; }
.rep-prev { transform: rotate(90deg); }
.rep-next { transform: rotate(-90deg); }
.rep-range-l { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
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
.rep-range { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 10px 12px; margin-bottom: 1rem; padding: 0.75rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.rep-range-f { display: grid; gap: 4px; }
.rep-range-arrow { display: grid; place-items: center; height: 2.5rem; color: var(--lg-faint); }
.rep-run { margin-left: auto; }
@media (max-width: 560px) { .rep-range-arrow { display: none; } .rep-range-f, .rep-run { width: 100%; } .rep-run { justify-content: center; } }

.rep-cell-sessions { display: inline-block; margin-left: 0.2rem; font-size: 0.66rem; font-weight: 800; color: #0369a1; }
.rep-map-link { display: inline-flex; align-items: center; gap: 0.3rem; color: #2563eb; font-weight: 600; }
.rep-map-link:hover { text-decoration: underline; }
html.dark .rep-map-link { color: #93c5fd; }
.rep-day { line-height: 1.15; }
.rep-day small { display: block; font-size: 0.66rem; font-weight: 600; color: var(--lg-faint); }
.lg-table th.is-weekend, .lg-table td.is-weekend { background: rgba(148, 163, 184, 0.09); color: var(--lg-faint); }
.lg-table th.is-today, .lg-table td.is-today { background: rgba(47, 143, 230, 0.1); box-shadow: inset 0 -2px 0 #2f8fe6; }
.lg-table td.rep-late { color: #b45309; font-weight: 700; }
.lg-table td.rep-miss { color: #b91c1c; font-weight: 800; }
html.dark .lg-table td.rep-late { color: #fcd34d; }
html.dark .lg-table td.rep-miss { color: #fca5a5; }
.rep-total-row td { border-top: 2px solid var(--lg-line); background: var(--lg-soft); font-weight: 800; font-variant-numeric: tabular-nums; }
</style>
