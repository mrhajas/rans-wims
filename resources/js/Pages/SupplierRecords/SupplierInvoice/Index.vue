<template>
  <admin-layout :title="$t('Supplier Invoices')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="doc-text"
        :title="$t('Supplier Invoices')"
        :subtitle="$t('Invoices raised by suppliers against purchase orders, matched to deliveries.')"
      >
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Records') }}
        </Link>
        <Link :href="route('supplier-records.supplier-invoices.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Record Invoice') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <section class="lg-card sr-filters print:hidden">
        <div class="lg-fields">
          <auto-complete id="supplier" :label="$t('Supplier')" v-model="form.supplier_id" class="w-full" :suggestions="supplierOptions" clearable />
          <auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable />
          <div class="sr-filters-reset">
            <button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </div>
      </section>

      <section class="lg-card sr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Invoice #') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('Invoice Date') }}</th>
                <th>{{ $t('Due Date') }}</th>
                <th class="num">{{ $t('Total') }}</th>
                <th class="num">{{ $t('Balance') }}</th>
                <th>{{ $t('Entered By') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="invoice in invoices.data"
                :key="invoice.id"
                class="is-click"
                :class="{ 'sr-overdue': isOverdue(invoice) }"
                @click="goto(invoice.id)"
              >
                <td :data-label="$t('Invoice #')">
                  <div class="lg-main">{{ invoice.invoice_number }}</div>
                </td>
                <td :data-label="$t('Supplier')">{{ invoice.supplier_name }}</td>
                <td :data-label="$t('Invoice Date')">{{ $date(invoice.invoice_date) }}</td>
                <td :data-label="$t('Due Date')">
                  {{ $date(invoice.due_date) }}
                  <span v-if="isOverdue(invoice)" class="lg-chip lg-chip-red ml-1" style="text-transform: none">{{ $t('Overdue') }}</span>
                </td>
                <td :data-label="$t('Total')" class="num sr-money">{{ $money(invoice.total, invoice.currency) }}</td>
                <td :data-label="$t('Balance')" class="num sr-money" :class="invoice.balance > 0 ? 'sr-balance-due' : 'sr-balance-clear'">
                  {{ $money(invoice.balance, invoice.currency) }}
                </td>
                <td :data-label="$t('Entered By')">{{ invoice.created_by_name || '-' }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusChip(invoice.status)" style="text-transform: none">
                    <icons v-if="invoice.status === 'Paid'" name="tick" class="w-3 h-3" />
                    {{ invoice.status }}
                  </span>
                  <span class="sr-match" :class="matchStatusClass(invoice.match_status)">{{ invoice.match_status }}</span>
                </td>
              </tr>
              <tr v-if="invoices.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="invoices.data.length" class="mt-6" :meta="invoices.meta" :links="invoices.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, Pagination, PageHeader, StatTiles },

  props: {
    filters: Object,
    statuses: Array,
    suppliers: Array,
    invoices: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        supplier_id: this.filters.supplier_id ? Number(this.filters.supplier_id) : null,
        status: this.filters.status || null,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'doc-text', tone: 'navy', label: this.$t('All Invoices'), value: this.stats.total, status: null, active: !this.form.status },
        { icon: 'cash', tone: 'amber', label: this.$t('Outstanding'), value: this.$money(this.stats.outstanding) },
        { icon: 'clock', tone: 'rose', label: this.$t('Overdue'), value: this.stats.overdue },
        { icon: 'tick', tone: 'emerald', label: this.$t('Paid'), value: this.stats.paid, status: 'Paid', active: this.form.status === 'Paid' },
        { icon: 'exclamation', tone: 'violet', label: this.$t('Disputed'), value: this.stats.disputed, status: 'Disputed', active: this.form.status === 'Disputed' },
      ];
    },
    supplierOptions() {
      return this.suppliers.map(s => ({ label: s.name, value: s.id }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('supplier-records.supplier-invoices.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    pickTile(item) {
      this.form.status = item.status || null;
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    goto(id) {
      this.$inertia.visit(route('supplier-records.supplier-invoices.show', id));
    },
    isOverdue(invoice) {
      return invoice.balance > 0 && new Date(invoice.due_date) < new Date();
    },
    matchStatusClass(status) {
      const map = {
        Matched: 'is-ok',
        'Quantity Variance': 'is-warn',
        'Price Variance': 'is-warn',
        Unmatched: 'is-bad',
      };
      return map[status] || '';
    },
    statusChip(status) {
      const map = {
        'Pending Match': 'lg-chip-amber',
        Matched: 'lg-chip-blue',
        Approved: 'lg-chip-blue',
        'Partially Paid': 'lg-chip-amber',
        Paid: 'lg-chip-green',
        Disputed: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.sr-filters {
  position: relative;
  z-index: 20;
  padding-top: 0.9rem;
  padding-bottom: 1rem;
}
.sr-filters-reset {
  display: flex;
  align-items: flex-end;
}
.sr-overdue td:first-child {
  box-shadow: inset 3px 0 0 #f43f5e;
}
.sr-money {
  font-weight: 700;
}
.sr-balance-due {
  color: #b45309;
}
.sr-balance-clear {
  color: var(--lg-faint);
}
.sr-match {
  margin-left: 0.5rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--lg-faint);
}
.sr-match.is-ok {
  color: #059669;
}
.sr-match.is-warn {
  color: #d97706;
}
.sr-match.is-bad {
  color: #dc2626;
}
html.dark .sr-balance-due {
  color: #fbbf24;
}
@media (max-width: 767.98px) {
  .sr-overdue td:first-child {
    box-shadow: none;
  }
  .sr-overdue {
    border-left: 3px solid #f43f5e !important;
  }
}
</style>
