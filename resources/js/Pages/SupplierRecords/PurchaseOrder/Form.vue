<template>
  <admin-layout :title="purchaseOrder ? $t('Edit Purchase Order') : $t('Create Purchase Order')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="truck"
        :title="purchaseOrder ? $t('Edit Purchase Order') : $t('Create Purchase Order')"
        :subtitle="$t('Orders raised against suppliers, from draft through delivery.')"
      >
        <span v-if="purchaseOrder" class="lg-chip lg-chip-green lg-mono" style="text-transform: none">{{ purchaseOrder.reference }}</span>
        <Link :href="route('supplier-records.purchase-orders.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Purchase Orders') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="truck" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Supplier') }} / {{ $t('Date Issued') }} / {{ $t('Currency') }}</p>
            </div>
          </header>

          <div class="lg-fields">
            <div class="lg-span-all">
              <label class="fx-label">{{ $t('This order is for') }}</label>
              <div class="fx-scope-grid">
                <button type="button" class="fx-scope-card" :class="{ 'is-active': form.scope === 'project' }" @click="setScope('project')">
                  <span class="fx-scope-icon"><icons name="map-pin" class="w-5 h-5" /></span>
                  <span class="fx-scope-text">
                    <span class="fx-scope-title">{{ $t('Project / Warehouse') }}</span>
                    <span class="fx-scope-desc">{{ $t('Tied to a specific site or project') }}</span>
                  </span>
                  <span class="fx-scope-check"><icons name="tick" class="w-3.5 h-3.5" /></span>
                </button>
                <button type="button" class="fx-scope-card" :class="{ 'is-active': form.scope === 'company' }" @click="setScope('company')">
                  <span class="fx-scope-icon"><icons name="building" class="w-5 h-5" /></span>
                  <span class="fx-scope-text">
                    <span class="fx-scope-title">{{ $t('RANS Company Limited') }}</span>
                    <span class="fx-scope-desc">{{ $t('Head Office, not tied to a project') }}</span>
                  </span>
                  <span class="fx-scope-check"><icons name="tick" class="w-3.5 h-3.5" /></span>
                </button>
              </div>
            </div>

            <div>
              <auto-complete
                id="supplier"
                :label="$t('Supplier')"
                :model-value="form.supplier_id"
                @update:modelValue="onSupplierChange"
                :suggestions="supplierSuggestions"
                :error="$page.props.errors.supplier_id"
              />
            </div>
            <div v-if="form.scope === 'project'">
              <auto-complete
                id="warehouse"
                :label="$t('Warehouse / Project')"
                v-model="form.warehouse_id"
                :suggestions="warehouseSuggestions"
                :error="$page.props.errors.warehouse_id"
              />
            </div>
            <div v-else class="flex items-end pb-2">
              <p class="lg-note lg-note-blue w-full">
                <icons name="info" class="w-4 h-4" />
                <span>{{ $t('This order is for {company} directly, not tied to a project.', { company: companyName }) }}</span>
              </p>
            </div>
            <div>
              <auto-complete
                id="purchase-request"
                :label="$t('Linked Purchase Request (optional)')"
                v-model="form.purchase_request_id"
                :suggestions="purchaseRequestSuggestions"
                :error="$page.props.errors.purchase_request_id"
              />
            </div>
            <div>
              <date-picker :label="$t('Date Issued')" v-model="form.date_issued" :error="$page.props.errors.date_issued" required />
            </div>
            <div>
              <date-picker :label="$t('Expected Delivery Date')" v-model="form.expected_delivery_date" :error="$page.props.errors.expected_delivery_date" />
            </div>
            <div>
              <auto-complete
                id="currency"
                :label="$t('Currency')"
                v-model="form.currency"
                :suggestions="currencySuggestions"
                :error="$page.props.errors.currency"
                placeholder="TZS"
              />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="calculator" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Terms') }}</h2>
              <p class="lg-card-sub">{{ $t('Include Tax') }} / {{ $t('Terms') }} / {{ $t('Remarks') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all flex flex-wrap items-center gap-4">
              <check-box id="tax_included" v-model:checked="form.tax_included" :error="$page.props.errors.tax_included" :label="$t('Include Tax')" />
              <text-input
                v-if="form.tax_included"
                id="tax_percentage"
                type="number"
                step="0.01"
                class="w-28"
                :label="$t('Tax %')"
                v-model="form.tax_percentage"
                :error="$page.props.errors.tax_percentage"
              />
            </div>
            <div class="lg-span-all">
              <textarea-input :label="$t('Terms')" v-model="form.terms" :error="$page.props.errors.terms" />
            </div>
            <div class="lg-span-all">
              <textarea-input :label="$t('Remarks')" v-model="form.remarks" :error="$page.props.errors.remarks" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Line Items') }}</h2>
              <p class="lg-card-sub">{{ $t('Add at least one item') }}</p>
            </div>
            <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ form.items.length }} {{ $t('Items') }}</span>
          </header>
          <div v-if="$page.props.errors.items" class="text-red-600 text-sm mb-2">{{ $page.props.errors.items }}</div>

          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive fx-items-table">
              <thead>
                <tr>
                  <th>{{ $t('Item') }}</th>
                  <th>{{ $t('Unit') }}</th>
                  <th class="text-right">{{ $t('Quantity') }}</th>
                  <th class="text-right">{{ $t('Unit Price') }}</th>
                  <th class="text-right">{{ $t('Line Total') }}</th>
                  <th class="w-12"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in form.items" :key="index" class="align-top">
                  <td :data-label="$t('Item')" class="fx-item-td">
                    <div class="fx-cell">
                      <auto-complete
                        :id="'item-' + index"
                        :model-value="row.item_id"
                        @update:modelValue="value => selectItem(row, value)"
                        :suggestions="itemSuggestions"
                        :placeholder="$t('Select item')"
                        :error="$page.props.errors['items.' + index + '.item_name']"
                      />
                    </div>
                  </td>
                  <td :data-label="$t('Unit')">{{ row.unit_label || '-' }}</td>
                  <td :data-label="$t('Quantity')" class="text-right">
                    <text-input type="number" step="0.01" v-model="row.quantity" class="fx-num ml-auto" :error="$page.props.errors['items.' + index + '.quantity']" />
                  </td>
                  <td :data-label="$t('Unit Price')" class="text-right">
                    <text-input type="number" step="0.01" v-model="row.unit_price" class="fx-num ml-auto" :error="$page.props.errors['items.' + index + '.unit_price']" />
                  </td>
                  <td :data-label="$t('Line Total')" class="text-right font-semibold">{{ $money(lineTotal(row), form.currency) }}</td>
                  <td :data-label="$t('Remove')" class="text-right">
                    <button type="button" v-if="form.items.length > 1" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" @click="removeRow(index)">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 flex flex-col-reverse gap-4 md:flex-row md:items-start md:justify-between">
            <button type="button" class="lg-btn lg-btn-soft self-start" @click="addRow">
              <icons name="plus" class="w-4 h-4" /> {{ $t('Add Item') }}
            </button>
            <dl class="fx-totals">
              <div class="fx-totals-row">
                <dt>{{ $t('Subtotal') }}</dt>
                <dd>{{ $money(subtotal, form.currency) }}</dd>
              </div>
              <div class="fx-totals-row">
                <dt>{{ form.tax_included ? `${$t('Tax')} (${form.tax_percentage}%)` : $t('Tax: Not Applied') }}</dt>
                <dd>{{ $money(taxAmount, form.currency) }}</dd>
              </div>
              <div class="fx-totals-row fx-totals-grand">
                <dt>{{ $t('Total') }}</dt>
                <dd>{{ $money(total, form.currency) }}</dd>
              </div>
            </dl>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="upload" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Quotations, signed copies, supporting documents: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
            </div>
          </header>
          <div>
            <button type="button" class="lg-btn lg-btn-soft" @click.prevent="$refs.attachmentFile?.click()">
              <icons name="upload" class="w-4 h-4" /> {{ selectedFiles.length ? $t('Add more files') : $t('Choose files') }}
            </button>
            <input ref="attachmentFile" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="sr-only" @change="onFilesChosen" />
            <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
            <ul v-if="selectedFiles.length" class="fx-files fx-files-list mt-3">
              <li v-for="(file, index) in selectedFiles" :key="index">
                <span class="truncate">{{ file.name }}</span>
                <button type="button" class="fx-rm" @click="removeFile(index)">{{ $t('Remove') }}</button>
              </li>
            </ul>
            <ul v-if="purchaseOrder && purchaseOrder.attachments && purchaseOrder.attachments.length" class="fx-files fx-files-list mt-3">
              <li v-for="file in purchaseOrder.attachments" :key="file.id">
                <span class="truncate">{{ file.name }}</span>
                <a :href="route('supplier-records.purchase-orders.attachments.download', [purchaseOrder.id, file.id])" class="fx-dl">{{ $t('Download') }}</a>
              </li>
            </ul>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ form.items.length }} {{ $t('Items') }} &middot; {{ $money(total, form.currency) }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.purchase-orders.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ purchaseOrder ? $t('Save Changes') : $t('Create Purchase Order') }}
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
import CheckBox from '@/Shared/CheckBox.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, TextInput, DatePicker, TextareaInput, AutoComplete, CheckBox, PageHeader },

  props: {
    purchaseOrder: Object,
    suppliers: Array,
    warehouses: Array,
    items: Array,
    purchaseRequests: Array,
    currencies: Array,
    companyName: String,
  },

  data() {
    const po = this.purchaseOrder || {};
    return {
      selectedFiles: [],
      form: this.$inertia.form({
        supplier_id: po.supplier_id || null,
        scope: this.purchaseOrder ? (po.warehouse_id ? 'project' : 'company') : 'project',
        warehouse_id: po.warehouse_id || null,
        purchase_request_id: po.purchase_request_id || null,
        quotation_id: po.quotation_id || null,
        date_issued: po.date_issued || '',
        expected_delivery_date: po.expected_delivery_date || '',
        currency: po.currency || 'TZS',
        tax_included: po.tax_included !== undefined ? po.tax_included : true,
        tax_percentage: po.tax_percentage !== undefined ? po.tax_percentage : 18,
        terms: po.terms || '',
        remarks: po.remarks || '',
        items: po.items && po.items.length ? po.items.map(item => ({
          item_id: item.item_id,
          item_name: item.item_name,
          item_code: item.item_code,
          unit_label: item.unit_label,
          quantity: item.quantity,
          unit_price: item.unit_price,
        })) : [this.emptyRow()],
      }),
    };
  },

  computed: {
    supplierSuggestions() {
      return this.suppliers.map(s => ({ id: s.id, name: s.name }));
    },
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, name: w.name }));
    },
    purchaseRequestSuggestions() {
      return this.purchaseRequests.map(pr => ({ id: pr.id, label: pr.reference }));
    },
    itemSuggestions() {
      return this.items.map(i => ({ id: i.id, label: `${i.code ? i.code + ' - ' : ''}${i.name}`, name: i.name, code: i.code, unit_label: i.unit_label, price: i.price }));
    },
    currencySuggestions() {
      return this.currencies.map(c => ({ label: c.label, value: c.code }));
    },
    subtotal() {
      return this.form.items.reduce((sum, row) => sum + this.lineTotal(row), 0);
    },
    taxAmount() {
      if (!this.form.tax_included) {
        return 0;
      }
      return (this.subtotal * (parseFloat(this.form.tax_percentage) || 0)) / 100;
    },
    total() {
      return this.subtotal + this.taxAmount;
    },
  },

  methods: {
    emptyRow() {
      return { item_id: null, item_name: '', item_code: '', unit_label: '', quantity: 1, unit_price: 0 };
    },
    lineTotal(row) {
      return (parseFloat(row.quantity) || 0) * (parseFloat(row.unit_price) || 0);
    },
    setScope(scope) {
      this.form.scope = scope;
      if (scope === 'company') {
        this.form.warehouse_id = null;
      }
    },
    onSupplierChange(supplierId) {
      this.form.supplier_id = supplierId;
      // Tax rate defaults from the supplier's own settings (it varies by
      // country) — only auto-fill on create, so editing an existing order
      // never silently overwrites what was actually agreed.
      if (!this.purchaseOrder) {
        const supplier = this.suppliers.find(s => s.id === supplierId);
        if (supplier) {
          this.form.tax_percentage = supplier.tax_percentage;
          if (!this.form.currency || this.form.currency === 'TZS') {
            this.form.currency = supplier.currency || this.form.currency;
          }
        }
      }
    },
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
    selectItem(row, itemId) {
      row.item_id = itemId;
      const found = this.items.find(i => i.id === itemId);
      if (found) {
        row.item_name = found.name;
        row.item_code = found.code;
        row.unit_label = found.unit_label;
        row.unit_price = found.price;
      }
    },
    addRow() {
      this.form.items.push(this.emptyRow());
    },
    removeRow(index) {
      this.form.items.splice(index, 1);
    },
    submit() {
      this.form.transform(data => ({
        supplier_id: data.supplier_id,
        scope: data.scope,
        warehouse_id: data.scope === 'company' ? null : data.warehouse_id,
        purchase_request_id: data.purchase_request_id,
        quotation_id: data.quotation_id,
        date_issued: data.date_issued,
        expected_delivery_date: data.expected_delivery_date,
        currency: data.currency,
        tax_included: !!data.tax_included,
        tax_percentage: data.tax_included ? data.tax_percentage : null,
        terms: data.terms,
        remarks: data.remarks,
        items: data.items.map(row => ({
          item_id: row.item_id,
          item_name: row.item_name,
          item_code: row.item_code,
          unit_label: row.unit_label,
          quantity: row.quantity,
          unit_price: row.unit_price,
        })),
        attachments: this.selectedFiles,
      }));

      if (this.purchaseOrder) {
        this.form.put(route('supplier-records.purchase-orders.update', this.purchaseOrder.id));
      } else {
        this.form.post(route('supplier-records.purchase-orders.store'));
      }
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

.fx-cell { min-width: 0; text-align: left; }
.fx-cell :deep(.mb-2) { margin-bottom: 0; }
@media (min-width: 768px) {
  .fx-item-td { min-width: 18rem; }
}
@media (max-width: 767.98px) {
  .fx-items-table .fx-cell { flex: 1 1 auto; }
}

.fx-files { width: 100%; font-size: 0.8rem; color: var(--lg-ink); }
.fx-files li { display: flex; align-items: center; gap: 8px; min-width: 0; padding: 6px 2px; }
.fx-files-list { border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.fx-files-list li { justify-content: space-between; padding: 8px 12px; border-bottom: 1px solid var(--lg-line); }
.fx-files-list li:last-child { border-bottom: 0; }
.fx-dl { flex: none; font-weight: 700; color: var(--lg-navy); }
.fx-dl:hover { text-decoration: underline; }
.fx-rm { flex: none; font-weight: 700; color: #b91c1c; }
.fx-rm:hover { text-decoration: underline; }
.fx-hint { font-size: 0.74rem; color: var(--lg-mute); margin-top: 0.35rem; }
html.dark .fx-dl { color: #93c5fd; }
html.dark .fx-rm { color: #fca5a5; }

.fx-num { width: 8rem; max-width: 100%; }
@media (max-width: 767.98px) { .fx-num { width: 100%; } }

.fx-totals { width: 100%; max-width: 22rem; margin-left: auto; padding: 0.75rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.fx-totals-row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; font-size: 0.85rem; color: var(--lg-mute); }
.fx-totals-row dd { font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; text-align: right; }
.fx-totals-grand { margin-top: 4px; padding-top: 8px; border-top: 1px solid var(--lg-line); font-size: 1rem; font-weight: 800; color: var(--lg-ink); }
.fx-totals-grand dd { font-size: 1.05rem; }

.fx-scope-grid { display: grid; grid-template-columns: 1fr; gap: 0.75rem; }
@media (min-width: 640px) { .fx-scope-grid { grid-template-columns: 1fr 1fr; } }
.fx-scope-card {
  position: relative; display: flex; align-items: center; gap: 0.75rem; text-align: left; padding: 0.85rem 1rem;
  border-radius: 16px; border: 1.5px solid var(--lg-line); background: var(--lg-card);
  transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.fx-scope-card:hover { transform: translateY(-1px); border-color: rgba(16, 185, 129, 0.6); }
.fx-scope-card.is-active { border-color: #10b981; background: rgba(16, 185, 129, 0.08); box-shadow: 0 8px 20px -12px rgba(4, 120, 87, 0.5); }
.fx-scope-icon {
  flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: 0.8rem;
  background: var(--lg-soft); color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease;
}
.fx-scope-card.is-active .fx-scope-icon { background: linear-gradient(145deg, #34d399, #047857); color: #fff; box-shadow: 0 8px 16px -8px rgba(4, 120, 87, 0.8); }
.fx-scope-text { display: flex; flex-direction: column; min-width: 0; }
.fx-scope-title { font-size: 0.88rem; font-weight: 800; color: var(--lg-ink); }
.fx-scope-desc { font-size: 0.72rem; color: var(--lg-mute); margin-top: 1px; }
.fx-scope-check {
  position: absolute; top: 0.6rem; right: 0.6rem; display: inline-flex; align-items: center; justify-content: center;
  width: 1.15rem; height: 1.15rem; border-radius: 9999px; background: #059669; color: #fff;
  opacity: 0; transform: scale(0.6); transition: opacity 0.15s ease, transform 0.15s ease;
}
.fx-scope-card.is-active .fx-scope-check { opacity: 1; transform: scale(1); }
</style>
