<template>
  <admin-layout :title="$t('Warehouses')">
    <div class="lg-page px-4 md:px-0 txn-shell wh-index">
      <page-header icon="building" :title="$t('Warehouses')" :subtitle="$t('Please review the data in the table below')">
        <offline-lock v-if="$can('create-warehouses')">
          <Link :href="route('warehouses.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('Warehouse') }) }}</Link>
        </offline-lock>
      </page-header>

      <stat-tiles :items="statTiles" />

      <!-- one card per category: click to show only that category (click again to show all) -->
      <div v-if="categoryCards.length" class="wh-cats" role="group" :aria-label="$t('Categories')">
        <button v-for="c in categoryCards" :key="c.name" type="button" class="wh-cat" :class="[c.tone, { 'is-on': form.category === c.name }]" :aria-pressed="form.category === c.name" @click="pickCategory(c.name)">
          <span class="wh-cat-ico"><icons :name="c.icon" class="w-5 h-5" /></span>
          <span class="wh-cat-t"><b>{{ c.n }}</b><small>{{ c.name }}</small></span>
        </button>
      </div>

      <div v-if="$connection.status === 'offline'" class="lg-note lg-note-amber mb-4 print:hidden">
        <icons name="wifi" class="w-4 h-4" />
        <span>{{ $t('Showing cached warehouses.') }} <span v-if="$connection.lastSyncAt">{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt) }} {{ $time($connection.lastSyncAt) }}</span></span>
      </div>

      <div class="txn-toolbar wh-toolbar liquid-filter-row mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-md flex-1" @reset="reset">
          <label class="mt-4 block text-gray-700">{{ $t('Trashed') }}:</label>
          <select-input v-model="form.trashed" class="mt-1 w-full">
            <option :value="null">{{ $t('Not Trashed') }}</option>
            <option value="with">{{ $t('With Trashed') }}</option>
            <option value="only">{{ $t('Only Trashed') }}</option>
          </select-input>
        </search-filter>
        <div class="flex items-center gap-2">
          <template v-if="$can('read-warehouses')">
            <button type="button" class="lg-btn lg-btn-dark" @click="printList"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></button>
            <button type="button" class="lg-btn lg-btn-green" @click="downloadList"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></button>
          </template>
          <tec-dropdown align="right" width="48" v-if="$can(['import-warehouses', 'export-warehouses'])">
            <template #trigger>
              <button class="lg-btn lg-btn-soft"><icons name="menu" class="w-4 h-4" />{{ $t('More') }}</button>
            </template>

            <template #content>
              <a
                v-if="$can('export-warehouses')"
                :href="route('warehouses.export')"
                class="block px-4 py-2 leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
              >
                {{ $t('export_x', { x: $t('Warehouses') }) }}
              </a>
              <tec-dropdown-link v-if="$can('import-warehouses')" :href="route('warehouses.import')">
                {{ $t('import_x', { x: $t('Warehouses') }) }}
              </tec-dropdown-link>
            </template>
          </tec-dropdown>
        </div>
        <div class="wh-filters w-full">
          <acct-select v-model="form.category" :options="categoryOptions" :placeholder="$t('All categories')" clearable class="wh-sel" />
          <acct-select v-model="form.stage" :options="stageOptions" :placeholder="$t('All stages')" clearable class="wh-sel" />
          <acct-select v-model="form.state" :options="stateOptions" :placeholder="$t('Any status')" clearable class="wh-sel" />
        </div>
      </div>

      <section class="lg-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('No') }}</th>
                <th>{{ $t('Name') }}</th>
                <th>{{ $t('Category') }}</th>
                <th>{{ $t('Stage') }}</th>
                <th>{{ $t('Contact') }}</th>
                <th>{{ $t('Address') }}</th>
                <th>{{ $t('Active') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="sec in grouped" :key="sec.name">
              <tr v-if="!wh" class="wh-sec"><td colspan="8"><span class="lg-chip" :class="categoryChip(sec.name)" style="text-transform: none">{{ sec.name }}</span><b class="wh-sec-n">{{ sec.items.length }} {{ sec.items.length === 1 ? $t('record') : $t('records') }}</b></td></tr>
              <template v-for="warehouse in sec.items" :key="warehouse.id">
                <tr
                  @click="toggleWarehouse(warehouse)"
                  v-if="wh === undefined || wh === null || wh.id === warehouse.id"
                  class="is-click"
                  :class="{ 'is-open': wh && wh.id === warehouse.id }"
                >
                  <td :data-label="$t('No')"><span class="lg-subtext">{{ warehouse._no }}</span></td>
                  <td :data-label="$t('Name')">
                    <div class="lg-person">
                      <img v-if="warehouse.logo" class="block h-10 w-10 rounded-full object-cover flex-none" :src="warehouse.logo" />
                      <span v-else class="warehouse-avatar flex-none">{{ warehouseInitials(warehouse.name) }}</span>
                      <div class="min-w-0 text-left">
                        <div class="flex items-center gap-1.5">
                          <span class="lg-main truncate">{{ warehouse.name }}</span>
                          <icons v-if="warehouse.deleted_at" name="trash" class="shrink-0 w-3.5 h-3.5 text-red-500" />
                        </div>
                        <div class="lg-subtext">{{ warehouse.code }}</div>
                      </div>
                    </div>
                  </td>
                  <td :data-label="$t('Category')">
                    <span class="lg-chip" :class="categoryChip(warehouse.status)" style="text-transform: none">{{ warehouse.status }}</span>
                  </td>
                  <td :data-label="$t('Stage')">
                    <span v-if="warehouse.stage">{{ warehouse.stage }}</span>
                    <span v-else class="lg-subtext">-</span>
                  </td>
                  <td :data-label="$t('Contact')">
                    <div>{{ warehouse.phone }}</div>
                    <div class="lg-subtext">{{ warehouse.email }}</div>
                  </td>
                  <td :data-label="$t('Address')">{{ warehouse.address }}</td>
                  <td :data-label="$t('Active')">
                    <div class="flex flex-wrap items-center gap-1.5 wh-chips">
                      <span class="lg-chip" :class="warehouse.active ? 'lg-chip-green' : 'lg-chip-slate'">
                        <span class="h-1.5 w-1.5 rounded-full" :class="warehouse.active ? 'bg-emerald-500' : 'bg-gray-400'"></span>
                        {{ warehouse.active ? $t('Active') : $t('Inactive') }}
                      </span>
                      <span v-if="warehouse.completed_at" class="lg-chip lg-chip-amber">
                        <icons name="lock-closed" class="w-3 h-3" />
                        {{ $t('Completed') }}
                      </span>
                    </div>
                  </td>
                  <td class="w-px" @click="goto(route('warehouses.edit', warehouse.id), warehouse)">
                    <span v-if="canEditRow(warehouse)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')"><icons name="edit" class="w-4 h-4" /></span>
                  </td>
                </tr>
              </template>
              </template>
              <tr v-if="displayWarehouses.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="$connection.status !== 'offline'" class="mt-6" :meta="warehouses.meta" :links="warehouses.links" />

      <section v-if="wh !== null" class="lg-card mt-5">
        <header class="lg-card-head flex-wrap">
          <span class="lg-card-ico lg-ico-teal"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Stock Items') }}</h2>
            <p class="lg-card-sub">{{ wh.name }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2 print:hidden" v-if="$can('read-warehouses')">
            <Link v-if="$can('view-project-certificates')" :href="route('project-certificates.project.index', wh.id)" class="lg-btn lg-btn-primary">
              <icons name="doc-text" class="w-4 h-4" />
              <span>{{ $t('Certificates') }}</span>
            </Link>
            <button type="button" @click="printWarehouse" class="lg-btn lg-btn-dark">
              <icons name="printer" class="w-4 h-4" />
              <span>{{ $t('Print') }}</span>
            </button>
            <button type="button" @click="downloadWarehousePdf" class="lg-btn lg-btn-green">
              <icons name="download" class="w-4 h-4" />
              <span>{{ $t('Download') }}</span>
            </button>
          </div>
        </header>

        <div class="mb-4 print:hidden">
          <input
            v-model="itemSearch"
            type="text"
            class="wh-input w-full max-w-md"
            :placeholder="$t('Search items in this warehouse by code or name')"
          />
        </div>

        <div class="lg-table-wrap wh-scroll">
          <table class="lg-table">
            <thead>
              <tr>
                <th>{{ $t('No') }}</th>
                <th>{{ $t('Item Code') }}</th>
                <th>{{ $t('Item Name') }}</th>
                <th class="text-center">{{ $t('Item Price') }}</th>
                <th class="text-center">{{ $t('Stock Quantity') }}</th>
                <th class="text-center">{{ $t('Unit') }}</th>
                <th class="text-center">{{ $t('Stock Value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(stock, si) in filteredItems" :key="si">
                <td><span class="lg-subtext">{{ si + 1 }}</span></td>
                <td><span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ stock.code }}</span></td>
                <td class="lg-main">{{ stock.name }}</td>
                <td class="text-center">{{ $number(stock.price) }} Tsh</td>
                <td class="text-center"><span class="text-maroon font-bold">{{ stock.quantity }}</span></td>
                <td class="text-center">{{ stock.unit }}</td>
                <td class="text-center lg-main">{{ $number(stock.total_amount) }} Tsh</td>
              </tr>
              <tr v-if="!hasStockItems">
                <td class="lg-empty" colspan="7">{{ $t('The warehouse is currently empty.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import TecButton from '@/Jetstream/Button.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Pagination from '@/Shared/Pagination.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import TecDropdown from '@/Jetstream/Dropdown.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecDropdownLink from '@/Jetstream/DropdownLink.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import { loadIndexData } from '@/Core/offlineDataSource';

export default {
  components: {
    PageHeader,
    AcctSelect,
    StatTiles,
    TecButton,
    Pagination,
    AdminLayout,
    TecDropdown,
    SelectInput,
    SearchFilter,
    TecDropdownLink,
    TecSectionTitle,
  },

  props: {
    filters: Object,
    warehouses: Object,
    listing: Array,
    categories: { type: Array, default: () => [] },
    categoryCounts: { type: Object, default: () => ({}) },
    stages: { type: Array, default: () => [] },
    stats: { type: Object, default: null },
  },

  data() {
    return {
      wh: null,
      itemSearch: '',
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
        category: this.filters.category,
        stage: this.filters.stage,
        state: this.filters.state,
      },
      offlineWarehouses: null,
    };
  },

  mounted() {
    if (this.$connection.status === 'offline') {
      this.loadOfflineWarehouses();
    }
  },

  watch: {
    form: {
      handler: throttle(function () {
        if (this.$connection.status === 'offline') {
          this.loadOfflineWarehouses();
          return;
        }
        let query = pickBy(this.form);
        this.wh = null;
        this.$inertia.visit(this.route('warehouses.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
          only: ['warehouses', 'filters', 'categories', 'stages'],
        });
      }, 250, { leading: false, trailing: true }),
      deep: true,
    },
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      const tiles = [
        { icon: 'building', tone: 'navy', label: this.$t('Total'), value: this.stats.total },
        { icon: 'home', tone: 'indigo', label: this.$t('Main Warehouses'), value: this.stats.main },
        { icon: 'collection', tone: 'sky', label: this.$t('Projects'), value: this.stats.projects },
        { icon: 'tick', tone: 'emerald', label: this.$t('Active'), value: this.stats.active },
      ];

      if (this.$super) {
        tiles.push({ icon: 'lock-closed', tone: 'amber', label: this.$t('Completed'), value: this.stats.completed });
      }

      return tiles;
    },
    categoryCards() {
      const look = { 'Main Warehouse': ['home', 'is-indigo'], 'Rans Warehouse': ['building', 'is-cyan'], Directors: ['users', 'is-violet'], Private: ['lock-closed', 'is-slate'], Government: ['doc-text', 'is-green'], General: ['collection', 'is-amber'] };
      const order = Object.keys(look);
      const names = [...new Set([...order.filter(n => this.categoryCounts[n] !== undefined), ...Object.keys(this.categoryCounts).filter(n => n && !order.includes(n))])];
      return names.map(name => ({ name, n: Number(this.categoryCounts[name] || 0), icon: (look[name] || ['collection'])[0], tone: (look[name] || [0, 'is-slate'])[1] }));
    },
    categoryOptions() { return this.categories.map(c => ({ value: c, label: c })); },
    stageOptions() { return [{ value: '__none', label: this.$t('No stage set') }, ...this.stages.map(g => ({ value: g, label: g }))]; },
    stateOptions() { return [{ value: 'active', label: this.$t('Active') }, { value: 'inactive', label: this.$t('Inactive') }, ...(this.$super ? [{ value: 'completed', label: this.$t('Completed') }] : [])]; },
    // the table in sections by category (the usual order first), each section by name; the numbers run on through the sections
    grouped() {
      const order = ['Main Warehouse', 'Rans Warehouse', 'Directors', 'Private', 'Government', 'General'];
      const by = {};
      (this.displayWarehouses || []).forEach(w => { const c = w.status || this.$t('No category'); (by[c] = by[c] || []).push(w); });
      const names = Object.keys(by).sort((a, b) => { const i = order.indexOf(a); const j = order.indexOf(b); return (i < 0 ? 99 : i) - (j < 0 ? 99 : j) || a.localeCompare(b); });
      let n = 0;
      return names.map(name => ({ name, items: by[name].slice().sort((a, b) => String(a.name).localeCompare(String(b.name))).map(w => ({ ...w, _no: ++n })) }));
    },
    displayWarehouses() {
      if (this.$connection.status === 'offline' && this.offlineWarehouses) {
        return this.offlineWarehouses;
      }
      return this.warehouses.data;
    },
    hasStockItems() {
      return this.filteredItems.some(item => item.quantity > 0);
    },
    filteredItems() {
      // quantity > 0 is filtered here (not just via the row's old v-if) so
      // the "No" column numbers the rows actually shown, with no gaps.
      const items = (this.wh ? this.listing.find(l => l.id === this.wh.id)?.items || [] : []).filter(item => item.quantity > 0);
      const query = this.itemSearch.trim().toLowerCase();

      if (!query) {
        return items;
      }

      return items.filter(item => {
        const code = String(item.code || '').toLowerCase();
        const name = String(item.name || '').toLowerCase();
        return code.includes(query) || name.includes(query);
      });
    },
  },

  methods: {
    async loadOfflineWarehouses() {
      const result = await loadIndexData('warehouses', { search: this.form.search });
      this.offlineWarehouses = result.data;
    },
    categoryChip(status) {
      return {
        'Main Warehouse': 'lg-chip-indigo',
        'Rans Warehouse': 'lg-chip-cyan',
        Directors: 'lg-chip-violet',
        Private: 'lg-chip-slate',
        Government: 'lg-chip-green',
        General: 'lg-chip-amber',
      }[status] || 'lg-chip-slate';
    },
    warehouseInitials(name) {
      return (name || '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('');
    },
    canEditRow(warehouse) {
      if (!this.$can('update-warehouses')) {
        return false;
      }
      return !warehouse.completed_at || this.$super;
    },
    goto(link, warehouse) {
      if (warehouse && !this.canEditRow(warehouse)) {
        return;
      }
      if (this.$can('update-warehouses')) {
        this.$inertia.visit(link);
      }
    },
    toggleWarehouse(warehouse) {
      this.itemSearch = '';
      this.wh = this.wh === null ? warehouse : null;
    },
    printWarehouse() {
      if (!this.wh) return;
      window.open(this.route('warehouses.print', this.wh.id), '_blank');
    },
    downloadWarehousePdf() {
      if (!this.wh) return;
      window.open(this.route('warehouses.pdf', this.wh.id), '_blank');
    },
    // exactly the rows now in the table: the same filters go to the server, which prints them in the same sections
    listQuery() { const q = {}; ['search', 'trashed', 'category', 'stage', 'state'].forEach(k => { if (this.form[k]) q[k] = this.form[k]; }); return q; },
    printList() { window.open(this.route('warehouses.list.print', this.listQuery()), '_blank'); },
    downloadList() { window.open(this.route('warehouses.list.pdf', this.listQuery()), '_blank'); },
    pickCategory(name) { this.form.category = this.form.category === name ? null : name; },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
  },

};
</script>

<style scoped>
.warehouse-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.5rem;
  width: 2.5rem;
  border-radius: 9999px;
  background: linear-gradient(135deg, #dbeafe, #e0e7ff);
  color: #1c3f66;
  font-size: 0.75rem;
  font-weight: 800;
  letter-spacing: 0.02em;
}
.txn-shell {
  position: relative;
}

.lg-table tbody tr.is-open td {
  background: var(--lg-soft);
}
/* non-responsive tables always scroll inside their own wrapper, also on phones */
.wh-scroll {
  overflow-x: auto !important;
  border: 1px solid var(--lg-line) !important;
  background: var(--lg-card) !important;
}
/* category cards: a soft ring that turns round the chosen (or hovered) card, nothing more */
@property --wh-a { syntax: '<angle>'; inherits: false; initial-value: 0deg; }
@keyframes whSpin { to { --wh-a: 360deg; } }
.wh-cats { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr)); margin-bottom: 1.25rem; }
.wh-cat { --tone: #64748b; --soft: rgba(100, 116, 139, 0.14); position: relative; display: flex; align-items: center; gap: 0.8rem; padding: 0.85rem 1rem; border-radius: 1.1rem; border: 0; text-align: left; cursor: pointer; background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1px var(--lg-line, #d6dfeb), 0 8px 18px -14px rgba(15, 37, 64, 0.5); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.wh-cat:hover { transform: translateY(-2px); }
.wh-cat:focus-visible { outline: 3px solid var(--tone); outline-offset: 2px; }
.wh-cat::before { content: ''; position: absolute; inset: 0; border-radius: inherit; padding: 2px; background: conic-gradient(from var(--wh-a), transparent 0 60%, var(--tone) 82%, transparent 100%); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask: linear-gradient(#000 0 0) content-box exclude, linear-gradient(#000 0 0); opacity: 0; transition: opacity 0.2s; animation: whSpin 4.5s linear infinite; pointer-events: none; }
.wh-cat:hover::before, .wh-cat.is-on::before { opacity: 1; }
.wh-cat.is-on { box-shadow: inset 0 0 0 2px var(--tone), 0 10px 20px -14px var(--tone); background: var(--soft); }
.wh-cat-ico { flex: none; width: 2.6rem; height: 2.6rem; border-radius: 0.9rem; display: inline-grid; place-items: center; background: var(--soft); color: var(--tone); }
.wh-cat-t { display: grid; min-width: 0; line-height: 1.15; } .wh-cat-t b { font-size: 1.45rem; font-variant-numeric: tabular-nums; color: var(--lg-ink, #1e293b); } .wh-cat-t small { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-mute, #64748b); overflow-wrap: break-word; }
.wh-cat.is-indigo { --tone: #4f46e5; --soft: rgba(79, 70, 229, 0.12); } .wh-cat.is-cyan { --tone: #0891b2; --soft: rgba(8, 145, 178, 0.12); } .wh-cat.is-violet { --tone: #7c3aed; --soft: rgba(124, 58, 237, 0.12); }
.wh-cat.is-green { --tone: #059669; --soft: rgba(5, 150, 105, 0.12); } .wh-cat.is-amber { --tone: #d97706; --soft: rgba(217, 119, 6, 0.14); } .wh-cat.is-slate { --tone: #475569; --soft: rgba(71, 85, 105, 0.12); }
@media (prefers-reduced-motion: reduce) { .wh-cat::before { animation: none; } .wh-cat { transition: none; } }
.wh-sec td { background: var(--lg-soft, rgba(148, 163, 184, 0.12)); padding-top: 8px !important; padding-bottom: 8px !important; }
.wh-sec-n { margin-left: 10px; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
/* the bar holds two rows (search, then the filters): a full pill would curve its corners deep into the content, so it gets a calm radius */
.lg-page .txn-toolbar.wh-toolbar { border-radius: 22px; }
.wh-filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem; align-items: center; }
.wh-sel { width: 100%; min-width: 0; height: 2.5rem; padding-top: 0; padding-bottom: 0; }
@media (max-width: 575.98px) { .wh-filters { grid-template-columns: 1fr; } }
.wh-input {
  padding: 0.6rem 0.9rem;
  border-radius: 0.85rem;
  border: 1px solid var(--lg-line);
  background: var(--lg-card);
  color: var(--lg-ink);
  font-size: 0.85rem;
}
.wh-input:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.22);
}
.wh-link {
  color: #1d4ed8;
}
.wh-link:hover {
  text-decoration: underline;
}
html.dark .wh-link {
  color: #93c5fd;
}
.wh-bar {
  width: 4rem;
  height: 0.5rem;
  border-radius: 9999px;
  background: var(--lg-line);
  overflow: hidden;
}
.wh-bar-fill {
  height: 100%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #34d399, #059669);
}
@media (max-width: 767.98px) {
  .wh-chips {
    justify-content: flex-end;
  }
}

.text-maroon { color: #7f1d1d; }
html.dark .text-maroon { color: #f87171; }
</style>
