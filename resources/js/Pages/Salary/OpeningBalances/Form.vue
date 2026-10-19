<template>
  <admin-layout :title="isEdit ? $t('Salary Opening Balance') : $t('New Opening Balance')">
    <salary-page>
      <page-header
        icon="calendar"
        :title="isEdit ? balance.staff_name : $t('New Opening Balance')"
        :subtitle="isEdit ? balance.reference + ' · ' + balance.staff_number : $t('Record what this employee already owes or is owed before WIMS.')"
      >
        <span v-if="isEdit" class="lg-chip" :class="balance.locked ? 'lg-chip-green' : 'lg-chip-amber'">
          {{ balance.locked ? $t('In use by payroll') : $t('Waiting for next salary') }}
        </span>
        <Link :href="route('salary.opening-balances.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Back to list') }}</Link>
      </page-header>

      <div v-if="isEdit && balance.locked" class="lg-note lg-note-amber sl-banner">
        <icons name="info" class="w-4 h-4 flex-none" />
        <span>{{ $t('This opening balance has already been used by payroll, so it can no longer be edited or deleted. Correct it with a salary adjustment on the employee\'s salary record instead.') }}</span>
      </div>

      <div class="sl-split">
        <form @submit.prevent="submit" class="sl-form">
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-slate"><icons name="users" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Employee & Period') }}</h2></div>
            </header>
            <div class="lg-fields">
              <auto-complete v-if="!isEdit" class="lg-span-all" id="staff_id" :label="$t('Employee')" v-model="form.staff_id" :suggestions="staffs" value-key="id" label-key="name" :error="form.errors.staff_id" required />
              <date-picker :label="$t('Balance From')" v-model="form.balance_from" :error="form.errors.balance_from" :disabled="readOnly" required />
              <date-picker :label="$t('Balance To')" v-model="form.balance_to" :error="form.errors.balance_to" :disabled="readOnly" required />
            </div>
            <p class="sl-hint ob-help">{{ $t('The span of time these balances cover, e.g. unpaid salary from 1 Jan 2026 to 30 Sep 2026.') }}</p>
          </section>

          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-rose"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Unpaid Salary') }}</h2></div>
            </header>
            <div class="lg-fields">
              <text-input type="number" step="0.01" min="0" :label="$t('Outstanding Salary Amount')" v-model="form.outstanding_salary" :error="form.errors.outstanding_salary" :disabled="readOnly" />
            </div>
            <p class="sl-hint ob-help">{{ $t('Added to this employee\'s next prepared salary as a brought-forward balance, and carried forward until paid.') }}</p>
          </section>

          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Loan Still Being Repaid') }}</h2></div>
            </header>
            <div class="lg-fields">
              <text-input type="number" step="0.01" min="0" :label="$t('Remaining Loan Balance')" v-model="form.loan_amount" :error="form.errors.loan_amount" :disabled="readOnly" />
              <text-input type="number" min="1" :label="$t('Instalments Remaining')" v-model="form.loan_installments" :error="form.errors.loan_installments" :disabled="readOnly" />
            </div>
            <p class="sl-hint ob-help">{{ $t('Enter only what is still owed. An Active interest-free loan is created and deducted from each salary.') }}</p>
          </section>

          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Advance Still Being Recovered') }}</h2></div>
            </header>
            <div class="lg-fields">
              <text-input type="number" step="0.01" min="0" :label="$t('Remaining Advance Balance')" v-model="form.advance_amount" :error="form.errors.advance_amount" :disabled="readOnly" />
              <text-input type="number" min="1" :label="$t('Instalments Remaining')" v-model="form.advance_installments" :error="form.errors.advance_installments" :disabled="readOnly" />
            </div>
          </section>

          <section class="lg-card">
            <div class="lg-fields">
              <textarea-input class="lg-span-all" :label="$t('Remarks')" v-model="form.remarks" :error="form.errors.remarks" :disabled="readOnly" />
            </div>
          </section>

          <div class="lg-actionbar">
            <div class="ob-bar-left">
              <button v-if="isEdit && !balance.locked && can.delete" type="button" class="lg-btn lg-btn-red" @click="destroy">
                <icons name="trash" class="w-4 h-4" /> {{ $t('Delete') }}
              </button>
              <button v-if="isEdit && canApply" type="button" class="lg-btn lg-btn-green" @click="apply">
                <icons name="tick" class="w-4 h-4" /> {{ $t('Add Unpaid Salary to Current Salary') }}
              </button>
            </div>
            <div class="lg-actionbar-btns">
              <Link :href="route('salary.opening-balances.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
              <loading-button v-if="!readOnly" :loading="form.processing" class="lg-btn lg-btn-primary" type="submit">
                {{ isEdit ? $t('Save Changes') : $t('Record Opening Balance') }}
              </loading-button>
            </div>
          </div>
        </form>

        <div class="sl-stack">
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="calculator" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Summary') }}</h2></div>
            </header>
            <div class="sl-sub">
              <div class="sl-sub-label">{{ $t('Unpaid salary brought forward') }}</div>
              <div class="sl-sub-value is-bad">{{ $money(num(form.outstanding_salary)) }}</div>
            </div>
            <div class="sl-sub">
              <div class="sl-sub-label">{{ $t('Monthly loan deduction') }}</div>
              <div class="sl-sub-value">{{ $money(loanMonthly) }}</div>
            </div>
            <div class="sl-sub">
              <div class="sl-sub-label">{{ $t('Monthly advance deduction') }}</div>
              <div class="sl-sub-value">{{ $money(advanceMonthly) }}</div>
            </div>
          </section>

          <section v-if="isEdit" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Status') }}</h2></div>
            </header>
            <div class="ob-status">
              <div v-if="balance.outstanding_salary > 0">
                <span class="lg-chip" :class="balance.outstanding_state === 'applied' ? 'lg-chip-green' : 'lg-chip-amber'">
                  {{ balance.outstanding_state === 'applied' ? $t('Unpaid salary added to') + ' ' + balance.applied_period : $t('Unpaid salary waits for next salary') }}
                </span>
              </div>
              <div v-if="balance.loan_id">
                <Link :href="route('salary.loans.show', balance.loan_id)" class="sl-link">{{ balance.loan_reference }}</Link>
                <span class="ob-status-text"> &middot; {{ balance.loan_status }} &middot; {{ $t('owed') }} {{ $money(balance.loan_outstanding) }}</span>
              </div>
              <div v-if="balance.advance_id">
                <Link :href="route('salary.advances.show', balance.advance_id)" class="sl-link">{{ balance.advance_reference }}</Link>
                <span class="ob-status-text"> &middot; {{ balance.advance_status }} &middot; {{ $t('owed') }} {{ $money(balance.advance_outstanding) }}</span>
              </div>
            </div>
          </section>
        </div>
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
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, AutoComplete, TextInput, TextareaInput, DatePicker, LoadingButton, Link, SalaryPage, PageHeader },
  props: { balance: Object, staffs: Array, can: { type: Object, default: () => ({ update: true, delete: false }) } },
  data() {
    const b = this.balance;
    return {
      form: this.$inertia.form({
        staff_id: b ? b.staff_id : null,
        balance_from: b ? b.balance_from : '',
        balance_to: b ? b.balance_to : '',
        outstanding_salary: b && b.outstanding_salary ? b.outstanding_salary : '',
        loan_amount: b && b.loan_amount ? b.loan_amount : '',
        loan_installments: b && b.loan_installments ? b.loan_installments : '',
        advance_amount: b && b.advance_amount ? b.advance_amount : '',
        advance_installments: b && b.advance_installments ? b.advance_installments : '',
        remarks: b ? b.remarks || '' : '',
      }),
    };
  },
  computed: {
    isEdit() {
      return !!this.balance;
    },
    readOnly() {
      return this.isEdit && (this.balance.locked || !this.can.update);
    },
    canApply() {
      return this.can.update && this.balance.outstanding_state === 'pending';
    },
    loanMonthly() {
      const n = parseInt(this.form.loan_installments) || 0;
      return n > 0 ? Math.round((this.num(this.form.loan_amount) / n) * 100) / 100 : 0;
    },
    advanceMonthly() {
      const n = parseInt(this.form.advance_installments) || 0;
      return n > 0 ? Math.round((this.num(this.form.advance_amount) / n) * 100) / 100 : 0;
    },
  },
  methods: {
    num(v) {
      return parseFloat(v) || 0;
    },
    submit() {
      if (this.isEdit) {
        this.form.patch(route('salary.opening-balances.update', this.balance.id), { preserveScroll: true });
      } else {
        this.form.post(route('salary.opening-balances.store'));
      }
    },
    apply() {
      this.$inertia.post(route('salary.opening-balances.apply', this.balance.id), {}, { preserveScroll: true });
    },
    async destroy() {
      if (await this.$confirm(this.$t('Delete this opening balance? Its loan and advance will be cancelled.'))) {
        this.$inertia.delete(route('salary.opening-balances.destroy', this.balance.id));
      }
    },
  },
};
</script>

<style scoped>
.ob-help { margin-top: 12px; }
.ob-bar-left { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.ob-status { display: flex; flex-direction: column; gap: 10px; font-size: 0.85rem; }
.ob-status-text { color: var(--lg-mute); }
</style>
