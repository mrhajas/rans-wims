<template>
  <admin-layout :title="$t('Subcontract Variations')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="adjustments" :title="$t('Subcontract Variations')" :subtitle="$t('Additional works and revised costs. A variation changes the revised contract value only when it is approved.')">
        <Link :href="route('subcontracts.agreements.index')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Agreements') }}</span></Link>
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <export-buttons type="variations" :params="f" needs-money :money="money" />
      </page-header>

      <div class="sc-over sc-reg-cards">
        <overview-card icon="adjustments" :title="$t('Variations')" :sub="$t('Every status')" :value="String(n.all)" :foot="$t('Show All')" :class="{ 'is-on': !f.status }" role="button" tabindex="0" @click="setStatus('')" @keydown.enter="setStatus('')" />
        <overview-card variant="amber" icon="clock" :title="$t('Waiting')" :sub="$t('Submitted or reviewed')" :value="String(n.waiting)" :foot="$t('Show Waiting')" :class="{ 'is-on': f.status === 'Submitted' }" role="button" tabindex="0" @click="setStatus('Submitted')" @keydown.enter="setStatus('Submitted')" />
        <overview-card variant="hero" icon="tick" :title="$t('Approved')" :sub="$t('Changing the contract value')" :value="String(n.approved)" :foot="$t('Show Approved')" :class="{ 'is-on': f.status === 'Approved' }" role="button" tabindex="0" @click="setStatus('Approved')" @keydown.enter="setStatus('Approved')" />
        <overview-card icon="x-circle" :title="$t('Rejected')" :sub="$t('No effect on the contract')" :value="String(n.rejected)" :foot="$t('Show Rejected')" :class="{ 'is-on': f.status === 'Rejected' }" role="button" tabindex="0" @click="setStatus('Rejected')" @keydown.enter="setStatus('Rejected')" />
      </div>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />
      <search-bar v-model="f.search" :placeholder="$t('Search by reference, title, agreement or subcontractor...')" :loading="loading" :count="rows.total" @submit="go(true)" />

      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr>
          <sort-th col="reference" tone="key" icon="adjustments" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Variation') }}</sort-th>
          <sort-th col="agreement" tone="text" icon="doc-text" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Agreement') }}</sort-th>
          <sort-th col="contact" tone="text" icon="users" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Subcontractor') }}</sort-th>
          <sort-th col="kind" tone="date" icon="info" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Kind') }}</sort-th>
          <sort-th v-if="money" col="amount" tone="money" num icon="cash" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Amount') }}</sort-th>
          <sort-th col="status" tone="state" icon="tick" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Status') }}</sort-th>
          <th class="hd-end"></th></tr></thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id" class="is-click" tabindex="0" @click="open(r.id)" @keydown.enter="open(r.id)">
            <td :data-label="$t('Variation')"><div class="sc-who"><span class="sc-av av-sq" :class="'av-' + (r.direction === 'reduction' ? 'rose' : 'violet')"><icons name="adjustments" class="w-4 h-4" /></span><div class="min-w-0"><span class="lg-main"><mark-hit :text="r.reference" :q="f.search" /></span><div class="lg-subtext"><mark-hit :text="r.title" :q="f.search" /></div></div></div></td>
            <td :data-label="$t('Agreement')"><span @click.stop><Link :href="route('subcontracts.agreements.show', r.agreement_id)" class="lg-main"><mark-hit :text="r.agreement" :q="f.search" /></Link></span><div v-if="r.agreement_title" class="lg-subtext">{{ r.agreement_title }}</div></td>
            <td :data-label="$t('Subcontractor')"><mark-hit :text="r.contact" :q="f.search" /></td>
            <td :data-label="$t('Kind')"><span class="sc-pill" :class="r.direction === 'reduction' ? 'pill-red' : 'pill-green'">{{ r.direction === 'reduction' ? $t('Reduction') : $t('Increase') }}</span></td>
            <td v-if="money" :data-label="$t('Amount')" class="ac-num"><span :class="r.direction === 'reduction' ? 'amt-neg' : 'amt-paid'">{{ r.direction === 'reduction' ? '-' : '+' }}{{ mc(r.amount, r.currency) }}</span></td>
            <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r.status)">{{ r.status_label }}</span></td>
            <td class="lg-sticky-end" @click.stop><Link :href="route('subcontracts.variations.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" :colspan="money ? 7 : 6">{{ $t('There is no data to display.') }}</td></tr>
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
  props: { text: String, q: String },
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
  props: { rows: Object, filters: Object, statuses: Array, counts: Object, money: Boolean },
  data() { return { f: { ...this.filters }, loading: false, timer: null }; },
  watch: { 'f.search'(v) { clearTimeout(this.timer); this.timer = setTimeout(() => this.go(true), v ? 300 : 0); } }, // the list follows the typing
  beforeUnmount() { clearTimeout(this.timer); },
  computed: {
    n() { const c = this.counts || {}; const x = k => Number(c[k] || 0); return { all: Object.values(c).reduce((a, b) => a + Number(b), 0), waiting: x('Submitted') + x('Reviewed'), approved: x('Approved'), rejected: x('Rejected') }; },
    tabs() { const c = this.counts || {}; return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: this.n.all }, ...[['Draft', 'edit', 'slate'], ['Submitted', 'clock', 'amber'], ['Reviewed', 'eye-flat', 'sky'], ['Approved', 'tick', 'emerald'], ['Rejected', 'x-circle', 'rose']].map(([k, i, t]) => ({ key: k, label: this.$t(k), icon: i, tone: t, count: Number(c[k] || 0) }))]; },
  },
  methods: {
    open(id) { this.$inertia.visit(route('subcontracts.variations.show', id)); },
    go(live) {
      const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k] && k !== 'sort' && k !== 'dir') q[k] = this.f[k]; });
      if (this.filters.sort) { q.sort = this.filters.sort; q.dir = this.filters.dir || 'asc'; }
      if (live === true) {
        if ((this.f.search || '') === (this.filters.search || '')) return;
        this.loading = true;
        router.get(route('subcontracts.variations.index'), q, { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'counts', 'filters'], onFinish: () => { this.loading = false; } });
        return;
      }
      this.$inertia.get(route('subcontracts.variations.index'), q, { preserveState: true });
    },
    setStatus(s) { this.f.status = s; this.go(); },
  },
};
</script>
