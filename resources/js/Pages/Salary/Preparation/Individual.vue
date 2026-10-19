<template>
  <admin-layout :title="$t('Prepare Individual Salary')">
    <salary-page>
      <page-header icon="plus" :title="$t('Prepare Individual Salary')" :subtitle="$t('Calculate and prepare a single employee\'s salary for a period.')" />

      <div class="sl-split">
        <form @submit.prevent="submit" class="sl-form">
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="user" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Prepare Individual Salary') }}</h2></div>
            </header>
            <div class="lg-fields pi-fields">
              <auto-complete
                id="salary_period_id" :label="$t('Salary Period')" v-model="form.salary_period_id"
                :suggestions="periods" value-key="id" label-key="label" :error="form.errors.salary_period_id" required
              />
              <auto-complete
                id="staff_id" :label="$t('Employee')" v-model="form.staff_id"
                :suggestions="staffs" value-key="id" label-key="name" :error="form.errors.staff_id" required
              />
            </div>
          </section>

          <div class="lg-actionbar">
            <div class="lg-actionbar-btns">
              <Link :href="route('salary.periods.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
              <loading-button :loading="form.processing" class="lg-btn lg-btn-primary" type="submit">
                {{ $t('Calculate & Prepare') }}
              </loading-button>
            </div>
          </div>
        </form>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Employee Preview') }}</h2></div>
          </header>
          <div v-if="selectedStaff" class="sl-sub">
            <div class="sl-sub-label">{{ $t('Basic Salary') }}</div>
            <div class="sl-sub-value">{{ $money(selectedStaff.basic_salary) }}</div>
          </div>
          <p v-else class="sl-hint">{{ $t('Select an employee to preview their basic salary.') }}</p>
          <p class="sl-hint pi-note">{{ $t('Earnings, statutory deductions, and any outstanding advances or loans are calculated automatically on submit.') }}</p>
        </section>
      </div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, AutoComplete, LoadingButton, Link, SalaryPage, PageHeader },
  props: { periods: Array, staffs: Array },
  data() {
    return {
      form: this.$inertia.form({
        salary_period_id: Number(new URLSearchParams(window.location.search).get('salary_period_id')) || null,
        staff_id: null,
      }),
    };
  },
  computed: {
    selectedStaff() {
      return this.staffs.find(s => s.id === this.form.staff_id) || null;
    },
  },
  methods: {
    submit() {
      this.form.post(route('salary.preparation.individual.store'));
    },
  },
};
</script>

<style scoped>
.pi-fields { position: relative; z-index: 10; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); }
.pi-note { margin-top: 12px; }
</style>
