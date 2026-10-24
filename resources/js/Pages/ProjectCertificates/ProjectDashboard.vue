<template>
  <admin-layout :title="$t('Project Certificates')">
    <div class="lg-page px-4 md:px-0 pc-page">
      <div class="mb-3 print:hidden">
        <Link :href="route('project-certificates.index')" class="pc-back-link">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('Project Certificates') }}
        </Link>
      </div>

      <page-header icon="doc-text" :title="warehouse.name" :subtitle="warehouse.code + ' · ' + $t('Project Certificates & Payment Tracking')">
        <a v-if="certificates.length" :href="route('project-certificates.project.print', warehouse.id)" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="certificates.length" :href="route('project-certificates.project.download', warehouse.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
        <Link :href="route('project-certificates.contract.edit', warehouse.id)" class="lg-btn lg-btn-soft"><icons name="cog" class="w-4 h-4" />{{ $t('Contract Setup') }}</Link>
        <Link :href="route('project-certificates.project.payments', warehouse.id)" class="lg-btn lg-btn-green"><icons name="cash" class="w-4 h-4" />{{ $t('Payment History') }}</Link>
        <Link v-if="contract" :href="route('project-certificates.create', warehouse.id)" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('Create Certificate') }}</Link>
      </page-header>

      <div v-if="!contract" class="lg-note lg-note-amber pc-setup-note">
        <icons name="info" class="w-5 h-5 flex-none" />
        <div class="pc-setup-body">
          <div class="font-bold">{{ $t('Contract details not set up yet') }}</div>
          <p class="text-xs mt-0.5">{{ $t('Set the client, contract sum, and currency for this project before creating a certificate.') }}</p>
        </div>
        <Link :href="route('project-certificates.contract.edit', warehouse.id)" class="lg-btn lg-btn-primary flex-none">{{ $t('Set Up Now') }}</Link>
      </div>

      <section v-else class="lg-stats lg-stats-tight">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines">{{ money(stats.contract_value) }}</div>
            <div class="lg-stat-label">{{ $t('Contract Value (VAT Exclusive)') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ stats.certificates_count }}</div>
            <div class="lg-stat-label">{{ $t('Certificates') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="chart-pie" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines"><div v-for="(line, i) in moneyMultiLines(stats.total_certified)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Certified') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines pc-pos"><div v-for="(line, i) in moneyMultiLines(stats.total_paid)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Total Paid') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines pc-warn"><div v-for="(line, i) in moneyMultiLines(stats.outstanding)" :key="i">{{ line }}</div></div>
            <div class="lg-stat-label">{{ $t('Outstanding') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-sky"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="pc-stat-lines pc-stat-small">{{ stats.last_certificate ? (stats.last_certificate.reference || ('No. ' + stats.last_certificate.certificate_number)) : $t('None') }}</div>
            <div class="lg-stat-label">{{ $t('Last Certificate') }}</div>
          </div>
        </div>
      </section>

      <section v-if="contract" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <div class="lg-card-title">{{ $t('Financial Progress') }}</div>
            <div class="lg-card-sub">{{ moneyMulti(stats.total_paid) }} / {{ money(stats.contract_value) }}</div>
          </div>
        </div>
        <!-- Progress bars compare against the contract's own currency only
             (contract_value has no meaningful "total" across currencies) —
             a certificate issued in a different currency still shows in the
             stat cards above, just doesn't move these two bars. -->
        <div class="pc-progress-track">
          <div class="pc-progress-fill pc-progress-certified" :style="{ width: pct(stats.total_certified[stats.contract_currency] || 0, stats.contract_value) + '%' }"></div>
        </div>
        <div class="pc-progress-track mt-1.5">
          <div class="pc-progress-fill pc-progress-paid" :style="{ width: pct(stats.total_paid[stats.contract_currency] || 0, stats.contract_value) + '%' }"></div>
        </div>
        <div class="flex items-center gap-4 mt-3 text-[11px] pc-legend">
          <span class="inline-flex items-center gap-1.5"><span class="pc-legend-dot pc-legend-certified"></span>{{ $t('Certified') }}</span>
          <span class="inline-flex items-center gap-1.5"><span class="pc-legend-dot pc-legend-paid"></span>{{ $t('Paid') }}</span>
        </div>
      </section>

      <section v-if="certificates.length" class="lg-card lg-filters">
        <div class="lg-fields pc-filters">
          <div class="pc-search-wrap lg-span-all">
            <icons name="search" class="pc-search-icon" />
            <input v-model="search" type="text" class="pc-search-input" :placeholder="$t('Search reference, certificate number, or status…')" />
          </div>
          <css-select v-model="statusFilter" class="pc-input w-full">
            <option value="">{{ $t('All Statuses') }}</option>
            <option v-for="s in statuses" :key="s" :value="s">{{ capitalize(s) }}</option>
          </css-select>
          <css-select v-model="paymentFilter" class="pc-input w-full">
            <option value="">{{ $t('All Payment Statuses') }}</option>
            <option v-for="s in paymentStatuses" :key="s" :value="s">{{ paymentLabel(s) }}</option>
          </css-select>
          <div>
            <label class="pc-mini-label">{{ $t('Date From') }}</label>
            <date-picker v-model="dateFrom" class="w-full" flush />
          </div>
          <div>
            <label class="pc-mini-label">{{ $t('Date To') }}</label>
            <date-picker v-model="dateTo" class="w-full" flush />
          </div>
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Certificate') }}</th>
              <th>{{ $t('Date') }}</th>
              <th class="num">{{ $t('Amount') }}</th>
              <th class="num">{{ $t('Paid') }}</th>
              <th>{{ $t('Status') }}</th>
              <th>{{ $t('Payment') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="c in filteredCertificates" :key="c.id">
              <!-- A past, superseded version — sourced from Revision History,
                   not a real certificate row. Clicking it opens the CURRENT
                   version (there's no separate page for an old snapshot). -->
              <tr v-if="c.is_voided" class="is-click pc-row-voided" @click="$inertia.visit(route('project-certificates.show', c.superseded_by_id))">
                <td class="pc-void-text" :data-label="$t('Certificate')"><span class="lg-main pc-strike">{{ c.reference }}<span class="pc-version-suffix">-{{ c.version_letter }}</span></span></td>
                <td :data-label="$t('Date')"><span class="lg-subtext">{{ c.certificate_date || '—' }}</span></td>
                <td class="num pc-void-text" colspan="2" :data-label="$t('Amount')">
                  {{ $t('Revised to') }} <span class="pc-revised-to">{{ c.superseded_by_reference }}</span>
                </td>
                <td :data-label="$t('Status')"><span class="lg-chip lg-chip-slate">{{ $t('Voided') }}</span></td>
                <td class="pc-muted" :data-label="$t('Payment')">—</td>
              </tr>
              <tr v-else class="is-click" @click="$inertia.visit(route('project-certificates.show', c.id))">
                <td :data-label="$t('Certificate')"><span class="lg-main">{{ c.reference || ('#' + c.certificate_number) }}<span v-if="c.version_letter" class="pc-version-suffix">-{{ c.version_letter }}</span></span></td>
                <td :data-label="$t('Date')"><span class="lg-subtext">{{ c.certificate_date || '—' }}</span></td>
                <td class="num" :class="c.status === 'issued' ? 'pc-strong' : 'pc-muted'" :data-label="$t('Amount')">
                  {{ c.status === 'issued' ? money(c.net_payment_amount, c.currency) : $t('Not yet issued') }}
                </td>
                <td class="num" :data-label="$t('Paid')">{{ money(c.total_paid, c.currency) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(c.status)">{{ c.status }}</span></td>
                <td :data-label="$t('Payment')"><span class="lg-chip" :class="payChip(c.payment_status)">{{ paymentLabel(c.payment_status) }}</span></td>
              </tr>
            </template>
            <tr v-if="!certificates.length" class="lg-empty-row">
              <td class="lg-empty" colspan="6">{{ $t('No certificates yet for this project.') }}</td>
            </tr>
            <tr v-else-if="!filteredCertificates.length" class="lg-empty-row">
              <td class="lg-empty" colspan="6">{{ $t('No certificates match these filters.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, PageHeader },

  props: {
    warehouse: Object,
    contract: Object,
    certificates: Array,
    stats: Object,
  },

  data() {
    return {
      search: '',
      statusFilter: '',
      paymentFilter: '',
      dateFrom: '',
      dateTo: '',
      statuses: ['draft', 'submitted', 'approved', 'issued', 'cancelled'],
      paymentStatuses: ['unpaid', 'partially_paid', 'paid_in_full', 'overdue', 'disputed', 'on_hold', 'cancelled'],
    };
  },

  computed: {
    filteredCertificates() {
      const q = this.search.trim().toLowerCase();

      return this.certificates.filter(c => {
        if (this.statusFilter && c.status !== this.statusFilter) return false;
        if (this.paymentFilter && c.payment_status !== this.paymentFilter) return false;
        if (this.dateFrom && (!c.certificate_date || c.certificate_date < this.dateFrom)) return false;
        if (this.dateTo && (!c.certificate_date || c.certificate_date > this.dateTo)) return false;
        if (!q) return true;

        return (c.reference || '').toLowerCase().includes(q)
          || String(c.certificate_number).includes(q)
          || (c.status || '').toLowerCase().includes(q)
          || (c.payment_status ? this.paymentLabel(c.payment_status).toLowerCase().includes(q) : false);
      });
    },
  },

  methods: {
    money(v, currency) {
      return (currency || this.contract?.currency || 'TZS') + '\u00A0' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    // total_certified/total_paid/outstanding arrive keyed by currency —
    // this project can itself span multiple certificate currencies.
    moneyMulti(totalsByCurrency) {
      const entries = Object.entries(totalsByCurrency || {});
      if (!entries.length) return this.money(0);

      return entries.map(([currency, v]) => this.money(v, currency)).join(' + ');
    },
    moneyMultiLines(totalsByCurrency) {
      const entries = Object.entries(totalsByCurrency || {});
      if (!entries.length) return [this.money(0)];

      return entries.map(([currency, v], i) => this.money(v, currency) + (i < entries.length - 1 ? ' +' : ''));
    },
    pct(part, whole) {
      if (!whole) return 0;
      return Math.min(100, Math.round((part / whole) * 1000) / 10);
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
    capitalize(s) {
      return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
    },
  },
};
</script>

<style scoped>
.pc-back-link { display: inline-flex; align-items: center; gap: 4px; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); }
.pc-back-link:hover { color: var(--lg-navy); }
html.dark .pc-back-link:hover { color: #bfdbfe; }

.pc-setup-note { align-items: center; gap: 14px; margin-bottom: 1rem; padding: 1rem 1.25rem; flex-wrap: wrap; }
.pc-setup-note svg { margin-top: 0; }
.pc-setup-body { flex: 1 1 220px; min-width: 0; }

.pc-stat-lines { font-size: 1rem; font-weight: 800; line-height: 1.25; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.pc-stat-small { font-size: 0.88rem; }
.pc-pos { color: #047857; }
.pc-warn { color: #b45309; }
html.dark .pc-pos { color: #6ee7b7; }
html.dark .pc-warn { color: #fcd34d; }

.pc-progress-track { height: 9px; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); overflow: hidden; }
.pc-progress-fill { height: 100%; border-radius: 9999px; transition: width 0.8s cubic-bezier(0.16, 1, 0.3, 1); }
.pc-progress-certified { background: linear-gradient(90deg, #93c5fd, #1c3f66); }
.pc-progress-paid { background: linear-gradient(90deg, #6ee7b7, #047857); }
.pc-legend { color: var(--lg-mute); }
.pc-legend-dot { display: inline-block; width: 8px; height: 8px; border-radius: 9999px; }
.pc-legend-certified { background: #1c3f66; }
.pc-legend-paid { background: #047857; }

.pc-filters { grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); align-items: end; }
.pc-mini-label { display: block; font-size: 0.65rem; font-weight: 800; color: var(--lg-faint); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
.pc-input { border: 1px solid var(--lg-line); border-radius: 0.75rem; padding: 0.6rem 0.8rem; font-size: 0.83rem; background-color: var(--lg-card); color: var(--lg-ink); }
.pc-input:focus { outline: none; border-color: #1c3f66; box-shadow: 0 0 0 3px rgba(28, 63, 102, 0.12); }
.pc-search-wrap { display: flex; align-items: center; gap: 8px; border: 1px solid var(--lg-line); border-radius: 0.75rem; padding: 0 0.85rem; background: var(--lg-card); }
.pc-search-wrap:focus-within { border-color: #1c3f66; box-shadow: 0 0 0 3px rgba(28, 63, 102, 0.12); }
.pc-search-icon { width: 14px; height: 14px; color: var(--lg-faint); flex: none; }
.pc-search-input { flex: 1; min-width: 0; border: none !important; outline: none !important; box-shadow: none !important; background: transparent !important; padding: 0.65rem 0; font-size: 0.82rem; color: var(--lg-ink); }

.pc-version-suffix { color: var(--lg-faint); font-weight: 600; }
.pc-strong { font-weight: 800; }
.pc-muted { color: var(--lg-faint) !important; }
.pc-strike { text-decoration: line-through; color: var(--lg-faint); }
.pc-void-text { color: var(--lg-faint); }
.pc-revised-to { font-weight: 800; color: var(--lg-navy); }
html.dark .pc-revised-to { color: #93c5fd; }
.pc-chip-overdue { background: #450a0a; color: #fecaca; }
</style>
