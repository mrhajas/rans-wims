<template>
  <admin-layout :title="$t('Purchase Requests')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Purchase Requests')" :subtitle="$t('Internal requests for materials, routed for approval before quotations are sought.')">
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="home" class="w-4 h-4" />{{ $t('Supplier Records') }}
        </Link>
        <Link :href="route('supplier-records.purchase-requests.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Purchase Request') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <div class="txn-toolbar mb-6 flex flex-col lg:flex-row gap-4 lg:items-end lg:justify-between print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-lg" @reset="reset">
          <auto-complete id="status" position="left" :label="$t('Status')" v-model="form.status" class="mt-1 w-full" :suggestions="statusOptions" />
        </search-filter>
      </div>

      <section class="lg-card spr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Project') }}</th>
                <th>{{ $t('Requested By') }}</th>
                <th>{{ $t('Date Requested') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="lg-sticky-end">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="pr in purchaseRequests.data" :key="pr.id" class="is-click" @click="goto(pr.id)">
                <td :data-label="$t('Reference')"><span class="lg-main">{{ pr.reference }}</span></td>
                <td :data-label="$t('Project')">{{ pr.warehouse_name || '-' }}</td>
                <td :data-label="$t('Requested By')">{{ pr.user_name || '-' }}</td>
                <td :data-label="$t('Date Requested')">{{ $date(pr.date_requested) }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusBadgeClass(pr.status)" style="text-transform: none">{{ pr.status }}</span>
                </td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions" @click.stop>
                    <Link :href="route('supplier-records.purchase-requests.show', pr.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')">
                      <icons name="eye-flat" class="w-4 h-4" />
                    </Link>
                    <a v-if="$can('print-purchase-requests')" :href="route('supplier-records.purchase-requests.print', pr.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')">
                      <icons name="printer" class="w-4 h-4" />
                    </a>
                  </div>
                </td>
              </tr>
              <tr v-if="purchaseRequests.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination v-if="purchaseRequests.data.length" class="mt-6" :meta="purchaseRequests.meta" :links="purchaseRequests.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
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
    purchaseRequests: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        status: this.filters.status,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'doc-text', tone: 'navy', label: this.$t('All'), value: this.stats.total, status: null, active: !this.form.status },
        { icon: 'clock', tone: 'amber', label: this.$t('Pending Approval'), value: this.stats.pending, status: 'Pending Approval', active: this.form.status === 'Pending Approval' },
        { icon: 'tick', tone: 'sky', label: this.$t('Approved'), value: this.stats.approved, status: 'Approved', active: this.form.status === 'Approved' },
        { icon: 'x-circle', tone: 'rose', label: this.$t('Rejected'), value: this.stats.rejected, status: 'Rejected', active: this.form.status === 'Rejected' },
        { icon: 'truck', tone: 'emerald', label: this.$t('Converted to PO'), value: this.stats.converted, status: 'Converted to PO', active: this.form.status === 'Converted to PO' },
      ];
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('supplier-records.purchase-requests.index', Object.keys(query).length ? query : { remember: 'forget' }), {
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
      this.form.status = item.status || null;
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    goto(id) {
      this.$inertia.visit(route('supplier-records.purchase-requests.show', id));
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate',
        'Pending Approval': 'lg-chip-amber',
        Approved: 'lg-chip-blue',
        Rejected: 'lg-chip-red',
        'Converted to PO': 'lg-chip-green',
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
