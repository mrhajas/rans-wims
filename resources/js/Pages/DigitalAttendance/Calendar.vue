<template>
  <admin-layout :title="$t('Attendance Calendar')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="calendar" :title="$t('Attendance Calendar')" :subtitle="$t('Your month at a glance. Hover a day for the details.')">
        <button v-if="!isCurrentMonth" type="button" class="lg-btn lg-btn-soft" @click="goToday"><icons name="refresh" class="w-4 h-4" />{{ $t('Today') }}</button>
        <Link :href="route('digital-attendance.show')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 rotate-90" />{{ $t('Back') }}</Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="lg-card cal-card">
        <div class="cal-nav">
          <button type="button" class="lg-icon-btn" @click="shift(-1)"><icons name="chevron-down" class="w-4 h-4 rotate-90" /></button>
          <h3 class="cal-month">{{ monthLabel }}</h3>
          <button type="button" class="lg-icon-btn" @click="shift(1)"><icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
        </div>

        <div class="cal-grid cal-weekdays">
          <div v-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d">{{ d }}</div>
        </div>
        <div class="cal-grid">
          <div
            v-for="cell in cells"
            :key="cell.key"
            class="cal-cell"
            :class="[{ 'cal-cell-outside': !cell.inMonth, 'cal-cell-today': cell.isToday, 'cal-tooltip': cell.record }, cell.inMonth && cell.status ? `cal-cell-has-${cell.status.toLowerCase()}` : '']"
            :data-tooltip="cell.record ? cellTooltip(cell.record) : null"
          >
            <div class="cal-cell-day">{{ cell.day }}</div>
            <div v-if="cell.inMonth && cell.status" class="cal-cell-status" :class="'cal-status-' + cell.status.toLowerCase()">
              <span v-if="cell.status === 'PRESENT'">✓</span>
              <span v-else-if="cell.status === 'LATE'">⚠</span>
              <span v-else-if="cell.status === 'MISSING'">!</span>
            </div>
            <div v-else-if="cell.inMonth" class="cal-cell-status cal-status-none">—</div>
          </div>
        </div>

        <div class="cal-legend">
          <span class="lg-chip lg-chip-green"><span class="cal-legend-dot cal-status-present">✓</span> {{ $t('Present') }}</span>
          <span class="lg-chip lg-chip-amber"><span class="cal-legend-dot cal-status-late">⚠</span> {{ $t('Late') }}</span>
          <span class="lg-chip lg-chip-red"><span class="cal-legend-dot cal-status-missing">!</span> {{ $t('Missing Clock-Out') }}</span>
          <span class="lg-chip lg-chip-slate"><span class="cal-legend-dot cal-status-none">—</span> {{ $t('No Record') }}</span>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Link, PageHeader, StatTiles },
  props: { records: Array, month: Number, year: Number, attendance_settings: Object },
  computed: {
    isCurrentMonth() {
      const n = new Date();
      return n.getFullYear() === this.year && n.getMonth() + 1 === this.month;
    },
    statTiles() {
      const list = this.records || [];
      const late = list.filter(r => r.status !== 'MISSING_CLOCK_OUT' && r.is_late).length;
      const missing = list.filter(r => r.status === 'MISSING_CLOCK_OUT').length;
      const present = list.length - late - missing;
      const minutes = list.reduce((sum, r) => sum + (r.worked_minutes || 0), 0);
      return [
        { icon: 'calendar', tone: 'navy', label: this.$t('Days'), value: list.length },
        { icon: 'tick', tone: 'emerald', label: this.$t('Present'), value: present },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Late'), value: late },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Missing Clock-Out'), value: missing },
        { icon: 'clock', tone: 'sky', label: this.$t('Total'), value: `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m` },
      ];
    },
    monthLabel() {
      return new Date(this.year, this.month - 1, 1).toLocaleDateString([], { month: 'long', year: 'numeric' });
    },
    recordsByDate() {
      const map = {};
      this.records.forEach(r => (map[r.attendance_date] = r));
      return map;
    },
    cells() {
      const first = new Date(this.year, this.month - 1, 1);
      const startOffset = first.getDay();
      const daysInMonth = new Date(this.year, this.month, 0).getDate();
      const todayKey = new Date().toISOString().slice(0, 10);
      const cells = [];
      for (let i = 0; i < startOffset; i++) cells.push({ key: 'pre' + i, inMonth: false, day: '' });
      for (let d = 1; d <= daysInMonth; d++) {
        const dateKey = `${this.year}-${String(this.month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const r = this.recordsByDate[dateKey];
        let status = null;
        if (r) {
          status = r.status === 'MISSING_CLOCK_OUT' ? 'MISSING' : r.is_late ? 'LATE' : 'PRESENT';
        }
        cells.push({ key: dateKey, inMonth: true, day: d, status, isToday: dateKey === todayKey, record: r || null });
      }
      return cells;
    },
  },
  methods: {
    cellTooltip(r) {
      const parts = [];
      parts.push(`${this.$t('In')}: ${r.clock_in_at ? this.$time(r.clock_in_at) : '—'}`);
      parts.push(`${this.$t('Out')}: ${r.clock_out_at ? this.$time(r.clock_out_at) : '—'}`);
      parts.push(`${this.$t('Worked')}: ${r.worked_label}`);
      parts.push(r.status.replace('_', ' '));
      if (r.sessions_count > 1) parts.push(`${r.sessions_count} ${this.$t('sessions')}`);
      return parts.join(' · ');
    },
    goToday() {
      const n = new Date();
      this.$inertia.get(this.route('digital-attendance.calendar'), { month: n.getMonth() + 1, year: n.getFullYear() }, { preserveState: true });
    },
    shift(delta) {
      let m = this.month + delta;
      let y = this.year;
      if (m < 1) { m = 12; y -= 1; }
      if (m > 12) { m = 1; y += 1; }
      this.$inertia.get(this.route('digital-attendance.calendar'), { month: m, year: y }, { preserveState: true });
    },
  },
};
</script>

<style scoped>
.cal-card { max-width: 56rem; margin-left: auto; margin-right: auto; }
.cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.cal-month { font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); }
.cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.cal-weekdays { margin-bottom: 6px; text-align: center; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }

.cal-cell { display: flex; flex-direction: column; align-items: center; gap: 4px; min-height: 3.6rem; padding: 6px 2px; border-radius: 14px; background: var(--lg-soft); border: 1px solid transparent; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.cal-cell-outside { background: transparent; }
.cal-cell-day { font-size: 0.74rem; font-weight: 800; color: var(--lg-mute); }
.cal-cell-today { border-color: #34d399; box-shadow: 0 0 0 2px rgba(52, 211, 153, 0.25); }
.cal-cell-today .cal-cell-day { color: #047857; }
.cal-cell-has-present { background: rgba(16, 185, 129, 0.1); }
.cal-cell-has-late { background: rgba(245, 158, 11, 0.13); }
.cal-cell-has-missing { background: rgba(244, 63, 94, 0.11); }
.cal-cell-has-present:hover, .cal-cell-has-late:hover, .cal-cell-has-missing:hover { transform: translateY(-2px); box-shadow: 0 10px 18px -10px rgba(15, 23, 42, 0.3); }
.cal-cell-status { display: flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; }
.cal-status-present { background: #d1fae5; color: #047857; }
.cal-status-late { background: #fef3c7; color: #92400e; }
.cal-status-missing { background: #fee2e2; color: #b91c1c; }
.cal-status-none { background: transparent; color: var(--lg-faint); opacity: 0.6; }
html.dark .cal-status-present { background: rgba(16, 185, 129, 0.25); color: #6ee7b7; }
html.dark .cal-status-late { background: rgba(245, 158, 11, 0.25); color: #fcd34d; }
html.dark .cal-status-missing { background: rgba(244, 63, 94, 0.25); color: #fda4af; }

.cal-legend { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 1.25rem; }
.cal-legend-dot { display: inline-flex; align-items: center; justify-content: center; width: 1.2rem; height: 1.2rem; margin-left: -6px; border-radius: 9999px; font-size: 0.65rem; font-weight: 800; }

@media (max-width: 480px) {
  .cal-grid { gap: 3px; }
  .cal-cell { min-height: 3.1rem; border-radius: 10px; }
  .cal-cell-status { width: 1.25rem; height: 1.25rem; font-size: 0.68rem; }
}

/* Pure-CSS tooltip on hover. Wraps within a fixed max-width so it never has to
   reach far past its own cell; the two edge columns anchor to their own edge so
   the bubble grows inward across the grid rather than outward past it. */
.cal-tooltip { position: relative; cursor: help; }
.cal-tooltip::after {
  content: attr(data-tooltip);
  position: absolute; bottom: calc(100% + 0.4rem); left: 50%;
  transform: translateX(-50%) translateY(4px);
  background: #0f172a; color: #f1f5f9;
  font-size: 0.68rem; font-weight: 600; white-space: normal; width: max-content; max-width: 150px;
  text-align: center; line-height: 1.35; padding: 0.4rem 0.65rem; border-radius: 0.5rem;
  box-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.35);
  opacity: 0; pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease; z-index: 20;
}
.cal-tooltip::before {
  content: ''; position: absolute; bottom: calc(100% + 0.15rem); left: 50%; transform: translateX(-50%);
  border: 5px solid transparent; border-top-color: #0f172a;
  opacity: 0; pointer-events: none; transition: opacity 0.15s ease; z-index: 20;
}
.cal-tooltip:hover::after, .cal-tooltip:hover::before { opacity: 1; transform: translateX(-50%) translateY(0); }
.cal-tooltip:nth-child(7n + 1)::after { left: 0; transform: translateY(4px); }
.cal-tooltip:nth-child(7n + 1):hover::after { transform: translateY(0); }
.cal-tooltip:nth-child(7n + 1)::before { left: 1rem; }
.cal-tooltip:nth-child(7n)::after { left: auto; right: 0; transform: translateY(4px); }
.cal-tooltip:nth-child(7n):hover::after { transform: translateY(0); }
.cal-tooltip:nth-child(7n)::before { left: auto; right: 1rem; }
</style>
