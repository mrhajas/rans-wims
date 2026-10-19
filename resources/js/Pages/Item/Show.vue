<template>
  <admin-layout :title="$t('Item Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="collection" :title="$t(item.name)" :subtitle="$t('Please review the item details below')">
        <Link :href="route('items.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Items') }}</Link>
        <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Print Report')" :aria-label="$t('Print')" @click="print()">
          <icons name="printer" class="w-4 h-4" />
        </button>
        <a :href="route('items.report.pdf', item.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download PDF')" :aria-label="$t('Download PDF')">
          <icons name="download" class="w-4 h-4" />
        </a>
        <Link
          v-if="canViewPriceHistory"
          :href="route('items.price-history.index', item.id)"
          class="lg-icon-btn lg-tip"
          :data-tip="$t('Price Inflation')"
          :aria-label="$t('Price Inflation')"
        >
          <icons name="chart-bar" class="w-4 h-4" />
        </Link>
        <Link v-if="$can('update-items')" :href="route('items.edit', item.id)" class="lg-btn lg-btn-primary"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
      </page-header>

      <item-details v-if="item" :item="item" />
    </div>
  </admin-layout>
</template>

<script>
import ItemDetails from './Details.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { printWithAnimation, itemPrintSheet } from '@/Core/printAnimation';

export default {
  props: { item: Object },
  components: { AdminLayout, ItemDetails, PageHeader },
  computed: {
    canViewPriceHistory() {
      const roles = this.$user.roles || [];
      return roles.some(r => ['Super Admin', 'Admin', 'Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger'].includes(r.name));
    },
  },
  methods: {
    print() {
      printWithAnimation(itemPrintSheet(this, this.item), () => window.open(this.route('items.report.print', this.item.id), '_blank'));
    },
  },
};
</script>
