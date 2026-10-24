<template>
  <admin-layout :title="$t('Item Transactional Report')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="doc-text"
        :title="$t('Item Transactional Report')"
        :subtitle="$t('Combined Checkin, Checkout and Transfer transactions by selected item, warehouse, and date range')"
      >
        <button type="button" class="lg-btn lg-btn-soft" @click="openPrint">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
        <button type="button" class="lg-btn lg-btn-red" @click="downloadPdf">
          <icons name="doc" class="w-4 h-4" />{{ $t('Download PDF') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card it-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Item') }} / {{ $t('Category') }} / {{ $t('Warehouse') }} / {{ $t('Date From') }} / {{ $t('Date To') }}</p>
          </div>
        </header>
        <form class="item-tx-filter-form" @submit.prevent="applyFilters">
          <div class="lg-fields">
            <auto-complete clearable :label="$t('Item')" v-model="form.item_id" :suggestions="itemSuggestions" />
            <auto-complete clearable :label="$t('Category')" v-model="form.category_id" :suggestions="categorySuggestions" />
            <auto-complete clearable :label="$t('Warehouse')" v-model="form.warehouse_id" :suggestions="warehouseSuggestions" />
            <div class="item-tx-from w-full">
              <date-picker v-model="form.start_date" :label="$t('Date From')" clearable />
            </div>
            <div class="item-tx-to w-full">
              <date-picker v-model="form.end_date" :label="$t('Date To')" clearable />
            </div>
            <div>
              <label class="fx-label">{{ $t('Transaction Type') }}</label>
              <css-select v-model="form.transaction_type" class="it-select">
                <option value="">{{ $t('All Types') }}</option>
                <option value="checkin">{{ $t('Checkin') }}</option>
                <option value="checkout">{{ $t('Checkout') }}</option>
                <option value="transfer">{{ $t('Transfer') }}</option>
              </css-select>
            </div>
            <div class="item-tx-search w-full lg-span-2">
              <label class="fx-label">{{ $t('Search In Result') }}</label>
              <input
                v-model="form.search"
                type="text"
                class="it-input"
                :placeholder="$t('Transaction no, item, type, warehouse, contact, person')"
              />
            </div>
          </div>
          <div class="it-filter-btns">
            <button type="button" class="lg-btn lg-btn-primary" @click="applyFilters"><icons name="search" class="w-4 h-4" />{{ $t('Apply') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters"><icons name="cross" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Item Transactional Report') }}</h2>
          </div>
          <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ rows.length }} {{ $t('Total Rows') }}</span>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th v-for="col in columns" :key="col.key" :class="{ num: col.num }">
                  <button type="button" class="item-tx-sort-btn" :class="{ 'item-tx-sort-btn-right': col.num }" @click="toggleSort(col.key)">
                    {{ col.label }}
                    <span class="item-tx-sort-indicator">{{ sortIndicator(col.key) }}</span>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in sortedRows" :key="`${row.transaction_no}-${index}`">
                <td :data-label="$t('No')" class="num" style="color: var(--lg-faint)">{{ index + 1 }}</td>
                <td :data-label="$t('Transaction No')">
                  <a :href="transactionRoute(row)" target="_blank" rel="noopener" class="it-link lg-main">{{ row.transaction_no }}</a>
                </td>
                <td :data-label="$t('Item Name')" class="it-wrap">{{ row.item_name }}</td>
                <td :data-label="$t('Date')">{{ row.date }}</td>
                <td :data-label="$t('Qty')" class="num lg-main">{{ formatQty(row.quantity) }}</td>
                <td :data-label="$t('Unit')">{{ row.unit }}</td>
                <td :data-label="$t('Type')">{{ row.transaction_type }}</td>
                <td :data-label="$t('Movement')">
                  <span class="lg-chip" :class="movementChipClass(row)" style="text-transform: none">
                    <icons :name="movementIcon(row)" class="w-3 h-3" />
                    {{ movementLabel(row) }}
                  </span>
                </td>
                <td :data-label="$t('Warehouse')" class="it-wrap">{{ row.warehouse_name }}</td>
                <td :data-label="$t('Contact')" class="it-wrap">{{ row.contact_name || '-' }}</td>
                <td :data-label="$t('Responsible Person')" class="it-wrap">{{ row.responsible_person || '-' }}</td>
              </tr>
              <tr v-if="rows.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="11">{{ emptyMessage }}</td>
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
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
    StatTiles,
    AutoComplete,
    DatePicker,
  },

  props: {
    filters: {
      type: Object,
      default: () => ({}),
    },
    items: {
      type: Array,
      default: () => [],
    },
    warehouses: {
      type: Array,
      default: () => [],
    },
    categories: {
      type: Array,
      default: () => [],
    },
    rows: {
      type: Array,
      default: () => [],
    },
  },

  data() {
    return {
      skipAutoSearch: false,
      searchDebounceId: null,
      sortBy: 'date',
      sortDirection: 'desc',
      form: {
        item_id: this.filters.item_id || null,
        category_id: this.filters.category_id || null,
        warehouse_id: this.filters.warehouse_id || null,
        start_date: this.filters.start_date || '',
        end_date: this.filters.end_date || '',
        transaction_type: this.filters.transaction_type || '',
        search: this.filters.search || '',
      },
    };
  },

  watch: {
    'form.item_id'(value) {
      if (value && this.form.category_id) {
        this.form.category_id = null;
      }
    },
    'form.category_id'(value) {
      if (value && this.form.item_id) {
        this.form.item_id = null;
      }
    },
    'form.warehouse_id'(value, oldValue) {
      if (!value || value === oldValue) {
        return;
      }

      const today = new Date();
      const fromDate = new Date(today);
      fromDate.setMonth(fromDate.getMonth() - 3);

      this.form.end_date = this.formatDateInput(today);
      this.form.start_date = this.formatDateInput(fromDate);
    },
    'form.search'() {
      if (this.skipAutoSearch) {
        return;
      }
      clearTimeout(this.searchDebounceId);
      this.searchDebounceId = setTimeout(() => {
        this.applyFilters();
      }, 350);
    },
  },

  computed: {
    statTiles() {
      return [
        { icon: 'collection', tone: 'indigo', label: this.$t('Total Rows'), value: this.rows.length },
        { icon: 'in', tone: 'emerald', label: this.$t('Checkin'), value: this.summary.checkin },
        { icon: 'out', tone: 'rose', label: this.$t('Checkout'), value: this.summary.checkout },
      ];
    },
    columns() {
      return [
        { key: 'row_no', label: this.$t('No'), num: true },
        { key: 'transaction_no', label: this.$t('Transaction No') },
        { key: 'item_name', label: this.$t('Item Name') },
        { key: 'date', label: this.$t('Date') },
        { key: 'quantity', label: this.$t('Qty'), num: true },
        { key: 'unit', label: this.$t('Unit') },
        { key: 'transaction_type', label: this.$t('Type') },
        { key: 'movement', label: this.$t('Movement') },
        { key: 'warehouse_name', label: this.$t('Warehouse') },
        { key: 'contact_name', label: this.$t('Contact') },
        { key: 'responsible_person', label: this.$t('Responsible Person') },
      ];
    },
    sortedRows() {
      const rowsWithIndex = this.rows.map((row, index) => ({ row, index }));
      const direction = this.sortDirection === 'asc' ? 1 : -1;

      return rowsWithIndex
        .slice()
        .sort((a, b) => {
          const left = this.sortValue(a.row, a.index, this.sortBy);
          const right = this.sortValue(b.row, b.index, this.sortBy);

          if (left === right) {
            return a.index - b.index;
          }

          if (typeof left === 'number' && typeof right === 'number') {
            return (left - right) * direction;
          }

          return String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: 'base' }) * direction;
        })
        .map(entry => entry.row);
    },
    summary() {
      return this.rows.reduce(
        (totals, row) => {
          const type = String(row.transaction_type || '').toLowerCase();
          if (type === 'checkin') totals.checkin += 1;
          if (type === 'checkout') totals.checkout += 1;
          if (type === 'transfer') totals.transfer += 1;
          return totals;
        },
        { checkin: 0, checkout: 0, transfer: 0 }
      );
    },
    itemSuggestions() {
      return this.items.map(item => ({
        id: item.id,
        name: item.code ? `${item.code} - ${item.name}` : item.name,
      }));
    },
    warehouseSuggestions() {
      return this.warehouses.map(warehouse => ({ id: warehouse.id, name: warehouse.name }));
    },
    categorySuggestions() {
      return this.categories.map(category => ({ id: category.id, name: category.name }));
    },
    emptyMessage() {
      if (!this.form.item_id && !this.form.category_id) {
        return this.$t('Please select an item or category to view transactions.');
      }
      return this.$t('There is no data to display.');
    },
  },

  methods: {
    toggleSort(column) {
      if (this.sortBy === column) {
        this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
        return;
      }

      this.sortBy = column;
      this.sortDirection = column === 'date' ? 'desc' : 'asc';
    },
    sortIndicator(column) {
      if (this.sortBy !== column) {
        return '';
      }
      return this.sortDirection === 'asc' ? '▲' : '▼';
    },
    sortValue(row, index, column) {
      if (column === 'row_no') {
        return index + 1;
      }
      if (column === 'quantity') {
        return Number(row.quantity || 0);
      }
      if (column === 'date') {
        const parsedDate = Date.parse(row.date);
        return Number.isNaN(parsedDate) ? 0 : parsedDate;
      }
      const value = row[column];
      return value == null ? '' : value;
    },
    routeParams(output = null) {
      const params = {
        item_id: this.form.item_id || undefined,
        category_id: this.form.category_id || undefined,
        warehouse_id: this.form.warehouse_id || undefined,
        start_date: this.form.start_date || undefined,
        end_date: this.form.end_date || undefined,
        transaction_type: this.form.transaction_type || undefined,
        search: this.form.search || undefined,
      };
      if (output) {
        params.output = output;
      }
      return params;
    },
    applyFilters() {
      this.$inertia.get(this.route('reports.item_transactional'), this.routeParams(), {
        preserveState: true,
        replace: true,
      });
    },
    resetFilters() {
      this.skipAutoSearch = true;
      this.form = {
        item_id: null,
        category_id: null,
        warehouse_id: null,
        start_date: '',
        end_date: '',
        transaction_type: '',
        search: '',
      };
      this.$inertia.get(this.route('reports.item_transactional'), {}, { preserveState: true, replace: true });
      this.$nextTick(() => {
        this.skipAutoSearch = false;
      });
    },
    transactionRoute(row) {
      return this.route('reports.item_transactional.form', {
        transactionKey: row.transaction_key,
        transactionId: row.transaction_id,
      });
    },
    openPrint() {
      if (!this.form.item_id && !this.form.category_id) {
        return;
      }
      window.open(this.route('reports.item_transactional', this.routeParams('print')), '_blank');
    },
    downloadPdf() {
      if (!this.form.item_id && !this.form.category_id) {
        return;
      }
      window.open(this.route('reports.item_transactional', this.routeParams('pdf')), '_blank');
    },
    formatQty(value) {
      return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    movementChipClass(row) {
      if (row.transaction_key === 'checkin') {
        return 'lg-chip-green';
      }
      if (row.transaction_key === 'transfer') {
        return 'lg-chip-indigo';
      }
      return row.movement === 'OUT' ? 'lg-chip-red' : 'lg-chip-green';
    },
    movementIcon(row) {
      if (row.transaction_key === 'checkin') {
        return 'in';
      }
      if (row.transaction_key === 'transfer') {
        return 'refresh';
      }
      return row.movement === 'OUT' ? 'out' : 'in';
    },
    movementLabel(row) {
      if (row.transaction_key === 'checkin') {
        return this.$t('CHECKIN IN');
      }
      if (row.transaction_key === 'transfer') {
        return this.$t(row.movement === 'IN' || row.movement === 'OUT' ? `TRANSFER ${row.movement}` : 'TRANSFER');
      }
      return row.movement;
    },
    formatDateInput(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
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
.it-input,
.it-select {
  border-radius: 0.85rem !important;
  border: 1px solid var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.it-input,
.it-select {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  font-size: 0.85rem;
}
.it-input:focus,
.it-select:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
  border-color: #60a5fa !important;
}
.it-filters {
  position: relative;
  z-index: 20;
}
.it-filter-btns {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 1rem;
}
.it-link {
  color: var(--lg-navy);
}
.it-link:hover {
  text-decoration: underline;
}
html.dark .it-link {
  color: #93c5fd;
}
.it-wrap {
  white-space: normal;
}

@media (max-width: 767.98px) {
  .item-tx-from,
  .item-tx-to,
  .item-tx-search {
    width: 100% !important;
    min-width: 100% !important;
  }
  .it-filter-btns .lg-btn {
    flex: 1 1 auto;
  }
}

.item-tx-sort-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  color: inherit;
  border-radius: 0.5rem;
  padding: 0.15rem 0.35rem;
  transition: all 160ms ease;
}
.item-tx-sort-btn:hover {
  color: var(--lg-navy);
  background: rgba(99, 102, 241, 0.12);
}
.item-tx-sort-btn:focus-visible {
  outline: 2px solid rgba(79, 70, 229, 0.4);
  outline-offset: 2px;
}
.item-tx-sort-btn-right {
  justify-content: flex-end;
  width: 100%;
}
.item-tx-sort-indicator {
  min-width: 0.8rem;
  text-align: center;
  font-size: 0.7rem;
  color: #4338ca;
}
html.dark .item-tx-sort-indicator {
  color: #a5b4fc;
}
</style>
