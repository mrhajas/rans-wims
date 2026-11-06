<template>
  <admin-layout :title="$t('Archived records')">
    <div class="lg-page px-4 md:px-0 wlb-page">
      <page-header icon="doc-text" :title="$t('Archived records')" :subtitle="$t('Every record that was moved out of the live database, by log. Search them, filter by person, open any record to read it exactly as it was, or put records back.')">
        <Link :href="route('wims-logs.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('WIMS Logs') }}</Link>
        <template v-if="current && current.rows">
          <a :href="report('wims-logs.browse.print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a :href="report('wims-logs.browse.pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
          <a :href="report('wims-logs.browse.download')" class="lg-btn lg-btn-primary"><icons name="table" class="w-4 h-4" />{{ $t('Download CSV') }}</a>
        </template>
      </page-header>

      <!-- a part of the page that could not be loaded: said here, so it can be fixed -->
      <section v-if="loadErrors && loadErrors.length" class="wl-loaderr" role="alert">
        <span class="wl-loaderr-ico"><icons name="exclamation" class="w-5 h-5" /></span>
        <div>
          <b>{{ $t('Part of this page could not be loaded') }}</b>
          <p>{{ $t('The rest works. Send these lines to the developer; they say exactly what failed.') }}</p>
          <ul><li v-for="(e, i) in loadErrors" :key="i"><strong>{{ e.part }}</strong>: {{ e.error }} <small>({{ e.where }})</small></li></ul>
        </div>
      </section>

      <stat-tiles v-if="current" :items="tiles" compact />

      <!-- one tab per log -->
      <nav class="wlb-tabs" :aria-label="$t('Logs')">
        <button v-for="l in logs" :key="l.key" type="button" class="wlb-tab" :class="{ 'is-on': l.key === source, 'is-empty': !l.rows }" :style="{ '--tone': look(l.key).tone, '--tone2': look(l.key).tone2 }" :aria-pressed="l.key === source" @click="visit({ source: l.key, page: 1 })">
          <span class="wlb-tab-ico"><icons :name="look(l.key).icon" class="w-5 h-5" /></span>
          <span class="wlb-tab-body">
            <b>{{ l.label }}</b>
            <span class="wlb-tab-n">{{ num(l.rows) }} {{ $t('records') }}<em v-if="l.rows" class="wlb-tab-save" :title="$t('Saved by compression')">-{{ savedPct(l) }}%</em></span>
            <small v-if="l.rows">{{ fmt(l.original) }} &gt; {{ fmt(l.stored) }}</small>
            <small v-else>{{ $t('Nothing archived yet') }}</small>
          </span>
        </button>
      </nav>

      <section class="lg-card">
        <header class="lg-card-head wlb-head">
          <span class="lg-card-ico wlb-head-ico" :style="{ '--tone': look(source).tone, '--tone2': look(source).tone2 }"><icons :name="look(source).icon" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ current ? current.label : '' }}</h2>
            <p class="lg-card-sub">
              {{ $t(':n record(s)', { n: num(total) }) }}<template v-if="q"> {{ $t('matching') }} "{{ q }}"</template>
              <template v-if="current && current.date_from"> · {{ $t('from :a to :b', { a: day(current.date_from), b: day(current.date_to) }) }}</template>
            </p>
          </div>
        </header>

        <!-- find: by words, and by person -->
        <div class="wlb-filters">
          <form class="wlb-search" @submit.prevent="visit({ page: 1, q: term.trim() })"><icons :name="busy ? 'refresh' : 'search'" class="w-4 h-4" :class="{ 'wlb-spin': busy }" /><input v-model="term" type="search" :placeholder="$t('Search the id, the date or the description')" @input="liveSearch" /><button v-if="term" type="button" class="wlb-x" :aria-label="$t('Clear the search')" @click="clearTerm"><icons name="cross" class="w-3.5 h-3.5" /></button></form>
          <div v-if="users.length" class="wlb-user">
            <label>{{ $t('Person') }}</label>
            <css-select v-model="userSel" class="wlb-user-sel" @change="visit({ page: 1, user: userSel })">
              <option value="">{{ $t('All people') }}</option>
              <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }} ({{ num(u.n) }})</option>
            </css-select>
          </div>
          <button v-if="q || userSel !== ''" type="button" class="lg-btn tone wlb-clear" style="--tone: #e11d48" @click="clearFilters"><span class="tone-ico"><icons name="cross" class="w-3.5 h-3.5" /></span>{{ $t('Clear filters') }}</button>
        </div>
        <div class="wlb-bar" :class="{ on: busy }" aria-hidden="true"><i></i></div>

        <div v-if="!records.length" class="wlb-empty-box">
          <span class="wlb-empty-ico" :style="{ '--tone': look(source).tone, '--tone2': look(source).tone2 }"><icons :name="look(source).icon" class="w-6 h-6" /></span>
          <b>{{ current && !current.rows ? $t('Nothing has been archived from this log yet.') : $t('No record found.') }}</b>
          <small v-if="current && !current.rows">{{ $t('Records appear here after WIMS Logs has run. Run it from the WIMS Logs page.') }}</small>
          <Link v-if="current && !current.rows" :href="route('wims-logs.index')" class="lg-btn lg-btn-primary wlb-sm">{{ $t('Go to WIMS Logs') }}</Link>
        </div>
        <div v-else class="wlb-results" :class="{ 'is-busy': busy }" :aria-busy="busy">
          <archived-records-list v-model:selected="selected" :records="records" :can-run="canRun" @read="open" @restore="askRestore" />
        </div>
        <div v-if="pages > 1" class="wlb-pager">
          <button type="button" class="lg-btn lg-btn-soft wlb-sm" :disabled="page <= 1" @click="visit({ page: page - 1 })">{{ $t('Previous') }}</button>
          <span>{{ $t('Page :a of :b', { a: page, b: pages }) }}</span>
          <button type="button" class="lg-btn lg-btn-soft wlb-sm" :disabled="page >= pages" @click="visit({ page: page + 1 })">{{ $t('Next') }}</button>
        </div>
      </section>

      <!-- one record, whole -->
      <archived-record-view :show="rec.open" :source="source" :label="current ? current.label : ''" :record-id="rec.id" :summary="rec.summary" :row="rec.row" :loading="rec.loading" :error="rec.error"
        :user="rec.user" :restored="rec.restored" :can-restore="canRun && rec.canRestore" :has-prev="rec.index > 0" :has-next="rec.index < records.length - 1" :position="rec.index + 1" :total="records.length"
        @nav="step" @restore="askRestore([rec.item])" @close="rec.open = false" />

      <restore-records-dialog :show="rs.open" :label="rs.label" :busy="rs.busy" :error="rs.error" :result="rs.result" @confirm="doRestore" @close="closeRestore" />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import ArchivedRecordView from '@/Shared/ArchivedRecordView.vue';
import ArchivedRecordsList from '@/Shared/ArchivedRecordsList.vue';
import RestoreRecordsDialog from '@/Shared/RestoreRecordsDialog.vue';
import { logLook } from '@/Core/wimsLogLook';
import { Link } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';

export default {
  components: { AdminLayout, PageHeader, StatTiles, ArchivedRecordView, ArchivedRecordsList, RestoreRecordsDialog, Link },
  props: { logs: Array, loadErrors: { type: Array, default: () => [] }, source: String, records: Array, total: Number, page: Number, per: Number, pages: Number, q: String, users: { type: Array, default: () => [] }, user: { type: Number, default: null }, canRun: Boolean },
  data() {
    return {
      busy: false, term: this.q || '', userSel: this.user === null ? '' : String(this.user), selected: [],
      rec: { open: false, id: '', index: 0, item: null, summary: '', loading: false, error: '', row: {}, user: null, restored: false, canRestore: false },
      rs: { open: false, label: '', items: [], busy: false, error: '', result: null },
    };
  },
  computed: {
    current() { return this.logs.find(l => l.key === this.source); },
    tiles() {
      const c = this.current || {};
      const saved = Math.max(0, (c.original || 0) - (c.stored || 0));
      return [
        { icon: 'doc-text', tone: 'orange', label: this.$t('Records archived'), value: this.num(c.rows) },
        { icon: 'database', tone: 'violet', label: this.$t('Before'), value: this.fmt(c.original) },
        { icon: 'archive-box', tone: 'sky', label: this.$t('On disk now'), value: this.fmt(c.stored) },
        { icon: 'tick', tone: 'forest', label: this.$t('Saved'), value: this.fmt(saved) + (c.original ? ' (' + Math.round((saved / c.original) * 100) + '%)' : '') },
      ];
    },
  },
  watch: { records() { this.selected = []; } },
  methods: {
    // each log has its own colour and icon
    look: logLook,
    savedPct(l) { return l.original > 0 ? Math.max(0, Math.round((1 - l.stored / l.original) * 100)) : 0; },
    report(name) { return this.route(name, { source: this.source, q: this.q || undefined }); },
    fmt(b) {
      b = Number(b || 0);
      const u = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return (i === 0 ? String(Math.round(b)) : b.toFixed(b < 10 ? 2 : 1)) + ' ' + u[i];
    },
    num(n) { return Number(n || 0).toLocaleString(); },
    day(d) { return d ? String(d).slice(0, 10) : '-'; },
    visit(change) {
      const switching = change.source && change.source !== this.source;
      if (switching) { this.term = ''; this.userSel = ''; }
      const q = switching ? undefined : ('q' in change ? change.q : this.q) || undefined;
      const user = switching ? undefined : ('user' in change ? change.user : this.userSel);
      const params = { source: change.source || this.source, page: change.page || 1, q, user: user === '' || user === undefined || user === null ? undefined : user };
      this.$inertia.get(this.route('wims-logs.browse'), params, { preserveScroll: !switching, preserveState: true, replace: true, headers: { 'X-Quiet': '1' }, onStart: () => { this.busy = true; }, onFinish: () => { this.busy = false; } });
    },
    clearTerm() { this.term = ''; this.visit({ page: 1, q: '' }); },
    clearFilters() { this.term = ''; this.userSel = ''; this.visit({ page: 1, q: '', user: '' }); },
    // the list follows the typing: one request when it pauses
    liveSearch: debounce(function () { this.visit({ page: 1, q: this.term.trim() }); }, 250),
    open(r) {
      const index = Math.max(0, this.records.indexOf(r));
      this.rec = { open: true, id: r.id, index, item: r, summary: r.summary, loading: true, error: '', row: {}, user: null, restored: !!r.restored, canRestore: false };
      window.axios.get(this.route('wims-logs.archives.record', [r.archive, r.id]), { headers: { 'X-Quiet': '1' } })
        .then(res => { if (this.rec.id === r.id) { this.rec.row = res.data.row; this.rec.user = res.data.user; this.rec.restored = res.data.restored; this.rec.canRestore = res.data.can_restore; } })
        .catch(() => { if (this.rec.id === r.id) this.rec.error = this.$t('The record could not be read.'); })
        .finally(() => { if (this.rec.id === r.id) this.rec.loading = false; });
    },
    // previous / next record of the list that is on the page
    step(d) { const r = this.records[this.rec.index + d]; if (r) this.open(r); },
    // ---- putting records back
    askRestore(items) {
      const list = (items || []).filter(Boolean);
      if (!list.length) return;
      this.rs = { open: true, items: list, busy: false, error: '', result: null, label: list.length === 1 ? '#' + list[0].id + ' · ' + (this.current ? this.current.label : '') : this.$t(':n records', { n: list.length }) + ' · ' + (this.current ? this.current.label : '') };
    },
    closeRestore() { const done = !!this.rs.result; this.rs.open = false; if (done) { this.rec.open = false; this.selected = []; this.$inertia.reload({ preserveScroll: true, preserveState: true, headers: { 'X-Quiet': '1' } }); } },
    // one request per archive the records come from
    async doRestore(password) {
      this.rs.busy = true;
      this.rs.error = '';
      const by = {};
      this.rs.items.forEach(r => { (by[r.archive] = by[r.archive] || []).push(String(r.id)); });
      const sum = { restored: 0, already_live: 0, previously: 0, missing: 0 };
      try {
        for (const [archive, ids] of Object.entries(by)) {
          const { data } = await window.axios.post(this.route('wims-logs.archives.restore', archive), { ids, password }, { headers: { 'X-Quiet': '1' } });
          Object.keys(sum).forEach(k => { sum[k] += data[k] || 0; });
        }
        this.rs.result = sum;
      } catch (e) {
        const er = e.response && e.response.data && e.response.data.errors;
        this.rs.error = (er && ((er.password && er.password[0]) || (er.ids && er.ids[0]))) || (e.response && e.response.data && e.response.data.message) || this.$t('The records could not be put back.');
      } finally {
        this.rs.busy = false;
      }
    },
  },
};
</script>

<style scoped>
.wlb-tabs { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 0.9rem; margin: 1.1rem 0 1.3rem; }
.wlb-tab { position: relative; display: flex; align-items: center; gap: 0.9rem; text-align: left; padding: 0.95rem 1rem; border-radius: 1.2rem; border: 1.5px solid color-mix(in srgb, var(--tone) 28%, transparent); background: linear-gradient(135deg, color-mix(in srgb, var(--tone) 12%, transparent), color-mix(in srgb, var(--tone2) 6%, transparent)); cursor: pointer; transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease, background 0.25s ease; }
.wlb-tab-ico { flex: none; width: 2.8rem; height: 2.8rem; display: grid; place-items: center; border-radius: 0.95rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 6px 14px -6px var(--tone); transition: transform 0.25s ease; }
.wlb-tab-body { display: grid; gap: 0.25rem; min-width: 0; } .wlb-tab-body b { font-size: 0.88rem; line-height: 1.25; } .wlb-tab-body small { font-size: 0.72rem; color: #64748b; }
.wlb-tab-n { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: 0.82rem; font-weight: 800; color: var(--tone); }
.wlb-tab-save { font-style: normal; font-size: 0.66rem; font-weight: 900; padding: 0.08rem 0.45rem; border-radius: 999px; color: var(--tone); background: color-mix(in srgb, var(--tone) 14%, transparent); }
.wlb-tab:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -14px var(--tone); border-color: var(--tone); } .wlb-tab:hover .wlb-tab-ico { transform: scale(1.06) rotate(-4deg); }
.wlb-tab.is-on { background: linear-gradient(135deg, var(--tone), var(--tone2)); border-color: transparent; box-shadow: 0 14px 28px -14px var(--tone); } .wlb-tab.is-on b, .wlb-tab.is-on .wlb-tab-n, .wlb-tab.is-on small { color: #fff; } .wlb-tab.is-on .wlb-tab-ico { background: rgba(255, 255, 255, 0.22); box-shadow: none; } .wlb-tab.is-on .wlb-tab-save { color: #fff; background: rgba(255, 255, 255, 0.22); }
.wlb-tab.is-empty:not(.is-on) { opacity: 0.7; }
.wlb-tab:focus-visible { outline: 3px solid color-mix(in srgb, var(--tone) 50%, transparent); outline-offset: 2px; }
.wlb-head-ico { color: #fff !important; background: linear-gradient(135deg, var(--tone), var(--tone2)) !important; }
.wlb-sm { padding: 0.35rem 0.8rem !important; font-size: 0.76rem !important; }
.wlb-empty-box { display: grid; justify-items: center; gap: 0.45rem; padding: 2.2rem 1rem; text-align: center; } .wlb-empty-box small { color: #64748b; max-width: 26rem; }
.wlb-empty-ico { width: 3.4rem; height: 3.4rem; display: grid; place-items: center; border-radius: 1.1rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 10px 22px -10px var(--tone); }
.wlb-pager { display: flex; align-items: center; justify-content: center; gap: 0.9rem; margin-top: 1rem; font-size: 0.8rem; }
.wlb-bar { height: 3px; margin: 0 0 0.7rem; border-radius: 999px; overflow: hidden; opacity: 0; transition: opacity 0.2s ease; } .wlb-bar.on { opacity: 1; }
.wlb-bar i { display: block; width: 38%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, transparent, #6366f1, #14b8a6, transparent); animation: wlb-travel 0.9s ease-in-out infinite; } @keyframes wlb-travel { from { transform: translateX(-110%); } to { transform: translateX(290%); } }
.wlb-results { transition: opacity 0.2s ease; } .wlb-results.is-busy { opacity: 0.55; pointer-events: none; }
.wlb-spin { animation: wlb-rot 0.8s linear infinite; } @keyframes wlb-rot { to { transform: rotate(360deg); } }
@media (max-width: 640px) { .wlb-tabs { grid-template-columns: 1fr 1fr; gap: 0.6rem; } .wlb-tab { padding: 0.7rem; gap: 0.55rem; } .wlb-tab-ico { width: 2.2rem; height: 2.2rem; } .wlb-user { flex: 1 1 100%; } }
@media (prefers-reduced-motion: reduce) { .wlb-tab, .wlb-tab-ico { transition: none; } .wlb-tab:hover, .wlb-tab:hover .wlb-tab-ico { transform: none; } .wlb-bar i, .wlb-spin { animation: none; } }

/* ---- the search, the person dropdown and the buttons: one height, one look ---- */
.wlb-filters { display: flex; align-items: flex-end; gap: 0.9rem; flex-wrap: wrap; margin: 0.2rem 0 1.1rem; }
.wlb-search { display: flex; align-items: center; gap: 0.6rem; flex: 1 1 20rem; min-width: 0; height: 2.9rem; padding: 0 0.5rem 0 1rem; border-radius: 1rem; border: 1.5px solid #e2e8f0; background: var(--lg-card, #fff); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); transition: border-color 0.2s ease, box-shadow 0.2s ease; }
.wlb-search:hover { border-color: #c7d2fe; } .wlb-search:focus-within { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.14); } .wlb-search > svg { flex: none; color: #6366f1; }
.wlb-search input { flex: 1; min-width: 0; height: 100%; padding: 0 !important; border: 0 !important; outline: 0 !important; box-shadow: none !important; background: transparent !important; font-size: 0.9rem; font-weight: 600; -webkit-appearance: none; appearance: none; color: inherit; }
.wlb-search input::-webkit-search-cancel-button, .wlb-search input::-webkit-search-decoration { display: none; -webkit-appearance: none; } .wlb-search input::placeholder { font-weight: 500; color: #94a3b8; }
html.dark .wlb-search { border-color: rgba(255, 255, 255, 0.14); }
.wlb-user { display: grid; gap: 0.3rem; flex: 0 1 17rem; min-width: 12rem; } .wlb-user label { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; } .wlb-user-sel { width: 100%; }
.wlb-user :deep(.cs-btn) { min-height: 2.9rem; width: 100%; padding: 0 0.9rem; border-radius: 1rem; border: 1.5px solid #e2e8f0; background: var(--lg-card, #fff); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); font-size: 0.9rem; font-weight: 700; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
.wlb-user :deep(.cs-btn:hover) { border-color: #c7d2fe; } .wlb-user :deep(.cs-btn.is-open), .wlb-user :deep(.cs-btn:focus-visible) { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.14); outline: none; }
.wlb-user :deep(.cs-btn.is-empty .cs-txt) { color: inherit; opacity: 1; } .wlb-user :deep(.cs-caret) { color: #6366f1; }
html.dark .wlb-user :deep(.cs-btn) { border-color: rgba(255, 255, 255, 0.14); }
.wlb-clear { height: 2.9rem; padding-right: 1rem !important; border-radius: 1rem !important; font-weight: 800; transition: transform 0.2s ease, box-shadow 0.2s ease; } .wlb-clear:hover { transform: translateY(-2px); }
@media (max-width: 640px) { .wlb-user { flex: 1 1 100%; } .wlb-clear { flex: 1 1 100%; justify-content: center; } }
@media (prefers-reduced-motion: reduce) { .wlb-x, .wlb-clear, .wlb-search { transition: none; } }

/* the clear (x) of the search: a true circle whatever the global button rules say, the icon dead centre, no rotation */
.wlb-x { flex: none; box-sizing: border-box; width: 1.9rem !important; height: 1.9rem !important; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; margin: 0 !important; aspect-ratio: 1 / 1; border: 0; border-radius: 50% !important; display: grid; place-items: center; line-height: 1; cursor: pointer; color: #64748b; background: rgba(148, 163, 184, 0.18); transition: transform 0.2s cubic-bezier(0.3, 1.5, 0.5, 1), background 0.2s ease, color 0.2s ease; }
.wlb-x svg, .wlb-x > div { display: block; margin: 0 !important; } .wlb-x:hover { color: #fff; background: #e11d48; transform: scale(1.1); } .wlb-x:active { transform: scale(0.92); } .wlb-x:focus-visible { outline: 3px solid rgba(225, 29, 72, 0.35); outline-offset: 2px; }

.wl-loaderr { display: flex; gap: 0.9rem; align-items: flex-start; margin: 0 0 1rem; padding: 0.9rem 1rem; border-radius: 1.1rem; border: 1.5px solid rgba(220, 38, 38, 0.35); background: rgba(220, 38, 38, 0.08); color: #7f1d1d; } html.dark .wl-loaderr { color: #fecaca; }
.wl-loaderr-ico { flex: none; width: 2.4rem; height: 2.4rem; display: grid; place-items: center; border-radius: 0.85rem; color: #fff; background: linear-gradient(135deg, #f87171, #dc2626); } .wl-loaderr b { display: block; font-size: 0.95rem; } .wl-loaderr p { margin: 0.15rem 0 0.5rem; font-size: 0.8rem; opacity: 0.85; }
.wl-loaderr ul { margin: 0; padding-left: 1.1rem; font-size: 0.78rem; line-height: 1.5; overflow-wrap: anywhere; } .wl-loaderr small { opacity: 0.7; }
</style>
