<template>
  <admin-layout :title="$t('Asset Movement Report')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="truck" :title="$t('Asset Movement Report')" :subtitle="$t('Check-out, check-in, and transfer history across all assets.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-green"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="pdfUrl" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
      </page-header>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Date') }}</th>
              <th>{{ $t('Asset') }}</th>
              <th>{{ $t('Movement') }}</th>
              <th class="num">{{ $t('Qty') }}</th>
              <th>{{ $t('From') }}</th>
              <th>{{ $t('To') }}</th>
              <th>{{ $t('By') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in movements.data" :key="m.id">
              <td :data-label="$t('Date')">{{ $date(m.movement_date) }}</td>
              <td :data-label="$t('Asset')">
                <Link :href="route('assets.show', m.asset.id)" class="lg-main mr-link">{{ m.asset.asset_number }}</Link>
                <div class="lg-subtext">{{ m.asset.name }}</div>
              </td>
              <td :data-label="$t('Movement')"><span class="lg-chip lg-chip-blue" style="text-transform: none">{{ m.movement_type }}</span></td>
              <td :data-label="$t('Qty')" class="num">{{ Number(m.quantity || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }) }}</td>
              <td :data-label="$t('From')">{{ (m.from_warehouse && m.from_warehouse.name) || m.from_label || '-' }}</td>
              <td :data-label="$t('To')">{{ (m.to_warehouse && m.to_warehouse.name) || m.to_label || '-' }}</td>
              <td :data-label="$t('By')">{{ (m.performed_by && m.performed_by.name) || '-' }}</td>
            </tr>
            <tr v-if="!movements.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="movements.data.length" class="mt-6" :meta="movements.meta" :links="movements.links" />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Pagination, PageHeader },
  props: { movements: Object, filters: Object, movementTypes: Array },
  computed: {
    queryString() {
      const query = Object.fromEntries(Object.entries(this.filters || {}).filter(([, v]) => v));
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    printUrl() {
      return this.route('assets.movement-report.print') + this.queryString;
    },
    pdfUrl() {
      return this.route('assets.movement-report.pdf') + this.queryString;
    },
  },
};
</script>

<style scoped>
.mr-link { color: #4338ca; }
.mr-link:hover { text-decoration: underline; }
html.dark .mr-link { color: #a5b4fc; }
</style>
