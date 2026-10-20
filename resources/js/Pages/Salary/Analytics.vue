<template>
  <admin-layout :title="$t('Salary Analytics')">
    <salary-page>
      <page-header icon="chart-pie" :title="$t('Salary Analytics')" :subtitle="$t('Deep payroll analytics across every period, employee, advance and loan.')">
        <Link :href="route('salary.dashboard.index')" class="lg-btn lg-btn-soft">
          <icons name="chart-pie" class="w-4 h-4" /> {{ $t('Dashboard') }}
        </Link>
      </page-header>

      <div class="sl-kpis">
        <salary-kpi-card icon="cash" accent="indigo" prefix="TSH" :value="summary.total_payroll_obligation" :label="$t('Total Payroll Obligation')" />
        <salary-kpi-card icon="tick" accent="emerald" prefix="TSH" :value="summary.total_paid" :label="$t('Total Paid')" />
        <salary-kpi-card icon="exclamation" accent="rose" prefix="TSH" :value="summary.total_outstanding" :label="$t('Total Outstanding')" />
        <salary-kpi-card icon="users" accent="slate" :value="summary.total_employees_paid" :label="$t('Salary Records')" />
      </div>
      <div class="sl-kpis">
        <salary-kpi-card icon="calculator" accent="sky" prefix="TSH" :value="summary.average_salary" :label="$t('Average Salary')" />
        <salary-kpi-card icon="cash" accent="amber" prefix="TSH" :value="summary.total_advances_outstanding" :label="$t('Advances Outstanding')" :hint="summary.employees_with_active_advances + ' ' + $t('employees')" />
        <salary-kpi-card icon="cash" accent="rose" prefix="TSH" :value="summary.total_loans_outstanding" :label="$t('Loans Outstanding')" :hint="summary.employees_with_active_loans + ' ' + $t('employees')" />
        <salary-kpi-card icon="exclamation" accent="rose" prefix="TSH" :value="ageingTotal" :label="$t('Total Arrears (Aged)')" />
      </div>

      <div class="sl-split sl-mb">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Salary Cost Trend') }}</h2>
              <p class="lg-card-sub">{{ $t('Payable vs. Paid, last 12 periods') }}</p>
            </div>
          </header>
          <vue-highcharts v-if="trendChart" type="chart" :options="trendChart" :redrawOnUpdate="true" :oneToOneUpdate="false" style="height: 300px" />
          <div v-else class="sl-empty">{{ $t('No salary periods yet.') }}</div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="chart-pie" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Deductions by Type') }}</h2>
              <p class="lg-card-sub">{{ $t('Where deductions come from') }}</p>
            </div>
          </header>
          <vue-highcharts v-if="deductionsChart" type="chart" :options="deductionsChart" :redrawOnUpdate="true" :oneToOneUpdate="false" style="height: 260px" />
          <div v-else class="sl-empty">{{ $t('No deductions recorded yet.') }}</div>
        </section>
      </div>

      <div class="sl-split sl-mb">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Outstanding Salary Ageing') }}</h2>
              <p class="lg-card-sub">{{ $t('How long unpaid balances have been outstanding') }}</p>
            </div>
          </header>
          <vue-highcharts v-if="ageingChart" type="chart" :options="ageingChart" :redrawOnUpdate="true" :oneToOneUpdate="false" style="height: 260px" />
          <div v-else class="sl-empty">{{ $t('No outstanding balances.') }}</div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Employees with Outstanding Salary') }}</h2></div>
          </header>
          <div v-if="employeesWithOutstanding.length" class="sa-list">
            <div v-for="(e, i) in employeesWithOutstanding" :key="i" class="sa-row">
              <div class="min-w-0">
                <div class="sa-name">{{ e.staff_name }}</div>
                <div class="sa-sub">{{ e.period_label }}</div>
              </div>
              <div class="sa-amount is-bad">{{ $money(e.amount) }}</div>
            </div>
          </div>
          <div v-else class="sl-empty">{{ $t('Nobody has an outstanding balance.') }}</div>
        </section>
      </div>

      <div class="sl-split sl-split-even">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Active Advances') }}</h2></div>
          </header>
          <div v-if="activeAdvances.length" class="sa-list">
            <div v-for="(a, i) in activeAdvances" :key="i" class="sa-row">
              <div class="min-w-0">
                <div class="sa-name">{{ a.staff_name }}</div>
                <div class="sa-sub">{{ a.reference }}</div>
              </div>
              <div class="sa-amount">{{ $money(a.outstanding) }}</div>
            </div>
          </div>
          <div v-else class="sl-empty">{{ $t('No active advances.') }}</div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Active Loans') }}</h2></div>
          </header>
          <div v-if="activeLoans.length" class="sa-list">
            <div v-for="(l, i) in activeLoans" :key="i" class="sa-row">
              <div class="min-w-0">
                <div class="sa-name">{{ l.staff_name }}</div>
                <div class="sa-sub">{{ l.reference }}</div>
              </div>
              <div class="sa-amount">{{ $money(l.outstanding) }}</div>
            </div>
          </div>
          <div v-else class="sl-empty">{{ $t('No active loans.') }}</div>
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

const SOURCE_LABELS = { component: 'Standard Deductions', advance: 'Advance Repayments', loan: 'Loan Repayments', outstanding: 'Outstanding Adjustments', manual: 'Manual' };

export default {
  components: { AdminLayout, SalaryKpiCard, VueHighcharts, Link, SalaryPage, PageHeader },
  props: { summary: Object, trend: Array, deductionsBySource: Object, ageingBuckets: Object, employeesWithOutstanding: Array, activeAdvances: Array, activeLoans: Array },
  computed: {
    ageingTotal() {
      return Object.values(this.ageingBuckets || {}).reduce((a, b) => a + Number(b), 0);
    },
    trendChart() {
      if (!this.trend || this.trend.length === 0) return null;
      return {
        chart: { type: 'areaspline', style: { fontFamily: "'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, sans-serif" }, backgroundColor: 'transparent' },
        credits: { enabled: false },
        title: { text: undefined },
        xAxis: { categories: this.trend.map(t => t.label) },
        yAxis: { title: { text: undefined }, min: 0 },
        tooltip: { shared: true, valuePrefix: 'TSH ', valueDecimals: 0 },
        colors: ['#4f46e5', '#059669'],
        plotOptions: { areaspline: { fillOpacity: 0.15, marker: { enabled: true, radius: 3 } } },
        legend: { align: 'right', verticalAlign: 'top' },
        series: [
          { name: this.$t('Payable'), data: this.trend.map(t => t.payable) },
          { name: this.$t('Paid'), data: this.trend.map(t => t.paid) },
        ],
      };
    },
    deductionsChart() {
      const entries = Object.entries(this.deductionsBySource || {});
      if (entries.length === 0) return null;
      const colors = { component: '#64748b', advance: '#0284c7', loan: '#d97706', outstanding: '#be123c', manual: '#7c3aed' };
      return {
        chart: { type: 'pie', style: { fontFamily: "'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, sans-serif" }, backgroundColor: 'transparent' },
        credits: { enabled: false },
        title: { text: undefined },
        tooltip: { pointFormat: 'TSH {point.y:,.0f} ({point.percentage:.0f}%)' },
        plotOptions: { pie: { innerSize: '60%', borderWidth: 2, borderColor: '#fff' } },
        series: [{
          name: this.$t('Deductions'),
          data: entries.map(([source, total]) => ({ name: this.$t(SOURCE_LABELS[source] || source), y: Number(total), color: colors[source] || '#94a3b8' })),
        }],
      };
    },
    ageingChart() {
      const buckets = this.ageingBuckets || {};
      if (this.ageingTotal <= 0) return null;
      return {
        chart: { type: 'bar', style: { fontFamily: "'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, sans-serif" }, backgroundColor: 'transparent' },
        credits: { enabled: false },
        title: { text: undefined },
        xAxis: { categories: Object.keys(buckets).map(k => k + ' ' + this.$t('days')) },
        yAxis: { title: { text: undefined }, min: 0 },
        legend: { enabled: false },
        tooltip: { valuePrefix: 'TSH ', valueDecimals: 0 },
        colors: ['#e11d48'],
        plotOptions: { bar: { borderRadius: 6, borderWidth: 0 } },
        series: [{ name: this.$t('Outstanding'), data: Object.values(buckets) }],
      };
    },
  },
};
</script>

<style scoped>
.sa-list { display: flex; flex-direction: column; }
.sa-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.6rem 0.25rem; border-bottom: 1px solid var(--lg-line); }
.sa-row:last-child { border-bottom: 0; }
.sa-name { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; }
.sa-sub { font-size: 0.72rem; color: var(--lg-faint); }
.sa-amount { flex: none; font-size: 0.88rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
</style>
