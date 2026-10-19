<template>
  <admin-layout :title="$t('Movement Value Report')">
    <div class="lg-page mv-page px-4 md:px-0">
      <page-header icon="truck" :title="$t('Movement Value Report')" :subtitle="$t('Track what moved to a project, from a Main Warehouse or from a supplier, with quantity and value.')">
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
            <div class="lg-card-sub">{{ $t('Choose what moved, where from and where to, then calculate.') }}</div>
          </div>
        </div>

        <!-- Step 0: mode -->
        <div class="mv-step">
          <div class="mv-step-row">
            <span class="mv-step-label"><span class="mv-step-num">1</span>{{ $t('Movement type') }}</span>
          </div>
          <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" class="mv-tab" :class="{ 'mv-tab-active': movementMode === 'warehouse' }" @click="setMovementMode('warehouse')">
              <icons name="building" class="w-3.5 h-3.5" />
              {{ $t('Warehouse → Project') }}
            </button>
            <button type="button" class="mv-tab" :class="{ 'mv-tab-active': movementMode === 'supplier' }" @click="setMovementMode('supplier')">
              <icons name="users" class="w-3.5 h-3.5" />
              {{ $t('Supplier → Project') }}
            </button>
            <button type="button" class="mv-reset-btn" @click="resetAll">
              <icons name="refresh" class="w-3.5 h-3.5" />
              {{ $t('Reset') }}
            </button>
          </div>
        </div>

        <!-- Step 1: materials -->
        <div class="mv-step">
          <div class="mv-step-row">
            <span class="mv-step-label"><span class="mv-step-num">2</span>{{ $t('Choose materials') }}</span>
            <div class="flex flex-wrap items-center gap-1.5">
              <button type="button" class="mv-tab" :class="{ 'mv-tab-active': materialMode === 'items' }" @click="setMaterialMode('items')">
                <icons name="bag" class="w-3.5 h-3.5" />
                {{ $t('By Item') }}
              </button>
              <button type="button" class="mv-tab" :class="{ 'mv-tab-active': materialMode === 'categories' }" @click="setMaterialMode('categories')">
                <icons name="folder" class="w-3.5 h-3.5" />
                {{ $t('By Category') }}
              </button>
            </div>
          </div>

          <div class="relative mb-2">
            <icons name="search" class="w-4 h-4 mv-search-ico absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              v-model="materialSearch"
              type="text"
              :placeholder="materialMode === 'items' ? $t('Search materials...') : $t('Search categories...')"
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

          <div v-if="selectedMaterialsList.length" class="flex flex-wrap gap-1.5 mt-3">
            <span v-for="m in selectedMaterialsList" :key="m.id" class="mv-chip">
              {{ m.name }}
              <button type="button" class="mv-chip-x" @click="toggleMaterial(m.id)"><icons name="cross" class="w-2.5 h-2.5" /></button>
            </span>
          </div>

          <div class="mv-count mt-2">{{ selectedMaterialIds.length }} {{ $t('selected') }}</div>
        </div>

        <!-- Step 2: source -->
        <div class="mv-step">
          <div class="mv-step-row">
            <span class="mv-step-label"><span class="mv-step-num">3</span>{{ movementMode === 'warehouse' ? $t('Source: Main Warehouse(s)') : $t('Source: Supplier(s)') }}</span>
          </div>

          <template v-if="movementMode === 'warehouse'">
            <div class="flex flex-wrap items-center gap-1.5 mb-3">
              <button type="button" class="mv-quick-btn mv-quick-btn-all" @click="selectAllSources">
                <icons name="tick" class="w-3.5 h-3.5" />
                {{ $t('All Main Warehouses') }}
              </button>
              <button v-if="selectedSourceIds.length" type="button" class="mv-quick-btn mv-quick-btn-clear" @click="clearSources">
                <icons name="cross" class="w-3.5 h-3.5" />
                {{ $t('Clear') }}
              </button>
            </div>
            <div class="mv-warehouse-grid">
              <label v-for="w in mainWarehouses" :key="w.id" class="mv-warehouse-chip" :class="{ 'mv-warehouse-chip-active': isSourceSelected(w.id) }">
                <input type="checkbox" class="sr-only" :checked="isSourceSelected(w.id)" @change="toggleSource(w.id)" />
                <icons name="tick" class="w-3.5 h-3.5 mv-warehouse-chip-tick" />
                <span class="min-w-0 inline-flex items-center gap-1.5">
                  <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="w.completed_at ? 'bg-red-500' : 'bg-emerald-500'"></span>
                  <span class="block truncate">{{ w.name }}</span>
                </span>
              </label>
              <div v-if="!mainWarehouses.length" class="lg-empty col-span-full">
                {{ $t('No Main Warehouses available to you.') }}
              </div>
            </div>
            <div class="mv-count mt-2">
              {{ selectedSourceIds.length ? `${selectedSourceIds.length} ${$t('selected')}` : $t('None selected. Defaults to all Main Warehouses.') }}
            </div>
          </template>

          <template v-else>
            <div class="relative mb-2">
              <icons name="search" class="w-4 h-4 mv-search-ico absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input v-model="supplierSearch" type="text" :placeholder="$t('Search suppliers...')" class="mv-search-input w-full pl-9 pr-3 py-2 text-sm rounded-lg" />
            </div>
            <div class="mv-picker-list">
              <label v-for="c in visibleSuppliers" :key="c.id" class="mv-picker-row">
                <input type="checkbox" class="sr-only" :checked="isSourceSelected(c.id)" @change="toggleSource(c.id)" />
                <span class="mv-picker-check"><icons name="tick" class="w-3 h-3" /></span>
                <span class="truncate">{{ c.name }}</span>
              </label>
              <div v-if="!filteredSuppliers.length" class="lg-empty">{{ $t('No matches.') }}</div>
            </div>
            <div v-if="selectedSourcesList.length" class="flex flex-wrap gap-1.5 mt-3">
              <span v-for="c in selectedSourcesList" :key="c.id" class="mv-chip">
                {{ c.name }}
                <button type="button" class="mv-chip-x" @click="toggleSource(c.id)"><icons name="cross" class="w-2.5 h-2.5" /></button>
              </span>
            </div>
            <div class="mv-count mt-2">
              {{ selectedSourceIds.length ? `${selectedSourceIds.length} ${$t('selected')}` : $t('None selected. Defaults to all suppliers.') }}
            </div>
          </template>
        </div>

        <!-- Step 3: destination -->
        <div class="mv-step">
          <div class="mv-step-row">
            <span class="mv-step-label"><span class="mv-step-num">4</span>{{ $t('Destination: Project(s) / Site(s)') }}</span>
          </div>

          <div class="flex flex-wrap items-center gap-1.5 mb-3">
            <button
              v-for="status in projectWarehouseStatuses"
              :key="status"
              type="button"
              class="mv-quick-btn"
              @click="selectDestinationsByStatus(status)"
            >
              <icons name="building" class="w-3.5 h-3.5" />
              {{ $t('All') }} {{ $t(status) }}
              <span class="mv-quick-count">{{ destinationCountByStatus(status) }}</span>
            </button>
            <button type="button" class="mv-quick-btn mv-quick-btn-all" @click="selectAllDestinations">
              <icons name="tick" class="w-3.5 h-3.5" />
              {{ $t('Select All Projects') }}
            </button>
            <button v-if="selectedDestinationIds.length" type="button" class="mv-quick-btn mv-quick-btn-clear" @click="clearDestinations">
              <icons name="cross" class="w-3.5 h-3.5" />
              {{ $t('Clear') }}
            </button>
          </div>

          <div class="mv-warehouse-grid">
            <label v-for="w in projectWarehouses" :key="w.id" class="mv-warehouse-chip" :class="{ 'mv-warehouse-chip-active': isDestinationSelected(w.id) }">
              <input type="checkbox" class="sr-only" :checked="isDestinationSelected(w.id)" @change="toggleDestination(w.id)" />
              <icons name="tick" class="w-3.5 h-3.5 mv-warehouse-chip-tick" />
              <span class="min-w-0">
                <span class="block truncate">{{ w.name }}</span>
                <span class="mv-warehouse-chip-status inline-flex items-center gap-1">
                  <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="w.completed_at ? 'bg-red-500' : 'bg-emerald-500'"></span>
                  {{ w.completed_at ? $t('Completed') : $t(w.status) }}
                </span>
              </span>
            </label>
            <div v-if="!projectWarehouses.length" class="lg-empty col-span-full">
              {{ $t('No project warehouses available to you.') }}
            </div>
          </div>

          <div class="mv-count mt-2">{{ selectedDestinationIds.length }} {{ $t('project(s) selected') }}</div>
        </div>

        <!-- Step 4: date range -->
        <div class="mv-step">
          <div class="mv-step-row">
            <span class="mv-step-label"><span class="mv-step-num">5</span>{{ $t('Date range') }}</span>
          </div>
          <div class="flex flex-wrap items-end gap-3">
            <date-picker v-model="dateFrom" :label="$t('From')" :disabled="entireProject" />
            <date-picker v-model="dateTo" :label="$t('To')" :disabled="entireProject" />
            <label class="mv-entire-toggle">
              <input type="checkbox" v-model="entireProject" class="sr-only" />
              <span class="mv-entire-check"><icons name="tick" class="w-3 h-3" /></span>
              {{ $t('Entire project (no date limit)') }}
            </label>
          </div>
        </div>

        <div class="flex items-center justify-end">
          <button type="button" class="lg-btn lg-btn-green mv-search-btn" :disabled="loading" @click="search">
            <icons v-if="!loading" name="search" class="w-4 h-4" />
            <svg v-else class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            {{ loading ? $t('Calculating...') : $t('Calculate Movement Value') }}
          </button>
        </div>
      </div>

      <!-- Empty state before first search -->
      <div v-if="!hasSearched" class="lg-card text-center py-14">
        <span class="lg-card-ico lg-ico-slate mx-auto mb-3"><icons name="truck" class="w-5 h-5" /></span>
        <p class="lg-card-sub">{{ $t('Choose materials, source, and destination projects above, then calculate.') }}</p>
      </div>

      <!-- Results -->
      <template v-else>
        <div v-if="!localReport.groups.length" class="lg-card text-center py-14">
          <span class="lg-card-ico lg-ico-amber mx-auto mb-3"><icons name="info" class="w-5 h-5" /></span>
          <p class="lg-card-sub">{{ $t('No movement found for that selection.') }}</p>
        </div>

        <div v-else class="space-y-4">
          <div v-for="grp in localReport.groups" :key="grp.warehouse_id" class="mv-warehouse-card">
            <button type="button" class="mv-warehouse-card-header w-full text-left" @mousedown.prevent @click="toggleExpand(grp.warehouse_id)">
              <span class="lg-card-ico lg-ico-emerald"><icons name="building" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <div class="lg-card-title truncate">{{ grp.warehouse_name }}</div>
                <span class="lg-chip lg-chip-green mt-1">{{ $t(grp.warehouse_status) }}</span>
              </div>
              <div class="text-right mr-2">
                <div class="lg-subtext">{{ $t('Subtotal') }}</div>
                <div class="mv-subtotal">Tsh {{ formatMoney(grp.subtotal) }}</div>
              </div>
              <icons name="chevron-down" class="w-4 h-4 mv-chevron shrink-0 transition-transform" :class="{ 'rotate-180': isExpanded(grp.warehouse_id) }" />
            </button>

            <div v-if="isExpanded(grp.warehouse_id)" class="mv-warehouse-card-body">
              <div class="lg-table-wrap mv-scroll">
                <table class="lg-table lg-responsive mv-sticky">
                  <thead>
                    <tr>
                      <th>{{ $t('Transaction') }}</th>
                      <th>{{ $t('Item') }}</th>
                      <th>{{ $t('Date') }}</th>
                      <th class="num">{{ $t('Quantity') }}</th>
                      <th class="text-center">{{ $t('Unit') }}</th>
                      <th class="num">{{ $t('Unit Price') }}</th>
                      <th class="num">{{ $t('Value') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(it, idx) in grp.items" :key="`${it.item_id}-${it.date}-${idx}`">
                      <td :data-label="$t('Transaction')">
                        <button type="button" class="mv-ref-link" @click="openTransaction(it)">{{ it.reference }}</button>
                      </td>
                      <td :data-label="$t('Item')">
                        <div class="lg-main">{{ it.name }}</div>
                        <div v-if="it.code" class="lg-subtext">{{ it.code }}</div>
                      </td>
                      <td :data-label="$t('Date')">{{ it.date }}</td>
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
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  props: {
    filters: {
      type: Object,
      default: () => ({
        mode: 'warehouse', item_ids: [], category_ids: [], source_warehouse_ids: [], contact_ids: [],
        destination_warehouse_ids: [], date_from: null, date_to: null, entire_project: false,
      }),
    },
    report: { type: Object, default: () => ({ grand_total: 0, item_count: 0, group_count: 0, groups: [] }) },
    mainWarehouses: { type: Array, default: () => [] },
    projectWarehouses: { type: Array, default: () => [] },
    projectWarehouseStatuses: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    availableItems: { type: Array, default: () => [] },
    availableCategories: { type: Array, default: () => [] },
  },

  components: { AdminLayout, DatePicker, PageHeader, StatTiles },

  data() {
    return {
      movementMode: this.filters.mode === 'supplier' ? 'supplier' : 'warehouse',
      materialMode: (this.filters.category_ids || []).length > 0 && !(this.filters.item_ids || []).length ? 'categories' : 'items',
      materialSearch: '',
      supplierSearch: '',
      selectedItemIds: [...(this.filters.item_ids || [])],
      selectedCategoryIds: [...(this.filters.category_ids || [])],
      selectedSourceWarehouseIds: [...(this.filters.source_warehouse_ids || [])],
      selectedContactIds: [...(this.filters.contact_ids || [])],
      selectedDestinationIds: [...(this.filters.destination_warehouse_ids || [])],
      dateFrom: this.filters.date_from || '',
      dateTo: this.filters.date_to || '',
      entireProject: !!this.filters.entire_project,
      expandedGroups: (this.report.groups || []).map(g => g.warehouse_id),
      localReport: { ...this.report },
      loading: false,
      hasSearched: (this.filters.item_ids || []).length > 0 || (this.filters.category_ids || []).length > 0,
    };
  },

  computed: {
    // Summary tiles only re-present figures the report already carries; no
    // value is recomputed here.
    statItems() {
      const r = this.localReport || {};
      const lines = (r.groups || []).reduce((n, g) => n + ((g.items || []).length), 0);
      return [
        { icon: 'cash', tone: 'emerald', label: this.$t('Grand Total Value'), value: 'Tsh ' + this.formatMoney(r.grand_total) },
        { icon: 'bag', tone: 'indigo', label: this.$t('Materials'), value: Number(r.item_count || 0) },
        { icon: 'building', tone: 'sky', label: this.$t('Projects'), value: Number(r.group_count || 0) },
        { icon: 'list', tone: 'slate', label: this.$t('Movement Lines'), value: lines },
      ];
    },
    selectedMaterialIds() {
      return this.materialMode === 'items' ? this.selectedItemIds : this.selectedCategoryIds;
    },
    materialList() {
      return this.materialMode === 'items' ? this.availableItems : this.availableCategories;
    },
    filteredMaterials() {
      const q = this.materialSearch.trim().toLowerCase();
      if (!q) return this.materialList;
      return this.materialList.filter(m => m.name.toLowerCase().includes(q) || (m.code || '').toLowerCase().includes(q));
    },
    visibleMaterials() {
      return this.filteredMaterials.slice(0, 50);
    },
    selectedMaterialsList() {
      return this.materialList.filter(m => this.selectedMaterialIds.includes(m.id));
    },
    selectedSourceIds() {
      return this.movementMode === 'warehouse' ? this.selectedSourceWarehouseIds : this.selectedContactIds;
    },
    filteredSuppliers() {
      const q = this.supplierSearch.trim().toLowerCase();
      if (!q) return this.suppliers;
      return this.suppliers.filter(c => c.name.toLowerCase().includes(q));
    },
    visibleSuppliers() {
      return this.filteredSuppliers.slice(0, 50);
    },
    selectedSourcesList() {
      return this.suppliers.filter(c => this.selectedContactIds.includes(c.id));
    },
  },

  watch: {
    report(newVal) {
      this.localReport = newVal;
    },
  },

  methods: {
    setMovementMode(mode) {
      if (this.movementMode === mode) return;
      this.movementMode = mode;
      this.selectedSourceWarehouseIds = [];
      this.selectedContactIds = [];
      this.supplierSearch = '';
    },
    setMaterialMode(mode) {
      if (this.materialMode === mode) return;
      this.materialMode = mode;
      this.materialSearch = '';
    },
    resetAll() {
      this.movementMode = 'warehouse';
      this.materialMode = 'items';
      this.materialSearch = '';
      this.supplierSearch = '';
      this.selectedItemIds = [];
      this.selectedCategoryIds = [];
      this.selectedSourceWarehouseIds = [];
      this.selectedContactIds = [];
      this.selectedDestinationIds = [];
      this.dateFrom = '';
      this.dateTo = '';
      this.entireProject = false;
      this.expandedGroups = [];
      this.hasSearched = false;
      this.loading = false;
      this.$inertia.get(route('movement-value.index'));
    },
    isMaterialSelected(id) {
      return this.selectedMaterialIds.includes(id);
    },
    toggleMaterial(id) {
      const list = this.materialMode === 'items' ? 'selectedItemIds' : 'selectedCategoryIds';
      this[list] = this[list].includes(id) ? this[list].filter(v => v !== id) : [...this[list], id];
    },
    isSourceSelected(id) {
      return this.selectedSourceIds.includes(id);
    },
    toggleSource(id) {
      const list = this.movementMode === 'warehouse' ? 'selectedSourceWarehouseIds' : 'selectedContactIds';
      this[list] = this[list].includes(id) ? this[list].filter(v => v !== id) : [...this[list], id];
    },
    selectAllSources() {
      this.selectedSourceWarehouseIds = this.mainWarehouses.map(w => w.id);
    },
    clearSources() {
      this.selectedSourceWarehouseIds = [];
      this.selectedContactIds = [];
    },
    isDestinationSelected(id) {
      return this.selectedDestinationIds.includes(id);
    },
    toggleDestination(id) {
      this.selectedDestinationIds = this.selectedDestinationIds.includes(id)
        ? this.selectedDestinationIds.filter(v => v !== id)
        : [...this.selectedDestinationIds, id];
    },
    destinationCountByStatus(status) {
      return this.projectWarehouses.filter(w => w.status === status).length;
    },
    selectDestinationsByStatus(status) {
      const ids = this.projectWarehouses.filter(w => w.status === status).map(w => w.id);
      this.selectedDestinationIds = [...new Set([...this.selectedDestinationIds, ...ids])];
    },
    selectAllDestinations() {
      this.selectedDestinationIds = this.projectWarehouses.map(w => w.id);
    },
    clearDestinations() {
      this.selectedDestinationIds = [];
    },
    isExpanded(id) {
      return this.expandedGroups.includes(id);
    },
    toggleExpand(id) {
      this.expandedGroups = this.expandedGroups.includes(id)
        ? this.expandedGroups.filter(v => v !== id)
        : [...this.expandedGroups, id];
    },
    params(output = null) {
      const params = { mode: this.movementMode };
      if (this.selectedItemIds.length) params.item_ids = this.selectedItemIds.join(',');
      if (this.selectedCategoryIds.length) params.category_ids = this.selectedCategoryIds.join(',');
      if (this.movementMode === 'warehouse' && this.selectedSourceWarehouseIds.length) params.source_warehouse_ids = this.selectedSourceWarehouseIds.join(',');
      if (this.movementMode === 'supplier' && this.selectedContactIds.length) params.contact_ids = this.selectedContactIds.join(',');
      if (this.selectedDestinationIds.length) params.destination_warehouse_ids = this.selectedDestinationIds.join(',');
      if (this.entireProject) {
        params.entire_project = 1;
      } else {
        if (this.dateFrom) params.date_from = this.dateFrom;
        if (this.dateTo) params.date_to = this.dateTo;
      }
      if (output) params.output = output;
      return params;
    },
    search() {
      this.loading = true;
      this.hasSearched = true;
      this.expandedGroups = [];
      this.localReport = { grand_total: 0, item_count: 0, group_count: 0, groups: [] };
      this.$inertia.get(route('movement-value.index'), this.params(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
          this.loading = false;
          this.expandedGroups = (this.report.groups || []).map(g => g.warehouse_id);
        },
      });
    },
    openOutput(output) {
      window.open(route('movement-value.index', this.params(output)), '_blank');
    },
    openTransaction(it) {
      const type = this.movementMode === 'supplier' ? 'checkin' : 'transfer';
      window.open(route('project-material-ledger.show', [type, it.transaction_id]), '_blank');
    },
    formatMoney(value) {
      return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    formatQty(value) {
      const n = Number(value || 0);
      return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

/* Misc */
.mv-ref-link { font-weight: 800; color: #4338ca; cursor: pointer; }
.mv-ref-link:hover { text-decoration: underline; }
html.dark .mv-ref-link { color: #a5b4fc; }
.mv-entire-toggle { position: relative; display: inline-flex; align-items: center; gap: 7px; font-size: 0.78rem; font-weight: 700; color: var(--lg-ink); cursor: pointer; padding-bottom: 9px; }
.mv-entire-check { display: inline-flex; align-items: center; justify-content: center; height: 17px; width: 17px; border-radius: 5px; border: 1.5px solid #cbd5e1; color: transparent; flex: none; transition: all 0.15s ease; }
.mv-entire-toggle:has(input:checked) .mv-entire-check { background: #059669; border-color: #059669; color: #fff; }
</style>
