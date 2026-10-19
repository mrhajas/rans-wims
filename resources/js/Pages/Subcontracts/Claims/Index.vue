<template>
  <admin-layout :title="$t('Contract Payment Requests')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="collection" :title="$t('Contract Payment Requests')" :subtitle="$t('One request for several completed contracts of a subcontractor, decided line by line and paid with one Payment Voucher. A request is not money paid.')">
        <Link v-if="can.create" :href="route('subcontracts.claims.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Contract Payment Request') }}</span></Link>
        <Link :href="route('subcontracts.requests.index')" class="lg-btn lg-btn-soft"><icons name="send" class="w-4 h-4" /><span>{{ $t('Payment Requests') }}</span></Link>
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <export-buttons type="claims" :params="exportParams" needs-money :money="money" />
      </page-header>

      <div class="sc-over sc-reg-cards">
        <overview-card variant="amber" icon="clock" :title="$t('Waiting for a decision')" :sub="$t('Requests with a line to decide')" :value="String(stats.waiting)" :foot="$t('Show Submitted')" :class="{ 'is-on': f.status === 'Submitted' }" role="button" tabindex="0" @click="setStatus('Submitted')" @keydown.enter="setStatus('Submitted')" />
        <overview-card v-if="money" icon="send" :title="$t('Requested')" :sub="$t('Submitted requests, per currency')" :value="first('requested')" :foot="$t('Show All')" role="button" tabindex="0" @click="setStatus('')" @keydown.enter="setStatus('')">
          <div v-for="c in rest" :key="'r' + c" class="cl-cur"><span>{{ c }}</span><b class="amt-rev">{{ mc(by[c].requested, c) }}</b></div>
        </overview-card>
        <overview-card v-if="money" variant="hero" icon="tick" :title="$t('Approved')" :sub="$t('Approved lines, per currency')" :value="first('approved')" :foot="$t('Show Approved')" :class="{ 'is-on': f.status === 'Approved' }" role="button" tabindex="0" @click="setStatus('Approved')" @keydown.enter="setStatus('Approved')">
          <div v-for="c in rest" :key="'a' + c" class="cl-cur"><span>{{ c }}</span><b class="amt-appr">{{ mc(by[c].approved, c) }}</b></div>
        </overview-card>
      </div>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />
      <search-bar v-model="f.search" :placeholder="$t('Search by reference, description, agreement or subcontractor...')" :loading="loading" :count="rows.total" @submit="go(true)" />
      <form class="lg-card" @submit.prevent="go()"><div class="ac-filters cl-filters">
        <div class="ac-f"><label>{{ $t('Subcontractor') }}</label><acct-select v-model="f.contact_id" :options="contacts" :placeholder="$t('Anyone')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}</label><acct-select v-model="f.warehouse_id" :options="projects" :placeholder="$t('Any')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies" :placeholder="$t('Any')" clearable /></div>
        <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
        <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
        <div class="ac-f sc-act"><label aria-hidden="true">&nbsp;</label><div class="cl-btnrow"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
      </div></form>

      <div class="lg-table-wrap"><table class="lg-table lg-responsive cl-table">
        <thead><tr>
          <sort-th col="reference" tone="key" icon="collection" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Request') }}</sort-th>
          <sort-th col="contact" tone="text" icon="users" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Subcontractor') }}</sort-th>
          <sort-th col="project" tone="text" icon="building" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Project') }}</sort-th>
          <sort-th col="date" tone="date" icon="calendar" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Date') }}</sort-th>
          <sort-th col="lines" tone="num" num icon="list" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Lines') }}</sort-th>
          <sort-th v-if="money" col="requested" tone="money" num icon="send" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Requested') }}</sort-th>
          <sort-th v-if="money" col="approved" tone="num" num icon="tick" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Approved') }}</sort-th>
          <sort-th v-if="money" tone="money" num icon="cash">{{ $t('Paid') }}</sort-th>
          <sort-th v-if="money" tone="money" num icon="clock">{{ $t('Left to pay') }}</sort-th>
          <sort-th col="status" tone="state" icon="tick" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Status') }}</sort-th>
          <th class="hd-end"></th></tr></thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id" class="is-click" tabindex="0" @click="open(r.id)" @keydown.enter="open(r.id)">
            <td :data-label="$t('Request')"><div class="sc-who"><span class="sc-av av-sq" :class="'av-' + tone(r.id)"><icons name="collection" class="w-4 h-4" /></span><div class="min-w-0"><span class="lg-main"><mark-hit :text="r.reference" :q="f.search" /></span><div class="lg-subtext">{{ r.by || '' }}</div></div></div></td>
            <td :data-label="$t('Subcontractor')"><mark-hit :text="r.contact" :q="f.search" /></td>
            <td :data-label="$t('Project')">{{ r.project || '-' }}</td>
            <td :data-label="$t('Date')" class="sc-nowrap">{{ r.date ? $date(r.date) : '-' }}</td>
            <td :data-label="$t('Lines')" class="ac-num"><div class="cl-cell"><b>{{ r.lines }}</b><div v-if="r.pending" class="lg-subtext">{{ r.pending }} {{ $t('waiting') }}</div></div></td>
            <td v-if="money" :data-label="$t('Requested')" class="ac-num"><span class="amt-rev">{{ mc(r.requested, r.currency) }}</span></td>
            <td v-if="money" :data-label="$t('Approved')" class="ac-num"><span v-if="r.approved !== null && Number(r.approved) > 0" class="amt-appr">{{ mc(r.approved, r.currency) }}</span><span v-else class="lg-subtext">-</span></td>
            <td v-if="money" :data-label="$t('Paid')" class="ac-num"><span v-if="r.paid !== null && Number(r.paid) > 0" class="amt-paid">{{ mc(r.paid, r.currency) }}</span><span v-else class="lg-subtext">-</span></td>
            <td v-if="money" :data-label="$t('Left to pay')" class="ac-num"><span v-if="r.left_to_pay !== null && Number(r.left_to_pay) > 0" class="amt-out">{{ mc(r.left_to_pay, r.currency) }}</span><span v-else class="lg-subtext">-</span></td>
            <td :data-label="$t('Status')"><div class="cl-cell"><span class="lg-chip" :class="chip(r.status)">{{ r.status_label }}</span><div v-if="r.progress && r.progress !== 'Unpaid'" class="cl-prog"><span class="sc-mini" :title="r.progress"><i :style="{ width: pct(r) + '%' }"></i></span><small class="lg-subtext">{{ $t(r.progress) }}</small></div></div></td>
            <td class="lg-sticky-end" @click.stop><Link :href="r.url" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" :colspan="money ? 11 : 7">{{ $t('There is no data to display.') }}</td></tr>
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
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
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
  components: { SortTh, MarkHit, ExportButtons, ScTabs, OverviewCard, SearchBar, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager },
  mixins: [sc, serverSort],
  props: { rows: Object, filters: Object, statuses: Array, counts: Object, stats: Object, contacts: Array, projects: Array, currencies: Array, money: Boolean, can: Object },
  data() { return { f: { ...this.filters }, loading: false, timer: null }; },
  watch: { 'f.search'(v) { clearTimeout(this.timer); this.timer = setTimeout(() => this.go(true), v ? 300 : 0); } }, // the list follows the typing
  beforeUnmount() { clearTimeout(this.timer); },
  computed: {
    by() { return (this.stats && this.stats.by_currency) || {}; },
    curs() { return Object.keys(this.by); },
    rest() { return this.curs.slice(1); },
    n() { const c = this.counts || {}; return { all: Object.values(c).reduce((a, b) => a + Number(b), 0) }; },
    exportParams() { const q = {}; ['contact_id', 'warehouse_id', 'currency', 'from', 'to', 'status'].forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); return q; },
    tabs() {
      const c = this.counts || {};
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: this.n.all },
        ...[['Draft', 'edit', 'slate'], ['Submitted', 'clock', 'amber'], ['Under Review', 'eye-flat', 'sky'], ['Partially Approved', 'check-double', 'teal'], ['Approved', 'tick', 'emerald'], ['Returned', 'refresh', 'violet'], ['Deferred', 'pause', 'amber'], ['Rejected', 'x-circle', 'rose'], ['Cancelled', 'x-circle', 'slate']]
          .map(([k, i, t]) => ({ key: k, label: this.$t(k), icon: i, tone: t, count: Number(c[k] || 0) }))];
    },
  },
  methods: {
    pct(r) { const a = Number(r.approved) || 0; return a > 0 ? Math.max(3, Math.min(100, Math.round((Number(r.paid) || 0) / a * 100))) : 0; },
    first(k) { const c = this.curs[0]; return c ? this.mc(this.by[c][k], c) : '-'; },
    tone(id) { return ['blue', 'green', 'violet', 'amber', 'rose', 'teal'][id % 6]; },
    open(id) { this.$inertia.visit(route('subcontracts.claims.show', id)); },
    go(live) {
      const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k] && k !== 'sort' && k !== 'dir') q[k] = this.f[k]; });
      if (this.filters.sort) { q.sort = this.filters.sort; q.dir = this.filters.dir || 'asc'; }
      if (live === true) {
        if ((this.f.search || '') === (this.filters.search || '')) return;
        this.loading = true;
        router.get(route('subcontracts.claims.index'), q, { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'counts', 'filters'], onFinish: () => { this.loading = false; } });
        return;
      }
      this.$inertia.get(route('subcontracts.claims.index'), q, { preserveState: true });
    },
    setStatus(s) { this.f.status = s; this.go(); },
    reset() { this.f = {}; this.go(); },
  },
};
</script>
