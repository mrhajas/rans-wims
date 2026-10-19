<template>
  <admin-layout :title="purchaseRequest ? $t('Edit Purchase Request') : $t('Create Purchase Request')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="doc-text"
        :title="purchaseRequest ? $t('Edit Purchase Request') : $t('Create Purchase Request')"
        :subtitle="$t('Internal requests for materials, routed for approval before quotations are sought.')"
      >
        <span v-if="purchaseRequest" class="lg-chip lg-chip-green lg-mono" style="text-transform: none">{{ purchaseRequest.reference }}</span>
        <Link :href="route('supplier-records.purchase-requests.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Purchase Requests') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Warehouse / Project') }} / {{ $t('Date Requested') }} / {{ $t('Reason') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <auto-complete id="warehouse" :label="$t('Warehouse / Project')" v-model="form.warehouse_id" :suggestions="warehouses" :error="$page.props.errors.warehouse_id" />
            </div>
            <div>
              <date-picker :label="$t('Date Requested')" v-model="form.date_requested" :error="$page.props.errors.date_requested" />
            </div>
            <div class="lg-span-all">
              <textarea-input :label="$t('Reason')" v-model="form.reason" :error="$page.props.errors.reason" />
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
                  <th class="w-40">{{ $t('Quantity') }}</th>
                  <th class="w-40">{{ $t('Unit') }}</th>
                  <th>{{ $t('Remarks') }}</th>
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
                        :id="`pr-item-${index}`"
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
                  <td :data-label="$t('Quantity')">
                    <div class="fx-cell">
                      <text-input type="number" step="0.01" v-model="item.quantity" />
                      <div v-if="$page.props.errors['items.' + index + '.quantity']" class="text-red-600 text-xs mt-1 wims-field-error">
                        {{ $page.props.errors['items.' + index + '.quantity'] }}
                      </div>
                    </div>
                  </td>
                  <td :data-label="$t('Unit')">
                    <div class="fx-cell">
                      <text-input :model-value="item.unit_label || '-'" disabled class="w-full" />
                    </div>
                  </td>
                  <td :data-label="$t('Remarks')">
                    <div class="fx-cell">
                      <text-input v-model="item.remarks" />
                    </div>
                  </td>
                </tr>
                <tr v-if="form.items.length === 0" class="lg-empty-row">
                  <td class="lg-empty" colspan="5">{{ $t('Add at least one item') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4">
            <button type="button" @click="addItem" class="lg-btn lg-btn-soft">
              <icons name="plus" class="w-4 h-4" />
              {{ $t('Add Item') }}
            </button>
            <div v-if="$page.props.errors.items" class="text-red-600 text-sm mt-2">{{ $page.props.errors.items }}</div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="upload" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Site photos, quotes, supporting documents: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
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
            <ul v-if="purchaseRequest && purchaseRequest.attachments && purchaseRequest.attachments.length" class="fx-files fx-files-list mt-3">
              <li v-for="file in purchaseRequest.attachments" :key="file.id">
                <span class="truncate">{{ file.name }}</span>
                <a :href="route('supplier-records.purchase-requests.attachments.download', [purchaseRequest.id, file.id])" class="fx-dl">{{ $t('Download') }}</a>
              </li>
            </ul>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ form.items.length }} {{ $t('Items') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('supplier-records.purchase-requests.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ purchaseRequest ? $t('Save Changes') : $t('Create Purchase Request') }}
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
    purchaseRequest: Object,
    warehouses: Array,
    items: Array,
  },

  data() {
    const pr = this.purchaseRequest || {};
    return {
      selectedFiles: [],
      form: this.$inertia.form({
        warehouse_id: pr.warehouse_id || null,
        date_requested: pr.date_requested || new Date().toISOString().slice(0, 10),
        reason: pr.reason || '',
        items:
          pr.items && pr.items.length
            ? pr.items.map(i => ({
                item_id: i.item_id || null,
                item_name: i.item_name || '',
                item_code: i.item_code || '',
                unit_label: i.unit_label || '',
                quantity: i.quantity || null,
                remarks: i.remarks || '',
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
      }));
    },
  },

  methods: {
    blankItem() {
      return { item_id: null, item_name: '', item_code: '', unit_label: '', quantity: null, remarks: '' };
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
    submit() {
      this.form.transform(data => ({ ...data, attachments: this.selectedFiles }));

      if (this.purchaseRequest) {
        this.form.put(route('supplier-records.purchase-requests.update', this.purchaseRequest.id));
      } else {
        this.form.post(route('supplier-records.purchase-requests.store'));
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
</style>
