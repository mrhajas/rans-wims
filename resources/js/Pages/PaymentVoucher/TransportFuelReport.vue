<template>
  <admin-layout :title="$t('Transport / Fuel Report')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="truck" :title="$t('Transport / Fuel Report')" :subtitle="$t('All Transport / Fuel payment vouchers, filterable by date, project, and asset.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a :href="pdfUrl" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
        </a>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card tf-filters">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="filter" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Filters') }}</div>
            <div class="lg-card-sub">{{ $t('Filter by date, project and asset.') }}</div>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" @click="reset">
            <icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}
          </button>
        </div>
        <div class="lg-fields">
          <div><date-picker class="w-full" :label="$t('Date From')" v-model="form.date_from" /></div>
          <div><date-picker class="w-full" :label="$t('Date To')" v-model="form.date_to" /></div>
          <div><auto-complete id="asset_id" :label="$t('Asset')" v-model="form.asset_id" class="w-full" :suggestions="assetOptions" clearable /></div>
          <div><auto-complete id="warehouse_id" :label="$t('Project')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseOptions" clearable /></div>
        </div>
      </section>

      <div class="mb-3">
        <span class="lg-subtext">{{ vouchers.meta ? vouchers.meta.total : 0 }} {{ $t('result(s)') }}</span>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('PV Number') }}</th>
              <th>{{ $t('Date') }}</th>
              <th>{{ $t('Payee') }}</th>
              <th>{{ $t('Asset') }}</th>
              <th>{{ $t('Project') }}</th>
              <th class="num">{{ $t('Amount') }}</th>
              <th>{{ $t('Status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in vouchers.data" :key="v.id" class="is-click" @click="goto(v.id)">
              <td :data-label="$t('PV Number')" class="lg-main">{{ v.pv_number }}</td>
              <td :data-label="$t('Date')">{{ $date(v.voucher_date) }}</td>
              <td :data-label="$t('Payee')">{{ v.payee_name }}</td>
              <td :data-label="$t('Asset')">{{ v.asset_name || '-' }}</td>
              <td :data-label="$t('Project')">{{ v.warehouse_name }}</td>
              <td :data-label="$t('Amount')" class="num font-semibold">{{ $money(v.total_amount) }}</td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusChip(v.status)" style="text-transform: none">{{ v.status }}</span>
              </td>
            </tr>
            <tr v-if="vouchers.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="vouchers.data.length" class="mt-6" :meta="vouchers.meta" :links="vouchers.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import Pagination from '@/Shared/Pagination.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, AutoComplete, DatePicker, Pagination },

  props: {
    filters: Object,
    vouchers: Object,
    assets: Array,
    warehouses: Array,
    totalAmount: [Number, String],
  },

  data() {
    return {
      form: {
        date_from: this.filters.date_from || '',
        date_to: this.filters.date_to || '',
        asset_id: this.filters.asset_id || null,
        warehouse_id: this.filters.warehouse_id || null,
      },
    };
  },

  computed: {
    statTiles() {
      return [
        { icon: 'table', tone: 'teal', label: this.$t('Total Vouchers'), value: this.vouchers.meta ? this.vouchers.meta.total : 0 },
        { icon: 'chart-bar', tone: 'slate', label: this.$t('Total Amount'), value: this.$money(this.totalAmount) },
      ];
    },
    assetOptions() {
      return this.assets.map(a => ({ label: `${a.asset_number} / ${a.name}`, value: a.id }));
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ label: w.name, value: w.id }));
    },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    printUrl() {
      return this.route('payment-vouchers.print-list') + this.queryString + (this.queryString ? '&' : '?') + 'type=' + encodeURIComponent('Transport / Fuel');
    },
    pdfUrl() {
      return this.route('payment-vouchers.pdf-list') + this.queryString + (this.queryString ? '&' : '?') + 'type=' + encodeURIComponent('Transport / Fuel');
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('payment-vouchers.reports.transport-fuel', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.date_from = '';
      this.form.date_to = '';
    },
    statusChip(status) {
      return (
        {
          Draft: 'lg-chip-slate',
          Submitted: 'lg-chip-amber',
          Approved: 'lg-chip-blue',
          Authorised: 'lg-chip-green',
          Rejected: 'lg-chip-red',
          Cancelled: 'lg-chip-red',
        }[status] || 'lg-chip-slate'
      );
    },
    goto(id) {
      this.$inertia.visit(this.route('payment-vouchers.show', id));
    },
  },
};
</script>

<style scoped>
.tf-filters { position: relative; z-index: 20; }
</style>
