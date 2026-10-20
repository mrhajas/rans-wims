<template>
  <admin-layout :title="$t('Material Reallocation')">
    <div class="lg-page px-4 md:px-0 mrl-page">
      <page-header icon="toggle" :title="$t('Material Reallocation')" :subtitle="$t('Return excess or unused site materials to a Main Warehouse.')">
        <button type="button" @click="showDashboard = !showDashboard" class="lg-btn lg-btn-dark" :aria-expanded="showDashboard">
          <icons name="chart-bar" class="w-4 h-4" />
          {{ $t('Analytics') }}
          <icons name="chevron-down" class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': showDashboard }" />
        </button>
        <Link v-if="canViewReports" :href="route('material-reallocations.reports')" class="lg-btn lg-btn-soft">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Reports') }}
        </Link>
        <Link v-if="canCreate" :href="route('material-reallocations.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Material Reallocation') }}
        </Link>
      </page-header>

      <transition name="slidedown">
        <div v-show="showDashboard" class="print:hidden">
          <section class="lg-stats lg-stats-tight">
            <button
              type="button"
              v-for="(card, idx) in numericCards"
              :key="card.key"
              class="lg-stat is-clickable mrl-stat"
              :class="{ 'mrl-live': card.live }"
              :style="{ animationDelay: `${idx * 40}ms` }"
              @click="applyCardFilter(card)"
            >
              <span v-if="card.live" class="mrl-live-dot" aria-hidden="true"></span>
              <span class="lg-stat-ico" :class="'lg-ico-' + toneOf(card.color)"><icons :name="card.icon" class="w-5 h-5" /></span>
              <div class="min-w-0 text-left">
                <div class="lg-stat-value"><animated-number :value="card.value" :prefix="card.prefix || ''" :use-grouping="card.useGrouping || false" /></div>
                <div class="lg-stat-label">{{ card.label }}</div>
              </div>
            </button>
          </section>

          <div class="mrl-grid">
            <section class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
                <div class="min-w-0"><h4 class="lg-card-title">{{ $t('Top Returned Materials') }}</h4></div>
              </header>
              <ol class="mrl-rank">
                <li v-for="(row, ri) in dashboard.cards.top_returned_materials" :key="row.item_id">
                  <span class="mrl-rank-no">{{ ri + 1 }}</span>
                  <span class="mrl-rank-name">{{ row.code }} - {{ row.name }}</span>
                  <span class="mrl-rank-val">{{ $number(row.quantity) }}</span>
                </li>
                <li v-if="!dashboard.cards.top_returned_materials || !dashboard.cards.top_returned_materials.length" class="mrl-rank-empty">
                  {{ $t('No completed reallocations yet.') }}
                </li>
              </ol>
            </section>
            <section class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-teal"><icons name="building" class="w-5 h-5" /></span>
                <div class="min-w-0"><h4 class="lg-card-title">{{ $t('Top Projects / Sites Returning Materials') }}</h4></div>
              </header>
              <ol class="mrl-rank">
                <li v-for="(row, ri) in dashboard.cards.top_projects_returning" :key="row.warehouse_id">
                  <span class="mrl-rank-no">{{ ri + 1 }}</span>
                  <span class="mrl-rank-name">{{ row.name }}</span>
                  <span class="mrl-rank-val">{{ row.count }}</span>
                </li>
                <li v-if="!dashboard.cards.top_projects_returning || !dashboard.cards.top_projects_returning.length" class="mrl-rank-empty">
                  {{ $t('No completed reallocations yet.') }}
                </li>
              </ol>
            </section>
          </div>

          <div class="mrl-grid">
            <div class="lg-card mrl-chart overflow-x-auto">
              <vue-highcharts type="chart" :options="statusPipelineOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
            </div>
            <div class="lg-card mrl-chart overflow-x-auto">
              <vue-highcharts type="chart" :options="monthlyTrendOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
            </div>
            <div class="lg-card mrl-chart overflow-x-auto">
              <vue-highcharts type="chart" :options="topItemsOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
            </div>
            <div class="lg-card mrl-chart overflow-x-auto">
              <vue-highcharts type="chart" :options="topProjectsOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
            </div>
            <div class="lg-card mrl-chart overflow-x-auto mrl-wide">
              <vue-highcharts type="chart" :options="avgDurationOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
            </div>
          </div>
        </div>
      </transition>

      <div class="txn-toolbar flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between relative z-30 print:hidden">
        <search-filter v-model="form.search" class="w-full max-w-lg" @reset="reset">
          <auto-complete
            id="status"
            position="left"
            :label="$t('Status')"
            v-model="form.status"
            class="mt-1 w-full"
            :suggestions="statusOptions"
          />

          <auto-complete
            id="source-warehouse"
            position="left"
            :label="$t('Source Warehouse')"
            v-model="form.source_warehouse_id"
            class="mt-1 w-full"
            :suggestions="warehouses"
          />

          <text-input type="date" v-model="form.start_date" :label="$t('Date From')" class="mt-1 w-full" />
          <text-input type="date" v-model="form.end_date" :label="$t('Date To')" class="mt-1 w-full" />
        </search-filter>

        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" @click="openPrint" class="lg-btn lg-btn-dark">
            <icons name="printer" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Print') }}</span>
          </button>
          <button type="button" @click="downloadPdf" class="lg-btn lg-btn-primary">
            <icons name="download" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Download PDF') }}</span>
          </button>
          <a :href="route('material-reallocations.index', currentFilters('excel'))" class="lg-btn lg-btn-green">
            <icons name="doc" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Excel') }}</span>
          </a>
        </div>
      </div>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th class="is-sortable" @click="setSort('reference')"><span class="lg-sort">{{ $t('Reference') }}<span class="text-xs">{{ sortIndicator('reference') }}</span></span></th>
              <th class="is-sortable" @click="setSort('date_requested')"><span class="lg-sort">{{ $t('Date Requested') }}<span class="text-xs">{{ sortIndicator('date_requested') }}</span></span></th>
              <th>{{ $t('Source Warehouse') }}</th>
              <th>{{ $t('Requested By') }}</th>
              <th class="is-sortable" @click="setSort('status')"><span class="lg-sort">{{ $t('Status') }}<span class="text-xs">{{ sortIndicator('status') }}</span></span></th>
              <th class="text-right">{{ $t('Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="materialReallocation in materialReallocations.data" :key="materialReallocation.id" class="is-click" @click="goto(materialReallocation.id)">
              <td :data-label="$t('Reference')"><span class="lg-main">{{ materialReallocation.reference }}</span></td>
              <td :data-label="$t('Date Requested')">{{ $date(materialReallocation.date_requested) }}</td>
              <td :data-label="$t('Source Warehouse')">{{ materialReallocation.source_warehouse?.name }}</td>
              <td :data-label="$t('Requested By')">{{ materialReallocation.user?.name }}</td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusBadgeClass(materialReallocation.status)" style="text-transform: none">{{ materialReallocation.status }}</span>
              </td>
              <td data-label="" @click.stop>
                <div class="lg-actions">
                  <Link
                    v-if="materialReallocation.can_view"
                    :href="route('material-reallocations.show', materialReallocation.id)"
                    class="lg-icon-btn lg-tip"
                    :data-tip="$t('View')"
                    :aria-label="$t('View')"
                  >
                    <icons name="eye-flat" class="w-4 h-4" />
                  </Link>
                  <Link
                    v-if="materialReallocation.can_edit"
                    :href="route('material-reallocations.edit', materialReallocation.id)"
                    class="lg-icon-btn lg-tip"
                    :data-tip="$t('Edit')"
                    :aria-label="$t('Edit')"
                  >
                    <icons name="edit" class="w-4 h-4" />
                  </Link>
                  <button
                    v-if="materialReallocation.can_delete"
                    type="button"
                    @click.stop="deleteRecord(materialReallocation)"
                    class="lg-icon-btn is-red lg-tip"
                    :data-tip="$t('Delete')"
                    :aria-label="$t('Delete')"
                  >
                    <icons name="trash" class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="materialReallocations.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="materialReallocations.meta" :links="materialReallocations.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import VueHighcharts from 'vue3-highcharts';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';

const CHART_COLORS = ['#059669', '#D97706', '#4F46E5', '#DC2626', '#3182CE', '#DB2777', '#4B5563', '#805AD5', '#38B2AC', '#ECC94B'];

export default {
  components: {
    PageHeader,
    Pagination,
    AdminLayout,
    TextInput,
    AutoComplete,
    SearchFilter,
    AnimatedNumber,
    VueHighcharts,
  },

  props: {
    filters: Object,
    statuses: Array,
    warehouses: Array,
    canCreate: Boolean,
    canViewReports: Boolean,
    materialReallocations: Object,
    dashboard: Object,
  },

  data() {
    return {
      showDashboard: false,
      form: {
        search: this.filters.search,
        status: this.filters.status,
        source_warehouse_id: this.filters.source_warehouse_id,
        start_date: this.filters.start_date,
        end_date: this.filters.end_date,
        sort: this.filters.sort,
        sort_direction: this.filters.sort_direction,
      },
    };
  },

  computed: {
    statusOptions() {
      return (this.statuses || []).map(s => ({ label: this.$t(s), value: s }));
    },

    numericCards() {
      const c = this.dashboard.cards || {};
      return [
        { key: 'draft', value: c.draft || 0, label: this.$t('Draft'), icon: 'doc-text', filterStatus: 'Draft', color: 'slate' },
        { key: 'waiting_pm_clearance', value: c.waiting_pm_clearance || 0, label: this.$t('Waiting for PM Clearance'), icon: 'clock', filterStatus: 'Submitted', color: 'amber' },
        { key: 'waiting_warehouse_allocation', value: c.waiting_warehouse_allocation || 0, label: this.$t('Waiting for Warehouse Allocation'), icon: 'building', filterStatus: 'Project Manager Clearance', color: 'amber' },
        { key: 'waiting_dispatch', value: c.waiting_dispatch || 0, label: this.$t('Waiting for Dispatch'), icon: 'truck', filterStatus: 'Warehouse Allocation', color: 'amber', live: true },
        { key: 'in_transit', value: c.in_transit || 0, label: this.$t('In Transit'), icon: 'plane', filterStatus: 'Dispatch Preparation', color: 'blue', live: true },
        { key: 'waiting_final_approval', value: c.waiting_final_approval || 0, label: this.$t('Waiting for Final Approval'), icon: 'exclamation', filterStatus: 'Warehouse Receiving', color: 'amber', live: true },
        { key: 'completed_today', value: c.completed_today || 0, label: this.$t('Completed Today'), icon: 'tick', filterStatus: 'Completed', color: 'emerald' },
        { key: 'completed_this_month', value: c.completed_this_month || 0, label: this.$t('Completed This Month'), icon: 'collection', filterStatus: 'Completed', color: 'emerald' },
        { key: 'rejected', value: c.rejected || 0, label: this.$t('Rejected'), icon: 'cross', filterStatus: 'Rejected', color: 'rose' },
        { key: 'cancelled', value: c.cancelled || 0, label: this.$t('Cancelled'), icon: 'trash', filterStatus: 'Cancelled', color: 'rose' },
        { key: 'total_material_reallocated_this_month', value: c.total_material_reallocated_this_month || 0, label: this.$t('Total Material Reallocated (This Month)'), icon: 'bag', filterStatus: 'Completed', color: 'indigo' },
        { key: 'total_value_reallocated_this_month', value: c.total_value_reallocated_this_month || 0, label: this.$t('Total Value Reallocated (This Month)'), icon: 'chart-pie', filterStatus: 'Completed', prefix: 'Tsh ', useGrouping: true, color: 'indigo' },
        { key: 'avg_pm_approval_hours', value: c.avg_pm_approval_hours || 0, label: this.$t('Avg. PM Approval Time (hrs)'), icon: 'clock', color: 'violet' },
        { key: 'avg_transit_hours', value: c.avg_transit_hours || 0, label: this.$t('Avg. Transit Time (hrs)'), icon: 'truck', color: 'violet' },
        { key: 'avg_receiving_to_approval_hours', value: c.avg_receiving_to_approval_hours || 0, label: this.$t('Avg. Receiving-to-Approval Time (hrs)'), icon: 'tick', color: 'violet' },
      ];
    },

    baseChartConfig() {
      return {
        credits: { enabled: false },
        chart: { style: { fontFamily: "'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, sans-serif" }, spacingTop: 20, backgroundColor: 'transparent' },
        colors: CHART_COLORS,
        tooltip: {
          shared: true,
          shadow: false,
          useHTML: true,
          borderRadius: '5',
          borderColor: '#1F2937',
          style: { color: '#fff' },
          backgroundColor: '#1F2937',
        },
      };
    },

    statusPipelineOptions() {
      const points = this.dashboard.charts?.statusPipeline || [];
      return {
        ...this.baseChartConfig,
        chart: { ...this.baseChartConfig.chart, type: 'column' },
        title: { text: this.$t('Status Pipeline'), style: { fontWeight: 'bold', paddingTop: '10px' } },
        xAxis: { categories: points.map(p => this.$t(p.name)), crosshair: true },
        yAxis: { min: 0, title: { text: '' } },
        legend: { enabled: false },
        plotOptions: { column: { pointPadding: 0.2, borderWidth: 0 } },
        series: [{ name: this.$t('Records'), data: points.map(p => p.y) }],
      };
    },

    monthlyTrendOptions() {
      const trend = this.dashboard.charts?.monthlyTrend || { categories: [], data: [] };
      return {
        ...this.baseChartConfig,
        chart: { ...this.baseChartConfig.chart, type: 'spline' },
        title: { text: this.$t('Reallocations Per Month'), style: { fontWeight: 'bold', paddingTop: '10px' } },
        xAxis: { categories: trend.categories.map(d => this.$month(d)) },
        yAxis: { min: 0, title: { text: '' } },
        legend: { enabled: false },
        series: [{ type: 'spline', name: this.$t('Reallocations'), data: trend.data }],
      };
    },

    topItemsOptions() {
      const points = this.dashboard.charts?.topItems || [];
      return {
        ...this.baseChartConfig,
        chart: { ...this.baseChartConfig.chart, type: 'pie' },
        title: { text: this.$t('Top Returned Materials'), style: { fontWeight: 'bold', paddingTop: '10px' } },
        series: [{ colorByPoint: true, name: this.$t('Quantity'), data: points }],
      };
    },

    topProjectsOptions() {
      const points = this.dashboard.charts?.topProjects || [];
      return {
        ...this.baseChartConfig,
        chart: { ...this.baseChartConfig.chart, type: 'column' },
        title: { text: this.$t('Top Projects / Sites Returning Materials'), style: { fontWeight: 'bold', paddingTop: '10px' } },
        xAxis: { categories: points.map(p => p.name), crosshair: true },
        yAxis: { min: 0, title: { text: '' } },
        legend: { enabled: false },
        plotOptions: { column: { pointPadding: 0.2, borderWidth: 0 } },
        series: [{ name: this.$t('Reallocations'), data: points.map(p => p.y) }],
      };
    },

    avgDurationOptions() {
      const points = this.dashboard.charts?.avgDuration || [];
      return {
        ...this.baseChartConfig,
        chart: { ...this.baseChartConfig.chart, type: 'bar' },
        title: { text: this.$t('Average Duration By Stage (hours)'), style: { fontWeight: 'bold', paddingTop: '10px' } },
        xAxis: { categories: points.map(p => this.$t(p.name)) },
        yAxis: { min: 0, title: { text: '' } },
        legend: { enabled: false },
        series: [{ name: this.$t('Hours'), data: points.map(p => p.y) }],
      };
    },
  },

  created() {
    this.visitWithCurrentFilters = throttle(() => {
      const query = pickBy(this.form);
      this.$inertia.visit(this.route('material-reallocations.index', Object.keys(query).length ? query : { remember: 'forget' }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
          const search = document.getElementById('page-search');
          if (search) {
            search.focus();
            const len = search.value ? search.value.length : 0;
            search.setSelectionRange(len, len);
          }
        },
      });
    }, 250);
  },

  watch: {
    form: {
      handler() {
        this.visitWithCurrentFilters();
      },
      deep: true,
    },
  },

  methods: {
    toneOf(color) {
      return { slate: 'slate', amber: 'amber', blue: 'sky', emerald: 'emerald', rose: 'rose', indigo: 'indigo', violet: 'violet' }[color] || 'navy';
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    applyCardFilter(card) {
      this.showDashboard = false;

      if (!card.filterStatus) {
        return;
      }

      this.form.search = null;
      this.form.source_warehouse_id = null;
      this.form.start_date = null;
      this.form.end_date = null;
      this.form.status = card.filterStatus;
    },
    goto(id) {
      this.$inertia.visit(route('material-reallocations.show', id));
    },
    async deleteRecord(materialReallocation) {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this material reallocation?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.delete(route('material-reallocations.destroy', materialReallocation.id));
    },
    currentFilters(output = null) {
      const params = pickBy(this.form);
      if (output) {
        params.output = output;
      }
      return params;
    },
    openPrint() {
      window.open(this.route('material-reallocations.index', this.currentFilters('print')), '_blank');
    },
    downloadPdf() {
      window.open(this.route('material-reallocations.index', this.currentFilters('pdf')), '_blank');
    },
    setSort(column) {
      if (this.form.sort === column) {
        this.form.sort_direction = this.form.sort_direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.sort_direction = 'asc';
      }
    },
    sortIndicator(column) {
      if (this.form.sort !== column) return '';
      return this.form.sort_direction === 'asc' ? '▲' : '▼';
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate',
        Submitted: 'lg-chip-amber',
        'Project Manager Clearance': 'lg-chip-amber',
        'Warehouse Allocation': 'lg-chip-amber',
        'Dispatch Preparation': 'lg-chip-blue',
        'Warehouse Receiving': 'lg-chip-amber',
        Completed: 'lg-chip-green',
        Rejected: 'lg-chip-red',
        Cancelled: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.mrl-stat { position: relative; overflow: hidden; }
.mrl-stat.is-clickable { cursor: pointer; }
.mrl-live-dot {
  position: absolute; top: 10px; right: 10px; height: 7px; width: 7px; border-radius: 9999px;
  background: #f59e0b; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.6);
  animation: mrl-dot-pulse 1.8s ease-in-out infinite;
}
@keyframes mrl-dot-pulse {
  0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.55); }
  70% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
  100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
}
@media (prefers-reduced-motion: reduce) { .mrl-live-dot { animation: none; } }

.mrl-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); gap: 1rem; margin-bottom: 1rem; }
.mrl-grid > .lg-card { margin-bottom: 0; min-width: 0; }
.mrl-chart { padding: 1rem; }
.mrl-wide { grid-column: 1 / -1; }

.mrl-rank { list-style: none; margin: 0; padding: 0; }
.mrl-rank li { display: flex; align-items: center; gap: 10px; padding: 0.55rem 0; font-size: 0.85rem; border-bottom: 1px dashed var(--lg-line); }
.mrl-rank li:last-child { border-bottom: 0; }
.mrl-rank-no { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #4338ca; background: rgba(99, 102, 241, 0.13); }
html.dark .mrl-rank-no { color: #c7d2fe; }
.mrl-rank-name { flex: 1; min-width: 0; overflow-wrap: anywhere; color: var(--lg-ink); font-weight: 600; }
.mrl-rank-val { flex: none; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.mrl-rank .mrl-rank-empty { display: block; color: var(--lg-faint); }
</style>
