<template>
  <admin-layout :title="$t('Material Movement Report')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="plane"
        :title="$t('Material Movement Report')"
        :subtitle="$t('Line-item detail of what moved from source to destination via Material Reallocation.')"
      >
        <template v-if="hasSearched">
          <button type="button" class="lg-btn lg-btn-dark" @click="openPrint"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
          <button type="button" class="lg-btn lg-btn-red" @click="downloadPdf"><icons name="download" class="w-4 h-4" />{{ $t('Download PDF') }}</button>
          <a :href="route('reports.material_movement', currentFilters('excel'))" class="lg-btn lg-btn-green"><icons name="doc" class="w-4 h-4" />{{ $t('Excel') }}</a>
        </template>
      </page-header>

      <stat-tiles v-if="hasSearched" :items="statTiles" />

      <!-- Filters -->
      <section class="lg-card mmv-filters-panel print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Item') }} / {{ $t('Source Warehouse') }} / {{ $t('Destination Warehouse') }} / {{ $t('Date From') }} / {{ $t('Date To') }}</p>
          </div>
        </header>
        <div class="lg-fields">
          <div>
            <label class="mmv-field-label">{{ $t('Item') }}</label>
            <auto-complete v-model="form.item_id" :suggestions="itemOptions" :clearable="true" :placeholder="$t('All Items')" class="mmv-autocomplete" />
          </div>
          <div>
            <label class="mmv-field-label">{{ $t('Source Warehouse') }}</label>
            <auto-complete v-model="form.source_warehouse_id" :suggestions="warehouses" :clearable="true" :placeholder="$t('All Source Warehouses')" class="mmv-autocomplete" />
          </div>
          <div>
            <label class="mmv-field-label">{{ $t('Destination Warehouse') }}</label>
            <auto-complete v-model="form.destination_warehouse_id" :suggestions="warehouses" :clearable="true" :placeholder="$t('All Destination Warehouses')" class="mmv-autocomplete" />
          </div>
          <div>
            <label class="mmv-field-label">{{ $t('Date From') }}</label>
            <date-picker v-model="form.start_date" :placeholder="$t('Date From')" clearable />
          </div>
          <div>
            <label class="mmv-field-label">{{ $t('Date To') }}</label>
            <date-picker v-model="form.end_date" :placeholder="$t('Date To')" clearable />
          </div>
          <div class="mmv-check-cell">
            <label class="mmv-check-toggle">
              <input type="checkbox" v-model="form.only_completed" class="sr-only" />
              <span class="mmv-check-box"><icons name="tick" class="w-3 h-3" /></span>
              {{ $t('Only Completed') }}
            </label>
          </div>
        </div>

        <div class="mmv-btns">
          <button type="button" class="lg-btn lg-btn-primary" :disabled="loading" @click="search">
            <icons v-if="!loading" name="search" class="w-4 h-4" />
            <svg v-else class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            {{ loading ? $t('Searching…') : $t('Apply Filters') }}
          </button>
          <button type="button" class="lg-btn lg-btn-soft" @click="resetAll">
            <icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}
          </button>
        </div>
      </section>

      <!-- Empty state before any search -->
      <section v-if="!hasSearched" class="lg-card text-center" style="padding-top: 3rem; padding-bottom: 3rem">
        <div class="mmv-empty-ico"><icons name="plane" class="w-8 h-8" /></div>
        <p class="lg-card-sub" style="font-size: 0.85rem">{{ $t('Choose an item, warehouse, date range, or status above, then apply filters.') }}</p>
      </section>

      <template v-else>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="table" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Material Movement Report') }}</h2>
            </div>
            <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ movements ? movements.meta.total : 0 }} {{ $t('result(s)') }}</span>
          </header>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Reference') }}</th>
                  <th>{{ $t('Item') }}</th>
                  <th>{{ $t('Source Warehouse') }}</th>
                  <th>{{ $t('Destination Warehouse') }}</th>
                  <th class="num is-sortable" @click="setSort('requested_quantity')"><span class="lg-sort">{{ $t('Requested') }} <span>{{ sortIndicator('requested_quantity') }}</span></span></th>
                  <th class="num is-sortable" @click="setSort('allocated_quantity')"><span class="lg-sort">{{ $t('Allocated') }} <span>{{ sortIndicator('allocated_quantity') }}</span></span></th>
                  <th class="num is-sortable" @click="setSort('received_quantity')"><span class="lg-sort">{{ $t('Received') }} <span>{{ sortIndicator('received_quantity') }}</span></span></th>
                  <th class="text-center">{{ $t('Unit') }}</th>
                  <th>{{ $t('Status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in (movements ? movements.data : [])" :key="row.id">
                  <td :data-label="$t('Reference')">
                    <Link :href="route('material-reallocations.show', row.material_reallocation_id)" class="mmv-link lg-main">{{ row.reference }}</Link>
                  </td>
                  <td :data-label="$t('Item')">
                    <div class="lg-main">{{ row.item?.name }}</div>
                    <div v-if="row.item?.code" class="lg-subtext lg-mono">{{ row.item.code }}</div>
                  </td>
                  <td :data-label="$t('Source Warehouse')">{{ row.source_warehouse?.name || '-' }}</td>
                  <td :data-label="$t('Destination Warehouse')">{{ row.destination_warehouse?.name || '-' }}</td>
                  <td :data-label="$t('Requested')" class="num">{{ formatQty(row.requested_quantity) }}</td>
                  <td :data-label="$t('Allocated')" class="num">{{ row.allocated_quantity != null ? formatQty(row.allocated_quantity) : '-' }}</td>
                  <td :data-label="$t('Received')" class="num lg-main">{{ row.received_quantity != null ? formatQty(row.received_quantity) : '-' }}</td>
                  <td :data-label="$t('Unit')" class="text-center">{{ row.unit?.code || row.unit?.name || '-' }}</td>
                  <td :data-label="$t('Status')"><span class="lg-chip lg-chip-slate mmv-status">{{ row.status }}</span></td>
                </tr>
                <tr v-if="!movements || !movements.data.length" class="lg-empty-row">
                  <td class="lg-empty" colspan="9">{{ $t('No matching movement found for the selected filters.') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
        <pagination v-if="movements" class="mt-6" :meta="movements.meta" :links="movements.links" />
      </template>
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
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
    filters: { type: Object, default: () => ({}) },
    hasFilters: { type: Boolean, default: false },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    movements: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        item_id: this.filters.item_id || null,
        source_warehouse_id: this.filters.source_warehouse_id || null,
        destination_warehouse_id: this.filters.destination_warehouse_id || null,
        start_date: this.filters.start_date || '',
        end_date: this.filters.end_date || '',
        only_completed: !!this.filters.only_completed,
        sort: this.filters.sort || null,
        sort_direction: this.filters.sort_direction || null,
      },
      loading: false,
      hasSearched: this.hasFilters,
    };
  },

  computed: {
    statTiles() {
      const rows = this.movements ? this.movements.data : [];
      const sum = key => rows.reduce((total, r) => total + (Number(r[key]) || 0), 0);
      return [
        { icon: 'collection', tone: 'violet', label: this.$t('result(s)'), value: this.movements ? this.movements.meta.total : 0 },
        { icon: 'out', tone: 'amber', label: this.$t('Requested'), value: this.formatQty(sum('requested_quantity')) },
        { icon: 'refresh', tone: 'sky', label: this.$t('Allocated'), value: this.formatQty(sum('allocated_quantity')) },
        { icon: 'in', tone: 'emerald', label: this.$t('Received'), value: this.formatQty(sum('received_quantity')) },
      ];
    },
    itemOptions() {
      return this.items.map(i => ({ value: i.id, label: `${i.code ? i.code + ' - ' : ''}${i.name}` }));
    },
  },

  methods: {
    currentFilters(output = null) {
      const params = pickBy(this.form);
      if (output) {
        params.output = output;
      }
      return params;
    },
    search() {
      this.loading = true;
      this.hasSearched = true;
      this.$inertia.get(this.route('reports.material_movement'), this.currentFilters(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => { this.loading = false; },
      });
    },
    resetAll() {
      this.form = {
        item_id: null, source_warehouse_id: null, destination_warehouse_id: null,
        start_date: '', end_date: '', only_completed: false, sort: null, sort_direction: null,
      };
      this.hasSearched = false;
      this.$inertia.get(this.route('reports.material_movement'));
    },
    openPrint() {
      window.open(this.route('reports.material_movement', this.currentFilters('print')), '_blank');
    },
    downloadPdf() {
      window.open(this.route('reports.material_movement', this.currentFilters('pdf')), '_blank');
    },
    setSort(column) {
      if (this.form.sort === column) {
        this.form.sort_direction = this.form.sort_direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.sort_direction = 'asc';
      }
      this.search();
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
.mmv-filters-panel { position: relative; z-index: 30; }

.mmv-field-label,
.fx-form :deep(label:not(:has(input[type='checkbox']))) {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--lg-mute);
}
.mmv-field-label { margin-bottom: 4px; }
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.mmv-autocomplete :deep(input:focus) {
  border-color: #c4b5fd !important;
  box-shadow: 0 0 0 3px rgba(196, 181, 253, 0.3) !important;
}

.mmv-check-cell { display: flex; align-items: flex-end; }
/* position:relative — contains an .sr-only checkbox so offsetParent does not fall through to <body>. */
.mmv-check-toggle { position: relative; display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem !important; font-weight: 700; text-transform: none !important; color: var(--lg-ink) !important; cursor: pointer; padding: 0.55rem 0; }
.mmv-check-box { display: inline-flex; align-items: center; justify-content: center; height: 18px; width: 18px; border-radius: 6px; border: 1.5px solid var(--lg-faint); color: transparent; flex: none; transition: all 0.15s ease; }
.mmv-check-toggle:has(input:checked) .mmv-check-box { background: #7c3aed; border-color: #7c3aed; color: #fff; }
.mmv-check-toggle:has(input:focus-visible) .mmv-check-box { box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.4); }

.mmv-btns { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; margin-top: 1rem; }

.mmv-empty-ico {
  display: inline-flex; align-items: center; justify-content: center; height: 64px; width: 64px; margin-bottom: 1rem;
  border-radius: 1.1rem; background: var(--lg-soft); color: var(--lg-faint);
}
.mmv-link { color: var(--lg-navy); }
.mmv-link:hover { text-decoration: underline; }
html.dark .mmv-link { color: #a5b4fc; }
.mmv-status { text-transform: capitalize; }

@media (max-width: 767.98px) {
  .mmv-btns .lg-btn { flex: 1 1 auto; }
}
</style>
