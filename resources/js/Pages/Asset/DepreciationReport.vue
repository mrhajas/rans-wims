<template>
  <admin-layout :title="$t('Asset Depreciation Report')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-pie" :title="$t('Asset Depreciation Report')" :subtitle="$t('Capitalised cost, accumulated depreciation, and current book value.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-green"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="pdfUrl" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
      </page-header>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Asset No.') }}</th>
              <th>{{ $t('Name') }}</th>
              <th>{{ $t('Category') }}</th>
              <th class="num">{{ $t('Cost') }}</th>
              <th class="num">{{ $t('Accumulated Depreciation') }}</th>
              <th class="num">{{ $t('Book Value') }}</th>
              <th class="num">{{ $t('Residual Value') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in assets.data" :key="a.id">
              <td :data-label="$t('Asset No.')"><Link :href="route('assets.show', a.id)" class="lg-main dr-link">{{ a.asset_number }}</Link></td>
              <td :data-label="$t('Name')">{{ a.name }}</td>
              <td :data-label="$t('Category')">{{ a.category || '-' }}</td>
              <td :data-label="$t('Cost')" class="num">{{ $money(a.total_capitalised_cost) }}</td>
              <td :data-label="$t('Accumulated Depreciation')" class="num">{{ $money(a.accumulated_depreciation) }}</td>
              <td :data-label="$t('Book Value')" class="num font-bold">{{ $money(a.current_book_value) }}</td>
              <td :data-label="$t('Residual Value')" class="num dr-mute">{{ $money(a.residual_value) }}</td>
            </tr>
            <tr v-if="!assets.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="assets.data.length" class="mt-6" :meta="assets.meta" :links="assets.links" />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Pagination, PageHeader },
  props: { assets: Object },
  computed: {
    printUrl() {
      return this.route('assets.depreciation-report.print') + window.location.search;
    },
    pdfUrl() {
      return this.route('assets.depreciation-report.pdf') + window.location.search;
    },
  },
};
</script>

<style scoped>
.dr-link { color: #4338ca; }
.dr-link:hover { text-decoration: underline; }
.dr-mute { color: var(--lg-mute); }
html.dark .dr-link { color: #a5b4fc; }
</style>
