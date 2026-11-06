<template>
  <admin-layout :title="archive.ref">
    <div class="lg-page px-4 md:px-0 wla-page">
      <page-header icon="archive-box" :title="archive.ref + ' · ' + archive.label" :subtitle="$t('Every record of this archive, with the size it had in the database and the size it has now. Open a record to read it in full.')">
        <Link :href="route('wims-logs.show', archive.run_id)" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ archive.run_ref }}</Link>
        <button v-if="canRun && archive.rows" type="button" class="lg-btn tone tone-solid" style="--tone: #0f766e" @click="askRestoreAll"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Put the whole archive back') }}</button>
        <a :href="route('wims-logs.archives.download', archive.id)" class="lg-btn lg-btn-primary"><icons name="download" class="w-4 h-4" />{{ $t('Download all records') }}</a>
      </page-header>

      <stat-tiles :items="tiles" compact />

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('About this archive') }}</h2>
            <p class="lg-card-sub">{{ $t('From :a to :b, taken from the table :t.', { a: day(archive.date_from), b: day(archive.date_to), t: archive.table }) }}</p>
          </div>
        </header>
        <dl class="wla-facts">
          <div><dt>{{ $t('Records') }}</dt><dd>{{ num(archive.rows) }}</dd></div>
          <div><dt>{{ $t('Before (in the database)') }}</dt><dd>{{ fmt(archive.original) }}</dd></div>
          <div><dt>{{ $t('Compressed (the records)') }}</dt><dd>{{ fmt(archive.compressed) }}</dd></div>
          <div><dt>{{ $t('On disk (records and index)') }}</dt><dd>{{ fmt(archive.stored) }}</dd></div>
          <div><dt>{{ $t('Check') }}</dt><dd><span class="wla-chip" :class="archive.verified ? 'is-ok' : 'is-bad'">{{ archive.verified ? $t('Every record was read back and matched') : $t('Not verified') }}</span></dd></div>
          <div class="wla-wide"><dt>{{ $t('Fingerprint of the data file (SHA-256)') }}</dt><dd><code>{{ archive.sha256 || '-' }}</code></dd></div>
          <div v-if="archive.skipped"><dt>{{ $t('Left live') }}</dt><dd>{{ archive.skipped }} {{ $t('record(s) could not be archived safely') }}</dd></div>
          <div v-if="archive.restored"><dt>{{ $t('Put back') }}</dt><dd>{{ archive.restored }} {{ $t('record(s) were restored to the live table') }}</dd></div>
        </dl>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Records') }}</h2>
            <p class="lg-card-sub">{{ $t(':n record(s)', { n: num(total) }) }}<template v-if="q"> {{ $t('matching') }} "{{ q }}"</template></p>
          </div>
        </header>

        <div class="wla-filters">
          <form class="wla-search" @submit.prevent="visit(1, term.trim())"><icons :name="busy ? 'refresh' : 'search'" class="w-4 h-4" :class="{ 'wla-spin': busy }" /><input v-model="term" type="search" :placeholder="$t('Search the id, the date or the description')" @input="liveSearch" /></form>
          <div v-if="users.length" class="wla-user">
            <label>{{ $t('Person') }}</label>
            <css-select v-model="userSel" class="wla-user-sel" @change="visit(1, term.trim())">
              <option value="">{{ $t('All people') }}</option>
              <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }} ({{ num(u.n) }})</option>
            </css-select>
          </div>
          <button v-if="q || userSel !== ''" type="button" class="lg-btn lg-btn-soft wla-sm" @click="clearFilters"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Clear') }}</button>
        </div>
        <div class="wla-bar" :class="{ on: busy }" aria-hidden="true"><i></i></div>

        <div v-if="!records.length" class="wla-empty">{{ $t('No record found.') }}</div>
        <div v-else class="wla-results" :class="{ 'is-busy': busy }" :aria-busy="busy">
          <archived-records-list v-model:selected="selected" :records="records" :can-run="canRun" @read="open" @restore="askRestore" />
        </div>
        <div v-if="pages > 1" class="wla-pager">
          <button type="button" class="lg-btn lg-btn-soft wla-sm" :disabled="page <= 1" @click="go(page - 1)">{{ $t('Previous') }}</button>
          <span>{{ $t('Page :a of :b', { a: page, b: pages }) }}</span>
          <button type="button" class="lg-btn lg-btn-soft wla-sm" :disabled="page >= pages" @click="go(page + 1)">{{ $t('Next') }}</button>
        </div>
      </section>

      <!-- one record, whole -->
      <archived-record-view :show="rec.open" :source="archive.source" :label="archive.label" :record-id="rec.id" :summary="rec.summary" :row="rec.row" :loading="rec.loading" :error="rec.error"
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
import { Link } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';

export default {
  components: { AdminLayout, PageHeader, StatTiles, ArchivedRecordView, ArchivedRecordsList, RestoreRecordsDialog, Link },
  props: { archive: Object, records: Array, total: Number, page: Number, per: Number, pages: Number, q: String, users: { type: Array, default: () => [] }, user: { type: Number, default: null }, canRun: Boolean },
  data() {
    return {
      busy: false, term: this.q || '', userSel: this.user === null ? '' : String(this.user), selected: [],
      rec: { open: false, id: '', index: 0, item: null, summary: '', loading: false, error: '', row: {}, user: null, restored: false, canRestore: false },
      rs: { open: false, label: '', items: [], all: false, busy: false, error: '', result: null },
    };
  },
  computed: {
    tiles() {
      const a = this.archive;
      return [
        { icon: 'doc-text', tone: 'orange', label: this.$t('Records'), value: this.num(a.rows) },
        { icon: 'database', tone: 'violet', label: this.$t('Before'), value: this.fmt(a.original) },
        { icon: 'archive-box', tone: 'sky', label: this.$t('On disk now'), value: this.fmt(a.stored) },
        { icon: 'tick', tone: 'forest', label: this.$t('Saved'), value: this.fmt(a.saved) + (a.saved_pct !== null ? ' (' + a.saved_pct + '%)' : '') },
      ];
    },
  },
  watch: { records() { this.selected = []; } },
  methods: {
    fmt(b) {
      b = Number(b || 0);
      const u = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return (i === 0 ? String(Math.round(b)) : b.toFixed(b < 10 ? 2 : 1)) + ' ' + u[i];
    },
    num(n) { return Number(n || 0).toLocaleString(); },
    day(d) { return d ? String(d).slice(0, 10) : '-'; },
    visit(page, q) {
      const params = { page, q: q || undefined, user: this.userSel === '' ? undefined : this.userSel };
      this.$inertia.get(this.route('wims-logs.archives.show', this.archive.id), params, { preserveScroll: false, preserveState: true, replace: true, headers: { 'X-Quiet': '1' }, onStart: () => { this.busy = true; }, onFinish: () => { this.busy = false; } });
    },
    clearFilters() { this.term = ''; this.userSel = ''; this.visit(1, ''); },
    go(p) { this.visit(p, this.q); },
    liveSearch: debounce(function () { this.visit(1, this.term.trim()); }, 250),
    open(r) {
      const index = Math.max(0, this.records.indexOf(r));
      this.rec = { open: true, id: r.id, index, item: r, summary: r.summary, loading: true, error: '', row: {}, user: null, restored: !!r.restored, canRestore: false };
      window.axios.get(this.route('wims-logs.archives.record', [this.archive.id, r.id]), { headers: { 'X-Quiet': '1' } })
        .then(res => { if (this.rec.id === r.id) { this.rec.row = res.data.row; this.rec.user = res.data.user; this.rec.restored = res.data.restored; this.rec.canRestore = res.data.can_restore; } })
        .catch(() => { if (this.rec.id === r.id) this.rec.error = this.$t('The record could not be read.'); })
        .finally(() => { if (this.rec.id === r.id) this.rec.loading = false; });
    },
    step(d) { const r = this.records[this.rec.index + d]; if (r) this.open(r); },
    // ---- putting records back
    askRestore(items) {
      const list = (items || []).filter(Boolean);
      if (!list.length) return;
      this.rs = { open: true, items: list, all: false, busy: false, error: '', result: null, label: list.length === 1 ? '#' + list[0].id + ' · ' + this.archive.label : this.$t(':n records', { n: list.length }) + ' · ' + this.archive.label };
    },
    askRestoreAll() { this.rs = { open: true, items: [], all: true, busy: false, error: '', result: null, label: this.$t('the whole archive :a (:n records)', { a: this.archive.ref, n: this.num(this.archive.rows) }) }; },
    closeRestore() { const done = !!this.rs.result; this.rs.open = false; if (done) { this.rec.open = false; this.selected = []; this.$inertia.reload({ preserveScroll: true, preserveState: true, headers: { 'X-Quiet': '1' } }); } },
    async doRestore(password) {
      this.rs.busy = true;
      this.rs.error = '';
      try {
        const body = this.rs.all ? { all: true, password } : { ids: this.rs.items.map(r => String(r.id)), password };
        const { data } = await window.axios.post(this.route('wims-logs.archives.restore', this.archive.id), body, { headers: { 'X-Quiet': '1' }, timeout: 0 });
        this.rs.result = data;
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
.wla-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 0.8rem; margin: 0; } .wla-facts > div { padding: 0.75rem 0.95rem; border-radius: 1rem; background: rgba(148, 163, 184, 0.1); min-width: 0; } .wla-wide { grid-column: 1 / -1; }
.wla-facts dt { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; } .wla-facts dd { margin: 0.2rem 0 0; font-weight: 800; font-size: 0.95rem; overflow-wrap: anywhere; } .wla-facts code { font-size: 0.72rem; font-weight: 600; }
.wla-chip { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.72rem; font-weight: 800; } .wla-chip.is-ok { background: #d1fae5; color: #065f46; } .wla-chip.is-bad { background: #fee2e2; color: #991b1b; }
.wla-filters { display: flex; align-items: flex-end; gap: 0.9rem; flex-wrap: wrap; margin: 0.2rem 0 1rem; }
.wla-search { display: flex; align-items: center; gap: 0.5rem; flex: 1 1 18rem; min-width: 0; padding: 0 0.9rem; border-radius: 0.9rem; border: 1.5px solid #e2e8f0; background: transparent; transition: border-color 0.2s ease, box-shadow 0.2s ease; } .wla-search:focus-within { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.16); } .wla-search input { flex: 1; min-width: 0; border: 0; background: transparent; padding: 0.7rem 0; font-size: 0.86rem; outline: none; box-shadow: none; } html.dark .wla-search { border-color: rgba(255, 255, 255, 0.14); } html.dark .wla-search input { color: #f1f5f9; }
.wla-user { display: grid; gap: 0.25rem; flex: 0 1 18rem; min-width: 12rem; } .wla-user label { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; } .wla-user-sel { width: 100%; }
.wla-sm { padding: 0.35rem 0.8rem !important; font-size: 0.76rem !important; }
.wla-empty { padding: 1.4rem 0.4rem; color: #64748b; font-weight: 600; }
.wla-pager { display: flex; align-items: center; justify-content: center; gap: 0.9rem; margin-top: 1rem; font-size: 0.8rem; }
.wla-bar { height: 3px; margin: 0 0 0.7rem; border-radius: 999px; overflow: hidden; opacity: 0; transition: opacity 0.2s ease; } .wla-bar.on { opacity: 1; }
.wla-bar i { display: block; width: 38%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, transparent, #6366f1, #14b8a6, transparent); animation: wla-travel 0.9s ease-in-out infinite; } @keyframes wla-travel { from { transform: translateX(-110%); } to { transform: translateX(290%); } }
.wla-results { transition: opacity 0.2s ease; } .wla-results.is-busy { opacity: 0.55; pointer-events: none; }
.wla-spin { animation: wla-rot 0.8s linear infinite; } @keyframes wla-rot { to { transform: rotate(360deg); } }
@media (max-width: 640px) { .wla-user { flex: 1 1 100%; } }
@media (prefers-reduced-motion: reduce) { .wla-bar i, .wla-spin { animation: none; } }
</style>
