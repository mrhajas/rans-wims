<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Transfer') })">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="truck"
        :title="edit ? $t('edit_x', { x: $t('Transfer') }) + ' (' + edit.reference + ')' : $t('create_x', { x: $t('Transfer') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <Link :href="route('transfers.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />
          <span>{{ $t('Back') }}</span>
        </Link>
      </page-header>

      <div v-if="mrLink" class="lg-note lg-note-blue mb-4 items-center flex-wrap print:hidden">
        <icons name="truck" class="w-4 h-4 flex-none" />
        <div class="flex-1 min-w-0">
          {{ $t('This transfer is for Material Request') }}
          <Link :href="route('material-requests.show', mrLink.id)" class="font-semibold underline">{{ mrLink.reference }}</Link>.
          {{ $t('It can only take items of that request, and no more than is still to be moved.') }}
          <span v-if="prefill && prefill.skipped && prefill.skipped.length" class="block mt-1">{{ $t('Add these items yourself, with their variations or serial numbers:') }} <b>{{ prefill.skipped.join(', ') }}</b></span>
        </div>
      </div>

      <div v-if="edit && !edit.deleted_at" class="lg-card fm-docs print:hidden">
        <div class="lg-card-head fm-docs-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <div class="lg-card-title">{{ $t('Transaction Documents') }}</div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <a v-if="$can('print-transfers')" :href="route('transfers.print', edit.id)" target="_blank" @click.exact.prevent="printThis" class="lg-btn lg-btn-soft">
              <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
            </a>
            <a v-if="$can('print-transfers')" :href="route('transfers.pdf', edit.id)" class="lg-btn lg-btn-soft">
              <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
            </a>
            <delivery-note-action :state="deliveryNote" />
            <gate-pass-action :state="gatePass" />
            <transport-route-action :state="transportRoute" />
          </div>
        </div>
      </div>

      <form novalidate @submit.prevent="onFormSubmit" @keyup.enter="onFormKeyup">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <div v-if="edit" class="tr-status-indicator mb-4" :style="{ '--status-accent': transferStatusStyle.accent }">
          <span class="tr-status-icon-badge">
            <icons :name="transferStatusStyle.icon" class="w-4 h-4" />
          </span>
          <span class="tr-status-text-wrap">
            <span class="tr-status-label">{{ $t('Status') }}</span>
            <span class="tr-status-value">{{ $t(transferStatus) }}</span>
          </span>
          <span v-if="transferStatus === 'Received' && edit.received_by_user" class="tr-status-meta">
            {{ $t('By') }} {{ edit.received_by_user.name }} &middot; {{ $datetime(edit.received_at) }}
          </span>
          <button
            v-if="['Pending', 'Approved'].includes(transferStatus) && canDeliverThisTransfer"
            type="button"
            @click="markReceived()"
            :disabled="receiving"
            class="tr-mark-received-btn"
          >
            <icons name="tick" class="h-3.5 w-3.5 mr-1" />
            {{ receiving ? $t('Marking…') : $t('Mark as Received') }}
          </button>
        </div>

        <!-- Approval (transfers that leave a main warehouse) -->
        <div v-if="edit && edit.approval_status === 'pending'" class="lg-note lg-note-amber mb-4 items-center flex-wrap">
          <icons name="clock" class="w-4 h-4" />
          <div class="flex-1 min-w-0">
            <strong>{{ $t('Waiting for approval.') }}</strong>
            {{ $t('This transfer leaves a main warehouse, so no stock has moved yet.') }}
          </div>
          <div class="flex items-center gap-2">
            <button v-if="$can('approve-transfers')" type="button" class="lg-btn lg-btn-green" @click="approveThis"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="$can('cancel-transfers')" type="button" class="lg-btn lg-btn-red" @click="cancelThis"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel transfer') }}</button>
          </div>
        </div>
        <div v-else-if="edit && edit.approval_status === 'approved' && edit.approved_by_user" class="lg-note lg-note-blue mb-4">
          <icons name="tick" class="w-4 h-4" />
          <div>{{ $t('Approved by') }} <strong>{{ edit.approved_by_user.name }}</strong> &middot; {{ $datetime(edit.approved_at) }}</div>
        </div>
        <div v-else-if="edit && edit.approval_status === 'cancelled'" class="lg-note lg-note-red mb-4">
          <icons name="cross" class="w-4 h-4" />
          <div>
            {{ $t('Cancelled by') }} <strong>{{ edit.cancelled_by_user ? edit.cancelled_by_user.name : '—' }}</strong>
            <span v-if="edit.cancelled_at"> &middot; {{ $datetime(edit.cancelled_at) }}</span>
            <span v-if="edit.cancel_reason"> &mdash; {{ edit.cancel_reason }}</span>
          </div>
        </div>
        <div v-else-if="fromIsMain && !(edit && edit.approval_status === 'approved')" class="lg-note lg-note-amber mb-4">
          <icons name="info" class="w-4 h-4" />
          <div>{{ $t('This transfer leaves a main warehouse. Once saved as final it waits for approval, and stock only moves after it is approved.') }}</div>
        </div>

        <!-- Details -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Details') }}</div>
              <div class="lg-card-sub">{{ $t('Transfer date and the warehouses involved') }}</div>
            </div>
          </div>
          <div class="lg-fields apple-field-grid">
            <div>
              <date-picker v-model="form.date" :error="$page.props.errors.date" :label="$t('Date')" />
            </div>
            <div>
              <auto-complete
                id="from_warehouse"
                stock-move
                :suggestions="userWarehouses"
                :label="$t('From Warehouse')"
                v-model="form.from_warehouse_id"
                :error="$page.props.errors.from_warehouse_id"
              />
            </div>
            <div>
              <auto-complete
                id="to_warehouse"
                stock-move
                :suggestions="warehouses"
                :label="$t('To Warehouse')"
                :disable="form.from_warehouse_id"
                v-model="form.to_warehouse_id"
                :error="$page.props.errors.to_warehouse_id"
              />
            </div>
          </div>
        </section>

        <!-- Items -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <div class="lg-card-title">{{ $t('Items') }}</div>
              <div class="lg-card-sub">{{ $t('Add at least one item') }}</div>
            </div>
            <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ filledCount }} {{ $t('Items') }}</span>
          </div>

          <div v-if="!warehousesReady" class="lg-note lg-note-amber mb-3">
            <icons name="info" class="w-4 h-4" />
            <div>{{ warehouseHint }}</div>
          </div>

          <div class="lg-table-wrap tr-items-wrap apple-field-grid">
            <table class="lg-table lg-responsive tr-items-table">
              <thead>
                <tr>
                  <th class="w-12"></th>
                  <th>{{ $t('Item') }}</th>
                  <th class="tr-col-qty">{{ $t('Quantity') }}</th>
                  <th class="tr-col-unit">{{ $t('Unit') }}</th>
                </tr>
              </thead>
              <tbody v-for="(row, ii) in form.items" :key="row._key">
                <!-- an item not chosen yet -->
                <tr v-if="row.blank" :class="{ 'tr-err-row': rowError(ii, 'item_id') }">
                  <td :data-label="$t('Remove')">
                    <button type="button" @click="removeItem(ii)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                  <td :data-label="$t('Item')" class="tr-item-td">
                    <div class="tr-cell">
                      <text-input v-if="!warehousesReady" :model-value="''" :placeholder="$t('Choose the warehouses first')" disabled />
                      <auto-complete
                        v-else
                        json
                        reset-search
                        load-on-focus
                        :id="'tr-item-' + row._key"
                        :suggestions="itemSearchUrl"
                        :placeholder="$t('Search item or scan barcode')"
                        :defaultText="$t('Type an item name, code or barcode')"
                        @update:model-value="pickItem(row._key, $event)"
                      />
                      <div v-if="rowError(ii, 'item_id')" class="text-red-600 text-xs mt-1 wims-field-error">{{ rowError(ii, 'item_id') }}</div>
                    </div>
                  </td>
                  <td :data-label="$t('Quantity')" class="tr-hide-sm"><div class="tr-cell"><text-input :model-value="''" disabled /></div></td>
                  <td :data-label="$t('Unit')" class="tr-hide-sm"><div class="tr-cell"><text-input :model-value="''" disabled /></div></td>
                </tr>

                <!-- an item with variations: the item line, then a line for each chosen variation -->
                <template v-else-if="hasVariations(row)">
                  <tr :class="{ 'tr-err-row': itemErrors(ii).length }">
                    <td :data-label="$t('Remove')">
                      <button type="button" @click="removeItem(ii)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')">
                        <icons name="trash" class="w-4 h-4" />
                      </button>
                    </td>
                    <td :data-label="$t('Item')" class="tr-item-td">
                      <div class="tr-cell tr-item-line">
                        <div class="min-w-0">
                          <div class="tr-item-name">{{ row.name }}</div>
                          <div class="tr-item-code">{{ row.code }}</div>
                        </div>
                        <button type="button" class="lg-btn tone tr-add-var" style="--tone: #7c3aed" @click="openVariants(row)">
                          <span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Add variation') }}
                        </button>
                      </div>
                      <div v-for="(msg, mi) in itemErrors(ii)" :key="mi" class="text-red-600 text-xs mt-1 wims-field-error">{{ msg }}</div>
                    </td>
                    <td :data-label="$t('Quantity')"><div class="tr-cell tr-total">{{ itemQuantity(row) }}</div></td>
                    <td class="tr-hide-sm"></td>
                  </tr>
                  <tr v-for="(variation, vi) in row.selected.variations" :key="variation.id" class="is-group tr-var-row">
                    <td :data-label="$t('Remove')">
                      <button type="button" @click="removeVariation(ii, vi)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')">
                        <icons name="trash" class="w-4 h-4" />
                      </button>
                    </td>
                    <td :data-label="$t('Variation')">
                      <div class="tr-cell tr-var-meta" v-html="$meta(variation.meta)"></div>
                    </td>
                    <td :data-label="$t('Quantity')">
                      <div class="tr-cell">
                        <text-input type="number" v-model="variation.quantity" @update:model-value="editedRow(ii)" />
                      </div>
                    </td>
                    <td :data-label="$t('Unit')">
                      <div class="tr-cell">
                        <select-input v-model="variation.unit_id" class="w-full">
                          <option v-for="u in unitChoices(row)" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select-input>
                      </div>
                    </td>
                  </tr>
                </template>

                <!-- an ordinary item -->
                <tr v-else :class="{ 'tr-err-row': rowError(ii, 'item_id') || rowError(ii, 'quantity') || rowError(ii, 'weight') }">
                  <td :data-label="$t('Remove')">
                    <button type="button" @click="removeItem(ii)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                  <td :data-label="$t('Item')" class="tr-item-td">
                    <div class="tr-cell">
                      <div class="tr-item-name">{{ row.name }}</div>
                      <div class="tr-item-code">{{ row.code }}</div>
                      <div v-if="rowError(ii, 'item_id')" class="text-red-600 text-xs mt-1 wims-field-error">{{ rowError(ii, 'item_id') }}</div>
                    </div>
                  </td>
                  <td :data-label="$t('Quantity')">
                    <div class="tr-cell">
                      <text-input :id="'tr-qty-' + row._key" type="number" v-model="row.quantity" @update:model-value="editedRow(ii)" />
                      <div v-if="row.mr_remaining != null" class="tr-hint">{{ $t('Still to move') }}: {{ row.mr_remaining }}</div>
                      <div v-if="rowError(ii, 'quantity') || rowError(ii, 'weight')" class="text-red-600 text-xs mt-1 wims-field-error">{{ rowError(ii, 'quantity') || rowError(ii, 'weight') }}</div>
                    </div>
                  </td>
                  <td :data-label="$t('Unit')">
                    <div class="tr-cell">
                      <select-input v-if="row.unit" v-model="row.unit_id" class="w-full">
                        <option v-for="u in unitChoices(row)" :key="u.id" :value="u.id">{{ u.name }}</option>
                      </select-input>
                      <text-input v-else :model-value="'-'" disabled />
                    </div>
                  </td>
                </tr>
              </tbody>
              <tbody v-if="form.items.length === 0">
                <tr class="lg-empty-row">
                  <td class="lg-empty" colspan="4">{{ $t('Add at least one item') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" @click="addItem" :disabled="!warehousesReady" class="lg-btn tone" style="--tone: #059669">
              <span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>
              {{ $t('Add Item') }}
            </button>
            <button
              type="button"
              @click="openScanner"
              :disabled="!warehousesReady"
              :aria-label="$t('Scan barcode with camera')"
              @mouseenter="showScanTooltip"
              @mouseleave="hideScanTooltip"
              class="lg-btn tone"
              style="--tone: #2563eb"
            >
              <span class="tone-ico">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M4 7h3l2-2h6l2 2h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z" />
                  <circle cx="12" cy="13" r="4" />
                </svg>
              </span>
              {{ $t('Scan') }}
            </button>
          </div>
          <div v-if="$page.props.errors.items" class="text-red-600 text-sm mt-2 wims-field-error">{{ $page.props.errors.items }}</div>

          <camera-barcode-scanner ref="cameraScanner" @resolved="itemSelected" />

          <teleport to="body">
            <div v-if="scanTooltip.show" class="tr-scan-tooltip" :style="{ left: scanTooltip.x + 'px', top: scanTooltip.y + 'px' }">
              {{ $t('Scan Barcode with Camera') }}
              <span class="tr-scan-tooltip-kbd">{{ formatShortcut(scanShortcut) }}</span>
            </div>
          </teleport>
        </section>

        <!-- Notes & Attachments -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Notes & Attachments') }}</div>
              <div class="lg-card-sub">{{ $t('Optional notes and supporting files for this record') }}</div>
            </div>
          </div>
          <div class="lg-fields apple-field-grid">
            <div>
              <label for="transfer-file-upload" class="font-medium text-gray-700">{{ $t('Attachments') }}</label>
              <div v-if="edit && edit.attachments && edit.attachments.length" class="print:hidden py-4 w-full">
                <ul role="list" class="border border-gray-200 rounded-md divide-y divide-gray-200">
                  <li
                    v-for="attachment in edit.attachments"
                    :key="attachment.id"
                    class="pl-3 pr-4 py-3 flex items-center justify-between text-sm"
                  >
                    <div class="w-0 flex-1 flex items-center">
                      <icons name="clip" class="flex-shrink-0 h-5 w-5 text-gray-400" />
                      <span class="ml-2 flex-1 w-0 truncate"> {{ attachment.title }} </span>
                    </div>
                    <div class="ml-4 flex-shrink-0 flex items-center gap-4">
                      <a class="font-medium text-indigo-600 hover:text-indigo-500" :href="route('media.download', attachment.id)">
                        {{ $t('Download') }}
                      </a>
                      <button type="button" class="font-medium text-red-600 hover:text-red-500" @click="deleteAttachment(attachment.id)">
                        {{ $t('Delete') }}
                      </button>
                    </div>
                  </li>
                </ul>
              </div>
              <attachment-picker
                v-model="chosenFiles"
                input-id="transfer-file-upload"
                accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip"
                :help-text="$t('You can select .png, .jpg, .pdf, .docx, .xlsx & .zip files.')"
                class="mt-1"
              />
              <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
            </div>
            <div class="lg-span-2 flex flex-col gap-4">
              <textarea-input v-model="form.details" :error="$page.props.errors.details" :label="$t('Details')" />
              <check-box
                id="draft"
                v-model:checked="form.draft"
                v-if="!edit || edit.draft == 1"
                :error="$page.props.errors.draft"
                :label="$t('This record is draft')"
              />
            </div>
          </div>
        </section>

        <!-- Sticky action bar -->
        <div class="lg-actionbar print:hidden">
          <div class="lg-actionbar-note">
            <animated-number :value="filledCount" /> {{ $t('Items') }}
            &middot;
            <animated-number :value="totalQuantity" use-grouping /> {{ $t('Quantity') }}
          </div>
          <div class="lg-actionbar-btns">
            <template v-if="edit">
              <button
                type="button"
                @click="destroy"
                v-if="!edit.deleted_at"
                class="lg-btn lg-btn-soft fm-danger"
              >
                <icons name="trash" class="w-4 h-4" />
                <span>{{ $t('delete_x', { x: $t('Transfer') }) }}</span>
              </button>
              <button
                v-else
                type="button"
                @click="deletePermanently"
                class="lg-btn lg-btn-soft fm-danger"
              >
                <icons name="trash" class="w-4 h-4" />
                <span>{{ $t('delete_x', { x: $t('Permanently') }) }}</span>
              </button>
            </template>
            <tec-action-message :on="form.recentlySuccessful" class="mx-1">{{ $t('Saved.') }}</tec-action-message>
            <Link :href="route('transfers.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <loading-button type="submit" :loading="form.processing" :disabled="form.processing" class="lg-btn lg-btn-primary fm-save gap-1.5">
              <icons name="tick" class="w-4 h-4" /> {{ $t('Save') }}
            </loading-button>
          </div>
        </div>
      </form>

      <section v-if="edit" class="lg-card print:hidden">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Audit History') }}</div>
          </div>
        </div>
        <div v-if="!history.length" class="lg-subtext text-center py-6">
          {{ $t('No history recorded for this transaction yet.') }}
        </div>
        <div v-else class="space-y-3">
          <div v-for="(entry, idx) in history" :key="idx" class="txn-history-row">
            <div class="flex flex-wrap items-center justify-between gap-x-3">
              <span class="lg-main text-sm">{{ entry.description }}</span>
              <span class="lg-subtext">{{ entry.created_at }}</span>
            </div>
            <div class="lg-subtext">{{ $t('By') }} {{ entry.causer }}</div>
          </div>
        </div>
      </section>
    </div>

    <!-- Select Variation Modal -->
    <select-variant-modal
      v-if="select_variant"
      :show="select_variant"
      :nf="unknown_variation"
      :variants="item.variants"
      @selected="variantSelected"
      @close="select_variant = false"
    />

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Transfer?"
      :close="closePermanentModal"
      action-text="Delete Permanently"
      :action="deleteCategoryPermanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteItem"
      action-type="delete"
      title="Delete Transfer?"
      action-text="Delete Transfer"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreItem"
      title="Restore Transfer!"
      :close="closeRestoreModal"
      action-text="Restore Transfer"
      :content="$t('Are you sure you want to restore the record?')"
    />
  </admin-layout>
</template>

<script>
import { printWithAnimation, printUrlInFrame, transferPrintSheet } from '@/Core/printAnimation';
import _isEqual from 'lodash/isEqual';
import debounce from 'lodash/debounce';
import Dialog from '@/Shared/Dialog.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TecFormSection from '@/Jetstream/FormSection.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import SelectVariantModal from '@/Shared/SelectVariantModal.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import DeliveryNoteAction from '@/Shared/DeliveryNoteAction.vue';
import { Link } from '@inertiajs/vue3';
import GatePassAction from '@/Shared/GatePassAction.vue';
import TransportRouteAction from '@/Shared/TransportRouteAction.vue';
import CameraBarcodeScanner from '@/Shared/CameraBarcodeScanner.vue';
import { DEFAULT_SCAN_SHORTCUT, matchesShortcut, formatShortcut } from '@/Shared/Rauncher/shortcut';

let rowSeq = 0;
const newKey = () => 'r' + ++rowSeq;

export default {
  props: {
    edit: Object,
    prefill: { type: Object, default: null },
    contacts: Array,
    warehouses: Array,
    history: { type: Array, default: () => [] },
    deliveryNote: { type: Object, default: null },
    gatePass: { type: Object, default: null },
    transportRoute: { type: Object, default: null },
  },

  components: {
    Link,
    Dialog,
    PageHeader,
    CheckBox,
    TextInput,
    DatePicker,
    SelectInput,
    AdminLayout,
    AutoComplete,
    LoadingButton,
    TextareaInput,
    TecFormSection,
    TrashedMessage,
    AttachmentPicker,
    TecActionMessage,
    SelectVariantModal,
    TecSecondaryButton,
    CameraBarcodeScanner,
    AnimatedNumber,
    DeliveryNoteAction,
    GatePassAction,
    TransportRouteAction,
  },

  data() {
    return {
      chosenFiles: [],
      enterStop: false,
      item: null,
      confirm: false,
      permanent: false,
      restoreConf: false,
      select_variant: false,
      unknown_variation: null,
      variantRowKey: null,
      stockSet: {}, // the errors the live stock check has put on the page, by key
      scanTooltip: { show: false, x: 0, y: 0 },
      receiving: false,
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        items: [],
        attachments: null,
        type: this.edit ? this.edit.type : 'Damage',
        details: this.edit ? this.edit.details : this.prefill ? this.prefill.details : null,
        draft: this.edit ? this.edit.draft == 1 : false,
        reference: this.edit ? this.edit.reference : null,
        material_request_id: this.edit ? this.edit.material_request_id : this.prefill ? this.prefill.material_request.id : null,
        to_warehouse_id: this.edit ? this.edit.to_warehouse_id : this.prefill ? this.prefill.to_warehouse_id : null,
        date: this.edit ? this.edit.date : this.$formatJSDate(new Date()),
        from_warehouse_id: this.edit ? this.edit.from_warehouse_id : this.prefill && this.prefill.from_warehouse_id ? this.prefill.from_warehouse_id : this.defaultWarehouseId(),
      }),
    };
  },

  computed: {
    // the Material Request this transfer is for (when it was made from one)
    mrLink() {
      if (this.edit && this.edit.material_request) return this.edit.material_request;
      return this.prefill ? this.prefill.material_request : null;
    },
    // items can only be added once the stock has a start and an end
    warehousesReady() {
      return !!(this.form.from_warehouse_id && this.form.to_warehouse_id && this.form.from_warehouse_id != this.form.to_warehouse_id);
    },
    warehouseHint() {
      if (!this.form.from_warehouse_id && !this.form.to_warehouse_id) return this.$t('Choose the From and To Project(s) / Warehouse(s) first, then add the items.');
      if (!this.form.from_warehouse_id) return this.$t('Choose the From Project(s) / Warehouse(s) first, then add the items.');
      if (!this.form.to_warehouse_id) return this.$t('Choose the To Project(s) / Warehouse(s) first, then add the items.');
      return this.$t('Please select different warehouses.');
    },
    trackWeight() {
      return this.$settings.track_weight == 1;
    },
    // the list the item pickers search in: "CODE - name", alphabetical, the first choices shown as soon as a row is clicked
    itemSearchUrl() {
      return route('items.search', { labelled: 1 });
    },
    filledCount() {
      return this.form.items.filter(i => !i.blank).length;
    },
    // Same precedence as Transfer/Index.vue's own transferStatus() and the
    // transfers.print blade — a non-draft transfer is only "Pending" until
    // approved_at, and "Approved" until received_at.
    transferStatus() {
      if (!this.edit) return 'Draft';
      if (this.edit.approval_status === 'pending') return 'Awaiting Approval';
      if (this.edit.approval_status === 'cancelled') return 'Cancelled';
      if (this.edit.draft == 1) return 'Draft';
      if (this.edit.received_at) return 'Received';
      // Released transfers (approved, or from a warehouse that needs no approval) wait here until received.
      return 'Approved';
    },
    transferStatusStyle() {
      const styles = {
        draft: { icon: 'edit', accent: '#475569' },
        'awaiting approval': { icon: 'clock', accent: '#b45309' },
        cancelled: { icon: 'cross', accent: '#be123c' },
        pending: { icon: 'clock', accent: '#b45309' },
        approved: { icon: 'tick', accent: '#1d4ed8' },
        received: { icon: 'truck', accent: '#047857' },
      };
      return styles[this.transferStatus.toLowerCase()] || styles.draft;
    },
    // Mirrors Transfer/Show.vue's identical computed exactly — same
    // deliver-transfers permission, same "Super Admin/Admin or the
    // transfer's own creator" scoping.
    // Whether the chosen source is a MAIN warehouse (its transfers need approval before stock moves).
    fromIsMain() {
      const w = (this.warehouses || []).find(x => x.id === this.form.from_warehouse_id);
      return !!w && ['main warehouse', 'main warehouses'].includes(String(w.status || '').trim().toLowerCase());
    },
    canDeliverThisTransfer() {
      if (!this.$can('deliver-transfers')) return false;
      if (this.$admin) return true;
      const currentUserId = this.$page.props.auth?.user?.id;
      return currentUserId && this.edit && this.edit.user_id === currentUserId;
    },
    userWarehouses() {
      const mine = this.$page.props.myWarehouses || [];
      return mine.length ? mine : this.warehouses;
    },
    scanShortcut() {
      return this.$page.props.auth?.user?.extra_attributes?.scan_shortcut || DEFAULT_SCAN_SHORTCUT;
    },
    totalQuantity() {
      return this.form.items.reduce((sum, item) => sum + this.itemQuantity(item), 0);
    },
  },

  watch: {
    select_variant: function (show) {
      this.unknown_variation = null;
      document.body.style.overflow = show ? 'hidden' : 'auto';
    },
    '$page.props.currentWarehouse': function (current) {
      if (!this.edit && !this.prefill && current && current.id) {
        this.form.from_warehouse_id = current.id;
      }
    },
    'form.items': {
      handler() {
        this.checkStock();
      },
      deep: true,
    },
    'form.from_warehouse_id': function () {
      this.clearFieldError('from_warehouse_id');
      this.checkStock();
    },
    'form.to_warehouse_id': function () {
      this.clearFieldError('to_warehouse_id');
    },
    'form.date': function () {
      this.clearFieldError('date');
    },
  },

  created() {
    if (!this.edit && this.prefill) {
      this.form.items = this.prefill.items.map(i => ({ ...i, id: null, _key: newKey(), blank: false }));
    }
    if (this.edit) {
      this.form.items = this.edit.items.map(i => {
        let item = {
          ...i.item,
          _key: newKey(),
          blank: false,
          id: i.id,
          details: '',
          item_id: i.item_id,
          unit_id: i.unit_id,
          weight: parseFloat(i.weight),
          quantity: parseFloat(i.quantity),
          old_quantity: parseFloat(i.quantity),
          selected: {
            serials: [],
            variations: i.variations.map(v => {
              let variations = {
                ...v,
                weight: parseFloat(v.pivot.weight),
                unit_id: v.pivot.unit_id || i.unit_id,
                quantity: parseFloat(v.pivot.quantity),
                old_quantity: parseFloat(v.pivot.quantity),
              };
              return variations;
            }),
          },
        };
        return item;
      });
    }
    // a new transfer opens with one empty row, ready for the first item
    if (!this.form.items.length) {
      this.form.items.push(this.blankRow());
    }
  },

  mounted() {
    if (!this.edit && !this.prefill) {
      this.form.from_warehouse_id = this.defaultWarehouseId();
    }
    window.addEventListener('keydown', this.onScanShortcut);
  },

  unmounted() {
    window.removeEventListener('keydown', this.onScanShortcut);
  },

  methods: {
    // the animation first, then the browser's print dialog on this document
    printThis() {
      printWithAnimation(transferPrintSheet(this, this.edit), () => printUrlInFrame(this.route('transfers.print', this.edit.id)));
    },
    approveThis() {
      this.$confirm(this.$t('Approve :ref? Stock will move from :from to :to.', { ref: this.edit.reference, from: (this.edit.from_warehouse || {}).name || '', to: (this.edit.to_warehouse || {}).name || '' })).then(ok => {
        if (ok) this.$inertia.post(this.route('transfers.approve', this.edit.id), {}, { preserveScroll: true });
      });
    },
    cancelThis() {
      this.$prompt(this.$t('Cancel :ref? You can add a reason (optional).', { ref: this.edit.reference }), { variant: 'danger', placeholder: this.$t('Reason (optional)') }).then(reason => {
        if (reason === null || reason === false || reason === undefined) return;
        this.$inertia.post(this.route('transfers.cancel', this.edit.id), { reason: typeof reason === 'string' ? reason : '' }, { preserveScroll: true });
      });
    },
    markReceived() {
      if (this.receiving || !this.edit) return;
      this.receiving = true;
      this.$inertia.post(this.route('transfers.receive', this.edit.id), {}, {
        preserveScroll: true,
        onFinish: () => { this.receiving = false; },
      });
    },
    defaultWarehouseId() {
      const current = this.$page.props.currentWarehouse;
      if (current && current.id) return current.id;
      const mine = this.$page.props.myWarehouses || [];
      if (mine.length === 1) return mine[0].id;
      if (mine.length === 0 && !this.$super && this.$user?.warehouse_id) return this.$user.warehouse_id;
      return null;
    },
    blankRow() {
      return { _key: newKey(), blank: true };
    },
    filledRows() {
      return this.form.items.filter(i => !i.blank);
    },
    hasVariations(row) {
      return !!(row.selected && row.selected.variations && row.selected.variations.length);
    },
    // the unit an item is counted in, then its sub-units
    unitChoices(row) {
      if (!row.unit) return [];
      return [row.unit, ...(row.unit.subunits || [])];
    },
    // Validation errors are keyed items.{row}.{field}; one row can carry several (item, quantity, weight, variation)
    rowError(ii, field) {
      const errors = this.$page.props.errors || {};
      return errors['items.' + ii + '.' + field] || null;
    },
    itemErrors(ii) {
      return ['item_id', 'variation_id', 'selected', 'quantity', 'weight'].map(f => this.rowError(ii, f)).filter(Boolean);
    },
    // the camera and the scan shortcut also wait for the warehouses
    openScanner() {
      if (!this.warehousesReady) {
        this.$alert(this.warehouseHint);
        return;
      }
      this.$refs.cameraScanner.show();
    },
    addItem() {
      if (!this.warehousesReady) return;
      const row = this.blankRow();
      this.form.items.push(row);
      this.clearItemsError();
      this.focusSoon('tr-item-' + row._key);
    },
    // the box of the new row takes the cursor, so the next item can be typed or scanned at once
    focusSoon(id) {
      this.$nextTick(() => {
        const el = document.getElementById(id);
        if (el) {
          el.focus();
          if (el.select) el.select();
        }
      });
    },
    // an error on a field goes as soon as the field is changed
    clearFieldError(field) {
      const errors = this.$page.props.errors;
      if (errors && errors[field]) delete errors[field];
    },
    // typing in a row's quantity or weight takes away that row's earlier complaint (the stock check, a moment later, speaks again if it still stands)
    editedRow(ii) {
      const errors = this.$page.props.errors;
      if (!errors) return;
      ['quantity', 'weight'].forEach(field => delete errors['items.' + ii + '.' + field]);
    },
    clearItemsError() {
      if (this.$page.props.errors && this.$page.props.errors.items) delete this.$page.props.errors.items;
    },
    rowIndex(key) {
      return this.form.items.findIndex(i => i._key === key);
    },
    removeItem(ii) {
      this.form.items.splice(ii, 1);
      this.shiftItemErrors(ii);
      if (!this.form.items.length) {
        this.form.items.push(this.blankRow());
      }
    },
    removeVariation(ii, vi) {
      const row = this.form.items[ii];
      row.selected.variations.splice(vi, 1);
      this.dropItemErrors(ii);
      if (!row.selected.variations.length) {
        this.removeItem(ii);
      }
    },
    // Validation errors are keyed by item index (items.{i}.quantity). When a row is
    // removed the array re-indexes, so drop the removed row's errors and shift the
    // rest down to keep them aligned with their items.
    shiftItemErrors(removedIndex) {
      const errors = this.$page.props.errors;
      if (!errors) return;
      const moved = {};
      Object.keys(errors).forEach(key => {
        const m = key.match(/^items\.(\d+)\.(.+)$/);
        if (!m) return;
        const idx = parseInt(m[1], 10);
        const value = errors[key];
        delete errors[key];
        if (idx === removedIndex) return;
        const newIdx = idx > removedIndex ? idx - 1 : idx;
        moved['items.' + newIdx + '.' + m[2]] = value;
      });
      Object.assign(errors, moved);
    },
    // Clear stale errors for a single row (e.g. one of its variations was removed).
    dropItemErrors(index) {
      const errors = this.$page.props.errors;
      if (!errors) return;
      Object.keys(errors).forEach(key => {
        if (key.startsWith('items.' + index + '.')) delete errors[key];
      });
    },
    // only what the form needs of an item found by the search (the full record carries far more, and every row is watched)
    rowFromItem(v) {
      return {
        _key: newKey(),
        blank: false,
        id: null,
        item_id: v.id,
        name: v.name,
        code: v.code,
        unit: v.unit,
        unit_id: v.unit_id,
        has_variants: v.has_variants,
        has_serials: v.has_serials,
        track_weight: v.track_weight,
        track_quantity: v.track_quantity,
        variants: v.variants,
        variations: v.variations,
        quantity: 1,
        weight: v.track_weight == 1 ? 1 : 0,
        selected: { variations: [], serials: [] },
      };
    },
    // an item chosen in the picker of an empty row
    pickItem(key, v) {
      if (!v || typeof v !== 'object') return;
      this.placeItem(v, key);
    },
    // Puts a found item on the list: an item already there gets one more, otherwise it takes the empty row `key` (or the first empty row, or a new one)
    placeItem(v, key = null) {
      if (!this.warehousesReady) {
        this.$alert(this.warehouseHint);
        return;
      }
      v.item_id = v.id;
      if (v.has_variants && v.variants && v.variants.length > 0) {
        this.item = { ...v, variants: v.variants.map(vr => ({ ...vr, selected: null })) };
        this.variantRowKey = key;
        this.select_variant = true;
        return;
      }
      const existing = this.form.items.find(i => !i.blank && i.item_id == v.id);
      if (existing) {
        existing.quantity = (parseFloat(existing.quantity) || 0) + 1;
        if (this.trackWeight && existing.track_weight == 1) existing.weight = (parseFloat(existing.weight) || 0) + 1;
        this.focusSoon('tr-qty-' + existing._key);
        return;
      }
      const row = this.rowFromItem(v);
      const at = this.slotFor(key);
      if (at >= 0) {
        row._key = this.form.items[at]._key;
        this.form.items.splice(at, 1, row);
      } else {
        this.form.items.push(row);
      }
      this.clearItemsError();
      this.focusSoon('tr-qty-' + row._key);
    },
    // the empty row to fill: the one asked for, else the first empty one, else -1 (a new row is added)
    slotFor(key) {
      let at = key ? this.rowIndex(key) : -1;
      if (at < 0 || !this.form.items[at].blank) at = this.form.items.findIndex(i => i.blank);
      return at;
    },
    openVariants(row) {
      this.item = { ...row, variants: (row.variants || []).map(vr => ({ ...vr, selected: null })) };
      this.variantRowKey = row._key;
      this.select_variant = true;
    },
    // Shapes the filled rows into the payload the server expects (used by both submit and
    // the live stock check) so variation keys match the validation logic.
    itemsPayload() {
      return this.filledRows().map(({ _key, blank, mr_remaining, ...i }) => {
        const variations = this.hasVariations(i);
        return {
          ...i,
          unit: null,
          variants: null,
          variations: null,
          // an item with variations is counted by its variations
          quantity: variations ? this.itemQuantity(i) : parseFloat(i.quantity) || 0,
          weight: variations ? this.itemWeight(i) : parseFloat(i.weight) || 0,
          unit_id: i.unit_id || null,
          selected: {
            serials: i.selected.serials && i.selected.serials.length ? i.selected.serials.map(s => s.id) : [],
            variations: i.selected.variations.map(v => ({
              weight: parseFloat(v.weight) || 0,
              variation_id: v.id,
              quantity: parseFloat(v.quantity) || 0,
              unit_id: v.unit_id || null,
              old_quantity: v.old_quantity,
            })),
          },
        };
      });
    },
    // Live availability check against the source warehouse: runs as items/quantities/
    // warehouse change so stock errors surface on selection instead of only on submit.
    checkStock: debounce(function () {
      const warehouse_id = this.form.from_warehouse_id;
      if (!warehouse_id || !this.filledCount) {
        this.applyStockErrors({});
        return;
      }
      // the server numbers the rows it was sent (the filled ones); the page numbers every row, empty ones included
      const rowOf = this.form.items.map((r, i) => (r.blank ? -1 : i)).filter(i => i >= 0);
      axios
        .post(route('stock.check'), { warehouse_id, items: this.itemsPayload() })
        .then(({ data }) => {
          const mapped = {};
          Object.keys(data.errors || {}).forEach(key => {
            const m = key.match(/^items\.(\d+)\.(.+)$/);
            mapped[m && rowOf[+m[1]] !== undefined ? 'items.' + rowOf[+m[1]] + '.' + m[2] : key] = data.errors[key];
          });
          this.applyStockErrors(mapped);
        })
        .catch(() => {});
    }, 450),
    // Replaces the previous live stock errors with the new ones. Only what the stock check itself put there is touched: a problem found
    // by the form's own check (an empty quantity, a zero) or by the server stays until the person fixes it.
    applyStockErrors(stockErrors) {
      if (!this.$page.props.errors) this.$page.props.errors = {};
      const errors = this.$page.props.errors;
      Object.keys(this.stockSet).forEach(key => {
        if (errors[key] === this.stockSet[key]) delete errors[key];
      });
      this.stockSet = {};
      Object.keys(stockErrors).forEach(key => {
        if (errors[key]) return;
        errors[key] = stockErrors[key];
        this.stockSet[key] = stockErrors[key];
      });
    },
    // a variation chosen in the window: it joins the item's row (the one the window was opened from, or the item's row already on the list), else fills an empty row
    variantSelected(meta) {
      let variation = null;
      if (typeof meta === 'object' && meta !== null) {
        variation = this.item.variations.find(v => _isEqual(v.meta, meta));
      } else {
        variation = this.item.variations.find(v => v.sku == meta);
      }
      if (!variation) {
        this.unknown_variation = this.$t('No match found for the item variation.');
        this.$page.props.flash.error = this.unknown_variation;
        return;
      }
      const track = this.trackWeight && this.item.track_weight == 1 ? 1 : 0;
      const line = () => ({ ...variation, quantity: 1, unit_id: this.item.unit_id, weight: track });
      const at = this.rowIndex(this.variantRowKey);
      let row = at >= 0 && !this.form.items[at].blank ? this.form.items[at] : this.form.items.find(i => !i.blank && i.item_id == this.item.id);
      if (row) {
        const exist = row.selected.variations.find(v => v.id == variation.id);
        if (exist) {
          exist.quantity = (parseFloat(exist.quantity) || 0) + 1;
          exist.weight = (parseFloat(exist.weight) || 0) + track;
        } else {
          row.selected.variations.push(line());
        }
      } else {
        row = this.rowFromItem(this.item);
        row.selected = { variations: [line()], serials: [] };
        const slot = this.slotFor(this.variantRowKey);
        if (slot >= 0) {
          row._key = this.form.items[slot]._key;
          this.form.items.splice(slot, 1, row);
        } else {
          this.form.items.push(row);
        }
      }
      this.clearItemsError();
      this.item = null;
      this.variantRowKey = null;
      this.select_variant = false;
    },
    itemQuantity(item) {
      if (item.selected && item.selected.variations && item.selected.variations.length) {
        return item.selected.variations.reduce((s, v) => s + (parseFloat(v.quantity) || 0), 0);
      }
      return parseFloat(item.quantity) || 0;
    },
    itemWeight(item) {
      if (item.selected && item.selected.variations && item.selected.variations.length) {
        return item.selected.variations.reduce((s, v) => s + (parseFloat(v.weight) || 0), 0);
      }
      return parseFloat(item.weight) || 0;
    },
    formatShortcut,
    onScanShortcut(e) {
      if (matchesShortcut(e, this.scanShortcut)) {
        e.preventDefault();
        this.openScanner();
      }
    },
    showScanTooltip(event) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.scanTooltip = { show: true, x: rect.left + rect.width / 2, y: rect.top - 10 };
    },
    hideScanTooltip() {
      this.scanTooltip.show = false;
    },
    // an item found by the camera scanner
    itemSelected(v) {
      this.placeItem(v);
    },
    // Replaces the old FormSection wrapper's submit handling: a keyup of Enter briefly
    // suppresses the (debounced) submit so Enter in autocompletes does not save the form.
    onFormKeyup(e) {
      if (e.code == 'Enter') {
        this.enterStop = true;
        setTimeout(() => (this.enterStop = false), 250);
      }
    },
    onFormSubmit() {
      setTimeout(() => {
        if (!this.enterStop) {
          this.submit();
        }
      }, 100);
    },
    // The checks that can be made before anything is sent, shown on the same fields (and rows) as the server's own errors.
    // Returns true when the transfer can be sent.
    validateLocal() {
      if (!this.$page.props.errors) this.$page.props.errors = {};
      const errors = this.$page.props.errors;
      Object.keys(errors).forEach(key => {
        if (/^(date|from_warehouse_id|to_warehouse_id|items(\..*)?)$/.test(key)) delete errors[key];
      });
      const found = {};
      const f = this.form;
      if (!f.date) found.date = this.$t('Choose the date of the transfer.');
      if (!f.from_warehouse_id) found.from_warehouse_id = this.$t('Choose the warehouse the stock leaves.');
      if (!f.to_warehouse_id) found.to_warehouse_id = this.$t('Choose the warehouse the stock goes to.');
      else if (f.from_warehouse_id && f.from_warehouse_id == f.to_warehouse_id) found.to_warehouse_id = this.$t('Please select different warehouses.');

      if (!this.filledCount) found.items = this.$t('Add at least one item to the list.');
      const seen = {};
      f.items.forEach((row, ii) => {
        if (row.blank) return;
        if (seen[row.item_id]) found['items.' + ii + '.item_id'] = this.$t(':item is on the list twice. Keep one row and change its quantity.', { item: row.name });
        seen[row.item_id] = true;
        const positive = n => n !== '' && n !== null && n !== undefined && !isNaN(n) && parseFloat(n) > 0;
        if (this.hasVariations(row)) {
          if (row.selected.variations.some(v => !positive(v.quantity))) found['items.' + ii + '.quantity'] = this.$t('Every variation of :item needs a quantity greater than 0.', { item: row.name });
        } else {
          if (!positive(row.quantity)) found['items.' + ii + '.quantity'] = this.$t('Enter a quantity greater than 0 for :item.', { item: row.name });
        }
      });
      if (!Object.keys(found).length) return true;
      Object.assign(errors, found);
      this.scrollToError();
      return false;
    },
    scrollToError() {
      this.$nextTick(() => {
        const el = document.querySelector('.wims-field-error, .tr-err-row, .border-red-500');
        if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      });
    },
    submit() {
      if (this.form.processing) return;
      // empty rows are dropped before the check, so the row numbers the server reports are the rows on the page
      if (this.filledCount && this.form.items.some(i => i.blank)) {
        this.form.items = this.filledRows();
      }
      if (!this.validateLocal()) return;
      this.form.attachments = this.chosenFiles;

      this.form
        .transform(data => ({
          ...data,
          items: this.itemsPayload(),
        }))
        .post(this.edit ? route('transfers.update', this.edit.id) : route('transfers.store'), {
          preserveScroll: true,
          // success: a fresh page (the saved record); validation errors: keep everything typed so far
          preserveState: 'errors',
          onError: () => this.scrollToError(),
        });
    },
    destroy() {
      this.confirm = true;
    },
    deleteItem() {
      this.form.delete(route('transfers.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreItem() {
      this.$inertia.put(this.route('transfers.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteCategoryPermanently() {
      this.form.delete(route('transfers.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.permanent = false;
    },
    deleteAttachment(id) {
      this.$inertia.form({ _method: 'DELETE' }).delete(route('media.delete', id));
    },
  },
};
</script>

<style scoped>
/* Documents card: keep the header row compact and wrap on narrow screens. */
.fm-docs { padding-top: 0.85rem; padding-bottom: 0.85rem; }
.fm-docs-head { flex-wrap: wrap; margin-bottom: 0; }

/* Sticky action bar buttons */
.fm-danger { color: #b91c1c; border-color: rgba(239, 68, 68, 0.35); }
html.dark .fm-danger { color: #fca5a5; }
.fm-save {
  padding-top: 8px !important;
  padding-bottom: 8px !important;
  border-radius: 9999px !important;
  border-color: transparent !important;
  text-transform: none !important;
  letter-spacing: normal !important;
  font-size: 0.82rem !important;
  font-weight: 700 !important;
  background: linear-gradient(145deg, #2f6fb3, #0f2540) !important;
  box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9);
}
.fm-save:disabled { opacity: 0.6; }

/* Status indicator, coloured per status via --status-accent (set inline). */
.tr-status-indicator {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.6rem 0.9rem;
  padding: 0.7rem 1rem;
  border-radius: 22px;
  background: color-mix(in srgb, var(--status-accent) 8%, #ffffff);
  border: 1px solid color-mix(in srgb, var(--status-accent) 25%, #ffffff);
}
.tr-status-icon-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.1rem;
  height: 2.1rem;
  flex: none;
  border-radius: 9999px;
  color: #fff;
  background: var(--status-accent);
  animation: tr-status-pulse 2.2s ease-in-out infinite;
}
.tr-status-text-wrap {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}
.tr-status-label {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
}
.tr-status-value {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--status-accent);
}
.tr-status-meta {
  font-size: 0.75rem;
  color: #6b7280;
  margin-left: auto;
}
.tr-mark-received-btn {
  display: inline-flex;
  align-items: center;
  margin-left: auto;
  padding: 0.4rem 0.85rem;
  border-radius: 9999px;
  font-size: 0.76rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(145deg, #34d399, #047857);
  box-shadow: 0 4px 12px -4px rgba(4, 120, 87, 0.5);
  transition: transform 0.15s ease;
}
.tr-mark-received-btn:hover { transform: translateY(-1px); }
.tr-mark-received-btn:disabled { opacity: 0.6; transform: none; }
@keyframes tr-status-pulse {
  0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--status-accent) 45%, transparent); }
  50% { box-shadow: 0 0 0 6px color-mix(in srgb, var(--status-accent) 0%, transparent); }
}
@media (prefers-reduced-motion: reduce) {
  .tr-status-icon-badge { animation: none; }
}
@media (max-width: 480px) {
  .tr-status-meta { margin-left: 0; width: 100%; }
  .tr-mark-received-btn { margin-left: 0; width: 100%; justify-content: center; }
}
html.dark .tr-status-indicator {
  background: color-mix(in srgb, var(--status-accent) 16%, #0f172a);
  border-color: color-mix(in srgb, var(--status-accent) 35%, #0f172a);
}
html.dark .tr-status-meta { color: #94a3b8; }

.tr-scan-tooltip {
  position: fixed;
  transform: translate(-50%, -100%);
  z-index: 9999;
  padding: 0.4rem 0.7rem;
  border-radius: 0.5rem;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.3;
  color: #f8fafc;
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.92));
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.25);
  pointer-events: none;
  animation: tr-tooltip-in 0.12s ease-out;
}
.tr-scan-tooltip-kbd {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  color: #93c5fd;
  font-family: inherit;
  font-variant-numeric: tabular-nums;
}
.tr-scan-tooltip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: rgba(30, 41, 59, 0.96);
}
@keyframes tr-tooltip-in {
  from { opacity: 0; transform: translate(-50%, -94%); }
  to { opacity: 1; transform: translate(-50%, -100%); }
}

.tr-cell { min-width: 0; text-align: left; }
.tr-items-table .wims-field-error { white-space: normal; overflow-wrap: anywhere; line-height: 1.35; }
.tr-cell :deep(.mb-2) { margin-bottom: 0; }
.tr-item-name { font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.tr-item-code { font-size: 0.72rem; color: var(--lg-mute); }
.tr-item-line { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
.tr-add-var { padding-top: 0.3rem; padding-bottom: 0.3rem; }
.tr-total { font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; padding: 0.55rem 0; }
.tr-hint { margin-top: 0.25rem; font-size: 0.7rem; color: var(--lg-mute); }
.tr-var-meta { color: var(--lg-ink); font-size: 0.85rem; padding-left: 0.5rem; border-left: 3px solid var(--lg-line); }
.tr-items-table th.w-12 { width: 3rem; }
.tr-items-table th.tr-col-qty { width: 15rem; }
.tr-items-table th.tr-col-unit { width: 10rem; }
.tr-err-row > td { background: rgba(254, 226, 226, 0.45) !important; }
html.dark .tr-err-row > td { background: rgba(127, 29, 29, 0.22) !important; }
@media (min-width: 768px) {
  .tr-item-td { min-width: 18rem; }
}
@media (max-width: 767.98px) {
  .tr-items-table .tr-cell { flex: 1 1 auto; }
  .tr-items-table .tr-item-line { justify-content: flex-start; }
  .tr-items-table .tr-hide-sm { display: none !important; }
  .tr-var-row { margin-left: 0.75rem; }
}

/* Field styling inherited from the old form: uppercase labels + rounded inputs. */
.apple-field-grid :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #64748b;
}
.apple-field-grid :deep(input:not([type='checkbox']):not([type='radio'])),
.apple-field-grid :deep(select),
.apple-field-grid :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: rgba(148, 163, 184, 0.4) !important;
  background: rgba(255, 255, 255, 0.85);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.apple-field-grid :deep(input:not([type='checkbox']):not([type='radio']):hover),
.apple-field-grid :deep(select:hover) {
  border-color: rgba(139, 92, 246, 0.6) !important;
}
.apple-field-grid :deep(input:not([type='checkbox']):not([type='radio']):focus),
.apple-field-grid :deep(select:focus),
.apple-field-grid :deep(textarea:focus) {
  border-color: #8b5cf6 !important;
  box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.18) !important;
  background: #ffffff;
}
html.dark .apple-field-grid :deep(label:not(:has(input[type='checkbox']))) {
  color: #94a3b8;
}
html.dark .apple-field-grid :deep(input:not([type='checkbox']):not([type='radio'])),
html.dark .apple-field-grid :deep(select),
html.dark .apple-field-grid :deep(textarea) {
  background: rgba(15, 23, 42, 0.55);
  border-color: rgba(255, 255, 255, 0.12) !important;
  color: #e2e8f0;
}

.txn-history-row {
  border-bottom: 1px solid var(--lg-line);
  padding-bottom: 10px;
}
.txn-history-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
</style>
