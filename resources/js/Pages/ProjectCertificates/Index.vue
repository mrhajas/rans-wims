<template>
  <admin-layout :title="$t('Project Certificates Payments')">
    <div class="lg-page px-4 md:px-0 pc-page">
      <page-header icon="doc-text" :title="$t('Project Certificates')" :subtitle="$t('Certification and payment status for every certificate across every project.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="downloadUrl" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
      </page-header>

      <section class="lg-stats lg-stats-tight print:hidden">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.total_unpaid)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Unpaid') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.total_paid)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Paid') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.total_overdue)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Overdue') }}</div>
          </div>
        </div>
      </section>

      <section class="lg-card lg-filters">
        <div class="lg-fields pc-filters">
          <div>
            <auto-complete
              v-model="form.warehouse_id"
              :suggestions="warehouses.map(w => ({ id: w.id, label: w.name }))"
              :placeholder="$t('All Projects')"
              clearable
            />
          </div>
          <div>
            <auto-complete
              v-model="form.status"
              :suggestions="statuses.map(s => ({ id: s, label: capitalize(s) }))"
              :placeholder="$t('All Statuses')"
              clearable
            />
          </div>
          <div class="pc-search-wrap lg-span-all">
            <icons name="search" class="pc-search-icon" />
            <input v-model="form.search" type="text" class="pc-search-input" :placeholder="$t('Search reference, certificate number, or project…')" />
          </div>
        </div>
      </section>

      <div v-if="selectedIds.length" class="lg-note lg-note-blue pc-bulk-bar">
        <div class="pc-bulk-info">
          <span class="pc-bulk-count">{{ selectedIds.length }} {{ $t('selected') }} · {{ $t('Total') }}:</span>
          <span v-for="(v, currency) in selectedTotals" :key="currency" class="pc-selected-total">{{ money(v, currency) }}</span>
        </div>
        <button type="button" class="lg-btn lg-btn-soft" @click="selectedIds = []">{{ $t('Clear Selection') }}</button>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th class="w-10">
                <input type="checkbox" class="pc-checkbox" :checked="allVisibleSelected" @change="toggleSelectAll" />
              </th>
              <th>{{ $t('Project') }}</th>
              <th>{{ $t('Certificate') }}</th>
              <th class="num">{{ $t('Net Payment') }}</th>
              <th class="num">{{ $t('Paid') }}</th>
              <th class="num">{{ $t('Outstanding') }}</th>
              <th>{{ $t('Status') }}</th>
              <th>{{ $t('Payment') }}</th>
              <th>{{ $t('Date Issued') }}</th>
              <th>{{ $t('Issued By') }}</th>
              <th>{{ $t('Approved By') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in certificates.data" :key="c.id" class="is-click" @click="$inertia.visit(route('project-certificates.show', c.id))">
              <td :data-label="$t('Select')" @click.stop>
                <input type="checkbox" class="pc-checkbox" :checked="selectedIds.includes(c.id)" @change="toggleSelected(c.id)" />
              </td>
              <td :data-label="$t('Project')">{{ c.warehouse_name }}</td>
              <td :data-label="$t('Certificate')"><span class="lg-main">{{ c.reference || ('#' + c.certificate_number) }}<span v-if="c.version_letter" class="pc-version-suffix">-{{ c.version_letter }}</span></span></td>
              <td class="num" :class="{ 'pc-muted': c.status !== 'issued' }" :data-label="$t('Net Payment')">
                {{ c.status === 'issued' ? money(c.net_payment_amount, c.currency) : $t('Not yet issued') }}
              </td>
              <td class="num" :data-label="$t('Paid')">{{ money(c.total_paid, c.currency) }}</td>
              <td class="num" :data-label="$t('Outstanding')">{{ money(c.outstanding, c.currency) }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip pc-cap" :class="statusChip(c.status)">{{ c.status }}</span></td>
              <td :data-label="$t('Payment')"><span class="lg-chip" :class="payChip(c.payment_status)">{{ paymentLabel(c.payment_status) }}</span></td>
              <td :data-label="$t('Date Issued')"><span class="lg-subtext">{{ c.issued_at || '—' }}</span></td>
              <td :data-label="$t('Issued By')"><span class="lg-subtext">{{ c.issued_by_name || '—' }}</span></td>
              <td :data-label="$t('Approved By')"><span class="lg-subtext">{{ c.approved_by_name || '—' }}</span></td>
            </tr>
            <tr v-if="!certificates.data.length" class="lg-empty-row">
              <td class="lg-empty" colspan="11">{{ $t('No certificates match these filters.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-4" :meta="certificates.meta" :links="certificates.links" />
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Pagination, AutoComplete, PageHeader },

  props: {
    certificates: Object,
    filters: Object,
    stats: { type: Object, default: () => ({ total_unpaid: {}, total_paid: {}, total_overdue: {} }) },
    warehouses: Array,
    statuses: Array,
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
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });

      return out;
    },
    printUrl() {
      return this.route('project-certificates.print', this.activeFilters);
    },
    downloadUrl() {
      return this.route('project-certificates.download', this.activeFilters);
    },
    allVisibleSelected() {
      return this.certificates.data.length > 0 && this.certificates.data.every(c => this.selectedIds.includes(c.id));
    },
    // Selection can span multiple currencies (rare, but possible across
    // projects) — group the sum by currency rather than silently adding
    // unlike units together.
    selectedTotals() {
      const totals = {};
      this.certificates.data.filter(c => this.selectedIds.includes(c.id)).forEach(c => {
        totals[c.currency] = (totals[c.currency] || 0) + Number(c.net_payment_amount || 0);
      });

      return totals;
    },
  },

  watch: {
    'form.search': debounce(function () { this.applyFilters(); }, 400),
    'form.warehouse_id'() { this.applyFilters(); },
    'form.status'() { this.applyFilters(); },
  },

  methods: {
    money(v, currency) {
      return (currency || 'TZS') + '\u00A0' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    moneyMulti(totalsByCurrency) {
      const entries = Object.entries(totalsByCurrency || {});
      if (!entries.length) return this.money(0, 'TZS');

      return entries.map(([currency, v]) => this.money(v, currency)).join(' + ');
    },
    moneyMultiLines(totalsByCurrency) {
      const entries = Object.entries(totalsByCurrency || {});
      if (!entries.length) return [this.money(0, 'TZS')];

      return entries.map(([currency, v], i) => this.money(v, currency) + (i < entries.length - 1 ? ' +' : ''));
    },
    capitalize(s) {
      return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
    },
    paymentLabel(status) {
      return { unpaid: 'Unpaid', partially_paid: 'Partially Paid', paid_in_full: 'Paid in Full', overdue: 'Overdue', disputed: 'Disputed', on_hold: 'On Hold', cancelled: 'Cancelled' }[status] || status;
    },
    statusChip(status) {
      return { draft: 'lg-chip-slate', submitted: 'lg-chip-indigo', approved: 'lg-chip-blue', issued: 'lg-chip-green', revised: 'lg-chip-amber', cancelled: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    payChip(status) {
      return { unpaid: 'lg-chip-red', partially_paid: 'lg-chip-amber', paid_in_full: 'lg-chip-green', overdue: 'pc-chip-overdue', disputed: 'lg-chip-violet', on_hold: 'lg-chip-slate', cancelled: 'lg-chip-slate' }[status] || 'lg-chip-slate';
    },
    toggleSelected(id) {
      const index = this.selectedIds.indexOf(id);
      if (index === -1) this.selectedIds.push(id); else this.selectedIds.splice(index, 1);
    },
    toggleSelectAll() {
      const visibleIds = this.certificates.data.map(c => c.id);
      if (this.allVisibleSelected) {
        this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
      } else {
        this.selectedIds = [...new Set([...this.selectedIds, ...visibleIds])];
      }
    },
    applyFilters() {
      this.$inertia.get(this.route('project-certificates.index'), this.form, { preserveState: true, replace: true });
    },
  },
};
</script>

<style scoped>
.pc-filters { grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); align-items: center; }
.pc-search-wrap { display: flex; align-items: center; gap: 8px; border: 1px solid var(--lg-line); border-radius: 0.75rem; padding: 0 0.85rem; background: var(--lg-card); }
.pc-search-wrap:focus-within { border-color: #1c3f66; box-shadow: 0 0 0 3px rgba(28, 63, 102, 0.12); }
.pc-search-icon { width: 14px; height: 14px; color: var(--lg-faint); flex: none; }
.pc-search-input { flex: 1; min-width: 0; border: none !important; outline: none !important; box-shadow: none !important; background: transparent !important; padding: 0.65rem 0; font-size: 0.82rem; color: var(--lg-ink); }
.pc-stat-lines { font-size: 1rem; font-weight: 800; line-height: 1.25; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.pc-bulk-bar { align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 1rem; padding: 0.75rem 1rem; }
.pc-bulk-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pc-bulk-count { font-size: 0.82rem; font-weight: 700; }
.pc-selected-total { font-size: 1.1rem; font-weight: 800; color: #be123c; }
html.dark .pc-selected-total { color: #fda4af; }
.pc-checkbox { width: 1rem; height: 1rem; border-radius: 0.3rem; border: 1px solid #cbd5e1; accent-color: #1c3f66; cursor: pointer; }
.pc-version-suffix { color: var(--lg-faint); font-weight: 600; }
.pc-muted { color: var(--lg-faint) !important; }
.pc-cap { text-transform: capitalize; }
.pc-chip-overdue { background: #450a0a; color: #fecaca; }
</style>
