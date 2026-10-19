<template>
  <admin-layout :title="edit ? $t('Edit Agreement') : $t('New Workforce Agreement')">
    <div class="lg-page px-4 md:px-0 wfa-form">
      <page-header
        icon="doc-text"
        :title="edit ? $t('Edit Agreement') : $t('New Workforce Agreement')"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="reference" class="lg-chip lg-chip-blue" style="text-transform: none">{{ reference }}</span>
        <Link :href="route('workforce-agreements.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Workforce Agreements') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <!-- Who and where -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Worker & Project') }}</h2>
              <p class="lg-card-sub">{{ $t('Labour Personnel') }} / {{ $t('Project / Site') }}</p>
            </div>
          </header>
          <div class="wfa-payee-toggle mb-4">
            <button type="button" class="wfa-payee-btn" :class="{ 'wfa-payee-active': form.payee_type === 'External Personnel' }" @click="form.payee_type = 'External Personnel'">{{ $t('External Personnel') }}</button>
            <button type="button" class="wfa-payee-btn" :class="{ 'wfa-payee-active': form.payee_type === 'Internal Staff' }" @click="form.payee_type = 'Internal Staff'">{{ $t('Internal Staff') }}</button>
          </div>
          <div class="lg-fields">
            <div v-if="form.payee_type === 'Internal Staff'"><auto-complete :label="$t('Staff')" v-model="form.staff_id" :suggestions="staffOptions" :error="$page.props.errors.staff_id" /></div>
            <div v-else><auto-complete :label="$t('Labour Personnel')" v-model="form.contact_id" :suggestions="contactOptions" :error="$page.props.errors.contact_id" /></div>
            <div><auto-complete id="warehouse_id" :label="$t('Project / Site')" v-model="form.warehouse_id" :suggestions="warehouseOptions" :error="$page.props.errors.warehouse_id" /></div>
          </div>
        </section>

        <!-- Scope and money -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Agreement Terms') }}</h2>
              <p class="lg-card-sub">{{ $t('Type of Work (optional)') }} / {{ $t('Agreed Amount (TZS)') }} / {{ $t('Agreement Date') }}</p>
            </div>
          </header>

          <div class="wfa-opening-box mb-4">
            <label class="wfa-opening-check">
              <input type="checkbox" v-model="hasOpeningBalance" @change="onOpeningBalanceToggled" />
              {{ $t('This worker has an opening balance from previous work on this project') }}
            </label>
            <p class="wfa-opening-hint">{{ $t('A carried-over amount owed before this agreement. If no Agreed Amount is entered, the Opening Balance alone becomes the starting Outstanding Balance.') }}</p>
            <div v-if="hasOpeningBalance" class="max-w-xs mt-3">
              <text-input type="number" :label="$t('Opening Balance (TZS)')" v-model="form.opening_balance" :error="$page.props.errors.opening_balance" />
            </div>
          </div>

          <div class="lg-fields">
            <div><auto-complete :label="$t('Type of Work (optional)')" v-model="form.type_of_work" :suggestions="workItems" freeform :error="$page.props.errors.type_of_work" /></div>
            <div><text-input type="number" :label="hasOpeningBalance ? $t('Agreed Amount (TZS, optional)') : $t('Agreed Amount (TZS)')" v-model="form.agreed_amount" :error="$page.props.errors.agreed_amount" /></div>
            <div><date-picker :label="$t('Agreement Date')" v-model="form.agreement_date" :error="$page.props.errors.agreement_date" /></div>
            <div><text-input :label="$t('Agreement Reference (optional)')" v-model="form.agreement_reference" :error="$page.props.errors.agreement_reference" /></div>
            <div class="lg-span-all"><textarea-input :label="$t('Scope of Work (optional)')" v-model="form.scope_of_work" :error="$page.props.errors.scope_of_work" rows="3" /></div>
            <div class="lg-span-all"><textarea-input :label="$t('Notes (optional)')" v-model="form.notes" :error="$page.props.errors.notes" rows="3" /></div>
          </div>
        </section>

        <!-- Attachments -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments (optional)') }}</h2>
              <p class="lg-card-sub">{{ attachmentRows.length }} {{ $t('Files') }}</p>
            </div>
          </header>
          <ul v-if="attachmentRows.length" class="wfa-attachment-list mb-3">
            <li v-for="(row, i) in attachmentRows" :key="row.key" class="wfa-attachment-row">
              <icons name="clip" class="w-4 h-4 wfa-attachment-ico shrink-0" />
              <span class="wfa-attachment-name">{{ row.file.name }}</span>
              <button type="button" class="wfa-attachment-remove lg-tip" :data-tip="$t('Remove')" @click="removeAttachment(i)">
                <icons name="cross" class="w-3.5 h-3.5" />
              </button>
            </li>
          </ul>
          <label class="wfa-file-pick">
            <icons name="plus" class="w-4 h-4" /> {{ $t('Add Files') }}
            <input type="file" multiple class="hidden" @change="onFilesPicked" />
          </label>
        </section>

        <div class="lg-actionbar">
          <div class="lg-actionbar-btns">
            <Link :href="route('workforce-agreements.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ edit ? $t('Save Changes') : $t('Save Agreement') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';

let attachmentKeySeq = 0;

export default {
  components: { AdminLayout, PageHeader, AutoComplete, TextInput, TextareaInput, DatePicker },

  props: {
    edit: Object,
    prefill: { type: Object, default: null },
    warehouses: Array,
    staff: Array,
    contacts: Array,
    workItems: { type: Array, default: () => [] },
    reference: String,
  },

  data() {
    return {
      form: this.$inertia.form({
        payee_type: this.edit?.payee_type || this.prefill?.payee_type || 'External Personnel',
        contact_id: this.edit?.contact_id || this.prefill?.contact_id || null,
        staff_id: this.edit?.staff_id || this.prefill?.staff_id || null,
        warehouse_id: this.edit?.warehouse_id || this.prefill?.warehouse_id || '',
        type_of_work: this.edit?.type_of_work || '',
        scope_of_work: this.edit?.scope_of_work || '',
        agreed_amount: this.edit?.agreed_amount || '',
        opening_balance: this.edit?.opening_balance || '',
        agreement_date: this.edit?.agreement_date || new Date().toISOString().slice(0, 10),
        agreement_reference: this.edit?.agreement_reference || '',
        notes: this.edit?.notes || '',
        attachments: [],
      }),
      attachmentRows: [],
      hasOpeningBalance: !!(this.edit?.opening_balance > 0),
    };
  },

  computed: {
    warehouseOptions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    staffOptions() {
      return this.staff.map(s => ({ id: s.id, label: s.name }));
    },
    contactOptions() {
      return this.contacts.map(c => ({ id: c.id, label: c.name }));
    },
  },

  methods: {
    onOpeningBalanceToggled() {
      if (!this.hasOpeningBalance) this.form.opening_balance = '';
    },
    onFilesPicked(e) {
      const files = Array.from(e.target.files || []);
      files.forEach(file => this.attachmentRows.push({ key: ++attachmentKeySeq, file }));
      this.form.attachments = this.attachmentRows.map(r => r.file);
      e.target.value = '';
    },
    removeAttachment(index) {
      this.attachmentRows.splice(index, 1);
      this.form.attachments = this.attachmentRows.map(r => r.file);
    },
    submit() {
      const url = this.edit ? this.route('workforce-agreements.update', this.edit.id) : this.route('workforce-agreements.store');
      const method = this.edit ? 'put' : 'post';
      this.form.transform(data => ({ ...data, _method: method })).post(url, { forceFormData: true });
    },
  },
};
</script>

<style scoped>
.wfa-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.wfa-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.wfa-form :deep(.lg-fields select),
.wfa-form :deep(.lg-fields textarea),
.wfa-form :deep(.wfa-opening-box input[type='number']) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.wfa-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.wfa-form :deep(.lg-fields select:hover) {
  border-color: rgba(59, 130, 246, 0.6) !important;
}
.wfa-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.wfa-form :deep(.lg-fields select:focus),
.wfa-form :deep(.lg-fields textarea:focus),
.wfa-form :deep(.wfa-opening-box input[type='number']:focus) {
  border-color: #3b82f6 !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18) !important;
}
.wfa-form :deep(textarea) { min-height: 76px; }
.wfa-form :deep(.wfa-opening-box label:not(.wfa-opening-check)) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #92400e;
}

.wfa-payee-toggle { display: inline-flex; max-width: 100%; flex-wrap: wrap; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); padding: 3px; gap: 3px; }
.wfa-payee-btn { padding: 0.45rem 1rem; border-radius: 9999px; font-size: 0.76rem; font-weight: 800; color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.wfa-payee-btn:hover { color: var(--lg-ink); }
.wfa-payee-active, .wfa-payee-active:hover { background: linear-gradient(145deg, #2f6fb3, #0f2540); color: #fff; box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.9); }

.wfa-opening-box { border: 1px solid rgba(245, 158, 11, 0.35); background: rgba(254, 243, 199, 0.55); border-radius: 16px; padding: 0.85rem 1.05rem; }
.wfa-opening-check { display: flex; align-items: center; gap: 0.55rem; font-size: 0.82rem; font-weight: 700; color: #92400e; cursor: pointer; }
.wfa-opening-check input { width: 1rem; height: 1rem; flex: none; accent-color: #b45309; cursor: pointer; }
.wfa-opening-hint { font-size: 0.74rem; line-height: 1.5; color: #92400e; opacity: 0.88; margin: 0.35rem 0 0 1.55rem; }

.wfa-attachment-list { display: flex; flex-direction: column; gap: 0.5rem; }
.wfa-attachment-row { display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.8rem; border: 1px solid var(--lg-line); border-radius: 14px; background: var(--lg-soft); }
.wfa-attachment-ico { color: var(--lg-faint); }
.wfa-attachment-name { flex: 1; min-width: 0; font-size: 0.83rem; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wfa-attachment-remove { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; flex: none; border-radius: 9999px; background: #fee2e2; color: #b91c1c; }
.wfa-attachment-remove:hover { background: #fecaca; }
.wfa-file-pick { display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; background: rgba(99, 102, 241, 0.1); color: #4338ca; border: 1px dashed rgba(99, 102, 241, 0.45); cursor: pointer; transition: background 0.15s ease; }
.wfa-file-pick:hover { background: rgba(99, 102, 241, 0.18); }

html.dark .wfa-opening-box { background: rgba(146, 64, 14, 0.14); border-color: rgba(253, 230, 138, 0.25); }
html.dark .wfa-opening-check, html.dark .wfa-opening-hint, html.dark .wfa-form :deep(.wfa-opening-box label:not(.wfa-opening-check)) { color: #fcd34d; }
html.dark .wfa-attachment-remove { background: rgba(185, 28, 28, 0.25); color: #fecaca; }
html.dark .wfa-file-pick { color: #c7d2fe; background: rgba(99, 102, 241, 0.16); }
</style>
