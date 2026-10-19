<template>
  <admin-layout :title="$t('New Salary Period')">
    <salary-page>
      <page-header icon="calendar" :title="$t('New Salary Period')" :subtitle="$t('Create a new payroll period to prepare and approve salaries against.')" />

      <div class="sl-split">
        <form @submit.prevent="submit" class="sl-form">
          <section class="lg-card sl-nomb">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="calendar" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Salary Period') }}</h2></div>
            </header>
            <div class="lg-fields">
              <text-input class="lg-span-all" :label="$t('Label')" v-model="form.label" :error="form.errors.label" required :placeholder="$t('e.g. October 2026')" />
              <date-picker :label="$t('Period Start')" v-model="form.period_start" :error="form.errors.period_start" required />
              <date-picker :label="$t('Period End')" v-model="form.period_end" :error="form.errors.period_end" required />
              <textarea-input class="lg-span-all" :label="$t('Notes')" v-model="form.notes" :error="form.errors.notes" />
            </div>
          </section>

          <div class="lg-actionbar">
            <div class="lg-actionbar-btns">
              <Link :href="route('salary.periods.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
              <loading-button :loading="form.processing" class="lg-btn lg-btn-primary" type="submit">
                {{ $t('Create Salary Period') }}
              </loading-button>
            </div>
          </div>
        </form>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('How Periods Work') }}</h2></div>
          </header>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Workflow') }}</div>
            <p class="sl-hint">{{ $t('Draft → Pending Approval → Approved → Partially Paid / Paid.') }}</p>
          </div>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Next Step') }}</div>
            <p class="sl-hint">{{ $t('Once created, prepare individual or bulk salaries against this period before submitting it for approval.') }}</p>
          </div>
        </section>
      </div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, TextInput, TextareaInput, DatePicker, LoadingButton, Link, SalaryPage, PageHeader },
  data() {
    return {
      form: this.$inertia.form({ label: '', period_start: '', period_end: '', notes: '' }),
    };
  },
  methods: {
    submit() {
      this.form.post(route('salary.periods.store'));
    },
  },
};
</script>

<style scoped>
.sl-nomb { margin-bottom: 1rem; }
</style>
