<template>
  <admin-layout :title="advance.reference">
    <salary-page>
      <page-header icon="cash" :title="advance.reference" :subtitle="advance.staff_name">
        <span class="lg-chip" :class="badgeClass(advance.status)">{{ advance.status }}</span>
        <Link :href="route('salary.advances.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Salary Advances') }}</Link>
      </page-header>

      <div class="sl-kpis">
        <salary-kpi-card icon="cash" accent="indigo" prefix="TSH" :value="advance.amount" :label="$t('Amount')" />
        <salary-kpi-card icon="calculator" accent="sky" prefix="TSH" :value="advance.installment_amount" :label="$t('Installment')" />
        <salary-kpi-card icon="exclamation" accent="rose" prefix="TSH" :value="advance.outstanding_balance" :label="$t('Outstanding')" />
      </div>

      <div v-if="advance.status === 'Pending' && $can('approve-salary-advances')" class="lg-card sl-actions">
        <button type="button" class="lg-btn lg-btn-green" @click="$inertia.patch(route('salary.advances.approve', advance.id))"><icons name="tick" class="w-4 h-4" /> {{ $t('Approve') }}</button>
        <button type="button" class="lg-btn lg-btn-red" @click="$inertia.patch(route('salary.advances.reject', advance.id))"><icons name="cross" class="w-4 h-4" /> {{ $t('Reject') }}</button>
      </div>
      <div v-else-if="advance.status === 'Active' && $can('approve-salary-advances')" class="lg-card sl-actions">
        <button type="button" class="lg-btn lg-btn-dark" @click="$inertia.patch(route('salary.advances.cancel', advance.id))"><icons name="x-circle" class="w-4 h-4" /> {{ $t('Cancel Advance') }}</button>
      </div>

      <div class="sl-split">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Repayment History') }}</h2></div>
          </header>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr><th>{{ $t('Period') }}</th><th>{{ $t('Date') }}</th><th class="num">{{ $t('Amount') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in advance.repayments" :key="i">
                  <td :data-label="$t('Period')" class="lg-main">{{ r.period_label }}</td>
                  <td :data-label="$t('Date')">{{ r.date }}</td>
                  <td :data-label="$t('Amount')" class="num">{{ $money(r.amount) }}</td>
                </tr>
                <tr v-if="advance.repayments.length === 0" class="lg-empty-row"><td class="lg-empty" colspan="3">{{ $t('No repayments recorded yet.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div>
          </header>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Reason') }}</div>
            <p class="sl-hint sl-ink">{{ advance.reason || '-' }}</p>
          </div>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Approved By') }}</div>
            <div class="sl-sub-value sl-sub-text">{{ advance.approved_by_name || '-' }}</div>
          </div>
        </section>
      </div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';
import SalaryKpiCard from '@/Shared/Salary/SalaryKpiCard.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, SalaryKpiCard, SalaryPage, PageHeader },
  props: { advance: Object },
  methods: {
    badgeClass(status) {
      const map = { Pending: 'lg-chip-amber', Approved: 'lg-chip-indigo', Active: 'lg-chip-indigo', Completed: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-red' };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.sl-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 0.8rem 1rem; }
.sl-ink { color: var(--lg-ink); font-weight: 600; font-size: 0.86rem; }
.sl-sub-text { font-size: 0.92rem; }
</style>
