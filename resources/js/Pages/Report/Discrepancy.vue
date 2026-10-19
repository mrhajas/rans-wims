<template>
  <admin-layout :title="$t('Discrepancy Report')">
    <div class="lg-page px-4 md:px-0 rc-form">
      <page-header
        icon="exclamation"
        :title="$t('Discrepancy Report')"
        :subtitle="$t('Received vs allocated quantity mismatches.')"
      />

      <stat-tiles :items="statTiles" />

      <section class="lg-card rc-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset Filters') }}</button>
        </header>
        <div class="lg-fields">
          <div>
            <label class="rc-label">{{ $t('Destination Warehouse') }}</label>
            <auto-complete v-model="form.destination_warehouse_id" :suggestions="warehouses" :clearable="true" :placeholder="$t('All Warehouses')" class="w-full" />
          </div>
          <div>
            <label class="rc-label">{{ $t('Date From') }}</label>
            <date-picker v-model="form.start_date" :placeholder="$t('Date From')" clearable />
          </div>
          <div>
            <label class="rc-label">{{ $t('Date To') }}</label>
            <date-picker v-model="form.end_date" :placeholder="$t('Date To')" clearable />
          </div>
        </div>
        <div class="rc-checks">
          <label class="rc-check">
            <input type="checkbox" v-model="form.only_under_received" class="sr-only" />
            <span class="rc-check-box"><icons name="tick" class="w-3 h-3" /></span>
            {{ $t('Only Under-Received') }}
          </label>
          <label class="rc-check">
            <input type="checkbox" v-model="form.only_over_received" class="sr-only" />
            <span class="rc-check-box"><icons name="tick" class="w-3 h-3" /></span>
            {{ $t('Only Over-Received') }}
          </label>
        </div>
      </section>

      <div class="rc-toolbar print:hidden">
        <span class="lg-chip lg-chip-rose" style="text-transform: none">{{ discrepancies.meta.total }} {{ $t('result(s)') }}</span>
        <div class="rc-toolbar-btns">
          <button type="button" class="lg-btn lg-btn-dark" @click="openPrint">
            <icons name="printer" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Print') }}</span>
          </button>
          <button type="button" class="lg-btn lg-btn-red" @click="downloadPdf">
            <icons name="download" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Download PDF') }}</span>
          </button>
          <a :href="route('reports.discrepancy', currentFilters('excel'))" class="lg-btn lg-btn-green">
            <icons name="table" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Excel') }}</span>
          </a>
        </div>
      </div>

      <section class="lg-card rc-table-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Item') }}</th>
                <th>{{ $t('Destination Warehouse') }}</th>
                <th class="is-sortable num" @click="setSort('allocated_quantity')"><span class="lg-sort">{{ $t('Allocated') }} <span class="rc-sort">{{ sortIndicator('allocated_quantity') }}</span></span></th>
                <th class="is-sortable num" @click="setSort('received_quantity')"><span class="lg-sort">{{ $t('Received') }} <span class="rc-sort">{{ sortIndicator('received_quantity') }}</span></span></th>
                <th class="num">{{ $t('Delta') }}</th>
                <th class="num">{{ $t('Delta %') }}</th>
                <th>{{ $t('Remarks') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in discrepancies.data" :key="row.id">
                <td :data-label="$t('Reference')"><span class="lg-mono">{{ row.reference }}</span></td>
                <td :data-label="$t('Item')"><div class="lg-main">{{ row.item?.name }}</div><div v-if="row.item?.code" class="lg-subtext">{{ row.item.code }}</div></td>
                <td :data-label="$t('Destination Warehouse')">{{ row.destination_warehouse?.name }}</td>
                <td :data-label="$t('Allocated')" class="num">{{ formatQty(row.allocated_quantity) }}</td>
                <td :data-label="$t('Received')" class="num">{{ formatQty(row.received_quantity) }}</td>
                <td :data-label="$t('Delta')" class="num" :class="row.delta < 0 ? 'rc-bad' : 'rc-good'">{{ row.delta >= 0 ? '+' : '' }}{{ formatQty(row.delta) }}</td>
                <td :data-label="$t('Delta %')" class="num" :class="row.delta < 0 ? 'rc-bad' : 'rc-good'">{{ row.delta_percent != null ? row.delta_percent + '%' : '-' }}</td>
                <td :data-label="$t('Remarks')">{{ row.remarks }}</td>
              </tr>
              <tr v-if="!discrepancies.data.length" class="lg-empty-row">
                <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
              </tr>
              <tr v-if="discrepancies.data.length > 1" class="rc-foot">
                <td :data-label="$t('Page total')" colspan="3">{{ $t('Page total') }}</td>
                <td :data-label="$t('Allocated')" class="num">{{ formatQty(pageTotals.allocated) }}</td>
                <td :data-label="$t('Received')" class="num">{{ formatQty(pageTotals.received) }}</td>
                <td :data-label="$t('Delta')" class="num" :class="pageTotals.delta < 0 ? 'rc-bad' : 'rc-good'">{{ pageTotals.delta >= 0 ? '+' : '' }}{{ formatQty(pageTotals.delta) }}</td>
                <td colspan="2"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination class="mt-6" :meta="discrepancies.meta" :links="discrepancies.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, DatePicker, Pagination, PageHeader, StatTiles },

  props: {
    filters: Object,
    warehouses: Array,
    discrepancies: Object,
  },

  data() {
    return {
      form: {
        destination_warehouse_id: this.filters.destination_warehouse_id,
        start_date: this.filters.start_date,
        end_date: this.filters.end_date,
        only_under_received: !!this.filters.only_under_received,
        only_over_received: !!this.filters.only_over_received,
        sort: this.filters.sort,
        sort_direction: this.filters.sort_direction,
      },
    };
  },

  computed: {
    rows() {
      return (this.discrepancies && this.discrepancies.data) || [];
    },
    pageTotals() {
      const sum = key => this.rows.reduce((s, r) => s + (Number(r[key]) || 0), 0);

      return { allocated: sum('allocated_quantity'), received: sum('received_quantity'), delta: sum('delta') };
    },
    statTiles() {
      const meta = (this.discrepancies && this.discrepancies.meta) || {};

      return [
        { icon: 'exclamation', tone: 'rose', label: this.$t('Total Records'), value: meta.total != null ? meta.total : this.rows.length },
        { icon: 'down', tone: 'amber', label: this.$t('Under-Received (this page)'), value: this.rows.filter(r => r.delta < 0).length },
        { icon: 'up', tone: 'emerald', label: this.$t('Over-Received (this page)'), value: this.rows.filter(r => r.delta > 0).length },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('reports.discrepancy', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          replace: true,
        });
      }, 300),
      deep: true,
    },
  },

  methods: {
    resetFilters() {
      this.form.destination_warehouse_id = null;
      this.form.start_date = null;
      this.form.end_date = null;
      this.form.only_under_received = false;
      this.form.only_over_received = false;
    },
    currentFilters(output = null) {
      const params = pickBy(this.form);
      if (output) {
        params.output = output;
      }
      return params;
    },
    openPrint() {
      window.open(this.route('reports.discrepancy', this.currentFilters('print')), '_blank');
    },
    downloadPdf() {
      window.open(this.route('reports.discrepancy', this.currentFilters('pdf')), '_blank');
    },
    setSort(column) {
      if (this.form.sort === column) {
        this.form.sort_direction = this.form.sort_direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.sort_direction = 'asc';
      }
    },
    sortIndicator(column) {
      if (this.form.sort !== column) return '';
      return this.form.sort_direction === 'asc' ? '▲' : '▼';
    },
    formatQty(value) {
      const n = Number(value || 0);
      return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
  },
};
</script>

<style scoped>
.rc-form :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.rc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.rc-label {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
/* only the filters card gets a z-index, so open dropdowns sit above the cards below */
.rc-filters {
  position: relative;
  z-index: 30;
}
.rc-search {
  position: relative;
}
.rc-search-ico {
  position: absolute;
  left: 0.85rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lg-faint);
  pointer-events: none;
}
.rc-search-input {
  display: block;
  width: 100%;
  padding: 0.5rem 1rem 0.5rem 2.4rem;
  font-size: 0.84rem;
  color: var(--lg-ink);
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  border-radius: 0.85rem;
}
.rc-search-input:focus {
  outline: none;
  border-color: #818cf8;
  box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.22);
}
.rc-checks {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 22px;
  margin-top: 1rem;
  padding-top: 0.9rem;
  border-top: 1px dashed var(--lg-line);
}
.rc-check {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  cursor: pointer;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--lg-ink);
}
.rc-check-box {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 7px;
  color: transparent;
  background: var(--lg-card);
  border: 1.5px solid #cbd5e1;
  transition: all 0.15s ease;
}
.rc-check input:checked + .rc-check-box {
  color: #fff;
  border-color: transparent;
  background: linear-gradient(145deg, #fb7185, #be123c);
  box-shadow: 0 6px 12px -6px rgba(190, 18, 60, 0.8);
}
.rc-check input:focus-visible + .rc-check-box {
  outline: 2px solid #818cf8;
  outline-offset: 2px;
}
.rc-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 1rem;
  padding: 0.6rem 0.8rem 0.6rem 1.1rem;
  border-radius: 9999px;
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  box-shadow: var(--lg-shadow);
}
.rc-toolbar-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.rc-table-card {
  padding: 0.6rem;
}
.rc-link {
  font-weight: 800;
  color: var(--lg-navy);
}
.rc-link:hover {
  text-decoration: underline;
}
.rc-strong {
  font-weight: 800;
}
.rc-good {
  color: #047857;
  font-weight: 800;
}
.rc-bad {
  color: #be123c;
  font-weight: 800;
}
.rc-sort {
  font-size: 0.7rem;
}
.rc-foot td {
  font-weight: 800;
  background: var(--lg-soft);
}
.lg-table tbody tr.rc-foot:hover td {
  background: var(--lg-soft);
}
html.dark .rc-link {
  color: #93c5fd;
}
html.dark .rc-good {
  color: #6ee7b7;
}
html.dark .rc-bad {
  color: #fda4af;
}
html.dark .rc-check-box {
  border-color: rgba(255, 255, 255, 0.25);
}
html.dark .rc-search-input:focus {
  border-color: #a5b4fc;
}
@media (max-width: 767.98px) {
  .rc-toolbar {
    border-radius: 22px;
  }
}
</style>
