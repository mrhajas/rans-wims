<template>
  <admin-layout :title="$t('Usage Material per Site and Cost')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="cash"
        :title="$t('Usage Material per Site and Cost')"
        :subtitle="$t('Select warehouse and date range to review used materials and cost.')"
      >
        <button type="button" class="lg-btn lg-btn-soft" @click="openPrint">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
        <button type="button" class="lg-btn lg-btn-green" @click="downloadPdf">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Download Report') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card um-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Warehouses') }} / {{ $t('From Date') }} / {{ $t('To Date') }}</p>
          </div>
        </header>
        <form @submit.prevent="applyFilters">
          <div class="um-grid">
            <div class="um-wh">
              <label class="fx-label">{{ $t('Warehouses') }}</label>
              <div class="relative">
                <icons name="search" class="w-4 h-4 um-search-ico" />
                <input v-model="warehouseSearch" type="text" class="um-input um-search" :placeholder="$t('Search warehouse and select')" />
              </div>
              <div class="um-list">
                <button
                  v-for="warehouse in filteredWarehouses"
                  :key="warehouse.id"
                  type="button"
                  class="um-list-item"
                  :class="{ 'is-on': isSelectedWarehouse(warehouse.id) }"
                  @click="toggleWarehouse(warehouse.id)"
                >
                  <span class="inline-flex items-center gap-2">
                    <span class="um-dot" :class="warehouse.completed_at ? 'is-done' : 'is-live'"></span>
                    {{ warehouse.name }}
                  </span>
                  <icons v-if="isSelectedWarehouse(warehouse.id)" name="tick" class="w-4 h-4 um-tick" />
                </button>
                <div v-if="filteredWarehouses.length === 0" class="um-list-empty">
                  {{ $t('No warehouses found.') }}
                </div>
              </div>
              <div v-if="selectedWarehouses.length" class="flex flex-wrap gap-2 mt-2">
                <span v-for="warehouse in selectedWarehouses" :key="warehouse.id" class="lg-chip lg-chip-indigo" style="text-transform: none">
                  {{ warehouse.name }}
                  <button type="button" class="um-chip-x" @click="toggleWarehouse(warehouse.id)">
                    <icons name="cross" class="w-3 h-3" />
                  </button>
                </span>
              </div>
              <p class="um-hint">{{ $t('Leave empty to include all warehouses.') }}</p>
            </div>

            <div class="um-side">
              <div class="um-box">
                <date-picker v-model="form.start_date" :label="$t('From Date')" :disabled="form.entire_period" clearable />
              </div>
              <div class="um-box">
                <date-picker v-model="form.end_date" :label="$t('To Date')" :disabled="form.entire_period" clearable />
              </div>
              <div class="um-box um-period" :class="{ 'is-on': form.entire_period }">
                <label for="entire_period" class="flex items-center cursor-pointer">
                  <input id="entire_period" v-model="form.entire_period" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm" />
                  <span class="ml-2 um-period-label">{{ $t('Entire Project(s) Duration') }}</span>
                </label>
                <p class="um-hint">{{ form.entire_period ? $t('All dates included') : $t('Use date range') }}</p>
              </div>
            </div>
          </div>

          <div class="um-btns">
            <button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Apply') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters"><icons name="cross" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Usage Material per Site and Cost') }}</h2>
          </div>
          <div class="um-search-wrap print:hidden">
            <icons name="search" class="w-4 h-4 um-search-ico" />
            <input v-model="itemSearch" type="text" class="um-input um-search" :placeholder="$t('Search items in table')" />
          </div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="num">{{ $t('No:') }}</th>
                <th>{{ $t('Material / Item') }}</th>
                <th class="num">{{ $t('Qty Used') }}</th>
                <th>{{ $t('Unit') }}</th>
                <th class="num">{{ $t('Total Cost') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in filteredRows" :key="`${row.item_id}-${row.unit}`">
                <td :data-label="$t('No:')" class="num" style="color: var(--lg-faint)">{{ index + 1 }}</td>
                <td :data-label="$t('Material / Item')" class="um-name lg-main">{{ row.item_name }}</td>
                <td :data-label="$t('Qty Used')" class="num">{{ formatQty(row.qty_used) }}</td>
                <td :data-label="$t('Unit')">{{ row.unit }}</td>
                <td :data-label="$t('Total Cost')" class="num lg-main">{{ formatMoney(row.total_cost) }}</td>
              </tr>
              <tr v-if="filteredRows.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td>
              </tr>
              <tr v-if="filteredRows.length" class="um-foot">
                <td :data-label="$t('Total Table Value')" colspan="4">{{ $t('Total Table Value') }}</td>
                <td :data-label="$t('Total Cost')" class="num">{{ formatMoney(totalValue) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
    StatTiles,
    DatePicker,
  },

  props: {
    filters: {
      type: Object,
      default: () => ({}),
    },
    warehouses: {
      type: Array,
      default: () => [],
    },
    rows: {
      type: Array,
      default: () => [],
    },
    totalValue: {
      type: Number,
      default: 0,
    },
  },

  data() {
    return {
      form: {
        warehouse_ids: (this.filters.warehouse_ids || []).map(id => String(id)),
        start_date: this.filters.start_date || '',
        end_date: this.filters.end_date || '',
        entire_period: Boolean(this.filters.entire_period),
      },
      warehouseSearch: '',
      itemSearch: this.filters.item_search || '',
    };
  },

  computed: {
    statTiles() {
      return [
        { icon: 'cash', tone: 'emerald', label: this.$t('Total Table Value'), value: this.formatMoney(this.totalValue) },
        { icon: 'collection', tone: 'indigo', label: this.$t('Materials'), value: this.filteredRows.length },
        { icon: 'building', tone: 'amber', label: this.$t('Warehouses Selected'), value: this.selectedWarehouses.length || this.$t('All') },
      ];
    },
    filteredWarehouses() {
      const q = this.warehouseSearch.toLowerCase().trim();
      if (!q) {
        return this.warehouses;
      }
      return this.warehouses.filter(w => (w.name || '').toLowerCase().includes(q));
    },
    selectedWarehouses() {
      const selected = new Set(this.form.warehouse_ids.map(String));
      return this.warehouses.filter(w => selected.has(String(w.id)));
    },
    filteredRows() {
      const q = this.itemSearch.toLowerCase().trim();
      if (!q) {
        return this.rows;
      }
      return this.rows.filter(row => (row.item_name || '').toLowerCase().includes(q));
    },
  },

  methods: {
    isSelectedWarehouse(id) {
      return this.form.warehouse_ids.includes(String(id));
    },
    toggleWarehouse(id) {
      const value = String(id);
      if (this.form.warehouse_ids.includes(value)) {
        this.form.warehouse_ids = this.form.warehouse_ids.filter(wId => wId !== value);
      } else {
        this.form.warehouse_ids.push(value);
      }
    },
    applyFilters() {
      const params = {
        warehouse_ids: this.form.warehouse_ids,
        entire_period: this.form.entire_period ? 1 : 0,
        item_search: this.itemSearch || undefined,
      };

      if (!this.form.entire_period) {
        if (this.form.start_date) {
          params.start_date = this.form.start_date;
        }
        if (this.form.end_date) {
          params.end_date = this.form.end_date;
        }
      }

      this.$inertia.get(this.route('reports.usage_material_per_site_cost'), params, {
        preserveState: true,
        replace: true,
      });
    },
    outputParams(output) {
      const params = {
        warehouse_ids: this.form.warehouse_ids,
        entire_period: this.form.entire_period ? 1 : 0,
        output,
      };
      if (!this.form.entire_period) {
        if (this.form.start_date) {
          params.start_date = this.form.start_date;
        }
        if (this.form.end_date) {
          params.end_date = this.form.end_date;
        }
      }
      if (this.itemSearch) {
        params.item_search = this.itemSearch;
      }
      return params;
    },
    openPrint() {
      window.open(this.route('reports.usage_material_per_site_cost', this.outputParams('print')), '_blank');
    },
    downloadPdf() {
      window.open(this.route('reports.usage_material_per_site_cost', this.outputParams('pdf')), '_blank');
    },
    resetFilters() {
      this.form.warehouse_ids = [];
      this.form.start_date = '';
      this.form.end_date = '';
      this.form.entire_period = false;
      this.itemSearch = '';
      this.$inertia.get(this.route('reports.usage_material_per_site_cost'));
    },
    formatQty(value) {
      return Number(value || 0).toLocaleString();
    },
    formatMoney(value) {
      const currencyCode = this.$page.props?.settings?.currency_code || 'TZS';
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(Number(value || 0));
    },
  },
};
</script>

<style scoped>
.fx-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.fx-form :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.um-input {
  border-radius: 0.85rem !important;
  border: 1px solid var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.um-input {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  font-size: 0.85rem;
}
.um-input:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
  border-color: #60a5fa !important;
}
.um-search {
  padding-left: 2.2rem;
}
.um-search-ico {
  position: absolute;
  left: 0.8rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lg-faint);
  z-index: 1;
}
.um-search-wrap {
  position: relative;
  width: 100%;
  max-width: 18rem;
}
.um-filters {
  position: relative;
  z-index: 20;
}
.um-grid {
  display: grid;
  gap: 1rem 1.25rem;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
}
.um-side {
  display: grid;
  gap: 0.75rem;
  align-content: start;
}
.um-box {
  padding: 0.7rem 0.85rem;
  border-radius: 14px;
  border: 1px solid var(--lg-line);
  background: var(--lg-soft);
}
.um-period.is-on {
  background: rgba(16, 185, 129, 0.12);
  border-color: rgba(16, 185, 129, 0.35);
}
.um-period-label {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--lg-ink);
}
.um-hint {
  margin-top: 0.3rem;
  font-size: 0.72rem;
  color: var(--lg-mute);
}
.um-list {
  margin-top: 0.5rem;
  max-height: 10rem;
  overflow: auto;
  border-radius: 14px;
  border: 1px solid var(--lg-line);
  background: var(--lg-card);
}
.um-list-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0.5rem 0.85rem;
  text-align: left;
  font-size: 0.83rem;
  color: var(--lg-ink);
  border-bottom: 1px solid var(--lg-line);
  transition: background 0.12s ease;
}
.um-list-item:last-child {
  border-bottom: 0;
}
.um-list-item:hover {
  background: var(--lg-soft);
}
.um-list-item.is-on {
  background: rgba(99, 102, 241, 0.1);
  font-weight: 700;
}
.um-list-empty {
  padding: 0.75rem;
  font-size: 0.83rem;
  color: var(--lg-faint);
}
.um-tick {
  color: #059669;
}
.um-dot {
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  flex: none;
}
.um-dot.is-live {
  background: #10b981;
}
.um-dot.is-done {
  background: #ef4444;
}
.um-chip-x {
  margin-left: 4px;
  display: inline-flex;
  opacity: 0.8;
}
.um-chip-x:hover {
  opacity: 1;
}
.um-btns {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 1rem;
}
.um-name {
  white-space: normal;
}
.um-foot td {
  font-weight: 800;
  background: var(--lg-soft);
}
@media (max-width: 767.98px) {
  .um-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .um-btns .lg-btn {
    flex: 1 1 auto;
  }
  .um-search-wrap {
    max-width: none;
  }
  .lg-card-head:has(.um-search-wrap) {
    flex-wrap: wrap;
  }
}
</style>
