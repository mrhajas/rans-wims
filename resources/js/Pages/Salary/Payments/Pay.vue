<template>
  <admin-layout :title="$t('Record Salary Payment')">
    <salary-page>
      <page-header icon="cash" :title="$t('Record Salary Payment')" :subtitle="record.staff_name + ' · ' + record.period_label" />

      <div class="sl-split">
        <form @submit.prevent="submit" class="sl-form">
          <section class="lg-card sl-nomb">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Record Salary Payment') }}</h2></div>
            </header>
            <div class="lg-fields">
              <div>
                <text-input type="number" step="0.01" :max="record.remaining_balance" :label="$t('Payment Amount')" v-model="form.amount" :error="form.errors.amount" required />
                <button type="button" class="sl-link-btn mt-1" @click="form.amount = record.remaining_balance">{{ $t('Pay Full Remaining Balance') }}</button>
              </div>
              <date-picker :label="$t('Payment Date')" v-model="form.payment_date" :error="form.errors.payment_date" required />
              <text-input :label="$t('Payment Method')" v-model="form.payment_method" :error="form.errors.payment_method" placeholder="e.g. Bank Transfer" />
              <text-input :label="$t('Payment Reference')" v-model="form.payment_reference" :error="form.errors.payment_reference" />
              <textarea-input class="lg-span-all" :label="$t('Notes')" v-model="form.notes" :error="form.errors.notes" />
            </div>
          </section>

          <div class="lg-actionbar">
            <div class="lg-actionbar-btns">
              <Link :href="route('salary.periods.show', record.period_id)" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
              <loading-button :loading="form.processing" class="lg-btn lg-btn-green" type="submit">
                {{ $t('Record Payment') }}
              </loading-button>
            </div>
          </div>
        </form>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Balance Summary') }}</h2></div>
          </header>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Payable') }}</div>
            <div class="sl-sub-value">{{ $money(record.current_payable) }}</div>
          </div>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Already Paid') }}</div>
            <div class="sl-sub-value is-good">{{ $money(record.total_paid) }}</div>
          </div>
          <div class="sl-sub">
            <div class="sl-sub-label">{{ $t('Remaining') }}</div>
            <div class="sl-sub-value is-bad">{{ $money(record.remaining_balance) }}</div>
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
  props: { record: Object },
  data() {
    return {
      form: this.$inertia.form({
        amount: this.record.remaining_balance,
        payment_date: new Date().toISOString().slice(0, 10),
        payment_method: '',
        payment_reference: '',
        notes: '',
      }),
    };
  },
  methods: {
    submit() {
      this.form.post(route('salary.payments.pay.store', this.record.id));
    },
  },
};
</script>

<style scoped>
.sl-nomb { margin-bottom: 1rem; }
</style>
