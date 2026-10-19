<template>
  <admin-layout :title="$t('Finance & Reconciliation Reports')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="chart-pie"
        :title="$t('Finance & Reconciliation Reports')"
        :subtitle="$t('Payable aging by supplier and invoice-to-payment reconciliation, in one filterable view.')"
      >
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Records') }}
        </Link>
      </page-header>

      <section class="lg-card rp-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Warehouse') }} / {{ $t('Supplier') }} / {{ $t('Date From') }} / {{ $t('Date To') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset Filters') }}</button>
        </header>
        <div class="lg-fields">
          <auto-complete
            id="warehouse"
            position="left"
            :label="$t('Warehouse')"
            v-model="form.warehouse_id"
            class="w-full"
            :suggestions="warehouseSuggestions"
            :clearable="true"
            :placeholder="$t('All Warehouses')"
          />
          <auto-complete
            id="supplier"
            position="left"
            :label="$t('Supplier')"
            v-model="form.supplier_id"
            class="w-full"
            :suggestions="supplierSuggestions"
            :clearable="true"
            :placeholder="$t('All Suppliers')"
          />
          <div>
            <label class="fx-label">{{ $t('Date From') }}</label>
            <date-picker id="date_from" v-model="form.date_from" class="w-full" />
          </div>
          <div>
            <label class="fx-label">{{ $t('Date To') }}</label>
            <date-picker id="date_to" v-model="form.date_to" class="w-full" />
          </div>
        </div>
      </section>

      <stat-tiles :items="statTiles" />

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Payable Aging by Supplier') }}</h2>
          </div>
          <span class="lg-chip lg-chip-amber" style="text-transform: none">{{ aging.length }} {{ $t('Supplier') }}</span>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Supplier') }}</th>
                <th class="num">{{ $t('Current') }}</th>
                <th class="num">{{ $t('1-30 Days') }}</th>
                <th class="num">{{ $t('31-60 Days') }}</th>
                <th class="num">{{ $t('61-90 Days') }}</th>
                <th class="num">{{ $t('90+ Days') }}</th>
                <th class="num">{{ $t('Total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in aging" :key="row.supplier_id">
                <td :data-label="$t('Supplier')">
                  <div class="lg-main">
                    <Link :href="route('supplier-records.suppliers.show', row.supplier_id)" class="rp-link">{{ row.supplier_name }}</Link>
                  </div>
                </td>
                <td :data-label="$t('Current')" class="num">{{ $money(row.current) }}</td>
                <td :data-label="$t('1-30 Days')" class="num">{{ $money(row.d1_30) }}</td>
                <td :data-label="$t('31-60 Days')" class="num">{{ $money(row.d31_60) }}</td>
                <td :data-label="$t('61-90 Days')" class="num" :class="{ 'rp-warn': row.d61_90 > 0 }">{{ $money(row.d61_90) }}</td>
                <td :data-label="$t('90+ Days')" class="num" :class="{ 'rp-bad': row.d90_plus > 0 }">{{ $money(row.d90_plus) }}</td>
                <td :data-label="$t('Total')" class="num rp-strong">{{ $money(row.total) }}</td>
              </tr>
              <tr v-if="!aging.length" class="lg-empty-row">
                <td class="lg-empty" colspan="7">{{ $t('No data for the selected filters.') }}</td>
              </tr>
              <tr v-if="aging.length > 1" class="rp-foot">
                <td :data-label="$t('Total')">{{ $t('Total') }}</td>
                <td :data-label="$t('Current')" class="num">{{ $money(agingTotals.current) }}</td>
                <td :data-label="$t('1-30 Days')" class="num">{{ $money(agingTotals.d1_30) }}</td>
                <td :data-label="$t('31-60 Days')" class="num">{{ $money(agingTotals.d31_60) }}</td>
                <td :data-label="$t('61-90 Days')" class="num">{{ $money(agingTotals.d61_90) }}</td>
                <td :data-label="$t('90+ Days')" class="num">{{ $money(agingTotals.d90_plus) }}</td>
                <td :data-label="$t('Total')" class="num">{{ $money(agingTotals.total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="calculator" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Invoice Reconciliation') }}</h2>
          </div>
          <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ reconciliation.length }} {{ $t('Invoice') }}</span>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Invoice') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('Invoice Date') }}</th>
                <th class="num">{{ $t('Total') }}</th>
                <th class="num">{{ $t('Amount Paid') }}</th>
                <th class="num">{{ $t('Allocated From Payments') }}</th>
                <th class="num">{{ $t('Variance') }}</th>
                <th>{{ $t('Reconciled') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in reconciliation" :key="row.id">
                <td :data-label="$t('Invoice')">
                  <div class="lg-main">
                    <Link :href="route('supplier-records.supplier-invoices.show', row.id)" class="rp-link">{{ row.reference }}</Link>
                  </div>
                </td>
                <td :data-label="$t('Supplier')">
                  <Link :href="route('supplier-records.suppliers.show', row.supplier_id)" class="rp-link">{{ row.supplier_name }}</Link>
                </td>
                <td :data-label="$t('Invoice Date')">{{ $date(row.invoice_date) }}</td>
                <td :data-label="$t('Total')" class="num">{{ $money(row.total) }}</td>
                <td :data-label="$t('Amount Paid')" class="num">{{ $money(row.amount_paid) }}</td>
                <td :data-label="$t('Allocated From Payments')" class="num">{{ $money(row.allocated_from_payments) }}</td>
                <td :data-label="$t('Variance')" class="num rp-strong" :class="{ 'rp-bad': !row.reconciled }">{{ $money(row.variance) }}</td>
                <td :data-label="$t('Reconciled')">
                  <span v-if="row.reconciled" class="lg-chip lg-chip-green" style="text-transform: none">
                    <icons name="tick" class="w-3 h-3" /> {{ $t('Reconciled') }}
                  </span>
                  <span v-else class="lg-chip lg-chip-red" style="text-transform: none">
                    <icons name="exclamation" class="w-3 h-3" /> {{ $t('Variance') }} ({{ $money(row.variance) }})
                  </span>
                </td>
              </tr>
              <tr v-if="!reconciliation.length" class="lg-empty-row">
                <td class="lg-empty" colspan="8">{{ $t('No data for the selected filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, DatePicker, PageHeader, StatTiles },

  props: {
    filters: Object,
    warehouses: Array,
    suppliers: Array,
    summary: Object,
    aging: Array,
    reconciliation: Array,
  },

  data() {
    return {
      form: {
        warehouse_id: this.filters.warehouse_id || null,
        supplier_id: this.filters.supplier_id || null,
        date_from: this.filters.date_from || null,
        date_to: this.filters.date_to || null,
      },
    };
  },

  computed: {
    statTiles() {
      const s = this.summary || {};

      return [
        { icon: 'doc-text', tone: 'slate', label: this.$t('Total Invoiced'), value: this.$money(s.total_invoiced) },
        { icon: 'tick', tone: 'emerald', label: this.$t('Total Paid'), value: this.$money(s.total_paid) },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Total Outstanding'), value: this.$money(s.total_outstanding) },
        { icon: 'collection', tone: 'sky', label: this.$t('Invoice Count'), value: s.invoice_count },
        { icon: 'key', tone: 'violet', label: this.$t('Payment Count'), value: s.payment_count },
      ];
    },
    agingTotals() {
      const totals = {};
      ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'].forEach(k => {
        totals[k] = (this.aging || []).reduce((sum, row) => sum + (parseFloat(row[k]) || 0), 0);
      });

      return totals;
    },
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, name: w.name }));
    },
    supplierSuggestions() {
      return this.suppliers.map(s => ({ id: s.id, name: s.name }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.visit(this.route('supplier-records.reports', pickBy(this.form)), {
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
    },
  },
};
</script>

<style scoped>
.fx-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.fx-form :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.rp-filters {
  position: relative;
  z-index: 20;
}
.rp-link {
  color: inherit;
}
.rp-link:hover {
  color: var(--lg-navy);
  text-decoration: underline;
}
.rp-strong {
  font-weight: 800;
}
.rp-warn {
  color: #d97706;
  font-weight: 700;
}
.rp-bad {
  color: #dc2626;
  font-weight: 700;
}
.rp-foot td {
  font-weight: 800;
  background: var(--lg-soft);
}
html.dark .rp-link:hover {
  color: #93c5fd;
}
</style>
