<template>
  <admin-layout :title="edit ? $t('Edit Issue') : $t('Create Issue')">
    <div class="lg-page px-4 md:px-0 if-form">
      <page-header icon="out" :title="edit ? $t('Edit Issue') : $t('Create New Issue')" :subtitle="$t('Issue materials from warehouse stock.')">
        <span v-if="edit && edit.issue_number" class="lg-chip lg-chip-blue" style="text-transform: none">{{ edit.issue_number }}</span>
        <Link :href="route('issue-materials.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Issue Material') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card relative z-30">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Issue Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Issue Date') }} / {{ $t('Project(s) / Warehouse(s)') }} / {{ $t('Issued To') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input v-model="form.issue_date" @change="onIssueDateChange" type="datetime-local" :label="$t('Issue Date')" :error="$page.props.errors.issue_date" />
            <auto-complete
              clearable
              id="issue-warehouse"
              :label="$t('Project(s) / Warehouse(s)')"
              v-model="form.warehouse_id"
              :suggestions="warehouseSuggestions"
              :error="$page.props.errors.warehouse_id"
              :placeholder="$t('Search project(s) / warehouse(s)')"
              @update:model-value="onWarehouseChange"
            />

            <text-input
              v-model="form.project"
              :label="$t('Work Activity')"
              :error="$page.props.errors.project"
              :placeholder="$t('What activity will these items be used for?')"
            />
            <auto-complete
              id="issued-to"
              clearable
              :label="$t('Issued To')"
              :suggestions="contactSuggestions"
              v-model="form.issued_to"
              :error="$page.props.errors.issued_to"
              :placeholder="$t('Search contact by name')"
            />

            <text-input v-model="form.expected_return_date" type="date" :label="$t('Expected Return Date')" :error="$page.props.errors.expected_return_date" />
            <div class="col-span-6 sm:col-span-4 relative">
              <label class="if-label">{{ $t('Attachments') }}</label>
              <input
                id="issue-attachments"
                type="file"
                multiple
                class="sr-only"
                @change="onAttachmentsChange"
                accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip"
              />
              <label for="issue-attachments" class="if-file mt-1">
                <span class="inline-flex items-center gap-2 text-sm">
                  <icons name="clip" class="w-4 h-4 text-blue-600" />
                  <span>{{ $t('Select attachments') }}</span>
                </span>
                <span class="lg-chip lg-chip-blue" style="text-transform: none">
                  {{ form.attachments.length ? `${form.attachments.length} ${$t('file(s)')}` : $t('No files chosen') }}
                </span>
              </label>
              <ul class="if-files" v-if="form.attachments.length">
                <li v-for="(file, fi) in form.attachments" :key="fi">
                  <span class="truncate">{{ fi + 1 }}. {{ fileLabel(file) }}</span>
                  <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove') + ' ' + fileLabel(file)" :aria-label="$t('Remove') + ' ' + fileLabel(file)" @click="removeAttachment(fi)">
                    <icons name="cross" class="w-3.5 h-3.5" />
                  </button>
                </li>
              </ul>
              <div class="if-err" v-if="$page.props.errors.attachments">{{ $page.props.errors.attachments }}</div>
              <div class="if-err" v-if="$page.props.errors['attachments.*']">{{ $page.props.errors['attachments.*'] }}</div>
            </div>

            <div class="lg-span-all">
              <textarea-input v-model="form.remarks" :label="$t('Remarks')" :error="$page.props.errors.remarks" />
            </div>
          </div>
        </section>

        <section class="lg-card relative z-20 issue-liquid-table">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Items') }}</h2>
              <p class="lg-card-sub">{{ form.items.length }} {{ $t('line(s)') }}</p>
            </div>
            <button type="button" @click="addRow" class="lg-btn lg-btn-soft ml-auto"><icons name="plus" class="w-4 h-4" />{{ $t('Add Item') }}</button>
          </header>

          <div class="mb-4">
            <div v-if="form.warehouse_id" class="space-y-2">
              <label class="if-label">{{ $t('Add Item (Scan Barcode or Search)') }}</label>
              <div class="lg-scan">
                <auto-complete
                  clearable
                  id="add-item-search"
                  :suggestions="warehouseItemSuggestions"
                  @update:model-value="addItemFromSearch"
                  :placeholder="$t('Scan barcode or type item name/code')"
                  :defaultText="$t('Scan barcode or type to search available items in selected warehouse')"
                  position="z-[80]"
                />
              </div>
              <div class="flex justify-end">
                <button type="button" @click="openCameraScanner" class="lg-btn lg-btn-soft">
                  <icons name="mobile" class="w-4 h-4" />{{ $t('Scan by Phone Camera') }}
                </button>
              </div>
            </div>
            <div v-else class="lg-note lg-note-amber">
              <icons name="exclamation" class="w-4 h-4" /><span>{{ $t('Select a warehouse first to load available items.') }}</span>
            </div>
            <div class="if-err" v-if="localItemsError">{{ localItemsError }}</div>
          </div>

          <div class="relative pb-8 overflow-x-auto xl:overflow-visible" style="overflow-y: visible;">
            <table class="w-full min-w-[900px] issue-items-table">
              <thead>
                <tr class="text-left">
                  <th class="p-2">{{ $t('Item') }}</th>
                  <th class="p-2">{{ $t('Quantity') }}</th>
                  <th class="p-2">{{ $t('Unit') }}</th>
                  <th class="p-2">{{ $t('Available Stock') }}</th>
                  <th class="p-2">{{ $t('Remarks') }}</th>
                  <th class="p-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, i) in form.items" :key="line._row_key">
                  <td class="p-2 align-top relative" :data-label="$t('Item')">
                    <auto-complete
                      :id="`line-item-${line._row_key}`"
                      :suggestions="itemSuggestionsForRow(line)"
                      v-model="line.item_id"
                      @update:model-value="onItemChange(line)"
                      :error="$page.props.errors[`items.${i}.item_id`]"
                      :placeholder="$t('Search item')"
                      position="z-[220]"
                    />
                  </td>
                  <td class="p-2 align-top" :data-label="$t('Quantity')">
                    <text-input v-model="line.quantity" type="number" step="0.01" min="0" :error="$page.props.errors[`items.${i}.quantity`]" />
                    <div class="if-err" v-if="lineQuantityError(i, line)">{{ lineQuantityError(i, line) }}</div>
                  </td>
                  <td class="p-2 align-top" :data-label="$t('Unit')">
                    <div class="h-10 if-static">{{ line.item?.unit?.name || '-' }}</div>
                  </td>
                  <td class="p-2 align-top" :data-label="$t('Available Stock')">
                    <div class="h-10 if-static if-static-num">{{ formatNumber(lineAvailable(i, line)) }}</div>
                  </td>
                  <td class="p-2 align-top" :data-label="$t('Remarks')">
                    <text-input v-model="line.remarks" :error="$page.props.errors[`items.${i}.remarks`]" />
                  </td>
                  <td class="p-2 align-top" data-label="">
                    <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeRow(line._row_key)">
                      <icons name="trash" class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="if-err" v-if="$page.props.errors.items">{{ $page.props.errors.items }}</div>
          <div class="lg-note lg-note-red mt-2" v-if="hasLineQuantityError">
            <icons name="exclamation" class="w-4 h-4" />
            <span>{{ $t('One or more lines exceed available stock. Please adjust issuing quantities before saving.') }}</span>
          </div>
        </section>

        <div class="lg-actionbar">
          <span v-if="hasLineQuantityError" class="lg-actionbar-note if-err-note">{{ $t('One or more lines exceed available stock.') }}</span>
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('issue-materials.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing || hasLineQuantityError">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ edit ? $t('Update Issue') : $t('Save Issue') }}
            </button>
          </div>
        </div>
      </form>

      <div v-if="cameraScanOpen" class="fixed inset-0 z-[200] bg-black/50 flex items-center justify-center p-4" @click.self="closeCameraScanner">
        <div class="if-scan lg-page">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="mobile" class="w-5 h-5" /></span>
            <div class="min-w-0"><h4 class="lg-card-title">{{ $t('Scan Item Barcode') }}</h4></div>
            <button type="button" class="lg-icon-btn ml-auto lg-tip" :data-tip="$t('Close')" :aria-label="$t('Close')" @click="closeCameraScanner">
              <icons name="cross" class="w-4 h-4" />
            </button>
          </header>

          <div id="issue-item-camera-scanner" class="if-scan-view"></div>
          <div class="lg-subtext mt-2">{{ $t('Use your phone camera to scan barcode, or enter barcode manually below.') }}</div>
          <div v-if="cameraStatusMessage" class="lg-note lg-note-blue mt-2">{{ cameraStatusMessage }}</div>
          <div v-if="cameraError" class="lg-note lg-note-red mt-2">{{ cameraError }}</div>

          <div class="mt-3">
            <text-input
              v-model="manualBarcode"
              type="text"
              :label="$t('Manual Barcode')"
              :placeholder="$t('Enter barcode and press Enter')"
              @keyup.enter="submitManualBarcode"
            />
          </div>

          <div class="mt-3 flex justify-end gap-2">
            <button type="button" @click="closeCameraScanner" class="lg-btn lg-btn-soft">{{ $t('Close') }}</button>
            <button type="button" @click="submitManualBarcode" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Find Item') }}</button>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
    TextInput,
    TextareaInput,
    LoadingButton,
    AutoComplete,
  },
  props: {
    edit: Object,
    warehouses: Array,
    items: Array,
    contacts: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    const initialIssueDate = this.edit?.issue_date ? this.formatForDateTimeLocalInput(this.edit.issue_date) : this.currentDateTimeLocal();
    const initialExpectedReturnDate = this.edit?.expected_return_date || this.defaultExpectedReturnDate(initialIssueDate);

    return {
      localItemsError: '',
      cameraScanOpen: false,
      cameraLoading: false,
      cameraScanner: null,
      cameraError: '',
      cameraStatusMessage: '',
      lastScannedCode: null,
      manualBarcode: '',
      autoExpectedReturnDate: initialExpectedReturnDate,
      form: this.$inertia.form({
        issue_date: initialIssueDate,
        warehouse_id: this.edit?.warehouse_id || null,
        project: this.edit?.project || '',
        issued_to: this.edit?.issued_to || '',
        expected_return_date: initialExpectedReturnDate,
        remarks: this.edit?.remarks || '',
        attachments: [],
        items:
          this.edit?.items?.map(line => ({
            _row_key: this.makeRowKey(line.id),
            issue_transaction_item_id: line.id,
            item_id: line.item_id,
            unit_id: line.unit_id,
            quantity: line.issued_quantity,
            remarks: line.remarks || '',
            original_item_id: line.item_id,
            original_quantity: Number(line.issued_quantity || 0),
            item: this.items.find(i => i.id === line.item_id) || null,
          })) || [this.emptyRow()],
      }),
    };
  },
  computed: {
    warehouseSuggestions() {
      return (this.warehouses || []).map(warehouse => ({
        value: warehouse.id,
        label: warehouse.name,
      }));
    },
    contactSuggestions() {
      return (this.contacts || []).map(contact => ({
        value: contact.name,
        label: contact.name,
      }));
    },
    warehouseItemSuggestions() {
      if (!this.form.warehouse_id) {
        return [];
      }

      const selectedIds = this.form.items.map(l => Number(l.item_id)).filter(Boolean);
      return this.items.filter(item => {
        const itemId = Number(item.id);
        const stock = this.stockQty(itemId);
        if (selectedIds.includes(itemId)) {
          return true;
        }
        return stock > 0;
      }).map(item => ({
        ...item,
        value: item.id,
        label: `${item.name} (${item.code || 'No Code'})`,
      }));
    },
    hasLineQuantityError() {
      return this.form.items.some((line, i) => !!this.lineQuantityError(i, line));
    },
  },
  methods: {
    emptyRow() {
      return {
        _row_key: this.makeRowKey(),
        issue_transaction_item_id: null,
        item_id: null,
        quantity: '',
        unit_id: null,
        remarks: '',
        item: null,
        original_item_id: null,
        original_quantity: 0,
      };
    },
    addRow() {
      this.localItemsError = '';
      this.form.items.push(this.emptyRow());
    },
    removeRow(rowKey) {
      this.form.items = this.form.items.filter(line => line._row_key !== rowKey);
      if (!this.form.items.length) this.form.items.push(this.emptyRow());
    },
    onWarehouseChange() {
      this.localItemsError = '';

      this.form.items = this.form.items.map(line => {
        if (!line.item_id) {
          return line;
        }
        const stock = this.stockQty(Number(line.item_id));
        const keepBecauseEditAllowance = Number(line.original_item_id) === Number(line.item_id) && Number(line.original_quantity) > 0;
        if (stock > 0 || keepBecauseEditAllowance) {
          return line;
        }
        return this.emptyRow();
      });
    },
    addItemFromSearch(itemId) {
      const id = Number(itemId || 0);
      if (!id) {
        return;
      }

      this.localItemsError = '';
      const existing = this.form.items.find(l => Number(l.item_id) === id);
      if (existing) {
        this.localItemsError = 'Item is already in the list. Update its quantity instead.';
        return;
      }

      const item = this.items.find(i => Number(i.id) === id) || null;
      if (!item) {
        return;
      }

      this.form.items.push({
        ...this.emptyRow(),
        item_id: id,
        item,
        unit_id: item?.unit?.id || null,
      });
    },
    itemSuggestionsForRow(line) {
      const currentId = Number(line.item_id || 0);
      return this.warehouseItemSuggestions.filter(item => Number(item.id) === currentId || this.stockQty(Number(item.id)) > 0);
    },
    onItemChange(line) {
      line.item = this.items.find(i => i.id === Number(line.item_id)) || null;
      if (line.item?.unit?.id) {
        line.unit_id = line.item.unit.id;
      } else {
        line.unit_id = null;
      }
    },
    stockQty(itemId) {
      if (!itemId || !this.form.warehouse_id) {
        return 0;
      }
      const item = this.items.find(i => Number(i.id) === Number(itemId));
      if (!item) {
        return 0;
      }
      const stockLine = (item.stock || []).find(s => Number(s.warehouse_id) === Number(this.form.warehouse_id));
      return Number(stockLine?.quantity || 0);
    },
    lineAvailable(i, line) {
      const itemId = Number(line.item_id || 0);
      if (!itemId) {
        return 0;
      }

      const baseAvailable = this.stockQty(itemId);
      const editAllowance = Number(line.original_item_id) === itemId ? Number(line.original_quantity || 0) : 0;
      const otherRowsQty = this.form.items.reduce((carry, row, idx) => {
        if (idx === i) {
          return carry;
        }
        if (Number(row.item_id) !== itemId) {
          return carry;
        }
        return carry + Number(row.quantity || 0);
      }, 0);

      return Math.max(baseAvailable + editAllowance - otherRowsQty, 0);
    },
    lineQuantityError(i, line) {
      const qty = Number(line.quantity || 0);
      if (!line.item_id || !qty) {
        return '';
      }

      const available = this.lineAvailable(i, line);
      if (qty > available) {
        return `Issued quantity exceeds available stock (${this.formatNumber(available)}).`;
      }
      return '';
    },
    // Native <input type=file multiple> replaces its own selection every
    // time the picker is used — choosing 2 files, then choosing 2 more,
    // would otherwise drop the first 2. Appending here (and resetting the
    // input after) lets the picker be opened repeatedly to build up one
    // combined list, matching removeAttachment()'s per-file delete below.
    onAttachmentsChange(e) {
      const newFiles = Array.from(e.target.files || []);
      const existingKeys = new Set(this.form.attachments.map(f => `${f.name}:${f.size}:${f.lastModified}`));
      newFiles.forEach(file => {
        const key = `${file.name}:${file.size}:${file.lastModified}`;
        if (!existingKeys.has(key)) {
          this.form.attachments.push(file);
          existingKeys.add(key);
        }
      });
      e.target.value = '';
    },
    removeAttachment(index) {
      this.form.attachments.splice(index, 1);
    },
    fileLabel(file) {
      if (!file || typeof file !== 'object') {
        return '';
      }
      const name = file.name || 'Attachment';
      const size = Number(file.size);
      if (!Number.isFinite(size) || size < 0) {
        return name;
      }
      return `${name} (${this.humanSize(size)})`;
    },
    humanSize(size) {
      if (!size) {
        return '0 B';
      }
      const units = ['B', 'kB', 'MB', 'GB', 'TB'];
      const i = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);
      return `${(size / Math.pow(1024, i)).toFixed(2)} ${units[i]}`;
    },
    formatNumber(v) {
      const n = Number(v || 0);
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },
    defaultExpectedReturnDate(issueDate) {
      if (!issueDate) {
        return '';
      }

      const dateOnly = String(issueDate).slice(0, 10);
      const dt = new Date(`${dateOnly}T00:00:00`);
      if (Number.isNaN(dt.getTime())) {
        return '';
      }

      dt.setDate(dt.getDate() + 10);
      return dt.toISOString().slice(0, 10);
    },
    currentDateTimeLocal() {
      const now = new Date();
      const year = now.getFullYear();
      const month = String(now.getMonth() + 1).padStart(2, '0');
      const day = String(now.getDate()).padStart(2, '0');
      const hours = String(now.getHours()).padStart(2, '0');
      const minutes = String(now.getMinutes()).padStart(2, '0');
      return `${year}-${month}-${day}T${hours}:${minutes}`;
    },
    formatForDateTimeLocalInput(value) {
      if (!value) {
        return '';
      }
      const dt = new Date(value);
      if (Number.isNaN(dt.getTime())) {
        return String(value).slice(0, 16);
      }
      const year = dt.getFullYear();
      const month = String(dt.getMonth() + 1).padStart(2, '0');
      const day = String(dt.getDate()).padStart(2, '0');
      const hours = String(dt.getHours()).padStart(2, '0');
      const minutes = String(dt.getMinutes()).padStart(2, '0');
      return `${year}-${month}-${day}T${hours}:${minutes}`;
    },
    makeRowKey(seed = '') {
      return `${Date.now()}-${Math.random().toString(36).slice(2)}-${seed || 'row'}`;
    },
    onIssueDateChange() {
      const suggestedDate = this.defaultExpectedReturnDate(this.form.issue_date);
      const currentExpected = this.form.expected_return_date;

      if (!currentExpected || currentExpected === this.autoExpectedReturnDate) {
        this.form.expected_return_date = suggestedDate;
      }

      this.autoExpectedReturnDate = suggestedDate;
    },
    submit() {
      this.localItemsError = '';
      if (this.hasLineQuantityError) {
        this.localItemsError = 'One or more lines exceed available stock. Please adjust issuing quantities.';
        return;
      }

      if (this.edit) {
        this.form.transform(data => ({ ...data, _method: 'put' })).post(route('issue-materials.update', this.edit.id));
      } else {
        this.form.post(route('issue-materials.store'));
      }
    },
    openCameraScanner() {
      if (!this.form.warehouse_id) {
        this.localItemsError = 'Select a warehouse first to scan items.';
        return;
      }

      this.cameraScanOpen = true;
      this.cameraError = '';
      this.cameraStatusMessage = 'Opening camera...';
      this.lastScannedCode = null;
      this.manualBarcode = '';
      this.$nextTick(() => this.startCameraScanner());
    },
    closeCameraScanner() {
      this.cameraScanOpen = false;
      this.cameraStatusMessage = '';
      this.stopCameraScanner();
    },
    async startCameraScanner() {
      if (!this.cameraScanOpen || this.cameraLoading) {
        return;
      }

      this.cameraLoading = true;
      this.cameraError = '';

      try {
        const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');

        if (this.cameraScanner) {
          await this.stopCameraScanner();
        }

        this.cameraScanner = new Html5Qrcode('issue-item-camera-scanner');
        await this.cameraScanner.start(
          { facingMode: 'environment' },
          {
            fps: 10,
            qrbox: (viewfinderWidth, viewfinderHeight) => ({
              width: Math.floor(viewfinderWidth * 0.9),
              height: Math.floor(viewfinderHeight * 0.4),
            }),
            aspectRatio: 1.333334,
            rememberLastUsedCamera: true,
            formatsToSupport: [
              Html5QrcodeSupportedFormats.CODE_128,
              Html5QrcodeSupportedFormats.CODE_39,
              Html5QrcodeSupportedFormats.CODE_93,
              Html5QrcodeSupportedFormats.CODABAR,
              Html5QrcodeSupportedFormats.EAN_13,
              Html5QrcodeSupportedFormats.EAN_8,
              Html5QrcodeSupportedFormats.UPC_A,
              Html5QrcodeSupportedFormats.UPC_E,
              Html5QrcodeSupportedFormats.ITF,
              Html5QrcodeSupportedFormats.QR_CODE,
              Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
          },
          decodedText => {
            const code = (decodedText || '').trim();
            if (!code || this.lastScannedCode === code) {
              return;
            }

            this.lastScannedCode = code;
            this.cameraStatusMessage = `Detected barcode: ${code}`;
            if (this.addItemByBarcode(code)) {
              this.closeCameraScanner();
            } else {
              this.lastScannedCode = null;
            }
          },
          () => {}
        );

        this.cameraStatusMessage = 'Camera started. Hold barcode steady for 1-2 seconds.';
      } catch (error) {
        this.cameraError = 'Unable to start camera scanner. Please allow camera access and try again.';
        this.cameraStatusMessage = '';
      } finally {
        this.cameraLoading = false;
      }
    },
    submitManualBarcode() {
      const code = String(this.manualBarcode || '').trim();
      if (!code) {
        this.cameraError = 'Please enter a barcode value.';
        return;
      }

      this.cameraError = '';
      this.cameraStatusMessage = `Searching item for: ${code}`;
      if (this.addItemByBarcode(code)) {
        this.closeCameraScanner();
      }
    },
    addItemByBarcode(rawCode) {
      const code = String(rawCode || '').trim().toLowerCase();
      if (!code) {
        return false;
      }

      const matched = this.warehouseItemSuggestions.find(item => String(item.code || '').trim().toLowerCase() === code);
      if (!matched) {
        this.cameraError = 'Barcode scanned but no available item matched in selected warehouse.';
        return false;
      }

      this.addItemFromSearch(matched.id);
      if (this.localItemsError) {
        this.cameraError = this.localItemsError;
        return false;
      }

      this.cameraError = '';
      this.cameraStatusMessage = `Item added: ${matched.name}`;
      return true;
    },
    async stopCameraScanner() {
      if (!this.cameraScanner) {
        return;
      }

      try {
        await this.cameraScanner.stop();
      } catch (e) {
      }

      try {
        await this.cameraScanner.clear();
      } catch (e) {
      }

      this.cameraScanner = null;
      this.cameraLoading = false;
    },
  },
  beforeUnmount() {
    this.stopCameraScanner();
  },
};
</script>

<style scoped>
.if-label, .if-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute);
}
.if-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.if-form :deep(.lg-fields select),
.if-form :deep(.lg-fields textarea),
.if-form :deep(.issue-items-table input:not([type='checkbox'])) {
  border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.if-form :deep(.lg-fields input:focus), .if-form :deep(.lg-fields select:focus), .if-form :deep(.lg-fields textarea:focus),
.if-form :deep(.issue-items-table input:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important; }

.if-file { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; min-height: 44px; padding: 0.5rem 0.8rem; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-card); cursor: pointer; transition: border-color 0.15s ease; }
.if-file:hover { border-color: rgba(56, 189, 248, 0.7); }
.if-files { list-style: none; margin: 0.5rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.if-files li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0.3rem 0.5rem 0.3rem 0.8rem; font-size: 0.76rem; color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); border-radius: 12px; }
.if-files .lg-icon-btn { width: 26px; height: 26px; border-radius: 8px; }
.if-err { margin-top: 0.3rem; font-size: 0.8rem; color: #be123c; }
.if-err-note { color: #be123c; }
html.dark .if-err, html.dark .if-err-note { color: #fda4af; }
.if-static { display: flex; align-items: center; padding: 0 0.8rem; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.86rem; font-weight: 600; }
.if-static-num { font-variant-numeric: tabular-nums; }

.issue-items-table { border-collapse: separate; border-spacing: 0; }
.issue-items-table thead th { padding: 10px 8px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); background: var(--lg-soft); border-bottom: 1px solid var(--lg-line); }
.issue-items-table thead th:first-child { border-top-left-radius: 12px; }
.issue-items-table thead th:last-child { border-top-right-radius: 12px; }
.issue-items-table tbody td { border-bottom: 1px solid var(--lg-line); }
.issue-items-table :deep(input),
.issue-items-table :deep(.h-10) { height: 2.5rem; }

.if-scan { width: 100%; max-width: 28rem; padding: 1.15rem 1.3rem; border-radius: 22px; background: #fff; box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.55); }
html .if-scan.lg-page { --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; --lg-faint: #94a3b8; --lg-navy: #1c3f66; }
.if-scan .lg-card-head { margin-bottom: 0.8rem; }
.if-scan-view { width: 100%; min-height: 220px; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); overflow: hidden; }

/* Below md each row becomes its own card with the column name above each control. */
@media (max-width: 767px) {
  .issue-items-table { min-width: 0; display: block; width: 100%; }
  .issue-items-table thead { display: none; }
  .issue-items-table tbody,
  .issue-items-table tr,
  .issue-items-table td { display: block; width: 100%; }
  .issue-items-table tr {
    border: 1px solid var(--lg-line) !important; border-radius: 16px; margin-bottom: 0.75rem;
    padding: 0.75rem; background: var(--lg-card); box-shadow: var(--lg-shadow);
  }
  .issue-items-table tbody td { padding: 0.4rem 0 !important; border-bottom: 0; }
  .issue-items-table td[data-label]:not([data-label=""])::before {
    content: attr(data-label); display: block; font-size: 0.66rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-faint); margin-bottom: 0.25rem;
  }
}
</style>
