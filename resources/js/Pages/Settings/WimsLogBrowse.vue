<template>
  <admin-layout :title="$t('Archived records')">
    <div class="lg-page px-4 md:px-0 wlb-page">
      <page-header icon="doc-text" :title="$t('Archived records')" :subtitle="$t('Every record that was moved out of the live database, by log. Search them, open any record to read it exactly as it was, or download a whole archive.')">
        <Link :href="route('wims-logs.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('WIMS Logs') }}</Link>
      </page-header>

      <!-- one tab per log -->
      <nav class="wlb-tabs" :aria-label="$t('Logs')">
        <button v-for="l in logs" :key="l.key" type="button" class="wlb-tab" :class="{ 'is-on': l.key === source }" :disabled="!l.rows && l.key !== source" @click="visit({ source: l.key, page: 1 })">
          <b>{{ l.label }}</b>
          <span>{{ num(l.rows) }} {{ $t('records') }}</span>
          <small v-if="l.rows">{{ fmt(l.original) }} &gt; {{ fmt(l.stored) }}</small>
        </button>
      </nav>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ current ? current.label : '' }}</h2>
            <p class="lg-card-sub">
              {{ $t(':n record(s)', { n: num(total) }) }}<template v-if="q"> {{ $t('matching') }} "{{ q }}"</template>
              <template v-if="current && current.date_from"> · {{ $t('from :a to :b', { a: day(current.date_from), b: day(current.date_to) }) }}</template>
            </p>
          </div>
          <form class="wlb-search" @submit.prevent="visit({ page: 1, q: term.trim() })"><icons name="search" class="w-4 h-4" /><input v-model="term" type="search" :placeholder="$t('Search the id, the date or the description')" /><button type="submit" class="lg-btn lg-btn-soft wlb-sm">{{ $t('Search') }}</button><button v-if="q" type="button" class="lg-btn lg-btn-soft wlb-sm" @click="term = ''; visit({ page: 1, q: '' })">{{ $t('Clear') }}</button></form>
        </header>
        <div v-if="!records.length" class="wlb-empty">{{ current && !current.rows ? $t('Nothing has been archived from this log yet.') : $t('No record found.') }}</div>
        <div v-else class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('No') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Record') }}</th><th>{{ $t('What it was') }}</th><th>{{ $t('Archive') }}</th><th>{{ $t('Size') }}</th><th>{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in records" :key="r.archive + '-' + r.id">
                <td class="wlb-nw">{{ (page - 1) * per + i + 1 }}</td>
                <td class="wlb-nw">{{ r.at ? $datetime(r.at) : '-' }}</td>
                <td class="wlb-nw"><b>{{ r.id }}</b></td>
                <td class="wlb-desc">{{ r.summary }}</td>
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
import Modal from '@/Jetstream/Modal.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, PageHeader, Modal, Link },
  props: { logs: Array, source: String, records: Array, total: Number, page: Number, per: Number, pages: Number, q: String },
  data() {
    return { term: this.q || '', rec: { open: false, id: '', loading: false, error: '', row: {} } };
  },
  computed: {
    current() { return this.logs.find(l => l.key === this.source); },
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
    visit(change) {
      const switching = change.source && change.source !== this.source;
      if (switching) this.term = '';
      const params = { source: change.source || this.source, page: change.page || 1, q: switching ? undefined : ('q' in change ? change.q : this.q) || undefined };
      this.$inertia.get(this.route('wims-logs.browse'), params, { preserveScroll: false, preserveState: true });
    },
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
.wlb-tabs { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.6rem; margin-bottom: 1rem; }
.wlb-tab { display: grid; gap: 0.1rem; text-align: left; padding: 0.7rem 0.9rem; border-radius: 1rem; border: 1.5px solid rgba(148, 163, 184, 0.25); background: rgba(148, 163, 184, 0.08); cursor: pointer; transition: border-color 0.2s, background 0.2s, transform 0.2s; }
.wlb-tab b { font-size: 0.86rem; } .wlb-tab span { font-size: 0.78rem; font-weight: 700; color: #4f46e5; } .wlb-tab small { font-size: 0.7rem; color: #64748b; }
.wlb-tab:hover:not(:disabled) { transform: translateY(-1px); border-color: rgba(79, 70, 229, 0.5); } .wlb-tab.is-on { border-color: #4f46e5; background: rgba(79, 70, 229, 0.1); } .wlb-tab:disabled { opacity: 0.55; cursor: default; }
html.dark .wlb-tab span { color: #a5b4fc; }
.wlb-search { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; } .wlb-search input { min-width: 15rem; border-radius: 0.65rem; border: 1.5px solid #e2e8f0; padding: 0.4rem 0.7rem; font-size: 0.82rem; } html.dark .wlb-search input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #f1f5f9; }
.wlb-nw { white-space: nowrap; } .wlb-desc { max-width: 34rem; overflow-wrap: anywhere; } .wlb-sm { padding: 0.3rem 0.7rem !important; font-size: 0.74rem !important; }
.wlb-empty { padding: 1.2rem 0.4rem; color: #64748b; font-weight: 600; } .wlb-empty.is-err { color: #dc2626; }
.wlb-pager { display: flex; align-items: center; justify-content: center; gap: 0.8rem; margin-top: 0.8rem; font-size: 0.8rem; }
.wlb-rec { padding: 1rem 1.2rem 1.2rem; max-height: 80vh; overflow: auto; background: var(--lg-card, #fff); }
.wlb-rec-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 0.8rem; } .wlb-rec-head b { display: block; } .wlb-rec-head small { color: #64748b; }
.wlb-cols { margin: 0; display: grid; gap: 0.5rem; } .wlb-cols > div { padding: 0.5rem 0.75rem; border-radius: 0.8rem; background: rgba(148, 163, 184, 0.1); }
.wlb-cols dt { font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; } .wlb-cols dd { margin: 0.15rem 0 0; } .wlb-cols pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.76rem; max-height: 16rem; overflow: auto; }
</style>
