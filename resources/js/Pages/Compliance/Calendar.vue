<template>
  <admin-layout :title="$t('Compliance Calendar')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="calendar"
        :title="$t('Compliance Calendar')"
        :subtitle="$t('Expiry, auto renewal, renewal, submission, review, loan payment, and contract notice dates: six months at a glance.')"
      >
        <button type="button" class="lg-btn lg-btn-soft" @click="goPrev"><icons name="chevron-down" class="w-4 h-4 rotate-90" /> {{ $t('Previous 6 Months') }}</button>
        <button type="button" class="lg-btn lg-btn-soft" @click="goNext">{{ $t('Next 6 Months') }} <icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
        <Link :href="route('compliance-records.index')" class="lg-btn lg-btn-primary">
          <icons name="list" class="w-4 h-4" /> {{ $t('View Register') }}
        </Link>
      </page-header>

      <div class="cp-legend">
        <span class="cp-legend-item"><span class="cp-legend-dot cp-legend-critical"></span>{{ $t('Critical (expired / ≤7 days)') }}</span>
        <span class="cp-legend-item"><span class="cp-legend-dot cp-legend-warning"></span>{{ $t('Upcoming (≤30 days) / Contract notice') }}</span>
        <span class="cp-legend-item"><span class="cp-legend-dot cp-legend-info"></span>{{ $t('Renewal / Submission / Review / Payment') }}</span>
        <span class="cp-legend-item"><span class="cp-legend-dot cp-legend-auto"></span>{{ $t('Auto renewal day / its notice days') }}</span>
      </div>

      <div class="cp-calendar-grid">
        <month-calendar
          v-for="m in months"
          :key="`${m.year}-${m.month}`"
          :year="m.year"
          :month="m.month"
          :events="events"
          hide-nav
          @event-click="onEventClick"
        />
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MonthCalendar from '@/Shared/Calendar/MonthCalendar.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, MonthCalendar, PageHeader, Link },
  props: { events: Array, month: Number, year: Number, months: Array },

  methods: {
    goPrev() {
      this.shiftWindow(-6);
    },
    goNext() {
      this.shiftWindow(6);
    },
    // Steps by a full 6-month window rather than 1 month, matching the
    // page's 6-month span — so Prev/Next always slide to a fresh set of
    // six months instead of just shifting the grid by one.
    shiftWindow(delta) {
      const totalMonths = this.year * 12 + (this.month - 1) + delta;
      this.navigate((((totalMonths % 12) + 12) % 12) + 1, Math.floor(totalMonths / 12));
    },
    navigate(month, year) {
      this.$inertia.visit(this.route('compliance-records.calendar', { month, year }), { preserveState: true });
    },
    onEventClick(ev) {
      if (ev.url) {
        this.$inertia.visit(ev.url);
      }
    },
  },
};
</script>

<style scoped>
.cp-calendar-grid { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: 1.25rem; }
.cp-calendar-grid > * { min-width: 0; }
@media (min-width: 1024px) { .cp-calendar-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1536px) { .cp-calendar-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.cp-legend { display: flex; gap: 0.5rem 1.25rem; flex-wrap: wrap; margin-bottom: 1rem; padding: 0.65rem 1rem; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); font-size: 0.74rem; font-weight: 700; color: var(--lg-mute); }
@media (max-width: 640px) { .cp-legend { border-radius: 18px; } }
.cp-legend-item { display: inline-flex; align-items: center; gap: 7px; }
.cp-legend-dot { display: inline-block; width: 0.65rem; height: 0.65rem; border-radius: 9999px; }
.cp-legend-critical { background: #be123c; }
.cp-legend-warning { background: #b45309; }
.cp-legend-info { background: #1d4ed8; }
.cp-legend-auto { background: #0d9488; }
</style>
