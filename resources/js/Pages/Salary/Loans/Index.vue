<template>
  <admin-layout :title="$t('Salary Loans')">
    <salary-page>
      <page-header icon="cash" :title="$t('Salary Loans')" :subtitle="$t('Employee salary loans and repayment tracking.')">
        <Link v-if="$can('create-salary-loans')" :href="route('salary.loans.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" /> {{ $t('New Loan') }}
        </Link>
      </page-header>

      <div class="sl-kpis">
        <salary-kpi-card icon="users" accent="indigo" :value="summary.total_active" :label="$t('Active Loans')" />
        <salary-kpi-card icon="cash" accent="rose" prefix="TSH" :value="summary.total_outstanding" :label="$t('Outstanding')" />
        <salary-kpi-card icon="clock" accent="amber" :value="summary.total_pending" :label="$t('Pending Approval')" />
        <salary-kpi-card icon="tick" accent="emerald" prefix="TSH" :value="summary.total_disbursed" :label="$t('Total Disbursed')" />
      </div>

      <section class="lg-card sl-filters">
        <div class="lg-fields sl-filter-grid">
          <text-input class="w-full" :label="$t('Search')" v-model="form.search" />
          <auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable />
        </div>
      </section>

      <div class="sl-cards">
        <Link v-for="l in loans.data" :key="l.id" :href="route('salary.loans.show', l.id)" class="sl-card-row">
          <div class="sl-card-head">
            <span class="lg-avatar">{{ initials(l.staff_name) }}</span>
            <div class="min-w-0 flex-1">
              <div class="sl-card-name">{{ l.staff_name }}</div>
              <div class="sl-card-sub">{{ l.reference }} &middot; {{ l.loan_date }}</div>
            </div>
            <span class="lg-chip" :class="badgeClass(l.status)">{{ l.status }}</span>
          </div>
          <div class="sl-card-figs">
            <span>{{ $t('Principal') }}: <strong>{{ $money(l.principal_amount) }}</strong></span>
            <span>{{ $t('Outstanding') }}: <strong class="is-bad">{{ $money(l.outstanding_principal) }}</strong></span>
          </div>
          <div class="sl-progress"><div :style="{ width: l.repayment_progress + '%' }"></div></div>
          <div class="sl-progress-note">{{ l.repayment_progress }}% {{ $t('repaid') }}</div>
        </Link>
        <div v-if="loans.data.length === 0" class="lg-card sl-cards-empty">{{ $t('There is no data to display.') }}</div>
      </div>
      <pagination v-if="loans.data.length" class="mt-6" :meta="loans" :links="{ prev: loans.prev_page_url, next: loans.next_page_url }" />
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import SalaryKpiCard from '@/Shared/Salary/SalaryKpiCard.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';

export default {
  components: { AdminLayout, AutoComplete, TextInput, Pagination, SalaryKpiCard, Link, SalaryPage, PageHeader },
  props: { filters: Object, loans: Object, statuses: Array, summary: Object },
  data() {
    return { form: { search: this.filters.search || '', status: this.filters.status || null } };
  },
  computed: {
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
  },
  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('salary.loans.index', query), { preserveState: true, preserveScroll: true, replace: true });
      }, 200),
      deep: true,
    },
  },
  methods: {
    initials(name) {
      return (name || '').split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
    },
    badgeClass(status) {
      const map = { Pending: 'lg-chip-amber', Approved: 'lg-chip-indigo', Active: 'lg-chip-indigo', Completed: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-red' };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.sl-filters { position: relative; z-index: 20; }
.sl-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); }
.sl-cards-empty { grid-column: 1 / -1; padding: 36px 16px; text-align: center; color: var(--lg-faint); margin-bottom: 0; }
</style>
