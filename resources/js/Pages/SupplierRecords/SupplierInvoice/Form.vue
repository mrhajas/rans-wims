<template>
  <admin-layout :title="editing ? $t('Edit Supplier Invoice') : $t('Record Supplier Invoice')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="doc-text"
        :title="editing ? $t('Edit Supplier Invoice') : $t('Record Supplier Invoice')"
        :subtitle="$t('Invoices raised by suppliers against purchase orders, matched to deliveries.')"
      >
        <Link :href="route('supplier-records.supplier-invoices.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Invoices') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Purchase Order') }} / {{ $t('Supplier Invoice Number') }} / {{ $t('Invoice Date') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete
              v-if="!editing"
              id="purchase_order"
              :label="$t('Purchase Order')"
              v-model="form.purchase_order_id"
              :suggestions="poOptions"
              :error="$page.props.errors.purchase_order_id"
              required
            />
            <div v-else>
              <label class="fx-label">{{ $t('Purchase Order') }}</label>
              <p class="text-sm font-semibold mt-2" style="color: var(--lg-ink)">{{ purchaseOrder ? purchaseOrder.reference : '-' }}</p>
            </div>
            <text-input :label="$t('Supplier Invoice Number')" v-model="form.invoice_number" :error="$page.props.errors.invoice_number" required />
            <date-picker :label="$t('Invoice Date')" v-model="form.invoice_date" :error="$page.props.errors.invoice_date" required />
            <date-picker :label="$t('Due Date')" v-model="form.due_date" :error="$page.props.errors.due_date" required />
            <div class="lg-span-all">
              <textarea-input :label="$t('Remarks')" v-model="form.remarks" :error="$page.props.errors.remarks" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ summarySource ? (editing ? $t('Invoice Items') : $t('Purchase Order Summary') + ' / ' + purchaseOrder.reference) : $t('Purchase Order Summary') }}</h2>
              <p v-if="summarySource && !editing" class="lg-card-sub">{{ $t('read-only, matching is computed automatically on save') }}</p>
            </div>
          </header>

          <div v-if="summarySource" class="lg-table-wrap">
            <table class="lg-table">
              <thead>
                <tr>
                  <th>{{ $t('Item') }}</th>
                  <th>{{ $t('Unit') }}</th>
                  <th class="num">{{ $t('Quantity') }}</th>
                  <th class="num">{{ $t('Unit Price') }}</th>
                  <th class="num">{{ $t('Line Total') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, index) in summarySource.items" :key="index">
                  <td>{{ line.item_code ? line.item_code + ' - ' : '' }}{{ line.item_name }}</td>
                  <td>{{ line.unit_label || '-' }}</td>
                  <td class="num">{{ $number(line.quantity) }}</td>
                  <td class="num">{{ $money(line.unit_price, currency) }}</td>
                  <td class="num fx-strong">{{ $money(line.quantity * line.unit_price, currency) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="fx-foot">
                  <td colspan="4">{{ $t('Subtotal') }}</td>
                  <td class="num">{{ $money(summarySource.subtotal, currency) }}</td>
                </tr>
                <tr class="fx-foot">
                  <td colspan="4">{{ $t('Tax') }}</td>
                  <td class="num">{{ $money(summarySource.tax_amount, currency) }}</td>
                </tr>
                <tr class="fx-foot fx-foot-total">
                  <td colspan="4">{{ $t('Total') }}</td>
                  <td class="num">{{ $money(summarySource.total, currency) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div v-else class="lg-note lg-note-blue">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Select a Purchase Order above to see its items and total before submitting.') }}</span>
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
            <p class="fx-hint">{{ $t('The supplier\'s original invoice, plus any supporting documents: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
            <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
            <ul v-if="selectedFiles.length" class="fx-files fx-files-list">
              <li v-for="(file, index) in selectedFiles" :key="index">
                <span class="truncate">{{ file.name }}</span>
                <button type="button" class="fx-remove" @click="removeFile(index)">{{ $t('Remove') }}</button>
              </li>
            </ul>
            <ul v-if="editing && invoice.attachments && invoice.attachments.length" class="fx-files fx-files-list">
              <li v-for="file in invoice.attachments" :key="file.id">
                <span class="truncate">{{ file.name }}</span>
                <a :href="route('supplier-records.supplier-invoices.attachments.download', [invoice.id, file.id])" class="fx-dl">{{ $t('Download') }}</a>
              </li>
            </ul>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ purchaseOrder ? purchaseOrder.reference : $t('Purchase Order') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.supplier-invoices.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing || !purchaseOrder" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ editing ? $t('Save Changes') : $t('Record Invoice') }}
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
    invoice: Object,
    purchaseOrder: Object,
    purchaseOrders: Array,
  },

  data() {
    const editing = !!this.invoice;
    return {
      editing,
      selectedFiles: [],
      form: this.$inertia.form({
        purchase_order_id: this.purchaseOrder ? this.purchaseOrder.id : null,
        invoice_number: editing ? this.invoice.invoice_number : '',
        invoice_date: editing ? this.invoice.invoice_date : '',
        due_date: editing ? this.invoice.due_date : '',
        remarks: editing ? this.invoice.remarks : '',
      }),
    };
  },

  computed: {
    poOptions() {
      return this.purchaseOrders.map(o => ({ label: `${o.reference} / ${o.supplier_name}`, value: o.id }));
    },
    summarySource() {
      return this.editing ? this.invoice : this.purchaseOrder;
    },
    currency() {
      return this.purchaseOrder ? this.purchaseOrder.currency : null;
    },
  },

  watch: {
    // Changing the Purchase Order reloads the create page with the new PO's
    // context (items/total/supplier) — matching happens server-side, so the
    // form itself carries no line items to keep in sync.
    'form.purchase_order_id'(newId, oldId) {
      if (this.editing) {
        return;
      }
      const currentId = this.purchaseOrder ? this.purchaseOrder.id : null;
      if (newId && newId !== oldId && newId !== currentId) {
        this.$inertia.visit(route('supplier-records.supplier-invoices.create', { purchase_order_id: newId }));
      }
    },
  },

  methods: {
    onFilesChosen() {
      const newFiles = this.$refs.attachmentFile?.files ? Array.from(this.$refs.attachmentFile.files) : [];
      const combined = [...this.selectedFiles];
      for (const file of newFiles) {
        if (!combined.some(f => f.name === file.name && f.size === file.size)) {
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
        [this.editing ? 'patch' : 'post'](
          this.editing ? route('supplier-records.supplier-invoices.update', this.invoice.id) : route('supplier-records.supplier-invoices.store')
        );
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

.fx-strong { font-weight: 700; }
.fx-foot td { font-weight: 700; background: var(--lg-soft); }
.fx-foot-total td { font-weight: 800; color: var(--lg-navy); }
html.dark .fx-foot-total td { color: #93c5fd; }
</style>
