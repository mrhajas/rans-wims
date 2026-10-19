<template>
  <admin-layout :title="$t(edit ? 'Edit Material Reallocation' : 'Create Material Reallocation')">
    <div class="lg-page px-4 md:px-0 mrf-form">
      <page-header icon="truck" :title="edit ? edit.reference : $t('Create')" :subtitle="$t('List the excess or unused materials to be returned from this site.')">
        <Link :href="route('material-reallocations.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Material Reallocation') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card relative z-40">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Request Overview') }}</h2></div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Reference')" :model-value="requestNumber" disabled />
            <date-picker :label="$t('Date Requested')" v-model="form.date_requested" :error="$page.props.errors.date_requested" />
            <text-input :label="$t('Requested By')" :model-value="requestedBy?.name" disabled />
            <div>
              <auto-complete
                id="source-warehouse"
                :label="$t('Source Warehouse / Site')"
                :suggestions="warehouses"
                v-model="form.source_warehouse_id"
                :error="$page.props.errors.source_warehouse_id"
              />
              <p class="mrf-hint">
                {{ $t('You can only request items and quantities that are currently in stock at this warehouse.') }}
              </p>
            </div>
          </div>
        </section>

        <section class="lg-card relative z-30">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Items to Return') }}</h2>
              <p class="lg-card-sub">{{ form.items.length }} {{ $t('line(s)') }}</p>
            </div>
            <button type="button" @click="addItem" class="lg-btn lg-btn-soft ml-auto">
              <icons name="plus" class="w-4 h-4" />{{ $t('Add Item') }}
            </button>
          </header>

          <!-- Desktop/tablet: table layout -->
          <div class="hidden md:block mrf-item-table overflow-visible">
            <table class="w-full whitespace-nowrap">
              <thead>
                <tr class="text-left">
                  <th class="px-4 py-3 w-12"></th>
                  <th class="px-4 py-3">{{ $t('Item') }}</th>
                  <th class="px-4 py-3 w-40">{{ $t('Quantity') }}</th>
                  <th class="px-4 py-3 w-40">{{ $t('Unit') }}</th>
                  <th class="px-4 py-3">{{ $t('Remarks') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(item, index) in form.items" :key="index">
                  <td class="px-4 py-3">
                    <button type="button" @click="removeItem(index)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')" :aria-label="$t('Remove')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                  <td class="px-4 py-3 min-w-72 relative">
                    <auto-complete
                      :id="`mrr-item-${index}`"
                      :suggestions="itemSuggestions"
                      :placeholder="$t('Search item')"
                      v-model="item.item_id"
                      @update:modelValue="onItemChange(index, $event)"
                      clearable
                    />
                    <div v-if="$page.props.errors['items.' + index + '.item_id']" class="mrf-err">
                      {{ $page.props.errors['items.' + index + '.item_id'] }}
                    </div>
                    <div v-if="item.item_id" class="mrf-avail" :class="availabilityFor(item).class">
                      {{ availabilityFor(item).message }}
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <text-input type="number" step="0.01" v-model="item.requested_quantity" />
                    <div v-if="$page.props.errors['items.' + index + '.requested_quantity']" class="mrf-err">
                      {{ $page.props.errors['items.' + index + '.requested_quantity'] }}
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <input type="hidden" v-model="item.unit_id" />
                    <text-input :model-value="unitName(item.unit_id) || '-'" disabled class="w-full" />
                  </td>
                  <td class="px-4 py-3">
                    <text-input v-model="item.remarks" />
                  </td>
                </tr>
                <tr v-if="form.items.length === 0">
                  <td class="px-4 py-6 mrf-empty" colspan="5">{{ $t('Add at least one item') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Mobile: stacked cards -->
          <div class="md:hidden space-y-4 mrf-item-cards">
            <div v-for="(item, index) in form.items" :key="'mobile-' + index" class="mrf-item-card relative">
              <button type="button" @click="removeItem(index)" class="lg-icon-btn is-red absolute top-3 right-3" :aria-label="$t('Remove')">
                <icons name="trash" class="w-4 h-4" />
              </button>

              <div class="pr-10">
                <label class="mrf-label">{{ $t('Item') }}</label>
                <auto-complete
                  :id="`mrr-item-mobile-${index}`"
                  :suggestions="itemSuggestions"
                  :placeholder="$t('Search item')"
                  v-model="item.item_id"
                  @update:modelValue="onItemChange(index, $event)"
                  clearable
                />
                <div v-if="$page.props.errors['items.' + index + '.item_id']" class="mrf-err">
                  {{ $page.props.errors['items.' + index + '.item_id'] }}
                </div>
                <div v-if="item.item_id" class="mrf-avail" :class="availabilityFor(item).class">
                  {{ availabilityFor(item).message }}
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3 mt-3">
                <div>
                  <label class="mrf-label">{{ $t('Quantity') }}</label>
                  <text-input type="number" step="0.01" v-model="item.requested_quantity" />
                  <div v-if="$page.props.errors['items.' + index + '.requested_quantity']" class="mrf-err">
                    {{ $page.props.errors['items.' + index + '.requested_quantity'] }}
                  </div>
                </div>
                <div>
                  <label class="mrf-label">{{ $t('Unit') }}</label>
                  <text-input :model-value="unitName(item.unit_id) || '-'" disabled />
                </div>
              </div>

              <div class="mt-3">
                <label class="mrf-label">{{ $t('Remarks') }}</label>
                <text-input v-model="item.remarks" />
              </div>
            </div>

            <div v-if="form.items.length === 0" class="mrf-empty mrf-empty-box">
              {{ $t('Add at least one item') }}
            </div>
          </div>

          <div v-if="$page.props.errors.items" class="mrf-err mt-2">{{ $page.props.errors.items }}</div>
        </section>

        <section class="lg-card relative z-20">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Reason & Remarks') }}</h2></div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all">
              <auto-complete
                id="reason"
                :label="$t('Reason for Allocation')"
                :suggestions="reasons"
                v-model="form.reason"
                :error="$page.props.errors.reason"
              />
              <p class="mrf-hint">
                {{ $t('Pick the closest reason this material is being returned. Choose "Other (Requires Explanation)" only if none of the listed reasons apply.') }}
              </p>
            </div>
            <div v-if="form.reason === otherReason" class="lg-span-all">
              <textarea-input
                v-model="form.reason_details"
                :error="$page.props.errors.reason_details"
                :label="$t('Please explain')"
              />
            </div>
            <div class="lg-span-all">
              <textarea-input v-model="form.remarks" :error="$page.props.errors.remarks" :label="$t('Remarks')" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2></div>
          </header>

          <div v-if="edit && edit.attachments && edit.attachments.other && edit.attachments.other.length" class="mb-3">
            <p class="mrf-hint mb-1.5">{{ $t('Already uploaded') }}</p>
            <ul class="mrf-file-list">
              <li v-for="attachment in edit.attachments.other" :key="attachment.id" class="mrf-file-row">
                <span class="truncate flex items-center gap-2"><icons name="doc" class="w-4 h-4 flex-none mrf-faint" />{{ attachment.title }}</span>
                <div class="flex items-center gap-1.5 shrink-0">
                  <a class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')" :href="route('media.download', attachment.id)"><icons name="download" class="w-4 h-4" /></a>
                  <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="deleteAttachment(attachment.id)"><icons name="trash" class="w-4 h-4" /></button>
                </div>
              </li>
            </ul>
          </div>

          <div class="flex flex-col gap-2 items-start">
            <button type="button" class="lg-btn lg-btn-soft" @click.prevent="$refs.file?.click()">
              <icons name="upload" class="w-4 h-4" />
              {{ selectedFiles.length ? $t('Add more files') : $t('Choose files') }}
            </button>
            <input
              ref="file"
              id="material-reallocation-attachments"
              type="file"
              multiple
              accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
              class="sr-only"
              @change="updateFile"
            />
            <p class="mrf-hint">
              {{ $t('You can select PDF, DOC, DOCX, JPG, JPEG, or PNG files. Pick files in as many rounds as you need. Everything listed below is uploaded together when you save, and you can remove any of them first.') }}
            </p>
          </div>

          <div v-if="$page.props.errors.attachments" class="mrf-err">{{ $page.props.errors.attachments }}</div>

          <div v-if="selectedFiles.length" class="mt-3">
            <p class="mrf-hint mb-1.5">{{ $t('Selected files (not yet uploaded)') }}</p>
            <ul class="mrf-file-list">
              <li v-for="(file, index) in selectedFiles" :key="index" class="mrf-file-row">
                <span class="truncate flex items-center gap-2"><icons name="doc" class="w-4 h-4 flex-none mrf-faint" />{{ file.name }}</span>
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeSelectedFile(index)"><icons name="trash" class="w-4 h-4" /></button>
              </li>
            </ul>
          </div>
        </section>

        <div class="lg-actionbar">
          <div></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('material-reallocations.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save Request') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import pick from 'lodash/pick';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: {
    AdminLayout,
    TextareaInput,
    TextInput,
    DatePicker,
    AutoComplete,
    LoadingButton,
    PageHeader,
  },

  props: {
    edit: Object,
    items: Array,
    units: Array,
    warehouses: Array,
    reasons: Array,
    requestNumber: String,
    requestedBy: Object,
    dateRequested: String,
    stockByWarehouse: { type: Object, default: () => ({}) },
  },

  data() {
    return {
      otherReason: 'Other (Requires Explanation)',
      selectedFiles: [],
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        source_warehouse_id: this.edit ? this.edit.source_warehouse_id : null,
        date_requested: this.edit ? this.edit.date_requested : this.dateRequested,
        reason: this.edit ? this.edit.reason : '',
        reason_details: this.edit ? this.edit.reason_details : '',
        remarks: this.edit ? this.edit.remarks : '',
        attachments: null,
        items: this.edit
          ? this.edit.items.map(i => ({
              item_id: i.item_id,
              requested_quantity: i.requested_quantity,
              unit_id: i.unit_id,
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
      }));
    },
  },

  methods: {
    formatQty(value) {
      const num = Number(value) || 0;
      return num.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    },
    availabilityFor(item) {
      const warehouseStock = this.stockByWarehouse[this.form.source_warehouse_id] || {};
      const available = parseFloat(warehouseStock[item.item_id] ?? 0);
      const requested = parseFloat(item.requested_quantity) || 0;

      if (available <= 0) {
        return { class: 'mrf-bad', message: this.$t('Not available in stock at this warehouse.') };
      }
      if (requested > available) {
        return { class: 'mrf-bad', message: `${this.$t('Not enough stock')}: ${this.$t('only')} ${this.formatQty(available)} ${this.$t('available at this warehouse')}.` };
      }
      return { class: 'mrf-good', message: `${this.$t('Available')}: ${this.formatQty(available)}` };
    },
    blankItem() {
      return { item_id: null, requested_quantity: null, unit_id: null, remarks: '' };
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
      this.form.items[index].unit_id = selected ? selected.unit_id : null;
    },
    unitName(unitId) {
      const selected = this.units.find(u => u.id == unitId);
      return selected ? selected.name || selected.code : '';
    },
    updateFile(e) {
      const newFiles = Array.from(e.target.files || []);
      this.selectedFiles.push(...newFiles);
      // Reset the input so the browser fires @change again next time, even for
      // files with the same name — otherwise a repeated pick of the same file
      // silently does nothing since the input's value hasn't changed.
      e.target.value = '';
    },
    removeSelectedFile(index) {
      this.selectedFiles.splice(index, 1);
    },
    async deleteAttachment(id) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.form({ _method: 'DELETE' }).delete(route('media.delete', id));
    },
    submit() {
      if (this.selectedFiles.length) {
        this.form.attachments = this.selectedFiles;
      }

      const payload = pick(this.form, ['source_warehouse_id', 'date_requested', 'reason', 'reason_details', 'remarks', 'attachments', 'items', '_method']);
      this.form.transform(() => payload);

      if (this.edit) {
        this.form.post(route('material-reallocations.update', this.edit.id));
      } else {
        this.form.post(route('material-reallocations.store'));
      }
    },
  },
};
</script>

<style scoped>
.mrf-form :deep(.lg-fields label:not(:has(input[type='checkbox']))), .mrf-label {
  display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute);
}
.mrf-label { margin-bottom: 0.25rem; }
.mrf-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.mrf-form :deep(select),
.mrf-form :deep(textarea) {
  border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.mrf-form :deep(input:disabled) { background: var(--lg-soft); color: var(--lg-mute); }
.mrf-form :deep(input:focus), .mrf-form :deep(select:focus), .mrf-form :deep(textarea:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important; }

.mrf-hint { margin-top: 0.35rem; font-size: 0.76rem; color: var(--lg-mute); }
.mrf-err { margin-top: 0.3rem; font-size: 0.78rem; color: #be123c; }
html.dark .mrf-err { color: #fda4af; }
.mrf-avail { margin-top: 0.3rem; font-size: 0.76rem; font-weight: 700; }
.mrf-good { color: #047857; } .mrf-bad { color: #be123c; }
html.dark .mrf-good { color: #6ee7b7; } html.dark .mrf-bad { color: #fda4af; }
.mrf-faint { color: var(--lg-faint); }
.mrf-empty { text-align: center; color: var(--lg-faint); font-size: 0.85rem; }
.mrf-empty-box { border: 1px dashed var(--lg-line); border-radius: 16px; padding: 1rem; }

/* No overflow:hidden so an open item-search dropdown is never clipped by the table container. */
.mrf-item-table { border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-card); }
.mrf-item-table table { border-collapse: separate; border-spacing: 0; }
.mrf-item-table thead th { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); background: var(--lg-soft); border-bottom: 1px solid var(--lg-line); }
.mrf-item-table thead th:first-child { border-top-left-radius: 15px; }
.mrf-item-table thead th:last-child { border-top-right-radius: 15px; }
.mrf-item-table tbody td { border-bottom: 1px solid var(--lg-line); vertical-align: top; }
.mrf-item-table tbody tr:last-child td { border-bottom: 0; }
.mrf-item-table :deep(.mb-2) { margin-bottom: 0 !important; }
.mrf-item-cards { position: relative; z-index: 30; }
.mrf-item-card { border: 1px solid var(--lg-line); border-radius: 18px; padding: 1rem; background: var(--lg-soft); }

.mrf-file-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; background: var(--lg-soft); }
.mrf-file-row { padding: 0.45rem 0.7rem 0.45rem 0.9rem; font-size: 0.85rem; color: var(--lg-ink); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.mrf-file-row + .mrf-file-row { border-top: 1px solid var(--lg-line); }
</style>
