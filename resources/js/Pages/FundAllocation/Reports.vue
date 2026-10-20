<template>
  <admin-layout :title="$t('Funds Allocation Reports')">
    <div class="lg-page px-4 md:px-0 fr-page">
      <page-header icon="chart-bar" :title="$t('Funds Allocation Reports')" :subtitle="$t('See where the money went: by period, category, payment method, beneficiary, payer and project.')">
        <Link v-if="can.sheets" :href="route('fund-allocations.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Allocation Sheets') }}</Link>
        <a v-if="can.print" :href="exportUrl('fund-allocations.reports.print')" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="can.export" :href="exportUrl('fund-allocations.reports.export')" class="lg-btn lg-btn-primary"><icons name="download" class="w-4 h-4" />{{ $t('Export') }}</a>
      </page-header>

      <!-- Filters -->
      <section class="lg-card lg-filters">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Everything below, the Print view and the Export follow these filters.') }}</p>
          </div>
          <button v-if="hasFilters" type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button>
        </header>

        <div class="fr-presets">
          <button v-for="p in presets" :key="p.key" type="button" class="fr-preset" @click="applyPreset(p.key)">{{ p.label }}</button>
        </div>

        <div class="lg-fields fr-fields">
          <div><text-input v-model="form.from" type="date" :label="$t('From')" /></div>
          <div><text-input v-model="form.to" type="date" :label="$t('To')" /></div>
          <div>
<filter-select v-model="form.category_id" :label="$t('Category')" :empty-label="$t('All categories')" :options="categoryOptions" />
          </div>
          <div>
<filter-select v-model="form.method_id" :label="$t('Payment Method')" :empty-label="$t('All methods')" :options="methodOptions" />
          </div>
          <div>
<filter-select v-model="form.status" :label="$t('Status')" :empty-label="$t('Active sheets (no Cancelled / Rejected)')" :options="statusOptions" />
          </div>
          <div class="fr-bene">
            <tec-label :value="$t('Beneficiary')" />
            <beneficiary-select
              :contacts="lookups.beneficiary_contacts"
              :users="lookups.beneficiary_users"
              :projects="lookups.beneficiary_projects"
              :warehouse-id="idOrNull(form.beneficiary_warehouse_id)"
              :contact-id="idOrNull(form.contact_id)"
              :user-id="idOrNull(form.beneficiary_user_id)"
              :legacy="form.beneficiary"
              :placeholder="$t('Search contacts, users or projects')"
              @select="onBeneficiary"
            />
          </div>
        </div>

        <button type="button" class="fr-more" @click="more = !more">
          <icons name="chevron-down" class="w-4 h-4 transform" :class="{ 'rotate-180': more }" />
          {{ more ? $t('Fewer filters') : $t('More filters') }}
          <span v-if="moreActive" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ moreActive }}</span>
        </button>

        <div v-show="more" class="lg-fields fr-fields">
          <div>
<filter-select v-model="form.payer" :label="$t('Payer')" :empty-label="$t('Any payer')" :options="payerOptions" />
          </div>
          <div>
<filter-select v-model="form.prepared_by" :label="$t('Prepared By')" :empty-label="$t('Anyone')" :options="preparerOptions" />
          </div>
          <div>
<filter-select v-model="form.warehouse_id" :label="$t('Project')" :empty-label="$t('All projects')" :options="projectOptions" />
          </div>
          <div>
<filter-select v-model="form.staff_id" :label="$t('Staff')" :empty-label="$t('Any staff member')" :options="staffOptions" />
          </div>
          <div>
<filter-select v-model="form.paid" :label="$t('Payment State')" :empty-label="$t('Any')" :options="paidOptions" />
          </div>
          <div>
<filter-select v-model="form.voucher" :label="$t('Payment Voucher')" :empty-label="$t('Any')" :options="voucherOptions" />
          </div>
          <div>
<filter-select v-model="form.group_by" :label="$t('Group By')" :empty-label="$t('No grouping')" :options="groupOptions" />
          </div>
        </div>
      </section>

      <div v-if="excludesInactive" class="lg-note lg-note-amber mb-4">
        <icons name="info" class="w-4 h-4" />
        <div>{{ $t('Cancelled and Rejected sheets are not counted in the totals. Pick that status in the filter to see them.') }}</div>
      </div>
      <div v-if="form.method_id" class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <div>{{ $t('With a payment method selected, Paid and Unpaid refer to the payments made by that method only.') }}</div>
      </div>

      <stat-tiles class="fr-tiles" :items="tiles" />

      <!-- Chart -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Daily allocation vs paid') }}</h2>
            <p class="lg-card-sub">{{ $t('One column pair per sheet date. Paid is what the payer has ticked.') }}</p>
          </div>
        </header>
        <vue-highcharts v-if="chart" type="chart" :options="chart" :redrawOnUpdate="true" :oneToOneUpdate="false" style="height: 300px" />
        <div v-else class="fr-empty">{{ $t('No allocations match these filters.') }}</div>
      </section>

      <!-- Group by -->
      <section v-if="groups" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Grouped by') }} {{ groupLabel(groups.by) }}</h2>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ groupLabel(groups.by) }}</th>
                <th class="num">{{ $t('Sheets') }}</th>
                <th class="num">{{ $t('Items') }}</th>
                <th class="num">{{ $t('Requested') }}</th>
                <th class="num">{{ $t('Allocated') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th class="num">{{ $t('Unpaid') }}</th>
                <th>{{ $t('Share') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="g in groups.rows" :key="g.key">
                <td class="lg-main">{{ g.label !== '' ? g.label : $t('(none)') }}</td>
                <td class="num">{{ g.sheets }}</td>
                <td class="num">{{ g.items }}</td>
                <td class="num">{{ g.requested === null ? '-' : fmt(g.requested) }}<i class="fr-cur">TZS</i></td>
                <td class="num">{{ fmt(g.allocated) }}<i class="fr-cur">TZS</i></td>
                <td class="num">{{ fmt(g.paid) }}<i class="fr-cur">TZS</i></td>
                <td class="num">{{ fmt(g.unpaid) }}<i class="fr-cur">TZS</i></td>
                <td class="fr-share"><div class="fr-bar"><i :style="{ width: g.share + '%' }"></i></div><span>{{ g.share }}%</span></td>
              </tr>
              <tr v-if="!groups.rows.length"><td class="lg-empty" colspan="8">{{ $t('No data.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Breakdowns -->
      <div class="fr-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('By category') }}</h2></div>
          </header>
          <div class="lg-table-wrap">
            <table v-labelize class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Category') }}</th><th class="num">{{ $t('Allocated') }}</th><th class="num">{{ $t('Paid') }}</th><th>{{ $t('Share') }}</th></tr></thead>
              <tbody>
                <tr v-for="c in byCategory" :key="c.id">
                  <td><span class="lg-main">{{ c.name }}</span><div class="lg-subtext">{{ c.items }} {{ $t('items') }}</div></td>
                  <td class="num">{{ fmt(c.allocated) }}<i class="fr-cur">TZS</i></td>
                  <td class="num">{{ fmt(c.paid) }}<i class="fr-cur">TZS</i></td>
                  <td class="fr-share"><div class="fr-bar"><i :style="{ width: c.share + '%' }"></i></div><span>{{ c.share }}%</span></td>
                </tr>
                <tr v-if="!byCategory.length"><td class="lg-empty" colspan="4">{{ $t('No data.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="mobile" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('By payment method') }}</h2></div>
          </header>
          <div class="lg-table-wrap">
            <table v-labelize class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Method') }}</th><th class="num">{{ $t('Planned') }}</th><th class="num">{{ $t('Paid') }}</th><th>{{ $t('Share') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in byMethod" :key="m.id">
                  <td><span class="lg-chip lg-chip-indigo">{{ m.short_label }}</span> <span class="lg-main ml-1">{{ m.name }}</span></td>
                  <td class="num">{{ fmt(m.planned) }}<i class="fr-cur">TZS</i><div v-if="m.currency === 'USD'" class="fr-usd">{{ fmt(m.usd_planned) }} USD</div></td>
                  <td class="num">{{ fmt(m.paid) }}<i class="fr-cur">TZS</i><div v-if="m.currency === 'USD'" class="fr-usd">{{ fmt(m.usd_paid) }} USD</div></td>
                  <td class="fr-share"><div class="fr-bar fr-bar-green"><i :style="{ width: m.share + '%' }"></i></div><span>{{ m.share }}%</span></td>
                </tr>
                <tr v-if="!byMethod.length"><td class="lg-empty" colspan="4">{{ $t('No data.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('By status') }}</h2></div>
          </header>
          <div class="lg-table-wrap">
            <table v-labelize class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Status') }}</th><th class="num">{{ $t('Sheets') }}</th><th class="num">{{ $t('Allocated') }}</th><th>{{ $t('Share') }}</th></tr></thead>
              <tbody>
                <tr v-for="s in byStatus" :key="s.status" :class="{ 'fr-excluded': s.excluded }">
                  <td>
                    <span class="lg-chip" :class="statusTone(s.status)" style="text-transform: none">{{ $t(s.status) }}</span>
                    <div v-if="s.excluded" class="lg-subtext">{{ $t('Not in totals') }}</div>
                  </td>
                  <td class="num">{{ s.sheets }}</td>
                  <td class="num">{{ fmt(s.allocated) }}<i class="fr-cur">TZS</i></td>
                  <td class="fr-share"><template v-if="!s.excluded"><div class="fr-bar"><i :style="{ width: s.share + '%' }"></i></div><span>{{ s.share }}%</span></template></td>
                </tr>
                <tr v-if="!byStatus.length"><td class="lg-empty" colspan="4">{{ $t('No data.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Top beneficiaries') }}</h2></div>
          </header>
          <div class="lg-table-wrap">
            <table v-labelize class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Beneficiary') }}</th><th class="num">{{ $t('Allocated') }}</th><th class="num">{{ $t('Paid') }}</th><th>{{ $t('Share') }}</th></tr></thead>
              <tbody>
                <tr v-for="(b, i) in topBeneficiaries" :key="i">
                  <td><span class="lg-main">{{ b.name || $t('(not specified)') }}</span><div class="lg-subtext">{{ b.items }} {{ $t('items') }}</div></td>
                  <td class="num">{{ fmt(b.allocated) }}<i class="fr-cur">TZS</i></td>
                  <td class="num">{{ fmt(b.paid) }}<i class="fr-cur">TZS</i></td>
                  <td class="fr-share"><div class="fr-bar fr-bar-teal"><i :style="{ width: b.share + '%' }"></i></div><span>{{ b.share }}%</span></td>
                </tr>
                <tr v-if="!topBeneficiaries.length"><td class="lg-empty" colspan="4">{{ $t('No data.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <!-- Detail -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Detail') }}</h2>
            <p class="lg-card-sub">{{ rows.meta.total }} {{ $t('items') }}</p>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Sheet') }}</th>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Category') }}</th>
                <th>{{ $t('Description') }}</th>
                <th>{{ $t('Beneficiary') }}</th>
                <th class="num">{{ $t('Request') }}</th>
                <th class="num">{{ $t('Allocated') }}</th>
                <th>{{ $t('Payment') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th>{{ $t('State') }}</th>
                <th>{{ $t('Voucher') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows.data" :key="r.item_id">
                <td>
                  <Link v-if="can.sheets" :href="route('fund-allocations.show', r.sheet_id)" class="lg-main fr-link">{{ r.reference }}</Link>
                  <span v-else class="lg-main">{{ r.reference }}</span>
                  <div><span class="lg-chip" :class="statusTone(r.status)" style="text-transform: none">{{ $t(r.status) }}</span></div>
                </td>
                <td>{{ r.allocation_date }}</td>
                <td>{{ r.category }}</td>
                <td class="fr-wrap">
                  <div class="lg-main">{{ r.description }}</div>
                  <div v-if="r.project" class="lg-subtext">{{ r.project }}</div>
                </td>
                <td>{{ r.beneficiary || '-' }}</td>
                <td class="num">{{ fmt(r.request_amount) }}<i class="fr-cur">TZS</i></td>
                <td class="num lg-main">{{ fmt(r.allocated_amount) }}<i class="fr-cur">TZS</i></td>
                <td>
                  <div class="fr-lines">
                    <span v-for="(l, i) in r.lines" :key="i" class="lg-chip" :class="l.is_paid ? 'lg-chip-green' : 'lg-chip-slate'" style="text-transform: none">
                      {{ l.short_label }} {{ fmt(l.amount) }}<i class="fr-cur">TZS</i>
                    </span>
                    <span v-if="!r.lines.length" class="lg-subtext">-</span>
                  </div>
                </td>
                <td class="num">{{ fmt(r.paid) }}<i class="fr-cur">TZS</i></td>
                <td><span class="lg-chip" :class="stateTone(r.paid_state)" style="text-transform: none">{{ stateLabel(r.paid_state) }}</span></td>
                <td>
                  <span v-if="r.voucher" class="lg-chip lg-chip-blue" style="text-transform: none">{{ r.voucher.pv_number }}</span>
                  <span v-else class="lg-subtext">-</span>
                </td>
              </tr>
              <tr v-if="!rows.data.length"><td class="lg-empty" colspan="11">{{ $t('No allocations match these filters.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <pagination class="mt-4" :meta="rows.meta" :links="rows.links" />
      </section>
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import VueHighcharts from 'vue3-highcharts';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import FilterSelect from './FilterSelect.vue';
import BeneficiarySelect from './BeneficiarySelect.vue';
import TecLabel from '@/Jetstream/Label.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

const FILTER_KEYS = ['from', 'to', 'category_id', 'method_id', 'status', 'payer', 'prepared_by', 'warehouse_id', 'contact_id', 'beneficiary_user_id', 'beneficiary_warehouse_id', 'staff_id', 'beneficiary', 'voucher', 'paid', 'group_by'];
const MORE_KEYS = ['payer', 'prepared_by', 'warehouse_id', 'staff_id', 'voucher', 'paid', 'group_by'];

export default {
  components: { Link, VueHighcharts, TextInput, Pagination, FilterSelect, BeneficiarySelect, TecLabel, AdminLayout, PageHeader, StatTiles },

  props: {
    filters: { type: Object, default: () => ({}) },
    totals: { type: Object, required: true },
    byCategory: { type: Array, default: () => [] },
    byMethod: { type: Array, default: () => [] },
    byStatus: { type: Array, default: () => [] },
    byDate: { type: Array, default: () => [] },
    topBeneficiaries: { type: Array, default: () => [] },
    groups: { type: Object, default: null },
    rows: { type: Object, required: true },
    lookups: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    excludesInactive: { type: Boolean, default: false },
  },

  data() {
    const form = {};
    FILTER_KEYS.forEach(k => (form[k] = this.filters[k] === undefined || this.filters[k] === null ? '' : this.filters[k]));
    return { form, more: MORE_KEYS.some(k => form[k] !== '') };
  },

  computed: {
    categoryOptions() {
      return (this.lookups.categories || []).map(c => ({ value: c.id, label: c.name }));
    },
    methodOptions() {
      return (this.lookups.methods || []).map(m => ({ value: m.id, label: m.name }));
    },
    statusOptions() {
      return (this.lookups.statuses || []).map(st => ({ value: st, label: this.$t(st) }));
    },
    payerOptions() {
      return (this.lookups.payers || []).map(u => ({ value: u.id, label: u.name }));
    },
    preparerOptions() {
      return (this.lookups.preparers || []).map(u => ({ value: u.id, label: u.name }));
    },
    projectOptions() {
      return (this.lookups.warehouses || []).map(w => ({ value: w.id, label: w.name }));
    },
    staffOptions() {
      return (this.lookups.staffs || []).map(x => ({ value: x.id, label: x.name }));
    },
    paidOptions() {
      return [{ value: 'paid', label: this.$t('Fully paid') }, { value: 'partial', label: this.$t('Partly paid') }, { value: 'unpaid', label: this.$t('Not paid') }];
    },
    voucherOptions() {
      return [{ value: 'with', label: this.$t('With voucher') }, { value: 'without', label: this.$t('Without voucher') }];
    },
    groupOptions() {
      return (this.lookups.groupings || []).map(g => ({ value: g, label: this.groupLabel(g) }));
    },
    hasFilters() {
      return FILTER_KEYS.some(k => this.form[k] !== '' && this.form[k] !== null);
    },
    moreActive() {
      return MORE_KEYS.filter(k => this.form[k] !== '' && this.form[k] !== null).length || '';
    },
    presets() {
      return [
        { key: 'month', label: this.$t('This month') },
        { key: 'last_month', label: this.$t('Last month') },
        { key: 'last30', label: this.$t('Last 30 days') },
        { key: 'year', label: this.$t('This year') },
        { key: 'all', label: this.$t('All time') },
      ];
    },
    tiles() {
      const t = this.totals;
      return [
        { icon: 'doc-text', tone: 'navy', label: this.$t('Sheets'), value: t.sheets },
        { icon: 'list', tone: 'slate', label: this.$t('Items'), value: t.items },
        { icon: 'send', tone: 'amber', label: this.$t('Requested'), value: this.fmt(t.requested) + ' TZS' },
        { icon: 'cash', tone: 'indigo', label: this.$t('Allocated'), value: this.fmt(t.allocated) + ' TZS' },
        { icon: 'tick', tone: 'emerald', label: this.$t('Paid') + ' (' + t.progress + '%)', value: this.fmt(t.paid) + ' TZS' },
        { icon: 'clock', tone: 'rose', label: this.$t('Unpaid'), value: this.fmt(t.unpaid) + ' TZS' },
      ];
    },
    chart() {
      if (!this.byDate.length) return null;
      return {
        chart: { type: 'column', style: { fontFamily: "'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, sans-serif" }, backgroundColor: 'transparent' },
        credits: { enabled: false },
        title: { text: undefined },
        xAxis: { categories: this.byDate.map(d => d.date), crosshair: true },
        yAxis: { title: { text: undefined }, min: 0 },
        legend: { align: 'right', verticalAlign: 'top' },
        tooltip: { shared: true, valueDecimals: 0 },
        colors: ['#4f46e5', '#059669'],
        plotOptions: { column: { borderRadius: 6, borderWidth: 0 } },
        series: [
          { name: this.$t('Allocated'), data: this.byDate.map(d => d.allocated) },
          { name: this.$t('Paid'), data: this.byDate.map(d => d.paid) },
        ],
      };
    },
  },

  watch: {
    form: {
      deep: true,
      handler() {
        this.visit();
      },
    },
  },

  created() {
    this.visit = debounce(() => {
      this.$inertia.get(this.route('fund-allocations.reports'), pickBy(this.form, v => v !== '' && v !== null), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      });
    }, 350);
  },

  methods: {
    idOrNull(v) {
      return v === '' || v === null || v === undefined ? null : Number(v);
    },
    onBeneficiary(v) {
      this.form.contact_id = v.contact_id || '';
      this.form.beneficiary_user_id = v.beneficiary_user_id || '';
      this.form.beneficiary_warehouse_id = v.beneficiary_warehouse_id || '';
      this.form.beneficiary = '';
    },
    fmt(v) {
      return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    queryString() {
      return new URLSearchParams(pickBy(this.filters, v => v !== '' && v !== null)).toString();
    },
    exportUrl(name) {
      const q = this.queryString();
      return this.route(name) + (q ? '?' + q : '');
    },
    reset() {
      FILTER_KEYS.forEach(k => (this.form[k] = ''));
    },
    ymd(d) {
      const p = n => String(n).padStart(2, '0');
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    },
    applyPreset(key) {
      const now = new Date();
      let from = '';
      let to = '';
      if (key === 'month') {
        from = this.ymd(new Date(now.getFullYear(), now.getMonth(), 1));
        to = this.ymd(new Date(now.getFullYear(), now.getMonth() + 1, 0));
      } else if (key === 'last_month') {
        from = this.ymd(new Date(now.getFullYear(), now.getMonth() - 1, 1));
        to = this.ymd(new Date(now.getFullYear(), now.getMonth(), 0));
      } else if (key === 'last30') {
        from = this.ymd(new Date(now.getTime() - 29 * 86400000));
        to = this.ymd(now);
      } else if (key === 'year') {
        from = now.getFullYear() + '-01-01';
        to = now.getFullYear() + '-12-31';
      }
      this.form.from = from;
      this.form.to = to;
    },
    groupLabel(g) {
      return {
        date: this.$t('Date'),
        category: this.$t('Category'),
        method: this.$t('Payment Method'),
        beneficiary: this.$t('Beneficiary'),
        payer: this.$t('Payer'),
        status: this.$t('Status'),
        project: this.$t('Project'),
      }[g] || g;
    },
    statusTone(s) {
      return {
        Draft: 'lg-chip-slate',
        'Pending Approval': 'lg-chip-amber',
        Approved: 'lg-chip-indigo',
        'In Progress': 'lg-chip-cyan',
        Completed: 'lg-chip-green',
        Rejected: 'lg-chip-red',
        Cancelled: 'lg-chip-red',
      }[s] || 'lg-chip-slate';
    },
    stateTone(s) {
      return { paid: 'lg-chip-green', partial: 'lg-chip-amber', unpaid: 'lg-chip-red', none: 'lg-chip-slate' }[s] || 'lg-chip-slate';
    },
    stateLabel(s) {
      return { paid: this.$t('Paid'), partial: this.$t('Partly paid'), unpaid: this.$t('Not paid'), none: this.$t('No payments') }[s] || s;
    },
  },
};
</script>

<style scoped>
.fr-presets { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.fr-preset { padding: 5px 12px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; color: var(--lg-navy); background: rgba(28, 63, 102, 0.08); transition: background 0.15s ease; }
.fr-preset:hover { background: rgba(28, 63, 102, 0.16); }
.fr-more { display: inline-flex; align-items: center; gap: 6px; margin: 12px 0; font-size: 0.78rem; font-weight: 800; color: var(--lg-navy); }
.fr-tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 640px) { .fr-tiles { grid-template-columns: 1fr; } }
.fr-tiles :deep(.lg-stat-value) { font-size: 1.2rem; overflow-wrap: normal; white-space: nowrap; }
.fr-cur { margin-left: 4px; font-style: normal; font-size: 0.72em; font-weight: 700; opacity: 0.75; letter-spacing: 0.03em; }
.fr-usd { font-size: 0.7rem; font-weight: 700; color: #047857; }
.fr-fields :deep(label) { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.fr-fields :deep(input), .fr-fields :deep(select) { border-radius: 0.85rem !important; }
.fr-empty { padding: 36px 12px; text-align: center; color: var(--lg-faint); font-size: 0.85rem; }

.fr-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); }
.fr-grid > * { min-width: 0; }
.fr-share { min-width: 130px; }
.fr-share span { font-size: 0.7rem; font-weight: 800; color: var(--lg-mute); }
.fr-bar { height: 6px; margin-bottom: 3px; border-radius: 9999px; background: rgba(100, 116, 139, 0.18); overflow: hidden; }
.fr-bar i { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #3b82f6, #1c3f66); }
.fr-bar-green i { background: linear-gradient(90deg, #34d399, #047857); }
.fr-bar-teal i { background: linear-gradient(90deg, #2dd4bf, #0f766e); }
.fr-excluded td { opacity: 0.55; }
.fr-wrap { white-space: normal; min-width: 200px; max-width: 320px; }
.fr-lines { display: flex; flex-wrap: wrap; gap: 4px; }
.fr-link:hover { text-decoration: underline; }
</style>
