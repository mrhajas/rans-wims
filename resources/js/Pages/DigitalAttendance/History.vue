<template>
  <admin-layout :title="$t('Attendance History')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="list" :title="$t('Attendance History')" :subtitle="$t('Every day you clocked in, with hours worked and status.')">
        <Link :href="route('digital-attendance.show')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 rotate-90" />{{ $t('Back') }}</Link>
        <a :href="route('digital-attendance.my-print', filters)" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="route('digital-attendance.my-pdf', filters)" class="lg-btn lg-btn-primary"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card lg-filters relative z-20 print:hidden">
        <div class="lg-grid" style="margin-top: 0">
          <div class="hist-search">
            <icons name="search" class="w-4 h-4 hist-search-icon" />
            <input v-model="search" type="text" :placeholder="$t('Search date or status…')" class="hist-search-input" />
            <button v-if="search" type="button" class="hist-search-clear" @click="search = ''"><icons name="cross" class="w-3 h-3" /></button>
          </div>
          <date-picker v-model="dateForm.date_from" :placeholder="$t('Date From')" clearable />
          <date-picker v-model="dateForm.date_to" :placeholder="$t('Date To')" clearable />
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Date') }}</th>
              <th>{{ $t('Clock In') }}</th>
              <th>{{ $t('Clock Out') }}</th>
              <th>{{ $t('Worked') }}</th>
              <th>{{ $t('Status') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in filteredRecords" :key="r.id">
              <tr :class="{ 'is-click': r.sessions_count > 1 }" @click="r.sessions_count > 1 && toggleSessions(r)">
                <td :data-label="$t('Date')">
                  <div class="flex items-center gap-2 flex-wrap justify-end md:justify-start">
                    <span class="lg-main">{{ $date(r.attendance_date) }}</span>
                    <button
                      v-if="r.sessions_count > 1"
                      type="button"
                      class="lg-chip lg-chip-cyan lg-tip tip-left hist-session-chip"
                      :data-tip="r.sessions_count + ' ' + $t('clock-in sessions today, all counted under one record.')"
                      @click.stop="toggleSessions(r)"
                    >
                      &times;{{ r.sessions_count }} {{ $t('sessions') }}
                      <icons name="chevron-down" class="w-3 h-3 hist-session-chevron" :class="{ 'rotate-180': expandedId === r.id }" />
                    </button>
                  </div>
                </td>
                <td :data-label="$t('Clock In')">{{ r.clock_in_at ? $time(r.clock_in_at) : '—' }}<span v-if="r.sessions_count > 1" class="lg-subtext" style="display: inline"> ({{ $t('latest') }})</span></td>
                <td :data-label="$t('Clock Out')">{{ r.clock_out_at ? $time(r.clock_out_at) : '—' }}</td>
                <td :data-label="$t('Worked')"><span class="lg-main hist-worked">{{ r.worked_label }}</span></td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="badgeClass(r)">{{ statusLabel(r) }}</span>
                </td>
              </tr>
              <tr v-if="expandedId === r.id" class="lg-empty-row">
                <td colspan="5" class="hist-sessions-cell">
                  <div class="hist-sessions-box">
                    <div v-if="sessionsLoading" class="lg-subtext py-2">{{ $t('Loading sessions…') }}</div>
                    <div v-else class="hist-sessions-grid">
                      <div v-for="(s, i) in expandedSessions" :key="i" class="hist-session-card">
                        <div class="hist-session-num">{{ $t('Session') }} {{ i + 1 }}</div>
                        <div class="hist-session-times">
                          <span><icons name="lock-open" class="w-3 h-3" /> {{ s.clock_in_label }}</span>
                          <span class="opacity-50">&rarr;</span>
                          <span><icons name="lock-closed" class="w-3 h-3" /> {{ s.clock_out_label || $t('open') }}</span>
                        </div>
                        <div v-if="s.worked_label" class="hist-session-worked">{{ s.worked_label }}</div>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!filteredRecords.length" class="lg-empty-row">
              <td colspan="5" class="lg-empty">{{ search ? $t('No records match your search.') : $t('No attendance history yet.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="records.meta" :links="records.links" />
    </div>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Link, Pagination, DatePicker, PageHeader, StatTiles },
  props: { records: Object, filters: Object, stats: { type: Object, default: null } },
  data() {
    return {
      search: '',
      dateForm: { date_from: this.filters?.date_from || '', date_to: this.filters?.date_to || '' },
      expandedId: null,
      expandedSessions: [],
      sessionsLoading: false,
    };
  },
  watch: {
    dateForm: {
      handler: throttle(function () {
        this.applyDateFilter();
      }, 300),
      deep: true,
    },
  },
  computed: {
    statTiles() {
      if (!this.stats) return [];
      return [
        { icon: 'calendar', tone: 'emerald', label: this.$t('Days'), value: this.stats.days },
        { icon: 'clock', tone: 'navy', label: this.$t('Total'), value: this.stats.total_label },
        { icon: 'chart-bar', tone: 'teal', label: this.$t('Avg/Day'), value: this.stats.average_label },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Late'), value: this.stats.late },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Missing Clock-Out'), value: this.stats.missing_clock_out },
      ];
    },
    filteredRecords() {
      const q = this.search.trim().toLowerCase();
      if (!q) return this.records.data;
      return this.records.data.filter(r => {
        const date = this.$date(r.attendance_date).toLowerCase();
        const status = this.statusLabel(r).toLowerCase();
        return date.includes(q) || status.includes(q);
      });
    },
  },
  methods: {
    statusLabel(r) {
      if (r.status === 'MISSING_CLOCK_OUT') return this.$t('Missing Clock-Out');
      if (r.is_late) return this.$t('Late');
      if (r.status === 'CLOCKED_IN') return this.$t('Clocked In');
      return this.$t('Present');
    },
    badgeClass(r) {
      if (r.status === 'MISSING_CLOCK_OUT') return 'lg-chip-red';
      if (r.is_late) return 'lg-chip-amber';
      if (r.status === 'CLOCKED_IN') return 'lg-chip-blue';
      return 'lg-chip-green';
    },
    applyDateFilter() {
      this.$inertia.get(this.route('digital-attendance.history'), this.dateForm, { preserveState: true, preserveScroll: true, replace: true });
    },
    toggleSessions(r) {
      if (this.expandedId === r.id) {
        this.expandedId = null;
        return;
      }
      this.expandedId = r.id;
      this.expandedSessions = [];
      this.sessionsLoading = true;
      axios.get(this.route('digital-attendance.history-sessions', r.id)).then(res => {
        this.expandedSessions = res.data.sessions;
        this.sessionsLoading = false;
      });
    },
  },
};
</script>

<style scoped>
.hist-search { display: flex; align-items: center; gap: 0.5rem; min-width: 0; padding: 0 0.85rem; height: 2.6rem; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.hist-search:focus-within { border-color: #6ee7b7; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.14); }
.hist-search-icon { flex: none; color: var(--lg-faint); }
.hist-search-input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent !important; padding: 0 !important; font-size: 0.82rem; color: var(--lg-ink); }
.hist-search-input::placeholder { color: var(--lg-faint); }
.hist-search-clear { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 1.2rem; height: 1.2rem; border-radius: 9999px; background: var(--lg-soft); color: var(--lg-mute); }
.hist-search-clear:hover { background: var(--lg-line); }

.hist-worked { color: #047857; }
html.dark .hist-worked { color: #6ee7b7; }
.hist-session-chip { border: 0; cursor: pointer; text-transform: none; transition: transform 0.15s ease; }
.hist-session-chip:hover { transform: translateY(-1px); }
.hist-session-chevron { transition: transform 0.2s ease; }

.hist-sessions-cell { padding-top: 0 !important; }
.hist-sessions-box { padding: 0.9rem 1rem; border-radius: 14px; border: 1px dashed #7dd3fc; background: rgba(224, 242, 254, 0.4); white-space: normal; }
.hist-sessions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(11rem, 100%), 1fr)); gap: 0.6rem; }
.hist-session-card { padding: 0.55rem 0.75rem; border-radius: 12px; background: var(--lg-card); border: 1px solid var(--lg-line); text-align: left; }
.hist-session-num { margin-bottom: 0.3rem; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #0369a1; }
.hist-session-times { display: flex; align-items: center; flex-wrap: wrap; gap: 0.35rem; font-size: 0.75rem; font-weight: 600; color: var(--lg-ink); }
.hist-session-times span { display: inline-flex; align-items: center; gap: 0.25rem; }
.hist-session-worked { margin-top: 0.25rem; font-size: 0.7rem; font-weight: 700; color: #059669; }
html.dark .hist-sessions-box { background: rgba(3, 105, 161, 0.12); border-color: rgba(56, 189, 248, 0.35); }
html.dark .hist-session-num { color: #7dd3fc; }
</style>
