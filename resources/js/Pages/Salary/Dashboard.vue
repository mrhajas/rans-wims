<template>
  <admin-layout :title="$t('Salary Dashboard')">
    <salary-page class="sl-dash">
      <page-header icon="cash" :title="$t('Salary Dashboard')" :subtitle="$t('Salary payable, paid, outstanding, advances, and loans at a glance.')">
        <Link v-if="$can('create-salary-periods')" :href="route('salary.preparation.individual')" class="lg-btn lg-btn-green">
          <icons name="plus" class="w-4 h-4" /> {{ $t('Prepare Salary') }}
        </Link>
        <Link :href="route('salary.periods.index')" class="lg-btn lg-btn-primary">
          <icons name="list" class="w-4 h-4" /> {{ $t('Salary Periods') }}
        </Link>
      </page-header>

      <!-- KPI row -->
      <div class="sl-kpis">
        <salary-kpi-card icon="cash" accent="indigo" prefix="TSH" :value="kpis.total_payable" :label="$t('Total Payable')" />
        <salary-kpi-card icon="tick" accent="emerald" prefix="TSH" :value="kpis.total_paid" :label="$t('Total Paid')" />
        <salary-kpi-card icon="exclamation" accent="rose" prefix="TSH" :value="kpis.total_outstanding" :label="$t('Total Outstanding')" />
        <salary-kpi-card icon="clock" accent="amber" :value="kpis.pending_approval" :label="$t('Pending Approval')" />
      </div>
      <div class="sl-kpis">
        <salary-kpi-card icon="list" accent="slate" :value="kpis.approved_awaiting_payment" :label="$t('Approved - Awaiting Payment')" />
        <salary-kpi-card icon="tick" accent="emerald" :value="kpis.paid_periods" :label="$t('Paid Periods')" />
        <salary-kpi-card icon="cash" accent="sky" prefix="TSH" :value="kpis.total_advances_outstanding" :label="$t('Advances Outstanding')" />
        <salary-kpi-card icon="cash" accent="amber" prefix="TSH" :value="kpis.total_loans_outstanding" :label="$t('Loans Outstanding')" />
      </div>

      <!-- Main content: chart area (2/3) + side panel (1/3) -->
      <div class="sl-split">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Payroll Trend') }}</h2>
              <p class="lg-card-sub">{{ $t('Payable vs. Paid, last 6 periods') }}</p>
            </div>
          </header>
          <vue-highcharts v-if="trendChart" type="chart" :options="trendChart" :redrawOnUpdate="true" :oneToOneUpdate="false" style="height: 300px" />
          <div v-else class="sl-empty">{{ $t('No salary periods yet.') }}</div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="chart-pie" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Period Status') }}</h2>
              <p class="lg-card-sub">{{ $t('All salary periods') }}</p>
            </div>
          </header>
          <vue-highcharts v-if="statusChart" type="chart" :options="statusChart" :redrawOnUpdate="true" :oneToOneUpdate="false" style="height: 220px" />
          <div v-else class="sl-empty">{{ $t('No data yet.') }}</div>
          <div class="sd-legend">
            <div v-for="(count, status) in byStatus" :key="status" class="sd-legend-row">
              <span class="sd-legend-dot" :style="{ background: statusColor(status) }"></span>
              <span class="sd-legend-label">{{ status }}</span>
              <span class="sd-legend-value">{{ count }}</span>
            </div>
          </div>
        </section>
      </div>

      <!-- Activity feed + quick links -->
      <div class="sl-split sl-mt">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Recent Activity') }}</h2></div>
          </header>
          <div v-if="activity.length" class="sd-activity">
            <div v-for="(a, i) in activity" :key="i" class="sd-activity-row">
              <span class="sd-activity-dot"></span>
              <div class="min-w-0 flex-1">
                <div class="sd-activity-desc">{{ a.description }}</div>
                <div class="sd-activity-meta">{{ a.causer_name }} &middot; {{ a.created_at }}</div>
              </div>
            </div>
          </div>
          <div v-else class="sl-empty">{{ $t('No recent activity.') }}</div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="link" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Quick Links') }}</h2></div>
          </header>
          <div class="sd-links">
            <Link v-if="$can('read-salary-advances')" :href="route('salary.advances.index')" class="sd-quicklink">
              <icons name="cash" class="w-4 h-4" /> {{ $t('Salary Advances') }}
            </Link>
            <Link v-if="$can('read-salary-loans')" :href="route('salary.loans.index')" class="sd-quicklink">
              <icons name="cash" class="w-4 h-4" /> {{ $t('Salary Loans') }}
            </Link>
            <Link v-if="$can('read-salary-opening-balances')" :href="route('salary.opening-balances.index')" class="sd-quicklink">
              <icons name="cash" class="w-4 h-4" /> {{ $t('Salary Opening Balance') }}
            </Link>
            <Link v-if="$can('read-salary-vouchers')" :href="route('salary.vouchers.index')" class="sd-quicklink">
              <icons name="doc-text" class="w-4 h-4" /> {{ $t('Salary Vouchers') }}
            </Link>
            <Link v-if="$can('read-salary-analytics')" :href="route('salary.analytics')" class="sd-quicklink">
              <icons name="chart-bar" class="w-4 h-4" /> {{ $t('Deep Analytics') }}
            </Link>
            <Link v-if="$can('read-salary-reports')" :href="route('salary.reports.register')" class="sd-quicklink">
              <icons name="doc" class="w-4 h-4" /> {{ $t('Salary Reports') }}
            </Link>
            <Link v-if="$can('manage-salary-settings')" :href="route('salary.settings.index')" class="sd-quicklink">
              <icons name="cog" class="w-4 h-4" /> {{ $t('Salary Settings') }}
            </Link>
          </div>
        </section>
      </div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalaryKpiCard from '@/Shared/Salary/SalaryKpiCard.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import VueHighcharts from 'vue3-highcharts';
import { Link } from '@inertiajs/vue3';

const STATUS_COLORS = {
  Draft: '#94a3b8', Preparing: '#94a3b8', 'Pending Approval': '#d97706', Approved: '#4f46e5',
  'Partially Paid': '#d97706', Paid: '#059669', Rejected: '#e11d48', Cancelled: '#e11d48', Closed: '#64748b',
};

export default {
  components: { AdminLayout, SalaryKpiCard, VueHighcharts, Link, SalaryPage, PageHeader },
  props: { kpis: Object, byStatus: Object, trend: Array, activity: Array },
  computed: {
    trendChart() {
      if (!this.trend || this.trend.length === 0) return null;
      return {
        chart: { type: 'column', style: { fontFamily: 'Nunito' }, backgroundColor: 'transparent' },
        credits: { enabled: false },
        title: { text: undefined },
        xAxis: { categories: this.trend.map(t => t.label), crosshair: true },
        yAxis: { title: { text: undefined }, min: 0 },
        legend: { align: 'right', verticalAlign: 'top' },
        tooltip: { shared: true, valuePrefix: 'TSH ', valueDecimals: 0 },
        colors: ['#4f46e5', '#059669'],
        plotOptions: { column: { borderRadius: 6, borderWidth: 0 } },
        series: [
          { name: this.$t('Payable'), data: this.trend.map(t => t.payable) },
          { name: this.$t('Paid'), data: this.trend.map(t => t.paid) },
        ],
      };
    },
    statusChart() {
      const entries = Object.entries(this.byStatus || {});
      if (entries.length === 0) return null;
      return {
        chart: { type: 'pie', style: { fontFamily: 'Nunito' }, backgroundColor: 'transparent' },
        credits: { enabled: false },
        title: { text: undefined },
        tooltip: { pointFormat: '{point.y} ({point.percentage:.0f}%)' },
        plotOptions: {
          pie: { innerSize: '65%', borderWidth: 2, borderColor: '#fff', dataLabels: { enabled: false } },
        },
        series: [{
          name: this.$t('Periods'),
          data: entries.map(([status, count]) => ({ name: status, y: count, color: this.statusColor(status) })),
        }],
      };
    },
  },
  methods: {
    statusColor(status) {
      return STATUS_COLORS[status] || '#94a3b8';
    },
  },
};
</script>

<style scoped>
.sl-mt { margin-top: 1rem; }
.sd-legend { display: flex; flex-direction: column; gap: 4px; margin-top: 10px; }
.sd-legend-row { display: flex; align-items: center; gap: 10px; padding: 5px 10px; border-radius: 10px; font-size: 0.8rem; }
.sd-legend-row:hover { background: var(--lg-soft); }
.sd-legend-dot { width: 10px; height: 10px; border-radius: 9999px; flex: none; }
.sd-legend-label { flex: 1; color: var(--lg-mute); font-weight: 600; }
.sd-legend-value { font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.sd-activity { display: flex; flex-direction: column; }
.sd-activity-row { display: flex; gap: 12px; padding: 0.65rem 0; border-bottom: 1px solid var(--lg-line); }
.sd-activity-row:last-child { border-bottom: 0; padding-bottom: 0; }
.sd-activity-dot { width: 10px; height: 10px; border-radius: 9999px; margin-top: 5px; flex: none; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 0 0 3px var(--lg-card); }
.sd-activity-desc { font-size: 0.84rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.sd-activity-meta { margin-top: 2px; font-size: 0.7rem; color: var(--lg-faint); }
.sd-links { display: flex; flex-direction: column; gap: 6px; }
.sd-quicklink { display: flex; align-items: center; gap: 10px; padding: 0.65rem 0.9rem; border-radius: 14px; font-size: 0.84rem; font-weight: 700; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: transform 0.15s ease, border-color 0.15s ease, color 0.15s ease; }
.sd-quicklink:hover { transform: translateX(3px); border-color: #93c5fd; color: var(--lg-navy); }
html.dark .sd-quicklink:hover { color: #93c5fd; }
</style>
