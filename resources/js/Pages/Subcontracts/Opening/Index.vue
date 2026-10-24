<template>
  <admin-layout :title="$t('Opening Balances')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="folder" :title="$t('Opening Balances')" :subtitle="$t('Where every subcontractor account starts: the contract value and what was paid to date, as at a date, with the previous data attached.')">
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <export-buttons type="opening_balances" :params="f" />
        <button v-if="can.reset" type="button" class="lg-btn t-maroon" @click="openReset"><icons name="refresh" class="w-4 h-4" /><span>{{ $t('Reset To Opening Balances') }}</span></button>
      </page-header>

      <div class="sc-over sc-reg-cards">
        <overview-card icon="users" :title="$t('Accounts')" :sub="$t(':n with an opening balance', { n: counts.opening })" :value="String(counts.accounts)" :foot="$t('In Total')" />
        <overview-card variant="blue" icon="doc-text" :title="$t('Contract Value')" :sub="others('revised')" :value="main('revised')" :foot="$t('Opening Contract')" />
        <overview-card variant="green" icon="cash" :title="$t('Paid In Total')" :sub="others('paid_total')" :value="main('paid_total')" :foot="$t('Opening And Since')" />
        <overview-card variant="rose" icon="clock" :title="$t('Outstanding')" :sub="others('commitment_left')" :value="main('commitment_left')" :foot="$t('Still To Pay')" />
      </div>

      <div v-if="counts.detailed" class="lg-note lg-note-amber" style="margin-bottom: 10px"><icons name="info" class="w-4 h-4" /><span>{{ $t(':n accounts still carry their detailed payment lines. Use Reset To Opening Balances to start every account from its last amounts, or open an account and set its opening balance yourself.', { n: counts.detailed }) }}</span></div>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />
      <search-bar v-model="f.search" :placeholder="$t('Search by reference, title, project or subcontractor...')" :loading="loading" :count="rows.total" @submit="go(true)" />

      <section class="lg-card">
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr>
            <th><span class="sc-th"><icons name="users" class="w-4 h-4" />{{ $t('Subcontractor') }}</span></th>
            <th><span class="sc-th"><icons name="building" class="w-4 h-4" />{{ $t('Project') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-rev"><icons name="doc-text" class="w-4 h-4" />{{ $t('Contract') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Opening Paid') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Paid Since') }}</span></th>
            <th class="ac-num"><span class="sc-th amt-out"><icons name="clock" class="w-4 h-4" />{{ $t('Outstanding') }}</span></th>
            <th><span class="sc-th"><icons name="calendar" class="w-4 h-4" />{{ $t('As At') }}</span></th>
            <th class="ac-num"><span class="sc-th"><icons name="clip" class="w-4 h-4" />{{ $t('Documents') }}</span></th>
          </tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id" class="is-click" tabindex="0" @click="open(r)" @keydown.enter="open(r)">
              <td :data-label="$t('Subcontractor')"><span class="lg-main"><mark-hit :text="r.contact" :q="f.search" /></span><div class="lg-subtext"><mark-hit :text="r.reference + ' · ' + r.title" :q="f.search" /> <span class="lg-chip" :class="chip(r.status)">{{ $t(r.status) }}</span></div></td>
              <td :data-label="$t('Project')">{{ r.project || '-' }}</td>
              <td :data-label="$t('Contract')" class="ac-num"><span class="amt-rev">{{ m(r.contract, r.currency) }}</span></td>
              <td :data-label="$t('Opening Paid')" class="ac-num"><span class="amt-paid">{{ m(r.opening_paid, r.currency) }}</span><div v-if="r.lines > 1" class="lg-subtext">{{ r.lines }} {{ $t('lines') }}</div></td>
              <td :data-label="$t('Paid Since')" class="ac-num"><span class="amt-paid">{{ m(String(sub(r.paid_total, r.opening_paid)), r.currency) }}</span></td>
              <td :data-label="$t('Outstanding')" class="ac-num"><b :class="neg(r.outstanding) ? 'amt-neg' : 'amt-out'">{{ m(r.outstanding, r.currency) }}</b></td>
              <td :data-label="$t('As At')">{{ r.as_at ? $date(r.as_at) : '-' }}</td>
              <td :data-label="$t('Documents')" class="ac-num"><span class="sc-doc" :class="{ 'is-on': r.docs }"><icons name="clip" class="w-4 h-4" />{{ r.docs }}</span></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table></div>
      </section>
      <pager :rows="rows" />
    </div>

    <!-- reset -->
    <tec-dialog-modal :show="resetting" max-width="lg" @close="resetting = false">
      <template #title><span class="op-title"><icons name="refresh" class="w-5 h-5" />{{ $t('Reset To Opening Balances') }}</span></template>
      <template #content>
        <p v-if="!plan" class="lg-sub">{{ $t('Reading what the reset would do...') }}</p>
        <template v-else>
          <p class="lg-sub">{{ $t('Every account keeps its agreement, work schedule and imported workbook records. Its last amounts become its opening balance, and the payments and history are removed. A backup of what is removed is written first.') }}</p>
          <div class="op-cols">
            <div class="op-box op-keep"><b><icons name="tick" class="w-4 h-4" />{{ $t('Stays the same, to the cent') }}</b>
              <div v-for="(v, c) in plan.by_currency" :key="c" class="op-cur"><strong>{{ c }}</strong><span>{{ $t('Contract') }} {{ m(v.contract, c) }}</span><span>{{ $t('Paid') }} {{ m(v.paid, c) }}</span><span class="op-red">{{ $t('Outstanding') }} {{ m(v.outstanding, c) }}</span></div>
              <small>{{ plan.accounts }} {{ $t('accounts') }} · {{ plan.kept.work_items }} {{ $t('work items') }} · {{ plan.kept.work_groups }} {{ $t('groups') }}</small></div>
            <div class="op-box op-go"><b><icons name="trash" class="w-4 h-4" />{{ $t('Removed') }}</b>
              <div v-for="(c, k) in plan.counts" :key="k" class="op-line"><span>{{ $t(c.label) }}</span><b>{{ Number(c.rows).toLocaleString() }}</b></div>
              <small>{{ $t('Each account then gets ONE opening line with its paid to date.') }}</small></div>
          </div>
          <div v-if="plan.blockers.length" class="lg-note lg-note-red" style="margin-top: 10px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t(':n account(s) still have an approved request that is not paid, or a voucher in process. Finish or cancel those first; the reset is refused until then.', { n: plan.blockers.length }) }}</span></div>
          <div class="op-form">
            <label>{{ $t('Opening Date') }}<date-picker v-model="rf.as_at" flush /></label>
            <label>{{ $t('Type RESET To Confirm') }}<input v-model="rf.confirm" type="text" autocomplete="off" placeholder="RESET" /><span class="ac-err">{{ err('confirm') }}</span></label>
            <label>{{ $t('Your Password') }}<input v-model="rf.password" type="password" autocomplete="current-password" /><span class="ac-err">{{ err('password') }}</span></label>
          </div>
        </template>
      </template>
      <template #footer>
        <div class="flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="resetting = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="!canReset || busy" @click="doReset"><icons name="refresh" class="w-4 h-4" />{{ busy ? $t('Resetting...') : $t('Reset Now') }}</button>
        </div>
      </template>
    </tec-dialog-modal>
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
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import Pager from '@/Pages/Accounts/Pager.vue';
import { sc } from '../sc';

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
  components: { MarkHit, SearchBar, OverviewCard, ExportButtons, ScTabs, AdminLayout, PageHeader, Link, TecDialogModal, Pager },
  mixins: [sc],
  props: { rows: Object, totals: Object, filters: Object, counts: Object, can: Object, today: String },
  data() { return { f: { ...this.filters }, loading: false, timer: null, resetting: false, plan: null, busy: false, rf: { as_at: this.today, confirm: '', password: '' } }; },
  watch: { 'f.search'(v) { clearTimeout(this.timer); this.timer = setTimeout(() => this.go(true), v ? 300 : 0); } },
  beforeUnmount() { clearTimeout(this.timer); },
  computed: {
    cur() { return Object.keys(this.totals || {}); },
    tabs() { return [{ key: '', label: this.$t('Active'), icon: 'collection', tone: 'navy' }, { key: 'Archived', label: this.$t('Archived'), icon: 'folder', tone: 'slate' }, { key: 'Draft', label: this.$t('Draft'), icon: 'edit', tone: 'amber' }]; },
    canReset() { return !!this.plan && !this.plan.blockers.length && this.rf.confirm.trim().toUpperCase() === 'RESET' && !!this.rf.password && !!this.rf.as_at; },
  },
  methods: {
    main(k) { const c = this.cur[0]; return c ? this.m(this.totals[c][k], c) : '-'; },
    others(k) { return this.cur.slice(1).map(c => this.m(this.totals[c][k], c)).join(' · '); },
    sub(a, b) { const x = String(a).split('.'); const y = String(b).split('.'); const n = BigInt(x[0] + ((x[1] || '') + '00').slice(0, 2)) - BigInt(y[0] + ((y[1] || '') + '00').slice(0, 2)); const neg = n < 0n; const s = (neg ? -n : n).toString().padStart(3, '0'); return (neg ? '-' : '') + s.slice(0, -2) + '.' + s.slice(-2); },
    open(r) { this.$inertia.visit(route('subcontracts.opening.edit', r.id)); },
    setStatus(s) { this.f.status = s; this.go(); },
    go(live) {
      const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; });
      if (live === true) {
        if ((this.f.search || '') === (this.filters.search || '')) return;
        this.loading = true;
        router.get(route('subcontracts.opening.index'), q, { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'totals', 'counts', 'filters'], onFinish: () => { this.loading = false; } });
        return;
      }
      this.$inertia.get(route('subcontracts.opening.index'), q, { preserveState: true });
    },
    openReset() {
      this.resetting = true; this.plan = null; this.rf.confirm = ''; this.rf.password = '';
      window.axios.get(route('subcontracts.opening.reset-plan')).then(r => { this.plan = r.data; });
    },
    doReset() {
      this.busy = true;
      this.$inertia.post(route('subcontracts.opening.reset'), this.rf, { preserveScroll: true, onSuccess: () => { this.resetting = false; }, onFinish: () => { this.busy = false; this.rf.password = ''; } });
    },
  },
};
</script>

<style scoped>
.sc-doc { display: inline-flex; align-items: center; gap: 5px; font-weight: 800; color: var(--sc-mute); }
.sc-doc.is-on { color: #0b6f66; }
.op-title { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; }
.op-cols { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-top: 10px; }
.op-box { padding: 12px 14px; border-radius: 16px; display: grid; gap: 6px; align-content: start; }
.op-keep { background: rgba(16, 185, 129, 0.09); box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.4); }
.op-go { background: rgba(209, 26, 42, 0.07); box-shadow: inset 0 0 0 1px rgba(209, 26, 42, 0.3); }
.op-box > b { display: inline-flex; align-items: center; gap: 6px; }
.op-cur { display: grid; gap: 1px; font-size: 0.82rem; padding: 4px 0; } .op-cur strong { font-size: 0.9rem; }
.op-line { display: flex; justify-content: space-between; gap: 10px; font-size: 0.84rem; }
.op-red { color: #d11a2a; font-weight: 800; }
.op-box small { color: var(--sc-mute); }
.op-form { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-top: 14px; }
.op-form label { display: grid; gap: 4px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--sc-mute); }
.op-form input { height: 40px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--sc-line, #d1d5db); background: transparent; font-size: 0.92rem; text-transform: none; letter-spacing: 0; color: inherit; }
</style>
