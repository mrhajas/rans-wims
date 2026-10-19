<template>
  <admin-layout :title="$t('Subcontract Agreements')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Subcontract Agreements')" :subtitle="$t('Every agreement with a subcontractor, its project, status and (for those who may see money) its value and balance.')">
        <Link v-if="can.create" :href="route('subcontracts.agreements.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Agreement') }}</span></Link>
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
              <export-buttons type="agreements" :params="f" />
      </page-header>
      <div class="sc-over sc-reg-cards">
        <overview-card icon="doc-text" :title="$t('Agreements')" :sub="summary.payable + ' ' + $t('payable')" :value="String(summary.count)" :foot="hasFilter ? $t('Matching Filters') : $t('In Total')" />
        <template v-if="money">
          <overview-card variant="blue" icon="doc-text" :title="$t('Revised Value')" :sub="others('revised')" :value="main('revised')" :foot="$t('Contract Value')" />
          <overview-card variant="hero" icon="cash" :title="$t('Paid')" :sub="others('paid_total')" :value="main('paid_total')" :delta="paidPct + '%'" :foot="$t('Paid To Date')" />
          <overview-card variant="rose" icon="clock" :title="$t('Outstanding')" :sub="others('commitment_left')" :value="main('commitment_left')" :foot="$t('Still To Pay')" />
        </template>
      </div>
      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />
      <search-bar v-model="f.search" :placeholder="$t('Search by reference, title, site or subcontractor...')" :loading="loading" :count="rows.total" @submit="go(true)" />
      <form class="lg-card" @submit.prevent="go()"><div class="ac-filters sc-filters">
        <div class="ac-f"><label>{{ $t('Subcontractor') }}</label><acct-select v-model="f.contact_id" :options="contacts" :placeholder="$t('Anyone')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}</label><acct-select v-model="f.warehouse_id" :options="projects" :placeholder="$t('Any')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies" :placeholder="$t('Any')" clearable /></div>
        <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
        <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
        <div class="ac-f sc-act"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn t-slate" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
      </div></form>
      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr class="sc-hrow">
          <sort-th col="reference" tone="key" icon="doc-text" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Agreement') }}</sort-th>
          <sort-th col="contact" tone="text" icon="users" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Subcontractor') }}</sort-th>
          <sort-th col="project" tone="text" icon="map-pin" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Project(s) / Warehouse(s)') }}</sort-th>
          <sort-th col="status" tone="state" icon="tick" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Status') }}</sort-th>
          <sort-th v-for="c in list_fields" :key="c.key" tone="text">{{ c.label }}</sort-th>
          <sort-th v-if="money" col="original" tone="money" num :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Original') }}</sort-th>
          <sort-th v-if="money" col="revised" tone="money" num icon="doc-text" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Revised') }}</sort-th>
          <sort-th v-if="money" col="paid" tone="money" num icon="cash" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Paid') }}</sort-th>
          <sort-th v-if="money" col="outstanding" tone="num" num icon="clock" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Outstanding') }}</sort-th>
          <th class="hd-end"></th></tr></thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('subcontracts.agreements.show', r.id))">
            <td :data-label="$t('Agreement')"><div class="sc-who"><span class="sc-av av-sq" :class="'av-' + tone(r.id)"><icons name="doc-text" class="w-4 h-4" /></span><div class="min-w-0"><span class="lg-main"><mark-hit :text="r.reference" :q="f.search" /></span><div class="lg-subtext"><mark-hit :text="r.title" :q="f.search" /></div></div></div></td>
            <td :data-label="$t('Subcontractor')"><mark-hit :text="r.contact" :q="f.search" /></td>
            <td :data-label="$t('Project(s) / Warehouse(s)')">{{ r.project || '-' }}<div v-if="r.site" class="lg-subtext">{{ r.site }}</div></td>
            <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r.status)">{{ r.status_label }}</span></td>
            <td v-for="c in list_fields" :key="c.key" :data-label="c.label">{{ cv(r, c) }}</td>
            <td v-if="money" :data-label="$t('Original')" class="ac-num"><b v-if="r.figures" class="amt-orig">{{ mc(r.figures.original, r.currency) }}</b><template v-else>-</template></td>
            <td v-if="money" :data-label="$t('Revised')" class="ac-num amt-rev">{{ r.figures ? m(r.figures.revised, r.currency) : '-' }}</td>
            <td v-if="money" :data-label="$t('Paid')" class="ac-num"><template v-if="r.figures"><b class="amt-paid-o">{{ m(r.figures.paid_total, r.currency) }}</b><span class="sc-mini" :title="pct(r.figures) + '%'"><i :style="{ width: Math.min(100, pct(r.figures)) + '%' }"></i></span></template><template v-else>-</template></td>
            <td v-if="money" :data-label="$t('Outstanding')" class="ac-num"><b v-if="r.figures" :class="neg(r.figures.commitment_left) ? 'amt-neg' : 'amt-out'">{{ m(r.figures.commitment_left, r.currency) }}</b></td>
            <td class="lg-sticky-end" @click.stop><Link :href="route('subcontracts.agreements.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="9">{{ $t('There is no data to display.') }}</td></tr>
        </tbody>
      </table></div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import ExportButtons from '../ExportButtons.vue';
import ScTabs from '../ScTabs.vue';
import OverviewCard from '../OverviewCard.vue';
import SearchBar from '../SearchBar.vue';
import { h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';
import { sc } from '../sc';
import SortTh from '../SortTh.vue';
import { serverSort } from '../sortable';

const MarkHit = {
  props: { text: String, q: String },
  render() {
    const t = String(this.text || ''); const q = String(this.q || '').trim();
    const i = q ? t.toLowerCase().indexOf(q.toLowerCase()) : -1;
    if (i < 0) return t;
    return [t.slice(0, i), h('mark', { class: 'sc-mark' }, t.slice(i, i + q.length)), t.slice(i + q.length)];
  },
};

export default {
  components: { SortTh, MarkHit, SearchBar, OverviewCard, ExportButtons, ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager },
  mixins: [sc, serverSort],
  props: { summary: Object, list_fields: { type: Array, default: () => [] }, rows: Object, filters: Object, statuses: Array, contacts: Array, projects: Array, currencies: Array, counts: Object, money: Boolean, can: Object },
  data() { return { f: { ...this.filters }, loading: false, timer: null }; },
  watch: { 'f.search'(v) { clearTimeout(this.timer); this.timer = setTimeout(() => this.go(true), v ? 300 : 0); } }, // the list follows the typing
  beforeUnmount() { clearTimeout(this.timer); },
  computed: {
    cur() { return Object.keys(this.summary.figures || {}); },
    hasFilter() { return Object.keys(this.f).some(k => this.f[k] && k !== 'status'); },
    paidPct() { const x = (this.summary.figures || {})[this.cur[0]]; return x && Number(x.revised) > 0 ? Math.round((Number(x.paid_total) / Number(x.revised)) * 100) : 0; },
    tabs() {
      const c = this.counts || {}; const n = k => Number(c[k] || 0); const all = Object.entries(c).filter(([k]) => k !== 'Archived').reduce((a, [, b]) => a + Number(b), 0);
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: all }, { key: 'Draft', label: this.$t('Draft'), icon: 'edit', tone: 'slate', count: n('Draft') }, { key: 'Submitted', label: this.$t('Waiting'), icon: 'clock', tone: 'amber', count: n('Submitted') + n('Under Review') },
        { key: 'Approved', label: this.$t('Approved'), icon: 'tick', tone: 'sky', count: n('Approved') }, { key: 'Active', label: this.$t('Active'), icon: 'tick', tone: 'emerald', count: n('Active') }, { key: 'Suspended', label: this.$t('Suspended'), icon: 'clock', tone: 'amber', count: n('Suspended') },
        { key: 'Completed', label: this.$t('Completed'), icon: 'lock-closed', tone: 'teal', count: n('Completed') }, { key: 'Terminated', label: this.$t('Terminated'), icon: 'x-circle', tone: 'rose', count: n('Terminated') }, { key: 'Archived', label: this.$t('Archived'), icon: 'folder', tone: 'slate', count: n('Archived') }];
    },
  },
  methods: {
    cv(r, c) { const v = r.custom ? r.custom[c.key] : null; return v === null || v === undefined || v === '' ? '-' : (c.type === 'yesno' ? (v ? this.$t('Yes') : this.$t('No')) : (c.type === 'date' ? this.$date(v) : v)); },
    main(k) { const c = this.cur[0]; return c ? this.mc(this.summary.figures[c][k], c) : '-'; },
    others(k) { return this.cur.slice(1).map(c => this.mc(this.summary.figures[c][k], c)).join(' · '); },
    pct(x) { return Number(x.revised) > 0 ? Math.round((Number(x.paid_total) / Number(x.revised)) * 100) : 0; },
    tone(id) { return ['blue', 'green', 'violet', 'amber', 'rose', 'teal'][id % 6]; },
    go(live) {
      const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; });
      if (live === true) {
        if ((this.f.search || '') === (this.filters.search || '')) return;
        this.loading = true;
        router.get(route('subcontracts.agreements.index'), q, { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'summary', 'counts', 'filters'], onFinish: () => { this.loading = false; } });
        return;
      }
      this.$inertia.get(route('subcontracts.agreements.index'), q, { preserveState: true });
    },
    setStatus(s) { this.f.status = s; this.go(); },
    reset() { this.f = {}; this.go(); },
  },
};
</script>

<style scoped>
.sc-filters .sc-act > div { display: flex; gap: 10px; }
@media (min-width: 1100px) { .sc-filters { grid-template-columns: repeat(5, minmax(0, 1fr)) auto; } }
</style>
