<template>
  <admin-layout :title="$t('Purchase Orders')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="truck" :title="$t('Purchase Orders')" :subtitle="$t('Orders raised against suppliers, from draft through delivery.')">
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="home" class="w-4 h-4" />{{ $t('Supplier Records') }}
        </Link>
        <Link :href="route('supplier-records.purchase-orders.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Purchase Order') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <div class="txn-toolbar mb-6 flex flex-col lg:flex-row gap-4 lg:items-end lg:justify-between print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-lg" @reset="reset">
          <auto-complete id="supplier" position="left" :label="$t('Supplier')" v-model="form.supplier_id" class="mt-1 w-full" :suggestions="supplierSuggestions" />
          <auto-complete id="status" position="left" :label="$t('Status')" v-model="form.status" class="mt-1 w-full" :suggestions="statusOptions" />
        </search-filter>
      </div>

      <section class="lg-card spr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('Project') }}</th>
                <th>{{ $t('Date Issued') }}</th>
                <th>{{ $t('Created By') }}</th>
                <th class="text-right">{{ $t('Total') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="lg-sticky-end">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="po in purchaseOrders.data" :key="po.id" class="is-click" @click="goto(po.id)">
                <td :data-label="$t('Reference')"><span class="lg-main">{{ po.reference }}</span></td>
                <td :data-label="$t('Supplier')">{{ po.supplier_name }}</td>
                <td :data-label="$t('Project')">{{ po.warehouse_name || '-' }}</td>
                <td :data-label="$t('Date Issued')">{{ $date(po.date_issued) }}</td>
                <td :data-label="$t('Created By')">{{ po.user_name || '-' }}</td>
                <td :data-label="$t('Total')" class="text-right font-semibold">{{ $money(po.total, po.currency) }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusBadgeClass(po.status)" style="text-transform: none">{{ po.status }}</span>
                </td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions" @click.stop>
                    <Link :href="route('supplier-records.purchase-orders.show', po.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')">
                      <icons name="eye-flat" class="w-4 h-4" />
                    </Link>
                  </div>
                </td>
              </tr>
              <tr v-if="purchaseOrders.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination v-if="purchaseOrders.data.length" class="mt-6" :meta="purchaseOrders.meta" :links="purchaseOrders.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import Pagination from '@/Shared/Pagination.vue';

export default {
  components: { PageHeader, StatTiles, AdminLayout, AutoComplete, SearchFilter, Pagination },

  props: {
    filters: Object,
    statuses: Array,
    suppliers: Array,
    purchaseOrders: Object,
    cards: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        supplier_id: this.filters.supplier_id || null,
        status: this.filters.status || null,
      },
    };
  },

  computed: {
    statTiles() {
      const s = this.stats || this.cards;
      if (!s) {
        return [];
      }

      const status = this.form.status;
      const tiles = [
        { icon: 'truck', tone: 'navy', label: this.$t('All'), value: s.total, status: null, active: !status },
        { icon: 'doc-text', tone: 'slate', label: this.$t('Draft'), value: s.draft, status: 'Draft', active: status === 'Draft' },
        { icon: 'clock', tone: 'amber', label: this.$t('Awaiting Approval'), value: s.awaiting_approval, status: 'Submitted', active: status === 'Submitted' },
        { icon: 'truck', tone: 'sky', label: this.$t('In Progress'), value: s.in_progress, status: 'In Progress', active: status === 'In Progress' },
        { icon: 'tick', tone: 'emerald', label: this.$t('Completed'), value: s.completed, status: 'Completed', active: status === 'Completed' },
      ];

      // Older payloads only carry the four `cards` counts, so there is no overall total.
      return s.total === undefined ? tiles.slice(1) : tiles;
    },
    supplierSuggestions() {
      return this.suppliers.map(s => ({ id: s.id, name: s.name }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('supplier-records.purchase-orders.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    pickTile(item) {
      this.selectCard(item.status || null);
    },
    selectCard(status) {
      this.form.search = '';
      this.form.supplier_id = null;
      this.form.status = status;
    },
    reset() {
      this.form.search = '';
      this.form.supplier_id = null;
      this.form.status = null;
    },
    goto(id) {
      this.$inertia.visit(route('supplier-records.purchase-orders.show', id));
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate',
        Submitted: 'lg-chip-amber',
        Approved: 'lg-chip-blue',
        'Sent to Supplier': 'lg-chip-indigo',
        Acknowledged: 'lg-chip-cyan',
        'Partially Delivered': 'lg-chip-violet',
        Completed: 'lg-chip-green',
        Rejected: 'lg-chip-red',
        Cancelled: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.spr-card {
  padding: 0.9rem;
}
@media (max-width: 767.98px) {
  .spr-card {
    padding: 0;
    background: transparent;
    border: 0;
    box-shadow: none;
  }
}
</style>
