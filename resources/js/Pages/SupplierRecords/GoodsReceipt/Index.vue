<template>
  <admin-layout :title="$t('Goods Receipts')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="download" :title="$t('Goods Receipts')" :subtitle="$t('Deliveries recorded against purchase orders.')">
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Records') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="txn-toolbar mb-6 flex flex-col lg:flex-row gap-4 lg:items-end lg:justify-between print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-lg" :dropdown="false" @reset="reset" />
      </div>

      <section class="lg-card sr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Purchase Order') }}</th>
                <th>{{ $t('Project') }}</th>
                <th>{{ $t('Date Received') }}</th>
                <th>{{ $t('Received By') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="gr in goodsReceipts.data" :key="gr.id" class="is-click" @click="goto(gr.id)">
                <td :data-label="$t('Reference')">
                  <div class="lg-main">{{ gr.reference }}</div>
                </td>
                <td :data-label="$t('Purchase Order')">
                  <Link
                    v-if="gr.purchase_order_id"
                    :href="route('supplier-records.purchase-orders.show', gr.purchase_order_id)"
                    class="sr-link"
                    @click.stop
                  >
                    {{ gr.purchase_order_reference }}
                  </Link>
                  <span v-else>-</span>
                </td>
                <td :data-label="$t('Project')">{{ gr.warehouse_name || '-' }}</td>
                <td :data-label="$t('Date Received')">{{ $date(gr.date_received) }}</td>
                <td :data-label="$t('Received By')">{{ gr.received_by_name || '-' }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusChip(gr.status)" style="text-transform: none">{{ gr.status }}</span>
                </td>
              </tr>
              <tr v-if="goodsReceipts.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="goodsReceipts.data.length" class="mt-6" :meta="goodsReceipts.meta" :links="goodsReceipts.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, SearchFilter, Pagination, PageHeader, StatTiles },

  props: {
    filters: Object,
    goodsReceipts: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'download', tone: 'navy', label: this.$t('Total Receipts'), value: this.stats.total },
        { icon: 'tick', tone: 'emerald', label: this.$t('Confirmed'), value: this.stats.confirmed },
        { icon: 'calendar', tone: 'indigo', label: this.$t('Received This Month'), value: this.stats.this_month },
        { icon: 'cart', tone: 'amber', label: this.$t('Purchase Orders Covered'), value: this.stats.orders },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('supplier-records.goods-receipts.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    goto(id) {
      this.$inertia.visit(route('supplier-records.goods-receipts.show', id));
    },
    statusChip(status) {
      const map = {
        Confirmed: 'lg-chip-green',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.sr-link {
  font-weight: 700;
  color: var(--lg-navy);
}
.sr-link:hover {
  text-decoration: underline;
}
html.dark .sr-link {
  color: #93c5fd;
}
</style>
