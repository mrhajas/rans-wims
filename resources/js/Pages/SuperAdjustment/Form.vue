<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Super Adjustment') })">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="adjustments"
        :title="edit ? $t('edit_x', { x: $t('Super Adjustment') }) + ' (' + edit.reference + ')' : $t('create_x', { x: $t('Super Adjustment') })"
        :subtitle="$t('Record a physical stock count for a project/warehouse. The system compares it against current stock and, once Super Admin approves, creates the correcting Adjustment(s).')"
      >
        <Link :href="route('super-adjustments.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />
          <span>{{ $t('Back') }}</span>
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <div v-if="edit && edit.status !== 'Draft'" class="lg-note lg-note-amber mb-3">
          <icons name="exclamation" class="w-4 h-4" />
          <span>{{ edit.status === 'Approved' ? $t('This count is Approved. Saving returns it to Draft and reverses the stock changes it applied; it then needs approving again.') : $t('This count was Rejected. Saving returns it to Draft so it can be approved again.') }}</span>
        </div>
        <!-- Details -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Details') }}</div>
              <div class="lg-card-sub">{{ $t('Where, when and why the count was taken') }}</div>
            </div>
          </div>
          <div class="lg-fields sa-fields">
            <div>
              <tec-label :value="$t('Project(s) / Warehouse(s)')" />
              <auto-complete
                id="warehouse"
                class="mt-1"
                :suggestions="warehouses"
                v-model="form.warehouse_id"
                :placeholder="$t('Search project / warehouse…')"
                :error="$page.props.errors.warehouse_id"
                :disable="!!edit"
              />
            </div>
            <div>
              <tec-label :value="$t('Date')" />
              <text-input disabled readonly class="mt-1 block w-full" :model-value="$date(today)" />
            </div>
            <div>
              <tec-label :value="$t('Responsible User')" />
              <text-input disabled readonly class="mt-1 block w-full" :model-value="responsibleUser?.name" />
            </div>
            <div class="lg-span-all">
              <tec-label for="reason" :value="$t('Reason')" />
              <auto-complete
                id="reason"
                class="mt-1"
                freeform
                :suggestions="reasonOptions"
                v-model="form.reason"
                :placeholder="$t('Select or type a reason…')"
                :error="$page.props.errors.reason"
              />
            </div>
            <div class="lg-span-all">
              <tec-label for="details" :value="$t('Details / Remarks')" />
              <textarea id="details" class="sa-textarea mt-1 block w-full" rows="3" v-model="form.details" :placeholder="$t('Any additional context about this physical count…')"></textarea>
              <input-error :message="$page.props.errors.details" class="mt-1" />
            </div>
          </div>
        </section>

        <!-- Physical count -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <div class="lg-card-title">{{ $t('Physical Stock Count') }}</div>
              <div class="lg-card-sub">{{ $t('Add the items you counted and enter the physical quantity for each') }}</div>
            </div>
          </div>

          <!-- an open Adjustment Session of this Project / Warehouse: this count is added to it and the "items not in this count" question is off -->
          <div v-if="openSession" class="lg-note lg-note-amber sa-session">
            <icons name="clock" class="w-4 h-4" />
            <span>{{ $t('Adjustment Session :r is open for this Project / Warehouse. This count is added to it, and the items you do not count here are not asked about: the session keeps track of them and you decide when you end it.', { r: openSession.reference }) }} <a :href="openSession.url" target="_blank" rel="noopener" class="sa-session-link">{{ $t('Open the session') }}</a></span>
          </div>
          <div v-if="!form.warehouse_id" class="lg-note lg-note-blue">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Select a Project / Warehouse above before adding items.') }}</span>
          </div>
          <template v-else>
            <div class="sa-scan-zone">
              <div class="sa-scan-top">
                <div class="sa-scan-hint">
                  <icons name="search" class="w-4 h-4" />
                  <span>{{ $t('Search items to count and add them to the list') }}</span>
                </div>
                <button type="button" class="lg-btn lg-btn-soft sa-import" :disabled="importing" @click="importFromWarehouse">
                  <icons :name="importing ? 'cog' : 'download'" class="w-4 h-4" :class="{ 'animate-spin': importing }" />
                  <span>{{ importing ? $t('Importing…') : $t('Import from Warehouse') }}</span>
                </button>
              </div>
              <div v-if="importNote" class="sa-import-note" :class="{ 'is-err': importErr }" role="status"><icons :name="importErr ? 'exclamation' : 'tick'" class="w-4 h-4" /><span>{{ importNote }}</span></div>
              <div class="lg-scan">
                <auto-complete
                  json
                  keep-focus
                  reset-search
                  id="add-item"
                  @update:model-value="itemSelected"
                  :suggestions="route('items.search')"
                  :placeholder="$t('Search items to count…')"
                />
              </div>
            </div>

            <stat-tiles v-if="form.items.length" :items="statItems" class="sa-tiles" />

            <div class="lg-table-wrap">
              <table class="lg-table lg-responsive">
                <thead>
                  <tr>
                    <th>{{ $t('Item') }}</th>
                    <th>{{ $t('Unit') }}</th>
                    <th class="num">{{ $t('System Stock') }}</th>
                    <th class="num">{{ $t('Physical Count') }}</th>
                    <th class="sa-clear-th">
                      <label class="sa-clear-all" :title="$t('Tick to clear every listed item from stock')">
                        <input type="checkbox" class="sa-clear-cb" :checked="allCleared" :disabled="!form.items.length" :aria-label="$t('Clear all listed items from stock')" @change="toggleAll($event.target.checked)" />
                        <span>{{ $t('Clear From Stock') }}</span>
                      </label>
                    </th>
                    <th class="num">{{ $t('Variance') }}</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, ri) in form.items" :key="row.item_id" class="sa-row">
                    <td :data-label="$t('Item')">
                      <span class="lg-main">{{ row.name }}</span> <span class="sa-code">({{ row.code }})</span>
                      <span
                        v-if="row.auto_cleared"
                        class="lg-chip lg-chip-red sa-cleared-tag"
                        tabindex="0"
                        @mouseenter="showTooltip($event, $t('Auto-added at zero via Clear, not physically counted'))"
                        @mouseleave="hideTooltip"
                        @focus="showTooltip($event, $t('Auto-added at zero via Clear, not physically counted'))"
                        @blur="hideTooltip"
                      >{{ $t('Cleared') }}</span>
                    </td>
                    <td :data-label="$t('Unit')" class="sa-muted">{{ row.unit_name }}</td>
                    <td class="num" :data-label="$t('System Stock')">
                      <span v-if="row.loadingStock" class="inline-flex items-center gap-1.5 sa-muted">
                        <icons name="cog" class="w-4 h-4 animate-spin inline-flex" />
                        <span class="text-xs">{{ $t('Loading') }}…</span>
                      </span>
                      <span v-else class="tabular-nums">{{ $number(row.system_quantity) }}</span>
                    </td>
                    <td class="num" :data-label="$t('Physical Count')">
                      <input type="number" step="0.0001" min="0" v-model="row.physical_quantity" class="sa-qty-input" :disabled="!!row.auto_cleared" />
                    </td>
                    <td class="sa-clear-td" :data-label="$t('Clear From Stock')">
                      <label class="sa-clear-lbl" :class="{ 'is-on': row.auto_cleared }">
                        <input type="checkbox" class="sa-clear-cb" :checked="!!row.auto_cleared" :aria-label="$t('Clear From Stock')" @change="toggleClear(row, $event.target.checked)" />
                        <span class="sa-clear-txt">{{ $t('Clear From Stock') }}</span>
                      </label>
                    </td>
                    <td class="num" :data-label="$t('Variance')">
                      <span class="lg-chip sa-variance" :class="variancePillClass(row)">
                        <icons :name="varianceIcon(row)" class="w-3 h-3 inline-flex" />
                        {{ varianceLabel(row) }}
                      </span>
                    </td>
                    <td class="lg-sticky-end" :data-label="$t('Actions')">
                      <div class="lg-actions">
                        <button type="button" @click="form.items.splice(ri, 1)" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')"><icons name="trash" /></button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!form.items.length" class="lg-empty-row">
                    <td class="lg-empty" colspan="7">{{ $t('No items added yet.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-if="$page.props.errors.items" class="text-sm text-red-600 mt-2">{{ $page.props.errors.items }}</div>
          </template>
        </section>

        <!-- Attachments -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Attachments') }}</div>
              <div class="lg-card-sub">{{ $t('Optional supporting files such as count sheets or photos') }}</div>
            </div>
          </div>

          <ul class="sa-att-list" v-if="existingAttachments.length">
            <li v-for="a in existingAttachments" :key="a.id" class="sa-att-item">
              <span class="inline-flex items-center gap-2 min-w-0">
                <icons :name="a.is_image ? 'image' : 'doc'" class="w-4 h-4 inline-flex sa-muted flex-none" />
                <span class="truncate text-sm">{{ a.title }}</span>
              </span>
              <span class="flex items-center gap-1.5 flex-none">
                <a :href="a.preview_url" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" :aria-label="$t('Preview')"><icons name="view" /></a>
                <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" /></a>
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" @click="deleteExistingAttachment(a)" :aria-label="$t('Remove')">
                  <icons name="trash" />
                </button>
              </span>
            </li>
          </ul>

          <input id="sa-attachments" type="file" multiple class="sr-only" @change="onAttachmentsChange" accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip" />
          <label for="sa-attachments" class="sa-file-pick">
            <span class="inline-flex items-center gap-2 text-sm font-semibold">
              <icons name="clip" class="w-4 h-4 inline-flex sa-pick-ico" />
              <span>{{ $t('Select files') }}</span>
            </span>
            <span class="lg-chip lg-chip-blue">
              {{ form.attachments.length ? form.attachments.length + ' ' + $t('file(s)') : $t('No files chosen') }}
            </span>
          </label>

          <ul class="sa-att-list sa-att-new" v-if="form.attachments.length">
            <li v-for="(file, fi) in form.attachments" :key="fi" class="sa-att-item sa-file-row">
              <span class="inline-flex items-center gap-2 min-w-0">
                <icons name="doc" class="w-3.5 h-3.5 inline-flex sa-muted flex-none" />
                <span class="truncate text-xs">{{ fileLabel(file) }}</span>
              </span>
              <span class="flex items-center gap-1.5 flex-none">
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" @click="previewNewAttachment(file)" :aria-label="$t('Preview')"><icons name="view" /></button>
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" @click="removeAttachment(fi)" :aria-label="$t('Remove')"><icons name="cross" /></button>
              </span>
            </li>
          </ul>
        </section>

        <!-- Sticky action bar -->
        <div class="lg-actionbar print:hidden">
          <div class="lg-actionbar-note">
            {{ form.items.length }} {{ $t('Items') }}
            <span v-if="!edit && $super" class="sa-auto-note">&middot; {{ $t('As Super Admin your count is approved on submit and stock is updated.') }}</span>
          </div>
          <div class="lg-actionbar-btns">
            <Link :href="route('super-adjustments.index')" class="lg-btn lg-btn-soft">
              <icons name="cross" class="w-4 h-4 inline-flex" />
              {{ $t('Cancel') }}
            </Link>
            <loading-button type="submit" :loading="form.processing || checkingUncounted" :disabled="form.processing || checkingUncounted || !form.items.length" class="lg-btn lg-btn-green sa-submit">
              <icons v-if="!form.processing && !checkingUncounted" name="tick" class="w-4 h-4 inline-flex mr-1.5" />
              {{ $t(edit ? 'Save' : $super ? 'Submit & Approve' : 'Submit for Review') }}
            </loading-button>
          </div>
        </div>
      </form>

      <!-- Uncounted-items check: items still stocked in this warehouse that
           weren't added to the physical count. Surfaced only when the check
           (fired right before submit) finds any — see checkUncountedThenSubmit(). -->
      <div v-if="uncountedModal.open" class="sa-modal-overlay" @click.self="uncountedModal.open = false">
        <div class="sa-modal-card">
          <div class="flex items-start gap-3 mb-4">
            <span class="lg-card-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5 inline-flex" /></span>
            <div>
              <h3 class="sa-modal-title">{{ $t('Some stocked items were not counted') }}</h3>
              <p class="sa-modal-sub">
                {{ uncountedModal.items.length }} {{ $t('item(s) currently have stock in this Project / Warehouse but were not added to this physical count. Choose how to handle them.') }}
              </p>
            </div>
          </div>

          <div class="sa-modal-list">
            <div v-for="u in uncountedModal.items" :key="u.item_id" class="sa-modal-row">
              <span class="truncate">{{ u.name }} <span class="sa-code">({{ u.code }})</span></span>
              <span class="tabular-nums sa-muted flex-none">{{ $number(u.system_quantity) }} {{ u.unit_name }}</span>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row justify-end gap-2 mt-5">
            <button type="button" class="lg-btn lg-btn-soft" @click="uncountedModal.open = false">
              <icons name="out" class="w-4 h-4 inline-flex" />
              {{ $t('Exit') }}
            </button>
            <button type="button" class="lg-btn lg-btn-dark" @click="resolveUncounted(false)">
              <icons name="lock-closed" class="w-4 h-4 inline-flex" />
              {{ $t('Preserve') }}
            </button>
            <button type="button" class="lg-btn lg-btn-red" @click="resolveUncounted(true)">
              <icons name="trash" class="w-4 h-4 inline-flex" />
              {{ $t('Clear') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Single shared tooltip, teleported to <body> and positioned via
         getBoundingClientRect (same mechanism as Show.vue) so it escapes the
         items table's own overflow-x-auto clipping and stays within the
         viewport regardless of horizontal scroll position. -->
    <Teleport to="body">
      <div
        v-if="tooltip.visible"
        class="sa-teleport-tooltip"
        :class="'sa-tooltip-' + tooltip.placement"
        :style="{ top: tooltip.top + 'px', left: tooltip.left + 'px', width: tooltip.width + 'px' }"
      >
        {{ tooltip.text }}
        <span class="sa-tooltip-arrow" :style="{ left: tooltip.arrowLeft + 'px' }"></span>
      </div>
    </Teleport>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import InputError from '@/Jetstream/InputError.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { Link, AdminLayout, TecLabel, TextInput, InputError, AutoComplete, LoadingButton, PageHeader, StatTiles },

  props: {
    edit: Object,
    warehouses: Array,
    today: String,
    responsibleUser: Object,
    existingItems: { type: Array, default: () => [] },
    existingAttachments: { type: Array, default: () => [] },
    openSessions: { type: Object, default: () => ({}) },
    preselectWarehouseId: { type: Number, default: null },
  },

  data() {
    return {
      reasonOptions: [
        this.$t('Periodic Stock Count'),
        this.$t('Cycle Count'),
        this.$t('Annual Inventory Audit'),
        this.$t('Damaged Goods Found'),
        this.$t('Theft / Loss Suspected'),
        this.$t('Data Entry Correction'),
        this.$t('Received Stock Not Recorded'),
        this.$t('Issued Stock Not Recorded'),
        this.$t('Warehouse Relocation Count'),
        this.$t('Spot Check'),
      ],
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        warehouse_id: this.edit ? this.edit.warehouse_id : (this.preselectWarehouseId && (this.warehouses || []).some(w => Number(w.value ?? w.id) === this.preselectWarehouseId) ? this.preselectWarehouseId : null),
        reason: this.edit ? this.edit.reason : '',
        details: this.edit ? [this.edit.details, this.edit.remarks].filter(Boolean).join('\n\n') : '',
        items: this.existingItems.map(i => ({
          item_id: i.item_id,
          name: i.name,
          code: i.code,
          unit_id: i.unit_id,
          unit_name: i.unit_name,
          system_quantity: i.system_quantity,
          physical_quantity: i.physical_quantity,
          loadingStock: false,
          auto_cleared: !!i.auto_cleared,
        })),
        attachments: [],
      }),
      importing: false,
      importNote: '',
      importErr: false,
      checkingUncounted: false,
      uncountedModal: { open: false, items: [] },
      tooltip: { visible: false, text: '', top: 0, left: 0, arrowLeft: 0, width: 260, placement: 'above' },
    };
  },

  mounted() {
    // Capture phase so this also fires for scrolling inside the items
    // table's own overflow-x-auto container, not just the window itself.
    window.addEventListener('scroll', this.hideTooltip, true);
    window.addEventListener('resize', this.hideTooltip);
  },

  beforeUnmount() {
    window.removeEventListener('scroll', this.hideTooltip, true);
    window.removeEventListener('resize', this.hideTooltip);
  },

  computed: {
    // the open Adjustment Session of the chosen Project / Warehouse, if there is one
    openSession() { return this.form.warehouse_id ? (this.openSessions || {})[this.form.warehouse_id] || null : null; },
    allCleared() {
      return this.form.items.length > 0 && this.form.items.every(r => r.auto_cleared);
    },
    statItems() {
      const rows = this.form.items.filter(r => !r.loadingStock);
      const counts = { add: 0, sub: 0, match: 0 };
      rows.forEach(r => {
        const v = this.variance(r);
        if (Math.abs(v) < 0.0001) counts.match++;
        else if (v > 0) counts.add++;
        else counts.sub++;
      });
      return [
        { icon: 'collection', tone: 'navy', label: this.$t('Items'), value: this.form.items.length },
        { icon: 'tick', tone: 'slate', label: this.$t('Match'), value: counts.match },
        { icon: 'up', tone: 'emerald', label: this.$t('Additions'), value: counts.add },
        { icon: 'down', tone: 'rose', label: this.$t('Subtractions'), value: counts.sub },
      ];
    },
  },

  watch: {
    // Warehouse is only locked once editing an existing Super Adjustment
    // (:disable="!!edit") — while creating, a user can add items under one
    // warehouse, then switch warehouses, and every already-added row's
    // system_quantity/variance preview was left showing the stale,
    // previous warehouse's stock. persist() on the backend already
    // recomputes fresh at submit time either way, but the on-screen
    // preview the user reviews before submitting should match it.
    'form.warehouse_id'() {
      if (this.edit) return;
      this.form.items.forEach(row => this.refreshStock(row));
    },
  },

  methods: {
    // Positions the single shared, teleported tooltip against whichever
    // trigger fired it — clamped horizontally to the viewport and flipped
    // to appear below the trigger when there isn't room above, so it always
    // stays fully visible regardless of table scroll position or screen size.
    showTooltip(event, text) {
      const rect = event.currentTarget.getBoundingClientRect();
      const width = Math.min(260, Math.max(180, window.innerWidth - 24));
      const gap = 10;
      const placement = rect.top < 110 ? 'below' : 'above';
      let left = rect.left + rect.width / 2 - width / 2;
      left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
      const triggerCenter = rect.left + rect.width / 2;
      const arrowLeft = Math.max(12, Math.min(width - 12, triggerCenter - left));

      this.tooltip = {
        visible: true,
        text,
        top: placement === 'above' ? rect.top - gap : rect.bottom + gap,
        left,
        arrowLeft,
        width,
        placement,
      };
    },
    hideTooltip() {
      this.tooltip.visible = false;
    },
    async itemSelected(v) {
      if (this.form.items.find(i => i.item_id === v.id)) {
        return;
      }
      const row = {
        item_id: v.id,
        name: v.name,
        code: v.code,
        unit_id: v.unit_id,
        unit_name: v.unit?.name || '',
        system_quantity: 0,
        physical_quantity: 0,
        loadingStock: true,
      };
      this.form.items.push(row);
      await this.refreshStock(row);
    },
    // "Import from Warehouse": REPLACES the whole count list with every stocked item of the chosen warehouse, each line
    // pre-filled with its system quantity (so nothing changes until the user edits a count or ticks Clear From Stock).
    // The list is emptied first, so an item can never appear twice; if there is already something in the list the user is
    // asked before it is thrown away. Stock that is negative in the system is counted at 0 (a count can never be negative).
    async importFromWarehouse() {
      if (!this.form.warehouse_id || this.importing) return;
      if (this.form.items.length) {
        const ok = await this.$confirm(this.$t('Replace the :n item(s) in the list below with the stock of this Project / Warehouse? Any counts you entered will be lost.', { n: this.form.items.length }), { variant: 'danger' });
        if (!ok) return;
      }
      const warehouseId = this.form.warehouse_id;
      this.importing = true;
      this.importNote = '';
      this.importErr = false;
      try {
        const { data } = await axios.get(this.route('super-adjustments.warehouse-stock'), { params: { warehouse_id: warehouseId } });
        if (warehouseId !== this.form.warehouse_id) return; // the warehouse was changed while loading: ignore this answer
        const seen = new Set();
        const rows = [];
        let negative = 0;
        data.forEach(u => {
          if (seen.has(u.item_id)) return; // one line per item, whatever the server sent
          seen.add(u.item_id);
          if (u.system_quantity < 0) negative++;
          rows.push({
            item_id: u.item_id, name: u.name, code: u.code, unit_id: u.unit_id, unit_name: u.unit_name,
            system_quantity: u.system_quantity, physical_quantity: Math.max(0, u.system_quantity), loadingStock: false, auto_cleared: false,
          });
        });
        this.form.items = rows; // reset, then fill
        this.importNote = rows.length
          ? this.$t(':n item(s) imported from this Project / Warehouse. Change a count, or tick Clear From Stock for items to remove.', { n: rows.length }) + (negative ? ' ' + this.$t(':n item(s) have negative stock in the system and start at a count of 0.', { n: negative }) : '')
          : this.$t('This Project / Warehouse has no stock to import.');
      } catch (e) {
        this.importErr = true;
        this.importNote = (e.response && e.response.data && e.response.data.message) || this.$t('Could not import the stock. Please try again.');
      } finally {
        this.importing = false;
      }
    },
    // Clear From Stock: the item is counted at zero and flagged as cleared (the same flag the "uncounted items → Clear"
    // choice uses), so approval subtracts the whole system quantity. Unticking puts the previous count back.
    toggleClear(row, on) {
      if (on) {
        row._prevCount = row.physical_quantity;
        row.physical_quantity = 0;
        row.auto_cleared = true;
      } else {
        row.auto_cleared = false;
        row.physical_quantity = row._prevCount !== undefined && row._prevCount !== null ? row._prevCount : row.system_quantity;
      }
    },
    toggleAll(on) {
      this.form.items.forEach(r => { if (!!r.auto_cleared !== on) this.toggleClear(r, on); });
    },
    async refreshStock(row) {
      row.loadingStock = true;
      try {
        const { data } = await axios.get(this.route('super-adjustments.item-stock'), {
          params: { item_id: row.item_id, warehouse_id: this.form.warehouse_id },
        });
        row.system_quantity = data.system_quantity;
      } finally {
        row.loadingStock = false;
      }
    },
    variance(row) {
      return Number((parseFloat(row.physical_quantity || 0) - parseFloat(row.system_quantity || 0)).toFixed(4));
    },
    varianceLabel(row) {
      const v = this.variance(row);
      if (Math.abs(v) < 0.0001) return this.$t('Match');
      return (v > 0 ? '+' : '') + this.$number(v) + ' ' + this.$t(v > 0 ? 'Addition' : 'Subtraction');
    },
    variancePillClass(row) {
      const v = this.variance(row);
      if (Math.abs(v) < 0.0001) return 'lg-chip-slate';
      return v > 0 ? 'lg-chip-green' : 'lg-chip-red';
    },
    varianceIcon(row) {
      const v = this.variance(row);
      if (Math.abs(v) < 0.0001) return 'tick';
      return v > 0 ? 'up' : 'down';
    },
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
      if (!file || typeof file !== 'object') return '';
      return file.name || 'Attachment';
    },
    previewNewAttachment(file) {
      window.open(URL.createObjectURL(file), '_blank', 'noopener');
    },
    async deleteExistingAttachment(attachment) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) return;
      this.$inertia.delete(attachment.delete_url, { preserveScroll: true });
    },
    // Fires on form submit — checks for items still stocked in this
    // warehouse that weren't added to the count, and asks the user how to
    // handle them, before actually posting (see uncountedModal / resolveUncounted).
    async submit() {
      if (!this.form.warehouse_id) {
        this.doSubmit();
        return;
      }
      this.checkingUncounted = true;
      try {
        const { data } = await axios.post(this.route('super-adjustments.uncounted-items'), {
          warehouse_id: this.form.warehouse_id,
          item_ids: this.form.items.map(i => i.item_id),
        });
        if (data.length) {
          this.uncountedModal = { open: true, items: data };
        } else {
          this.doSubmit();
        }
      } finally {
        this.checkingUncounted = false;
      }
    },
    resolveUncounted(clearFromStock) {
      if (clearFromStock) {
        this.uncountedModal.items.forEach(u => {
          this.form.items.push({
            item_id: u.item_id,
            name: u.name,
            code: u.code,
            unit_id: u.unit_id,
            unit_name: u.unit_name,
            system_quantity: u.system_quantity,
            physical_quantity: 0,
            loadingStock: false,
            auto_cleared: true,
          });
        });
      }
      this.uncountedModal.open = false;
      this.doSubmit();
    },
    async doSubmit() {
      // A Super Admin's own count is approved the moment it is submitted, which changes real stock — say so first.
      if (this.$super && !this.edit) {
        const ok = await this.$confirm(this.$t('Submit and approve this Super Adjustment now? Stock will be updated straight away.'), { confirmText: this.$t('Submit & Approve') });
        if (!ok) return;
      }
      this.form
        .transform(data => ({
          ...data,
          items: data.items.map(i => ({ item_id: i.item_id, unit_id: i.unit_id, physical_quantity: i.physical_quantity, auto_cleared: !!i.auto_cleared })),
        }))
        .post(this.edit ? this.route('super-adjustments.update', this.edit.id) : this.route('super-adjustments.store'), {
          forceFormData: true,
          preserveScroll: true,
        });
    },
  },
};
</script>

<style scoped>
.sa-auto-note { color: var(--lg-faint); font-weight: 600; }
.sa-muted { color: var(--lg-mute); }
.sa-code { color: var(--lg-faint); font-weight: 500; }

/* Field styling (labels + rounded inputs), dark-mode safe */
.sa-fields :deep(label) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.sa-fields :deep(input:not([type='checkbox']):not([type='radio'])),
.sa-fields :deep(select),
.sa-textarea {
  border-radius: 0.85rem !important;
  border: 1px solid var(--lg-line);
  border-color: rgba(148, 163, 184, 0.4) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sa-textarea { padding: 0.6rem 0.75rem !important; font-size: 0.875rem; }
.sa-fields :deep(input:disabled) { background: var(--lg-soft) !important; color: var(--lg-mute); }
.sa-fields :deep(input:not([type='checkbox']):not([type='radio']):focus),
.sa-fields :deep(select:focus),
.sa-textarea:focus {
  outline: none;
  border-color: #60a5fa !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18) !important;
}

/* Scan / search zone */
.sa-scan-zone {
  padding: 0.8rem;
  margin-bottom: 1rem;
  border-radius: 16px;
  border: 1px dashed color-mix(in srgb, var(--lg-navy) 30%, transparent);
  background: var(--lg-soft);
}
.sa-scan-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 12px; margin-bottom: 0.6rem; }
.sa-scan-top .sa-scan-hint { margin-bottom: 0; }
.sa-import { white-space: nowrap; }
.sa-import-note { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 0.6rem; padding: 0.55rem 0.8rem; border-radius: 0.8rem; background: rgba(5, 150, 105, 0.1); color: #047857; font-size: 0.8rem; font-weight: 600; }
.sa-import-note.is-err { background: rgba(190, 18, 60, 0.1); color: #be123c; }
/* maroon "Clear From Stock" checkbox */
.sa-clear-cb { appearance: none; -webkit-appearance: none; width: 22px; height: 22px; flex: none; margin: 0; border: 2px solid #7f1d1d; border-radius: 6px; background: transparent; cursor: pointer; display: inline-grid; place-content: center; transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.12s ease; }
.sa-clear-cb::after { content: ''; width: 11px; height: 6px; border-left: 2.5px solid #fff; border-bottom: 2.5px solid #fff; transform: rotate(-45deg) scale(0); margin-top: -2px; transition: transform 0.12s ease; }
.sa-clear-cb:checked { background: #7f1d1d; box-shadow: 0 6px 12px -6px #7f1d1d; }
.sa-clear-cb:checked::after { transform: rotate(-45deg) scale(1); }
.sa-clear-cb:hover:not(:disabled) { transform: scale(1.08); }
.sa-clear-cb:focus-visible { outline: 3px solid rgba(127, 29, 29, 0.35); outline-offset: 2px; }
.sa-clear-cb:disabled { opacity: 0.45; cursor: not-allowed; }
.sa-clear-lbl, .sa-clear-all { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 700; }
.sa-clear-lbl .sa-clear-txt { display: none; color: #7f1d1d; font-size: 0.78rem; }
.sa-clear-lbl.is-on .sa-clear-txt { color: #7f1d1d; }
.sa-clear-all { flex-direction: column; gap: 4px; }
.sa-clear-all span { color: #7f1d1d; font-size: 0.68rem; letter-spacing: 0.03em; text-transform: uppercase; white-space: normal; line-height: 1.15; max-width: 5.2rem; text-align: center; }
.sa-clear-td, .sa-clear-th { text-align: center; }
.sa-clear-td { width: 5.2rem; }
.sa-row:has(.sa-clear-cb:checked) { background: rgba(127, 29, 29, 0.06); }
.sa-row:has(.sa-clear-cb:checked) .lg-main { text-decoration: line-through; text-decoration-color: rgba(127, 29, 29, 0.55); }
.sa-qty-input:disabled { opacity: 0.5; cursor: not-allowed; }
.sa-scan-hint {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 0.6rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--lg-mute);
}
.sa-tiles { margin-bottom: 1rem; }

/* Count table */
.sa-qty-input {
  width: 7rem;
  max-width: 100%;
  text-align: right;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  padding: 0.4rem 0.6rem;
  border-radius: 0.7rem;
  border: 1px solid rgba(148, 163, 184, 0.45);
  background: var(--lg-card);
  color: var(--lg-ink);
}
.sa-qty-input:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
}
.sa-variance { gap: 0.3rem; text-transform: none; }
.sa-cleared-tag {
  margin-left: 0.4rem;
  padding: 0.05rem 0.55rem;
  font-size: 0.66rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  vertical-align: middle;
  cursor: help;
  outline: none;
}

/* Attachments */
.sa-att-list {
  margin-bottom: 0.9rem;
  border: 1px solid var(--lg-line);
  border-radius: 16px;
  background: var(--lg-soft);
  list-style: none;
  padding: 0;
}
.sa-att-new { margin-top: 0.9rem; margin-bottom: 0; }
.sa-att-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0.85rem;
  color: var(--lg-ink);
  border-top: 1px solid var(--lg-line);
}
.sa-att-item:first-child { border-top: 0; }
.sa-file-pick {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  min-height: 44px;
  padding: 0.55rem 0.9rem;
  border-radius: 14px;
  border: 1px dashed var(--lg-line);
  background: var(--lg-soft);
  color: var(--lg-ink);
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
@media (min-width: 640px) { .sa-file-pick { width: auto; min-width: 20rem; } }
.sa-file-pick:hover { border-color: #60a5fa; background: var(--lg-card); }
.sa-pick-ico { color: #2563eb; }
.sa-file-row { animation: sa-file-in 0.18s ease-out; }
@keyframes sa-file-in {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Submit button */
.sa-submit {
  padding-top: 8px !important;
  padding-bottom: 8px !important;
  border-radius: 9999px !important;
  border-color: transparent !important;
  text-transform: none !important;
  letter-spacing: normal !important;
  font-size: 0.82rem !important;
  font-weight: 800 !important;
}
.sa-submit:disabled { opacity: 0.55; }

/* Teleported to <body> — fixed positioning computed in JS (showTooltip), see
   Show.vue for the identical mechanism and rationale. */
.sa-teleport-tooltip {
  position: fixed;
  z-index: 9999;
  background: #1f2937;
  color: #f8fafc;
  font-size: 0.72rem;
  font-weight: 500;
  line-height: 1.42;
  text-align: left;
  padding: 0.55rem 0.7rem;
  border-radius: 0.6rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.3);
  pointer-events: none;
}
.sa-tooltip-above { transform: translateY(-100%); }
.sa-tooltip-below { transform: translateY(0); }
.sa-tooltip-arrow {
  position: absolute;
  width: 0;
  height: 0;
  border: 5px solid transparent;
  margin-left: -5px;
}
.sa-tooltip-above .sa-tooltip-arrow { top: 100%; border-top-color: #1f2937; }
.sa-tooltip-below .sa-tooltip-arrow { bottom: 100%; border-bottom-color: #1f2937; }
@media (max-width: 480px) {
  .sa-teleport-tooltip { font-size: 0.68rem; }
}

/* Uncounted-items modal */
.sa-modal-overlay {
  position: fixed; inset: 0; z-index: 99999;
  display: flex; align-items: center; justify-content: center; padding: 1rem;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(2px);
  animation: sa-modal-fade 0.15s ease-out;
}
@keyframes sa-modal-fade { from { opacity: 0; } to { opacity: 1; } }
.sa-modal-card {
  width: 100%; max-width: 32rem; max-height: 85vh; overflow-y: auto;
  background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 22px; padding: 1.4rem;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
  animation: sa-modal-in 0.18s ease-out;
}
html.dark .sa-modal-card { background: #0f172a; }
@keyframes sa-modal-in {
  from { opacity: 0; transform: translateY(8px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.sa-modal-title { font-size: 1rem; font-weight: 800; color: var(--lg-ink); }
.sa-modal-sub { margin-top: 0.25rem; font-size: 0.82rem; color: var(--lg-mute); }
.sa-modal-list {
  max-height: 14rem; overflow-y: auto;
  border: 1px solid var(--lg-line); border-radius: 14px;
}
.sa-modal-row {
  display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
  padding: 0.5rem 0.85rem; font-size: 0.85rem; color: var(--lg-ink);
  border-top: 1px solid var(--lg-line);
}
.sa-modal-row:first-child { border-top: none; }

@media (prefers-reduced-motion: reduce) {
  .sa-file-row { animation: none; }
}
.sa-session { margin-bottom: 10px; } .sa-session-link { font-weight: 800; text-decoration: underline; }
</style>
