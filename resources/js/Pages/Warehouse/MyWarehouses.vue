<template>
  <admin-layout :title="$t('My Warehouses')">
    <div class="lg-page px-4 md:px-0 mw-page">
      <page-header icon="building" :title="$t('My Warehouses')" :subtitle="$t('View stock for your assigned warehouses')">
        <button type="button" @click="printReport" class="lg-btn lg-btn-dark print:hidden">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print Report') }}
        </button>
        <button type="button" @click="downloadPdf" class="lg-btn lg-btn-primary print:hidden">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Download PDF') }}
        </button>
      </page-header>

      <div class="txn-toolbar mb-6 flex flex-col gap-4 lg:flex-row lg:items-end print:hidden relative z-20">
        <div class="w-full lg:max-w-[18rem]">
          <auto-complete
            id="my-warehouse"
            :label="$t('Project(s) / Warehouse(s)')"
            :suggestions="warehouses"
            v-model="selectedWarehouseId"
          />
        </div>
        <div class="w-full lg:max-w-md lg:flex-1">
          <input v-model="search" type="text" class="mw-input w-full" :placeholder="$t('Search items by code or name')" />
        </div>
      </div>

      <section class="lg-card">
        <header v-if="selectedWarehouse" class="lg-card-head flex-wrap">
          <span class="lg-card-ico lg-ico-teal"><icons name="folder" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title flex flex-wrap items-center gap-2">
              <span>{{ selectedWarehouse.name }}</span>
              <span class="lg-chip" :class="selectedWarehouse.completed_at ? 'lg-chip-red' : 'lg-chip-green'">
                <span class="h-1.5 w-1.5 rounded-full" :class="selectedWarehouse.completed_at ? 'bg-red-500' : 'bg-emerald-500'"></span>
                {{ selectedWarehouse.completed_at ? $t('Completed') : $t('Active') }}
              </span>
            </h2>
            <p class="lg-card-sub inline-flex items-center gap-1.5">
              <icons name="users" class="w-3.5 h-3.5" />
              <span>{{ $t('Assigned to') }}: {{ selectedWarehouse.assigned_users || userName }}</span>
            </p>
          </div>
        </header>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Number') }}</th>
                <th>{{ $t('Item Code') }}</th>
                <th>{{ $t('Item Name') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th>{{ $t('Unit') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, index) in filteredItems" :key="item.id">
                <td :data-label="$t('Number')"><span class="lg-subtext">{{ index + 1 }}</span></td>
                <td :data-label="$t('Item Code')"><span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ item.code }}</span></td>
                <td :data-label="$t('Item Name')" class="lg-main">{{ item.name }}</td>
                <td :data-label="$t('Quantity')" class="num lg-main">{{ item.quantity }}</td>
                <td :data-label="$t('Unit')">{{ item.unit }}</td>
              </tr>
              <tr v-if="filteredItems.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('No items found for this warehouse.') }}</td>
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
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
    AutoComplete,
    TecSectionTitle,
  },

  props: {
    warehouses: {
      type: Array,
      default: () => [],
    },
    userName: {
      type: String,
      default: '',
    },
    initialWarehouseId: {
      type: [Number, String],
      default: null,
    },
  },

  data() {
    return {
      selectedWarehouseId: this.initialWarehouseId || (this.warehouses[0] ? this.warehouses[0].id : null),
      search: '',
    };
  },

  computed: {
    selectedWarehouse() {
      return this.warehouses.find(warehouse => String(warehouse.id) === String(this.selectedWarehouseId)) || null;
    },
    filteredItems() {
      if (!this.selectedWarehouse) {
        return [];
      }

      const query = this.search.trim().toLowerCase();
      const items = this.selectedWarehouse.items || [];

      if (!query) {
        return items;
      }

      return items.filter(item => {
        const code = (item.code || '').toLowerCase();
        const name = (item.name || '').toLowerCase();
        return code.includes(query) || name.includes(query);
      });
    },
  },

  methods: {
    printReport() {
      if (!this.selectedWarehouseId) {
        return;
      }

      window.open(route('my.warehouses.print', { warehouse: this.selectedWarehouseId }), '_blank');
    },
    downloadPdf() {
      if (!this.selectedWarehouseId) {
        return;
      }

      window.open(route('my.warehouses.pdf', { warehouse: this.selectedWarehouseId }), '_blank');
    },
  },
};
</script>

<style scoped>
.mw-input {
  padding: 0.6rem 0.9rem;
  border-radius: 0.85rem;
  border: 1px solid var(--lg-line);
  background: var(--lg-card);
  color: var(--lg-ink);
  font-size: 0.85rem;
}
.mw-input:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.22);
}
</style>
