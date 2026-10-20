<template>
  <admin-layout :title="$t('Total Records')">
    <div class="lg-page px-4 md:px-0">
      <offline-report-notice v-if="$connection.status === 'offline'" class="mb-4" />

      <page-header
        icon="chart-bar"
        :title="$t('Executive Internal KPI Dashboard')"
        :subtitle="$t('Live internal metrics snapshot for year') + ' (' + dashboardKpi.year_label + ')'"
      />

      <stat-tiles :items="kpiTiles" />

      <div class="ra-two">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Top 3 expensive materials') }}</h2>
            </div>
          </header>
          <ol v-if="dashboardKpi.top_expensive_materials.length" class="ra-list">
            <li v-for="(material, idx) in dashboardKpi.top_expensive_materials" :key="`material-${material.id}`" class="ra-li">
              <span class="ra-rank">{{ idx + 1 }}</span>
              <span class="min-w-0 flex-1 truncate">{{ material.name }}</span>
              <span class="ra-amt">{{ formatCurrency(material.value) }}</span>
            </li>
          </ol>
          <p v-else class="lg-empty">{{ $t('No data yet') }}</p>
          <div v-if="dashboardKpi.top_expensive_materials.length" class="ra-chart print:hidden">
            <vue-highcharts type="chart" :options="materialsPieOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Top 3 consuming projects') }}</h2>
            </div>
          </header>
          <ol v-if="dashboardKpi.top_consuming_projects.length" class="ra-list">
            <li
              v-for="(project, idx) in dashboardKpi.top_consuming_projects"
              :key="`project-${idx}-${project.name}`"
              class="ra-li"
            >
              <span class="ra-rank">{{ idx + 1 }}</span>
              <span class="min-w-0 flex-1 truncate">{{ project.name }}</span>
              <span class="ra-amt">{{ formatCurrency(project.value) }}</span>
            </li>
          </ol>
          <p v-else class="lg-empty">{{ $t('No data yet') }}</p>
          <div v-if="dashboardKpi.top_consuming_projects.length" class="ra-chart print:hidden">
            <vue-highcharts type="chart" :options="projectsBarOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" />
          </div>
        </section>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Total Records') }}</h2>
            <p class="lg-card-sub">{{ $t('Please review the total records below') }}</p>
          </div>
        </header>
        <div class="ra-grid">
          <Link v-for="(card, idx) in totalRecordCards" :key="card.key" :href="route(card.route)" class="ra-tile">
            <span class="lg-stat-ico" :class="chipClass(idx)"><icons :name="statIcon(card.key)" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-stat-value">{{ data[card.key] }}</div>
              <div class="lg-stat-label">{{ card.label }}</div>
            </div>
          </Link>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Report Links') }}</h2>
            <p class="lg-card-sub">{{ $t('Please click to view the report') }}</p>
          </div>
        </header>
        <div class="ra-grid ra-grid-wide">
          <Link v-for="(report, idx) in reportLinks" :key="report.route" :href="route(report.route)" class="ra-tile ra-link-tile">
            <span class="lg-stat-ico" :class="chipClass(idx)"><icons :name="report.icon" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <p class="ra-link-title">{{ report.label }}</p>
              <p class="ra-link-desc">{{ report.description }}</p>
            </div>
          </Link>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Project(s) / Warehouse(s) Map') }}</h2>
            <p class="lg-card-sub">{{ $t('Click a pin to open that project/warehouse\'s stock report') }}</p>
          </div>
        </header>
        <warehouse-map :warehouses="mapWarehouses" />
      </section>
    </div>
  </admin-layout>
</template>

<script>
import VueHighcharts from 'vue3-highcharts';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import WarehouseMap from '@/Shared/WarehouseMap.vue';

const CHIP_PALETTE = ['lg-ico-navy', 'lg-ico-violet', 'lg-ico-emerald', 'lg-ico-amber', 'lg-ico-rose', 'lg-ico-teal', 'lg-ico-indigo', 'lg-ico-slate'];

export default {
  props: ['data', 'kpi', 'mapWarehouses'],
  computed: {
    totalRecordCards() {
      return [
        { key: 'items', route: 'items.index', label: this.$t('Items') },
        { key: 'contacts', route: 'contacts.index', label: this.$t('Contacts') },
        { key: 'categories', route: 'categories.index', label: this.$t('Categories') },
        { key: 'warehouses', route: 'warehouses.index', label: this.$t('Warehouses') },
        { key: 'checkins', route: 'checkins.index', label: this.$t('Checkins') },
        { key: 'checkouts', route: 'checkouts.index', label: this.$t('Checkouts') },
        { key: 'transfers', route: 'transfers.index', label: this.$t('Transfers') },
        { key: 'adjustments', route: 'adjustments.index', label: this.$t('Adjustments') },
        { key: 'units', route: 'units.index', label: this.$t('Units') },
        { key: 'users', route: 'users.index', label: this.$t('Users') },
        { key: 'roles', route: 'roles.index', label: this.$t('Roles') },
      ].filter((card) => Object.prototype.hasOwnProperty.call(this.data || {}, card.key));
    },
    kpiTiles() {
      const k = this.dashboardKpi;

      return [
        { icon: 'doc-text', tone: 'teal', label: this.$t('Total inventory value'), value: this.formatCurrency(k.total_inventory_value) },
        { icon: 'bag', tone: 'emerald', label: this.$t('Yearly consumption'), value: this.formatCurrency(k.yearly_consumption) },
        { icon: 'building', tone: 'amber', label: this.$t('Active projects'), value: this.formatNumber(k.active_projects) },
      ];
    },
    dashboardKpi() {
      return {
        year_label: this.kpi?.year_label || '2026',
        total_inventory_value: Number(this.kpi?.total_inventory_value || 0),
        yearly_consumption: Number(this.kpi?.yearly_consumption ?? this.kpi?.monthly_consumption ?? 0),
        active_projects: Number(this.kpi?.active_projects || 0),
        top_expensive_materials: Array.isArray(this.kpi?.top_expensive_materials) ? this.kpi.top_expensive_materials : [],
        top_consuming_projects: Array.isArray(this.kpi?.top_consuming_projects) ? this.kpi.top_consuming_projects : [],
      };
    },
    hasChartData() {
      return this.dashboardKpi.top_expensive_materials.length > 0 || this.dashboardKpi.top_consuming_projects.length > 0;
    },
    materialsPieOptions() {
      const vm = this;
      const materials = this.dashboardKpi.top_expensive_materials;
      return {
        chart: { type: 'pie', style: { fontFamily: 'Montserrat' }, height: 240 },
        credits: { enabled: false },
        title: { text: undefined },
        colors: ['#4f46e5', '#0891b2', '#d97706'],
        tooltip: {
          shadow: false,
          useHTML: true,
          borderRadius: 8,
          borderColor: '#1F2937',
          style: { color: '#fff' },
          backgroundColor: '#1F2937',
          formatter() {
            return `<div style="min-width:150px"><div style="font-weight:700;margin-bottom:2px">${this.point.name}</div><div>${vm.formatCurrency(this.point.y)}</div></div>`;
          },
        },
        plotOptions: {
          pie: {
            innerSize: '55%',
            animation: { duration: 900 },
            allowPointSelect: true,
            dataLabels: { enabled: true, format: '{point.percentage:.0f}%', style: { fontSize: '10px', fontWeight: '600', textOutline: 'none' } },
          },
        },
        legend: { itemStyle: { fontSize: '11px' } },
        series: [{ name: this.$t('Value'), colorByPoint: true, data: materials.map(m => ({ name: m.name, y: Number(m.value) || 0 })) }],
      };
    },
    projectsBarOptions() {
      const vm = this;
      const projects = this.dashboardKpi.top_consuming_projects;
      return {
        chart: { type: 'bar', style: { fontFamily: 'Montserrat' }, height: 240 },
        credits: { enabled: false },
        title: { text: undefined },
        colors: ['#e11d48', '#f59e0b', '#4f46e5'],
        xAxis: { categories: projects.map(p => p.name), labels: { style: { fontSize: '11px' } } },
        yAxis: { min: 0, title: { text: undefined } },
        legend: { enabled: false },
        tooltip: {
          shadow: false,
          useHTML: true,
          borderRadius: 8,
          borderColor: '#1F2937',
          style: { color: '#fff' },
          backgroundColor: '#1F2937',
          formatter() {
            return `<div style="min-width:150px"><div style="font-weight:700;margin-bottom:2px">${this.point.category}</div><div>${vm.formatCurrency(this.point.y)}</div></div>`;
          },
        },
        plotOptions: {
          series: {
            animation: { duration: 900 },
            colorByPoint: true,
            borderRadius: 4,
            dataLabels: { enabled: false },
          },
        },
        series: [{ name: this.$t('Value'), data: projects.map(p => Number(p.value) || 0) }],
      };
    },
    reportLinks() {
      const links = [
        {
          route: 'reports.checkin',
          label: this.$t('x_report', { x: this.$t('Checkin') }),
          description: this.$t('Inbound material transactions'),
          icon: 'collection',
        },
        {
          route: 'reports.checkout',
          label: this.$t('x_report', { x: this.$t('Checkout') }),
          description: this.$t('Material usage and issue records'),
          icon: 'bag',
        },
        {
          route: 'reports.transfer',
          label: this.$t('x_report', { x: this.$t('Transfer') }),
          description: this.$t('Inter-warehouse movement report'),
          icon: 'doc-text',
        },
        {
          route: 'reports.adjustment',
          label: this.$t('x_report', { x: this.$t('Adjustment') }),
          description: this.$t('Damage, addition and subtraction records'),
          icon: 'chip',
        },
        {
          route: 'reports.warehousestock',
          label: this.$t('x_report', { x: this.$t('Warehouse Stock') }),
          description: this.$t('Current stock by warehouse'),
          icon: 'building',
        },
        {
          route: 'reports.warehouseamounts',
          label: this.$t('x_report', { x: this.$t('Warehouse Value') }),
          description: this.$t('Total stock value per warehouse'),
          icon: 'chart-pie',
        },
        {
          route: 'reports.most_used_material',
          label: this.$t('Material Used'),
          description: this.$t('Top used materials in selected period'),
          icon: 'chart-bar',
        },
        {
          route: 'reports.item_transactional',
          label: this.$t('Item Transactional Report'),
          description: this.$t('Checkin and transfer transactions by item, date, and warehouse'),
          icon: 'doc-text',
        },
        {
          route: 'reports.usage_material_per_site_cost',
          label: this.$t('Usage Material Per Site'),
          description: this.$t('Usage quantity and cost by selected site'),
          icon: 'building',
        },
        {
          route: 'reports.timely_material_usage',
          label: this.$t('Timely Material Usage'),
          description: this.$t('Accumulated checked out materials by project and period'),
          icon: 'chart-bar',
        },
        {
          route: 'reports.boq_material_compliance',
          label: this.$t('BOQ Material Compliance'),
          description: this.$t('BOQ baseline vs issued quantity, variance, and compliance insights'),
          icon: 'collection',
        },
        {
          route: 'reports.material_reallocation_summary',
          label: this.$t('Material Reallocation Summary'),
          description: this.$t('Header-level summary of all material reallocations'),
          icon: 'truck',
        },
        {
          route: 'reports.material_movement',
          label: this.$t('Material Movement Report'),
          description: this.$t('Line-item detail of what moved from source to destination'),
          icon: 'collection',
        },
        {
          route: 'reports.driver_dispatch',
          label: this.$t('Driver Dispatch Report'),
          description: this.$t('Dispatched shipments by driver and vehicle'),
          icon: 'truck',
        },
        {
          route: 'reports.warehouse_receiving',
          label: this.$t('Warehouse Receiving Report'),
          description: this.$t('Items received at destination warehouses'),
          icon: 'doc-text',
        },
        {
          route: 'reports.discrepancy',
          label: this.$t('Discrepancy Report'),
          description: this.$t('Received vs allocated quantity mismatches'),
          icon: 'chart-bar',
        },
      ];

      // Smart Brief reports are only listed for people who may open them (the page itself is also checked on the server)
      const features = this.$page.props.enabledFeatures || {};
      if (this.$can('view-smart-brief-reports') && features.smart_brief !== false && features.smart_brief_reports !== false) {
        links.push({
          route: 'smart-brief.reports.index',
          label: this.$t('Smart Brief Reports'),
          description: this.$t('Printable activity reports built from what WIMS has recorded'),
          icon: 'sparkles',
        });
      }

      // Weather & Site Risk report: only for people who may read it (the page and the exports are checked again on the server)
      if (this.$can('read-weather') && features.weather_risk !== false && features.weather_reports !== false) {
        links.push({
          route: 'weather.reports.index',
          label: this.$t('Weather & Site Risk Report'),
          description: this.$t('Observations, forecast warnings, acknowledgements and forecast sources per project'),
          icon: 'cloud',
        });
      }

      return links;
    },
  },
  components: {
    AdminLayout,
    PageHeader,
    StatTiles,
    WarehouseMap,
    VueHighcharts,
  },
  methods: {
    formatCurrency(value) {
      const amount = new Intl.NumberFormat('en-TZ', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(Number(value || 0));
      return `TSh ${amount}`;
    },
    formatAmount(value) {
      return new Intl.NumberFormat('en-TZ', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(Number(value || 0));
    },
    formatNumber(value) {
      return new Intl.NumberFormat('en-US').format(Number(value || 0));
    },
    chipClass(index) {
      return CHIP_PALETTE[index % CHIP_PALETTE.length];
    },
    statIcon(key) {
      const map = {
        items: 'collection',
        contacts: 'doc-text',
        categories: 'chip',
        warehouses: 'building',
        checkins: 'doc-text',
        checkouts: 'bag',
        transfers: 'doc-text',
        adjustments: 'chip',
        units: 'collection',
        users: 'doc-text',
        roles: 'doc',
      };
      return map[key] || 'doc';
    },
  },
};
</script>

<style scoped>
.ra-two { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0 1rem; }
@media (min-width: 1024px) { .ra-two { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.ra-list { list-style: none; margin: 0 0 0.5rem; padding: 0; }
.ra-li { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--lg-line); font-size: 0.84rem; color: var(--lg-ink); }
.ra-li:last-child { border-bottom: 0; }
.ra-rank { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 24px; height: 24px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: var(--lg-navy); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.ra-amt { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ra-chart { margin-top: 0.5rem; overflow-x: auto; border-top: 1px dashed var(--lg-line); padding-top: 0.75rem; }
.ra-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(min(190px, 100%), 1fr)); }
.ra-grid-wide { grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr)); }
.ra-tile {
  display: flex; align-items: center; gap: 12px; min-width: 0; padding: 12px 16px; border-radius: 18px;
  background: var(--lg-soft); border: 1px solid var(--lg-line);
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.ra-tile:hover { transform: translateY(-2px); border-color: #93c5fd; box-shadow: var(--lg-shadow); }
.ra-link-tile { align-items: flex-start; }
.ra-link-title { font-size: 0.85rem; font-weight: 800; line-height: 1.3; color: var(--lg-ink); }
.ra-link-desc { margin-top: 3px; font-size: 0.72rem; line-height: 1.4; color: var(--lg-mute); }
</style>
