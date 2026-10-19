<template>
  <admin-layout :title="$t('Unpaid Certificates')">
    <div class="lg-page px-4 md:px-0 pc-page">
      <page-header icon="exclamation" :title="$t('Unpaid Certificates')" :subtitle="$t('Every issued certificate with an outstanding balance, across every project.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="downloadUrl" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
      </page-header>

      <section class="lg-stats print:hidden">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ stats.total_count }}</div>
            <div class="lg-stat-label">{{ $t('Unpaid Certificates') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.total_outstanding)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Unpaid Certificate Balance') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ stats.overdue_count }}</div>
            <div class="lg-stat-label">{{ $t('Overdue Certificates') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-violet"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ stats.unique_projects }}</div>
            <div class="lg-stat-label">{{ $t('Projects Affected') }}</div>
          </div>
        </div>
      </section>

      <section class="lg-card lg-filters">
        <div class="lg-fields pc-filters">
          <div><auto-complete v-model="form.warehouse_id" :suggestions="warehouses.map(w => ({ id: w.id, label: w.name }))" :placeholder="$t('All Projects')" clearable /></div>
          <div><auto-complete v-model="form.client_id" :suggestions="clients.map(c => ({ id: c.id, label: c.name }))" :placeholder="$t('All Clients')" clearable /></div>
          <div><auto-complete v-model="form.currency" :suggestions="currencies.map(c => ({ id: c.code, label: `${c.code} ${c.name}` }))" :placeholder="$t('All Currencies')" clearable /></div>
          <div><auto-complete v-model="form.payment_status" :suggestions="statusOptions" :placeholder="$t('All Statuses')" clearable /></div>
          <div>
            <label class="pc-mini-label">{{ $t('Date From') }}</label>
            <input v-model="form.date_from" type="date" class="pc-input w-full" />
          </div>
          <div>
            <label class="pc-mini-label">{{ $t('Date To') }}</label>
            <input v-model="form.date_to" type="date" class="pc-input w-full" />
          </div>
          <div class="pc-search-wrap lg-span-all">
            <icons name="search" class="pc-search-icon" />
            <input v-model="form.search" type="text" class="pc-search-input" :placeholder="$t('Search certificate reference, project, or client…')" />
          </div>
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Project') }}</th>
              <th>{{ $t('Client') }}</th>
              <th>{{ $t('Certificate') }}</th>
              <th>{{ $t('Certificate Date') }}</th>
              <th>{{ $t('Due Date') }}</th>
              <th class="num">{{ $t('Net Payment') }}</th>
              <th class="num">{{ $t('Paid') }}</th>
              <th class="num">{{ $t('Outstanding') }}</th>
              <th>{{ $t('Status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('project-certificates.show', r.id))">
              <td :data-label="$t('Project')"><div class="lg-main">{{ r.warehouse_name }}</div><div class="lg-subtext">{{ r.warehouse_code }}</div></td>
              <td :data-label="$t('Client')">{{ r.client_name || '—' }}</td>
              <td :data-label="$t('Certificate')">
                <span class="lg-mono">{{ r.certificate_reference }}</span>
                <span v-if="r.certificate_status && r.certificate_status !== 'issued'" class="lg-chip lg-chip-amber pc-revision-chip">{{ $t('Under Revision') }}</span>
              </td>
              <td :data-label="$t('Certificate Date')"><span class="lg-subtext">{{ r.certificate_date }}</span></td>
              <td :data-label="$t('Due Date')" :class="{ 'pc-late': r.days_overdue > 0 }">
                <div>{{ r.payment_due_date || '—' }}</div>
                <div v-if="r.days_overdue > 0" class="pc-late-sub">{{ r.days_overdue }} {{ $t('days overdue') }}</div>
              </td>
              <td class="num" :data-label="$t('Net Payment')">{{ money(r.net_payment_amount, r.currency) }}</td>
              <td class="num pc-pos" :data-label="$t('Paid')">{{ money(r.total_paid, r.currency) }}</td>
              <td class="num pc-neg" :data-label="$t('Outstanding')">{{ money(r.outstanding, r.currency) }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="payChip(r.payment_status)">{{ statusLabel(r.payment_status) }}</span></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row">
              <td class="lg-empty" colspan="9">{{ $t('No unpaid certificates match these filters.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-4" :meta="rows.meta" :links="rows.links" />
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
    rows: Object,
    filters: Object,
    stats: {
      type: Object,
      default: () => ({ total_count: 0, total_outstanding: {}, overdue_count: 0, overdue_outstanding: {}, unique_projects: 0 }),
    },
    warehouses: Array,
    clients: Array,
    currencies: Array,
  },

  data() {
    return {
      statusOptions: [
        { id: 'unpaid', name: 'Unpaid' },
        { id: 'partially_paid', name: 'Partially Paid' },
        { id: 'overdue', name: 'Overdue' },
      ],
      form: {
        search: this.filters?.search || '',
        warehouse_id: this.filters?.warehouse_id || '',
        client_id: this.filters?.client_id || '',
        currency: this.filters?.currency || '',
        payment_status: this.filters?.payment_status || '',
        date_from: this.filters?.date_from || '',
        date_to: this.filters?.date_to || '',
      },
    };
  },

  computed: {
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });

      return out;
    },
    printUrl() {
      return this.route('project-certificates.unpaid.print', this.activeFilters);
    },
    downloadUrl() {
      return this.route('project-certificates.unpaid.download', this.activeFilters);
    },
  },

  watch: {
    'form.search': debounce(function () { this.applyFilters(); }, 400),
    'form.warehouse_id'() { this.applyFilters(); },
    'form.client_id'() { this.applyFilters(); },
    'form.currency'() { this.applyFilters(); },
    'form.payment_status'() { this.applyFilters(); },
    'form.date_from'() { this.applyFilters(); },
    'form.date_to'() { this.applyFilters(); },
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
    statusLabel(status) {
      const found = this.statusOptions.find(s => s.id === status);
      return found ? found.name : status;
    },
    payChip(status) {
      return { unpaid: 'lg-chip-red', partially_paid: 'lg-chip-amber', overdue: 'pc-chip-overdue' }[status] || 'lg-chip-slate';
    },
    applyFilters() {
      this.$inertia.get(this.route('project-certificates.unpaid'), this.form, { preserveState: true, replace: true });
    },
  },
};
</script>

<style scoped>
.pc-filters { grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); align-items: end; }
.pc-mini-label { display: block; font-size: 0.65rem; font-weight: 800; color: var(--lg-faint); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
.pc-input { border: 1px solid var(--lg-line); border-radius: 0.75rem; padding: 0.6rem 0.8rem; font-size: 0.83rem; background: var(--lg-card); color: var(--lg-ink); }
.pc-input:focus { outline: none; border-color: #1c3f66; box-shadow: 0 0 0 3px rgba(28, 63, 102, 0.12); }
.pc-search-wrap { display: flex; align-items: center; gap: 8px; border: 1px solid var(--lg-line); border-radius: 0.75rem; padding: 0 0.85rem; background: var(--lg-card); }
.pc-search-wrap:focus-within { border-color: #1c3f66; box-shadow: 0 0 0 3px rgba(28, 63, 102, 0.12); }
.pc-search-icon { width: 14px; height: 14px; color: var(--lg-faint); flex: none; }
.pc-search-input { flex: 1; min-width: 0; border: none !important; outline: none !important; box-shadow: none !important; background: transparent !important; padding: 0.65rem 0; font-size: 0.82rem; color: var(--lg-ink); }
.pc-stat-lines { font-size: 1rem; font-weight: 800; line-height: 1.25; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.pc-pos { color: #047857; font-weight: 700; }
.pc-neg { color: #be123c; font-weight: 800; }
html.dark .pc-pos { color: #6ee7b7; }
html.dark .pc-neg { color: #fda4af; }
.pc-late { color: #be123c; font-weight: 700; }
.pc-late-sub { font-size: 0.68rem; font-weight: 700; }
.pc-revision-chip { margin-left: 6px; padding: 1px 8px; font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.02em; }
.pc-chip-overdue { background: #450a0a; color: #fecaca; }
</style>
