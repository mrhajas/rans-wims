<template>
  <admin-layout :title="$t('Subcontract Reconciliation')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="tick" :title="$t('Subcontract Reconciliation')" :subtitle="$t('Imported accounts: what the workbook says against what WIMS holds. A difference stays open until it is explained and approved; it is never forced to zero.')">
        <Link :href="route('subcontracts.import.index')" class="lg-btn lg-btn-soft"><icons name="upload" class="w-4 h-4" /><span>{{ $t('Excel Import') }}</span></Link>
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <export-buttons type="reconciliation" needs-money :money="true" />
      </page-header>

      <div class="sc-over sc-reg-cards">
        <overview-card variant="amber" icon="clock" :title="$t('Open')" :sub="$t('Difference not yet approved')" :value="String(n.open)" :foot="$t('Show Open')" :class="{ 'is-on': status === 'open' }" role="button" tabindex="0" @click="setStatus('open')" @keydown.enter="setStatus('open')" />
        <overview-card variant="hero" icon="tick" :title="$t('Approved')" :sub="$t('Explained and approved')" :value="String(n.approved)" :foot="$t('Show Approved')" :class="{ 'is-on': status === 'approved' }" role="button" tabindex="0" @click="setStatus('approved')" @keydown.enter="setStatus('approved')" />
        <overview-card icon="collection" :title="$t('Accounts')" :sub="$t('Open and approved')" :value="String(n.open + n.approved)" :foot="$t('Show All')" :class="{ 'is-on': status === 'all' }" role="button" tabindex="0" @click="setStatus('all')" @keydown.enter="setStatus('all')" />
      </div>

      <sc-tabs :tabs="tabs" :model-value="status" @update:model-value="setStatus" />
      <search-bar v-model="q" :placeholder="$t('Search by agreement, title, site or subcontractor...')" :loading="loading" :count="rows.total" @submit="run" />

      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr>
          <sort-th col="agreement" tone="key" icon="doc-text" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Agreement') }}</sort-th>
          <sort-th col="contact" tone="text" icon="users" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Subcontractor') }}</sort-th>
          <sort-th col="workbook" tone="money" num icon="upload" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Workbook paid') }}</sort-th>
          <sort-th col="historical" tone="money" num icon="cash" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('WIMS historical paid') }}</sort-th>
          <sort-th col="difference" tone="num" num icon="adjustments" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Difference') }}</sort-th>
          <sort-th col="dated" tone="date" num icon="calendar" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Dated list') }}</sort-th>
          <sort-th col="status" tone="state" icon="tick" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Status') }}</sort-th>
          <th class="hd-end"></th></tr></thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id" class="is-click" tabindex="0" @click="open(r)" @keydown.enter="open(r)">
            <td :data-label="$t('Agreement')"><div class="sc-who"><span class="sc-av av-sq" :class="'av-' + tone(r.id)"><icons name="doc-text" class="w-4 h-4" /></span><div class="min-w-0"><span class="lg-main"><mark-hit :text="r.agreement" :q="q" /></span><div class="lg-subtext"><mark-hit :text="r.title" :q="q" /></div></div></div></td>
            <td :data-label="$t('Subcontractor')"><mark-hit :text="r.contact" :q="q" /></td>
            <td :data-label="$t('Workbook paid')" class="ac-num"><span class="amt-rev">{{ r.source_paid !== null ? mc(r.source_paid, r.currency) : '-' }}</span></td>
            <td :data-label="$t('WIMS historical paid')" class="ac-num"><span class="amt-paid">{{ mc(r.imported_paid, r.currency) }}</span></td>
            <td :data-label="$t('Difference')" class="ac-num"><b :class="Number(r.diff_paid) !== 0 ? 'amt-neg' : 'amt-paid'">{{ mc(r.diff_paid, r.currency) }}</b></td>
            <td :data-label="$t('Dated list')" class="ac-num"><span class="amt-appr">{{ mc(r.dated_list, r.currency) }}</span></td>
            <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r.status)">{{ r.status === 'open' ? $t('Open') : $t('Approved') }}</span></td>
            <td class="lg-sticky-end" @click.stop><Link :href="route('subcontracts.reconciliation.show', r.agreement_id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td></tr>
        </tbody></table></div>
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
import Pager from '@/Pages/Accounts/Pager.vue';
import SortTh from '../SortTh.vue';
import { serverSort } from '../sortable';
import { sc } from '../sc';

// the part of a text that matches the search is marked
const MarkHit = {
  props: { filters: Object, text: String, q: String },
  render() {
    const t = String(this.text || ''); const q = String(this.q || '').trim();
    const i = q ? t.toLowerCase().indexOf(q.toLowerCase()) : -1;
    if (i < 0) return t;
    return [t.slice(0, i), h('mark', { class: 'sc-mark' }, t.slice(i, i + q.length)), t.slice(i + q.length)];
  },
};

export default {
  components: { SortTh, MarkHit, ExportButtons, ScTabs, OverviewCard, SearchBar, AdminLayout, PageHeader, Link, Pager },
  mixins: [sc, serverSort],
  props: { rows: Object, status: String, search: String, counts: Object, can: Object },
  data() { return { q: this.search || '', loading: false, timer: null }; },
  watch: { q(v) { clearTimeout(this.timer); this.timer = setTimeout(this.run, v === '' ? 0 : 300); } }, // the list follows the typing
  beforeUnmount() { clearTimeout(this.timer); },
  computed: {
    n() { const c = this.counts || {}; return { open: Number(c.open || 0), approved: Number(c.approved || 0) }; },
    tabs() { return [{ key: 'open', label: this.$t('Open'), icon: 'clock', tone: 'amber', count: this.n.open }, { key: 'approved', label: this.$t('Approved'), icon: 'tick', tone: 'emerald', count: this.n.approved }, { key: 'all', label: this.$t('All'), icon: 'collection', tone: 'navy', count: this.n.open + this.n.approved }]; },
  },
  methods: {
    tone(id) { return ['blue', 'green', 'violet', 'amber', 'rose', 'teal'][id % 6]; },
    open(r) { this.$inertia.visit(route('subcontracts.reconciliation.show', r.agreement_id)); },
    query(status) { const o = { status }; if (this.q) o.search = this.q; if (this.filters && this.filters.sort) { o.sort = this.filters.sort; o.dir = this.filters.dir; } return o; },
    setStatus(k) { router.get(route('subcontracts.reconciliation.index'), this.query(k), { preserveState: true, preserveScroll: true }); },
    run() {
      if (this.q === (this.search || '')) return;
      this.loading = true;
      router.get(route('subcontracts.reconciliation.index'), this.query(this.status), { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'counts', 'status', 'search', 'filters'], onFinish: () => { this.loading = false; } });
    },
  },
};
</script>
