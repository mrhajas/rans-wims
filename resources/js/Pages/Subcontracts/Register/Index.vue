<template>
  <admin-layout :title="$t('Subcontractor Register')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="users" :title="$t('Subcontractor Register')" :subtitle="$t('Subcontractors are Contacts in the SubContractors category. Add or change their details in Contacts; their agreements, payments and balances are here.')">
        <a v-if="newContactUrl" :href="newContactUrl" class="lg-btn lg-btn-soft"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Add a subcontractor in Contacts') }}</span></a>
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
              <export-buttons type="register" needs-money :money="money" />
      </page-header>
      <div class="sc-over sc-reg-cards">
        <overview-card icon="users" :title="$t('Subcontractors')" :sub="summary.agreements + ' ' + $t('agreements') + ' · ' + summary.payable + ' ' + $t('payable') + (summary.archived ? ' · ' + summary.archived + ' ' + $t('archived') : '')" :value="String(summary.subcontractors)" :foot="search ? $t('Matching Search') : $t('In Register')" />
        <template v-if="money">
          <overview-card variant="blue" icon="doc-text" :title="$t('Revised Value')" :sub="others('revised')" :value="main('revised')" :foot="$t('All Contracts')" />
          <overview-card variant="hero" icon="cash" :title="$t('Paid')" :sub="others('paid_total')" :value="main('paid_total')" :delta="paidPct + '%'" :foot="$t('Paid To Date')" />
          <overview-card variant="rose" icon="clock" :title="$t('Outstanding')" :sub="others('commitment_left')" :value="main('commitment_left')" :foot="$t('Still To Pay')" />
        </template>
      </div>

      <search-bar v-model="q" :placeholder="$t('Search by name, phone, email, location or agreement...')" :loading="loading" :count="rows.total" @submit="run" />

      <div class="lg-table-wrap"><table class="lg-table lg-responsive sc-reg">
        <thead><tr>
          <sort-th col="name" tone="key" icon="users" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Subcontractor') }}</sort-th>
          <sort-th col="contact" tone="text" icon="mobile" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Contact') }}</sort-th>
          <sort-th col="agreements" tone="date" num icon="doc-text" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Agreements') }}</sort-th>
          <sort-th v-if="money" col="revised" tone="money" num icon="doc-text" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Revised contract') }}</sort-th>
          <sort-th v-if="money" col="paid" tone="money" num icon="cash" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Paid') }}</sort-th>
          <sort-th v-if="money" col="outstanding" tone="num" num icon="clock" :sort="sortKey" :dir="sortDir" @sort="sortBy">{{ $t('Outstanding') }}</sort-th>
          <th class="hd-end"></th></tr></thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('subcontracts.register.show', r.id))">
            <td :data-label="$t('Subcontractor')"><div class="sc-who"><span class="sc-av" :class="'av-' + tone(r.id)">{{ initials(r.name) }}</span><div class="min-w-0"><span class="lg-main"><mark-hit :text="r.name" :q="q" /></span><div v-if="r.location" class="lg-subtext sc-ic"><icons name="map-pin" class="w-3 h-3" />{{ r.location }}</div></div></div></td>
            <td :data-label="$t('Contact')"><div v-if="r.phone" class="sc-ic"><icons name="mobile" class="w-3.5 h-3.5 ic-phone" />{{ r.phone }}</div><span v-else class="lg-subtext">-</span><div v-if="r.email" class="lg-subtext sc-ic"><icons name="email" class="w-3 h-3 ic-mail" />{{ r.email }}</div></td>
            <td :data-label="$t('Agreements')" class="ac-num"><span class="sc-pill pill-blue">{{ r.agreements }}</span> <span v-if="r.active" class="sc-pill pill-green">{{ r.active }} {{ $t('payable') }}</span> <span v-if="r.archived" class="sc-pill pill-slate"><icons name="archive-box" class="w-3 h-3" />{{ r.archived }}</span></td>
            <td v-if="money" :data-label="$t('Revised contract')" class="ac-num"><div v-for="(x, c) in r.figures" :key="c" class="amt-rev">{{ mc(x.revised, c) }}</div><span v-if="!Object.keys(r.figures || {}).length" class="lg-subtext">-</span></td>
            <td v-if="money" :data-label="$t('Paid')" class="ac-num"><div v-for="(x, c) in r.figures" :key="c" class="amt-paid">{{ mc(x.paid_total, c) }}<span class="sc-mini" :title="pct(x) + '%'"><i :style="{ width: Math.min(100, pct(x)) + '%' }"></i></span></div></td>
            <td v-if="money" :data-label="$t('Outstanding')" class="ac-num"><div v-for="(x, c) in r.figures" :key="c"><b :class="neg(x.commitment_left) ? 'amt-neg' : 'amt-out'">{{ mc(x.commitment_left, c) }}</b></div></td>
            <td class="lg-sticky-end" @click.stop><Link :href="route('subcontracts.register.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open the account')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
        </tbody>
      </table></div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import ExportButtons from '../ExportButtons.vue';
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

// the part of a name that matches the search is marked
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
  components: { SortTh, SearchBar, ExportButtons, OverviewCard, AdminLayout, PageHeader, Link, Pager, MarkHit },
  mixins: [sc, serverSort],
  props: { summary: Object, rows: Object, search: String, money: Boolean, newContactUrl: String },
  data() { return { q: this.search || '', loading: false, timer: null }; },
  computed: {
    cur() { return Object.keys(this.summary.figures || {}); },
    paidPct() { const f = (this.summary.figures || {})[this.cur[0]]; return f && Number(f.revised) > 0 ? Math.round((Number(f.paid_total) / Number(f.revised)) * 100) : 0; },
  },
  watch: {
    q(v) { clearTimeout(this.timer); this.timer = setTimeout(this.run, v === '' ? 0 : 300); }, // the list follows the typing
  },
  beforeUnmount() { clearTimeout(this.timer); },
  methods: {
    main(k) { const c = this.cur[0]; return c ? this.mc(this.summary.figures[c][k], c) : '-'; },
    others(k) { return this.cur.slice(1).map(c => this.mc(this.summary.figures[c][k], c)).join(' · '); },
    pct(x) { return Number(x.revised) > 0 ? Math.round((Number(x.paid_total) / Number(x.revised)) * 100) : 0; },
    initials(n) { return String(n || '?').replace(/[^A-Za-z0-9 ]/g, ' ').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?'; },
    tone(id) { return ['blue', 'green', 'violet', 'amber', 'rose', 'teal'][id % 6]; },
    run() {
      if (this.q === (this.search || '')) return;
      this.loading = true;
      router.get(route('subcontracts.register.index'), { ...(this.q ? { search: this.q } : {}), ...(this.filters && this.filters.sort ? { sort: this.filters.sort, dir: this.filters.dir } : {}) }, { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'summary', 'search', 'filters'], onFinish: () => { this.loading = false; } });
    },
  },
};
</script>
