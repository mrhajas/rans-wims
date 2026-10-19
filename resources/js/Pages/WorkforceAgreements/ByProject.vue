<template>
  <admin-layout :title="$t('Workforce Agreements / By Project')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-pie" :title="$t('Workforce Financials / By Project')" :subtitle="$t('Agreed, requested, approved, paid, and outstanding, grouped by project and worker.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="pdfUrl" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
        <Link :href="route('workforce-agreements.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Workforce Agreements') }}</Link>
      </page-header>

      <div class="lg-card lg-filters relative z-20" style="padding: 1rem 1.1rem">
        <div class="max-w-xs">
          <auto-complete id="warehouse_id" :label="$t('Project')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseOptions" clearable :placeholder="$t('All Projects')" />
        </div>
      </div>

      <section v-for="project in projects" :key="project.warehouse_name" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ project.warehouse_name }}</h2>
            <div class="flex items-center gap-1.5 flex-wrap mt-1">
              <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ project.worker_count }} {{ $t('worker(s)') }}</span>
              <span class="lg-chip lg-chip-amber" style="text-transform: none">{{ project.pending_count }} {{ $t('pending') }}</span>
              <span class="lg-chip lg-chip-green" style="text-transform: none">{{ project.paid_count }} {{ $t('fully paid') }}</span>
            </div>
          </div>
        </header>

        <stat-tiles :items="projectTiles(project)" />

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Labour Personnel') }}</th>
                <th>{{ $t('Scope of Work') }}</th>
                <th>{{ $t('Agreed Date') }}</th>
                <th class="num">{{ $t('Agreed') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th class="num">{{ $t('Outstanding') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in project.workers" :key="w.id" class="is-click" @click="$inertia.visit(route('workforce-agreements.show', w.id))">
                <td :data-label="$t('Labour Personnel')">
                  <div class="lg-person"><span class="lg-avatar">{{ initials(w.payee_name) }}</span><span class="lg-main">{{ w.payee_name }}</span></div>
                </td>
                <td class="wfa-col-scope" :data-label="$t('Scope of Work')">{{ w.scope_of_work || '—' }}</td>
                <td :data-label="$t('Agreed Date')">{{ w.agreement_date ? $date(w.agreement_date) : '—' }}</td>
                <td class="num" :data-label="$t('Agreed')">
                  {{ money(w.agreed_amount) }}
                  <div v-if="w.opening_balance > 0" class="wfa-opening-sub">+ {{ $t('Opening') }}: {{ money(w.opening_balance) }}</div>
                </td>
                <td class="num wfa-paid" :data-label="$t('Paid')">{{ money(w.total_paid) }}</td>
                <td class="num lg-main" :data-label="$t('Outstanding')">{{ money(w.outstanding_balance) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="payChip(w.payment_status)">{{ w.payment_status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div v-if="!projects.length" class="lg-card lg-empty" style="padding: 36px 16px !important">{{ $t('No active agreements found.') }}</div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, PageHeader, StatTiles },

  props: {
    projects: Array,
    warehouses: Array,
    filters: Object,
  },

  data() {
    return {
      form: { warehouse_id: this.filters?.warehouse_id || '' },
    };
  },

  computed: {
    warehouseOptions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    printUrl() {
      return this.route('workforce-agreements.by-project.print', this.form);
    },
    pdfUrl() {
      return this.route('workforce-agreements.by-project.pdf', this.form);
    },
  },

  watch: {
    'form.warehouse_id'() {
      this.$inertia.get(this.route('workforce-agreements.by-project'), this.form, { preserveState: true, replace: true });
    },
  },

  methods: {
    projectTiles(project) {
      return [
        { icon: 'doc-text', tone: 'navy', label: this.$t('Agreed'), value: this.money(project.total_agreed) },
        { icon: 'send', tone: 'indigo', label: this.$t('Requested'), value: this.money(project.total_requested) },
        { icon: 'tick', tone: 'sky', label: this.$t('Approved'), value: this.money(project.total_approved) },
        { icon: 'cash', tone: 'emerald', label: this.$t('Paid'), value: this.money(project.total_paid), valueClass: 'is-good' },
        { icon: 'clock', tone: 'amber', label: this.$t('Outstanding'), value: this.money(project.total_outstanding) },
      ];
    },
    payChip(s) {
      return { unpaid: 'lg-chip-red', partially_paid: 'lg-chip-amber', fully_paid: 'lg-chip-green' }[this.slug(s)] || 'lg-chip-slate';
    },
    initials(name) {
      return (name || '')
        .split(' ')
        .map(p => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
    },
    money(v) {
      return 'TZS ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    slug(s) {
      return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
    },
  },
};
</script>

<style scoped>
.wfa-col-scope { max-width: 220px; white-space: normal !important; overflow-wrap: break-word; color: var(--lg-mute); }
.wfa-opening-sub { font-size: 0.66rem; font-weight: 600; color: #b45309; margin-top: 0.1rem; }
.wfa-paid { font-weight: 700; color: #047857; }
html.dark .wfa-paid { color: #34d399; }
html.dark .wfa-opening-sub { color: #fcd34d; }
@media (max-width: 767.98px) { .wfa-col-scope { max-width: none; } }
</style>
