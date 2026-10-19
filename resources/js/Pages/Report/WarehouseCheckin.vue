<template>
  <admin-layout :title="$t('x_report', { x: $t('Warehouse Stock Value') })">
    <div class="lg-page px-4 md:px-0 rc-form">
      <page-header
        icon="in"
        :title="$t('x_report', { x: $t('Warehouse Checkin') })"
        :subtitle="$t('Items delivered to warehouse within date range')"
      >
        <tec-button @click="" class="bg-dark">
          <span class="hidden lg:inline">{{ $t('Download PDF') }}</span>
        </tec-button>
      </page-header>

      <section class="lg-card rc-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Warehouse') }}</p>
          </div>
        </header>
        <div class="lg-fields">
          <auto-complete :suggestions="warehouses" v-model="warehouse" class="w-full" />
        </div>
      </section>

      <section class="lg-card rc-table-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('SN') }}</th>
                <th>{{ $t('Item Name') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr :key="ci" v-for="(item, ci) in checkins">
                <td :data-label="$t('SN')">{{ ci + 1 }}</td>
                <td :data-label="$t('Item Name')"><span class="lg-main">item name</span></td>
                <td :data-label="$t('Quantity')" class="num">quantity</td>
                <td :data-label="$t('Amount')" class="num">amount</td>
              </tr>
              <tr v-if="checkins.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="4">{{ $t('There is no data to display.') }}</td>
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
import PageHeader from '@/Shared/Page/PageHeader.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TecButton from "@/Jetstream/Button.vue"

export default {
  components: {
    AdminLayout,
    PageHeader,
    SelectInput,
    AutoComplete,
    TecButton
  },

  props: {
    checkins: Array,
    warehouses: Array
  },

  data() {
    return {
      warehouse: this.warehouses && this.warehouses.length ? this.warehouses[0].id : null,
    };
  },

  computed: {
  },

  methods: {
    print() {
      window.print();
    },
  },
};
</script>

<style scoped>
.rc-form :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.rc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.rc-filters {
  position: relative;
  z-index: 30;
}
.rc-table-card {
  padding: 0.6rem;
}
</style>
