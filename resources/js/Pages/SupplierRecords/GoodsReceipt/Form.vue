<template>
  <admin-layout :title="$t('Record Goods Receipt')">
    <div class="lg-page px-4 md:px-0 sd-form">
      <page-header
        icon="download"
        :title="editing ? $t('Edit Goods Receipt') : $t('Record Goods Receipt')"
        :subtitle="$t('Record what arrived against a purchase order.')"
      >
        <span v-if="editing && goodsReceipt" class="lg-chip lg-chip-green lg-mono" style="text-transform: none">{{ goodsReceipt.reference }}</span>
        <Link :href="route('supplier-records.goods-receipts.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Goods Receipts') }}
        </Link>
      </page-header>

      <!-- Trace -->
      <section v-if="trace" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="link" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Where This Comes From') }}</h2>
          </div>
        </header>
        <div class="sd-trace">
          <template v-if="trace.purchase_request">
            <Link :href="route(trace.purchase_request.route, trace.purchase_request.id)" class="sd-node">
              <span class="sd-node-kind">{{ $t('Purchase Request') }}</span>
              <span class="sd-node-ref">{{ trace.purchase_request.reference }}</span>
              <span class="sd-node-by">{{ $t('by') }} {{ trace.purchase_request.created_by_name || $t('Unknown') }}</span>
            </Link>
            <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          </template>
          <template v-if="trace.quotation">
            <Link :href="route(trace.quotation.route, trace.quotation.id)" class="sd-node">
              <span class="sd-node-kind">{{ $t('Quotation') }}</span>
              <span class="sd-node-ref">{{ trace.quotation.reference }}</span>
              <span class="sd-node-by">{{ $t('by') }} {{ trace.quotation.created_by_name || $t('Unknown') }}</span>
            </Link>
            <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          </template>
          <Link :href="route(trace.purchase_order.route, trace.purchase_order.id)" class="sd-node is-active">
            <span class="sd-node-kind">{{ $t('Purchase Order') }}</span>
            <span class="sd-node-ref">{{ trace.purchase_order.reference }}</span>
            <span class="sd-node-by">{{ $t('by') }} {{ trace.purchase_order.created_by_name || $t('Unknown') }}</span>
          </Link>
          <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          <div class="sd-node is-current">
            <span class="sd-node-kind">{{ $t('Goods Receipt') }}</span>
            <span class="sd-node-ref">{{ editing ? goodsReceipt.reference : $t('New') }}</span>
          </div>
        </div>
      </section>

      <form @submit.prevent="submit">
        <!-- Details -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="truck" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Purchase Order') }} / {{ $t('Date Received') }} / {{ $t('Remarks') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <auto-complete
                v-if="!editing"
                id="purchase-order"
                :label="$t('Purchase Order')"
                :model-value="form.purchase_order_id"
                @update:modelValue="onPoChange"
                :suggestions="purchaseOrderSuggestions"
                :placeholder="$t('Select a purchase order')"
                :error="$page.props.errors.purchase_order_id"
              />
              <div v-else>
                <label class="sd-label">{{ $t('Purchase Order') }}</label>
                <p class="sd-static">{{ purchaseOrder ? purchaseOrder.reference : '-' }}</p>
              </div>
            </div>
            <div>
              <date-picker :label="$t('Date Received')" v-model="form.date_received" :error="$page.props.errors.date_received" required />
            </div>
            <div class="lg-span-all">
              <textarea-input :label="$t('Remarks')" v-model="form.remarks" :error="$page.props.errors.remarks" />
            </div>
          </div>
        </section>

        <!-- Items -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Items to Receive') }}</h2>
              <p v-if="purchaseOrder" class="lg-card-sub">{{ form.items.length }} {{ $t('Items') }}</p>
            </div>
            <button v-if="purchaseOrder" type="button" class="lg-btn sd-btn-cyan" @click="addRow">
              <icons name="plus" class="w-4 h-4" />{{ $t('Add Item') }}
            </button>
          </header>

          <template v-if="purchaseOrder">
            <div v-if="$page.props.errors.items" class="text-red-600 text-sm mb-2">{{ $page.props.errors.items }}</div>
            <div class="lg-table-wrap">
              <table class="lg-table lg-responsive sd-items-table">
                <thead>
                  <tr>
                    <th>{{ $t('Item') }}</th>
                    <th>{{ $t('Unit') }}</th>
                    <th class="num">{{ $t('Ordered Qty') }}</th>
                    <th class="num">{{ $t('Received Qty') }}</th>
                    <th>{{ $t('Source') }}</th>
                    <th class="w-12"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, index) in form.items" :key="index" class="align-top">
                    <td :data-label="$t('Item')" class="sd-item-td">
                      <div class="sd-cell">
                        <span v-if="isFromPo(row)" class="lg-main">{{ row.item_code ? row.item_code + ' - ' : '' }}{{ row.item_name }}</span>
                        <auto-complete
                          v-else
                          :id="'gr-item-' + index"
                          :model-value="row.item_id"
                          @update:modelValue="value => selectItem(row, value)"
                          :suggestions="itemSuggestions"
                          :placeholder="$t('Select item')"
                          :error="$page.props.errors['items.' + index + '.item_name']"
                        />
                      </div>
                    </td>
                    <td :data-label="$t('Unit')">{{ row.unit_label || '-' }}</td>
                    <td :data-label="$t('Ordered Qty')" class="num">{{ isFromPo(row) ? $number(row.ordered_quantity) : '-' }}</td>
                    <td :data-label="$t('Received Qty')" class="num">
                      <text-input
                        type="number"
                        step="0.01"
                        v-model="row.received_quantity"
                        class="sd-num ml-auto"
                        :error="$page.props.errors['items.' + index + '.received_quantity']"
                      />
                    </td>
                    <td :data-label="$t('Source')">
                      <span v-if="isFromPo(row)" class="lg-chip" :class="poRowVarianceClass(row)" style="text-transform: none">{{ $t('From PO') }}</span>
                      <span v-else class="lg-chip lg-chip-violet" style="text-transform: none">{{ $t('Extra / Not on PO') }}</span>
                    </td>
                    <td :data-label="$t('Remove')" class="text-right">
                      <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeRow(index)">
                        <icons name="trash" class="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                  <tr v-if="!form.items.length" class="lg-empty-row">
                    <td class="lg-empty" colspan="6">{{ $t('No items on this receipt yet.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <p v-else class="lg-note lg-note-blue">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Select a purchase order above to load its items for receiving.') }}</span>
          </p>
        </section>

        <!-- Attachments -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="upload" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Delivery notes, waybills, photos: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
            </div>
          </header>
          <div>
            <button type="button" class="lg-btn lg-btn-soft" @click.prevent="$refs.attachmentFile?.click()">
              <icons name="upload" class="w-4 h-4" />{{ selectedFiles.length ? $t('Add more files') : $t('Choose files') }}
            </button>
            <input ref="attachmentFile" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="sr-only" @change="onFilesChosen" />
            <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
            <ul v-if="selectedFiles.length" class="sd-files mt-3">
              <li v-for="(file, index) in selectedFiles" :key="index">
                <span class="sd-file-name">{{ file.name }}</span>
                <button type="button" class="sd-rm" @click="removeFile(index)">{{ $t('Remove') }}</button>
              </li>
            </ul>
            <ul v-if="editing && goodsReceipt.attachments && goodsReceipt.attachments.length" class="sd-files mt-3">
              <li v-for="file in goodsReceipt.attachments" :key="file.id">
                <span class="sd-file-name">{{ file.name }}</span>
                <a :href="route('supplier-records.goods-receipts.attachments.download', [goodsReceipt.id, file.id])" class="sd-dl">{{ $t('Download') }}</a>
              </li>
            </ul>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ form.items.length }} {{ $t('Items') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.goods-receipts.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing || !purchaseOrder" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ editing ? $t('Save Changes') : $t('Record Goods Receipt') }}
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
    goodsReceipt: Object,
    purchaseOrder: Object,
    purchaseOrders: Array,
    trace: Object,
    // Not currently sent by GoodsReceiptController@create — defaulted to an
    // empty array so the "Add Item" picker degrades to "no suggestions"
    // instead of throwing, rather than crashing the page.
    items: { type: Array, default: () => [] },
  },

  data() {
    const editing = !!this.goodsReceipt;
    const sourceItems = editing ? this.goodsReceipt.items : (this.purchaseOrder && this.purchaseOrder.items ? this.purchaseOrder.items : []);

    return {
      editing,
      selectedFiles: [],
      form: this.$inertia.form({
        purchase_order_id: this.purchaseOrder ? this.purchaseOrder.id : null,
        date_received: editing ? this.goodsReceipt.date_received : '',
        remarks: editing ? this.goodsReceipt.remarks : '',
        items: sourceItems.map(item => editing ? {
          item_id: item.item_id,
          item_name: item.item_name,
          item_code: item.item_code,
          unit_label: item.unit_label,
          ordered_quantity: item.ordered_quantity,
          received_quantity: item.received_quantity,
        } : {
          item_id: item.item_id,
          item_name: item.item_name,
          item_code: item.item_code,
          unit_label: item.unit_label,
          ordered_quantity: item.quantity,
          received_quantity: item.quantity,
        }),
      }),
    };
  },

  computed: {
    purchaseOrderSuggestions() {
      return this.purchaseOrders.map(po => ({ id: po.id, label: `${po.reference} / ${po.supplier_name}` }));
    },
    itemSuggestions() {
      return this.items.map(i => ({ id: i.id, label: `${i.code ? i.code + ' - ' : ''}${i.name}`, name: i.name, code: i.code, unit_label: i.unit_label }));
    },
  },

  methods: {
    isFromPo(row) {
      return (parseFloat(row.ordered_quantity) || 0) > 0;
    },
    poRowVarianceClass(row) {
      const ordered = parseFloat(row.ordered_quantity) || 0;
      const received = parseFloat(row.received_quantity);
      if (isNaN(received) || received === ordered) {
        return 'lg-chip-green';
      }
      return received < ordered ? 'lg-chip-red' : 'lg-chip-amber';
    },
    selectItem(row, itemId) {
      row.item_id = itemId;
      const found = this.items.find(i => i.id === itemId);
      if (found) {
        row.item_name = found.name;
        row.item_code = found.code;
        row.unit_label = found.unit_label;
      }
    },
    addRow() {
      this.form.items.push({ item_id: null, item_name: '', item_code: '', unit_label: '', ordered_quantity: 0, received_quantity: 1 });
    },
    removeRow(index) {
      this.form.items.splice(index, 1);
    },
    onPoChange(newId) {
      if (newId === this.form.purchase_order_id) {
        return;
      }
      this.$inertia.visit(route('supplier-records.goods-receipts.create', { purchase_order_id: newId }));
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
    async submit() {
      if (!this.purchaseOrder) {
        await this.$alert(this.$t('Please select a purchase order first.'));
        return;
      }
      this.form
        .transform(data => ({
          purchase_order_id: data.purchase_order_id,
          date_received: data.date_received,
          remarks: data.remarks,
          items: data.items.map(row => ({
            item_id: row.item_id,
            item_name: row.item_name,
            item_code: row.item_code,
            unit_label: row.unit_label,
            ordered_quantity: row.ordered_quantity,
            received_quantity: row.received_quantity,
          })),
          attachments: this.selectedFiles,
        }))
        [this.editing ? 'patch' : 'post'](
          this.editing ? route('supplier-records.goods-receipts.update', this.goodsReceipt.id) : route('supplier-records.goods-receipts.store')
        );
    },
  },
};
</script>

<style scoped>
.sd-form :deep(label:not(:has(input[type='checkbox']))),
.sd-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.sd-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.sd-form :deep(select),
.sd-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.55rem !important;
  padding-bottom: 0.55rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sd-form :deep(input:disabled) { background: var(--lg-soft); }
.sd-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.sd-form :deep(select:hover) { border-color: rgba(16, 185, 129, 0.6) !important; }
.sd-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.sd-form :deep(select:focus),
.sd-form :deep(textarea:focus) { border-color: #10b981 !important; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18) !important; }

.sd-static { padding: 0.55rem 0; font-size: 0.92rem; font-weight: 800; color: var(--lg-ink); }
.sd-btn-cyan { color: #fff; background: linear-gradient(145deg, #22d3ee, #0e7490); box-shadow: 0 10px 18px -10px rgba(14, 116, 144, 0.8); }

.sd-trace { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.sd-node { display: flex; flex-direction: column; gap: 1px; min-width: 150px; padding: 9px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.8rem; color: var(--lg-ink); transition: transform 0.15s ease, border-color 0.15s ease; }
a.sd-node:hover { transform: translateY(-1px); border-color: #93c5fd; }
.sd-node.is-active { background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.45); }
.sd-node.is-current { background: rgba(236, 72, 153, 0.08); border: 1px dashed rgba(236, 72, 153, 0.55); }
.sd-node-kind { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sd-node-ref { font-weight: 800; }
.sd-node-by { font-size: 0.72rem; color: var(--lg-mute); }
.sd-arrow { color: var(--lg-faint); flex: none; }

.sd-cell { min-width: 0; text-align: left; }
.sd-cell :deep(.mb-2) { margin-bottom: 0; }
@media (min-width: 768px) { .sd-item-td { min-width: 18rem; } }
@media (max-width: 767.98px) { .sd-items-table .sd-cell { flex: 1 1 auto; } }
.sd-num { width: 8rem; max-width: 100%; }
@media (max-width: 767.98px) { .sd-num { width: 100%; } }

.sd-files { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-files li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--lg-line); font-size: 0.82rem; color: var(--lg-ink); }
.sd-files li:last-child { border-bottom: 0; }
.sd-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sd-dl { flex: none; font-weight: 800; color: var(--lg-navy); }
.sd-dl:hover { text-decoration: underline; }
.sd-rm { flex: none; font-weight: 800; color: #b91c1c; }
.sd-rm:hover { text-decoration: underline; }
html.dark .sd-dl { color: #93c5fd; }
html.dark .sd-rm { color: #fca5a5; }
</style>
