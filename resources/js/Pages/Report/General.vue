<template>
  <admin-layout :title="$t('x_report', { x: $t('General') })">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="chart-bar"
        :title="$t('x_report', { x: $t('General') })"
        :subtitle="$t('All warehouses, items in stock, prices and totals')"
      />

      <div v-if="$connection.status === 'offline'" class="lg-note lg-note-amber mb-4 print:hidden">
        <icons name="wifi" class="w-3.5 h-3.5" />
        <span>{{ $t('Showing cached stock from your last sync. Emailing, PDF download, and print need a connection.') }} <span v-if="$connection.lastSyncAt">{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt) }} {{ $time($connection.lastSyncAt) }}</span></span>
      </div>

      <stat-tiles :items="statTiles" />

      <section class="lg-card ra-toolbar print:hidden">
        <div class="ra-search">
          <icons name="search" class="ra-search-icon" />
          <text-input v-model="search" class="w-full" :placeholder="$t('Search warehouse or item...')" />
        </div>
        <div class="ra-tools">
          <offline-lock>
            <email-report-button type="general" />
          </offline-lock>
          <offline-lock>
            <a :href="route('reports.general.pdf')" class="lg-btn lg-btn-dark">
              <icons name="down" class="w-4 h-4" />
              <span class="ra-btn-text">{{ $t('Download PDF') }}</span>
            </a>
          </offline-lock>
          <offline-lock>
            <button type="button" @click="print()" class="lg-btn lg-btn-primary">
              <icons name="printer" class="w-4 h-4" />
              <span class="ra-btn-text">{{ $t('Print') }}</span>
            </button>
          </offline-lock>
        </div>
      </section>

      <h2 class="text-lg font-semibold mb-2 hidden print:block text-center">
        {{ $t('General Stock Report') }}
      </h2>

      <section v-for="warehouse in filteredListing" :key="warehouse.id" class="lg-card ra-wh">
        <header class="lg-card-head ra-wh-head">
          <span class="lg-card-ico lg-ico-sky print:hidden"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title truncate">
              {{ warehouse.name }}<span v-if="warehouse.code" class="ra-code"> ({{ warehouse.code }})</span>
            </h3>
          </div>
          <span class="ra-wh-total">
            <span class="ra-wh-total-label">{{ $t('Warehouse Total') }}</span>
            <span class="ra-wh-total-value">{{ formatNumber(warehouse.total) }} Tsh</span>
          </span>
        </header>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-10">#</th>
                <th>{{ $t('Code') }}</th>
                <th>{{ $t('Name') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th>{{ $t('Unit') }}</th>
                <th class="num">{{ $t('Price') }}</th>
                <th class="num">{{ $t('Total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in warehouse.items" :key="item.id">
                <td data-label="#" class="ra-faint">{{ idx + 1 }}</td>
                <td :data-label="$t('Code')" class="ra-mute">{{ item.code }}</td>
                <td :data-label="$t('Name')"><div class="lg-main">{{ item.name }}</div></td>
                <td :data-label="$t('Quantity')" class="num">{{ formatNumber(item.quantity) }}</td>
                <td :data-label="$t('Unit')" class="ra-mute">{{ item.unit || '-' }}</td>
                <td :data-label="$t('Price')" class="num">{{ formatNumber(item.price) }} Tsh</td>
                <td :data-label="$t('Total')" class="num ra-strong">{{ formatNumber(item.total) }} Tsh</td>
              </tr>
              <tr v-if="warehouse.items.length === 0" class="lg-empty-row">
                <td colspan="7" class="lg-empty">{{ $t('No items in stock.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="filteredListing.length === 0" class="lg-card">
        <div class="lg-empty">
          <icons name="search" class="w-8 h-8 mx-auto mb-2" />
          {{ $t('There is no data to display.') }}
        </div>
      </section>

      <div class="ra-grand">
        <div class="ra-grand-label">{{ $t('Grand Total') }}</div>
        <div class="ra-grand-value">{{ formatNumber(displayedGrandTotal) }} Tsh</div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TextInput from '@/Shared/TextInput.vue';
import TecButton from '@/Jetstream/Button.vue';
import EmailReportButton from '@/Shared/EmailReportButton.vue';
import { loadGeneralReport } from '@/Core/offlineDataSource';

export default {
  components: {
    AdminLayout,
    PageHeader,
    StatTiles,
    TextInput,
    TecButton,
    EmailReportButton,
  },

  props: {
    listing: { type: Array, default: () => [] },
    grandTotal: { type: Number, default: 0 },
  },

  data() {
    return {
      search: '',
      offlineListing: null,
      offlineGrandTotal: 0,
    };
  },

  mounted() {
    if (this.$connection.status === 'offline') {
      this.loadOfflineReport();
    }
  },

  computed: {
    displayListing() {
      if (this.$connection.status === 'offline' && this.offlineListing) {
        return this.offlineListing;
      }
      return this.listing;
    },
    displayGrandTotal() {
      if (this.$connection.status === 'offline' && this.offlineListing) {
        return this.offlineGrandTotal;
      }
      return this.grandTotal;
    },
    filteredListing() {
      const q = this.search.trim().toLowerCase();
      if (!q) return this.displayListing;
      return this.displayListing
        .map(w => {
          const warehouseMatches =
            (w.name || '').toLowerCase().includes(q) ||
            (w.code || '').toLowerCase().includes(q);
          const items = warehouseMatches
            ? w.items
            : w.items.filter(
                i =>
                  (i.name || '').toLowerCase().includes(q) ||
                  (i.code || '').toLowerCase().includes(q)
              );
          if (!warehouseMatches && items.length === 0) return null;
          const total = items.reduce((s, i) => s + Number(i.total || 0), 0);
          return { ...w, items, total };
        })
        .filter(Boolean);
    },
    statTiles() {
      const itemCount = this.filteredListing.reduce((s, w) => s + (w.items ? w.items.length : 0), 0);

      return [
        { icon: 'building', tone: 'sky', label: this.$t('Warehouses'), value: this.filteredListing.length },
        { icon: 'collection', tone: 'indigo', label: this.$t('Items'), value: itemCount },
        { icon: 'cash', tone: 'emerald', label: this.$t('Grand Total'), value: this.formatNumber(this.displayedGrandTotal) + ' Tsh' },
      ];
    },
    displayedGrandTotal() {
      if (!this.search.trim()) return this.displayGrandTotal;
      return this.filteredListing.reduce((s, w) => s + Number(w.total || 0), 0);
    },
  },

  methods: {
    async loadOfflineReport() {
      const result = await loadGeneralReport();
      this.offlineListing = result.listing;
      this.offlineGrandTotal = result.grandTotal;
    },
    print() {
      window.open(this.route('reports.general', { output: 'print' }), '_blank');
    },
    formatNumber(value) {
      const n = Number(value || 0);
      return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
    },
  },
};
</script>

<style scoped>
.ra-toolbar { display: flex; flex-direction: column; gap: 12px; padding: 0.8rem 1rem; position: relative; z-index: 20; }
@media (min-width: 1024px) { .ra-toolbar { flex-direction: row; align-items: center; justify-content: space-between; } }
.ra-search { position: relative; width: 100%; }
@media (min-width: 1024px) { .ra-search { max-width: 28rem; } }
.ra-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--lg-faint); pointer-events: none; z-index: 1; }
.ra-search :deep(input) {
  border-radius: 9999px !important; border-color: var(--lg-line) !important; padding-left: 2.25rem; background: var(--lg-card); color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.ra-search :deep(input:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18); }
.ra-tools { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.ra-wh-head { flex-wrap: wrap; }
.ra-code { color: var(--lg-faint); font-weight: 600; }
.ra-wh-total { display: inline-flex; flex-direction: column; align-items: flex-end; padding: 0.35rem 0.9rem; border-radius: 0.9rem; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.ra-wh-total-label { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.ra-wh-total-value { font-size: 0.95rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-ink); }
.ra-mute { color: var(--lg-mute); }
.ra-faint { color: var(--lg-faint); }
.ra-strong { font-weight: 800; }
.ra-grand {
  display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 1rem; padding: 1rem 1.3rem; border-radius: 22px;
  border: 1px solid rgba(99, 102, 241, 0.25); background: linear-gradient(135deg, rgba(224, 231, 255, 0.6), rgba(199, 210, 254, 0.4));
}
.ra-grand-label { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #4f46e5; }
.ra-grand-value { font-size: 1.25rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #312e81; }
html.dark .ra-grand { background: linear-gradient(135deg, rgba(67, 56, 202, 0.25), rgba(30, 41, 59, 0.4)); border-color: rgba(99, 102, 241, 0.35); }
html.dark .ra-grand-label { color: #a5b4fc; }
html.dark .ra-grand-value { color: #e0e7ff; }
@media (max-width: 640px) { .ra-btn-text { display: none; } .ra-tools .lg-btn { width: 2.5rem; height: 2.5rem; padding: 0; } }
@media print {
  .ra-wh { break-inside: avoid; box-shadow: none; border: 0; padding: 0; }
  .ra-grand { background: transparent; border: 0; }
}
</style>
