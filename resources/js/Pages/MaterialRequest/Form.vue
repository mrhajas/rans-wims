<template>
  <admin-layout :title="$t(edit ? 'Edit Material Request' : 'Create Material Request')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="clip"
        :title="$t(edit ? 'Edit Material Request' : 'Create Material Request')"
        :subtitle="$t('Fill in the details below to submit the material request.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-green" style="text-transform: none">{{ edit.reference }}</span>
        <Link :href="route('material-requests.index')" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /></span>{{ $t('Material Requests') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Request Number') }} / {{ $t('Date Requested') }} / {{ $t('Warehouse / Site') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <text-input :label="$t('Request Number')" :model-value="requestNumber" disabled />
            </div>
            <div>
              <date-picker :label="$t('Date Requested')" v-model="form.date_requested" :error="$page.props.errors.date_requested" />
            </div>
            <div>
              <text-input :label="$t('Requested By')" :model-value="requestedBy?.name" disabled />
            </div>
            <div>
              <auto-complete
                id="warehouse"
                :label="$t('Warehouse / Site')"
                :suggestions="warehouses"
                v-model="form.warehouse_id"
                :error="$page.props.errors.warehouse_id"
              />
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

          <div class="lg-table-wrap fx-mr-wrap">
            <table class="lg-table lg-responsive fx-mr-table">
              <thead>
                <tr>
                  <th class="w-12"></th>
                  <th>{{ $t('Item') }}</th>
                  <th class="w-40">{{ $t('Quantity') }}</th>
                  <th class="w-40">{{ $t('Unit') }}</th>
                  <th class="w-48">{{ $t('Priority') }}</th>
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
                        :id="`mr-item-${index}`"
                        :suggestions="itemSuggestions"
                        :placeholder="$t('Search item')"
                        position="bottom-100 mb-2"
                        v-model="item.item_id"
                        @update:modelValue="onItemChange(index, $event)"
                        clearable
                      />
                      <div v-if="$page.props.errors['items.' + index + '.item_id']" class="text-red-600 text-xs mt-1 wims-field-error">
                        {{ $page.props.errors['items.' + index + '.item_id'] }}
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
                      <input type="hidden" v-model="item.unit_id" />
                      <text-input :model-value="unitName(item.unit_id) || '-'" disabled class="w-full" />
                    </div>
                  </td>
                  <td :data-label="$t('Priority')">
                    <div class="fx-cell">
                      <select-input v-model="item.priority" class="w-full">
                        <option value="Low">{{ $t('Low') }}</option>
                        <option value="Normal">{{ $t('Normal') }}</option>
                        <option value="High">{{ $t('High') }}</option>
                        <option value="Emergency">{{ $t('Emergency') }}</option>
                      </select-input>
                    </div>
                  </td>
                  <td :data-label="$t('Remarks')">
                    <div class="fx-cell">
                      <text-input v-model="item.remarks" />
                    </div>
                  </td>
                </tr>
                <tr v-if="form.items.length === 0" class="lg-empty-row">
                  <td class="lg-empty" colspan="6">{{ $t('Add at least one item') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" @click="addItem" class="lg-btn tone" style="--tone: #059669">
              <span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>
              {{ $t('Add Item') }}
            </button>
            <button type="button" @click="$refs.cameraScanner.show()" class="lg-btn tone" style="--tone: #2563eb" :aria-label="$t('Scan barcode with camera')">
              <span class="tone-ico">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M4 7h3l2-2h6l2 2h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z" />
                  <circle cx="12" cy="13" r="4" />
                </svg>
              </span>
              {{ $t('Scan') }}
            </button>
          </div>
          <div v-if="$page.props.errors.items" class="text-red-600 text-sm mt-2">{{ $page.props.errors.items }}</div>
          <camera-barcode-scanner ref="cameraScanner" @resolved="scannedItem" />
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="upload" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes & Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('PDF, DOC, or DOCX files') }}</p>
            </div>
          </header>
          <div class="fx-notes">
            <div class="fx-attach">
              <label class="fx-label">{{ $t('Attachments') }}</label>
              <div class="flex flex-col gap-2 items-start">
                <button type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="filesLeft <= 0" @click.prevent="$refs.file?.click()">
                  <span class="tone-ico"><icons name="upload" class="w-4 h-4" /></span>
                  {{ $t('Choose files') }}
                </button>
                <input
                  ref="file"
                  id="material-request-attachments"
                  type="file"
                  multiple
                  accept=".pdf,.doc,.docx"
                  class="sr-only"
                  @change="onFiles"
                />
                <p class="fx-file-hint">{{ $t('Up to :n files, :mb MB each (PDF, DOC or DOCX).', { n: maxFiles, mb: maxMb }) }}</p>
                <ul v-if="existingFiles.length || files.length" class="fx-files fx-files-list">
                  <li v-for="attachment in existingFiles" :key="'e' + attachment.id">
                    <span class="fx-file-ico"><icons name="doc-text" class="w-4 h-4" /></span>
                    <span class="fx-file-name truncate">{{ attachment.title }}</span>
                    <a class="lg-icon-btn lg-tip tip-left" :data-tip="$t('Preview')" :aria-label="$t('Preview')" :href="route('media.download', { media: attachment.id, preview: 1 })" target="_blank" rel="noopener"><icons name="eye" class="w-4 h-4" /></a>
                    <button type="button" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeExisting(attachment)"><icons name="trash" class="w-4 h-4" /></button>
                  </li>
                  <li v-for="(file, fi) in files" :key="'n' + fi + file.name + file.size">
                    <span class="fx-file-ico is-new"><icons name="doc-text" class="w-4 h-4" /></span>
                    <span class="fx-file-name truncate">{{ file.name }}<small>{{ sizeText(file.size) }}</small></span>
                    <button type="button" class="lg-icon-btn lg-tip tip-left" :data-tip="$t('Preview')" :aria-label="$t('Preview')" @click="previewFile(file)"><icons name="eye" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="files.splice(fi, 1); fileProblems = []"><icons name="trash" class="w-4 h-4" /></button>
                  </li>
                </ul>
              </div>
              <div v-for="(msg, mi) in fileProblems" :key="'p' + mi" class="text-red-600 text-sm mt-1">{{ msg }}</div>
              <div v-for="msg in serverFileErrors" :key="msg" class="text-red-600 text-sm mt-1">{{ msg }}</div>
            </div>
            <div class="lg-fields fx-notes-fields">
              <div class="lg-span-all">
                <textarea-input v-model="form.reason" :error="$page.props.errors.reason" :label="$t('Reason for Request')" />
              </div>
              <div class="lg-span-all">
                <textarea-input v-model="form.comments" :error="$page.props.errors.comments" :label="$t('Comments & Notes')" />
              </div>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ form.items.length }} {{ $t('Items') }}</span>
          <div class="lg-actionbar-btns">
            <button type="submit" :disabled="form.processing" class="lg-btn tone tone-solid" style="--tone: #059669">
              <span class="tone-ico"><icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /></span>{{ $t('Submit Request') }}
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
import SelectInput from '@/Shared/SelectInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import CameraBarcodeScanner from '@/Shared/CameraBarcodeScanner.vue';

export default {
  components: {
    AdminLayout,
    TextareaInput,
    TextInput,
    DatePicker,
    AutoComplete,
    SelectInput,
    PageHeader,
    CameraBarcodeScanner,
  },

  props: {
    edit: Object,
    items: Array,
    units: Array,
    warehouses: Array,
    requestNumber: String,
    requestedBy: Object,
    dateRequested: String,
  },

  data() {
    return {
      // the files chosen so far (each one can be previewed or removed before saving), the ones already saved on this request,
      // and the reasons any chosen file was turned away
      files: [],
      existingFiles: this.edit && this.edit.attachments ? [...this.edit.attachments] : [],
      fileProblems: [],
      maxFiles: 5,
      maxMb: 5,
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        warehouse_id: this.edit ? this.edit.warehouse_id : null,
        date_requested: this.edit ? this.edit.date_requested : this.dateRequested,
        reason: this.edit ? this.edit.reason : '',
        comments: this.edit ? this.edit.comments : '',
        attachments: null,
        items: this.edit
          ? this.edit.items.map(i => ({
              item_id: i.item_id,
              quantity: i.quantity,
              unit_id: i.unit_id,
              priority: i.priority || 'Normal',
              remarks: i.remarks || '',
            }))
          : [this.blankItem()],
      }),
    };
  },

  computed: {
    filesLeft() {
      return this.maxFiles - this.existingFiles.length - this.files.length;
    },
    serverFileErrors() {
      const errors = this.$page.props.errors || {};
      return Object.keys(errors).filter(k => k === 'attachments' || k.startsWith('attachments.')).map(k => errors[k]);
    },
    itemSuggestions() {
      return this.items.map(item => ({
        id: item.id,
        label: `${item.code} - ${item.name}`,
      }));
    },
  },

  methods: {
    // adds the chosen files to the list: PDF / DOC / DOCX, 5 MB each, 5 in all (saved ones count), the same file twice is skipped
    onFiles(e) {
      const picked = Array.from((e.target && e.target.files) || []);
      const problems = [];
      picked.forEach(file => {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!['pdf', 'doc', 'docx'].includes(ext)) {
          problems.push(this.$t(':file is not a PDF, DOC or DOCX file.', { file: file.name }));
        } else if (file.size > this.maxMb * 1024 * 1024) {
          problems.push(this.$t(':file is larger than :mb MB (:size).', { file: file.name, mb: this.maxMb, size: this.sizeText(file.size) }));
        } else if (this.files.some(f => f.name === file.name && f.size === file.size)) {
          problems.push(this.$t(':file is already in the list.', { file: file.name }));
        } else if (this.filesLeft <= 0) {
          problems.push(this.$t(':file was not added: a request holds up to :n files.', { file: file.name, n: this.maxFiles }));
        } else {
          this.files.push(file);
        }
      });
      this.fileProblems = problems;
      // so choosing the same file again after removing it still fires
      if (e.target) e.target.value = '';
    },
    sizeText(bytes) {
      return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    },
    // opens the chosen file in a new tab straight from this computer (a PDF shows; a Word file is handed to the browser to download)
    previewFile(file) {
      const url = URL.createObjectURL(file);
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    },
    // a file already saved on this request: removed from the server at once (the rest of the form stays as typed)
    async removeExisting(attachment) {
      if (!(await this.$confirm(this.$t('Remove :file from this request?', { file: attachment.title })))) return;
      this.$inertia.delete(route('media.delete', attachment.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
          this.existingFiles = this.existingFiles.filter(a => a.id !== attachment.id);
        },
      });
    },
    blankItem() {
      return { item_id: null, quantity: null, unit_id: null, priority: 'Normal', remarks: '' };
    },
    addItem() {
      this.form.items.push(this.blankItem());
    },
    // an item found by the camera or by typing its code: the same item again adds one to its quantity, otherwise it takes the first empty line
    scannedItem(found) {
      const id = found && found.id;
      if (!id) return;
      const same = this.form.items.find(i => i.item_id == id);
      if (same) {
        same.quantity = (parseFloat(same.quantity) || 0) + 1;
        return;
      }
      let index = this.form.items.findIndex(i => !i.item_id);
      if (index === -1) {
        this.addItem();
        index = this.form.items.length - 1;
      }
      this.form.items[index].quantity = 1;
      this.onItemChange(index, id);
      if (!this.form.items[index].unit_id && found.unit_id) this.form.items[index].unit_id = found.unit_id;
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
    submit() {
      this.form.attachments = this.files.length ? this.files : null;

      const payload = pick(this.form, ['warehouse_id', 'date_requested', 'reason', 'comments', 'attachments', 'items', '_method']);
      this.form.transform(() => payload);

      if (this.edit) {
        this.form.post(route('material-requests.update', this.edit.id));
      } else {
        this.form.post(route('material-requests.store'));
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
.fx-mr-table th.w-12 { width: 3rem; }
@media (min-width: 768px) {
  .fx-item-td { min-width: 18rem; }
}
@media (max-width: 767.98px) {
  .fx-mr-table .fx-cell { flex: 1 1 auto; }
}

.fx-notes { display: grid; gap: 1.25rem; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 1024px) {
  .fx-notes { grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); }
}
.fx-notes-fields { grid-template-columns: minmax(0, 1fr); }
.fx-attach { min-width: 0; }
.fx-files { width: 100%; font-size: 0.8rem; color: var(--lg-ink); }
.fx-files li { display: flex; align-items: center; gap: 8px; min-width: 0; padding: 6px 2px; }
.fx-files-list { border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; background: var(--lg-card); }
.fx-files-list li { padding: 8px 12px; border-bottom: 1px solid var(--lg-line); }
.fx-files-list li:last-child { border-bottom: 0; }
.fx-file-hint { font-size: 0.75rem; color: var(--lg-mute); }
.fx-file-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 28px; height: 28px; border-radius: 9px; color: #fff; background: linear-gradient(145deg, #60a5fa, #1d4ed8); }
.fx-file-ico.is-new { background: linear-gradient(145deg, #34d399, #047857); }
.fx-file-name { flex: 1 1 auto; min-width: 0; font-weight: 600; }
.fx-file-name small { display: block; font-size: 0.7rem; font-weight: 500; color: var(--lg-mute); }
</style>
