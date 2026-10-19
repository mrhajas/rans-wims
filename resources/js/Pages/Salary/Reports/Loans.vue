<template>
  <admin-layout :title="$t('Salary Loan Report')">
    <salary-page>
      <page-header icon="cash" :title="$t('Salary Loan Report')">
        <a :href="route('salary.reports.loans.print')" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('salary.reports.loans.pdf')" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
      </page-header>

      <salary-report-tabs active="loans" />

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Employee') }}</th>
                <th class="num">{{ $t('Principal') }}</th>
                <th class="num">{{ $t('Outstanding') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="i">
                <td :data-label="$t('Reference')" class="lg-main">{{ r.reference }}</td>
                <td :data-label="$t('Employee')">{{ r.staff_name }}</td>
                <td :data-label="$t('Principal')" class="num">{{ $money(r.amount) }}</td>
                <td :data-label="$t('Outstanding')" class="num lg-main" :class="r.outstanding > 0 ? 'is-bad' : ''">{{ $money(r.outstanding) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="badgeClass(r.status)">{{ r.status }}</span></td>
              </tr>
              <tr v-if="rows.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td>
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
  props: { rows: Array },
  methods: {
    badgeClass(status) {
      const map = { Pending: 'lg-chip-amber', Approved: 'lg-chip-indigo', Active: 'lg-chip-indigo', Completed: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-red' };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

