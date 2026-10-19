<template>
  <admin-layout :title="$t('Transaction Detail')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clip" :title="transaction.reference || transactionTitle" :subtitle="transactionTitle">
        <Link :href="route('project-material-ledger.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          <span>{{ $t('Project Material Ledger') }}</span>
        </Link>
        <button type="button" class="lg-btn lg-btn-dark" @click="printPage">
          <icons name="printer" class="h-4 w-4" />
          <span>{{ $t('Print') }}</span>
        </button>
      </page-header>

      <component :is="detailsComponent" v-if="detailsComponent" v-bind="detailsProps" class="mt-0 pt-0" />

      <div class="lg-card mt-6 print:hidden">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Audit History') }}</div>
          </div>
        </div>
        <div v-if="!history.length" class="lg-empty">
          {{ $t('No history recorded for this transaction yet.') }}
        </div>
        <div v-else class="pml-timeline">
          <div v-for="(entry, idx) in history" :key="idx" class="pml-history-row">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <span class="lg-main">{{ entry.description }}</span>
              <span class="lg-subtext">{{ entry.created_at }}</span>
            </div>
            <div class="lg-subtext">{{ $t('By') }} {{ entry.causer }}</div>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import CheckinDetails from '@/Pages/Checkin/Details.vue';
import CheckoutDetails from '@/Pages/Checkout/Details.vue';
import TransferDetails from '@/Pages/Transfer/Details.vue';

export default {
  components: { AdminLayout, Link, PageHeader },

  props: {
    transactionType: { type: String, required: true },
    transaction: { type: Object, required: true },
    history: { type: Array, default: () => [] },
  },

  computed: {
    detailsComponent() {
      if (this.transactionType === 'checkin') return CheckinDetails;
      if (this.transactionType === 'checkout') return CheckoutDetails;
      if (this.transactionType === 'transfer') return TransferDetails;
      return null;
    },
    detailsProps() {
      if (this.transactionType === 'checkin') return { checkin: this.transaction };
      if (this.transactionType === 'checkout') return { checkout: this.transaction };
      if (this.transactionType === 'transfer') return { transfer: this.transaction };
      return {};
    },
    transactionTitle() {
      if (this.transactionType === 'checkin') return this.$t('Checked In');
      if (this.transactionType === 'checkout') return this.$t('Checked Out');
      if (this.transactionType === 'transfer') return this.$t('Transfer');
      return this.$t('Transaction');
    },
  },

  methods: {
    printPage() {
      window.print();
    },
  },
};
</script>

<style scoped>
.pml-timeline { display: flex; flex-direction: column; }
.pml-history-row { position: relative; padding: 0 0 14px 20px; border-left: 2px solid var(--lg-line); margin-left: 6px; }
.pml-history-row::before { content: ''; position: absolute; left: -6px; top: 4px; width: 10px; height: 10px; border-radius: 9999px; background: #6366f1; box-shadow: 0 0 0 3px var(--lg-card); }
.pml-history-row:last-child { padding-bottom: 0; border-left-color: transparent; }
</style>
