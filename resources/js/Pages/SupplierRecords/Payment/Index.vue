<template>
  <admin-layout :title="$t('Payments')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="cash"
        :title="$t('Payments')"
        :subtitle="$t('Permanent record of payments made against approved payment requests.')"
      >
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Records') }}
        </Link>
        <Link :href="route('supplier-records.payments.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Record Payment') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card sr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Method') }}</th>
                <th>{{ $t('Paid By') }}</th>
                <th>{{ $t('Paid On') }}</th>
                <th>{{ $t('Verification') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="payment in payments.data" :key="payment.id" class="is-click" @click="goto(payment.id)">
                <td :data-label="$t('Reference')">
                  <div class="lg-main">{{ payment.reference }}</div>
                </td>
                <td :data-label="$t('Supplier')">{{ payment.supplier_name }}</td>
                <td :data-label="$t('Amount')" class="num sr-money">{{ $money(payment.amount, payment.currency) }}</td>
                <td :data-label="$t('Method')">
                  <div>
                    {{ payment.method }}
                    <div v-if="payment.cheque_number" class="lg-subtext">{{ payment.cheque_number }}</div>
                  </div>
                </td>
                <td :data-label="$t('Paid By')">{{ payment.paid_by_name || '-' }}</td>
                <td :data-label="$t('Paid On')">{{ $date(payment.paid_on) }}</td>
                <td :data-label="$t('Verification')">
                  <span class="lg-chip" :class="payment.verification_status === 'Verified' ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">
                    <icons v-if="payment.verification_status === 'Verified'" name="tick" class="w-3 h-3" />
                    {{ payment.verification_status }}
                  </span>
                </td>
              </tr>
              <tr v-if="payments.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="payments.data.length" class="mt-6" :meta="payments.meta" :links="payments.links" />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Pagination, PageHeader, StatTiles },

  props: {
    payments: Object,
    stats: { type: Object, default: null },
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'cash', tone: 'navy', label: this.$t('Payments Made'), value: this.stats.total },
        { icon: 'calculator', tone: 'indigo', label: this.$t('Total Amount Paid'), value: this.$money(this.stats.amount) },
        { icon: 'tick', tone: 'emerald', label: this.$t('Verified'), value: this.stats.verified },
        { icon: 'clock', tone: 'amber', label: this.$t('Pending Verification'), value: this.stats.pending },
      ];
    },
  },

  methods: {
    goto(id) {
      this.$inertia.visit(route('supplier-records.payments.show', id));
    },
  },
};
</script>

<style scoped>
.sr-money {
  font-weight: 700;
}
</style>
