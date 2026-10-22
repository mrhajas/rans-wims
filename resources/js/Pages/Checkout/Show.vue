<template>
  <admin-layout :title="$t('Checkout Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="out" :title="checkout.reference" :subtitle="$t('Please review the checkout details below')">
        <Link :href="route('checkouts.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Checkouts') }}</Link>
        <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')" @click="print()">
          <icons name="printer" class="w-4 h-4" />
        </button>
        <button v-if="canEditCheckouts" type="button" class="lg-btn lg-btn-primary" @click.prevent="goToEdit(checkout.id)">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </button>
      </page-header>

      <checkout-details v-if="checkout" :checkout="checkout" />
    </div>
  </admin-layout>
</template>

<script>
import CheckoutDetails from './Details.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { printWithAnimation, printUrlInFrame, stockDocumentPrintSheet } from '@/Core/printAnimation';

export default {
  props: { checkout: Object },
  components: { AdminLayout, CheckoutDetails, PageHeader },
  computed: {
    canEditCheckouts() {
      const user = this.$page.props.auth?.user;
      const roleNames = (user?.roles || []).map(role => role.name);

      return this.$can('update-checkouts') || roleNames.includes('Super Admin') || roleNames.includes('Admin') || Boolean(user?.edit_all);
    },
  },
  methods: {
    goToEdit(id) {
      window.location.assign(route('checkouts.edit', id));
    },
    print() {
      // Open the redesigned print document when the user may print; otherwise fall back to the page print.
      if (this.$can('print-checkouts')) {
        printWithAnimation(stockDocumentPrintSheet(this, this.checkout, 'Check-out'), () => printUrlInFrame(this.route('checkouts.print', this.checkout.id)));
        return;
      }
      window.print();
    },
  },
};
</script>
