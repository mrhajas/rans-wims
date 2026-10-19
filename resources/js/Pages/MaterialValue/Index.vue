<template>
  <admin-layout :title="$t('Material Value')">
    <div class="lg-page mv-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Material Value')" :subtitle="$t('Select materials or categories, choose warehouses, and see exactly what your stock is worth.')">
        <template v-if="hasSearched">
          <button type="button" class="lg-btn lg-btn-dark" @click="openOutput('print')">
            <icons name="printer" class="w-4 h-4" />
            <span>{{ $t('Print') }}</span>
          </button>
          <button type="button" class="lg-btn lg-btn-primary" @click="openOutput('pdf')">
            <icons name="download" class="w-4 h-4" />
            <span>{{ $t('Download') }}</span>
          </button>
        </template>
      </page-header>

      <!-- Summary tiles (from the report already loaded) -->
      <stat-tiles v-if="hasSearched" :items="statItems" />

      <!-- Filters -->
      <div class="lg-card lg-filters mv-filters">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Report Filters') }}</div>
            <div class="lg-card-sub">{{ $t('Choose what to value, then calculate.') }}</div>
          </div>
        </div>

        <!-- Step 1: materials -->
        <div class="mv-step">
          <div class="mv-step-row">
            <span class="mv-step-label"><span class="mv-step-num">1</span>{{ $t('Choose materials') }}</span>
            <div class="flex flex-wrap items-center gap-1.5">
              <button type="button" class="mv-tab" :class="{ 'mv-tab-active': mode === 'items' }" @click="setMode('items')">
                <icons name="bag" class="w-3.5 h-3.5" />
                {{ $t('By Item') }}
              </button>
              <button type="button" class="mv-tab" :class="{ 'mv-tab-active': mode === 'categories' }" @click="setMode('categories')">
                <icons name="folder" class="w-3.5 h-3.5" />
                {{ $t('By Category') }}
              </button>
              <button type="button" class="mv-reset-btn" @click="resetAll">
                <icons name="refresh" class="w-3.5 h-3.5" />
                {{ $t('Reset') }}
              </button>
            </div>
          </div>

          <div class="relative mb-2">
            <icons name="search" class="w-4 h-4 mv-search-ico absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              v-model="materialSearch"
              type="text"
              :placeholder="mode === 'items' ? $t('Search materials...') : $t('Search categories...')"
              class="mv-search-input w-full pl-9 pr-3 py-2 text-sm rounded-lg"
            />
          </div>

          <div class="mv-picker-list">
            <label v-for="m in visibleMaterials" :key="m.id" class="mv-picker-row">
              <input type="checkbox" class="sr-only" :checked="isMaterialSelected(m.id)" @change="toggleMaterial(m.id)" />
              <span class="mv-picker-check"><icons name="tick" class="w-3 h-3" /></span>
              <span class="truncate">{{ m.name }}</span>
              <span v-if="m.code" class="mv-picker-code">{{ m.code }}</span>
            </label>
            <div v-if="!filteredMaterials.length" class="lg-empty">
              {{ $t('No matches.') }}
            </div>
          </div>
          <div v-if="filteredMaterials.length > visibleMaterials.length" class="lg-subtext px-1 mt-1.5">
            {{ $t('Showing') }} {{ visibleMaterials.length }} {{ $t('of') }} {{ filteredMaterials.length }}, {{ $t('type to narrow down.') }}
          </div>

          <div v-if="selectedMaterialsList.length" class="flex flex-wrap gap-1.5 mt-3">
            <span v-for="m in selectedMaterialsList" :key="m.id" class="mv-chip">
              {{ m.name }}
              <button type="button" class="mv-chip-x" @click="toggleMaterial(m.id)"><icons name="cross" class="w-2.5 h-2.5" /></button>
            </span>
          </div>

          <div class="flex items-center justify-between mt-2">
            <span class="mv-count">{{ selectedMaterialIds.length }} {{ $t('selected') }}</span>
            <button v-if="selectedMaterialIds.length" type="button" class="mv-link-btn" @click="clearMaterials">
              {{ $t('Clear selection') }}
            </button>
          </div>
        </div>

        <!-- Step 2: warehouses -->
        <div class="mv-step">
          <div class="mv-step-row">
            <span class="mv-step-label"><span class="mv-step-num">2</span>{{ $t('Choose warehouses') }}</span>
          </div>

          <div class="flex flex-wrap items-center gap-1.5 mb-3">
            <button
              v-for="status in warehouseStatuses"
              :key="status"
              type="button"
              class="mv-quick-btn"
              @click="selectByStatus(status)"
            >
              <icons name="building" class="w-3.5 h-3.5" />
              {{ $t('All') }} {{ $t(status) }}
              <span class="mv-quick-count">{{ warehouseCountByStatus(status) }}</span>
            </button>
            <button type="button" class="mv-quick-btn mv-quick-btn-all" @click="selectAllWarehouses">
              <icons name="tick" class="w-3.5 h-3.5" />
              {{ $t('Select All') }}
            </button>
            <button v-if="selectedWarehouseIds.length" type="button" class="mv-quick-btn mv-quick-btn-clear" @click="clearWarehouses">
              <icons name="cross" class="w-3.5 h-3.5" />
              {{ $t('Clear') }}
            </button>
          </div>

          <div class="mv-warehouse-grid">
            <label v-for="w in warehouses" :key="w.id" class="mv-warehouse-chip" :class="{ 'mv-warehouse-chip-active': isWarehouseSelected(w.id) }">
              <input type="checkbox" class="sr-only" :checked="isWarehouseSelected(w.id)" @change="toggleWarehouse(w.id)" />
              <icons name="tick" class="w-3.5 h-3.5 mv-warehouse-chip-tick" />
              <span class="min-w-0">
                <span class="block truncate">{{ w.name }}</span>
                <span class="mv-warehouse-chip-status inline-flex items-center gap-1">
                  <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="w.completed_at ? 'bg-red-500' : 'bg-emerald-500'"></span>
                  {{ w.completed_at ? $t('Completed') : $t(w.status) }}
                </span>
              </span>
            </label>
            <div v-if="!warehouses.length" class="lg-empty col-span-full">
              {{ $t('No warehouses available to you.') }}
            </div>
          </div>

          <div v-if="selectedWarehousesList.length" class="flex flex-wrap gap-1.5 mt-3">
            <span v-for="w in selectedWarehousesList" :key="w.id" class="mv-chip mv-chip-green">
              {{ w.name }}
              <button type="button" class="mv-chip-x" @click="toggleWarehouse(w.id)"><icons name="cross" class="w-2.5 h-2.5" /></button>
            </span>
          </div>

          <div class="mv-count mt-2">{{ selectedWarehouseIds.length }} {{ $t('warehouse(s) selected') }}</div>
        </div>

        <div class="flex items-center justify-end">
          <button type="button" class="lg-btn lg-btn-green mv-search-btn" :disabled="loading" @click="search">
            <icons v-if="!loading" name="search" class="w-4 h-4" />
            <svg v-else class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            {{ loading ? $t('Calculating...') : $t('Calculate Value') }}
          </button>
        </div>
      </div>

      <!-- Empty state before first search -->
      <div v-if="!hasSearched" class="lg-card text-center py-14">
        <span class="lg-card-ico lg-ico-slate mx-auto mb-3"><icons name="chart-bar" class="w-5 h-5" /></span>
        <p class="lg-card-sub">{{ $t('Choose materials and warehouses above, then calculate.') }}</p>
      </div>

      <!-- Results -->
      <template v-else>
        <div v-if="!localReport.warehouses.length" class="lg-card text-center py-14">
          <span class="lg-card-ico lg-ico-amber mx-auto mb-3"><icons name="info" class="w-5 h-5" /></span>
          <p class="lg-card-sub">{{ $t('No stock found for that selection.') }}</p>
        </div>

        <div v-else class="space-y-4">
          <div v-for="wh in localReport.warehouses" :key="wh.warehouse_id" class="mv-warehouse-card">
            <button type="button" class="mv-warehouse-card-header w-full text-left" @mousedown.prevent @click="toggleExpand(wh.warehouse_id)">
              <span class="lg-card-ico lg-ico-emerald"><icons name="building" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <div class="lg-card-title truncate">{{ wh.warehouse_name }}</div>
                <span class="lg-chip lg-chip-green mt-1">{{ $t(wh.warehouse_status) }}</span>
              </div>
              <div class="text-right mr-2">
                <div class="lg-subtext">{{ $t('Subtotal') }}</div>
                <div class="mv-subtotal">Tsh {{ formatMoney(wh.subtotal) }}</div>
              </div>
              <icons name="chevron-down" class="w-4 h-4 mv-chevron shrink-0 transition-transform" :class="{ 'rotate-180': isExpanded(wh.warehouse_id) }" />
            </button>

            <div v-if="isExpanded(wh.warehouse_id)" class="mv-warehouse-card-body">
              <div class="lg-table-wrap mv-scroll">
                <table class="lg-table lg-responsive mv-sticky">
                  <thead>
                    <tr>
                      <th>{{ $t('Item') }}</th>
                      <th class="num">{{ $t('Quantity') }}</th>
                      <th class="text-center">{{ $t('Unit') }}</th>
                      <th class="num">{{ $t('Unit Price') }}</th>
                      <th class="num">{{ $t('Value') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="it in wh.items" :key="it.item_id" class="is-click" @click="openItemDetails(it.item_id)">
                      <td :data-label="$t('Item')">
                        <div class="lg-main">{{ it.name }}</div>
                        <div v-if="it.code" class="lg-subtext">{{ it.code }}</div>
                      </td>
                      <td class="num" :data-label="$t('Quantity')">{{ formatQty(it.quantity) }}</td>
                      <td class="text-center" :data-label="$t('Unit')">
                        <span v-if="it.unit" class="lg-chip lg-chip-slate">{{ it.unit }}</span>
                      </td>
                      <td class="num" :data-label="$t('Unit Price')">{{ formatMoney(it.unit_price) }}</td>
                      <td class="num lg-main" :data-label="$t('Value')">{{ formatMoney(it.value) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Item Details Modal -->
      <transition name="mv-modal" v-if="showItemModal">
        <div class="mv-overlay fixed inset-0 flex items-center justify-center z-50 p-4" @click.self="closeItemModal">
          <div class="mv-modal-panel max-w-2xl w-full max-h-[85vh] overflow-y-auto">
            <div class="mv-modal-header">
              <span class="lg-card-ico lg-ico-emerald"><icons name="bag" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h3 class="lg-card-title truncate">{{ selectedItemDetails.item_name }}</h3>
                <p class="lg-card-sub">{{ $t('Item Code') }}: {{ selectedItemDetails.item_code || '-' }}</p>
              </div>
              <button type="button" class="mv-modal-close-btn" @click="closeItemModal">
                <icons name="cross" class="w-4 h-4" />
              </button>
            </div>

            <div class="mv-modal-body">
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="mv-stat-box">
                  <span class="mv-stat-label">{{ $t('Unit') }}</span>
                  <span class="mv-stat-value">{{ selectedItemDetails.unit }}</span>
                </div>
                <div class="mv-stat-box">
                  <span class="mv-stat-label">{{ $t('Unit Price') }}</span>
                  <span class="mv-stat-value">Tsh {{ formatMoney(selectedItemDetails.price) }}</span>
                </div>
                <div class="mv-stat-box">
                  <span class="mv-stat-label">{{ $t('Total Quantity') }}</span>
                  <span class="mv-stat-value">{{ formatQty(selectedItemDetails.total_quantity) }}</span>
                </div>
                <div class="mv-stat-box mv-stat-box-green">
                  <span class="mv-stat-label">{{ $t('Total Value') }}</span>
                  <span class="mv-stat-value">Tsh {{ formatMoney(selectedItemDetails.total_value) }}</span>
                </div>
              </div>

              <div>
                <h4 class="mv-field-label mb-2"><icons name="building" class="w-3.5 h-3.5" />{{ $t('Warehouse Stock Details') }}</h4>
                <div class="lg-table-wrap">
                  <table class="lg-table">
                    <thead>
                      <tr>
                        <th>{{ $t('Warehouse') }}</th>
                        <th class="num">{{ $t('Quantity') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(detail, idx) in selectedItemDetails.warehouse_details" :key="idx">
                        <td class="lg-main">{{ detail.warehouse }}</td>
                        <td class="num">{{ formatQty(detail.quantity) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="mv-modal-footer">
              <button type="button" class="lg-btn lg-btn-soft" @click="closeItemModal">{{ $t('Close') }}</button>
            </div>
          </div>
        </div>
      </transition>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  props: {
    filters: { type: Object, default: () => ({ item_ids: [], category_ids: [], warehouse_ids: [] }) },
    report: { type: Object, default: () => ({ grand_total: 0, item_count: 0, warehouse_count: 0, warehouses: [] }) },
    warehouses: { type: Array, default: () => [] },
    warehouseStatuses: { type: Array, default: () => [] },
    availableItems: { type: Array, default: () => [] },
    availableCategories: { type: Array, default: () => [] },
  },

  components: { AdminLayout, PageHeader, StatTiles },

  data() {
    return {
      mode: (this.filters.category_ids || []).length > 0 && !(this.filters.item_ids || []).length ? 'categories' : 'items',
      materialSearch: '',
      selectedItemIds: [...(this.filters.item_ids || [])],
      selectedCategoryIds: [...(this.filters.category_ids || [])],
      selectedWarehouseIds: [...(this.filters.warehouse_ids || [])],
      expandedWarehouses: (this.report.warehouses || []).map(w => w.warehouse_id),
      localReport: { ...this.report },
      loading: false,
      hasSearched: (this.filters.item_ids || []).length > 0 || (this.filters.category_ids || []).length > 0,
      showItemModal: false,
      selectedItemDetails: {
        item_id: null,
        item_name: '',
        item_code: '',
        unit: '',
        price: 0,
        total_quantity: 0,
        total_value: 0,
        warehouse_details: [],
      },
    };
  },

  computed: {
    // Summary tiles only re-present figures the report already carries; no
    // value is recomputed here.
    statItems() {
      const r = this.localReport || {};
      const lines = (r.warehouses || []).reduce((n, w) => n + ((w.items || []).length), 0);
      return [
        { icon: 'cash', tone: 'emerald', label: this.$t('Grand Total Value'), value: 'Tsh ' + this.formatMoney(r.grand_total) },
        { icon: 'bag', tone: 'indigo', label: this.$t('Materials'), value: Number(r.item_count || 0) },
        { icon: 'building', tone: 'sky', label: this.$t('Warehouses'), value: Number(r.warehouse_count || 0) },
        { icon: 'list', tone: 'slate', label: this.$t('Stock Lines'), value: lines },
      ];
    },
    selectedMaterialIds() {
      return this.mode === 'items' ? this.selectedItemIds : this.selectedCategoryIds;
    },
    materialList() {
      return this.mode === 'items' ? this.availableItems : this.availableCategories;
    },
    filteredMaterials() {
      const q = this.materialSearch.trim().toLowerCase();
      if (!q) {
        return this.materialList;
      }
      return this.materialList.filter(m => m.name.toLowerCase().includes(q) || (m.code || '').toLowerCase().includes(q));
    },
    visibleMaterials() {
      // Rendering the full unfiltered catalog (hundreds of rows) into the
      // scrollable picker inflated this panel's layout height by tens of
      // thousands of pixels even though the box itself stayed visually
      // clipped to max-height — capping the render count is both the fix
      // and just better UX (nobody wants to scroll 700+ unfiltered rows).
      return this.filteredMaterials.slice(0, 50);
    },
    selectedMaterialsList() {
      return this.materialList.filter(m => this.selectedMaterialIds.includes(m.id));
    },
    selectedWarehousesList() {
      return this.warehouses.filter(w => this.selectedWarehouseIds.includes(w.id));
    },
  },

  watch: {
    report(newVal) {
      this.localReport = newVal;
    },
  },

  methods: {
    setMode(mode) {
      if (this.mode === mode) return;
      this.mode = mode;
      this.materialSearch = '';
    },
    resetAll() {
      this.mode = 'items';
      this.materialSearch = '';
      this.selectedItemIds = [];
      this.selectedCategoryIds = [];
      this.selectedWarehouseIds = [];
      this.expandedWarehouses = [];
      this.hasSearched = false;
      this.loading = false;
      this.$inertia.get(route('material-value.index'));
    },
    isMaterialSelected(id) {
      return this.selectedMaterialIds.includes(id);
    },
    toggleMaterial(id) {
      const list = this.mode === 'items' ? 'selectedItemIds' : 'selectedCategoryIds';
      this[list] = this[list].includes(id) ? this[list].filter(v => v !== id) : [...this[list], id];
    },
    clearMaterials() {
      this.selectedItemIds = [];
      this.selectedCategoryIds = [];
    },
    warehouseCountByStatus(status) {
      return this.warehouses.filter(w => w.status === status).length;
    },
    isWarehouseSelected(id) {
      return this.selectedWarehouseIds.includes(id);
    },
    toggleWarehouse(id) {
      this.selectedWarehouseIds = this.selectedWarehouseIds.includes(id)
        ? this.selectedWarehouseIds.filter(v => v !== id)
        : [...this.selectedWarehouseIds, id];
    },
    selectByStatus(status) {
      const ids = this.warehouses.filter(w => w.status === status).map(w => w.id);
      this.selectedWarehouseIds = [...new Set([...this.selectedWarehouseIds, ...ids])];
    },
    selectAllWarehouses() {
      this.selectedWarehouseIds = this.warehouses.map(w => w.id);
    },
    clearWarehouses() {
      this.selectedWarehouseIds = [];
    },
    isExpanded(id) {
      return this.expandedWarehouses.includes(id);
    },
    toggleExpand(id) {
      this.expandedWarehouses = this.expandedWarehouses.includes(id)
        ? this.expandedWarehouses.filter(v => v !== id)
        : [...this.expandedWarehouses, id];
    },
    params(output = null) {
      const params = {};
      if (this.selectedItemIds.length) params.item_ids = this.selectedItemIds.join(',');
      if (this.selectedCategoryIds.length) params.category_ids = this.selectedCategoryIds.join(',');
      if (this.selectedWarehouseIds.length) params.warehouse_ids = this.selectedWarehouseIds.join(',');
      if (output) params.output = output;
      return params;
    },
    search() {
      this.loading = true;
      this.hasSearched = true;
      this.expandedWarehouses = [];
      this.localReport = { grand_total: 0, item_count: 0, warehouse_count: 0, warehouses: [] };
      this.$inertia.get(route('material-value.index'), this.params(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
          this.loading = false;
          this.expandedWarehouses = (this.report.warehouses || []).map(w => w.warehouse_id);
        },
      });
    },
    openOutput(output) {
      window.open(route('material-value.index', this.params(output)), '_blank');
    },
    formatMoney(value) {
      return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    formatQty(value) {
      const n = Number(value || 0);
      return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    async openItemDetails(itemId) {
      try {
        const response = await fetch(route('material-value.item-details', itemId));
        if (response.ok) {
          this.selectedItemDetails = await response.json();
          this.showItemModal = true;
        }
      } catch (error) {
        console.error('Error fetching item details:', error);
      }
    },
    closeItemModal() {
      this.showItemModal = false;
      this.selectedItemDetails = {
        item_id: null,
        item_name: '',
        item_code: '',
        unit: '',
        price: 0,
        total_quantity: 0,
        total_value: 0,
        warehouse_details: [],
      };
    },
  },
};
</script>

<style scoped>
/* Filter steps ------------------------------------------------------- */
.mv-step { padding: 14px 0 16px; border-top: 1px dashed var(--lg-line); }
.mv-step:last-of-type { margin-bottom: 4px; }
.mv-step-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.mv-step-label { display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 800; color: var(--lg-ink); }
.mv-step-num { display: inline-flex; align-items: center; justify-content: center; height: 22px; width: 22px; border-radius: 9999px; background: linear-gradient(145deg, #34d399, #047857); color: #fff; font-size: 0.7rem; }
.mv-count { font-size: 0.74rem; font-weight: 700; color: var(--lg-mute); }
.mv-link-btn { font-size: 0.74rem; font-weight: 800; color: #1d4ed8; }
.mv-link-btn:hover { text-decoration: underline; }
html.dark .mv-link-btn { color: #93c5fd; }
.mv-search-ico { color: var(--lg-faint); }

.mv-tab { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: all 0.15s ease; }
.mv-tab:hover { border-color: #a7f3d0; color: #047857; }
.mv-tab-active { background: linear-gradient(145deg, #34d399, #047857); border-color: transparent; color: #fff; box-shadow: 0 8px 14px -8px rgba(4, 120, 87, 0.8); }
.mv-tab-active:hover { color: #fff; }
.mv-reset-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; color: var(--lg-faint); background: transparent; border: 1px solid var(--lg-line); transition: all 0.15s ease; }
.mv-reset-btn:hover { border-color: #fca5a5; color: #dc2626; }

.mv-search-input { color: var(--lg-ink); border: 1px solid var(--lg-line); background: var(--lg-soft); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.mv-search-input:focus { outline: none; border-color: #6ee7b7; box-shadow: 0 0 0 3px rgba(110, 231, 183, 0.3); }

.mv-picker-list { max-height: 220px; overflow-y: auto; border: 1px solid var(--lg-line); border-radius: 14px; background: var(--lg-soft); padding: 4px; }
/* position:relative contains the .sr-only checkbox so focusing it scrolls this pane, not the window (see Role/Form.vue .permission-chip). */
.mv-picker-row { position: relative; display: flex; align-items: center; gap: 8px; padding: 7px 9px; border-radius: 10px; font-size: 0.84rem; color: var(--lg-ink); cursor: pointer; transition: background 0.12s ease; }
.mv-picker-row:hover { background: rgba(16, 185, 129, 0.09); }
.mv-picker-check { display: inline-flex; align-items: center; justify-content: center; height: 17px; width: 17px; border-radius: 5px; border: 1.5px solid #cbd5e1; color: transparent; flex: none; transition: all 0.15s ease; }
.mv-picker-row:has(input:checked) .mv-picker-check { background: #059669; border-color: #059669; color: #fff; }
.mv-picker-row:has(input:checked) { background: rgba(5, 150, 105, 0.09); }
.mv-picker-code { margin-left: auto; font-size: 0.7rem; color: var(--lg-faint); flex: none; }

.mv-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 4px 3px 10px; border-radius: 9999px; background: rgba(5, 150, 105, 0.1); color: #047857; border: 1px solid rgba(110, 231, 183, 0.5); font-size: 0.74rem; font-weight: 700; }
.mv-chip-green { background: rgba(13, 148, 136, 0.1); color: #0f766e; border-color: rgba(94, 234, 212, 0.5); }
.mv-chip-x { display: inline-flex; align-items: center; justify-content: center; height: 16px; width: 16px; min-height: 0; padding: 0; border-radius: 9999px; background: rgba(255, 255, 255, 0.6); color: inherit; transition: background 0.15s ease; }
.mv-chip-x:hover { background: rgba(220, 38, 38, 0.15); color: #dc2626; }
html.dark .mv-chip { background: rgba(16, 185, 129, 0.18); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.3); }
html.dark .mv-chip-green { background: rgba(20, 184, 166, 0.18); color: #5eead4; border-color: rgba(20, 184, 166, 0.3); }
html.dark .mv-chip-x { background: rgba(255, 255, 255, 0.1); }

.mv-quick-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 9999px; font-size: 0.74rem; font-weight: 700; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: all 0.15s ease; }
.mv-quick-btn:hover { border-color: #a7f3d0; background: rgba(16, 185, 129, 0.08); color: #047857; }
.mv-quick-count { font-size: 0.68rem; color: var(--lg-mute); background: rgba(15, 23, 42, 0.07); padding: 0 6px; border-radius: 9999px; }
html.dark .mv-quick-count { background: rgba(255, 255, 255, 0.1); }
.mv-quick-btn-all { background: rgba(79, 70, 229, 0.08); color: #4338ca; border-color: rgba(165, 180, 252, 0.5); }
.mv-quick-btn-clear { background: rgba(239, 68, 68, 0.08); color: #dc2626; border-color: rgba(252, 165, 165, 0.5); }
html.dark .mv-quick-btn-all { color: #c7d2fe; }
html.dark .mv-quick-btn-clear { color: #fca5a5; }

.mv-warehouse-grid { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: 8px; max-height: 260px; overflow-y: auto; padding: 2px; }
@media (min-width: 640px) { .mv-warehouse-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1024px) { .mv-warehouse-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.mv-warehouse-chip { position: relative; display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 12px; font-size: 0.84rem; font-weight: 600; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; transition: all 0.15s ease; }
.mv-warehouse-chip:hover { border-color: #a7f3d0; }
.mv-warehouse-chip-tick { color: transparent; flex: none; transition: color 0.15s ease; }
.mv-warehouse-chip-active { background: rgba(5, 150, 105, 0.1); border-color: #6ee7b7; }
.mv-warehouse-chip-active .mv-warehouse-chip-tick { color: #059669; }
.mv-warehouse-chip-status { display: block; font-size: 0.68rem; font-weight: 600; color: var(--lg-faint); }

.mv-search-btn { padding: 10px 22px; font-size: 0.82rem; }
.mv-search-btn:disabled { cursor: wait; }

/* Result cards ------------------------------------------------------- */
.mv-warehouse-card { background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 22px; box-shadow: var(--lg-shadow); overflow: hidden; }
.mv-warehouse-card-header { display: flex; align-items: center; gap: 12px; padding: 14px 18px; }
.mv-subtotal { font-size: 1rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.mv-chevron { color: var(--lg-faint); }
.mv-warehouse-card-body { padding: 12px 16px 16px; border-top: 1px solid var(--lg-line); }

/* Sticky header inside a scrollable table */
.mv-sticky th { position: sticky; top: 0; z-index: 2; background-color: #f1f5f9; }
html.dark .mv-sticky th { background-color: #17213a; }
@media (min-width: 768px) { .mv-scroll { max-height: 60vh; overflow: auto; } }

/* Modal -------------------------------------------------------------- */
.mv-modal-enter-active, .mv-modal-leave-active { transition: opacity 0.2s ease; }
.mv-modal-enter-from, .mv-modal-leave-to { opacity: 0; }
.mv-modal-enter-to, .mv-modal-leave-from { opacity: 1; }
.mv-overlay { background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
.mv-modal-panel { background: #ffffff; border: 1px solid var(--lg-line); border-radius: 24px; box-shadow: 0 30px 70px -16px rgba(15, 23, 42, 0.45); overflow: hidden auto; }
html.dark .mv-modal-panel { background: #0f172a; }
.mv-modal-header { display: flex; align-items: center; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--lg-line); background: linear-gradient(135deg, rgba(209, 250, 229, 0.55), rgba(255, 255, 255, 0)); }
html.dark .mv-modal-header { background: linear-gradient(135deg, rgba(16, 185, 129, 0.14), rgba(15, 23, 42, 0)); }
.mv-modal-close-btn { display: inline-flex; align-items: center; justify-content: center; height: 32px; width: 32px; min-height: 0; padding: 0; border-radius: 10px; flex: none; background: rgba(28, 63, 102, 0.09); color: var(--lg-navy); transition: background 0.15s ease; }
.mv-modal-close-btn:hover { background: rgba(28, 63, 102, 0.17); }
html.dark .mv-modal-close-btn { color: #bfdbfe; }
.mv-modal-body { padding: 20px 22px; display: flex; flex-direction: column; gap: 20px; }
.mv-modal-footer { display: flex; justify-content: flex-end; padding: 12px 22px 18px; }
.mv-field-label { display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800; color: #047857; text-transform: uppercase; letter-spacing: 0.06em; }
html.dark .mv-field-label { color: #6ee7b7; }
.mv-stat-box { background: var(--lg-soft); border: 1px solid var(--lg-line); border-radius: 14px; padding: 10px 12px; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.mv-stat-box-green { background: rgba(5, 150, 105, 0.09); border-color: rgba(110, 231, 183, 0.5); }
.mv-stat-label { font-size: 0.64rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--lg-faint); }
.mv-stat-value { font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
</style>
