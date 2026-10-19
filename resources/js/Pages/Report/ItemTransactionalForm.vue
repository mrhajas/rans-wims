<template>
  <admin-layout :title="$t('Transaction Form')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc-text" :title="transaction.reference || transactionTitle" :subtitle="transactionTitle">
        <button type="button" class="lg-btn lg-btn-primary" @click="printPage">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
        <button type="button" class="lg-btn lg-btn-soft" @click="closePage">
          <icons name="cross" class="w-4 h-4" />{{ $t('Close') }}
        </button>
      </page-header>

      <component
        v-if="detailsComponent"
        :is="detailsComponent"
        v-bind="detailsProps"
        class="mt-0 pt-0"
      />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import CheckinDetails from '@/Pages/Checkin/Details.vue';
import CheckoutDetails from '@/Pages/Checkout/Details.vue';
import TransferDetails from '@/Pages/Transfer/Details.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
  },

  props: {
    transactionType: {
      type: String,
      required: true,
    },
    transaction: {
      type: Object,
      required: true,
    },
  },

  computed: {
    detailsComponent() {
      if (this.transactionType === 'checkin') {
        return CheckinDetails;
      }
      if (this.transactionType === 'checkout') {
        return CheckoutDetails;
      }
      if (this.transactionType === 'transfer') {
        return TransferDetails;
      }
      return null;
    },
    detailsProps() {
      if (this.transactionType === 'checkin') {
        return { checkin: this.transaction };
      }
      if (this.transactionType === 'checkout') {
        return { checkout: this.transaction };
      }
      if (this.transactionType === 'transfer') {
        return { transfer: this.transaction };
      }
      return {};
    },
    transactionTitle() {
      if (this.transactionType === 'checkin') {
        return this.$t('Checkin Form');
      }
      if (this.transactionType === 'checkout') {
        return this.$t('Checkout Form');
      }
      if (this.transactionType === 'transfer') {
        return this.$t('Transfer Form');
      }
      return this.$t('Transaction Form');
    },
  },

  methods: {
    printPage() {
      window.print();
    },
    closePage() {
      window.close();
      if (!window.closed) {
        window.history.back();
      }
    },
  },
};
</script>
