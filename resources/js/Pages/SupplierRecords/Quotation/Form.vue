<template>
  <admin-layout :title="quotation ? $t('Edit Quotation') : $t('Create Quotation')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="doc-text"
        :title="quotation ? $t('Edit Quotation') : $t('Create Quotation')"
        :subtitle="$t('Supplier quotations gathered against approved purchase requests.')"
      >
        <span v-if="quotation" class="lg-chip lg-chip-green lg-mono" style="text-transform: none">{{ quotation.reference }}</span>
        <Link :href="route('supplier-records.quotations.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Quotations') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Supplier') }} / {{ $t('Purchase Request (optional)') }} / {{ $t('Valid Until') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <auto-complete id="supplier" :label="$t('Supplier')" v-model="form.supplier_id" :suggestions="suppliers" :error="$page.props.errors.supplier_id" />
            </div>
            <div>
              <auto-complete
                id="purchase_request"
                :label="$t('Purchase Request (optional)')"
                v-model="form.purchase_request_id"
                :suggestions="purchaseRequestOptions"
                :error="$page.props.errors.purchase_request_id"
                clearable
              />
            </div>
            <div>
              <auto-complete id="warehouse" :label="$t('Warehouse / Project')" v-model="form.warehouse_id" :suggestions="warehouses" :error="$page.props.errors.warehouse_id" clearable />
            </div>
            <div>
              <date-picker :label="$t('Date')" v-model="form.date" :error="$page.props.errors.date" />
            </div>
            <div>
              <date-picker :label="$t('Valid Until')" v-model="form.valid_until" :error="$page.props.errors.valid_until" />
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
              <h2 class="lg-card-title">{{ $t('Items') }}</h2>
              <p class="lg-card-sub">{{ $t('Add at least one item') }}</p>
            </div>
            <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ form.items.length }} {{ $t('Items') }}</span>
          </header>

          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive fx-items-table">
              <thead>
                <tr>
                  <th class="w-12"></th>
                  <th>{{ $t('Item') }}</th>
                  <th class="w-40">{{ $t('Unit') }}</th>
                  <th class="w-40">{{ $t('Quantity') }}</th>
                  <th class="w-48">{{ $t('Unit Price') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(item, index) in form.items" :key="index">
                  <td :data-label="$t('Remove')">
                    <button type="button" @click="removeItem(index)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                  <td :data-label="$t('Item')" class="fx-item-td">
                    <div class="fx-cell">
                      <auto-complete
                        :id="`qt-item-${index}`"
                        :suggestions="itemSuggestions"
                        :placeholder="$t('Search item')"
                        v-model="item.item_id"
                        @update:modelValue="onItemChange(index, $event)"
                        clearable
                      />
                      <div v-if="$page.props.errors['items.' + index + '.item_name']" class="text-red-600 text-xs mt-1 wims-field-error">
                        {{ $page.props.errors['items.' + index + '.item_name'] }}
                      </div>
                    </div>
                  </td>
                  <td :data-label="$t('Unit')">
                    <div class="fx-cell">
                      <text-input :model-value="item.unit_label || '-'" disabled class="w-full" />
                    </div>
                  </td>
                  <td :data-label="$t('Quantity')">
                    <div class="fx-cell">
                      <text-input type="number" step="0.01" v-model="item.quantity" />
                      <div v-if="$page.props.errors['items.' + index + '.quantity']" class="text-red-600 text-xs mt-1 wims-field-error">
                        {{ $page.props.errors['items.' + index + '.quantity'] }}
                      </div>
                    </div>
                  </td>
                  <td :data-label="$t('Unit Price')">
                    <div class="fx-cell">
                      <text-input type="number" step="0.01" v-model="item.unit_price" />
                      <div v-if="$page.props.errors['items.' + index + '.unit_price']" class="text-red-600 text-xs mt-1 wims-field-error">
                        {{ $page.props.errors['items.' + index + '.unit_price'] }}
                      </div>
                    </div>
                  </td>
                </tr>
                <tr v-if="form.items.length === 0" class="lg-empty-row">
                  <td class="lg-empty" colspan="5">{{ $t('Add at least one item') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <button type="button" @click="addItem" class="lg-btn lg-btn-soft">
              <icons name="plus" class="w-4 h-4" />
              {{ $t('Add Item') }}
            </button>
            <div class="fx-total">{{ $t('Estimated Total') }}: <strong>{{ $money(estimatedTotal) }}</strong></div>
          </div>
          <div v-if="$page.props.errors.items" class="text-red-600 text-sm mt-2">{{ $page.props.errors.items }}</div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ form.items.length }} {{ $t('Items') }} &middot; {{ $money(estimatedTotal) }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.quotations.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ quotation ? $t('Save Changes') : $t('Create Quotation') }}
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
    quotation: Object,
    suppliers: Array,
    warehouses: Array,
    items: Array,
    purchaseRequests: Array,
    prefillPurchaseRequestId: [String, Number],
  },

  data() {
    const q = this.quotation || {};
    return {
      form: this.$inertia.form({
        purchase_request_id: q.purchase_request_id || (this.prefillPurchaseRequestId ? Number(this.prefillPurchaseRequestId) : null),
        supplier_id: q.supplier_id || null,
        warehouse_id: q.warehouse_id || null,
        date: q.date || new Date().toISOString().slice(0, 10),
        valid_until: q.valid_until || '',
        remarks: q.remarks || '',
        items:
          q.items && q.items.length
            ? q.items.map(i => ({
                item_id: i.item_id || null,
                item_name: i.item_name || '',
                item_code: i.item_code || '',
                unit_label: i.unit_label || '',
                quantity: i.quantity || null,
                unit_price: i.unit_price || null,
              }))
            : [this.blankItem()],
      }),
    };
  },

  computed: {
    itemSuggestions() {
      return this.items.map(item => ({
        id: item.id,
        label: `${item.code} - ${item.name}`,
        code: item.code,
        name: item.name,
        unit_label: item.unit_label,
        price: item.price,
      }));
    },
    purchaseRequestOptions() {
      return this.purchaseRequests.map(pr => ({ id: pr.id, label: pr.reference }));
    },
    estimatedTotal() {
      return this.form.items.reduce((sum, i) => sum + (parseFloat(i.quantity) || 0) * (parseFloat(i.unit_price) || 0), 0);
    },
  },

  methods: {
    blankItem() {
      return { item_id: null, item_name: '', item_code: '', unit_label: '', quantity: null, unit_price: null };
    },
    addItem() {
      this.form.items.push(this.blankItem());
    },
    removeItem(index) {
      this.form.items.splice(index, 1);
      if (!this.form.items.length) {
        this.addItem();
      }
    },
    onItemChange(index, value = null) {
      if (value !== null && value !== undefined) {
        this.form.items[index].item_id = value;
      }

      const selected = this.items.find(i => i.id == this.form.items[index].item_id);
      this.form.items[index].item_name = selected ? selected.name : '';
      this.form.items[index].item_code = selected ? selected.code : '';
      this.form.items[index].unit_label = selected ? selected.unit_label : '';
      if (selected && !this.form.items[index].unit_price) {
        this.form.items[index].unit_price = selected.price;
      }
    },
    submit() {
      if (this.quotation) {
        this.form.put(route('supplier-records.quotations.update', this.quotation.id));
      } else {
        this.form.post(route('supplier-records.quotations.store'));
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
.fx-total { font-size: 0.85rem; color: var(--lg-mute); }
.fx-total strong { color: var(--lg-ink); font-size: 1rem; font-variant-numeric: tabular-nums; }
</style>
