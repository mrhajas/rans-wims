<template>
  <admin-layout :title="$t('Subcontract Payment Requests')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="send" :title="$t('Subcontract Payment Requests')" :subtitle="$t('Requests are approved here and paid only through a Payment Voucher. A request that is submitted or approved is not money paid.')">
        <Link v-if="can.create" :href="route('subcontracts.requests.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Payment Request') }}</span></Link>
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <export-buttons type="requests" :params="f" needs-money :money="money" />
      </page-header>

      <div class="sc-over sc-reg-cards">
        <overview-card icon="send" :title="$t('Requests')" :sub="$t('Every status')" :value="String(n.all)" :foot="$t('Show All')" :class="{ 'is-on': !f.status }" role="button" tabindex="0" @click="setStatus('')" @keydown.enter="setStatus('')" />
        <overview-card variant="amber" icon="clock" :title="$t('Waiting')" :sub="$t('Submitted or under review')" :value="String(n.waiting)" :foot="$t('Show Waiting')" :class="{ 'is-on': f.status === 'Submitted' }" role="button" tabindex="0" @click="setStatus('Submitted')" @keydown.enter="setStatus('Submitted')" />
        <overview-card variant="hero" icon="tick" :title="$t('Approved')" :sub="$t('Ready for a voucher')" :value="String(n.approved)" :foot="$t('Show Approved')" :class="{ 'is-on': f.status === 'Approved' }" role="button" tabindex="0" @click="setStatus('Approved')" @keydown.enter="setStatus('Approved')" />
        <overview-card icon="refresh" :title="$t('Returned')" :sub="$t('Sent back for changes')" :value="String(n.returned)" :foot="$t('Show Returned')" :class="{ 'is-on': f.status === 'Returned' }" role="button" tabindex="0" @click="setStatus('Returned')" @keydown.enter="setStatus('Returned')" />
      </div>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />
      <search-bar v-model="f.search" :placeholder="$t('Search by reference, description, agreement or subcontractor...')" :loading="loading" :count="rows.total" @submit="go(true)" />
      <form class="lg-card" @submit.prevent="go()"><div class="ac-filters sc-filters">
        <div class="ac-f"><label>{{ $t('Subcontractor') }}</label><acct-select v-model="f.contact_id" :options="contacts" :placeholder="$t('Anyone')" clearable /></div>
        <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
        <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
        <div class="ac-f sc-act"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
      </div></form>

      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr>
          <sort-th col="reference" tone="key" icon="send" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Request') }}</sort-th>
          <sort-th col="contact" tone="text" icon="users" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Subcontractor') }}</sort-th>
          <sort-th col="agreement" tone="text" icon="doc-text" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Agreement') }}</sort-th>
          <sort-th col="date" tone="date" icon="calendar" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Date') }}</sort-th>
          <sort-th col="status" tone="state" icon="tick" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Status') }}</sort-th>
          <sort-th v-if="money" col="requested" tone="money" num icon="send" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Requested') }}</sort-th>
          <sort-th v-if="money" col="approved" tone="num" num icon="tick" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Approved') }}</sort-th>
          <th class="hd-end"></th></tr></thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id" class="is-click" tabindex="0" @click="open(r.id)" @keydown.enter="open(r.id)">
            <td :data-label="$t('Request')"><div class="sc-who"><span class="sc-av av-sq" :class="'av-' + tone(r.id)"><icons name="send" class="w-4 h-4" /></span><div class="min-w-0"><span class="lg-main"><mark-hit :text="r.reference" :q="f.search" /></span><div class="lg-subtext">{{ $t(kindLabel(r.kind)) }}</div></div></div></td>
            <td :data-label="$t('Subcontractor')"><mark-hit :text="r.contact" :q="f.search" /></td>
            <td :data-label="$t('Agreement')"><span @click.stop><Link :href="route('subcontracts.agreements.show', r.agreement_id)" class="lg-main"><mark-hit :text="r.agreement" :q="f.search" /></Link></span></td>
            <td :data-label="$t('Date')" class="sc-nowrap">{{ r.date ? $date(r.date) : '-' }}</td>
            <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r.status)">{{ r.status_label }}</span></td>
            <td v-if="money" :data-label="$t('Requested')" class="ac-num"><span class="amt-rev">{{ mc(r.requested, r.currency) }}</span></td>
            <td v-if="money" :data-label="$t('Approved')" class="ac-num"><span v-if="r.approved !== null" class="amt-appr">{{ mc(r.approved, r.currency) }}</span><span v-else class="lg-subtext">-</span></td>
            <td class="lg-sticky-end" @click.stop><Link :href="route('subcontracts.requests.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" :colspan="money ? 8 : 6">{{ $t('There is no data to display.') }}</td></tr>
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
  props: { rows: Object, filters: Object, statuses: Array, counts: Object, contacts: Array, money: Boolean, can: Object },
  data() { return { f: { ...this.filters }, loading: false, timer: null }; },
  watch: { 'f.search'(v) { clearTimeout(this.timer); this.timer = setTimeout(() => this.go(true), v ? 300 : 0); } }, // the list follows the typing
  beforeUnmount() { clearTimeout(this.timer); },
  computed: {
    n() { const c = this.counts || {}; const x = k => Number(c[k] || 0); return { all: Object.values(c).reduce((a, b) => a + Number(b), 0), waiting: x('Submitted') + x('Under Review'), approved: x('Approved'), returned: x('Returned') }; },
    tabs() { const c = this.counts || {}; return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: this.n.all }, ...[['Draft', 'edit', 'slate'], ['Submitted', 'clock', 'amber'], ['Under Review', 'eye-flat', 'sky'], ['Returned', 'refresh', 'violet'], ['Approved', 'tick', 'emerald'], ['Rejected', 'x-circle', 'rose'], ['Cancelled', 'x-circle', 'slate']].map(([k, i, t]) => ({ key: k, label: this.$t(k), icon: i, tone: t, count: Number(c[k] || 0) }))]; },
  },
  methods: {
    kindLabel(k) { return { work: 'Work done', advance: 'Advance payment', variation: 'Variation', other: 'Other' }[k] || k; },
    tone(id) { return ['blue', 'green', 'violet', 'amber', 'rose', 'teal'][id % 6]; },
    open(id) { this.$inertia.visit(route('subcontracts.requests.show', id)); },
    go(live) {
      const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k] && k !== 'sort' && k !== 'dir') q[k] = this.f[k]; });
      if (this.filters.sort) { q.sort = this.filters.sort; q.dir = this.filters.dir || 'asc'; }
      if (live === true) {
        if ((this.f.search || '') === (this.filters.search || '')) return;
        this.loading = true;
        router.get(route('subcontracts.requests.index'), q, { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'counts', 'filters'], onFinish: () => { this.loading = false; } });
        return;
      }
      this.$inertia.get(route('subcontracts.requests.index'), q, { preserveState: true });
    },
    setStatus(s) { this.f.status = s; this.go(); },
    reset() { this.f = {}; this.go(); },
  },
};
</script>

<style scoped>
.sc-filters .sc-act > div { display: flex; gap: 10px; }
@media (min-width: 1100px) { .sc-filters { grid-template-columns: repeat(3, minmax(0, 1fr)) auto; } }
</style>
