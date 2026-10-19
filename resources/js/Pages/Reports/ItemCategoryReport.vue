<template>
  <admin-layout title="Item / Category Report">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="chart-pie"
        :title="$t('Item / Category Report')"
        :subtitle="$t('View inventory by items, categories, or warehouse with advanced filtering.')"
      />

      <stat-tiles :items="statTiles" />

      <!-- Filters -->
      <section class="lg-card icr-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
          </div>
          <div class="icr-tabs" role="tablist">
            <button type="button" role="tab" :aria-selected="form.report_type === 'items'" class="icr-tab" :class="{ 'icr-tab-active': form.report_type === 'items' }" @click="setReportType('items')">
              <icons name="bag" class="w-3.5 h-3.5" />
              {{ $t('By Items') }}
            </button>
            <button type="button" role="tab" :aria-selected="form.report_type === 'categories'" class="icr-tab" :class="{ 'icr-tab-active': form.report_type === 'categories' }" @click="setReportType('categories')">
              <icons name="folder" class="w-3.5 h-3.5" />
              {{ $t('By Categories') }}
            </button>
          </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
          <!-- Items/Categories -->
          <div class="icr-col">
            <span class="icr-field-label">
              <icons :name="form.report_type === 'items' ? 'bag' : 'folder'" class="w-3.5 h-3.5" />
              {{ form.report_type === 'items' ? $t('Search Items') : $t('Search Categories') }}
            </span>

            <div class="relative mb-2">
              <icons name="search" class="w-4 h-4 icr-search-ico" />
              <input
                v-model="itemsSearchInput"
                type="text"
                :placeholder="form.report_type === 'items' ? $t('Search by item name or code...') : $t('Search categories...')"
                class="icr-search-input"
              />
            </div>

            <div v-if="filteredItemsOptions.length > 0" class="icr-picker-list mb-3">
              <button v-for="option in filteredItemsOptions" :key="option.id" type="button" class="icr-picker-row" @click="addItem(option)">
                <span class="icr-picker-plus"><icons name="plus" class="w-3 h-3" /></span>
                <span class="min-w-0">
                  <span class="block truncate font-semibold">{{ option.name }}</span>
                  <span class="block text-xs icr-muted">{{ option.code || $t('No code') }}</span>
                </span>
              </button>
            </div>

            <div v-if="selectedItemsList.length > 0" class="flex flex-wrap gap-1.5">
              <span v-for="item in selectedItemsList" :key="item.id" class="icr-chip">
                {{ item.name }}
                <button type="button" class="icr-chip-x lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeItem(item.id)"><icons name="cross" class="w-2.5 h-2.5" /></button>
              </span>
            </div>
            <div v-else class="text-sm icr-muted italic">
              {{ $t('No') }} {{ form.report_type === 'items' ? $t('items') : $t('categories') }} {{ $t('selected') }}
            </div>
          </div>

          <!-- Warehouses -->
          <div class="icr-col">
            <span class="icr-field-label"><icons name="building" class="w-3.5 h-3.5" />{{ $t('Search Project(s) / Warehouse(s)') }}</span>

            <div class="relative mb-2">
              <icons name="search" class="w-4 h-4 icr-search-ico" />
              <input
                v-model="warehouseSearchInput"
                type="text"
                :placeholder="$t('Search warehouse name...')"
                class="icr-search-input"
              />
            </div>

            <div v-if="allWarehousesAndFiltered.length > 0" class="icr-picker-list mb-3">
              <button type="button" class="icr-picker-row icr-picker-row-all" @click="selectAllWarehouses">
                <span class="icr-picker-plus"><icons name="tick" class="w-3 h-3" /></span>
                <span class="font-bold">{{ $t('All Project(s) / Warehouse(s)') }}</span>
              </button>
              <button v-for="warehouse in filteredWarehouses" :key="warehouse.id" type="button" class="icr-picker-row" @click="addWarehouse(warehouse)">
                <span class="icr-picker-plus"><icons name="plus" class="w-3 h-3" /></span>
                <span
                  class="h-1.5 w-1.5 rounded-full shrink-0"
                  :class="warehouse.completed_at ? 'bg-red-500' : 'bg-emerald-500'"
                ></span>
                <span class="truncate font-semibold">{{ warehouse.name }}</span>
              </button>
            </div>

            <div v-if="selectedWarehousesList.length > 0" class="flex flex-wrap gap-1.5">
              <span v-if="isAllWarehousesSelected" class="icr-chip icr-chip-indigo">
                {{ $t('All Project(s) / Warehouse(s)') }}
                <button type="button" class="icr-chip-x lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="clearAllWarehouses"><icons name="cross" class="w-2.5 h-2.5" /></button>
              </span>
              <span v-else v-for="warehouse in selectedWarehousesList" :key="warehouse.id" class="icr-chip icr-chip-indigo">
                {{ warehouse.name }}
                <button type="button" class="icr-chip-x lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeWarehouse(warehouse.id)"><icons name="cross" class="w-2.5 h-2.5" /></button>
              </span>
            </div>
            <div v-else class="text-sm icr-muted italic">{{ $t('No warehouses selected') }}</div>
          </div>
        </div>

        <div class="mb-5">
          <span class="icr-field-label"><icons name="view" class="w-3.5 h-3.5" />{{ $t('Search Results') }}</span>
          <div class="relative">
            <icons name="search" class="w-4 h-4 icr-search-ico" />
            <input
              v-model="form.search"
              type="text"
              :placeholder="$t('Filter report results by item name or warehouse...')"
              class="icr-search-input"
            />
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters">
            <icons name="refresh" class="w-4 h-4" />
            {{ $t('Reset') }}
          </button>
          <button type="button" class="lg-btn lg-btn-dark" @click="open('print')">
            <icons name="printer" class="w-4 h-4" />
            {{ $t('Print') }}
          </button>
          <button type="button" class="lg-btn lg-btn-red" @click="open('pdf')">
            <icons name="download" class="w-4 h-4" />
            {{ $t('PDF') }}
          </button>
          <button type="button" class="lg-btn lg-btn-green" @click="open('excel')">
            <icons name="table" class="w-4 h-4" />
            {{ $t('Excel') }}
          </button>
        </div>
      </section>

      <!-- Results Table -->
      <section class="lg-card icr-table-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ $t('Item No') }}</th>
                <th>{{ $t('Item Name') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th class="text-center">{{ $t('Unit') }}</th>
                <th class="num">{{ $t('Price') }}</th>
                <th class="num">{{ $t('Total') }}</th>
                <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in report.data" :key="i">
                <td data-label="#" class="icr-muted">{{ row.No }}</td>
                <td :data-label="$t('Item No')">
                  <button type="button" class="icr-link" @click="openItemDetails(row.item_id)">{{ row['Item No'] }}</button>
                </td>
                <td :data-label="$t('Item Name')"><span class="lg-main">{{ row['Item Name'] }}</span></td>
                <td :data-label="$t('Quantity')" class="num">{{ formatNumber(row.Quantity) }}</td>
                <td :data-label="$t('Unit')" class="text-center icr-muted">{{ row.Unit }}</td>
                <td :data-label="$t('Price')" class="num">{{ formatMoney(row.Price) }}</td>
                <td :data-label="$t('Total')" class="num icr-strong">{{ formatMoney(row.Total) }}</td>
                <td :data-label="$t('Project(s) / Warehouse(s)')">{{ row.Warehouse }}</td>
              </tr>
              <tr v-if="!report.data || report.data.length === 0" class="lg-empty-row">
                <td :colspan="8" class="lg-empty">
                  {{ $t('No data to display. Please select items/categories and warehouses.') }}
                </td>
              </tr>
              <tr v-if="report.data && report.data.length > 0" class="icr-foot">
                <td :data-label="$t('Total Records')" colspan="6">{{ $t('Total Records') }}: {{ report.data.length }}</td>
                <td :data-label="$t('Total')" class="num">{{ formatMoney(report.total_value) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="report.data && report.data.length > 0" class="icr-summary-footer">
          <span class="icr-muted">{{ $t('Generated') }}: {{ formatDate(new Date()) }}</span>
        </div>
      </section>

      <!-- Item Details Modal -->
      <teleport to="body">
        <transition name="modal">
          <div v-if="showItemModal" class="lg-page icr-modal-backdrop" @click.self="closeItemModal">
            <div class="icr-modal-panel">
              <div class="icr-modal-header">
                <span class="icr-modal-ico"><icons name="bag" class="w-5 h-5" /></span>
                <div class="min-w-0 flex-1">
                  <h3 class="icr-modal-title truncate">{{ selectedItemDetails.item_name }}</h3>
                  <p class="icr-modal-sub">{{ $t('Item Code') }}: {{ selectedItemDetails.item_code }}</p>
                </div>
                <button type="button" class="icr-modal-close-btn lg-tip" :data-tip="$t('Close')" :aria-label="$t('Close')" @click="closeItemModal">
                  <icons name="cross" class="w-4 h-4" />
                </button>
              </div>

              <div class="icr-modal-body">
                <div class="icr-stat-grid">
                  <div class="icr-stat-box">
                    <span class="icr-stat-label">{{ $t('Unit') }}</span>
                    <span class="icr-stat-value">{{ selectedItemDetails.unit }}</span>
                  </div>
                  <div class="icr-stat-box">
                    <span class="icr-stat-label">{{ $t('Unit Price') }}</span>
                    <span class="icr-stat-value">Tsh {{ formatMoney(selectedItemDetails.price) }}</span>
                  </div>
                  <div class="icr-stat-box icr-stat-box-green">
                    <span class="icr-stat-label">{{ $t('Total Company Quantity') }}</span>
                    <span class="icr-stat-value">{{ (selectedItemDetails.total_quantity || 0).toFixed(2) }}</span>
                  </div>
                </div>

                <div>
                  <h4 class="icr-field-label"><icons name="building" class="w-3.5 h-3.5" />{{ $t('Project(s) / Warehouse(s) Stock Details') }}</h4>
                  <div class="lg-table-wrap">
                    <table v-labelize class="lg-table lg-responsive">
                      <thead>
                        <tr>
                          <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
                          <th class="num">{{ $t('Quantity') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(detail, idx) in selectedItemDetails.warehouse_details" :key="idx">
                          <td>{{ detail.warehouse }}</td>
                          <td class="num icr-strong">{{ detail.quantity.toFixed(2) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </transition>
      </teleport>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles },
  props: {
    filters: Object,
    report: Object,
    warehouses: Array,
    availableItems: Array,
    availableCategories: Array,
  },
  data() {
    return {
      form: {
        report_type: this.filters.report_type || 'items',
        items: (this.filters.items || []).map(String),
        categories: (this.filters.categories || []).map(String),
        warehouse_ids: (this.filters.warehouse_ids || []).map(String),
        search: this.filters.search || '',
      },
      itemsSearchInput: '',
      warehouseSearchInput: '',
      showItemModal: false,
      selectedItemDetails: {
        item_id: null,
        item_name: '',
        item_code: '',
        barcode: '',
        unit: '',
        price: 0,
        total_quantity: 0,
        warehouse_details: [],
      },
    };
  },
  computed: {
    statTiles() {
      const rows = (this.report && this.report.data) || [];
      if (!rows.length) return [];

      return [
        { icon: 'cash', tone: 'violet', label: this.$t('Total Value (Filtered Results)'), value: 'Tsh ' + this.formatMoney(this.report.total_value) },
        { icon: 'collection', tone: 'sky', label: this.$t('Total Records'), value: rows.length },
      ];
    },

    filteredItemsOptions() {
      const search = this.itemsSearchInput.toLowerCase();

      if (this.form.report_type === 'items') {
        return this.availableItems.filter(item => {
          const isSelected = this.form.items.includes(String(item.id));
          const matchesSearch = item.name.toLowerCase().includes(search) ||
                               (item.code && item.code.toLowerCase().includes(search));
          return matchesSearch && !isSelected;
        });
      } else {
        return this.availableCategories.filter(cat => {
          const isSelected = this.form.categories.includes(String(cat.id));
          const matchesSearch = cat.name.toLowerCase().includes(search);
          return matchesSearch && !isSelected;
        });
      }
    },

    filteredWarehouses() {
      const search = this.warehouseSearchInput.toLowerCase();
      return this.warehouses.filter(warehouse => {
        const isAllSelected = this.isAllWarehousesSelected;
        const isSelected = !isAllSelected && this.form.warehouse_ids.includes(String(warehouse.id));
        const matchesSearch = warehouse.name.toLowerCase().includes(search);
        return matchesSearch && !isSelected;
      });
    },

    selectedItemsList() {
      if (this.form.report_type === 'items') {
        return this.availableItems.filter(item => this.form.items.includes(String(item.id)));
      } else {
        return this.availableCategories.filter(cat => this.form.categories.includes(String(cat.id)));
      }
    },

    selectedWarehousesList() {
      return this.warehouses.filter(w => this.form.warehouse_ids.includes(String(w.id)));
    },

    allWarehousesAndFiltered() {
      const search = this.warehouseSearchInput.toLowerCase();
      return this.warehouses.filter(warehouse =>
        warehouse.name.toLowerCase().includes(search)
      );
    },

    isAllWarehousesSelected() {
      return this.warehouses.length > 0 &&
             this.form.warehouse_ids.length === this.warehouses.length &&
             this.warehouses.every(w => this.form.warehouse_ids.includes(String(w.id)));
    },
  },

  watch: {
    'form.search'() {
      if (this.form.search && this.form.search.trim().length > 0) {
        this.$nextTick(() => {
          this.apply();
        });
      }
    },
  },

  methods: {
    setReportType(type) {
      if (this.form.report_type === type) return;
      this.form.report_type = type;
      this.onReportTypeChange();
    },

    addItem(item) {
      const itemId = String(item.id);
      if (this.form.report_type === 'items') {
        if (!this.form.items.includes(itemId)) {
          this.form.items.push(itemId);
        }
      } else {
        if (!this.form.categories.includes(itemId)) {
          this.form.categories.push(itemId);
        }
      }
      this.itemsSearchInput = '';
      this.apply();
    },

    removeItem(itemId) {
      if (this.form.report_type === 'items') {
        this.form.items = this.form.items.filter(id => id !== String(itemId));
      } else {
        this.form.categories = this.form.categories.filter(id => id !== String(itemId));
      }
      this.apply();
    },

    addWarehouse(warehouse) {
      const warehouseId = String(warehouse.id);
      if (this.isAllWarehousesSelected) {
        this.form.warehouse_ids = [warehouseId];
      } else {
        if (!this.form.warehouse_ids.includes(warehouseId)) {
          this.form.warehouse_ids.push(warehouseId);
        }
      }
      this.warehouseSearchInput = '';
      this.apply();
    },

    removeWarehouse(warehouseId) {
      this.form.warehouse_ids = this.form.warehouse_ids.filter(id => id !== String(warehouseId));
      this.apply();
    },

    selectAllWarehouses() {
      this.form.warehouse_ids = this.warehouses.map(w => String(w.id));
      this.warehouseSearchInput = '';
      this.$nextTick(() => {
        this.apply();
      });
    },

    clearAllWarehouses() {
      this.form.warehouse_ids = [];
      this.$nextTick(() => {
        this.apply();
      });
    },

    resetFilters() {
      this.form.items = [];
      this.form.categories = [];
      this.form.warehouse_ids = [];
      this.form.search = '';
      this.itemsSearchInput = '';
      this.warehouseSearchInput = '';
      this.$inertia.get(route('item-category-report.index'));
    },

    onReportTypeChange() {
      this.form.items = [];
      this.form.categories = [];
      this.itemsSearchInput = '';
    },

    params(output = null) {
      const params = { ...this.form };
      params.items = params.items.length > 0 ? params.items.join(',') : null;
      params.categories = params.categories.length > 0 ? params.categories.join(',') : null;
      params.warehouse_ids = params.warehouse_ids.length > 0 ? params.warehouse_ids.join(',') : null;

      Object.keys(params).forEach(k => {
        if (!params[k] || params[k] === '') delete params[k];
      });

      if (output) params.output = output;
      return params;
    },

    apply() {
      this.$inertia.get(
        route('item-category-report.index', this.params()),
        {},
        { preserveState: true, replace: true, preserveScroll: true }
      );
    },

    open(output) {
      window.open(route('item-category-report.index', this.params(output)), '_blank');
    },

    formatNumber(value) {
      return typeof value === 'number' ? value.toFixed(2) : value;
    },

    formatMoney(value) {
      return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    formatDate(date) {
      return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      });
    },

    async openItemDetails(itemId) {
      try {
        const response = await fetch(route('item-category-report.item-details', itemId));
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
        barcode: '',
        unit: '',
        price: 0,
        total_quantity: 0,
        warehouse_details: [],
      };
    },
  },
};
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-to,
.modal-leave-from {
  opacity: 1;
}

/* filters card sits above the table so nothing is clipped */
.icr-filters {
  position: relative;
  z-index: 20;
}
.icr-muted {
  color: var(--lg-faint);
}
.icr-strong {
  font-weight: 800;
}
.icr-tabs {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  border-radius: 9999px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.icr-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 800;
  color: var(--lg-mute);
  transition: all 0.15s ease;
}
.icr-tab:hover {
  color: var(--lg-navy);
}
.icr-tab-active,
.icr-tab-active:hover {
  color: #fff;
  background: linear-gradient(145deg, #2f6fb3, #0f2540);
  box-shadow: 0 8px 16px -8px rgba(15, 37, 64, 0.9);
}
.icr-field-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 0.4rem;
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--lg-mute);
}
.icr-search-ico {
  position: absolute;
  left: 0.85rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lg-faint);
  pointer-events: none;
}
.icr-search-input {
  display: block;
  width: 100%;
  padding: 0.55rem 1rem 0.55rem 2.4rem;
  font-size: 0.84rem;
  color: var(--lg-ink);
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  border-radius: 0.85rem;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.icr-search-input:focus {
  outline: none;
  border-color: #818cf8;
  box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.22);
}
.icr-picker-list {
  max-height: 190px;
  overflow-y: auto;
  padding: 4px;
  border-radius: 14px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.icr-picker-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 7px 9px;
  text-align: left;
  border-radius: 10px;
  font-size: 0.84rem;
  color: var(--lg-ink);
  transition: background 0.12s ease;
}
.icr-picker-row:hover {
  background: rgba(47, 111, 179, 0.1);
}
.icr-picker-row-all {
  margin-bottom: 2px;
  border-bottom: 1px solid var(--lg-line);
  border-radius: 10px 10px 0 0;
  color: var(--lg-navy);
}
.icr-picker-plus {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 18px;
  height: 18px;
  border-radius: 9999px;
  color: #1d4ed8;
  background: rgba(59, 130, 246, 0.14);
}
.icr-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 4px 3px 10px;
  border-radius: 9999px;
  font-size: 0.74rem;
  font-weight: 700;
  color: #1d4ed8;
  background: rgba(59, 130, 246, 0.12);
  border: 1px solid rgba(96, 165, 250, 0.35);
}
.icr-chip-indigo {
  color: #4338ca;
  background: rgba(99, 102, 241, 0.12);
  border-color: rgba(129, 140, 248, 0.35);
}
.icr-chip-x {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  min-height: 0;
  padding: 0;
  border-radius: 9999px;
  color: inherit;
  background: rgba(255, 255, 255, 0.6);
  transition: background 0.15s ease;
}
.icr-chip-x:hover {
  color: #dc2626;
  background: rgba(220, 38, 38, 0.15);
}
.icr-table-card {
  padding: 0.6rem;
}
.icr-link {
  font-weight: 800;
  color: #1d4ed8;
}
.icr-link:hover {
  text-decoration: underline;
}
.icr-foot td {
  font-weight: 800;
  background: var(--lg-soft);
}
.lg-table tbody tr.icr-foot:hover td {
  background: var(--lg-soft);
}
.icr-summary-footer {
  padding: 0.7rem 0.9rem 0.3rem;
  font-size: 0.76rem;
}

/* modal (teleported to body: carries its own lg-page tokens) */
.icr-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.5);
  backdrop-filter: blur(4px);
}
.icr-modal-panel {
  width: 100%;
  max-width: 42rem;
  max-height: 88vh;
  overflow-y: auto;
  border-radius: 22px;
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  box-shadow: 0 30px 70px -16px rgba(15, 23, 42, 0.5);
}
html.dark .icr-modal-panel {
  background: #0f172a;
}
.icr-modal-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 20px;
  background: linear-gradient(135deg, #2f6fb3, #0f2540);
}
.icr-modal-ico {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 13px;
  color: #fff;
  background: rgba(255, 255, 255, 0.16);
}
.icr-modal-title {
  font-size: 1.05rem;
  font-weight: 800;
  color: #fff;
}
.icr-modal-sub {
  margin-top: 2px;
  font-size: 0.74rem;
  color: rgba(255, 255, 255, 0.75);
}
.icr-modal-close-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 32px;
  height: 32px;
  min-height: 0;
  padding: 0;
  border-radius: 10px;
  color: #fff;
  background: rgba(255, 255, 255, 0.18);
  transition: background 0.15s ease;
}
.icr-modal-close-btn:hover {
  background: rgba(255, 255, 255, 0.32);
}
/* the panel clips overflow: open the tooltip downward */
.icr-modal-close-btn.lg-tip::after {
  bottom: auto;
  top: calc(100% + 8px);
}
.icr-modal-body {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.25rem 1.4rem 1.5rem;
}
.icr-stat-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}
.icr-stat-box {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding: 10px 12px;
  border-radius: 14px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.icr-stat-box-green {
  background: rgba(16, 185, 129, 0.1);
  border-color: rgba(52, 211, 153, 0.4);
}
.icr-stat-label {
  font-size: 0.64rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--lg-faint);
}
.icr-stat-value {
  font-size: 0.95rem;
  font-weight: 800;
  color: var(--lg-ink);
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
html.dark .icr-link {
  color: #93c5fd;
}
html.dark .icr-chip {
  color: #bfdbfe;
}
html.dark .icr-chip-indigo {
  color: #c7d2fe;
}
html.dark .icr-chip-x {
  background: rgba(255, 255, 255, 0.1);
}
html.dark .icr-picker-plus {
  color: #bfdbfe;
}
html.dark .icr-search-input:focus {
  border-color: #a5b4fc;
}
@media (max-width: 575.98px) {
  .icr-stat-grid {
    grid-template-columns: 1fr;
  }
}
@media print {
  .icr-modal-backdrop {
    display: none;
  }
}
</style>
