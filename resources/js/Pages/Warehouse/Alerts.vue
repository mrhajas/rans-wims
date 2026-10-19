<template>
  <admin-layout :title="$t('Alerts')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="bell" :title="$t('Item quantity alerts for x', { x: warehouse.name })" :subtitle="$t('Please review the data in the table below')" />

      <div class="txn-toolbar mb-6 flex justify-between items-center print:hidden relative z-20">
        <search-filter v-model="form.search" :dropdown="false" class="w-full max-w-md mr-4" @reset="reset" />
      </div>

      <section class="lg-card">
        <div id="dd-table" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Name') }}</th>
                <th>{{ $t('Code') }}</th>
                <th class="num">{{ $t('Alert on') }}</th>
                <th class="num">{{ $t('Stock') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr :key="item.id" v-for="item in items.data">
                <td :data-label="$t('Name')" class="lg-main">{{ item.name }}</td>
                <td :data-label="$t('Code')"><span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ item.code }}</span></td>
                <td :data-label="$t('Alert on')" class="num">{{ $number(item.alert_quantity) }}</td>
                <td :data-label="$t('Stock')" class="num">
                  <div v-for="stock in item.stock" :key="stock.id" class="al-stock" :class="{ 'is-low': Number(stock.quantity) <= Number(item.alert_quantity) }">
                    {{ $number(stock.quantity) }}
                  </div>
                </td>
              </tr>
              <tr v-if="items.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="4">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination class="mt-6" :meta="items.meta" :links="items.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Pagination from '@/Shared/Pagination.vue';
import TecDropdown from '@/Jetstream/Dropdown.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecDropdownLink from '@/Jetstream/DropdownLink.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';

export default {
  components: {
    Pagination,
    AdminLayout,
    PageHeader,
    TecDropdown,
    AutoComplete,
    SearchFilter,
    TecDropdownLink,
    TecSectionTitle,
  },

  props: {
    items: Object,
    filters: Object,
    warehouse: Object,
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
      },
    };
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy(this.form);
        this.$inertia.get(
          this.route(
            'alerts.list',
            Object.keys(query).length ? { ...query, warehouse: this.warehouse.id } : { warehouse: this.warehouse.id, remember: 'forget' }
          ),
          {},
          { replace: true, preserveState: true }
        );
      }, 150),
      deep: true,
    },
  },

  methods: {
    reset() {
      this.form = mapValues(this.form, () => null);
    },
  },
};
</script>

<style scoped>
.al-stock {
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.al-stock.is-low {
  color: #be123c;
}
html.dark .al-stock.is-low {
  color: #fda4af;
}
</style>
