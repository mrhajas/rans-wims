<template>
  <admin-layout :title="$t('Enter Opening Balances for All Staff')">
    <salary-page>
      <page-header icon="users" :title="$t('Enter Opening Balances for All Staff')" :subtitle="$t('Fill in only the staff who carry a balance. Rows left empty are ignored.')">
        <Link :href="route('salary.opening-balances.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Back to list') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="calendar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Balance From') }} / {{ $t('Balance To') }}</h2>
              <p class="lg-card-sub">{{ $t('The span of time these balances cover. It applies to every row below.') }}</p>
            </div>
          </header>
          <div class="lg-fields ob-dates">
            <date-picker :label="$t('Balance From')" v-model="form.balance_from" :error="form.errors.balance_from" required />
            <date-picker :label="$t('Balance To')" v-model="form.balance_to" :error="form.errors.balance_to" required />
          </div>
        </section>

        <div v-if="errorList.length" class="lg-note lg-note-red sl-banner sl-banner-list">
          <icons name="exclamation" class="w-4 h-4" />
          <span><span v-for="(e, i) in errorList" :key="i" class="ob-err-line">{{ e }}</span></span>
        </div>

        <section class="lg-card sl-flush">
          <div class="lg-table-wrap">
            <table class="lg-table ob-table">
              <thead>
                <tr>
                  <th>{{ $t('Employee') }}</th>
                  <th>{{ $t('Unpaid Salary') }}</th>
                  <th>{{ $t('Loan Balance') }}</th>
                  <th>{{ $t('Loan Instalments') }}</th>
                  <th>{{ $t('Advance Balance') }}</th>
                  <th>{{ $t('Advance Instalments') }}</th>
                  <th>{{ $t('Remarks') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in form.rows" :key="row.staff_id" :class="{ 'ob-row-filled': filled(row) }">
                  <td>
                    <div class="lg-main">{{ row.name }}</div>
                    <div class="lg-subtext">{{ row.staff_number }}<span v-if="row.position"> &middot; {{ row.position }}</span></div>
                  </td>
                  <td><input v-model="row.outstanding_salary" type="number" step="0.01" min="0" class="ob-input" placeholder="0.00" /></td>
                  <td><input v-model="row.loan_amount" type="number" step="0.01" min="0" class="ob-input" placeholder="0.00" /></td>
                  <td><input v-model="row.loan_installments" type="number" min="1" class="ob-input ob-input-sm" :disabled="!num(row.loan_amount)" /></td>
                  <td><input v-model="row.advance_amount" type="number" step="0.01" min="0" class="ob-input" placeholder="0.00" /></td>
                  <td><input v-model="row.advance_installments" type="number" min="1" class="ob-input ob-input-sm" :disabled="!num(row.advance_amount)" /></td>
                  <td><input v-model="row.remarks" type="text" class="ob-input ob-input-wide" /></td>
                </tr>
                <tr v-if="!form.rows.length" class="lg-empty-row">
                  <td colspan="7" class="lg-empty">{{ $t('Every active employee already has an opening balance.') }}</td>
                </tr>
              </tbody>
              <tfoot v-if="form.rows.length">
                <tr>
                  <td>{{ filledCount }} {{ $t('employee(s) filled') }}</td>
                  <td>{{ $money(totals.outstanding) }}</td>
                  <td>{{ $money(totals.loan) }}</td>
                  <td></td>
                  <td>{{ $money(totals.advance) }}</td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ filledCount }} {{ $t('employee(s) filled') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('salary.opening-balances.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <loading-button :loading="form.processing" :disabled="!filledCount" class="lg-btn lg-btn-primary" type="submit">{{ $t('Record Opening Balances') }}</loading-button>
          </div>
        </div>
      </form>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, DatePicker, LoadingButton, Link, SalaryPage, PageHeader },
  props: { staffs: Array },
  data() {
    return {
      form: this.$inertia.form({
        balance_from: '',
        balance_to: '',
        rows: this.staffs.map(s => ({
          staff_id: s.id, name: s.name, staff_number: s.staff_number, position: s.position,
          outstanding_salary: '', loan_amount: '', loan_installments: '', advance_amount: '', advance_installments: '', remarks: '',
        })),
      }),
    };
  },
  computed: {
    filledCount() {
      return this.form.rows.filter(r => this.filled(r)).length;
    },
    totals() {
      const sum = key => this.form.rows.reduce((t, r) => t + this.num(r[key]), 0);
      return { outstanding: sum('outstanding_salary'), loan: sum('loan_amount'), advance: sum('advance_amount') };
    },
    errorList() {
      return Object.values(this.form.errors || {}).filter(Boolean);
    },
  },
  methods: {
    num(v) {
      return parseFloat(v) || 0;
    },
    filled(r) {
      return this.num(r.outstanding_salary) > 0 || this.num(r.loan_amount) > 0 || this.num(r.advance_amount) > 0;
    },
    submit() {
      this.form
        .transform(data => ({
          ...data,
          rows: data.rows.filter(r => this.filled(r)).map(({ name, staff_number, position, ...rest }) => rest),
        }))
        .post(route('salary.opening-balances.bulk.store'));
    },
  },
};
</script>

<style scoped>
.ob-dates { position: relative; z-index: 10; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); max-width: 640px; }
.ob-err-line { display: block; }
.ob-table { min-width: 980px; }
.ob-row-filled td { background: rgba(59, 130, 246, 0.06); }
.ob-input { width: 8.5rem; padding: 0.4rem 0.6rem; border: 1px solid var(--lg-line); border-radius: 10px; font-size: 0.85rem; background: var(--lg-card); color: var(--lg-ink); font-variant-numeric: tabular-nums; transition: border-color 0.15s, box-shadow 0.15s; }
.ob-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.ob-input:disabled { background: var(--lg-soft); opacity: 0.55; }
.ob-input-sm { width: 5rem; }
.ob-input-wide { width: 14rem; }
</style>
