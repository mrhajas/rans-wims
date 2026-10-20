<template>
  <admin-layout :title="$t('Payment Voucher Batches')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="folder" :title="$t('Batches')" :subtitle="$t('Groups of payment vouchers created together. Each voucher still has its own number, approval, and print history.')">
        <Link :href="route('payment-vouchers.index')" class="lg-btn lg-btn-soft">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Payment Vouchers') }}
        </Link>
        <Link v-if="$can('create-payment-vouchers')" :href="route('payment-vouchers.batches.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Bulk Vouchers') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card pvb-filters">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="filter" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Filters') }}</div>
            <div class="lg-card-sub">{{ $t('Search batch number or reference…') }}</div>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" @click="reset">
            <icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}
          </button>
        </div>
        <div class="lg-fields">
          <div><text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Search batch number or reference…')" /></div>
          <div><auto-complete id="type" :label="$t('Type')" v-model="form.type" class="w-full" :suggestions="typeOptions" clearable /></div>
          <div><auto-complete id="warehouse" :label="$t('Project')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseSuggestions" clearable /></div>
          <div><date-picker class="w-full" :label="$t('Date From')" v-model="form.date_from" /></div>
          <div><date-picker class="w-full" :label="$t('Date To')" v-model="form.date_to" /></div>
        </div>
      </section>

      <div class="flex items-center justify-between gap-3 flex-wrap mb-3 print:hidden">
        <span class="lg-subtext">{{ batches.meta ? batches.meta.total : 0 }} {{ $t('result(s)') }}</span>
        <div class="flex items-center gap-2 flex-wrap">
          <a :href="route('payment-vouchers.batches.print-list') + queryString" target="_blank" class="lg-btn lg-btn-soft">
            <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
          </a>
          <a :href="route('payment-vouchers.batches.pdf-list') + queryString" class="lg-btn lg-btn-soft">
            <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
          </a>
        </div>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th class="pvb-no">{{ $t('No') }}</th>
              <th>{{ $t('Batch Number') }}</th>
              <th>{{ $t('Type') }}</th>
              <th>{{ $t('Project') }}</th>
              <th>{{ $t('Date') }}</th>
              <th class="num">{{ $t('Vouchers') }}</th>
              <th class="num">{{ $t('Total Amount') }}</th>
              <th>{{ $t('Status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(b, i) in batches.data" :key="b.id" class="is-click" @click="goto(b.id)">
              <td :data-label="$t('No')" class="pvb-no">{{ ((batches.meta && batches.meta.from) || 1) + i }}</td>
              <td :data-label="$t('Batch Number')" class="lg-main"><span class="pvb-numcell">{{ b.batch_number }}<span v-if="b.all_paid" class="pvb-allpaid" :title="$t('Every voucher of this batch is paid')"><icons name="check-double" class="pvb-allpaid__ico" />{{ $t('All Paid') }}</span></span></td>
              <td :data-label="$t('Type')">{{ b.type }}</td>
              <td :data-label="$t('Project')">{{ b.warehouse_name }}</td>
              <td :data-label="$t('Date')">{{ $date(b.voucher_date) }}</td>
              <td :data-label="$t('Vouchers')" class="num">{{ b.voucher_count }}</td>
              <td :data-label="$t('Total Amount')" class="num font-semibold">{{ $money(b.total_amount) }}</td>
              <td :data-label="$t('Status')">
                <span class="pvb-status" :class="'pv-st-' + statusKey(b.status)"><icons :name="statusIcon(b.status)" class="pvb-status__ico" />{{ b.status }}</span>
              </td>
            </tr>
            <tr v-if="batches.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="batches.data.length" class="mt-6" :meta="batches.meta" :links="batches.links" />
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
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import Pagination from '@/Shared/Pagination.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, TextInput, AutoComplete, DatePicker, Pagination },

  props: {
    filters: Object,
    warehouses: Array,
    types: Array,
    batches: Object,
    cards: Object,
  },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        type: this.filters.type || null,
        warehouse_id: this.filters.warehouse_id ? Number(this.filters.warehouse_id) : null,
        date_from: this.filters.date_from || null,
        date_to: this.filters.date_to || null,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.cards) {
        return [];
      }

      return [
        { icon: 'folder', tone: 'pink', label: this.$t('Total Batches'), value: this.cards.total_batches },
        { icon: 'doc-text', tone: 'sky', label: this.$t('Vouchers in Batches'), value: this.cards.total_vouchers },
        { icon: 'chart-bar', tone: 'slate', label: this.$t('Total Value'), value: this.$money(this.cards.total_value) },
      ];
    },
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, name: w.name }));
    },
    typeOptions() {
      return this.types.map(t => ({ label: t, value: t }));
    },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('payment-vouchers.batches.index', Object.keys(query).length ? query : { remember: 'forget' }), {
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
      this.form.search = '';
    },
    goto(id) {
      this.$inertia.visit(route('payment-vouchers.batches.show', id));
    },
    // the same colour and icon per status as on the vouchers
    statusKey(status) { return String(status || 'Draft').toLowerCase(); },
    statusIcon(status) { return { Draft: 'doc-text', Submitted: 'clock', Approved: 'tick', Authorised: 'lock-closed', Paid: 'cash', Rejected: 'x-circle', Cancelled: 'cross', Mixed: 'collection' }[status] || 'doc-text'; },
    statusChip(status) {
      return (
        {
          Draft: 'lg-chip-slate',
          Submitted: 'lg-chip-amber',
          Approved: 'lg-chip-blue',
          Authorised: 'lg-chip-green',
          Rejected: 'lg-chip-red',
          Cancelled: 'lg-chip-red',
          Mixed: 'lg-chip-amber',
        }[status] || 'lg-chip-slate'
      );
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'pv-badge-slate',
        Submitted: 'pv-badge-amber',
        Approved: 'pv-badge-blue',
        Authorised: 'pv-badge-emerald',
        Rejected: 'pv-badge-rose',
        Cancelled: 'pv-badge-rose',
        Mixed: 'pv-badge-amber',
      };
      return map[status] || 'pv-badge-slate';
    },
  },
};
</script>

<style scoped>
.pvb-no { width: 1%; white-space: nowrap; color: var(--lg-mute, #64748b); font-weight: 700; }
.pvb-numcell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.45rem; }
/* all the vouchers of the batch are paid */
.pvb-allpaid { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.18rem 0.55rem 0.18rem 0.3rem; border-radius: 999px; color: #fff; font-size: 0.62rem; font-weight: 800; line-height: 1.2; white-space: nowrap; background: linear-gradient(135deg, #15803d, #14532d); box-shadow: 0 8px 14px -10px rgba(20, 83, 45, 0.8); }
.pvb-allpaid__ico { width: 0.72rem; height: 0.72rem; flex: none; padding: 0.11rem; box-sizing: content-box; border-radius: 50%; background: rgba(255, 255, 255, 0.24); }
.pvb-filters { position: relative; z-index: 20; }
</style>
