<template>
  <admin-layout :title="$t('Salary Periods')">
    <salary-page>
      <page-header icon="calendar" :title="$t('Salary Periods')" :subtitle="$t('Create a new payroll period to prepare and approve salaries against.')">
        <Link v-if="can.create" :href="route('salary.periods.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" /> {{ $t('New Salary Period') }}
        </Link>
      </page-header>

      <section class="lg-card sp-filters">
        <div class="lg-fields sp-filter-grid">
          <text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Label or batch reference…')" />
          <auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable />
          <div class="sp-reset-cell">
            <button type="button" class="lg-btn lg-btn-soft sp-reset" @click="form.search = ''; form.status = null;">
              <icons name="refresh" class="w-4 h-4" /> {{ $t('Reset') }}
            </button>
          </div>
        </div>
      </section>

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Batch Reference') }}</th>
                <th>{{ $t('Label') }}</th>
                <th>{{ $t('Period') }}</th>
                <th class="num">{{ $t('Employees') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in periods.data" :key="p.id" class="is-click" @click="$inertia.visit(route('salary.periods.show', p.id))">
                <td :data-label="$t('Batch Reference')" class="lg-main">{{ p.batch_reference }}</td>
                <td :data-label="$t('Label')">{{ p.label }}</td>
                <td :data-label="$t('Period')" class="sp-dim">{{ p.period_start }} - {{ p.period_end }}</td>
                <td :data-label="$t('Employees')" class="num">{{ p.records_count }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="badgeClass(p.status)">{{ p.status }}</span></td>
              </tr>
              <tr v-if="periods.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="periods.data.length" class="mt-6" :meta="periods" :links="{ prev: periods.prev_page_url, next: periods.next_page_url }" />
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';

export default {
  components: { AdminLayout, AutoComplete, TextInput, Pagination, Link, SalaryPage, PageHeader },
  props: { filters: Object, periods: Object, statuses: Array, can: Object },
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
        this.$inertia.visit(this.route('salary.periods.index', query), { preserveState: true, preserveScroll: true, replace: true });
      }, 200),
      deep: true,
    },
  },
  methods: {
    badgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate', Preparing: 'lg-chip-slate', 'Pending Approval': 'lg-chip-amber',
        Approved: 'lg-chip-indigo', 'Partially Paid': 'lg-chip-amber', Paid: 'lg-chip-green',
        Rejected: 'lg-chip-red', Cancelled: 'lg-chip-red', Closed: 'lg-chip-slate',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.sp-filters { position: relative; z-index: 20; }
.sp-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); align-items: end; }
.sp-reset-cell { display: flex; align-items: flex-end; padding-bottom: 2px; }
.sp-reset { width: 100%; min-height: 42px; }
.sp-dim { color: var(--lg-mute); }
</style>
