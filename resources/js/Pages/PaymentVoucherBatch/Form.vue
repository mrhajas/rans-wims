<template>
  <admin-layout :title="$t('Create Bulk Vouchers')">
    <div class="lg-page px-4 md:px-0 pvb-form">
      <page-header
        icon="folder"
        :title="$t('Create Bulk Vouchers')"
        :subtitle="$t('Creates one independent Payment Voucher per payee below. Each gets its own PV number, approval and print history.')"
      >
        <Link :href="route('payment-vouchers.index')" class="lg-btn lg-btn-soft">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Payment Vouchers') }}
        </Link>
        <Link :href="route('payment-vouchers.batches.index')" class="lg-btn lg-btn-soft">
          <icons name="folder" class="w-4 h-4" />{{ $t('Batches') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card pvb-top">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-pink"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Common Information') }}</h2>
              <p class="lg-card-sub">{{ $t('This batch is for') }}</p>
            </div>
          </header>

          <div class="mb-4">
            <label class="pv-payee-type-label block mb-2">{{ $t('This batch is for') }}</label>
            <div class="pv-payee-type-toggle">
              <button type="button" class="pv-payee-type-btn" :class="{ 'pv-payee-type-active': form.payee_type === 'External Personnel' }" @click="setPayeeType('External Personnel')">
                <icons name="users" class="w-4 h-4" /> {{ $t('External Personnel') }}
              </button>
              <button type="button" class="pv-payee-type-btn" :class="{ 'pv-payee-type-active': form.payee_type === 'Internal Staff' }" @click="setPayeeType('Internal Staff')">
                <icons name="users" class="w-4 h-4" /> {{ $t('Internal Staff') }}
              </button>
            </div>
            <p class="lg-subtext mt-1">{{ $t('Controls whether the Payee column below lists Contacts or RANS Staff.') }}</p>
          </div>

          <div class="lg-fields">
            <div>
              <auto-complete
                id="type"
                :label="$t('Type of Voucher')"
                :model-value="form.type"
                @update:modelValue="v => (form.type = v)"
                :suggestions="types"
                freeform
                :error="$page.props.errors.type"
              />
            </div>
            <div>
              <auto-complete
                id="project"
                :label="$t('Project (optional)')"
                v-model="form.warehouse_id"
                :suggestions="warehouseSuggestions"
                :error="$page.props.errors.warehouse_id"
                clearable
                :placeholder="companyName"
              />
            </div>
            <div><date-picker :label="$t('Date')" v-model="form.voucher_date" :error="$page.props.errors.voucher_date" required /></div>
            <div><text-input :label="$t('Batch Reference (optional)')" v-model="form.reference" :error="$page.props.errors.reference" /></div>
            <div class="lg-span-all">
              <label class="block mb-1 pvb-label">{{ $t('Common Particulars') }}</label>
              <div class="pv-particulars-input" :class="{ 'pv-particulars-input-error': $page.props.errors.common_particulars }">
                <span class="pv-particulars-prefix">{{ $t('Being Paid for :') }}</span>
                <input v-model="form.common_particulars" list="pvb-particulars-list" class="pv-particulars-remainder" :placeholder="$t('e.g. August salary payments')" />
              </div>
              <datalist id="pvb-particulars-list">
                <option v-for="p in particulars" :key="p" :value="p" />
              </datalist>
              <p v-if="$page.props.errors.common_particulars" class="text-red-600 text-xs mt-1 wims-field-error">{{ $page.props.errors.common_particulars }}</p>
              <p class="lg-subtext mt-1">{{ $t('Used as the particulars line on every voucher created below (a payee row\'s own remarks are appended to it).') }}</p>
            </div>
            <div class="lg-span-all">
              <textarea-input :label="$t('Common Notes (internal, not printed)')" v-model="form.common_notes" :error="$page.props.errors.common_notes" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h3 class="lg-card-title">{{ $t('Payees') }}</h3>
              <p class="lg-card-sub">{{ voucherCount }} {{ $t('Vouchers') }}</p>
            </div>
            <button type="button" class="lg-btn lg-btn-soft" @click="addRow">
              <icons name="plus" class="w-4 h-4" />{{ $t('Add Payee') }}
            </button>
          </header>
          <div v-if="$page.props.errors.rows" class="text-red-600 text-sm mb-2">{{ $page.props.errors.rows }}</div>

          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th class="w-12">{{ $t('SN') }}</th>
                  <th class="pvb-payee-col">{{ $t('Payee') }}</th>
                  <th class="num">{{ $t('Amount') }}</th>
                  <th>{{ $t('Reference') }}</th>
                  <th>{{ $t('Remarks') }}</th>
                  <th class="w-12"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in form.rows" :key="row.key" class="align-top">
                  <td :data-label="$t('SN')" class="lg-subtext">{{ index + 1 }}</td>
                  <td :data-label="$t('Payee')" class="pvb-payee-td">
                    <template v-if="form.payee_type === 'Internal Staff'">
                      <auto-complete
                        class="flex-1"
                        v-model="row.staff_id"
                        :suggestions="staffSuggestions"
                        :error="$page.props.errors['rows.' + index + '.staff_id']"
                      />
                    </template>
                    <template v-else>
                      <div class="flex items-end gap-2">
                        <auto-complete
                          :key="row.fieldKey"
                          class="flex-1"
                          :model-value="row.payee_contact_id"
                          @update:modelValue="id => onPayeeChange(row, id)"
                          :suggestions="contactSuggestions"
                          :error="$page.props.errors['rows.' + index + '.payee_name']"
                        />
                        <button v-if="$can('create-contacts')" type="button" class="pv-add-contact-btn lg-tip" :data-tip="$t('Add New Contact')" @click="openContactModal(row)">
                          <icons name="plus" class="w-4 h-4" />
                        </button>
                      </div>
                      <input v-model="row.payee_name" class="pv-payee-name-input mt-1" :placeholder="$t('Payee name')" />
                    </template>
                  </td>
                  <td :data-label="$t('Amount')" class="num">
                    <text-input type="number" step="0.01" v-model="row.amount" class="pvb-w-amount" :error="$page.props.errors['rows.' + index + '.amount']" />
                  </td>
                  <td :data-label="$t('Reference')">
                    <text-input v-model="row.reference" class="pvb-w-ref" />
                  </td>
                  <td :data-label="$t('Remarks')">
                    <text-input v-model="row.remarks" class="pvb-w-rem" />
                  </td>
                  <td :data-label="$t('Remove')">
                    <button type="button" v-if="form.rows.length > 1" class="lg-icon-btn is-red lg-tip tip-left" @click="removeRow(index)" :data-tip="$t('Remove')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="pv-summary-bar flex items-center justify-between flex-wrap gap-3 mt-4">
            <div class="text-sm font-semibold pvb-label-strong">{{ voucherCount }} {{ $t('Vouchers') }}</div>
            <div class="text-lg font-bold pvb-label-strong">{{ $t('Total') }}: {{ $money(totalAmount) }}</div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h3 class="lg-card-title">{{ $t('Batch Attachments') }}</h3>
              <p class="lg-card-sub">{{ $t('Supporting documents for the whole batch: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
            </div>
          </header>
          <attachment-picker v-model="selectedFiles" :max-files="5" :help-text="$t('Supporting documents for the whole batch: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.')" />
          <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ voucherCount }} {{ $t('Vouchers') }} · {{ $t('Total') }}: {{ $money(totalAmount) }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('payment-vouchers.batches.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <loading-button type="submit" :loading="form.processing" :disabled="form.processing">
              <icons name="tick" class="w-4 h-4 mr-1" /> {{ $t('Create Vouchers') }}
            </loading-button>
          </div>
        </div>
      </form>

      <quick-add-contact-modal :show="showContactModal" @close="showContactModal = false" @created="onContactCreated" />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import QuickAddContactModal from '@/Shared/QuickAddContactModal.vue';

let rowKeySeq = 0;

export default {
  components: { AdminLayout, PageHeader, TextInput, DatePicker, TextareaInput, AutoComplete, LoadingButton, AttachmentPicker, QuickAddContactModal },

  props: {
    contacts: Array,
    staffs: Array,
    warehouses: Array,
    particulars: Array,
    types: Array,
    companyName: String,
  },

  data() {
    return {
      selectedFiles: [],
      showContactModal: false,
      activeRow: null,
      localContacts: [...this.contacts],
      form: this.$inertia.form({
        type: 'Administration',
        payee_type: 'External Personnel',
        warehouse_id: null,
        voucher_date: new Date().toISOString().slice(0, 10),
        common_particulars: '',
        reference: '',
        common_notes: '',
        rows: [this.emptyRow()],
      }),
    };
  },

  computed: {
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, name: w.name }));
    },
    contactSuggestions() {
      return this.localContacts.map(c => ({ id: c.id, name: c.name, category: c.category }));
    },
    staffSuggestions() {
      return this.staffs.map(s => ({ id: s.id, name: `${s.name} (${s.staff_number})` }));
    },
    voucherCount() {
      return this.form.rows.length;
    },
    totalAmount() {
      return this.form.rows.reduce((sum, row) => sum + (parseFloat(row.amount) || 0), 0);
    },
  },

  methods: {
    emptyRow() {
      rowKeySeq += 1;
      return { key: rowKeySeq, fieldKey: 0, payee_contact_id: null, staff_id: null, payee_name: '', amount: 0, reference: '', remarks: '' };
    },
    addRow() {
      this.form.rows.push(this.emptyRow());
    },
    removeRow(index) {
      this.form.rows.splice(index, 1);
    },
    setPayeeType(type) {
      if (this.form.payee_type === type) return;
      this.form.payee_type = type;
      this.form.rows.forEach(row => {
        row.payee_contact_id = null;
        row.staff_id = null;
        row.payee_name = '';
      });
    },
    openContactModal(row) {
      this.activeRow = row;
      this.showContactModal = true;
    },
    onContactCreated(contact) {
      this.localContacts = [contact, ...this.localContacts];
      if (this.activeRow) {
        this.onPayeeChange(this.activeRow, contact.id);
      }
    },
    onPayeeChange(row, contactId) {
      row.payee_contact_id = contactId;
      const contact = this.localContacts.find(c => c.id === contactId);
      if (contact) {
        row.payee_name = contact.name;
      }
      row.fieldKey++;
    },
    submit() {
      this.form.transform(data => ({
        type: data.type,
        payee_type: data.payee_type,
        warehouse_id: data.warehouse_id,
        voucher_date: data.voucher_date,
        common_particulars: data.common_particulars,
        reference: data.reference,
        common_notes: data.common_notes,
        rows: data.rows.map(row => ({
          payee_contact_id: data.payee_type === 'Internal Staff' ? null : row.payee_contact_id,
          staff_id: data.payee_type === 'Internal Staff' ? row.staff_id : null,
          payee_name: row.payee_name,
          amount: row.amount,
          reference: row.reference,
          remarks: row.remarks,
        })),
        attachments: this.selectedFiles,
      })).post(route('payment-vouchers.batches.store'));
    },
  },
};
</script>

<style scoped>
.pvb-form :deep(label:not(:has(input[type='checkbox']))),
.pv-payee-type-label,
.pvb-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.pvb-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.pvb-form :deep(select),
.pvb-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pvb-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.pvb-form :deep(select:focus),
.pvb-form :deep(textarea:focus) {
  border-color: #db2777 !important;
  box-shadow: 0 0 0 3px rgba(219, 39, 119, 0.15) !important;
}
.pvb-top { position: relative; z-index: 3; }
.pv-payee-type-toggle { display: inline-flex; gap: 4px; background: var(--lg-soft); border: 1px solid var(--lg-line); border-radius: 9999px; padding: 4px; max-width: 100%; flex-wrap: wrap; }
.pv-payee-type-btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 700;
  color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease;
}
.pv-payee-type-active { background: linear-gradient(145deg, #f472b6, #be185d); color: #fff; box-shadow: 0 6px 16px -6px rgba(190, 24, 93, 0.5); }
.pv-add-contact-btn {
  position: relative; flex: none; display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; margin-bottom: 0.5rem; border-radius: 0.85rem;
  color: #fff; background: linear-gradient(145deg, #f472b6, #be185d); box-shadow: 0 8px 18px -6px rgba(190, 24, 93, 0.55);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.pv-add-contact-btn:hover { transform: translateY(-2px) scale(1.03); box-shadow: 0 12px 24px -6px rgba(190, 24, 93, 0.6); }
.pvb-payee-col { min-width: 220px; }
.pvb-payee-td { min-width: 260px; }
.pvb-w-amount { width: 8rem; margin-left: auto; }
.pvb-w-ref { width: 8rem; }
.pvb-w-rem { width: 10rem; }
.pv-payee-name-input {
  width: 100%; border: 1px solid var(--lg-line); border-radius: 0.7rem; padding: 0.35rem 0.6rem; font-size: 0.78rem; color: var(--lg-mute); background: var(--lg-soft);
}
.pv-payee-name-input:focus { outline: none; border-color: #db2777; }
.pv-particulars-input {
  display: flex; align-items: stretch; border: 1px solid var(--lg-line); border-radius: 0.85rem; overflow: hidden; background: var(--lg-card);
}
.pv-particulars-input:focus-within { border-color: #db2777; box-shadow: 0 0 0 3px rgba(219, 39, 119, 0.15); }
.pv-particulars-input-error { border-color: #ef4444; }
.pv-particulars-prefix {
  flex: none; display: flex; align-items: center; padding: 0 0.65rem; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute);
  background: var(--lg-soft); border-right: 1px solid var(--lg-line); white-space: nowrap;
}
.pvb-form .pv-particulars-remainder { flex: 1; min-width: 0; border: 0 !important; border-radius: 0 !important; padding: 0.55rem 0.65rem; font-size: 0.85rem; color: var(--lg-ink); background: transparent; box-shadow: none !important; }
.pv-particulars-remainder:focus { outline: none; }
.pv-summary-bar {
  background: rgba(190, 24, 93, 0.06); border: 1px solid rgba(190, 24, 93, 0.2); border-radius: 1rem; padding: 0.85rem 1.1rem;
}
.pvb-label-strong { color: var(--lg-ink); }
html.dark .pv-summary-bar { background: rgba(190, 24, 93, 0.12); border-color: rgba(190, 24, 93, 0.3); }
@media (max-width: 767.98px) {
  .pvb-payee-col, .pvb-payee-td { min-width: 0; }
  .pvb-payee-td > * { flex: 1 1 auto; min-width: 0; width: 100%; }
  .pvb-w-amount, .pvb-w-ref, .pvb-w-rem { width: 100%; }
}

@media (max-width: 767.98px) {
  /* The "Being Paid for :" prefix sat beside the text box and squeezed it to ~25px; on phones it becomes a caption above it. */
  .pv-part-td { min-width: 0; }
  .pv-particulars-input { flex-wrap: wrap; }
  .pv-particulars-prefix { flex: 1 1 100%; border-right: 0; border-bottom: 1px solid var(--lg-line); padding: 0.4rem 0.65rem; white-space: normal; }
  .pv-form .pv-particulars-remainder, .pvb-form .pv-particulars-remainder, .pv-particulars-remainder { flex: 1 1 100%; width: 100%; }
}
</style>
