<template>
  <admin-layout :title="$t('Supplier Financial Details')">
    <div class="lg-page sp-form px-4 md:px-0">
      <page-header
        icon="key"
        :title="supplier.name + ' / ' + $t('Financial Details')"
        :subtitle="$t('These fields are stored on the Contact record and only apply to Suppliers.')"
      >
        <Link :href="route('supplier-records.suppliers.show', supplier.id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ supplier.name }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Financial Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Tax ID') }} / {{ $t('Payment Terms (days)') }} / {{ $t('Currency') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Tax ID')" v-model="form.tax_id" :error="$page.props.errors.tax_id" />
            <text-input type="number" :label="$t('Payment Terms (days)')" v-model="form.payment_terms_days" :error="$page.props.errors.payment_terms_days" />
            <auto-complete id="currency" :label="$t('Currency')" v-model="form.currency" :suggestions="currencyOptions" :error="$page.props.errors.currency" />
            <text-input type="number" :label="$t('Credit Limit')" v-model="form.credit_limit" :error="$page.props.errors.credit_limit" />
            <div class="lg-span-all">
              <label class="sp-label">{{ $t('Opening Balance') }}</label>
              <div class="sp-readonly">
                <span class="sp-readonly-val">{{ $money(supplier.opening_balance || 0, form.currency) }}</span>
                <icons name="lock-closed" class="w-4 h-4 sp-readonly-ico" />
              </div>
              <p class="sp-hint">
                {{ $t('Set only by a verified Ledger entry, so this can never drift from the Current Balance.') }}
                <Link :href="route('supplier-records.suppliers.ledger.show', supplier.id)" class="sp-link">{{ $t('Go to Ledger') }}</Link>
              </p>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="calculator" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Tax Settings') }}</h2>
              <p class="lg-card-sub">{{ $t('Defaults used when creating Purchase Orders, Invoices, and ledger entries for this supplier, always overridable per transaction.') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input type="number" step="0.01" :label="$t('Purchase Tax % (e.g. VAT)')" v-model="form.tax_percentage" :error="$page.props.errors.tax_percentage" />
            <text-input type="number" step="0.01" :label="$t('Withholding Tax %')" v-model="form.withholding_tax_percentage" :error="$page.props.errors.withholding_tax_percentage" />
            <text-input type="number" step="0.01" :label="$t('Retention %')" v-model="form.retention_percentage" :error="$page.props.errors.retention_percentage" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Bank Details') }}</h2></div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Bank Name')" v-model="form.bank_name" :error="$page.props.errors.bank_name" />
            <text-input :label="$t('Bank Account')" v-model="form.bank_account" :error="$page.props.errors.bank_account" />
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ supplier.name }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.suppliers.show', supplier.id)" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save Changes') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

const CURRENCIES = [
  ['TZS', 'Tanzanian Shilling'], ['USD', 'US Dollar'], ['EUR', 'Euro'], ['GBP', 'British Pound'],
  ['KES', 'Kenyan Shilling'], ['UGX', 'Ugandan Shilling'], ['RWF', 'Rwandan Franc'], ['ZAR', 'South African Rand'],
  ['CNY', 'Chinese Yuan'], ['INR', 'Indian Rupee'], ['AED', 'UAE Dirham'], ['SAR', 'Saudi Riyal'],
  ['JPY', 'Japanese Yen'], ['CHF', 'Swiss Franc'], ['CAD', 'Canadian Dollar'], ['AUD', 'Australian Dollar'],
  ['NGN', 'Nigerian Naira'], ['EGP', 'Egyptian Pound'], ['ETB', 'Ethiopian Birr'], ['ZMW', 'Zambian Kwacha'],
  ['MWK', 'Malawian Kwacha'], ['BWP', 'Botswana Pula'], ['XOF', 'West African CFA Franc'], ['SGD', 'Singapore Dollar'],
  ['HKD', 'Hong Kong Dollar'],
];

export default {
  components: { AdminLayout, TextInput, AutoComplete, PageHeader },

  props: {
    supplier: Object,
  },

  data() {
    return {
      form: this.$inertia.form({
        tax_id: this.supplier.tax_id || '',
        tax_percentage: this.supplier.tax_percentage ?? 18,
        withholding_tax_percentage: this.supplier.withholding_tax_percentage ?? 0,
        retention_percentage: this.supplier.retention_percentage ?? 0,
        payment_terms_days: this.supplier.payment_terms_days || 30,
        currency: this.supplier.currency || 'TZS',
        credit_limit: this.supplier.credit_limit || 0,
        bank_name: this.supplier.bank_name || '',
        bank_account: this.supplier.bank_account || '',
      }),
    };
  },

  computed: {
    currencyOptions() {
      return CURRENCIES.map(([code, name]) => ({ label: `${code} / ${name}`, value: code }));
    },
  },

  methods: {
    submit() {
      this.form.patch(route('supplier-records.suppliers.details.update', this.supplier.id));
    },
  },
};
</script>

<style scoped>
.sp-form :deep(label:not(:has(input[type='checkbox']))),
.sp-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.sp-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.sp-form :deep(select),
.sp-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.55rem !important;
  padding-bottom: 0.55rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sp-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.sp-form :deep(select:focus),
.sp-form :deep(textarea:focus) {
  outline: none;
  border-color: #3b82f6 !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important;
}
.sp-readonly { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0.6rem 0.9rem; border-radius: 0.85rem; border: 1px dashed var(--lg-line); background: var(--lg-soft); }
.sp-readonly-val { font-size: 1rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.sp-readonly-ico { color: var(--lg-faint); }
.sp-hint { margin-top: 0.4rem; font-size: 0.74rem; color: var(--lg-mute); }
.sp-link { font-weight: 700; color: var(--lg-navy); }
.sp-link:hover { text-decoration: underline; }
html.dark .sp-link { color: #93c5fd; }
</style>
