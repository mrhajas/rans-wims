<template>
  <admin-layout :title="$t('x_report', { x: $t('Material Reallocation Summary') })">
    <div class="lg-page px-4 md:px-0 rc-form">
      <page-header
        icon="toggle"
        :title="$t('Material Reallocation Summary')"
        :subtitle="$t('Header-level summary of all material reallocations.')"
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
            <label class="rc-label">{{ $t('Search') }}</label>
            <div class="rc-search">
              <icons name="search" class="w-4 h-4 rc-search-ico" />
              <input v-model="form.search" type="text" :placeholder="$t('Reference…')" class="rc-search-input" />
            </div>
          </div>
          <div>
            <label class="rc-label">{{ $t('Status') }}</label>
            <auto-complete v-model="form.status" :suggestions="statuses" :clearable="true" :placeholder="$t('All Statuses')" class="w-full" />
          </div>
          <div>
            <label class="rc-label">{{ $t('Source Warehouse') }}</label>
            <auto-complete v-model="form.source_warehouse_id" :suggestions="warehouses" :clearable="true" :placeholder="$t('All Warehouses')" class="w-full" />
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
      </section>

      <div class="rc-toolbar print:hidden">
        <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ materialReallocations.meta.total }} {{ $t('result(s)') }}</span>
        <div class="rc-toolbar-btns">
          <button type="button" class="lg-btn lg-btn-dark" @click="openPrint">
            <icons name="printer" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Print') }}</span>
          </button>
          <button type="button" class="lg-btn lg-btn-red" @click="downloadPdf">
            <icons name="download" class="w-4 h-4" />
            <span class="hidden sm:inline">{{ $t('Download PDF') }}</span>
          </button>
          <a :href="route('reports.material_reallocation_summary', currentFilters('excel'))" class="lg-btn lg-btn-green">
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
                <th class="is-sortable" @click="setSort('reference')"><span class="lg-sort">{{ $t('Reference') }} <span class="rc-sort">{{ sortIndicator('reference') }}</span></span></th>
                <th>{{ $t('Source Warehouse') }}</th>
                <th>{{ $t('Requested By') }}</th>
                <th class="is-sortable" @click="setSort('status')"><span class="lg-sort">{{ $t('Status') }} <span class="rc-sort">{{ sortIndicator('status') }}</span></span></th>
                <th class="is-sortable" @click="setSort('date_requested')"><span class="lg-sort">{{ $t('Date Requested') }} <span class="rc-sort">{{ sortIndicator('date_requested') }}</span></span></th>
                <th class="num">{{ $t('Items') }}</th>
                <th class="num">{{ $t('Requested Qty') }}</th>
                <th class="num">{{ $t('Allocated Qty') }}</th>
                <th class="num">{{ $t('Received Qty') }}</th>
                <th class="num">{{ $t('Value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in materialReallocations.data" :key="row.id">
                <td :data-label="$t('Reference')"><Link :href="route('material-reallocations.show', row.id)" class="rc-link">{{ row.reference }}</Link></td>
                <td :data-label="$t('Source Warehouse')">{{ row.source_warehouse?.name }}</td>
                <td :data-label="$t('Requested By')">{{ row.user?.name }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip lg-chip-indigo">{{ row.status }}</span></td>
                <td :data-label="$t('Date Requested')">{{ $date(row.date_requested) }}</td>
                <td :data-label="$t('Items')" class="num">{{ row.items_count }}</td>
                <td :data-label="$t('Requested Qty')" class="num">{{ $number(row.total_requested_qty) }}</td>
                <td :data-label="$t('Allocated Qty')" class="num">{{ $number(row.total_allocated_qty) }}</td>
                <td :data-label="$t('Received Qty')" class="num">{{ $number(row.total_received_qty) }}</td>
                <td :data-label="$t('Value')" class="num rc-strong">{{ $number(row.total_value) }}</td>
              </tr>
              <tr v-if="!materialReallocations.data.length" class="lg-empty-row">
                <td class="lg-empty" colspan="10">{{ $t('There is no data to display.') }}</td>
              </tr>
              <tr v-if="materialReallocations.data.length > 1" class="rc-foot">
                <td :data-label="$t('Page total')" colspan="5">{{ $t('Page total') }}</td>
                <td :data-label="$t('Items')" class="num">{{ pageTotals.items }}</td>
                <td :data-label="$t('Requested Qty')" class="num">{{ $number(pageTotals.requested) }}</td>
                <td :data-label="$t('Allocated Qty')" class="num">{{ $number(pageTotals.allocated) }}</td>
                <td :data-label="$t('Received Qty')" class="num">{{ $number(pageTotals.received) }}</td>
                <td :data-label="$t('Value')" class="num">{{ $number(pageTotals.value) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination class="mt-6" :meta="materialReallocations.meta" :links="materialReallocations.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, DatePicker, Pagination, Link, PageHeader, StatTiles },

  props: {
    filters: Object,
    statuses: Array,
    warehouses: Array,
    users: Array,
    materialReallocations: Object,
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        status: this.filters.status,
        source_warehouse_id: this.filters.source_warehouse_id,
        user_id: this.filters.user_id,
        start_date: this.filters.start_date,
        end_date: this.filters.end_date,
        sort: this.filters.sort,
        sort_direction: this.filters.sort_direction,
      },
    };
  },

  computed: {
    rows() {
      return (this.materialReallocations && this.materialReallocations.data) || [];
    },
    pageTotals() {
      const sum = key => this.rows.reduce((s, r) => s + (Number(r[key]) || 0), 0);

      return {
        items: sum('items_count'),
        requested: sum('total_requested_qty'),
        allocated: sum('total_allocated_qty'),
        received: sum('total_received_qty'),
        value: sum('total_value'),
      };
    },
    statTiles() {
      const meta = (this.materialReallocations && this.materialReallocations.meta) || {};

      return [
        { icon: 'toggle', tone: 'indigo', label: this.$t('Total Records'), value: meta.total != null ? meta.total : this.rows.length },
        { icon: 'collection', tone: 'sky', label: this.$t('Requested Qty'), value: this.$number(this.pageTotals.requested) },
        { icon: 'truck', tone: 'amber', label: this.$t('Allocated Qty'), value: this.$number(this.pageTotals.allocated) },
        { icon: 'tick', tone: 'emerald', label: this.$t('Received Qty'), value: this.$number(this.pageTotals.received) },
        { icon: 'cash', tone: 'violet', label: this.$t('Value'), value: this.$number(this.pageTotals.value) },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('reports.material_reallocation_summary', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          replace: true,
        });
      }, 300),
      deep: true,
    },
  },

  methods: {
    resetFilters() {
      this.form.search = null;
      this.form.status = null;
      this.form.source_warehouse_id = null;
      this.form.start_date = null;
      this.form.end_date = null;
    },
    currentFilters(output = null) {
      const params = pickBy(this.form);
      if (output) {
        params.output = output;
      }
      return params;
    },
    openPrint() {
      window.open(this.route('reports.material_reallocation_summary', this.currentFilters('print')), '_blank');
    },
    downloadPdf() {
      window.open(this.route('reports.material_reallocation_summary', this.currentFilters('pdf')), '_blank');
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
