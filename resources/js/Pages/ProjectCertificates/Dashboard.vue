<template>
  <admin-layout :title="$t('Certificate Dashboard')">
    <div class="lg-page px-4 md:px-0 pc-page">
      <page-header icon="chart-pie" :title="$t('Projects Contract Dashboard')" :subtitle="$t('Every project with a certificate contract, at a glance.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="downloadUrl" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
      </page-header>

      <section v-if="projectsWithoutContract.length" class="lg-card pc-setup-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Projects Awaiting Contract Setup') }}</div>
            <div class="lg-card-sub">{{ projectsWithoutContract.length }} {{ $t("project(s) don't have a contract set up yet.") }}</div>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Link
            v-for="p in projectsWithoutContract" :key="p.id"
            :href="route('project-certificates.project.index', p.id)"
            class="pc-chip"
          >
            <icons name="building" class="w-3 h-3" />
            {{ p.name }}
            <span class="pc-chip-code">{{ p.code }}</span>
          </Link>
        </div>
      </section>

      <section class="lg-stats lg-stats-tight">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ stats.count }}</div>
            <div class="lg-stat-label">{{ $t('Projects') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.contract_value)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Contract Value (VAT Exclusive)') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="chart-pie" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.certified)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Certified') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.paid)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Paid') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.unpaid)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Unpaid') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-rose"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.overdue)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Overdue') }}</div>
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
              v-model="form.client_id"
              :suggestions="clients.map(c => ({ id: c.id, label: c.name }))"
              :placeholder="$t('All Clients')"
              clearable
            />
          </div>
          <div>
            <label class="pc-mini-label">{{ $t('Date From') }}</label>
            <date-picker v-model="form.date_from" class="w-full" />
          </div>
          <div>
            <label class="pc-mini-label">{{ $t('Date To') }}</label>
            <date-picker v-model="form.date_to" class="w-full" />
          </div>
          <div class="pc-search-wrap lg-span-all">
            <icons name="search" class="pc-search-icon" />
            <input v-model="form.search" type="text" class="pc-search-input" :placeholder="$t('Search project, client, or certificate reference…')" />
          </div>
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th class="text-center">{{ $t('Actions') }}</th>
              <th>{{ $t('Project') }}</th>
              <th>{{ $t('Client') }}</th>
              <th class="num">{{ $t('Contract Sum (VAT Exclusive)') }}</th>
              <th class="num">{{ $t('Certified') }}</th>
              <th class="num">{{ $t('Paid') }}</th>
              <th class="num">{{ $t('Unpaid') }}</th>
              <th class="text-center">{{ $t('Payment Status') }}</th>
              <th>{{ $t('Last Certificate') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filteredRows" :key="r.warehouse_id" class="is-click" @click="$inertia.visit(route('project-certificates.project.index', r.warehouse_id))">
              <td :data-label="$t('Actions')" @click.stop>
                <div class="lg-actions pc-actions">
                  <Link :href="route('warehouses.edit', r.warehouse_id)" class="lg-icon-btn lg-tip tip-left" :data-tip="$t('View Project Info')" :aria-label="$t('View Project Info')">
                    <icons name="info" class="w-4 h-4" />
                  </Link>
                  <Link :href="route('project-certificates.project.index', r.warehouse_id)" class="lg-icon-btn lg-tip tip-left" :data-tip="$t('Certificates')" :aria-label="$t('Certificates')">
                    <icons name="doc-text" class="w-4 h-4" />
                  </Link>
                </div>
              </td>
              <td :data-label="$t('Project')"><div class="lg-main">{{ r.warehouse_name }}</div><div class="lg-subtext">{{ r.warehouse_code }}</div></td>
              <td :data-label="$t('Client')">{{ r.client_name || '—' }}</td>
              <td class="num" :data-label="$t('Contract Sum (VAT Exclusive)')">{{ money(r.contract_value, r.currency) }}</td>
              <td class="num" :data-label="$t('Certified')">{{ moneyMulti(r.total_certified) }}</td>
              <td class="num pc-pos" :data-label="$t('Paid')">{{ moneyMulti(r.total_paid) }}</td>
              <td class="num pc-warn" :data-label="$t('Unpaid')">{{ moneyMulti(r.total_unpaid) }}</td>
              <td class="text-center" :data-label="$t('Payment Status')">
                <span v-if="hasAny(r.total_overdue)" class="lg-chip lg-chip-red">
                  <icons name="exclamation" class="w-3.5 h-3.5" /> {{ $t('Overdue') }}
                </span>
                <span v-else class="lg-chip lg-chip-green">
                  <icons name="tick" class="w-3.5 h-3.5" /> {{ $t('In Time') }}
                </span>
              </td>
              <td :data-label="$t('Last Certificate')">
                <template v-if="r.last_certificate_reference">
                  <div class="lg-main">{{ r.last_certificate_reference }}</div>
                  <div class="lg-subtext">{{ r.last_certificate_date }}</div>
                </template>
                <span v-else class="pc-muted">{{ $t('None yet') }}</span>
              </td>
            </tr>
            <tr v-if="!filteredRows.length" class="lg-empty-row">
              <td class="lg-empty" colspan="9">{{ $t('No projects with certificate contracts match these filters.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, DatePicker, PageHeader },

  props: {
    rows: Array,
    filters: Object,
    warehouses: Array,
    clients: Array,
    projectsWithoutContract: { type: Array, default: () => [] },
  },

  data() {
    return {
      form: {
        search: this.filters?.search || '',
        warehouse_id: this.filters?.warehouse_id || '',
        client_id: this.filters?.client_id || '',
        date_from: this.filters?.date_from || '',
        date_to: this.filters?.date_to || '',
      },
    };
  },

  computed: {
    filteredRows() {
      return this.rows;
    },
    // Grouped by currency rather than summed flat — projects here can span
    // multiple contract currencies (e.g. a TZS project and a USD project),
    // and adding those raw numbers together would be meaningless.
    stats() {
      const totals = { count: this.rows.length, contract_value: {}, certified: {}, paid: {}, unpaid: {}, overdue: {} };
      const add = (bucket, currency, v) => { bucket[currency] = (bucket[currency] || 0) + (Number(v) || 0); };
      // total_certified/total_paid/total_unpaid/total_overdue are already
      // per-currency objects from the backend (a project can itself span
      // multiple certificate currencies), so their keys merge straight into
      // the page-level totals — only contract_value is a single number in
      // the project's own contract currency.
      const addAll = (bucket, amounts) => Object.entries(amounts || {}).forEach(([currency, v]) => add(bucket, currency, v));

      this.rows.forEach(r => {
        add(totals.contract_value, r.currency || 'TZS', r.contract_value);
        addAll(totals.certified, r.total_certified);
        addAll(totals.paid, r.total_paid);
        addAll(totals.unpaid, r.total_unpaid);
        addAll(totals.overdue, r.total_overdue);
      });

      return totals;
    },
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });

      return out;
    },
    printUrl() {
      return this.route('project-certificates.dashboard.print', this.activeFilters);
    },
    downloadUrl() {
      return this.route('project-certificates.dashboard.download', this.activeFilters);
    },
  },

  watch: {
    'form.search': debounce(function () { this.applyFilters(); }, 400),
    'form.warehouse_id'() { this.applyFilters(); },
    'form.client_id'() { this.applyFilters(); },
    'form.date_from'() { this.applyFilters(); },
    'form.date_to'() { this.applyFilters(); },
  },

  methods: {
    // A non-breaking space between the currency code and its figure keeps
    // "TZS 180,000,000.00" together as one unit — mobile card layouts wrap
    // at the join between currencies (" + "), never inside a single amount.
    money(v, currency) {
      return (currency || 'TZS') + '\u00A0' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    moneyMulti(totalsByCurrency) {
      const entries = Object.entries(totalsByCurrency || {});
      if (!entries.length) return this.money(0, 'TZS');

      return entries.map(([currency, v]) => this.money(v, currency)).join(' + ');
    },
    // One line per currency, each but the last ending in a trailing "+" —
    // for stat cards specifically, so "USD 118,000.00 + TZS 90,000,000.00"
    // never wraps mid-figure; moneyMulti() above stays as the single-line
    // joined form for table cells.
    moneyMultiLines(totalsByCurrency) {
      const entries = Object.entries(totalsByCurrency || {});
      if (!entries.length) return [this.money(0, 'TZS')];

      return entries.map(([currency, v], i) => this.money(v, currency) + (i < entries.length - 1 ? ' +' : ''));
    },
    hasAny(totalsByCurrency) {
      return Object.keys(totalsByCurrency || {}).length > 0;
    },
    applyFilters() {
      this.$inertia.get(this.route('project-certificates.dashboard'), this.form, { preserveState: true, replace: true });
    },
  },
};
</script>

<style scoped>
.pc-stat-lines { font-size: 0.92rem; font-weight: 800; line-height: 1.25; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.pc-pos { color: #047857; font-weight: 700; }
.pc-warn { color: #b45309; font-weight: 700; }
html.dark .pc-pos { color: #6ee7b7; }
html.dark .pc-warn { color: #fcd34d; }
.pc-muted { color: var(--lg-faint); }
.pc-actions { justify-content: center; }
/* row action tooltips always open above the button, even on the first row */
.pc-page .lg-table tbody tr:first-child .lg-tip::after { top: auto; bottom: calc(100% + 8px); }

.pc-setup-card { border-color: rgba(245, 158, 11, 0.35); }
.pc-chip {
  display: inline-flex; align-items: center; gap: 6px; padding: 0.4rem 0.8rem; border-radius: 9999px;
  background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-navy); font-size: 0.74rem; font-weight: 700;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.pc-chip:hover { transform: translateY(-1px); box-shadow: 0 6px 14px -4px rgba(37, 99, 235, 0.3); }
.pc-chip-code { color: #2563eb; font-weight: 800; }
html.dark .pc-chip { color: #cbd5e1; }
html.dark .pc-chip-code { color: #60a5fa; }

.pc-filters { grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); align-items: end; }
.pc-mini-label { display: block; font-size: 0.65rem; font-weight: 800; color: var(--lg-faint); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
.pc-search-wrap { display: flex; align-items: center; gap: 8px; border: 1px solid var(--lg-line); border-radius: 0.75rem; padding: 0 0.85rem; background: var(--lg-card); }
.pc-search-wrap:focus-within { border-color: #1c3f66; box-shadow: 0 0 0 3px rgba(28, 63, 102, 0.12); }
.pc-search-icon { width: 14px; height: 14px; color: var(--lg-faint); flex: none; }
.pc-search-input { flex: 1; min-width: 0; border: none !important; outline: none !important; box-shadow: none !important; background: transparent !important; padding: 0.65rem 0; font-size: 0.82rem; color: var(--lg-ink); }

@media (max-width: 767.98px) {
  .pc-actions { justify-content: flex-end; }
}
</style>
