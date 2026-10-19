<template>
  <admin-layout :title="$t('Salary Outstanding Report')">
    <salary-page>
      <page-header icon="exclamation" :title="$t('Salary Outstanding Report')">
        <a :href="route('salary.reports.outstanding.print')" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('salary.reports.outstanding.pdf')" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
      </page-header>

      <salary-report-tabs active="outstanding" />

      <div class="sl-kpis">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value is-bad">{{ $money(total_outstanding) }}</div>
            <div class="lg-stat-label">{{ $t('Total Outstanding') }}</div>
          </div>
        </div>
      </div>

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th>
                <th>{{ $t('Period') }}</th>
                <th class="num">{{ $t('Payable') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th class="num">{{ $t('Remaining') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="i">
                <td :data-label="$t('Employee')"><span class="lg-main">{{ r.staff_name }}</span> <span class="lg-subtext rr-inline">({{ r.staff_number }})</span></td>
                <td :data-label="$t('Period')">{{ r.period_label }}</td>
                <td :data-label="$t('Payable')" class="num">{{ $money(r.current_payable) }}</td>
                <td :data-label="$t('Paid')" class="num">{{ $money(r.total_paid) }}</td>
                <td :data-label="$t('Remaining')" class="num lg-main is-bad">{{ $money(r.remaining_balance) }}</td>
              </tr>
              <tr v-if="rows.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('No outstanding salary balances.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalaryReportTabs from '@/Shared/Salary/SalaryReportTabs.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, SalaryPage, PageHeader, SalaryReportTabs },
  props: { rows: Array, total_outstanding: Number },
};
</script>

<style scoped>
.rr-inline { display: inline; }
</style>
