<template>
  <admin-layout :title="$t('New Salary Loan')">
    <salary-page>
      <page-header icon="cash" :title="$t('New Salary Loan')" :subtitle="$t('Issue an interest-free loan to be repaid via fixed salary installments.')" />

      <div class="sl-split">
        <form @submit.prevent="submit" class="sl-form" enctype="multipart/form-data">
          <section class="lg-card sl-fz">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-rose"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('New Salary Loan') }}</h2></div>
            </header>
            <div class="lg-fields">
              <auto-complete id="staff_id" class="lg-span-all" :label="$t('Employee')" v-model="form.staff_id" :suggestions="staffs" value-key="id" label-key="name" :error="form.errors.staff_id" required />
              <date-picker :label="$t('Loan Date')" v-model="form.loan_date" :error="form.errors.loan_date" required />
              <text-input type="number" step="0.01" :label="$t('Principal Amount')" v-model="form.principal_amount" :error="form.errors.principal_amount" required />
              <text-input type="number" :label="$t('Number of Installments')" v-model="form.installments_count" :error="form.errors.installments_count" required />
              <textarea-input class="lg-span-all" :label="$t('Notes')" v-model="form.notes" :error="form.errors.notes" />
              <attachment-picker class="lg-span-all" v-model="form.attachments" />
            </div>
          </section>

          <div class="lg-actionbar">
            <div class="lg-actionbar-btns">
              <Link :href="route('salary.loans.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
              <loading-button :loading="form.processing" class="lg-btn lg-btn-primary" type="submit">{{ $t('Create Loan') }}</loading-button>
            </div>
          </div>
        </form>

        <section class="lg-card sl-fz">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="calculator" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Repayment Preview') }}</h2></div>
          </header>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Monthly deduction') + ' (' + $t('interest-free') + ')' }}</div>
            <div class="sl-sub-value">{{ $money(installmentPreview) }}</div>
          </div>
          <p class="sl-hint sl-pt">{{ $t('This amount will be automatically deducted from the employee\'s salary each period until the loan principal is fully repaid.') }}</p>
        </section>
      </div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, AutoComplete, TextInput, TextareaInput, DatePicker, LoadingButton, AttachmentPicker, Link, SalaryPage, PageHeader },
  props: { staffs: Array },
  data() {
    return {
      form: this.$inertia.form({ staff_id: null, loan_date: '', principal_amount: '', installments_count: 1, notes: '', attachments: [] }),
    };
  },
  computed: {
    installmentPreview() {
      const amt = parseFloat(this.form.principal_amount) || 0;
      const count = parseInt(this.form.installments_count) || 1;
      return count > 0 ? Math.round((amt / count) * 100) / 100 : 0;
    },
  },
  methods: {
    submit() {
      this.form.post(route('salary.loans.store'));
    },
  },
};
</script>

<style scoped>
.sl-pt { margin-top: 12px; }
</style>
