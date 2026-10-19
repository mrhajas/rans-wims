<template>
  <admin-layout :title="edit ? $t('Edit Payment Request') : $t('New Payment Request')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="key"
        :title="edit ? $t('Edit Payment Request') : $t('New Payment Request')"
        :subtitle="$t('Requests to pay a supplier invoice, in full or in part, pending approval.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-green lg-mono" style="text-transform: none">{{ edit.reference }}</span>
        <Link :href="route('supplier-records.payment-requests.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Payment Requests') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section v-if="edit || !invoice" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('This payment request is for') }}</h2>
              <p class="lg-card-sub">{{ $t('Pay a Specific Invoice') }} / {{ $t('Pay Against Account Balance') }}</p>
            </div>
          </header>

          <div v-if="edit">
            <div class="fx-edit-info">
              <span class="lg-chip lg-chip-indigo" style="text-transform: none">
                {{ edit.type === 'balance' ? $t('Pay Against Account Balance') : $t('Pay a Specific Invoice') }}
              </span>
              <span v-if="edit.type === 'invoice'" class="lg-main">{{ edit.invoice_reference }}</span>
              <span v-else class="lg-main">{{ edit.supplier_name }}</span>
            </div>
            <p class="fx-hint">{{ $t('The type, invoice and supplier can\'t be changed. Cancel this request and submit a new one instead if those need to change.') }}</p>
          </div>
          <div v-else>
            <div class="spr-scope-grid">
              <button type="button" class="spr-scope-card" :class="{ 'spr-scope-active': form.type === 'invoice' }" @click="setType('invoice')">
                <span class="spr-scope-icon"><icons name="doc-text" class="w-5 h-5" /></span>
                <span class="spr-scope-text">
                  <span class="spr-scope-title">{{ $t('Pay a Specific Invoice') }}</span>
                  <span class="spr-scope-desc">{{ $t('Settle one supplier invoice') }}</span>
                </span>
                <span class="spr-scope-check"><icons name="tick" class="w-3.5 h-3.5" /></span>
              </button>
              <button type="button" class="spr-scope-card" :class="{ 'spr-scope-active': form.type === 'balance' }" @click="setType('balance')">
                <span class="spr-scope-icon"><icons name="chart-bar" class="w-5 h-5" /></span>
                <span class="spr-scope-text">
                  <span class="spr-scope-title">{{ $t('Pay Against Account Balance') }}</span>
                  <span class="spr-scope-desc">{{ $t('Applied to oldest outstanding invoices first') }}</span>
                </span>
                <span class="spr-scope-check"><icons name="tick" class="w-3.5 h-3.5" /></span>
              </button>
            </div>
            <div v-if="form.type === 'balance'" class="lg-note lg-note-blue mt-3">
              <icons name="info" class="w-4 h-4" />
              <span>{{ $t('Not tied to one invoice. The amount will be applied to this supplier\'s oldest outstanding invoices first when the payment is recorded.') }}</span>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Amount') }} / {{ $t('Date Requested') }} / {{ $t('Remarks') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete
              v-if="!edit && form.type === 'invoice'"
              id="invoice"
              :label="$t('Invoice')"
              v-model="form.invoice_id"
              :suggestions="invoiceOptions"
              :error="$page.props.errors.invoice_id"
              :disable="!!invoice"
              required
            />
            <auto-complete
              v-else-if="!edit"
              id="supplier"
              :label="$t('Supplier')"
              v-model="form.supplier_id"
              :suggestions="supplierOptions"
              :error="$page.props.errors.supplier_id"
              required
            />
            <div>
              <text-input type="number" step="0.01" :label="$t('Amount')" v-model="form.amount" :error="$page.props.errors.amount" required />
              <p v-if="edit && edit.available_balance !== null" class="fx-hint">
                {{ $t('Available balance') }}: {{ $money(edit.available_balance) }}
              </p>
              <p v-if="!edit && form.type === 'invoice' && selectedInvoice && partialRemaining !== null" class="fx-hint" :class="{ 'fx-hint-warn': partialRemaining > 0 }">
                <template v-if="partialRemaining > 0">
                  {{ $t('This is a partial payment. Remaining balance after this request:') }} {{ $money(partialRemaining) }}
                </template>
                <template v-else-if="form.amount">
                  {{ $t('This settles the invoice in full.') }}
                </template>
              </p>
              <p v-else-if="!edit && form.type === 'balance' && selectedSupplier" class="fx-hint">
                {{ $t('Outstanding balance') }}: {{ $money(selectedSupplier.balance) }}
              </p>
            </div>
            <date-picker :label="$t('Date Requested')" v-model="form.date_requested" :error="$page.props.errors.date_requested" required />
            <div class="lg-span-all">
              <textarea-input :label="$t('Remarks')" v-model="form.remarks" :error="$page.props.errors.remarks" />
            </div>
          </div>

          <div v-if="!edit && form.type === 'invoice' && selectedInvoice" class="lg-note lg-note-amber mt-4">
            <icons name="info" class="w-4 h-4" />
            <span>
              <strong>{{ $t('Invoice Balance') }}:</strong> {{ $money(selectedInvoice.balance) }}
              &nbsp;|&nbsp;
              <strong>{{ $t('Due Date') }}:</strong> {{ $date(selectedInvoice.due_date) }}
            </span>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ form.amount ? $money(form.amount) : $t('Amount') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.payment-requests.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ edit ? $t('Save Changes') : $t('Submit Request') }}
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
import DatePicker from '@/Shared/DatePicker.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, TextInput, DatePicker, TextareaInput, AutoComplete, PageHeader },

  props: {
    edit: { type: Object, default: null },
    invoice: Object,
    payableInvoices: { type: Array, default: () => [] },
    suppliersWithBalance: { type: Array, default: () => [] },
  },

  data() {
    if (this.edit) {
      return {
        form: this.$inertia.form({
          amount: this.edit.amount,
          date_requested: this.edit.date_requested,
          remarks: this.edit.approval_remarks || '',
        }),
      };
    }

    const preselected = this.invoice ? this.invoice.id : null;
    const preselectedInvoice = this.invoice || (this.payableInvoices || []).find(i => i.id === preselected);
    return {
      form: this.$inertia.form({
        type: 'invoice',
        invoice_id: preselected,
        supplier_id: null,
        amount: preselectedInvoice ? preselectedInvoice.balance : '',
        date_requested: new Date().toISOString().slice(0, 10),
        remarks: '',
      }),
    };
  },

  computed: {
    invoiceOptions() {
      return this.payableInvoices.map(i => ({
        label: `${i.reference} / ${i.supplier_name} / ${this.$money(i.balance)}`,
        value: i.id,
      }));
    },
    supplierOptions() {
      return (this.suppliersWithBalance || []).map(s => ({
        label: `${s.name} / ${this.$t('Balance')}: ${this.$money(s.balance)}`,
        value: s.id,
      }));
    },
    selectedInvoice() {
      if (this.invoice && this.invoice.id === this.form.invoice_id) {
        return this.invoice;
      }
      return this.payableInvoices.find(i => i.id === this.form.invoice_id) || null;
    },
    selectedSupplier() {
      return (this.suppliersWithBalance || []).find(s => s.id === this.form.supplier_id) || null;
    },
    partialRemaining() {
      if (!this.selectedInvoice) {
        return null;
      }
      const amount = parseFloat(this.form.amount) || 0;
      return Math.max(this.selectedInvoice.balance - amount, 0);
    },
  },

  watch: {
    'form.invoice_id'(newId) {
      const inv = this.payableInvoices.find(i => i.id === newId);
      if (inv && !this.form.amount) {
        this.form.amount = inv.balance;
      }
    },
    'form.supplier_id'(newId) {
      const s = (this.suppliersWithBalance || []).find(sup => sup.id === newId);
      if (s) {
        this.form.amount = s.balance;
      }
    },
  },

  methods: {
    setType(type) {
      this.form.type = type;
      this.form.invoice_id = null;
      this.form.supplier_id = null;
      this.form.amount = '';
    },
    submit() {
      if (this.edit) {
        this.form.put(route('supplier-records.payment-requests.update', this.edit.id));
        return;
      }
      this.form.post(route('supplier-records.payment-requests.store'));
    },
  },
};
</script>

<style scoped>
.fx-form :deep(label:not(:has(input[type='checkbox']))),
.fx-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.fx-form :deep(select),
.fx-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.55rem !important;
  padding-bottom: 0.55rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-form :deep(input:disabled) {
  background: var(--lg-soft);
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.fx-form :deep(select:hover) {
  border-color: rgba(16, 185, 129, 0.6) !important;
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.fx-form :deep(select:focus),
.fx-form :deep(textarea:focus) {
  border-color: #10b981 !important;
  box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18) !important;
}
.fx-hint { margin-top: 0.4rem; font-size: 0.74rem; color: var(--lg-mute); }
.fx-hint-warn { color: #d97706; }
.fx-edit-info { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; }

.spr-scope-grid { display: grid; grid-template-columns: 1fr; gap: 0.75rem; }
@media (min-width: 640px) { .spr-scope-grid { grid-template-columns: 1fr 1fr; } }
.spr-scope-card {
  position: relative; display: flex; align-items: center; gap: 0.75rem; text-align: left; padding: 0.85rem 1rem;
  border-radius: 1rem; border: 1.5px solid var(--lg-line); background: var(--lg-card);
  transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.spr-scope-card:hover { transform: translateY(-1px); border-color: #93c5fd; }
.spr-scope-active { border-color: #2f6fb3; background: rgba(219, 234, 254, 0.45); box-shadow: 0 8px 20px -10px rgba(47, 111, 179, 0.45); }
.spr-scope-icon {
  flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: 0.75rem;
  background: var(--lg-soft); color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease;
}
.spr-scope-active .spr-scope-icon { background: linear-gradient(145deg, #2f6fb3, #0f2540); color: #fff; box-shadow: 0 6px 14px -6px rgba(15, 37, 64, 0.6); }
.spr-scope-text { display: flex; flex-direction: column; min-width: 0; }
.spr-scope-title { font-size: 0.88rem; font-weight: 700; color: var(--lg-ink); }
.spr-scope-desc { font-size: 0.72rem; color: var(--lg-mute); margin-top: 1px; }
.spr-scope-check {
  position: absolute; top: 0.6rem; right: 0.6rem; display: inline-flex; align-items: center; justify-content: center;
  width: 1.15rem; height: 1.15rem; border-radius: 9999px; background: #2f6fb3; color: #fff;
  opacity: 0; transform: scale(0.6); transition: opacity 0.15s ease, transform 0.15s ease;
}
.spr-scope-active .spr-scope-check { opacity: 1; transform: scale(1); }
html.dark .spr-scope-active { background: rgba(47, 111, 179, 0.18); border-color: #60a5fa; }
html.dark .spr-scope-card:hover { border-color: #60a5fa; }
</style>
