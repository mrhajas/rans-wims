<template>
  <div class="ofs-shell">
    <div class="ofs-bg-decor" aria-hidden="true">
      <span class="ofs-blob ofs-blob-1"></span>
      <span class="ofs-blob ofs-blob-2"></span>
    </div>

    <div class="ofs-header glass-panel rounded-2xl p-4 md:p-5 mb-5 relative z-[1]">
      <div class="flex items-start gap-3">
        <div class="ofs-header-icon flex items-center justify-center h-10 w-10 rounded-xl flex-none">
          <icons name="wifi" class="w-5 h-5 text-white" />
        </div>
        <div class="min-w-0">
          <h2 class="text-base font-bold text-gray-900">{{ $t('Offline Read Mode') }}</h2>
          <p class="text-xs text-gray-500 mt-0.5">
            {{ $t('No connection. Browsing cached data. Navigation between pages is disabled, use the tabs below instead.') }}
            <span v-if="$connection.lastSyncAt">{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt) }} {{ $time($connection.lastSyncAt) }}</span>
          </p>
        </div>
      </div>
    </div>

    <div class="ofs-tabs relative z-[1]" v-if="!selectedId">
      <button
        v-for="tab in visibleTabs"
        :key="tab.key"
        type="button"
        class="ofs-tab"
        :class="{ 'ofs-tab-active': activeTab === tab.key }"
        @click="selectTab(tab.key)"
      >
        <icons :name="tab.icon" class="w-4 h-4" />
        <span>{{ $t(tab.label) }}</span>
      </button>
    </div>

    <div v-if="!selectedId" class="relative z-[1] mb-4">
      <div class="ofs-search-box glass-surface rounded-xl flex items-center gap-2 px-3 py-2">
        <icons name="search" class="w-4 h-4 text-gray-400 flex-none" />
        <input
          v-model="search"
          type="text"
          class="ofs-search-input"
          :placeholder="$t('Search') + '...'"
        />
      </div>
    </div>

    <!-- General Report view -->
    <div v-if="!selectedId && activeTab === 'general-report'" class="relative z-[1]">
      <div v-if="loading" class="ofs-empty glass-panel rounded-2xl p-8 text-center text-sm text-gray-500">
        {{ $t('Loading') }}...
      </div>
      <div v-else-if="filteredReportListing.length === 0" class="ofs-empty glass-panel rounded-2xl p-8 text-center text-sm text-gray-500">
        {{ $t('Nothing cached here yet. Visit this page while online once so it can sync.') }}
      </div>
      <template v-else>
        <div v-for="warehouse in filteredReportListing" :key="warehouse.id" class="ofs-report-card glass-panel rounded-2xl p-4 mb-3">
          <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
            <h3 class="text-sm font-bold text-gray-800">{{ warehouse.name }}<span v-if="warehouse.code" class="text-gray-400 font-normal"> ({{ warehouse.code }})</span></h3>
            <span class="ofs-report-total">{{ formatNumber(warehouse.total) }} Tsh</span>
          </div>
          <div class="overflow-x-auto -mx-1">
            <table class="w-full text-sm">
              <tr class="text-left ofs-mr-thead">
                <th class="px-3 py-2">{{ $t('Code') }}</th>
                <th class="px-3 py-2">{{ $t('Name') }}</th>
                <th class="px-3 py-2 text-right">{{ $t('Quantity') }}</th>
                <th class="px-3 py-2 text-right">{{ $t('Total') }}</th>
              </tr>
              <tr v-for="item in warehouse.items" :key="item.id" class="ofs-mr-row">
                <td class="px-3 py-2 text-gray-500">{{ item.code }}</td>
                <td class="px-3 py-2 font-medium text-gray-800">{{ item.name }}</td>
                <td class="px-3 py-2 text-right">{{ formatNumber(item.quantity) }} {{ item.unit }}</td>
                <td class="px-3 py-2 text-right font-medium">{{ formatNumber(item.total) }} Tsh</td>
              </tr>
            </table>
          </div>
        </div>
        <div class="ofs-report-grand glass-surface rounded-2xl p-4 flex items-center justify-between">
          <span class="text-xs font-bold uppercase tracking-wide text-amber-700">{{ $t('Grand Total') }}</span>
          <span class="text-base font-bold text-amber-900">{{ formatNumber(search.trim() ? filteredReportListing.reduce((s, w) => s + w.total, 0) : reportGrandTotal) }} Tsh</span>
        </div>
      </template>
    </div>

    <!-- List view -->
    <div v-else-if="!selectedId" class="relative z-[1]">
      <div v-if="loading" class="ofs-empty glass-panel rounded-2xl p-8 text-center text-sm text-gray-500">
        {{ $t('Loading') }}...
      </div>
      <div v-else-if="rows.length === 0" class="ofs-empty glass-panel rounded-2xl p-8 text-center text-sm text-gray-500">
        {{ $t('Nothing cached here yet. Visit this page while online once so it can sync.') }}
      </div>
      <div v-else class="ofs-list glass-panel rounded-2xl overflow-hidden divide-y divide-gray-100">
        <button
          type="button"
          v-for="row in rows"
          :key="row.id"
          class="ofs-row"
          @click="openDetail(row)"
        >
          <div class="min-w-0 flex-1">
            <div class="ofs-row-title truncate">{{ rowTitle(row) }}</div>
            <div class="ofs-row-subtitle truncate">{{ rowSubtitle(row) }}</div>
          </div>
          <div v-if="rowTrailing(row)" class="ofs-row-trailing flex-none">{{ rowTrailing(row) }}</div>
          <icons name="chevron-down" class="w-3.5 h-3.5 -rotate-90 text-gray-300 flex-none ml-1" />
        </button>
      </div>
    </div>

    <!-- Detail view -->
    <div v-else class="relative z-[1]">
      <button type="button" class="ofs-back-btn mb-4" @click="closeDetail">
        <icons name="chevron-down" class="w-4 h-4 rotate-90" />
        <span>{{ $t('Back to list') }}</span>
      </button>

      <div v-if="detailLoading" class="ofs-empty glass-panel rounded-2xl p-8 text-center text-sm text-gray-500">
        {{ $t('Loading') }}...
      </div>
      <template v-else>
        <item-details v-if="activeTab === 'items' && detailRecord" :item="detailRecord" />
        <checkin-details v-else-if="activeTab === 'checkins' && detailRecord" :checkin="detailRecord" />
        <checkout-details v-else-if="activeTab === 'checkouts' && detailRecord" :checkout="detailRecord" />
        <transfer-details v-else-if="activeTab === 'transfers' && detailRecord" :transfer="detailRecord" />
        <adjustment-details v-else-if="activeTab === 'adjustments' && detailRecord" :adjustment="detailRecord" />
        <div v-else-if="activeTab === 'material-requests' && detailRecord" class="glass-panel rounded-2xl p-5">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <div class="text-lg font-bold text-gray-900">{{ detailRecord.reference }}</div>
              <div class="text-xs text-gray-500 mt-0.5">{{ detailRecord.date_requested ? $date(detailRecord.date_requested) : '' }}</div>
            </div>
            <span class="ofs-status-pill">{{ detailRecord.status }}</span>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
            <div class="ofs-info-item">
              <span class="ofs-info-label">{{ $t('Project(s) / Warehouse(s)') }}</span>
              <span class="ofs-info-value">{{ detailRecord.warehouse?.name || '-' }}</span>
            </div>
            <div class="ofs-info-item">
              <span class="ofs-info-label">{{ $t('Requested By') }}</span>
              <span class="ofs-info-value">{{ detailRecord.user?.name || '-' }}</span>
            </div>
            <div v-if="detailRecord.cleared_by" class="ofs-info-item">
              <span class="ofs-info-label">{{ $t('Cleared by') }}</span>
              <span class="ofs-info-value">{{ detailRecord.cleared_by.name }}</span>
            </div>
          </div>
          <div class="overflow-x-auto -mx-1">
            <table class="w-full text-sm">
              <tr class="text-left ofs-mr-thead">
                <th class="px-3 py-2">{{ $t('Item') }}</th>
                <th class="px-3 py-2 text-right">{{ $t('Quantity') }}</th>
                <th class="px-3 py-2">{{ $t('Unit') }}</th>
                <th class="px-3 py-2">{{ $t('Priority') }}</th>
              </tr>
              <tr v-for="item in detailRecord.items || []" :key="item.id" class="ofs-mr-row">
                <td class="px-3 py-2">{{ item.item?.code }} - {{ item.item?.name }}</td>
                <td class="px-3 py-2 text-right">{{ $number(item.quantity) }}</td>
                <td class="px-3 py-2">{{ item.unit?.name || item.unit?.code || '-' }}</td>
                <td class="px-3 py-2">{{ item.priority || '-' }}</td>
              </tr>
            </table>
          </div>
        </div>
        <div v-else class="ofs-empty glass-panel rounded-2xl p-8 text-center text-sm text-gray-500">
          {{ $t('This record is not cached on this device.') }}
        </div>
      </template>
    </div>
  </div>
</template>

<script>
import ItemDetails from '@/Pages/Item/Details.vue';
import CheckinDetails from '@/Pages/Checkin/Details.vue';
import CheckoutDetails from '@/Pages/Checkout/Details.vue';
import TransferDetails from '@/Pages/Transfer/Details.vue';
import AdjustmentDetails from '@/Pages/Adjustment/Details.vue';
import { loadIndexData, loadDetail, loadItemStock, groupStockByWarehouse, loadGeneralReport } from '@/Core/offlineDataSource';

const TABS = [
  { key: 'items', label: 'Items', icon: 'bag', permission: 'read-items' },
  { key: 'warehouses', label: 'Warehouses', icon: 'building', permission: 'read-warehouses' },
  { key: 'categories', label: 'Categories', icon: 'folder', permission: 'read-categories' },
  { key: 'checkins', label: 'Checkins', icon: 'in', permission: 'read-checkins' },
  { key: 'checkouts', label: 'Checkouts', icon: 'out', permission: 'read-checkouts' },
  { key: 'transfers', label: 'Transfers', icon: 'truck', permission: 'read-transfers' },
  { key: 'adjustments', label: 'Adjustments', icon: 'adjustments', permission: 'read-adjustments' },
  { key: 'material-requests', label: 'Material Requests', icon: 'clip', permission: 'read-material-requests' },
  { key: 'general-report', label: 'General Report', icon: 'chart-bar', permission: 'read-reports' },
];

export default {
  components: { ItemDetails, CheckinDetails, CheckoutDetails, TransferDetails, AdjustmentDetails },

  data() {
    return {
      activeTab: 'items',
      search: '',
      rows: [],
      loading: false,
      selectedId: null,
      detailRecord: null,
      detailLoading: false,
      reportListing: [],
      reportGrandTotal: 0,
    };
  },

  computed: {
    visibleTabs() {
      return TABS.filter(tab => this.$can(tab.permission));
    },

    filteredReportListing() {
      const q = this.search.trim().toLowerCase();
      if (!q) return this.reportListing;
      return this.reportListing
        .map(w => {
          const warehouseMatches = (w.name || '').toLowerCase().includes(q) || (w.code || '').toLowerCase().includes(q);
          const items = warehouseMatches
            ? w.items
            : w.items.filter(i => (i.name || '').toLowerCase().includes(q) || (i.code || '').toLowerCase().includes(q));
          if (!warehouseMatches && items.length === 0) return null;
          return { ...w, items, total: items.reduce((s, i) => s + Number(i.total || 0), 0) };
        })
        .filter(Boolean);
    },
  },

  watch: {
    activeTab() {
      this.search = '';
      this.loadRows();
    },
    search() {
      // The general report filters reactively off the already-loaded
      // listing (see filteredReportListing) — no need to re-hit IndexedDB
      // on every keystroke the way the flat-list tabs do.
      if (this.activeTab !== 'general-report') {
        this.loadRows();
      }
    },
  },

  mounted() {
    const firstAllowed = this.visibleTabs[0];
    if (firstAllowed) {
      this.activeTab = firstAllowed.key;
    }
    this.loadRows();
  },

  methods: {
    selectTab(key) {
      if (this.activeTab === key) {
        return;
      }
      this.activeTab = key;
    },

    async loadRows() {
      if (this.activeTab === 'general-report') {
        this.loading = true;
        try {
          const result = await loadGeneralReport();
          this.reportListing = result.listing;
          this.reportGrandTotal = result.grandTotal;
        } finally {
          this.loading = false;
        }
        return;
      }

      this.loading = true;
      try {
        const result = await loadIndexData(this.activeTab, { search: this.search });
        this.rows = result.data;
      } finally {
        this.loading = false;
      }
    },

    formatNumber(value) {
      const n = Number(value || 0);
      return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
    },

    async openDetail(row) {
      this.selectedId = row.id;
      this.detailLoading = true;
      try {
        if (this.activeTab === 'items') {
          const [item, stockRows] = await Promise.all([loadDetail('items', row.id), loadItemStock(row.id)]);
          this.detailRecord = item ? { ...item, all_stock: stockRows, stock: groupStockByWarehouse(stockRows) } : null;
        } else {
          this.detailRecord = await loadDetail(this.activeTab, row.id);
        }
      } finally {
        this.detailLoading = false;
      }
    },

    closeDetail() {
      this.selectedId = null;
      this.detailRecord = null;
    },

    rowTitle(row) {
      if (this.activeTab === 'items' || this.activeTab === 'warehouses' || this.activeTab === 'categories') {
        return row.name;
      }
      return row.reference;
    },

    rowSubtitle(row) {
      switch (this.activeTab) {
        case 'items':
          return row.code || row.sku || '';
        case 'warehouses':
          return row.code || '';
        case 'categories':
          return row.parent ? `${this.$t('Under')} ${row.parent.name}` : row.code || '';
        case 'checkins':
        case 'checkouts':
          return [row.date ? this.$date(row.date) : null, row.warehouse?.name, row.contact?.name].filter(Boolean).join(' · ');
        case 'transfers':
          return [row.date ? this.$date(row.date) : null, row.from_warehouse?.name, row.to_warehouse ? `→ ${row.to_warehouse.name}` : null]
            .filter(Boolean)
            .join(' · ');
        case 'adjustments':
          return [row.date ? this.$date(row.date) : null, row.warehouse?.name, row.user?.name].filter(Boolean).join(' · ');
        case 'material-requests':
          return [row.date_requested ? this.$date(row.date_requested) : null, row.warehouse?.name, row.user?.name].filter(Boolean).join(' · ');
        default:
          return '';
      }
    },

    rowTrailing(row) {
      if (this.activeTab === 'items') {
        return row.price != null ? `${this.$number(row.price)} Tsh` : '';
      }
      if (this.activeTab === 'material-requests') {
        return row.status || '';
      }
      return '';
    },
  },
};
</script>

<style scoped>
.ofs-shell {
  position: relative;
}
.ofs-bg-decor {
  position: absolute;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;
}
.ofs-blob {
  position: absolute;
  border-radius: 9999px;
  filter: blur(70px);
  opacity: 0.28;
}
.ofs-blob-1 {
  top: -60px;
  left: -40px;
  height: 220px;
  width: 220px;
  background: radial-gradient(circle, #fbbf24, transparent 70%);
}
.ofs-blob-2 {
  bottom: -80px;
  right: -40px;
  height: 240px;
  width: 240px;
  background: radial-gradient(circle, #60a5fa, transparent 70%);
}

.ofs-header-icon {
  background: linear-gradient(145deg, #fbbf24, #d97706);
  box-shadow: 0 8px 20px -6px rgba(217, 119, 6, 0.5);
}

.ofs-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.ofs-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.85rem;
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 600;
  color: #475569;
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid #eef1f6;
  transition: all 0.15s ease;
  min-height: 0;
}
.ofs-tab:hover {
  background: rgba(251, 191, 36, 0.12);
}
.ofs-tab-active {
  color: #ffffff;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border-color: transparent;
}

.ofs-search-input {
  border: none;
  background: transparent;
  outline: none;
  flex: 1;
  font-size: 0.85rem;
  color: #1e293b;
}

.ofs-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.85rem 1.1rem;
  text-align: left;
  transition: background 0.15s ease;
  min-height: 0;
}
.ofs-row:hover {
  background: rgba(251, 191, 36, 0.08);
}
.ofs-row-title {
  font-size: 0.88rem;
  font-weight: 600;
  color: #1e293b;
}
.ofs-row-subtitle {
  font-size: 0.74rem;
  color: #94a3b8;
  margin-top: 1px;
}
.ofs-row-trailing {
  font-size: 0.8rem;
  font-weight: 600;
  color: #475569;
  white-space: nowrap;
}

.ofs-back-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #d97706;
  min-height: 0;
  padding: 0;
}
.ofs-back-btn:hover {
  color: #b45309;
}

.ofs-status-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.3rem 0.7rem;
  border-radius: 9999px;
  font-size: 0.7rem;
  font-weight: 700;
  color: #92400e;
  background: rgba(251, 191, 36, 0.16);
  border: 1px solid rgba(217, 119, 6, 0.2);
  flex: none;
  white-space: nowrap;
}

.ofs-info-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.5);
  border: 1px solid rgba(15, 23, 42, 0.05);
}
.ofs-info-label {
  font-size: 0.66rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #94a3b8;
}
.ofs-info-value {
  font-size: 0.86rem;
  font-weight: 600;
  color: #1e293b;
}

.ofs-report-card {
  border: 1px solid rgba(15, 23, 42, 0.05);
}
.ofs-report-total {
  font-size: 0.78rem;
  font-weight: 700;
  color: #92400e;
  white-space: nowrap;
}
.ofs-report-grand {
  border: 1px solid rgba(217, 119, 6, 0.2);
  background: rgba(251, 191, 36, 0.1);
}

.ofs-mr-thead {
  font-size: 0.66rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #94a3b8;
  background: rgba(15, 23, 42, 0.02);
}
.ofs-mr-row {
  border-top: 1px solid rgba(15, 23, 42, 0.05);
}

html.dark .ofs-status-pill {
  color: #fcd34d;
  background: rgba(251, 191, 36, 0.12);
  border-color: rgba(217, 119, 6, 0.3);
}
html.dark .ofs-report-total {
  color: #fcd34d;
}
html.dark .ofs-report-grand {
  background: rgba(251, 191, 36, 0.08);
  border-color: rgba(217, 119, 6, 0.3);
}
html.dark .ofs-report-grand span:last-child {
  color: #fde68a;
}
html.dark .ofs-info-item {
  background: rgba(255, 255, 255, 0.04);
  border-color: rgba(255, 255, 255, 0.08);
}
html.dark .ofs-info-value {
  color: #f1f5f9;
}
html.dark .ofs-mr-thead {
  background: rgba(255, 255, 255, 0.03);
  color: #94a3b8;
}
html.dark .ofs-mr-row {
  border-top-color: rgba(255, 255, 255, 0.06);
}

html.dark .ofs-tab {
  background: rgba(255, 255, 255, 0.05);
  border-color: rgba(255, 255, 255, 0.08);
  color: #cbd5e1;
}
html.dark .ofs-row-title {
  color: #f1f5f9;
}
html.dark .ofs-search-input {
  color: #e2e8f0;
}
</style>
