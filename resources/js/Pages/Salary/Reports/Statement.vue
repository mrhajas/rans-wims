<template>
  <admin-layout :title="$t('Employee Salary Statement')">
    <salary-page>
      <page-header icon="user" :title="staff.name" :subtitle="staff.staff_number + ' · ' + staff.position + ' · ' + $t('Basic Salary') + ': ' + $money(staff.basic_salary)">
        <a :href="route('salary.reports.statement.print', staff.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('salary.reports.statement.pdf', staff.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
      </page-header>

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Period') }}</th>
                <th class="num">{{ $t('Net Salary') }}</th>
                <th class="num">{{ $t('Payable') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th class="num">{{ $t('Remaining') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(h, i) in history" :key="i">
                <td :data-label="$t('Period')" class="lg-main">{{ h.period_label }}</td>
                <td :data-label="$t('Net Salary')" class="num">{{ $money(h.net_salary) }}</td>
                <td :data-label="$t('Payable')" class="num">{{ $money(h.current_payable) }}</td>
                <td :data-label="$t('Paid')" class="num is-good">{{ $money(h.total_paid) }}</td>
                <td :data-label="$t('Remaining')" class="num" :class="h.remaining_balance > 0 ? 'is-bad lg-main' : ''">{{ $money(h.remaining_balance) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip lg-chip-slate">{{ h.status }}</span></td>
              </tr>
              <tr v-if="history.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('No salary history yet.') }}</td>
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
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, SalaryPage, PageHeader },
  props: { staff: Object, history: Array },
};
</script>

