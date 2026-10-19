<template>
  <admin-layout :title="$t('Salary Opening Balance')">
    <salary-page>
      <page-header icon="calendar" :title="$t('Salary Opening Balance')" :subtitle="$t('What each employee already owes or is owed when you start using WIMS: unpaid salary, loans and advances.')">
        <template v-if="can.create">
          <Link :href="route('salary.opening-balances.bulk')" class="lg-btn lg-btn-soft">
            <icons name="users" class="w-4 h-4" /> {{ $t('Enter All Staff') }}
          </Link>
          <Link :href="route('salary.opening-balances.create')" class="lg-btn lg-btn-primary">
            <icons name="plus" class="w-4 h-4" /> {{ $t('New Opening Balance') }}
          </Link>
        </template>
      </page-header>

      <div class="sl-kpis">
        <salary-kpi-card icon="users" accent="slate" :value="summary.entries" :label="$t('Employees with an Opening Balance')" />
        <salary-kpi-card icon="cash" accent="rose" prefix="TSH" :value="summary.outstanding_total" :label="$t('Unpaid Salary Brought Forward')" :hint="summary.outstanding_pending > 0 ? $money(summary.outstanding_pending) + ' ' + $t('not yet added to a salary') : null" />
        <salary-kpi-card icon="clock" accent="amber" prefix="TSH" :value="summary.loan_balance" :label="$t('Loan Balance Still Owed')" />
        <salary-kpi-card icon="clock" accent="indigo" prefix="TSH" :value="summary.advance_balance" :label="$t('Advance Balance Still Owed')" />
      </div>

      <section class="lg-card ob-filters">
        <div class="lg-fields ob-filter-grid">
          <text-input class="w-full" :label="$t('Search')" v-model="form.search" />
          <auto-complete id="state" :label="$t('Status')" v-model="form.state" class="w-full" :suggestions="stateOptions" clearable />
        </div>
      </section>

      <div class="sl-cards ob-cards">
        <Link v-for="b in balances.data" :key="b.id" :href="route('salary.opening-balances.edit', b.id)" class="sl-card-row">
          <div class="sl-card-head">
            <span class="lg-avatar">{{ initials(b.staff_name) }}</span>
            <div class="min-w-0 flex-1">
              <div class="sl-card-name">{{ b.staff_name }}</div>
              <div class="sl-card-sub">{{ b.reference }} &middot; {{ b.staff_number }}</div>
            </div>
            <span class="lg-chip" :class="b.locked ? 'lg-chip-green' : 'lg-chip-amber'">
              {{ b.locked ? $t('In use by payroll') : $t('Waiting for next salary') }}
            </span>
          </div>

          <div class="ob-range">
            <icons name="calendar" class="w-3.5 h-3.5" />
            {{ $date(b.balance_from) }} <span class="ob-range-arrow">&rarr;</span> {{ $date(b.balance_to) }}
          </div>

          <div class="ob-grid">
            <div class="sl-sub ob-cell">
              <div class="ob-cell-label">{{ $t('Unpaid Salary') }}</div>
              <div class="ob-cell-value" :class="b.outstanding_salary > 0 ? 'is-bad' : 'ob-zero'">{{ b.outstanding_salary > 0 ? $money(b.outstanding_salary) : '—' }}</div>
              <div v-if="b.outstanding_state === 'applied'" class="ob-cell-sub">{{ $t('Added to') }} {{ b.applied_period }}</div>
              <div v-else-if="b.outstanding_state === 'pending'" class="ob-cell-sub">{{ $t('Added to next salary') }}</div>
            </div>
            <div class="sl-sub ob-cell">
              <div class="ob-cell-label">{{ $t('Loan') }}</div>
              <div class="ob-cell-value" :class="b.loan_amount > 0 ? 'is-warn' : 'ob-zero'">{{ b.loan_amount > 0 ? $money(b.loan_outstanding) : '—' }}</div>
              <div v-if="b.loan_amount > 0" class="ob-cell-sub">{{ b.loan_installments }} {{ $t('instalments') }} &middot; {{ b.loan_status }}</div>
            </div>
            <div class="sl-sub ob-cell">
              <div class="ob-cell-label">{{ $t('Advance') }}</div>
              <div class="ob-cell-value" :class="b.advance_amount > 0 ? 'is-accent' : 'ob-zero'">{{ b.advance_amount > 0 ? $money(b.advance_outstanding) : '—' }}</div>
              <div v-if="b.advance_amount > 0" class="ob-cell-sub">{{ b.advance_installments }} {{ $t('instalments') }} &middot; {{ b.advance_status }}</div>
            </div>
          </div>

          <p v-if="b.remarks" class="sl-hint ob-remarks">{{ b.remarks }}</p>
        </Link>
        <div v-if="balances.data.length === 0" class="lg-card ob-empty">
          {{ $t('There is no data to display.') }}
          <div v-if="can.create" class="ob-empty-link">
            <Link :href="route('salary.opening-balances.bulk')" class="sl-link">{{ $t('Enter opening balances for all staff') }}</Link>
          </div>
        </div>
      </div>
      <pagination v-if="balances.data.length" class="mt-6" :meta="balances" :links="{ prev: balances.prev_page_url, next: balances.next_page_url }" />
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
  props: { filters: Object, balances: Object, summary: Object, can: Object },
  data() {
    return { form: { search: this.filters.search || '', state: this.filters.state || null } };
  },
  computed: {
    stateOptions() {
      return [
        { label: this.$t('Waiting for next salary'), value: 'pending' },
        { label: this.$t('In use by payroll'), value: 'in_use' },
      ];
    },
  },
  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('salary.opening-balances.index', query), { preserveState: true, preserveScroll: true, replace: true });
      }, 200),
      deep: true,
    },
  },
  methods: {
    initials(name) {
      return (name || '').split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
    },
  },
};
</script>

<style scoped>
.ob-filters { position: relative; z-index: 20; }
.ob-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); }
.ob-cards { grid-template-columns: repeat(auto-fill, minmax(min(420px, 100%), 1fr)); }
@media (max-width: 520px) { .ob-cards { grid-template-columns: minmax(0, 1fr); } }
.ob-empty { grid-column: 1 / -1; padding: 36px 16px; text-align: center; color: var(--lg-faint); margin-bottom: 0; }
.ob-empty-link { margin-top: 12px; font-size: 0.85rem; }
.ob-range { display: inline-flex; align-items: center; gap: 0.4rem; margin-bottom: 12px; font-size: 0.74rem; font-weight: 700; color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); border-radius: 999px; padding: 0.25rem 0.75rem; }
.ob-range-arrow { color: var(--lg-faint); }
.ob-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
@media (max-width: 520px) { .ob-grid { grid-template-columns: minmax(0, 1fr); } }
.ob-cell { margin-top: 0 !important; padding: 0.6rem 0.7rem; min-width: 0; }
.ob-cell-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.ob-cell-value { font-size: 0.95rem; font-weight: 800; line-height: 1.3; word-break: break-word; font-variant-numeric: tabular-nums; }
.ob-zero { color: var(--lg-faint); font-weight: 600; }
.ob-cell-sub { font-size: 0.65rem; color: var(--lg-faint); margin-top: 2px; }
.ob-remarks { margin-top: 12px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>
