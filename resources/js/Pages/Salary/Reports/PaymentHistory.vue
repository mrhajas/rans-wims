<template>
  <admin-layout :title="$t('Salary Payment History')">
    <salary-page>
      <page-header icon="cash" :title="$t('Salary Payment History')">
        <a :href="route('salary.reports.payment-history.print')" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('salary.reports.payment-history.pdf')" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
      </page-header>

      <salary-report-tabs active="payment-history" />

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Employee') }}</th>
                <th>{{ $t('Period') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Method') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Paid By') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in payments.data" :key="p.id">
                <td :data-label="$t('Date')">{{ p.payment_date }}</td>
                <td :data-label="$t('Employee')" class="lg-main">{{ p.staff_name }}</td>
                <td :data-label="$t('Period')">{{ p.period_label }}</td>
                <td :data-label="$t('Amount')" class="num lg-main">{{ $money(p.amount) }}</td>
                <td :data-label="$t('Method')">{{ p.payment_method || '-' }}</td>
                <td :data-label="$t('Reference')">{{ p.payment_reference || '-' }}</td>
                <td :data-label="$t('Paid By')">{{ p.paid_by_name }}</td>
              </tr>
              <tr v-if="payments.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="payments.data.length" class="mt-6" :meta="payments" :links="{ prev: payments.prev_page_url, next: payments.next_page_url }" />
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import SalaryReportTabs from '@/Shared/Salary/SalaryReportTabs.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Pagination, SalaryPage, PageHeader, SalaryReportTabs },
  props: { filters: Object, payments: Object },
};
</script>

