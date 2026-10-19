<template>
  <admin-layout :title="$t('Timely Material Usage')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="chart-bar"
        :title="$t('Timely Material Usage')"
        :subtitle="$t('Accumulated checkout usage by project and date range')"
      >
        <button type="button" class="lg-btn lg-btn-soft" @click="openPrint"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
        <button type="button" class="lg-btn lg-btn-red" @click="downloadPdf"><icons name="doc" class="w-4 h-4" />{{ $t('Download PDF') }}</button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card tm-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Project / Warehouse') }} / {{ $t('Date From') }} / {{ $t('Date To') }}</p>
          </div>
        </header>
        <form @submit.prevent="applyFilters">
          <div class="lg-fields">
            <div>
              <label class="fx-label">
                <span class="inline-flex items-center gap-1"><icons name="building" class="h-4 w-4" />{{ $t('Project / Warehouse') }}</span>
              </label>
              <auto-complete
                clearable
                :suggestions="warehouses"
                v-model="form.warehouse_id"
                :placeholder="canViewAllProjects ? $t('All Accessible') : $t('Select Project')"
              />
            </div>
            <div>
              <label class="fx-label">
                <span class="inline-flex items-center gap-1"><icons name="calendar" class="h-4 w-4" />{{ $t('Date From') }}</span>
              </label>
              <date-picker v-model="form.start_date" clearable />
            </div>
            <div>
              <label class="fx-label">
                <span class="inline-flex items-center gap-1"><icons name="calendar" class="h-4 w-4" />{{ $t('Date To') }}</span>
              </label>
              <date-picker v-model="form.end_date" clearable />
            </div>
          </div>
          <div class="tm-btns">
            <button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Apply') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters"><icons name="cross" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Timely Material Usage') }}</h2>
          </div>
          <div class="tm-search-wrap print:hidden">
            <icons name="search" class="h-4 w-4 tm-search-ico" />
            <input v-model="search" type="text" class="tm-input" :placeholder="$t('Search item in table')" />
          </div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="num">{{ $t('No') }}</th>
                <th>{{ $t('Item') }}</th>
                <th class="num">{{ $t('Accumulated Quantity') }}</th>
                <th>{{ $t('Unit') }}</th>
                <th v-if="showCostColumns" class="num">{{ $t('Unit Price') }}</th>
                <th v-if="showCostColumns" class="num">{{ $t('Total Price') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in filteredRows" :key="`${row.item_id}-${row.unit}`">
                <td :data-label="$t('No')" class="num" style="color: var(--lg-faint)">{{ index + 1 }}</td>
                <td :data-label="$t('Item')" class="tm-name lg-main">{{ row.item_name }}</td>
                <td :data-label="$t('Accumulated Quantity')" class="num">{{ formatQty(row.accumulated_quantity) }}</td>
                <td :data-label="$t('Unit')">{{ row.unit }}</td>
                <td v-if="showCostColumns" :data-label="$t('Unit Price')" class="num">{{ formatMoney(row.unit_price) }}</td>
                <td v-if="showCostColumns" :data-label="$t('Total Price')" class="num lg-main">{{ formatMoney(row.total_price) }}</td>
              </tr>
              <tr v-if="filteredRows.length === 0" class="lg-empty-row">
                <td class="lg-empty" :colspan="showCostColumns ? 6 : 4">{{ $t('There is no data to display.') }}</td>
              </tr>
              <tr v-if="showCostColumns && filteredRows.length" class="tm-foot">
                <td :data-label="$t('Total Cost')" colspan="5" class="tm-foot-label">{{ $t('Total Cost') }}</td>
                <td :data-label="$t('Total Cost')" class="num">{{ formatMoney(filteredTotalCost) }}</td>
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
    warehouses: {
      type: Array,
      default: () => [],
    },
    rows: {
      type: Array,
      default: () => [],
    },
    showCostColumns: {
      type: Boolean,
      default: false,
    },
    canViewAllProjects: {
      type: Boolean,
      default: false,
    },
    totalTableCost: {
      type: Number,
      default: 0,
    },
  },

  data() {
    return {
      form: {
        warehouse_id: this.filters.warehouse_id ? String(this.filters.warehouse_id) : '',
        start_date: this.filters.start_date || '',
        end_date: this.filters.end_date || '',
      },
      search: this.filters.search || '',
    };
  },

  computed: {
    statTiles() {
      return [
        { icon: 'list', tone: 'sky', label: this.$t('Rows'), value: this.filteredRows.length },
        { icon: 'collection', tone: 'indigo', label: this.$t('Unique Items'), value: this.rows.length },
        { icon: 'cash', tone: 'emerald', label: this.$t('Visible Cost'), value: this.showCostColumns ? this.formatMoney(this.filteredTotalCost) : '-' },
      ];
    },
    filteredRows() {
      const query = String(this.search || '').toLowerCase().trim();
      if (!query) {
        return this.rows;
      }

      return this.rows.filter((row) => {
        const name = String(row.item_name || '').toLowerCase();
        return name.includes(query);
      });
    },
    filteredTotalCost() {
      return this.filteredRows.reduce((sum, row) => sum + Number(row.total_price || 0), 0);
    },
  },

  methods: {
    applyFilters() {
      const params = {
        warehouse_id: this.form.warehouse_id || undefined,
        start_date: this.form.start_date || undefined,
        end_date: this.form.end_date || undefined,
        search: this.search || undefined,
      };

      this.$inertia.get(this.route('reports.timely_material_usage'), params, {
        preserveState: true,
        replace: true,
      });
    },
    resetFilters() {
      this.form = {
        warehouse_id: '',
        start_date: '',
        end_date: '',
      };
      this.search = '';
      this.$inertia.get(this.route('reports.timely_material_usage'), {}, { preserveState: true, replace: true });
    },
    outputParams(output) {
      return {
        warehouse_id: this.form.warehouse_id || undefined,
        start_date: this.form.start_date || undefined,
        end_date: this.form.end_date || undefined,
        search: this.search || undefined,
        output,
      };
    },
    openPrint() {
      window.open(this.route('reports.timely_material_usage', this.outputParams('print')), '_blank');
    },
    downloadPdf() {
      window.open(this.route('reports.timely_material_usage', this.outputParams('pdf')), '_blank');
    },
    formatQty(value) {
      return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    formatMoney(value) {
      return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
.tm-input {
  border-radius: 0.85rem !important;
  border: 1px solid var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.tm-input {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem 0.5rem 2.2rem;
  font-size: 0.85rem;
}
.tm-input:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
  border-color: #60a5fa !important;
}
.tm-search-wrap {
  position: relative;
  width: 100%;
  max-width: 18rem;
}
.tm-search-ico {
  position: absolute;
  left: 0.8rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lg-faint);
  z-index: 1;
}
.tm-filters {
  position: relative;
  z-index: 20;
}
.tm-btns {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 1rem;
}
.tm-name {
  white-space: normal;
}
.tm-foot td {
  font-weight: 800;
  background: var(--lg-soft);
}
.tm-foot-label {
  text-align: right;
}
@media (max-width: 767.98px) {
  .tm-btns .lg-btn {
    flex: 1 1 auto;
  }
  .tm-search-wrap {
    max-width: none;
  }
  .lg-card-head:has(.tm-search-wrap) {
    flex-wrap: wrap;
  }
  .tm-foot-label {
    text-align: right;
  }
}
</style>
