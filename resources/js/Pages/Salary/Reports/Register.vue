<template>
  <admin-layout :title="$t('Salary Register')">
    <salary-page>
      <page-header icon="table" :title="$t('Salary Register')" :subtitle="$t('Select a salary period to view its register.')">
        <template v-if="payload">
          <a :href="route('salary.reports.register.print') + '?salary_period_id=' + selectedPeriodId" target="_blank" class="lg-btn lg-btn-soft">
            <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
          </a>
          <a :href="route('salary.reports.register.pdf') + '?salary_period_id=' + selectedPeriodId" class="lg-btn lg-btn-dark">
            <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
          </a>
        </template>
      </page-header>

      <salary-report-tabs active="register" />

      <section class="lg-card rr-filters">
        <div class="lg-fields rr-grid">
          <auto-complete id="salary_period_id" :label="$t('Salary Period')" v-model="selectedPeriodId" class="w-full" :suggestions="periods" value-key="id" label-key="label" />
        </div>
      </section>

      <section v-if="payload" class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Staff No.') }}</th>
                <th>{{ $t('Employee') }}</th>
                <th class="num">{{ $t('Earnings') }}</th>
                <th class="num">{{ $t('Deductions') }}</th>
                <th class="num">{{ $t('Net') }}</th>
                <th class="num">{{ $t('Payable') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th class="num">{{ $t('Remaining') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in payload.rows" :key="i">
                <td :data-label="$t('Staff No.')"><span class="lg-mono">{{ r.staff_number }}</span></td>
                <td :data-label="$t('Employee')" class="lg-main">{{ r.staff_name }}</td>
                <td :data-label="$t('Earnings')" class="num">{{ $money(r.total_earnings) }}</td>
                <td :data-label="$t('Deductions')" class="num">{{ $money(r.total_deductions) }}</td>
                <td :data-label="$t('Net')" class="num lg-main">{{ $money(r.net_salary) }}</td>
                <td :data-label="$t('Payable')" class="num">{{ $money(r.current_payable) }}</td>
                <td :data-label="$t('Paid')" class="num is-good">{{ $money(r.total_paid) }}</td>
                <td :data-label="$t('Remaining')" class="num" :class="r.remaining_balance > 0 ? 'is-bad lg-main' : ''">{{ $money(r.remaining_balance) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <div v-else class="lg-card rr-empty">{{ $t('Select a salary period to view its register.') }}</div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SalaryReportTabs from '@/Shared/Salary/SalaryReportTabs.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, AutoComplete, SalaryPage, PageHeader, SalaryReportTabs },
  props: { periods: Array, payload: Object },
  data() {
    return { selectedPeriodId: new URLSearchParams(window.location.search).get('salary_period_id') ? Number(new URLSearchParams(window.location.search).get('salary_period_id')) : null };
  },
  watch: {
    selectedPeriodId(value) {
      if (value) {
        this.$inertia.visit(route('salary.reports.register', { salary_period_id: value }), { preserveState: true, preserveScroll: true });
      }
    },
  },
};
</script>

<style scoped>
.rr-filters { position: relative; z-index: 20; }
.rr-grid { grid-template-columns: minmax(0, 420px); }
.rr-empty { padding: 36px 16px; text-align: center; color: var(--lg-faint); }
</style>
