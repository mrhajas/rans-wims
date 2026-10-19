<template>
  <admin-layout :title="$t('Workforce Agreements')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Workforce Agreements')" :subtitle="$t('Agreed amounts per project and worker, with live requested / approved / paid / outstanding balances.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="pdfUrl" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
        <Link :href="route('workforce-agreements.by-project')" class="lg-btn lg-btn-soft">
          <icons name="chart-pie" class="w-4 h-4" /> {{ $t('By Project') }}
        </Link>
        <Link v-if="canCreate" :href="route('workforce-agreements.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" /> {{ $t('New Agreement') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div v-if="selectedIds.length" class="lg-note lg-note-blue mb-4 wfa-bulk">
        <icons name="tick" class="w-4 h-4" />
        <span class="font-semibold">{{ selectedIds.length }} {{ $t('selected') }}</span>
        <button type="button" class="lg-btn lg-btn-soft wfa-bulk-clear" @click="selectedIds = []">{{ $t('Clear Selection') }}</button>
      </div>

      <div class="lg-card lg-filters relative z-20 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end" style="padding: 1rem 1.1rem">
        <div><text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Reference or worker…')" /></div>
        <div><auto-complete id="warehouse_id" :label="$t('Project')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseOptions" clearable /></div>
        <div><auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable /></div>
        <div class="flex items-end mb-2">
          <button type="button" class="lg-btn lg-btn-soft w-full" @click="reset">
            <icons name="refresh" class="w-4 h-4" /> {{ $t('Reset') }}
          </button>
        </div>
      </div>

      <div class="flex items-center justify-between gap-4 mb-3 flex-wrap">
        <span class="text-xs text-gray-400">{{ agreements.meta ? agreements.meta.total : 0 }} {{ $t('result(s)') }}</span>
        <div class="wfa-legend">
          <span class="wfa-legend-item"><span class="lg-chip lg-chip-amber wfa-ob"><icons name="info" class="w-3 h-3" /></span> {{ $t('Outstanding includes an Opening Balance') }}</span>
        </div>
      </div>

      <div class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-10">
                  <input type="checkbox" class="wfa-checkbox" :checked="allVisibleSelected" @change="toggleSelectAll" />
                </th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Labour Personnel') }}</th>
                <th>{{ $t('Project') }}</th>
                <th>{{ $t('Scope of Work') }}</th>
                <th>{{ $t('Agreed Date') }}</th>
                <th class="num">{{ $t('Agreed') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th class="num">{{ $t('Outstanding') }}</th>
                <th>{{ $t('Payment') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in agreements.data" :key="a.id" class="is-click" @click="$inertia.visit(route('workforce-agreements.show', a.id))">
                <td @click.stop>
                  <input type="checkbox" class="wfa-checkbox" :checked="selectedIds.includes(a.id)" @change="toggleSelected(a.id)" />
                </td>
                <td :data-label="$t('Reference')"><span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ a.reference }}</span></td>
                <td :data-label="$t('Labour Personnel')">
                  <div class="lg-person"><span class="lg-avatar">{{ initials(a.payee_name) }}</span><span class="lg-main">{{ a.payee_name }}</span></div>
                </td>
                <td :data-label="$t('Project')">{{ a.warehouse_name || '—' }}</td>
                <td class="wfa-col-scope" :data-label="$t('Scope of Work')">{{ a.scope_of_work || '—' }}</td>
                <td :data-label="$t('Agreed Date')">{{ a.agreement_date ? $date(a.agreement_date) : '—' }}</td>
                <td class="num lg-main" :data-label="$t('Agreed')">{{ money(a.agreed_amount) }}</td>
                <td class="num wfa-col-paid" :data-label="$t('Paid')">{{ money(a.total_paid) }}</td>
                <td class="num wfa-col-outstanding" :data-label="$t('Outstanding')">
                  {{ money(a.outstanding_balance) }}
                  <span v-if="a.opening_balance > 0" class="lg-chip lg-chip-amber lg-tip wfa-ob" :data-tip="openingBalanceTooltip(a)" tabindex="0" @click.stop>
                    <icons name="info" class="w-3 h-3" />
                  </span>
                </td>
                <td :data-label="$t('Payment')"><span class="lg-chip" :class="payChip(a.payment_status)">{{ a.payment_status }}</span></td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(a.status)">{{ a.status }}</span></td>
              </tr>
              <tr v-if="!agreements.data.length" class="lg-empty-row">
                <td class="lg-empty" colspan="11">{{ $t('No agreements match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <pagination class="mt-4" :meta="agreements.meta" :links="agreements.links" />
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Pagination, AutoComplete, TextInput, PageHeader, StatTiles },

  props: {
    agreements: Object,
    filters: Object,
    statuses: Array,
    warehouses: Array,
    canCreate: Boolean,
    cards: Object,
  },

  data() {
    return {
      form: {
        search: this.filters?.search || '',
        warehouse_id: this.filters?.warehouse_id || '',
        status: this.filters?.status || '',
      },
      selectedIds: [],
    };
  },

  computed: {
    statTiles() {
      const sel = this.selectedIds.length > 0;
      const t = this.selectedTotals;
      const c = this.cards || {};
      return [
        { icon: 'doc-text', tone: 'navy', label: sel ? this.$t('Agreed (Selected)') : this.$t('Total Agreed'), value: this.money(sel ? t.agreed : c.total_agreed) },
        { icon: 'tick', tone: 'emerald', label: sel ? this.$t('Paid (Selected)') : this.$t('Total Paid'), value: this.money(sel ? t.paid : c.total_paid) },
        { icon: 'clock', tone: 'amber', label: sel ? this.$t('Outstanding (Selected)') + ': ' + this.selectedIds.length : this.$t('Total Outstanding'), value: this.money(sel ? t.outstanding : c.total_outstanding) },
      ];
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ id: s, label: s }));
    },
    selectedTotals() {
      const rows = this.agreements.data.filter(a => this.selectedIds.includes(a.id));
      return {
        agreed: rows.reduce((sum, a) => sum + Number(a.agreed_amount || 0), 0),
        paid: rows.reduce((sum, a) => sum + Number(a.total_paid || 0), 0),
        outstanding: rows.reduce((sum, a) => sum + Number(a.outstanding_balance || 0), 0),
      };
    },
    allVisibleSelected() {
      return this.agreements.data.length > 0 && this.agreements.data.every(a => this.selectedIds.includes(a.id));
    },
    printUrl() {
      return this.route('workforce-agreements.print', this.reportQuery);
    },
    pdfUrl() {
      return this.route('workforce-agreements.pdf', this.reportQuery);
    },
    // When rows are checked, the report is restricted to exactly those —
    // the normal search/warehouse/status filters are dropped in that case
    // since "print only what I checked" should win outright, not be
    // narrowed further by whatever's still sitting in the filter bar.
    reportQuery() {
      return this.selectedIds.length ? { ids: this.selectedIds.join(',') } : this.form;
    },
  },

  watch: {
    'form.search': debounce(function () { this.applyFilters(); }, 400),
    'form.warehouse_id'() { this.applyFilters(); },
    'form.status'() { this.applyFilters(); },
  },

  methods: {
    money(v) {
      return 'TZS ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    payChip(s) {
      return { unpaid: 'lg-chip-red', partially_paid: 'lg-chip-amber', fully_paid: 'lg-chip-green' }[this.slug(s)] || 'lg-chip-slate';
    },
    statusChip(s) {
      return { active: 'lg-chip-blue', cancelled: 'lg-chip-slate' }[this.slug(s)] || 'lg-chip-slate';
    },
    initials(name) {
      return (name || '')
        .split(' ')
        .map(p => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
    },
    slug(s) {
      return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
    },
    openingBalanceTooltip(a) {
      return this.$t('Includes an Opening Balance of') + ' ' + this.money(a.opening_balance);
    },
    reset() {
      this.form = { search: '', warehouse_id: '', status: '' };
    },
    applyFilters() {
      this.$inertia.get(this.route('workforce-agreements.index'), this.form, { preserveState: true, replace: true });
    },
    toggleSelected(id) {
      const index = this.selectedIds.indexOf(id);
      if (index === -1) { this.selectedIds.push(id); } else { this.selectedIds.splice(index, 1); }
    },
    toggleSelectAll() {
      const visibleIds = this.agreements.data.map(a => a.id);
      if (this.allVisibleSelected) {
        this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
      } else {
        this.selectedIds = [...new Set([...this.selectedIds, ...visibleIds])];
      }
    },
  },
};
</script>

<style scoped>
.wfa-bulk { align-items: center; justify-content: space-between; flex-wrap: wrap; border-radius: 16px; padding: 0.7rem 1rem; }
.wfa-bulk > svg, .wfa-bulk > div { margin-top: 0; }
.wfa-bulk-clear { padding: 5px 12px; }
.wfa-checkbox { width: 1rem; height: 1rem; border-radius: 0.3rem; border: 1px solid #cbd5e1; accent-color: #1c3f66; cursor: pointer; }
.wfa-ob { padding: 2px 6px; margin-left: 0.3rem; vertical-align: middle; cursor: help; }
.wfa-legend { display: flex; align-items: center; gap: 1rem; }
.wfa-legend-item { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.7rem; font-weight: 600; color: var(--lg-mute); }
.wfa-legend-item .wfa-ob { margin-left: 0; cursor: default; }
.wfa-col-paid { font-weight: 700; color: #065f46; }
.wfa-col-scope { max-width: 220px; white-space: normal !important; overflow-wrap: break-word; }
.wfa-col-outstanding { font-weight: 700; color: #7f1d1d; }
html.dark .wfa-col-paid { color: #34d399; }
html.dark .wfa-col-outstanding { color: #fca5a5; }
@media (max-width: 767.98px) {
  .wfa-col-scope { max-width: none; }
  .lg-table.lg-responsive td.wfa-col-paid, .lg-table.lg-responsive td.wfa-col-outstanding { text-align: right; }
}
</style>
