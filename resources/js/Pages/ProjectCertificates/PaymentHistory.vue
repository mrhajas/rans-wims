<template>
  <admin-layout :title="pageTitle">
    <div class="lg-page px-4 md:px-0 pc-form pc-history">
      <page-header
        icon="cash"
        :title="pageTitle"
        :subtitle="lockedWarehouse ? $t('Every payment recorded against this project\'s certificates.') : $t('Every payment recorded across every project, in one place.')"
      >
        <Link v-if="lockedWarehouse" :href="route('project-certificates.project.index', lockedWarehouse.id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ lockedWarehouse.name }}
        </Link>
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> <span class="hidden sm:inline">{{ $t('Print') }}</span>
        </a>
        <a :href="downloadUrl" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" /> <span class="hidden sm:inline">{{ $t('Download') }}</span>
        </a>
      </page-header>

      <!-- Project-scoped summary — Contract Value, Total Certified, Total Paid
           (the one that matters most at a glance), Certificates (hover for the
           list), Outstanding. All 5 are project-wide facts, unaffected by the
           filters below (see ProjectCertificatePaymentController::projectStats). -->
      <section v-if="projectStats" class="lg-stats lg-stats-tight">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value pc-val">{{ money(projectStats.contract_value, projectStats.contract_currency) }}</div>
            <div class="lg-stat-label">{{ $t('Contract Value (VAT Exclusive)') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value pc-val"><div v-for="(line, i) in moneyMultiLines(projectStats.total_certified)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Certified') }}</div>
          </div>
        </div>
        <div class="lg-stat pc-stat-hi">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value pc-val is-good"><div v-for="(line, i) in moneyMultiLines(stats.total_amount)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Paid') }}</div>
          </div>
        </div>
        <div class="lg-stat pc-pop-wrap" tabindex="0">
          <span class="lg-stat-ico lg-ico-violet"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ projectStats.certificates_count }}</div>
            <div class="lg-stat-label">{{ $t('Certificates') }}</div>
          </div>
          <div v-if="(projectStats.certificates || []).length" class="pc-pop">
            <div class="pc-pop-title">{{ $t('Certificates on this project') }}</div>
            <div v-for="cert in projectStats.certificates" :key="cert.reference" class="pc-pop-row">
              <span>{{ cert.reference }}</span>
              <span class="lg-chip" :class="statusTone(cert.status)">{{ cert.status }}</span>
            </div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value pc-val is-bad"><div v-for="(line, i) in moneyMultiLines(projectStats.outstanding)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Outstanding') }}</div>
          </div>
        </div>
      </section>

      <section v-else class="lg-stats">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ stats.total_count }}</div>
            <div class="lg-stat-label">{{ $t('Total Payments') }}</div>
          </div>
        </div>
        <div class="lg-stat pc-stat-hi">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value pc-val is-good"><div v-for="(line, i) in moneyMultiLines(stats.total_amount)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Amount') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value pc-val"><div v-for="(line, i) in moneyMultiLines(stats.this_month_amount)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('This Month') }}</div>
          </div>
        </div>
        <div class="lg-stat pc-pop-wrap" tabindex="0">
          <span class="lg-stat-ico lg-ico-violet"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ stats.unique_projects }} {{ stats.unique_projects === 1 ? $t('Project') : $t('Projects') }}</div>
            <div class="lg-stat-label">{{ $t('Projects Paid') }}</div>
          </div>
          <div v-if="(stats.projects || []).length" class="pc-pop">
            <div class="pc-pop-title">{{ $t('Projects paid so far') }}</div>
            <div v-for="proj in stats.projects" :key="proj.code || proj.name" class="pc-pop-row is-stacked">
              <span class="pc-pop-name">{{ proj.name }}</span>
              <span class="pc-pop-meta">
                {{ proj.payments_count }} {{ proj.payments_count === 1 ? $t('payment') : $t('payments') }} &middot; {{ moneyMulti(proj.total_amount) }}
              </span>
            </div>
          </div>
        </div>
      </section>

      <section class="lg-card">
        <div class="lg-fields">
          <div v-if="!lockedWarehouse"><auto-complete v-model="form.warehouse_id" :suggestions="warehouses.map(w => ({ id: w.id, label: w.name }))" :placeholder="$t('All Projects')" clearable /></div>
          <div><auto-complete v-model="form.client_id" :suggestions="clients.map(c => ({ id: c.id, label: c.name }))" :placeholder="$t('All Clients')" clearable /></div>
          <div><auto-complete v-model="form.currency" :suggestions="currencies.map(c => ({ id: c.code, label: `${c.code} ${c.name}` }))" :placeholder="$t('All Currencies')" clearable /></div>
          <div><auto-complete v-model="form.method" :suggestions="methods.map(m => ({ id: m, label: m }))" :placeholder="$t('All Methods')" clearable /></div>
          <div><auto-complete v-model="form.payment_status" :suggestions="paymentStatuses.map(s => ({ id: s, label: paymentLabel(s) }))" :placeholder="$t('All Payment Statuses')" clearable /></div>
          <div>
            <label class="pc-label">{{ $t('Date From') }}</label>
            <date-picker v-model="form.date_from" class="w-full" />
          </div>
          <div>
            <label class="pc-label">{{ $t('Date To') }}</label>
            <date-picker v-model="form.date_to" class="w-full" />
          </div>
          <div class="lg-span-all">
            <div class="pc-search">
              <icons name="search" class="pc-search-icon" />
              <input v-model="form.search" type="text" class="pc-input pc-search-input" :placeholder="$t('Search certificate reference, payment reference, project, client, method, payer…')" />
            </div>
          </div>
        </div>
      </section>

      <div class="lg-table-wrap pc-table">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th v-for="col in columns" :key="col.field" class="is-sortable" :class="{ num: col.right }" @click="sortBy(col.field)">
                <span class="lg-sort" :class="{ 'flex-row-reverse': col.right }">
                  {{ col.label }}
                  <icons
                    v-if="form.sort === col.field" name="chevron-down" class="w-3 h-3 transition-transform"
                    :class="{ 'transform rotate-180': form.direction === 'asc' }"
                  />
                  <span v-else class="w-3 h-3"></span>
                </span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in payments.data" :key="p.id" class="is-click" @click="$inertia.visit(route('project-certificates.show', p.certificate_id))">
              <td class="pc-faint" :data-label="$t('Date')">{{ p.payment_date }}</td>
              <td class="lg-main" :data-label="$t('Reference')">{{ p.reference }}</td>
              <td :data-label="$t('Project')">{{ p.warehouse_name }}</td>
              <td :data-label="$t('Client')">{{ p.client_name || '—' }}</td>
              <td class="pc-faint" :data-label="$t('Certificate')">{{ p.certificate_reference }}</td>
              <td :data-label="$t('Method')"><span v-if="p.method" class="lg-chip lg-chip-slate" style="text-transform: none">{{ p.method }}</span><span v-else class="pc-faint">—</span></td>
              <td :data-label="$t('Paid By')">{{ p.paid_by || '—' }}</td>
              <td class="num" :data-label="$t('Amount')">
                <span v-if="p.paid_currency" class="pc-amt">
                  <span class="lg-main">{{ money(p.amount, p.currency) }}</span>
                  <span class="lg-chip lg-chip-indigo pc-cur">{{ p.paid_currency }}</span>
                  <span v-if="p.exchange_rate" class="lg-subtext">({{ $t('Rate') }}: {{ formatRate(p.exchange_rate) }})</span>
                </span>
                <span v-else class="lg-main">{{ money(p.amount, p.currency) }}</span>
              </td>
              <td class="pc-faint" :data-label="$t('Recorded By')">{{ p.recorded_by_name || '—' }}</td>
            </tr>
            <tr v-if="!payments.data.length" class="lg-empty-row">
              <td class="lg-empty" colspan="9">{{ $t('No payments match these filters.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-4" :meta="payments.meta" :links="payments.links" />
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, Pagination, AutoComplete, DatePicker, PageHeader },

  props: {
    payments: Object,
    filters: Object,
    sort: { type: String, default: 'payment_date' },
    direction: { type: String, default: 'desc' },
    stats: { type: Object, default: () => ({ total_count: 0, total_amount: {}, this_month_amount: {}, unique_projects: 0, projects: [] }) },
    // Present only when opened from a project's own "Payment History"
    // button (see ProjectCertificatePaymentController::projectIndex) —
    // locks the Project filter and swaps the stat cards for this
    // project's own standing (Contract Value/Certificates/Outstanding).
    lockedWarehouse: { type: Object, default: null },
    projectStats: { type: Object, default: null },
    warehouses: Array,
    clients: Array,
    currencies: Array,
    methods: Array,
    paymentStatuses: { type: Array, default: () => [] },
  },

  data() {
    return {
      columns: [
        { field: 'payment_date', label: this.$t('Date') },
        { field: 'reference', label: this.$t('Reference') },
        { field: 'warehouse_name', label: this.$t('Project') },
        { field: 'client_name', label: this.$t('Client') },
        { field: 'certificate_reference', label: this.$t('Certificate') },
        { field: 'method', label: this.$t('Method') },
        { field: 'paid_by', label: this.$t('Paid By') },
        { field: 'amount', label: this.$t('Amount'), right: true },
        { field: 'recorded_by_name', label: this.$t('Recorded By') },
      ],
      form: {
        search: this.filters?.search || '',
        warehouse_id: this.filters?.warehouse_id || '',
        client_id: this.filters?.client_id || '',
        currency: this.filters?.currency || '',
        method: this.filters?.method || '',
        payment_status: this.filters?.payment_status || '',
        date_from: this.filters?.date_from || '',
        date_to: this.filters?.date_to || '',
        sort: this.sort,
        direction: this.direction,
      },
    };
  },

  computed: {
    pageTitle() {
      return this.lockedWarehouse ? this.$t('Payment History') + ' · ' + this.lockedWarehouse.name : this.$t('All Payment History');
    },
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });
      if (this.lockedWarehouse) out.warehouse_id = this.lockedWarehouse.id;

      return out;
    },
    printUrl() {
      return this.route('project-certificates.payments.print', this.activeFilters);
    },
    downloadUrl() {
      return this.route('project-certificates.payments.download', this.activeFilters);
    },
  },

  watch: {
    'form.search': debounce(function () { this.applyFilters(); }, 400),
    'form.warehouse_id'() { this.applyFilters(); },
    'form.client_id'() { this.applyFilters(); },
    'form.currency'() { this.applyFilters(); },
    'form.method'() { this.applyFilters(); },
    'form.payment_status'() { this.applyFilters(); },
    'form.date_from'() { this.applyFilters(); },
    'form.date_to'() { this.applyFilters(); },
  },

  methods: {
    statusTone(status) {
      return { draft: 'lg-chip-slate', submitted: 'lg-chip-indigo', approved: 'lg-chip-blue', issued: 'lg-chip-green', revised: 'lg-chip-amber', cancelled: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
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
    // Up to 4dp, trimmed of trailing zeros — exchange rates are often
    // entered with more precision than a plain 2dp money value needs.
    formatRate(rate) {
      return Number(rate).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    },
    paymentLabel(status) {
      return { unpaid: 'Unpaid', partially_paid: 'Partially Paid', paid_in_full: 'Paid in Full', overdue: 'Overdue', disputed: 'Disputed', on_hold: 'On Hold', cancelled: 'Cancelled' }[status] || status;
    },
    sortBy(field) {
      this.form.direction = this.form.sort === field && this.form.direction === 'asc' ? 'desc' : 'asc';
      this.form.sort = field;
      this.applyFilters();
    },
    applyFilters() {
      const url = this.lockedWarehouse
        ? this.route('project-certificates.project.payments', this.lockedWarehouse.id)
        : this.route('project-certificates.payments.index');
      this.$inertia.get(url, this.form, { preserveState: true, replace: true });
    },
  },
};
</script>

<style scoped>
/* ---------- shared Project Certificates chrome (pc- prefix) ---------- */
.pc-label {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 1.1rem;
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--lg-mute);
  margin-bottom: 0.3rem;
}
.pc-req { color: #be123c; }
.pc-input {
  display: block;
  width: 100%;
  border: 1px solid var(--lg-line);
  border-radius: 0.85rem;
  padding: 0.55rem 0.8rem;
  font-size: 0.85rem;
  color: var(--lg-ink);
  background: var(--lg-card);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pc-input::placeholder { color: var(--lg-faint); }
.pc-input:hover:not(:disabled) { border-color: rgba(47, 111, 179, 0.55); }
.pc-input:focus { outline: none; border-color: #2f6fb3; box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18); }
.pc-input:disabled, .pc-input.is-readonly { background: var(--lg-soft); color: var(--lg-mute); cursor: not-allowed; }
.pc-input.is-readonly { font-weight: 800; }
textarea.pc-input { resize: vertical; }

.pc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.pc-form :deep(select),
.pc-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.pc-form :deep(select:focus),
.pc-form :deep(textarea:focus) {
  border-color: #2f6fb3 !important;
  box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18) !important;
}
html.dark .pc-form :deep(input),
html.dark .pc-form :deep(select),
html.dark .pc-form :deep(textarea) { color-scheme: dark; }

.pc-checkbox { width: 1rem; height: 1rem; border-radius: 0.3rem; accent-color: #2f6fb3; flex: none; }

.pc-info { display: inline-flex; align-items: center; color: var(--lg-faint); cursor: help; text-transform: none; letter-spacing: 0; }
.pc-info:hover { color: var(--lg-navy); }
html.dark .pc-info:hover { color: #93c5fd; }
.pc-info.lg-tip::after { right: auto; left: 50%; width: 240px; margin-left: -120px; z-index: 300; font-weight: 600; }
@media (max-width: 640px) { .pc-info.lg-tip::after { margin-left: -60px; width: 220px; } }

.pc-linkbtn { display: inline-flex; align-items: center; gap: 4px; padding: 0 !important; min-height: 0 !important; font-size: 0.74rem; font-weight: 800; color: var(--lg-navy); }
.pc-linkbtn:hover { text-decoration: underline; }
html.dark .pc-linkbtn { color: #93c5fd; }

.pc-sec { padding: 0.9rem 1rem; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); }
.pc-sec-title { display: flex; align-items: center; gap: 7px; margin-bottom: 0.75rem; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-navy); }
html.dark .pc-sec-title { color: #93c5fd; }

.pc-sm { padding: 6px 12px; font-size: 0.72rem; }

.pc-file { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 6px 12px; border-radius: 9999px; font-size: 0.74rem; font-weight: 700; color: var(--lg-navy); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: background 0.15s ease, transform 0.15s ease; }
.pc-file:hover { transform: translateY(-1px); background: rgba(47, 111, 179, 0.12); }
.pc-file > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
html.dark .pc-file { color: #bfdbfe; }

.pc-filelist { border: 1px solid var(--lg-line); border-radius: 14px; }
.pc-filelist li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; font-size: 0.82rem; color: var(--lg-ink); border-bottom: 1px solid var(--lg-line); }
.pc-filelist li:last-child { border-bottom: 0; }
.pc-filelist li > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ---------- dialogs (teleported to body) ---------- */
.pc-overlay { --pc-solid: #ffffff; position: fixed; inset: 0; z-index: 200; display: flex; justify-content: center; padding: 1rem; overflow-y: auto; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); }
html.dark .pc-overlay { --pc-solid: #0f172a; }
.pc-dialog { display: flex; flex-direction: column; width: 100%; max-width: 30rem; margin: auto; border-radius: 22px; background: var(--pc-solid); border: 1px solid var(--lg-line); box-shadow: 0 30px 70px -20px rgba(15, 23, 42, 0.55); }
.pc-dialog-wide { max-width: 38rem; }
.pc-dialog-sm { max-width: 24rem; }
.pc-dialog-head { display: flex; align-items: center; gap: 12px; padding: 1.05rem 1.3rem; border-bottom: 1px solid var(--lg-line); }
.pc-dialog-body { padding: 1.1rem 1.3rem 1.2rem; }
.pc-dialog-foot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; padding: 0.85rem 1.3rem; border-top: 1px solid var(--lg-line); background: var(--lg-soft); border-radius: 0 0 22px 22px; }
.pc-dialog-center { align-items: center; text-align: center; padding: 1.6rem 1.4rem 1.3rem; gap: 4px; }
.pc-dialog-center .pc-dialog-foot { align-self: stretch; margin: 1rem -1.4rem -1.3rem; justify-content: center; }
.pc-stack > * + * { margin-top: 0.85rem; }


/* ---------- Payment history ---------- */
.pc-faint { color: var(--lg-faint); }
.pc-val { font-size: 1.05rem; }
.pc-val > div { line-height: 1.25; }
.lg-stat-value.is-good { color: #047857; }
html.dark .lg-stat-value.is-good { color: #6ee7b7; }
.lg-stat-value.is-bad { color: #b45309; }
html.dark .lg-stat-value.is-bad { color: #fcd34d; }
.pc-stat-hi { background: linear-gradient(150deg, rgba(209, 250, 229, 0.7), var(--lg-card)); border-color: rgba(16, 185, 129, 0.35); }
html.dark .pc-stat-hi { background: linear-gradient(150deg, rgba(6, 78, 59, 0.45), var(--lg-card)); }

.pc-search { position: relative; }
.pc-search-icon { position: absolute; left: 0.85rem; top: 50%; width: 15px; height: 15px; margin-top: -7.5px; color: var(--lg-faint); pointer-events: none; z-index: 1; }
.pc-search-input { padding-left: 2.4rem; }

.pc-table { margin-bottom: 0; }
.pc-amt { display: inline-flex; align-items: center; justify-content: flex-end; gap: 6px; flex-wrap: wrap; }
.pc-cur { padding: 0 8px; font-size: 0.66rem; }

/* Hover / focus popover on the Certificates and Projects Paid tiles */
.pc-pop-wrap { position: relative; cursor: default; }
.pc-pop-wrap:hover, .pc-pop-wrap:focus-within, .pc-pop-wrap:focus { z-index: 60; outline: none; }
.pc-pop {
  position: absolute; left: 0; top: calc(100% + 8px); z-index: 70;
  width: max-content; min-width: 200px; max-width: min(300px, calc(100vw - 2rem)); max-height: 240px; overflow-y: auto;
  padding: 0.65rem 0.8rem; border-radius: 14px; text-align: left;
  background: #0f172a; color: #e2e8f0; box-shadow: 0 16px 34px -10px rgba(15, 23, 42, 0.6);
  opacity: 0; visibility: hidden; transform: translateY(-4px); pointer-events: none;
  transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s;
}
.pc-pop-wrap:hover .pc-pop, .pc-pop-wrap:focus .pc-pop, .pc-pop-wrap:focus-within .pc-pop { opacity: 1; visibility: visible; transform: translateY(0); pointer-events: auto; }
.pc-pop-title { margin-bottom: 0.4rem; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #94a3b8; }
.pc-pop-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0.3rem 0; font-size: 0.74rem; border-top: 1px solid rgba(255, 255, 255, 0.08); }
.pc-pop-row:first-of-type { border-top: 0; }
.pc-pop-row.is-stacked { flex-direction: column; align-items: flex-start; gap: 1px; padding: 0.4rem 0; }
.pc-pop-name { font-weight: 800; color: #f1f5f9; }
.pc-pop-meta { font-size: 0.66rem; color: #94a3b8; }
@media (max-width: 640px) { .pc-pop { left: 0; right: 0; width: auto; max-width: none; } }
@media (prefers-reduced-motion: reduce) { .pc-pop { transition: none; } }
</style>
