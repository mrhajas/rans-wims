<template>
  <admin-layout :title="$t('Assets')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chip" :title="$t('Asset Register')" :subtitle="$t('Company assets: acquisition, location, assignment, condition, and value.')">
        <div class="ast-total-value" v-if="!isSiteEngineer">
          <span class="ast-total-value-label">{{ $t('Total Value') }}</span>
          <span class="ast-total-value-amount">{{ $money(totalValue) }}</span>
        </div>
        <a v-if="$can('print-assets')" :href="route('assets.full-report.pdf')" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download Assets') }}
        </a>
        <Link :href="route('assets.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chart-pie" class="w-4 h-4" />{{ $t('Dashboard') }}
        </Link>
        <Link v-if="$can('create-assets')" :href="route('assets.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Add Asset') }}
        </Link>
      </page-header>

      <stat-tiles v-if="stats" :items="statTiles" />

      <div class="lg-card ast-filters relative z-20">
        <div class="lg-fields ast-filter-grid">
          <div><text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Asset No., Name, Serial…')" /></div>
          <div><auto-complete id="asset_category_id" :label="$t('Category')" v-model="form.asset_category_id" class="w-full" :suggestions="categoryOptions" clearable /></div>
          <div><auto-complete id="warehouse_id" :label="$t('Project / Warehouse')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseOptions" clearable /></div>
          <div><auto-complete id="asset_type" :label="$t('Asset Type')" v-model="form.asset_type" class="w-full" :suggestions="assetTypeOptions" clearable /></div>
          <div><auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable /></div>
          <div><auto-complete id="condition" :label="$t('Condition')" v-model="form.condition" class="w-full" :suggestions="conditionOptions" clearable /></div>
          <div class="ast-reset-cell">
            <button type="button" class="lg-btn lg-btn-soft w-full" @click="reset">
              <icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}
            </button>
          </div>
        </div>
      </div>

      <div class="flex items-center justify-between gap-4 mb-3 flex-wrap">
        <div class="flex items-center gap-3 flex-wrap">
          <span class="lg-subtext">{{ assets.meta ? assets.meta.total : 0 }} {{ $t('result(s)') }}</span>
          <span v-if="selected.size && !isSiteEngineer" class="lg-chip lg-chip-indigo">
            {{ selected.size }} {{ $t('selected') }} &middot; {{ $money(selectedTotal) }}
          </span>
          <span v-else-if="selected.size" class="lg-chip lg-chip-indigo">{{ selected.size }} {{ $t('selected') }}</span>
        </div>
        <div class="flex items-center gap-2 flex-wrap w-full sm:w-auto sm:shrink-0" v-if="$can('print-assets')">
          <template v-if="selected.size">
            <a :href="route('assets.print-list') + selectedQueryString" target="_blank" class="lg-btn lg-btn-green">
              <icons name="printer" class="w-4 h-4" />{{ $t('Print Selected') }}
            </a>
            <a :href="route('assets.pdf-list') + selectedQueryString" class="lg-btn lg-btn-dark">
              <icons name="download" class="w-4 h-4" />{{ $t('Download Selected') }}
            </a>
            <button type="button" class="lg-btn lg-btn-soft" @click="selected = new Set()">{{ $t('Clear') }}</button>
          </template>
          <template v-else>
            <a :href="route('assets.print-list') + queryString" target="_blank" class="lg-btn lg-btn-green">
              <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
            </a>
            <a :href="route('assets.pdf-list') + queryString" class="lg-btn lg-btn-dark">
              <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
            </a>
          </template>
        </div>
      </div>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive ast-table">
          <thead>
            <tr>
              <th class="w-10" @click.stop>
                <input type="checkbox" class="ast-check" :checked="allOnPageSelected" @change="toggleSelectAll" />
              </th>
              <th class="ast-no-col">{{ $t('No') }}</th>
              <th class="w-14"></th>
              <th v-for="col in sortableColumns" :key="col.key" :class="[{ num: col.num }, 'ast-sortable', { 'is-sorted': form.sort === col.key }]" :aria-sort="ariaSort(col.key)" @click="sortBy(col.key)">
                <span class="ast-sort-label">{{ col.label }}<span class="ast-sort-arrows"><span :class="{ on: form.sort === col.key && form.direction === 'asc' }">&#9650;</span><span :class="{ on: form.sort === col.key && form.direction !== 'asc' }">&#9660;</span></span></span>
              </th>
              <th class="w-16"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(a, rowIndex) in assets.data" :key="a.id" class="is-click" @click="goto(a.id)">
              <td data-label="" @click.stop>
                <input type="checkbox" class="ast-check" :checked="selected.has(a.id)" @change="toggleRow(a.id)" />
              </td>
              <td :data-label="$t('No')" class="ast-no-col lg-main">{{ rowNumber(rowIndex) }}</td>
              <td :data-label="$t('Photo')" @click.stop>
                <img
                  v-if="a.photo_url"
                  :src="a.photo_url"
                  class="ast-thumb"
                  alt=""
                  @mouseenter="showZoom($event, a)"
                  @mouseleave="hideZoom"
                />
                <div v-else class="ast-thumb-placeholder">
                  <icons :name="categoryIcon(a)" class="w-5 h-5" />
                </div>
              </td>
              <td :data-label="$t('Asset No.')">
                <div class="lg-main ast-no">{{ a.asset_number }}</div>
                <div class="lg-subtext ast-name">{{ a.name }}</div>
              </td>
              <td :data-label="$t('Category')">{{ a.category || '-' }}</td>
              <td :data-label="$t('Asset Type')">
                <span v-if="a.asset_type" class="lg-chip" :class="a.asset_type === 'Fixed Asset' ? 'lg-chip-indigo' : 'lg-chip-cyan'" style="text-transform: none">
                  <icons :name="a.asset_type === 'Fixed Asset' ? 'building' : 'truck'" class="w-3 h-3" />{{ $t(a.asset_type) }}
                </span>
                <span v-else class="lg-subtext">-</span>
              </td>
              <td :data-label="$t('Location')">{{ a.warehouse || a.location_label || '-' }}</td>
              <td :data-label="$t('Responsible')">{{ a.responsible || '-' }}</td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusBadgeClass(a.status)" style="text-transform: none">{{ a.status }}</span>
                <div class="lg-subtext">{{ a.condition }}</div>
              </td>
              <td :data-label="$t('Quantity')" class="num ast-qty">{{ qty(a.quantity) }} <span class="lg-subtext">{{ a.unit }}</span></td>
              <td v-if="!isSiteEngineer" :data-label="$t('Price for Each')" class="num">{{ $money(a.unit_price) }}</td>
              <td v-if="!isSiteEngineer" :data-label="$t('Book Value')" class="num ast-book-value">{{ $money(a.current_book_value) }}</td>
              <td data-label="">
                <icons v-if="a.deleted_at" name="trash" class="w-4 h-4 text-red-500" />
              </td>
            </tr>
            <tr v-if="assets.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" :colspan="isSiteEngineer ? 11 : 12">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="assets.data.length" class="mt-6" :meta="assets.meta" :links="assets.links" />
    </div>

    <teleport to="body">
      <div ref="zoomBox" class="ast-zoom" :class="{ 'ast-zoom-show': zoom.show }" :style="{ left: zoom.x + 'px', top: zoom.y + 'px', width: zoom.w + 'px' }">
        <img v-if="zoom.asset && zoom.asset.photo_url" :src="zoom.asset.photo_url" alt="" />
        <template v-if="zoom.asset">
          <div class="ast-zoom-name">{{ zoom.asset.name }}</div>
          <div class="ast-zoom-row"><span>{{ $t('Asset No.') }}</span><strong>{{ zoom.asset.asset_number }}</strong></div>
          <div class="ast-zoom-row" v-if="zoom.asset.category"><span>{{ $t('Category') }}</span><strong>{{ zoom.asset.category }}</strong></div>
          <div class="ast-zoom-row" v-if="zoom.asset.asset_type"><span>{{ $t('Type') }}</span><strong>{{ zoom.asset.asset_type }}</strong></div>
          <div class="ast-zoom-row"><span>{{ $t('Status') }}</span><strong>{{ zoom.asset.status }}</strong></div>
          <div class="ast-zoom-row" v-if="!isSiteEngineer"><span>{{ $t('Book Value') }}</span><strong>{{ $money(zoom.asset.current_book_value) }}</strong></div>
        </template>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import { placeZoom } from '@/Core/zoomPosition';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, TextInput, Pagination, PageHeader, StatTiles },

  props: {
    filters: Object,
    assets: Object,
    totalValue: [Number, String],
    categories: Array,
    warehouses: Array,
    statuses: Array,
    conditions: Array,
    assetTypes: { type: Array, default: () => [] },
    isSiteEngineer: Boolean,
    stats: { type: Object, default: null },
  },

  mounted() {
    window.addEventListener('scroll', this.hideZoomOnMove, true);
    window.addEventListener('resize', this.hideZoomOnMove);
  },

  beforeUnmount() {
    window.removeEventListener('scroll', this.hideZoomOnMove, true);
    window.removeEventListener('resize', this.hideZoomOnMove);
  },

  data() {
    return {
      zoom: { show: false, asset: null, x: 0, y: 0, w: 200 },
      selected: new Set(),
      form: {
        search: this.filters.search || '',
        asset_category_id: this.filters.asset_category_id || null,
        asset_type: this.filters.asset_type || null,
        warehouse_id: this.filters.warehouse_id || null,
        status: this.filters.status || null,
        condition: this.filters.condition || null,
        sort: this.filters.sort || null,
        direction: this.filters.direction || null,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'chip', tone: 'navy', label: this.$t('Total Assets'), value: this.stats.total },
        { icon: 'building', tone: 'indigo', label: this.$t('Fixed Assets'), value: this.stats.fixed },
        { icon: 'truck', tone: 'teal', label: this.$t('Unfixed Assets'), value: this.stats.unfixed },
        { icon: 'tick', tone: 'emerald', label: this.$t('In Use'), value: this.stats.in_use },
        { icon: 'cog', tone: 'amber', label: this.$t('Under Maintenance'), value: this.stats.maintenance },
      ];
    },
    sortableColumns() {
      const cols = [
        { key: 'asset_number', label: this.$t('Asset No.') },
        { key: 'category', label: this.$t('Category') },
        { key: 'asset_type', label: this.$t('Asset Type') },
        { key: 'location', label: this.$t('Location') },
        { key: 'responsible', label: this.$t('Responsible') },
        { key: 'status', label: this.$t('Status') },
        { key: 'quantity', label: this.$t('Quantity'), num: true },
      ];
      if (!this.isSiteEngineer) {
        cols.push({ key: 'unit_price', label: this.$t('Price for Each'), num: true });
        cols.push({ key: 'book_value', label: this.$t('Book Value'), num: true });
      }
      return cols;
    },
    categoryOptions() {
      return this.categories.map(c => ({ label: c.name, value: c.id }));
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ label: w.name, value: w.id }));
    },
    assetTypeOptions() {
      return this.assetTypes.map(t => ({ label: this.$t(t), value: t }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
    conditionOptions() {
      return this.conditions.map(c => ({ label: this.$t(c), value: c }));
    },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    allOnPageSelected() {
      return this.assets.data.length > 0 && this.assets.data.every(a => this.selected.has(a.id));
    },
    selectedTotal() {
      return this.assets.data.filter(a => this.selected.has(a.id)).reduce((sum, a) => sum + (Number(a.current_book_value) || 0), 0);
    },
    selectedQueryString() {
      const params = new URLSearchParams();
      this.selected.forEach(id => params.append('ids[]', id));
      if (this.form.sort) {
        params.append('sort', this.form.sort);
        params.append('direction', this.form.direction || 'asc');
      }
      return '?' + params.toString();
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('assets.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    // 5 not 5.000: whole quantities show without decimals
    qty(n) {
      const v = Number(n || 0);
      return Number.isInteger(v) ? v.toLocaleString() : v.toLocaleString(undefined, { maximumFractionDigits: 3 });
    },
    // Running number across pages (page 2 continues after page 1).
    rowNumber(index) {
      return (this.assets.meta && this.assets.meta.from ? this.assets.meta.from : 1) + index;
    },
    // Click a header: first click sorts ascending, second descending, third goes back to the default order.
    sortBy(key) {
      if (this.form.sort !== key) {
        this.form.sort = key;
        this.form.direction = 'asc';
      } else if (this.form.direction === 'asc') {
        this.form.direction = 'desc';
      } else {
        this.form.sort = null;
        this.form.direction = null;
      }
    },
    ariaSort(key) {
      if (this.form.sort !== key) return 'none';
      return this.form.direction === 'asc' ? 'ascending' : 'descending';
    },
    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.search = '';
    },
    goto(id) {
      this.$inertia.visit(this.route('assets.show', id));
    },
    toggleRow(id) {
      if (this.selected.has(id)) {
        this.selected.delete(id);
      } else {
        this.selected.add(id);
      }
    },
    toggleSelectAll() {
      if (this.allOnPageSelected) {
        this.assets.data.forEach(a => this.selected.delete(a.id));
      } else {
        this.assets.data.forEach(a => this.selected.add(a.id));
      }
    },
    showZoom(event, asset) {
      const rect = event.currentTarget.getBoundingClientRect();
      // Render the popup invisibly first so its real height is known, then place it inside the viewport.
      this.zoom = { show: false, asset, x: -9999, y: -9999, w: 200 };
      this.$nextTick(() => {
        const box = this.$refs.zoomBox;
        if (!box || !this.zoom.asset) return;
        const pos = placeZoom(rect, 200, box.offsetHeight);
        this.zoom = { show: true, asset, x: pos.x, y: pos.y, w: pos.w };
      });
    },
    hideZoom() {
      this.zoom.show = false;
    },
    // Icon for the photo placeholder, picked from the asset's category (sub-category first, then its parent).
    categoryIcon(asset) {
      const rules = [
        [/motorcycle|car\b|cars\b|pickup|vehicle|bus(es)?\b/i, 'car'],
        [/truck|lorr|trailer/i, 'truck'],
        [/land\b/i, 'map-pin'],
        [/building|office\b|offices|warehouse|store/i, 'building'],
        [/printer/i, 'printer'],
        [/network|wifi|communicat|radio/i, 'wifi'],
        [/phone|tablet|mobile/i, 'mobile'],
        [/cctv|camera/i, 'eye'],
        [/laptop|computer|monitor|server|\bIT\b|electronic/i, 'chip'],
        [/survey/i, 'map-pin'],
        [/tool|ladder|scaffold|weld|safety/i, 'adjustments'],
        [/excavator|loader|bulldozer|roller|crane|mixer|compressor|generator|compactor|pump|machinery|plant/i, 'cog'],
        [/meeting/i, 'group'],
        [/desk|chair|cabinet|furniture|fixture/i, 'home'],
        [/air condition|appliance|electrical/i, 'sparkles'],
        [/office equipment/i, 'folder'],
      ];
      for (const name of [asset.category, asset.category_parent]) {
        if (!name) continue;
        const hit = rules.find(([re]) => re.test(name));
        if (hit) return hit[1];
      }
      return 'collection';
    },
    hideZoomOnMove() {
      if (this.zoom.show) this.zoom.show = false;
    },
    statusBadgeClass(status) {
      const map = {
        Available: 'lg-chip-green',
        Active: 'lg-chip-green',
        Assigned: 'lg-chip-blue',
        'In Use': 'lg-chip-blue',
        'Under Maintenance': 'lg-chip-amber',
        'Under Repair': 'lg-chip-amber',
        'In Transit': 'lg-chip-amber',
        Lost: 'lg-chip-red',
        Stolen: 'lg-chip-red',
        Damaged: 'lg-chip-red',
        Idle: 'lg-chip-slate',
        Disposed: 'lg-chip-slate',
        Sold: 'lg-chip-slate',
        'Written Off': 'lg-chip-slate',
        Retired: 'lg-chip-slate',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.ast-total-value { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.2; margin-right: 6px; }
.ast-total-value-label { font-size: 0.66rem; font-weight: 800; color: var(--lg-faint); text-transform: uppercase; letter-spacing: 0.06em; }
.ast-total-value-amount { font-size: 1.1rem; font-weight: 800; color: #7f1d1d; font-variant-numeric: tabular-nums; }
html.dark .ast-total-value-amount { color: #fda4af; }
.ast-book-value { font-weight: 800; color: #7f1d1d; font-variant-numeric: tabular-nums; }
html.dark .ast-book-value { color: #fda4af; }
.ast-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.ast-sortable:hover .ast-sort-label { color: var(--lg-ink); }
.ast-sort-label { display: inline-flex; align-items: center; gap: 0.35rem; }
.ast-sort-arrows { display: inline-flex; flex-direction: column; font-size: 0.5rem; line-height: 0.7; opacity: 0.4; }
.ast-sort-arrows .on { color: #7f1d1d; opacity: 1; }
html.dark .ast-sort-arrows .on { color: #fda4af; }
.is-sorted .ast-sort-arrows { opacity: 1; }

.ast-filters { padding-bottom: 1rem; }
.ast-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(170px, 100%), 1fr)); align-items: end; }
.ast-reset-cell { margin-bottom: 0.5rem; }

.ast-thumb { width: 3rem; height: 3rem; min-width: 3rem; max-width: none; border-radius: 9999px; object-fit: cover; cursor: zoom-in; border: 1px solid var(--lg-line); }
.ast-thumb-placeholder {
  width: 3rem; height: 3rem; border-radius: 9999px; background: var(--lg-soft); color: var(--lg-faint);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

.ast-zoom {
  position: fixed; transform: scale(0.85); transform-origin: center; z-index: 9999; box-sizing: border-box; max-width: calc(100vw - 16px);
  background: #fff; border-radius: 1rem; padding: 0.75rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease;
}
.ast-zoom-show { opacity: 1; transform: scale(1); }
.ast-zoom img { display: block; width: 100%; height: 130px; object-fit: cover; border-radius: 0.7rem; margin-bottom: 0.5rem; }
.ast-zoom-name { font-size: 0.82rem; font-weight: 800; color: #1f2937; margin-bottom: 0.35rem; }
.ast-zoom-row { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.7rem; color: #6b7280; padding: 0.15rem 0; }
.ast-zoom-row strong { color: #111827; font-weight: 700; text-align: right; }

html.dark .ast-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .ast-zoom-name { color: #f1f5f9; }
html.dark .ast-zoom-row strong { color: #e2e8f0; }

@media (max-width: 767.98px) {
  .ast-total-value { align-items: flex-start; width: 100%; margin-right: 0; }
}

.ast-link { font-weight: 800; text-decoration: underline; }
.ast-name { color: #7f1d1d; font-weight: 700; }
html.dark .ast-name { color: #fda4af; }

/* No column, check boxes with a shadow, and a shadow line between records */
.ast-no-col { width: 48px; text-align: center; font-variant-numeric: tabular-nums; }
.ast-check { width: 18px; height: 18px; border-radius: 6px; cursor: pointer; box-shadow: 0 3px 8px -2px rgba(15, 37, 64, 0.45), 0 1px 2px rgba(15, 37, 64, 0.2); }
.ast-check:checked { box-shadow: 0 4px 10px -2px rgba(79, 70, 229, 0.6), 0 1px 2px rgba(15, 37, 64, 0.2); }
html.dark .ast-check { box-shadow: 0 3px 8px -2px rgba(0, 0, 0, 0.8), 0 1px 2px rgba(0, 0, 0, 0.4); }
@media (min-width: 768px) {
  .ast-table tbody tr:not(.lg-empty-row) > td { border-bottom: 1px solid var(--lg-line); box-shadow: 0 7px 8px -8px rgba(15, 37, 64, 0.38); }
  html.dark .ast-table tbody tr:not(.lg-empty-row) > td { box-shadow: 0 7px 8px -8px rgba(0, 0, 0, 0.8); }
}
</style>
