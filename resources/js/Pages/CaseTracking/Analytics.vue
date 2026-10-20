<template>
  <admin-layout :title="$t('Case Tracking Analytics')">
    <div class="lg-page px-4 md:px-0 cs-analytics">
      <page-header icon="chart-pie" :title="$t('Analytics')" :subtitle="$t('Case Tracking Analytics')">
        <Link :href="route('case-tracking.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Case Tracking') }}</Link>
        <a :href="route('case-tracking.analytics.print', filters)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('case-tracking.analytics.pdf', filters)" class="lg-btn lg-btn-primary">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download PDF') }}
        </a>
      </page-header>

      <section class="lg-card cs-filters">
        <div class="lg-fields">
          <date-picker :label="$t('Date From')" v-model="form.date_from" @update:modelValue="search" />
          <date-picker :label="$t('Date To')" v-model="form.date_to" @update:modelValue="search" />
          <auto-complete :label="$t('Status')" v-model="form.status" :suggestions="statusOptions" clearable @update:modelValue="search" />
          <auto-complete :label="$t('Priority')" v-model="form.case_priority_id" :suggestions="priorityOptions" clearable @update:modelValue="search" />
        </div>
      </section>

      <stat-tiles :items="statTiles" />

      <div class="cs-charts-2">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Cases Created Over Time') }}</h2></div>
          </header>
          <vue-highcharts type="chart" :options="createdOverTimeOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Cases by Status') }}</h2></div>
          </header>
          <vue-highcharts type="chart" :options="byStatusOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
        </section>
      </div>

      <div class="cs-charts-3">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="chart-pie" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Cases by Category') }}</h2></div>
          </header>
          <vue-highcharts type="chart" :options="byCategoryOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="chart-pie" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Cases by Priority') }}</h2></div>
          </header>
          <vue-highcharts type="chart" :options="byPriorityOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Cases by Assignee') }}</h2></div>
          </header>
          <table v-if="Object.keys(byAssignee).length" class="lg-table cs-assignees">
            <tbody>
              <tr v-for="(count, name) in byAssignee" :key="name">
                <td class="lg-main">{{ name }}</td>
                <td class="num lg-main">{{ count }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else class="cs-empty">{{ $t('No assigned cases in this range.') }}</p>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import VueHighcharts from 'vue3-highcharts';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import debounce from 'lodash/debounce';

export default {
  components: { AdminLayout, Link, AutoComplete, DatePicker, VueHighcharts, PageHeader, StatTiles },

  props: {
    filters: Object,
    statuses: Array,
    categories: Array,
    priorities: Array,
    stats: Object,
    byStatus: Object,
    byCategory: Object,
    byPriority: Object,
    byAssignee: Object,
    createdOverTime: Object,
    resolvedOverTime: Object,
  },

  data() {
    return {
      form: {
        date_from: this.filters.date_from || '',
        date_to: this.filters.date_to || '',
        status: this.filters.status || null,
        case_priority_id: this.filters.case_priority_id || null,
      },
    };
  },

  computed: {
    statusOptions() {
      return this.statuses.map(s => ({ id: s, label: s }));
    },
    statTiles() {
      const s = this.stats;
      return [
        { icon: 'collection', tone: 'navy', label: this.$t('Total'), value: s.total },
        { icon: 'folder', tone: 'sky', label: this.$t('Open'), value: s.open },
        { icon: 'search', tone: 'amber', label: this.$t('In Review'), value: s.in_review },
        { icon: 'refresh', tone: 'indigo', label: this.$t('In Progress'), value: s.in_progress },
        { icon: 'tick', tone: 'emerald', label: this.$t('Resolved'), value: s.resolved, valueClass: 'is-good' },
        { icon: 'lock-closed', tone: 'slate', label: this.$t('Closed'), value: s.closed },
        { icon: 'exclamation', tone: 'rose', label: this.$t('High Priority'), value: s.high_priority },
        { icon: 'clock', tone: 'teal', label: this.$t('Avg. Resolution'), value: s.avg_resolution_hours !== null ? s.avg_resolution_hours + 'h' : '—' },
      ];
    },
    priorityOptions() {
      return this.priorities.map(p => ({ id: p.id, label: p.name }));
    },
    createdOverTimeOptions() {
      return {
        chart: { type: 'line', style: { fontFamily: 'Montserrat' } },
        credits: { enabled: false },
        title: { text: '' },
        colors: ['#9f1239'],
        xAxis: { categories: Object.keys(this.createdOverTime) },
        yAxis: { min: 0, title: { text: '' }, allowDecimals: false },
        series: [{ name: this.$t('Created'), data: Object.values(this.createdOverTime) }],
      };
    },
    byStatusOptions() {
      return {
        chart: { type: 'column', style: { fontFamily: 'Montserrat' } },
        credits: { enabled: false },
        title: { text: '' },
        colors: ['#9f1239'],
        xAxis: { categories: Object.keys(this.byStatus) },
        yAxis: { min: 0, title: { text: '' }, allowDecimals: false },
        legend: { enabled: false },
        series: [{ name: this.$t('Cases'), data: Object.values(this.byStatus) }],
      };
    },
    byCategoryOptions() {
      return this.pieOptions(this.byCategory);
    },
    byPriorityOptions() {
      return this.pieOptions(this.byPriority);
    },
  },

  methods: {
    pieOptions(dataObj) {
      return {
        chart: { type: 'pie', style: { fontFamily: 'Montserrat' } },
        credits: { enabled: false },
        title: { text: '' },
        colors: ['#9f1239', '#fb7185', '#f59e0b', '#0ea5e9', '#22c55e', '#94a3b8'],
        series: [{ name: this.$t('Cases'), data: Object.entries(dataObj).map(([name, y]) => ({ name, y })) }],
      };
    },
    search: debounce(function () {
      this.$inertia.get(this.route('case-tracking.analytics'), this.form, { preserveState: true, replace: true });
    }, 300),
  },
};
</script>

<style scoped>
.cs-filters { position: relative; z-index: 20; }
.cs-charts-2 { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.cs-charts-3 { display: grid; gap: 1rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 1100px) { .cs-charts-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 800px) { .cs-charts-2, .cs-charts-3 { grid-template-columns: minmax(0, 1fr); } }
.cs-charts-2 > .lg-card, .cs-charts-3 > .lg-card { min-width: 0; overflow: hidden; }
.cs-assignees { white-space: normal; }
.cs-empty { padding: 20px; text-align: center; font-size: 0.82rem; color: var(--lg-faint); border: 1px dashed var(--lg-line); border-radius: 14px; }
html.dark .cs-analytics :deep(.highcharts-background) { fill: transparent; }
html.dark .cs-analytics :deep(.highcharts-axis-labels text), html.dark .cs-analytics :deep(.highcharts-legend-item text), html.dark .cs-analytics :deep(.highcharts-data-label text) { fill: #cbd5e1 !important; color: #cbd5e1 !important; }
</style>
