<template>
  <admin-layout :title="archive.ref">
    <div class="lg-page px-4 md:px-0 wla-page">
      <page-header icon="archive-box" :title="archive.ref + ' · ' + archive.label" :subtitle="$t('Every record of this archive, with the size it had in the database and the size it has now. Open a record to read it in full.')">
        <Link :href="route('wims-logs.show', archive.run_id)" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ archive.run_ref }}</Link>
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
          <form class="wla-search" @submit.prevent="search"><icons name="search" class="w-4 h-4" /><input v-model="term" type="search" :placeholder="$t('Search the id, the date or the description')" /><button type="submit" class="lg-btn lg-btn-soft wla-sm">{{ $t('Search') }}</button><button v-if="q" type="button" class="lg-btn lg-btn-soft wla-sm" @click="clear">{{ $t('Clear') }}</button></form>
        </header>
        <div v-if="!records.length" class="wla-empty">{{ $t('No record found.') }}</div>
        <div v-else class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('No') }}</th><th>{{ $t('Record') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Description') }}</th><th>{{ $t('Before') }}</th><th>{{ $t('Compressed') }}</th><th>{{ $t('Saved') }}</th><th>{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in records" :key="r.id">
                <td class="wla-nw">{{ (page - 1) * per + i + 1 }}</td>
                <td class="wla-nw"><b>{{ r.id }}</b></td>
                <td class="wla-nw">{{ r.at ? $datetime(r.at) : '-' }}</td>
                <td class="wla-desc">{{ r.summary }}</td>
                <td class="wla-nw">{{ fmt(r.original) }}</td>
                <td class="wla-nw">{{ fmt(r.compressed) }}</td>
                <td class="wla-nw"><b class="is-good">{{ r.original > 0 ? Math.max(0, Math.round((1 - r.compressed / r.original) * 100)) : 0 }}%</b></td>
                <td><button type="button" class="lg-btn lg-btn-soft wla-sm" @click="open(r)"><icons name="search" class="w-4 h-4" />{{ $t('Read') }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="pages > 1" class="wla-pager">
          <button type="button" class="lg-btn lg-btn-soft wla-sm" :disabled="page <= 1" @click="go(page - 1)">{{ $t('Previous') }}</button>
          <span>{{ $t('Page :a of :b', { a: page, b: pages }) }}</span>
          <button type="button" class="lg-btn lg-btn-soft wla-sm" :disabled="page >= pages" @click="go(page + 1)">{{ $t('Next') }}</button>
        </div>
      </section>

      <!-- one record, whole -->
      <modal :show="rec.open" max-width="3xl" :closeable="true" @close="rec.open = false">
        <div class="wla-rec">
          <header class="wla-rec-head">
            <div class="min-w-0"><b>{{ archive.label }} · {{ rec.id }}</b><small>{{ $t('The record exactly as it was in the database') }}</small></div>
            <button type="button" class="lg-btn lg-btn-soft wla-sm" @click="rec.open = false">{{ $t('Close') }}</button>
          </header>
          <p v-if="rec.loading" class="wla-empty">{{ $t('Reading...') }}</p>
          <p v-else-if="rec.error" class="wla-empty is-err">{{ rec.error }}</p>
          <dl v-else class="wla-cols">
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

export default {
  components: { AdminLayout, PageHeader, StatTiles, Modal, Link },
  props: { archive: Object, records: Array, total: Number, page: Number, per: Number, pages: Number, q: String },
  data() {
    return { term: this.q || '', rec: { open: false, id: '', loading: false, error: '', row: {} } };
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
    show(v) { return v === null || v === undefined ? 'NULL' : (typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v)); },
    visit(page, q) {
      this.$inertia.get(this.route('wims-logs.archives.show', this.archive.id), { page, q: q || undefined }, { preserveScroll: false, preserveState: true });
    },
    search() { this.visit(1, this.term.trim()); },
    clear() { this.term = ''; this.visit(1, ''); },
    go(p) { this.visit(p, this.q); },
    open(r) {
      this.rec = { open: true, id: r.id, loading: true, error: '', row: {} };
      window.axios.get(this.route('wims-logs.archives.record', [this.archive.id, r.id]), { headers: { 'X-Quiet': '1' } })
        .then(res => { this.rec.row = res.data.row; })
        .catch(() => { this.rec.error = this.$t('The record could not be read.'); })
        .finally(() => { this.rec.loading = false; });
    },
  },
};
</script>

<style scoped>
.wla-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 0.6rem; margin: 0; } .wla-facts > div { padding: 0.6rem 0.8rem; border-radius: 0.85rem; background: rgba(148, 163, 184, 0.1); min-width: 0; } .wla-wide { grid-column: 1 / -1; }
.wla-facts dt { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; } .wla-facts dd { margin: 0.15rem 0 0; font-weight: 800; font-size: 0.95rem; overflow-wrap: anywhere; } .wla-facts code { font-size: 0.72rem; font-weight: 600; }
.wla-chip { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.72rem; font-weight: 800; } .wla-chip.is-ok { background: #d1fae5; color: #065f46; } .wla-chip.is-bad { background: #fee2e2; color: #991b1b; }
.wla-search { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; } .wla-search input { min-width: 15rem; border-radius: 0.65rem; border: 1.5px solid #e2e8f0; padding: 0.4rem 0.7rem; font-size: 0.82rem; } html.dark .wla-search input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #f1f5f9; }
.wla-nw { white-space: nowrap; } .wla-desc { max-width: 30rem; overflow-wrap: anywhere; } .wla-sm { padding: 0.3rem 0.7rem !important; font-size: 0.74rem !important; }
.wla-empty { padding: 1.2rem 0.4rem; color: #64748b; font-weight: 600; } .wla-empty.is-err { color: #dc2626; }
.is-good { color: #047857; } html.dark .is-good { color: #34d399; }
.wla-pager { display: flex; align-items: center; justify-content: center; gap: 0.8rem; margin-top: 0.8rem; font-size: 0.8rem; }
.wla-rec { padding: 1rem 1.2rem 1.2rem; max-height: 80vh; overflow: auto; background: var(--lg-card, #fff); }
.wla-rec-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.8rem; } .wla-rec-head b { display: block; } .wla-rec-head small { color: #64748b; }
.wla-cols { margin: 0; display: grid; gap: 0.5rem; } .wla-cols > div { padding: 0.5rem 0.75rem; border-radius: 0.8rem; background: rgba(148, 163, 184, 0.1); }
.wla-cols dt { font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; } .wla-cols dd { margin: 0.15rem 0 0; } .wla-cols pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.76rem; max-height: 16rem; overflow: auto; }
</style>
