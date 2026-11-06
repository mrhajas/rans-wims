<template>
  <admin-layout :title="$t('Archived records')">
    <div class="lg-page px-4 md:px-0 wlb-page">
      <page-header icon="doc-text" :title="$t('Archived records')" :subtitle="$t('Every record that was moved out of the live database, by log. Search them, open any record to read it exactly as it was, or download a whole archive.')">
        <Link :href="route('wims-logs.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('WIMS Logs') }}</Link>
        <template v-if="current && current.rows">
          <a :href="report('wims-logs.browse.print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a :href="report('wims-logs.browse.pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
          <a :href="report('wims-logs.browse.download')" class="lg-btn lg-btn-primary"><icons name="table" class="w-4 h-4" />{{ $t('Download CSV') }}</a>
        </template>
      </page-header>

      <stat-tiles v-if="current" :items="tiles" compact />

      <!-- one tab per log -->
      <nav class="wlb-tabs" :aria-label="$t('Logs')">
        <button v-for="l in logs" :key="l.key" type="button" class="wlb-tab" :class="{ 'is-on': l.key === source, 'is-empty': !l.rows }" :style="{ '--tone': look(l.key).tone, '--tone2': look(l.key).tone2 }" :aria-pressed="l.key === source" @click="visit({ source: l.key, page: 1 })">
          <span class="wlb-tab-ico"><icons :name="look(l.key).icon" class="w-5 h-5" /></span>
          <span class="wlb-tab-body">
            <b>{{ l.label }}</b>
            <span class="wlb-tab-n">{{ num(l.rows) }} {{ $t('records') }}</span>
            <small v-if="l.rows">{{ fmt(l.original) }} &gt; {{ fmt(l.stored) }}</small>
            <small v-else>{{ $t('Nothing archived yet') }}</small>
          </span>
          <span v-if="l.rows" class="wlb-tab-save" :title="$t('Saved by compression')">-{{ savedPct(l) }}%</span>
        </button>
      </nav>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico wlb-head-ico" :style="{ '--tone': look(source).tone, '--tone2': look(source).tone2 }"><icons :name="look(source).icon" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ current ? current.label : '' }}</h2>
            <p class="lg-card-sub">
              {{ $t(':n record(s)', { n: num(total) }) }}<template v-if="q"> {{ $t('matching') }} "{{ q }}"</template>
              <template v-if="current && current.date_from"> · {{ $t('from :a to :b', { a: day(current.date_from), b: day(current.date_to) }) }}</template>
            </p>
          </div>
          <form class="wlb-search" @submit.prevent="visit({ page: 1, q: term.trim() })"><icons :name="busy ? 'refresh' : 'search'" class="w-4 h-4" :class="{ 'wlb-spin': busy }" /><input v-model="term" type="search" :placeholder="$t('Search the id, the date or the description')" @input="liveSearch" /><button type="submit" class="lg-btn lg-btn-soft wlb-sm">{{ $t('Search') }}</button><button v-if="q" type="button" class="lg-btn lg-btn-soft wlb-sm" @click="term = ''; visit({ page: 1, q: '' })">{{ $t('Clear') }}</button></form>
        </header>
        <div class="wlb-bar" :class="{ on: busy }" aria-hidden="true"><i></i></div>
        <div v-if="!records.length" class="wlb-empty-box">
          <span class="wlb-empty-ico" :style="{ '--tone': look(source).tone, '--tone2': look(source).tone2 }"><icons :name="look(source).icon" class="w-6 h-6" /></span>
          <b>{{ current && !current.rows ? $t('Nothing has been archived from this log yet.') : $t('No record found.') }}</b>
          <small v-if="current && !current.rows">{{ $t('Records appear here after WIMS Logs has run. Run it from the WIMS Logs page.') }}</small>
          <Link v-if="current && !current.rows" :href="route('wims-logs.index')" class="lg-btn lg-btn-primary wlb-sm">{{ $t('Go to WIMS Logs') }}</Link>
        </div>
        <div v-else class="lg-table-wrap wlb-results" :class="{ 'is-busy': busy }" :aria-busy="busy">
          <table v-labelize class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('No') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Record') }}</th><th>{{ $t('What it was') }}</th><th>{{ $t('Archive') }}</th><th>{{ $t('Size') }}</th><th>{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in records" :key="r.archive + '-' + r.id" class="wlb-row" :style="{ '--i': Math.min(i, 12) }">
                <td class="wlb-nw">{{ (page - 1) * per + i + 1 }}</td>
                <td class="wlb-nw">{{ r.at ? $datetime(r.at) : '-' }}</td>
                <td class="wlb-nw"><b>{{ r.id }}</b></td>
                <td class="wlb-desc"><span v-if="parts(r.summary).head" class="wlb-chip" :style="{ '--tone': look(source).tone }">{{ parts(r.summary).head }}</span>{{ parts(r.summary).rest }}</td>
                <td class="wlb-nw"><Link :href="route('wims-logs.archives.show', r.archive)">{{ r.ref }}</Link></td>
                <td class="wlb-nw">{{ fmt(r.original) }} &gt; {{ fmt(r.compressed) }}</td>
                <td><button type="button" class="lg-btn lg-btn-soft wlb-sm" @click="open(r)"><icons name="search" class="w-4 h-4" />{{ $t('Read') }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="pages > 1" class="wlb-pager">
          <button type="button" class="lg-btn lg-btn-soft wlb-sm" :disabled="page <= 1" @click="visit({ page: page - 1 })">{{ $t('Previous') }}</button>
          <span>{{ $t('Page :a of :b', { a: page, b: pages }) }}</span>
          <button type="button" class="lg-btn lg-btn-soft wlb-sm" :disabled="page >= pages" @click="visit({ page: page + 1 })">{{ $t('Next') }}</button>
        </div>
      </section>

      <!-- one record, whole -->
      <modal :show="rec.open" max-width="3xl" :closeable="true" @close="rec.open = false">
        <div class="wlb-rec">
          <header class="wlb-rec-head">
            <div class="min-w-0"><b>{{ current ? current.label : '' }} · {{ rec.id }}</b><small>{{ $t('The record exactly as it was in the database') }}</small></div>
            <button type="button" class="lg-btn lg-btn-soft wlb-sm" @click="rec.open = false">{{ $t('Close') }}</button>
          </header>
          <p v-if="rec.loading" class="wlb-empty">{{ $t('Reading...') }}</p>
          <p v-else-if="rec.error" class="wlb-empty is-err">{{ rec.error }}</p>
          <dl v-else class="wlb-cols">
            <div v-for="(v, k) in rec.row" :key="k"><dt>{{ k }}</dt><dd><pre>{{ show(v) }}</pre></dd></div>
          </dl>
        </div>
      </modal>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Modal from '@/Jetstream/Modal.vue';
import { Link } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';

export default {
  components: { AdminLayout, PageHeader, StatTiles, Modal, Link },
  props: { logs: Array, source: String, records: Array, total: Number, page: Number, per: Number, pages: Number, q: String },
  data() {
    return { busy: false, term: this.q || '', rec: { open: false, id: '', loading: false, error: '', row: {} } };
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
  methods: {
    // each log has its own colour and icon
    look(key) {
      return ({
        weather: { icon: 'cloud', tone: '#0284c7', tone2: '#38bdf8' },
        delivery: { icon: 'email', tone: '#7c3aed', tone2: '#a78bfa' },
        downloads: { icon: 'download', tone: '#059669', tone2: '#34d399' },
        alerts: { icon: 'bell', tone: '#d97706', tone2: '#fbbf24' },
        slow: { icon: 'lightning-bolt', tone: '#e11d48', tone2: '#fb7185' },
      })[key] || { icon: 'archive-box', tone: '#4f46e5', tone2: '#818cf8' };
    },
    savedPct(l) { return l.original > 0 ? Math.max(0, Math.round((1 - l.stored / l.original) * 100)) : 0; },
    parts(text) {
      const t = String(text || '');
      const i = t.indexOf(' · ');
      return i > 0 && i < 24 ? { head: t.slice(0, i), rest: t.slice(i + 3) } : { head: '', rest: t };
    },
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
    show(v) { return v === null || v === undefined ? 'NULL' : (typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v)); },
    visit(change) {
      const switching = change.source && change.source !== this.source;
      if (switching) this.term = '';
      const params = { source: change.source || this.source, page: change.page || 1, q: switching ? undefined : ('q' in change ? change.q : this.q) || undefined };
      this.$inertia.get(this.route('wims-logs.browse'), params, { preserveScroll: !switching, preserveState: true, replace: true, headers: { 'X-Quiet': '1' }, onStart: () => { this.busy = true; }, onFinish: () => { this.busy = false; } });
    },
    // the list follows the typing: one request when it pauses
    liveSearch: debounce(function () { this.visit({ page: 1, q: this.term.trim() }); }, 250),
    open(r) {
      this.rec = { open: true, id: r.id, loading: true, error: '', row: {} };
      window.axios.get(this.route('wims-logs.archives.record', [r.archive, r.id]), { headers: { 'X-Quiet': '1' } })
        .then(res => { this.rec.row = res.data.row; })
        .catch(() => { this.rec.error = this.$t('The record could not be read.'); })
        .finally(() => { this.rec.loading = false; });
    },
  },
};
</script>

<style scoped>
.wlb-tabs { display: grid; grid-template-columns: repeat(auto-fit, minmax(13.5rem, 1fr)); gap: 0.7rem; margin: 1rem 0; }
.wlb-tab { position: relative; display: flex; align-items: center; gap: 0.75rem; text-align: left; padding: 0.75rem 0.85rem; border-radius: 1.1rem; border: 1.5px solid color-mix(in srgb, var(--tone) 28%, transparent); background: linear-gradient(135deg, color-mix(in srgb, var(--tone) 12%, transparent), color-mix(in srgb, var(--tone2) 6%, transparent)); cursor: pointer; transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease, background 0.25s ease; }
.wlb-tab-ico { flex: none; width: 2.6rem; height: 2.6rem; display: grid; place-items: center; border-radius: 0.85rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 6px 14px -6px var(--tone); transition: transform 0.25s ease; }
.wlb-tab-body { display: grid; gap: 0.05rem; min-width: 0; } .wlb-tab-body b { font-size: 0.84rem; line-height: 1.2; } .wlb-tab-n { font-size: 0.8rem; font-weight: 800; color: var(--tone); } .wlb-tab-body small { font-size: 0.7rem; color: #64748b; }
.wlb-tab-save { position: absolute; top: 0.45rem; right: 0.55rem; font-size: 0.66rem; font-weight: 900; padding: 0.08rem 0.4rem; border-radius: 999px; color: var(--tone); background: color-mix(in srgb, var(--tone) 14%, transparent); }
.wlb-tab:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -14px var(--tone); border-color: var(--tone); } .wlb-tab:hover .wlb-tab-ico { transform: scale(1.06) rotate(-4deg); }
.wlb-tab.is-on { background: linear-gradient(135deg, var(--tone), var(--tone2)); border-color: transparent; box-shadow: 0 14px 28px -14px var(--tone); } .wlb-tab.is-on b, .wlb-tab.is-on .wlb-tab-n, .wlb-tab.is-on small { color: #fff; } .wlb-tab.is-on .wlb-tab-ico { background: rgba(255, 255, 255, 0.22); box-shadow: none; } .wlb-tab.is-on .wlb-tab-save { color: #fff; background: rgba(255, 255, 255, 0.22); }
.wlb-tab.is-empty:not(.is-on) { opacity: 0.7; }
.wlb-tab:focus-visible { outline: 3px solid color-mix(in srgb, var(--tone) 50%, transparent); outline-offset: 2px; }
.wlb-head-ico { color: #fff !important; background: linear-gradient(135deg, var(--tone), var(--tone2)) !important; }
.wlb-chip { display: inline-block; margin-right: 0.45rem; padding: 0.05rem 0.5rem; border-radius: 999px; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: var(--tone); background: color-mix(in srgb, var(--tone) 13%, transparent); vertical-align: 1px; }
.wlb-empty-box { display: grid; justify-items: center; gap: 0.45rem; padding: 2rem 1rem; text-align: center; } .wlb-empty-box small { color: #64748b; max-width: 26rem; }
.wlb-empty-ico { width: 3.4rem; height: 3.4rem; display: grid; place-items: center; border-radius: 1.1rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 10px 22px -10px var(--tone); }
@media (prefers-reduced-motion: reduce) { .wlb-tab, .wlb-tab-ico { transition: none; } .wlb-tab:hover, .wlb-tab:hover .wlb-tab-ico { transform: none; } }
.wlb-search { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; } .wlb-search input { min-width: 15rem; border-radius: 0.65rem; border: 1.5px solid #e2e8f0; padding: 0.4rem 0.7rem; font-size: 0.82rem; } html.dark .wlb-search input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #f1f5f9; }
.wlb-nw { white-space: nowrap; } .wlb-desc { max-width: 34rem; overflow-wrap: anywhere; } .wlb-sm { padding: 0.3rem 0.7rem !important; font-size: 0.74rem !important; }
.wlb-empty { padding: 1.2rem 0.4rem; color: #64748b; font-weight: 600; } .wlb-empty.is-err { color: #dc2626; }
.wlb-pager { display: flex; align-items: center; justify-content: center; gap: 0.8rem; margin-top: 0.8rem; font-size: 0.8rem; }
.wlb-rec { padding: 1rem 1.2rem 1.2rem; max-height: 80vh; overflow: auto; background: var(--lg-card, #fff); }
.wlb-rec-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.8rem; } .wlb-rec-head b { display: block; } .wlb-rec-head small { color: #64748b; }
.wlb-cols { margin: 0; display: grid; gap: 0.5rem; } .wlb-cols > div { padding: 0.5rem 0.75rem; border-radius: 0.8rem; background: rgba(148, 163, 184, 0.1); }
.wlb-cols dt { font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; } .wlb-cols dd { margin: 0.15rem 0 0; } .wlb-cols pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.76rem; max-height: 16rem; overflow: auto; }

/* ---- searching ---- */
.wlb-bar { height: 3px; margin: 0 0 0.6rem; border-radius: 999px; overflow: hidden; opacity: 0; transition: opacity 0.2s ease; } .wlb-bar.on { opacity: 1; }
.wlb-bar i { display: block; width: 38%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, transparent, #6366f1, #14b8a6, transparent); animation: wlb-travel 0.9s ease-in-out infinite; } @keyframes wlb-travel { from { transform: translateX(-110%); } to { transform: translateX(290%); } }
.wlb-results { transition: opacity 0.2s ease; } .wlb-results.is-busy { opacity: 0.55; pointer-events: none; }
.wlb-row { animation: wlb-row-in 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 25ms); } @keyframes wlb-row-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.wlb-spin { animation: wlb-rot 0.8s linear infinite; } @keyframes wlb-rot { to { transform: rotate(360deg); } }
.wlb-search { max-width: 100%; } .wlb-search input { max-width: 100%; }
@media (max-width: 640px) { .wlb-search { width: 100%; } .wlb-search input { flex: 1; min-width: 0; } .wlb-tabs { grid-template-columns: 1fr 1fr; } .wlb-tab { padding: 0.6rem 0.65rem; gap: 0.5rem; } .wlb-tab-ico { width: 2.1rem; height: 2.1rem; } }
@media (prefers-reduced-motion: reduce) { .wlb-bar i, .wlb-row, .wlb-spin { animation: none; } }
</style>
