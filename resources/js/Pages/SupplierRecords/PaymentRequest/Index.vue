<template>
  <admin-layout :title="$t('Payment Requests')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="key"
        :title="$t('Payment Requests')"
        :subtitle="$t('Requests to pay a supplier invoice, in full or in part, pending approval.')"
      >
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Records') }}
        </Link>
        <Link :href="route('supplier-records.payment-requests.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('New Payment Request') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <section class="lg-card sr-filters print:hidden">
        <div class="lg-fields">
          <auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable />
          <div class="sr-filters-reset">
            <button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </div>
      </section>

      <section class="lg-card sr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Date Requested') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="pr in paymentRequests.data" :key="pr.id" class="is-click" @click="goto(pr.id)">
                <td :data-label="$t('Reference')">
                  <div class="lg-main">{{ pr.reference }}</div>
                </td>
                <td :data-label="$t('Supplier')">{{ pr.supplier_name }}</td>
                <td :data-label="$t('Amount')" class="num sr-money">{{ $money(pr.amount) }}</td>
                <td :data-label="$t('Date Requested')">{{ $date(pr.date_requested) }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusChip(pr.status)" style="text-transform: none">{{ pr.status }}</span>
                </td>
              </tr>
              <tr v-if="paymentRequests.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="paymentRequests.data.length" class="mt-6" :meta="paymentRequests.meta" :links="paymentRequests.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, Pagination, PageHeader, StatTiles },

  props: {
    filters: Object,
    statuses: Array,
    paymentRequests: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        status: this.filters.status || null,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'key', tone: 'navy', label: this.$t('All'), value: this.stats.total, status: null, active: !this.form.status },
        { icon: 'clock', tone: 'amber', label: this.$t('Pending Approval'), value: this.stats.pending, status: 'Pending Approval', active: this.form.status === 'Pending Approval' },
        { icon: 'tick', tone: 'indigo', label: this.$t('Approved'), value: this.stats.approved, status: 'Approved', active: this.form.status === 'Approved' },
        { icon: 'x-circle', tone: 'rose', label: this.$t('Rejected'), value: this.stats.rejected, status: 'Rejected', active: this.form.status === 'Rejected' },
        { icon: 'cash', tone: 'emerald', label: this.$t('Paid'), value: this.stats.paid, status: 'Paid', active: this.form.status === 'Paid' },
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
        this.$inertia.visit(this.route('supplier-records.payment-requests.index', Object.keys(query).length ? query : { remember: 'forget' }), {
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
      this.$inertia.visit(route('supplier-records.payment-requests.show', id));
    },
    statusChip(status) {
      const map = {
        'Pending Approval': 'lg-chip-amber',
        Approved: 'lg-chip-blue',
        Rejected: 'lg-chip-red',
        Paid: 'lg-chip-green',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.sr-filters {
  position: relative;
  z-index: 20;
  padding-top: 0.9rem;
  padding-bottom: 1rem;
}
.sr-filters-reset {
  display: flex;
  align-items: flex-end;
}
.sr-money {
  font-weight: 700;
}
</style>
