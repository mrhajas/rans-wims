<template>
  <admin-layout :title="voucher.voucher_number">
    <salary-page>
      <page-header icon="doc-text" :title="voucher.voucher_number" :subtitle="voucher.period_label + (voucher.staff_name ? ' · ' + voucher.staff_name : '')">
        <a :href="route('salary.vouchers.print', voucher.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('salary.vouchers.pdf', voucher.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
      </page-header>

      <div class="sl-split sl-mb">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Breakdown') }}</h2></div>
          </header>
          <dl class="sl-kv">
            <div><dt>{{ $t('Gross Earnings') }}</dt><dd>{{ $money(voucher.gross_amount) }}</dd></div>
            <div><dt>{{ $t('Total Deductions') }}</dt><dd class="is-bad">({{ $money(voucher.total_deductions) }})</dd></div>
            <div class="is-total"><dt>{{ $t('Net Salary') }}</dt><dd>{{ $money(voucher.net_salary) }}</dd></div>
            <div><dt>{{ $t('Previous Outstanding') }}</dt><dd>{{ $money(voucher.opening_outstanding_balance) }}</dd></div>
            <div class="is-total is-accent"><dt>{{ $t('Current Payment') }}</dt><dd>{{ $money(voucher.current_payment) }}</dd></div>
            <div><dt>{{ $t('Remaining Balance') }}</dt><dd>{{ $money(voucher.remaining_balance) }}</dd></div>
          </dl>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Sign-off') }}</h2></div>
          </header>
          <div class="sl-sub"><div class="sl-sub-label">{{ $t('Prepared By') }}</div><div class="sl-sub-value sv-text">{{ voucher.prepared_by_name || '-' }}</div></div>
          <div class="sl-sub"><div class="sl-sub-label">{{ $t('Approved By') }}</div><div class="sl-sub-value sv-text">{{ voucher.approved_by_name || '-' }}</div></div>
          <div class="sl-sub"><div class="sl-sub-label">{{ $t('Paid By') }}</div><div class="sl-sub-value sv-text">{{ voucher.paid_by_name || '-' }}</div></div>
        </section>
      </div>

      <section v-if="voucher.children && voucher.children.length" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Individual Vouchers') }}</h2></div>
          <span class="lg-chip lg-chip-slate">{{ voucher.children.length }}</span>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr><th>{{ $t('Employee') }}</th><th>{{ $t('Voucher No.') }}</th><th class="num">{{ $t('Net Salary') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="c in voucher.children" :key="c.id" class="is-click" @click="$inertia.visit(route('salary.vouchers.show', c.id))">
                <td :data-label="$t('Employee')" class="lg-main">{{ c.staff_name }}</td>
                <td :data-label="$t('Voucher No.')">{{ c.voucher_number }}</td>
                <td :data-label="$t('Net Salary')" class="num">{{ $money(c.net_salary) }}</td>
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
  props: { voucher: Object },
};
</script>

<style scoped>
.sv-text { font-size: 0.92rem; }
</style>
