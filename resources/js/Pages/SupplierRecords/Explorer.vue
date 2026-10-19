<template>
  <admin-layout :title="$t('Transaction Explorer')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="chart-pie"
        :title="$t('Transaction Explorer')"
        :subtitle="$t('Search and filter every supplier transaction, purchase requests through payments, in one place.')"
      >
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Records') }}
        </Link>
      </page-header>

      <section v-if="supplierBalances.length" class="lg-card">
        <header class="lg-card-head flex-wrap">
          <span class="lg-card-ico lg-ico-rose"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('All Supplier Balances') }}</h2>
            <p class="lg-card-sub">{{ $t('Tick suppliers to total just their balances. Everyone is selected by default.') }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <div v-for="t in selectedTotals" :key="t.currency" class="spr-balance-total">
              <span class="spr-balance-total-label">{{ $t('Selected Total') }}<span v-if="t.currency"> ({{ t.currency }})</span></span>
              <span class="spr-balance-total-value" :class="t.total > 0 ? 'text-maroon' : t.total < 0 ? 'text-emerald-700' : 'spr-zero'">{{ $money(t.total, t.currency) }}</span>
            </div>
            <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ selectedSupplierIds.length }} / {{ supplierBalances.length }} {{ $t('selected') }}</span>
            <a :href="balancesPrintUrl" target="_blank" class="lg-btn lg-btn-primary">
              <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
            </a>
          </div>
        </header>

        <div class="spr-search">
          <icons name="search" class="w-3.5 h-3.5 spr-balance-search-icon" />
          <input v-model="balanceSearch" type="text" class="spr-input spr-balance-search" :placeholder="$t('Search suppliers…')" />
        </div>

        <div class="mb-3">
          <label class="spr-balance-row spr-balance-row-all">
            <input type="checkbox" class="spr-balance-checkbox" :checked="allSelected" :indeterminate.prop="someSelected && !allSelected" @change="toggleSelectAll" />
            <icons name="users" class="w-3.5 h-3.5 spr-icon-mute" />
            <span class="spr-all-label">{{ allSelected ? $t('Deselect All') : $t('Select All') }}</span>
            <span v-if="balanceSearch" class="spr-faint">({{ $t('visible only') }})</span>
          </label>
        </div>

        <div class="spr-balance-grid">
          <label v-for="s in filteredSupplierBalances" :key="s.id" class="spr-balance-row">
            <input type="checkbox" class="spr-balance-checkbox" :value="s.id" v-model="selectedSupplierIds" />
            <Link
              :href="route('supplier-records.suppliers.show', s.id)"
              class="spr-balance-icon-link"
              @click.stop
              @mouseenter="showBalanceTooltip($event, s)"
              @mouseleave="hideBalanceTooltip"
            >
              <icons name="users" class="w-3.5 h-3.5" />
            </Link>
            <span class="spr-balance-name">{{ s.name }}</span>
            <span class="spr-balance-value" :class="s.balance > 0 ? 'text-maroon' : s.balance < 0 ? 'text-emerald-700' : 'spr-faint'">{{ $money(s.balance, s.currency) }}</span>
          </label>
          <p v-if="!filteredSupplierBalances.length" class="spr-faint py-4 text-center col-span-full text-xs">{{ $t('No suppliers match your search.') }}</p>
        </div>
      </section>

      <teleport to="body">
        <div class="spr-fixed-tooltip" :class="{ 'spr-fixed-tooltip-show': balanceTooltip.show }" :style="{ left: balanceTooltip.x + 'px', top: balanceTooltip.y + 'px' }">
          {{ balanceTooltip.text }}
        </div>
      </teleport>

      <section class="lg-card spr-filters">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset Filters') }}</button>
        </header>
        <div class="lg-fields">
          <div>
            <label class="spr-field-label block mb-1">{{ $t('Search') }}</label>
            <input v-model="form.search" type="text" class="spr-input" :placeholder="$t('Reference…')" />
          </div>
          <div>
            <label class="spr-field-label block mb-1">{{ $t('Type') }}</label>
            <auto-complete id="type" position="left" v-model="form.type" class="w-full" :suggestions="typeOptions" :clearable="true" :placeholder="$t('All Types')" />
          </div>
          <div>
            <label class="spr-field-label block mb-1">{{ $t('Warehouse') }}</label>
            <auto-complete id="warehouse" position="left" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseSuggestions" :clearable="true" :placeholder="$t('All Warehouses')" />
          </div>
          <div>
            <label class="spr-field-label block mb-1">{{ $t('Supplier') }}</label>
            <auto-complete id="supplier" position="left" v-model="form.supplier_id" class="w-full" :suggestions="supplierSuggestions" :clearable="true" :placeholder="$t('All Suppliers')" />
          </div>
          <div>
            <label class="spr-field-label block mb-1">{{ $t('Date From') }}</label>
            <date-picker id="date_from" v-model="form.date_from" class="w-full" />
          </div>
          <div>
            <label class="spr-field-label block mb-1">{{ $t('Date To') }}</label>
            <date-picker id="date_to" v-model="form.date_to" class="w-full" />
          </div>
        </div>
      </section>

      <section v-if="supplierSummary" class="lg-stats">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value is-good">{{ $money(supplierSummary.paid, supplierSummary.currency) }}</div>
            <div class="lg-stat-label">{{ $t('Amount Paid') }} / {{ supplierSummary.supplier_name }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ $money(supplierSummary.credited, supplierSummary.currency) }}</div>
            <div class="lg-stat-label">{{ $t('Amount Credited') }}</div>
            <p class="lg-subtext">{{ $t('Credit notes, discounts, retention, withholding tax') }}</p>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value" :class="{ 'is-bad': supplierSummary.balance > 0 }">{{ $money(supplierSummary.balance, supplierSummary.currency) }}</div>
            <div class="lg-stat-label">{{ $t('Current Balance') }}</div>
          </div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head flex-wrap">
          <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Transaction Explorer') }}</h2>
          </div>
          <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ rows.meta?.total ?? rows.data.length }} {{ $t('records') }}</span>
          <a :href="route('supplier-records.explorer.print') + queryString" target="_blank" class="lg-btn lg-btn-primary">
            <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
          </a>
          <a :href="route('supplier-records.explorer.pdf') + queryString" class="lg-btn lg-btn-dark">
            <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
          </a>
        </header>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('Project') }}</th>
                <th>{{ $t('Date') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows.data" :key="row.type + '-' + row.id" class="is-click" @click="goto(row)">
                <td :data-label="$t('Type')">
                  <span class="lg-chip" :class="typeBadgeClass(row.type)" style="text-transform: none">{{ $t(row.type) }}</span>
                </td>
                <td :data-label="$t('Reference')"><div class="lg-main">{{ row.reference }}</div></td>
                <td :data-label="$t('Supplier')">
                  <Link v-if="row.supplier_id" :href="route('supplier-records.suppliers.show', row.supplier_id)" class="spr-link" @click.stop>{{ row.supplier_name }}</Link>
                  <span v-else>—</span>
                </td>
                <td :data-label="$t('Project')">{{ row.warehouse_name || '—' }}</td>
                <td :data-label="$t('Date')">{{ $date(row.date) }}</td>
                <td :data-label="$t('Amount')" class="num spr-strong">{{ row.amount !== null ? $money(row.amount, row.currency) : '—' }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusBadgeClass(row.status)" style="text-transform: none">{{ row.status }}</span>
                </td>
              </tr>
              <tr v-if="rows.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <pagination v-if="rows.data.length" class="mt-5" :meta="rows.meta" :links="rows.links" />
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
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, AutoComplete, DatePicker, Pagination, PageHeader },

  props: {
    filters: Object,
    types: Array,
    warehouses: Array,
    suppliers: Array,
    rows: Object,
    supplierSummary: Object,
    supplierBalances: { type: Array, default: () => [] },
  },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        type: this.filters.type || null,
        warehouse_id: this.filters.warehouse_id || null,
        supplier_id: this.filters.supplier_id || null,
        date_from: this.filters.date_from || null,
        date_to: this.filters.date_to || null,
      },
      // Everyone selected by default — the top total starts as every
      // supplier's balance, and unticking narrows it down.
      selectedSupplierIds: this.supplierBalances.map(s => s.id),
      balanceSearch: '',
      // Teleported to <body> and positioned via getBoundingClientRect() on
      // hover — a plain CSS ::after tooltip gets silently clipped here
      // since the row it hangs off sits inside .spr-balance-grid's own
      // overflow-y: auto (the 5-line-then-scroll list).
      balanceTooltip: { show: false, text: '', x: 0, y: 0 },
    };
  },

  computed: {
    filteredSupplierBalances() {
      const q = this.balanceSearch.trim().toLowerCase();
      if (!q) {
        return this.supplierBalances;
      }
      return this.supplierBalances.filter(s => s.name.toLowerCase().includes(q));
    },
    // "Select All" acts on whatever is currently visible (search-filtered),
    // matching the same "select all in this view" behavior as a mail inbox
    // — not the full, possibly-hidden supplier list.
    allSelected() {
      return this.filteredSupplierBalances.length > 0
        && this.filteredSupplierBalances.every(s => this.selectedSupplierIds.includes(s.id));
    },
    someSelected() {
      return this.filteredSupplierBalances.some(s => this.selectedSupplierIds.includes(s.id));
    },
    // Always carries ?ids= (even empty) so the backend can tell "nothing
    // ticked" apart from "no filter given at all" and print exactly the
    // current on-screen selection either way.
    balancesPrintUrl() {
      return route('supplier-records.explorer.balances.print') + '?ids=' + this.selectedSupplierIds.join(',');
    },
    // Grouped by currency rather than a single sum — every supplier happens
    // to be TZS today, but a raw sum across differing currencies would be
    // meaningless if that ever changes.
    selectedTotals() {
      const totals = {};
      this.supplierBalances
        .filter(s => this.selectedSupplierIds.includes(s.id))
        .forEach(s => {
          const currency = s.currency || 'TZS';
          totals[currency] = (totals[currency] || 0) + Number(s.balance || 0);
        });
      return Object.keys(totals).map(currency => ({ currency, total: Math.round(totals[currency] * 100) / 100 }));
    },
    typeOptions() {
      return this.types.map(t => ({ label: this.$t(t), value: t }));
    },
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, name: w.name }));
    },
    supplierSuggestions() {
      return this.suppliers.map(s => ({ id: s.id, name: s.name }));
    },
    queryString() {
      const query = {};
      Object.keys(this.form).forEach(k => { if (this.form[k]) query[k] = this.form[k]; });
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('supplier-records.explorer', Object.keys(query).length ? query : { remember: 'forget' }), {
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
    showBalanceTooltip(event, supplier) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.balanceTooltip = {
        show: true,
        text: `${supplier.name}: ${this.$money(supplier.balance, supplier.currency)}. ${this.$t('Click to view profile')}`,
        x: rect.left + rect.width / 2,
        y: rect.bottom + 8,
      };
    },
    hideBalanceTooltip() {
      this.balanceTooltip.show = false;
    },
    toggleSelectAll() {
      const visibleIds = this.filteredSupplierBalances.map(s => s.id);
      if (this.allSelected) {
        this.selectedSupplierIds = this.selectedSupplierIds.filter(id => !visibleIds.includes(id));
      } else {
        this.selectedSupplierIds = Array.from(new Set([...this.selectedSupplierIds, ...visibleIds]));
      }
    },
    goto(row) {
      this.$inertia.visit(route(row.route, row.id));
    },
    typeBadgeClass(type) {
      const map = {
        'Purchase Request': 'lg-chip-slate',
        Quotation: 'lg-chip-violet',
        'Purchase Order': 'lg-chip-blue',
        'Goods Receipt': 'lg-chip-cyan',
        'Supplier Invoice': 'lg-chip-amber',
        'Payment Request': 'lg-chip-red',
        Payment: 'lg-chip-green',
      };
      return map[type] || 'lg-chip-slate';
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate',
        'Pending Approval': 'lg-chip-amber',
        Submitted: 'lg-chip-amber',
        Approved: 'lg-chip-blue',
        'Sent to Supplier': 'lg-chip-blue',
        Acknowledged: 'lg-chip-blue',
        'Partially Delivered': 'lg-chip-amber',
        Completed: 'lg-chip-green',
        'Converted to PO': 'lg-chip-green',
        Sent: 'lg-chip-blue',
        Received: 'lg-chip-amber',
        Selected: 'lg-chip-green',
        Confirmed: 'lg-chip-green',
        'Pending Match': 'lg-chip-amber',
        Matched: 'lg-chip-blue',
        'Partially Paid': 'lg-chip-amber',
        Paid: 'lg-chip-green',
        Recorded: 'lg-chip-green',
        Rejected: 'lg-chip-red',
        Cancelled: 'lg-chip-red',
        Disputed: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
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
.spr-filters { position: relative; z-index: 20; }
.spr-field-label { font-size: 0.72rem; font-weight: 700; color: var(--lg-mute); text-transform: uppercase; letter-spacing: 0.03em; }
.spr-input {
  width: 100%; border: 1px solid var(--lg-line); border-radius: 0.85rem; padding: 0.55rem 0.75rem; font-size: 0.85rem; background: var(--lg-card); color: var(--lg-ink);
}
.spr-input:focus { outline: none; border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.spr-link { color: inherit; }
.spr-link:hover { color: var(--lg-navy); text-decoration: underline; }
.spr-strong { font-weight: 800; }
.spr-faint { color: var(--lg-faint); font-size: 0.75rem; }
.spr-zero { color: var(--lg-mute); }
.spr-icon-mute { color: var(--lg-mute); }
.spr-all-label { font-size: 0.84rem; font-weight: 700; color: var(--lg-ink); }

.text-maroon { color: #7f1d1d; }
.spr-balance-total {
  display: flex; flex-direction: column; align-items: flex-end; padding: 0.4rem 0.9rem; border-radius: 0.9rem;
  background: rgba(127, 29, 29, 0.06); border: 1px solid rgba(127, 29, 29, 0.2);
}
.spr-balance-total-label { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #9d174d; }
.spr-balance-total-value { font-size: 1.05rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.spr-search { position: relative; margin-bottom: 0.75rem; }
.spr-balance-grid {
  display: grid; grid-template-columns: 1fr; gap: 0.4rem; max-height: 12.85rem; overflow-y: auto; padding-right: 0.25rem;
}
@media (min-width: 768px) { .spr-balance-grid { grid-template-columns: 1fr 1fr; } }
@media (min-width: 1280px) { .spr-balance-grid { grid-template-columns: 1fr 1fr 1fr; } }
.spr-balance-row {
  display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.7rem; border-radius: 0.85rem;
  border: 1px solid var(--lg-line); background: var(--lg-soft); cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease;
}
.spr-balance-row:hover { border-color: #93c5fd; }
.spr-balance-row-all { border-style: dashed; background: transparent; display: inline-flex; }
.spr-balance-checkbox { flex: none; width: 1rem; height: 1rem; accent-color: #1c3f66; cursor: pointer; }
.spr-balance-name { flex: 1; min-width: 0; font-size: 0.82rem; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.spr-balance-value { flex: none; font-size: 0.8rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.spr-balance-search-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--lg-faint); }
.spr-balance-search { padding-left: 2.1rem; max-width: 22rem; }
.spr-balance-icon-link {
  position: relative; display: inline-flex; align-items: center; justify-content: center; flex: none;
  width: 1.4rem; height: 1.4rem; border-radius: 0.4rem; color: var(--lg-faint);
  transition: color 0.15s ease, background 0.15s ease, transform 0.15s ease;
}
.spr-balance-icon-link:hover { color: var(--lg-navy); background: rgba(28, 63, 102, 0.12); transform: scale(1.15); }

.spr-fixed-tooltip {
  position: fixed; transform: translate(-50%, 4px) scale(0.92); z-index: 9999;
  background: #0f172a; color: #fff; padding: 6px 10px; border-radius: 10px; font-size: 0.72rem; font-weight: 600;
  white-space: normal; width: max-content; max-width: 15rem; text-align: center; line-height: 1.35;
  opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease;
  box-shadow: 0 10px 22px -8px rgba(0, 0, 0, 0.45);
}
.spr-fixed-tooltip::before {
  content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
  border: 5px solid transparent; border-bottom-color: #0f172a;
}
.spr-fixed-tooltip-show { opacity: 1; transform: translate(-50%, 0) scale(1); }

html.dark .spr-balance-total { background: rgba(248, 113, 113, 0.1); border-color: rgba(248, 113, 113, 0.3); }
html.dark .spr-balance-total-label { color: #f9a8d4; }
html.dark .spr-link:hover, html.dark .spr-balance-icon-link:hover { color: #93c5fd; }
html.dark .text-maroon { color: #f87171; }
</style>
