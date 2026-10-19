<template>
  <admin-layout :title="$t('Record Payment')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="cash"
        :title="$t('Record Payment')"
        :subtitle="$t('Permanent record of payments made against approved payment requests.')"
      >
        <Link :href="route('supplier-records.payments.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Payments') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Payment') }}</h2>
              <p class="lg-card-sub">{{ $t('Payment Request') }} / {{ $t('Amount') }} / {{ $t('Method') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete
              id="payment_request"
              :label="$t('Payment Request')"
              v-model="form.payment_request_id"
              :suggestions="requestOptions"
              :error="$page.props.errors.payment_request_id"
              :disable="!!paymentRequest"
              required
            />
            <div>
              <text-input type="number" step="0.01" :label="$t('Amount')" v-model="form.amount" :error="$page.props.errors.amount" required />
              <p v-if="selectedRequest" class="fx-hint">{{ $t('Approved amount') }}: {{ $money(selectedRequest.amount, form.currency) }}</p>
            </div>
            <select-input :label="$t('Method')" v-model="form.method" :error="$page.props.errors.method">
              <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
            </select-input>
            <auto-complete
              id="currency"
              :label="$t('Currency')"
              v-model="form.currency"
              :suggestions="currencyOptions"
              :error="$page.props.errors.currency"
            />
            <text-input v-if="form.method === 'Cheque'" :label="$t('Cheque Number')" v-model="form.cheque_number" :error="$page.props.errors.cheque_number" required />
            <text-input :label="$t('Reference / Transaction Number')" v-model="form.reference_number" :error="$page.props.errors.reference_number" />
            <date-picker :label="$t('Paid On')" v-model="form.paid_on" :error="$page.props.errors.paid_on" required />
            <auto-complete
              id="paid_by"
              :label="$t('Payment Made By')"
              v-model="form.paid_by_user_id"
              :suggestions="userOptions"
              :error="$page.props.errors.paid_by_user_id"
              required
            />
            <div class="lg-span-all">
              <textarea-input :label="$t('Remarks')" v-model="form.remarks" :error="$page.props.errors.remarks" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="upload" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
            </div>
          </header>
          <div>
            <button type="button" class="lg-btn lg-btn-soft" @click.prevent="$refs.attachmentFile?.click()">
              <icons name="upload" class="w-4 h-4" /> {{ selectedFiles.length ? $t('Add more files') : $t('Choose files') }}
            </button>
            <input ref="attachmentFile" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="sr-only" @change="onFilesChosen" />
            <p class="fx-hint">
              {{ $t('Bank slips, cheque photos, or other proof of payment: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}
            </p>
            <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
            <ul v-if="selectedFiles.length" class="fx-files fx-files-list">
              <li v-for="(file, index) in selectedFiles" :key="index">
                <span class="truncate">{{ file.name }}</span>
                <button type="button" class="fx-remove" @click="removeFile(index)">{{ $t('Remove') }}</button>
              </li>
            </ul>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ selectedRequest ? selectedRequest.reference : $t('Payment Request') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.payments.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t('Record Payment') }}
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
import SelectInput from '@/Shared/SelectInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, TextInput, DatePicker, TextareaInput, SelectInput, AutoComplete, PageHeader },

  props: {
    paymentRequest: Object,
    approvedRequests: Array,
    methods: Array,
    currencies: Array,
    users: Array,
  },

  data() {
    const preselected = this.paymentRequest ? this.paymentRequest.id : null;
    const preselectedRequest = this.paymentRequest || (this.approvedRequests || []).find(r => r.id === preselected);
    return {
      selectedFiles: [],
      form: this.$inertia.form({
        payment_request_id: preselected,
        amount: preselectedRequest ? preselectedRequest.amount : '',
        method: this.methods && this.methods.length ? this.methods[0] : '',
        currency: 'TZS',
        cheque_number: '',
        reference_number: '',
        paid_on: new Date().toISOString().slice(0, 10),
        paid_by_user_id: this.$page.props.auth.user.id,
        remarks: '',
      }),
    };
  },

  computed: {
    requestOptions() {
      return this.approvedRequests.map(r => ({
        label: `${r.reference} / ${r.supplier_name} / ${this.$money(r.amount, r.currency || this.form.currency)}`,
        value: r.id,
      }));
    },
    selectedRequest() {
      if (this.paymentRequest && this.paymentRequest.id === this.form.payment_request_id) {
        return this.paymentRequest;
      }
      return this.approvedRequests.find(r => r.id === this.form.payment_request_id) || null;
    },
    currencyOptions() {
      return (this.currencies || []).map(c => ({ label: c.label, value: c.code }));
    },
    userOptions() {
      return (this.users || []).map(u => ({ label: u.name, value: u.id }));
    },
  },

  watch: {
    'form.payment_request_id'(newId) {
      const req = this.approvedRequests.find(r => r.id === newId);
      if (req && !this.form.amount) {
        this.form.amount = req.amount;
      }
    },
  },

  methods: {
    onFilesChosen() {
      const newFiles = this.$refs.attachmentFile?.files ? Array.from(this.$refs.attachmentFile.files) : [];
      const combined = [...this.selectedFiles];
      for (const file of newFiles) {
        const isDuplicate = combined.some(f => f.name === file.name && f.size === file.size);
        if (!isDuplicate) {
          combined.push(file);
        }
      }
      this.selectedFiles = combined;
      if (this.$refs.attachmentFile) {
        this.$refs.attachmentFile.value = '';
      }
    },
    removeFile(index) {
      this.selectedFiles.splice(index, 1);
    },
    submit() {
      this.form
        .transform(data => ({ ...data, attachments: this.selectedFiles }))
        .post(route('supplier-records.payments.store'));
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
.fx-files { width: 100%; font-size: 0.8rem; color: var(--lg-ink); }
.fx-files-list { border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; margin-top: 0.75rem; }
.fx-files-list li { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-width: 0; padding: 8px 12px; border-bottom: 1px solid var(--lg-line); }
.fx-files-list li:last-child { border-bottom: 0; }
.fx-dl { flex: none; font-weight: 700; color: var(--lg-navy); }
.fx-dl:hover { text-decoration: underline; }
.fx-remove { flex: none; font-weight: 700; color: #dc2626; }
.fx-remove:hover { text-decoration: underline; }
.fx-hint { margin-top: 0.4rem; font-size: 0.74rem; color: var(--lg-mute); }
.fx-money { font-variant-numeric: tabular-nums; }
html.dark .fx-dl { color: #93c5fd; }
</style>
