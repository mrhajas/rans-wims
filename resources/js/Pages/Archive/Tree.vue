<template>
  <admin-layout :title="$t('Archive Tree')">
    <div class="lg-page arx px-4 md:px-0">
      <page-header icon="open-folder" :title="$t('Archive Tree')" :subtitle="$t('Browse the archive as folders. Folders with confidential documents ask for your password.')">
        <button type="button" class="lg-btn lg-btn-soft arx-mobile-toggle" @click="panelOpen = !panelOpen"><icons name="menu" class="w-4 h-4" />{{ $t('Folders') }}</button>
        <Link :href="route('archive.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-pie" class="w-4 h-4" />{{ $t('Dashboard') }}</Link>
        <Link :href="route('archive.records.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All Records') }}</Link>
        <Link v-if="can.create" :href="route('archive.records.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('New Record') }}</Link>
      </page-header>

      <div class="arx-tree">
        <!-- Left panel: the tree -->
        <aside class="lg-card arx-panel" :class="{ 'is-collapsed': !panelOpen }">
          <div class="arx-seg" role="tablist">
            <button v-for="m in modes" :key="m.key" type="button" role="tab" :class="{ 'is-on': mode === m.key }" :aria-selected="mode === m.key" @click="setMode(m.key)">{{ m.label.replace('By ', '') }}</button>
          </div>
          <input v-model="nodeFilter" type="search" class="arx-nodesearch" :placeholder="$t('Filter folders…')" />
          <div class="arx-nodes">
            <template v-if="loadingRoot"><div v-for="n in 6" :key="n" class="arx-skel-row"></div></template>
            <ul v-else-if="visibleRoots.length">
              <li v-for="n in visibleRoots" :key="n.key">
                <button type="button" class="arx-node" :class="{ 'is-active': selectedKey === n.key }" @click="select(n)">
                  <span class="arx-node-caret" :class="{ 'is-open': expanded[n.key] }" @click.stop="toggle(n)"><icons name="chevron-down" class="w-3 h-3 -rotate-90" /></span>
                  <folder-icon :tone="folderTone(n.label)" :size="22" :open="selectedKey === n.key || !!expanded[n.key]" />
                  <span class="arx-node-label" :title="n.label">{{ n.label }}</span>
                  <icons v-if="n.locked" name="lock-closed" class="arx-node-lock" />
                  <span class="arx-node-count">{{ n.count.toLocaleString() }}</span>
                </button>
                <ul v-if="expanded[n.key]" class="arx-rise">
                  <li v-if="childLoading[n.key]"><div class="arx-skel-row"></div></li>
                  <li v-for="c in children[n.key] || []" :key="c.key">
                    <button type="button" class="arx-node" :class="{ 'is-active': selectedKey === c.key }" @click="select(c)">
                      <folder-icon :tone="folderTone(c.label)" :size="18" :open="selectedKey === c.key" />
                      <span class="arx-node-label" :title="c.label">{{ c.label }}</span>
                      <icons v-if="c.locked" name="lock-closed" class="arx-node-lock" />
                      <span class="arx-node-count">{{ c.count.toLocaleString() }}</span>
                    </button>
                  </li>
                  <li v-if="!childLoading[n.key] && !(children[n.key] || []).length" class="lg-subtext" style="padding: 4px 8px">{{ $t('No sub-folders.') }}</li>
                </ul>
              </li>
            </ul>
            <p v-else class="lg-empty">{{ $t('No folders yet.') }}</p>
          </div>
        </aside>

        <!-- Main -->
        <section class="arx-main">
          <div class="lg-card">
            <div class="arx-crumbs">
              <button type="button" @click="clearSelection">{{ $t('All folders') }}</button>
              <template v-for="(label, i) in crumbs" :key="i"><icons name="chevron-down" class="w-3 h-3 -rotate-90" /><button v-if="i < crumbs.length - 1" type="button" @click="selectCrumb(i)">{{ label }}</button><span v-else class="arx-crumb-now">{{ label }}</span></template>
            </div>
            <div class="arx-hero" style="margin-top: 0.7rem">
              <folder-icon :tone="folderTone(title)" :size="41" :open="!!selected" :locked="locked" />
              <div class="min-w-0">
                <h2>{{ selected ? selected.label : $t('All folders') }}</h2>
                <p class="lg-subtext">
                  <template v-if="locked">{{ $t('This folder is locked, enter your password to continue.') }}</template>
                  <template v-else-if="selected">{{ selected.count.toLocaleString() }} {{ $t('documents') }}<span v-if="restricted"> · {{ $t('contains confidential documents') }}</span></template>
                  <template v-else>{{ rootTotal.toLocaleString() }} {{ $t('documents in') }} {{ roots.length }} {{ $t('folders') }}</template>
                </p>
              </div>
              <div class="arx-toggle" style="margin-left: auto" role="group" :aria-label="$t('View')">
                <button type="button" :class="{ 'is-on': view === 'grid' }" @click="setView('grid')"><icons name="collection" class="w-4 h-4" />{{ $t('Grid') }}</button>
                <button type="button" :class="{ 'is-on': view === 'list' }" @click="setView('list')"><icons name="list" class="w-4 h-4" />{{ $t('List') }}</button>
              </div>
            </div>
          </div>

          <!-- Filters -->
          <div v-if="!locked" class="lg-card relative z-20">
            <div class="lg-fields arx-filterbar">
              <div class="ar-search-wrap">
                <label class="ar-lbl">{{ $t('Search') }}</label>
                <input v-model="f.q" type="search" class="arx-nodesearch" style="margin: 0" :placeholder="$t('Title, reference, keywords…')" />
              </div>
              <auto-complete id="t_status" :label="$t('Status')" v-model="f.status" :suggestions="opt(options.statuses)" :searchable="false" clearable />
              <auto-complete id="t_conf" :label="$t('Confidentiality')" v-model="f.confidentiality" :suggestions="opt(options.confidentiality)" :searchable="false" clearable />
              <auto-complete id="t_type" :label="$t('Document Type')" v-model="f.document_type" :suggestions="opt(options.documentTypes)" clearable />
              <auto-complete id="t_ftype" :label="$t('File Type')" v-model="f.file_type" :suggestions="opt(options.fileTypes)" :searchable="false" clearable />
              <date-range-picker :label="$t('Document Date')" v-model="dateRange" />
              <auto-complete id="t_sort" :label="$t('Sort')" v-model="sortKey" :suggestions="sortOptions" :searchable="false" />
              <div class="ar-filter-reset"><button type="button" class="lg-btn lg-btn-soft w-full" :disabled="!hasFilters" @click="clearFilters"><icons name="refresh" class="w-4 h-4" />{{ $t('Clear Filters') }}</button></div>
            </div>
          </div>

          <!-- Locked -->
          <div v-if="locked" class="lg-card arx-lockcard arx-pop">
            <folder-icon :tone="folderTone(title)" :size="63" locked />
            <h3>{{ $t('Restricted folder') }}</h3>
            <p>{{ $t('“:name” holds confidential documents. Confirm it is you to open it; it stays open for :m minutes.', { name: title, m: unlockMinutes }).replace(':name', title).replace(':m', unlockMinutes) }}</p>
            <button type="button" class="lg-btn lg-btn-primary" @click="openUnlock"><icons name="lock-open" class="w-4 h-4" />{{ $t('Enter password') }}</button>
          </div>

          <template v-else>
            <!-- Sub-folders / top-level folders -->
            <div v-if="folderCards.length" class="lg-card">
              <div class="arx-section-head"><h2 class="arx-section-title">{{ selected ? $t('Sub-folders') : $t('Folders') }}</h2></div>
              <div class="arx-folders">
                <button v-for="(n, i) in folderCards" :key="n.key" type="button" class="arx-fcard" :style="{ '--i': i }" @click="select(n)">
                  <folder-icon :tone="folderTone(n.label)" :size="33" :locked="n.locked" />
                  <span class="arx-fcard-body"><span class="arx-fcard-name">{{ n.label }}</span><span class="arx-fcard-meta"><b>{{ n.count.toLocaleString() }}</b> {{ $t('documents') }}<template v-if="n.locked"> · {{ $t('locked') }}</template></span></span>
                </button>
              </div>
            </div>

            <!-- Records -->
            <div class="lg-card">
              <div class="arx-section-head">
                <h2 class="arx-section-title">{{ selected ? $t('Documents') : $t('Recently added') }} <span class="lg-subtext">· {{ meta.total.toLocaleString() }}</span></h2>
              </div>
              <div v-if="loading" :class="view === 'grid' ? 'arx-grid' : ''"><div v-for="n in 6" :key="n" class="arx-skel-row" :style="view === 'grid' ? 'height:130px;border-radius:20px' : ''"></div></div>
              <template v-else-if="records.length">
                <div v-if="view === 'grid'" class="arx-grid">
                  <Link v-for="(r, i) in records" :key="r.id" :href="route('archive.records.show', r.id)" class="arx-rec" :class="'arx-g' + grad(r.category)" :style="{ '--i': i }">
                    <span class="arx-rec-ico"><icons :name="docIcon(r)" class="w-5 h-5" /></span>
                    <span class="arx-rec-title">{{ r.title }}</span>
                    <span class="arx-rec-ref">{{ r.reference }} · {{ r.category }}</span>
                    <span class="arx-rec-chips">
                      <span v-if="r.confidentiality === 'Confidential' || r.confidentiality === 'Restricted'" class="arx-rec-chip">🔒 {{ r.confidentiality }}</span>
                      <span v-if="r.is_expired" class="arx-rec-chip">{{ $t('Expired') }}</span>
                      <span v-else-if="r.expires_soon" class="arx-rec-chip">{{ $t('Expiring') }}</span>
                      <span v-if="r.status !== 'Active'" class="arx-rec-chip">{{ r.status }}</span>
                    </span>
                    <span class="arx-rec-foot"><span>{{ r.document_date ? $date(r.document_date) : '—' }}</span><span>{{ r.files_count }} {{ $t('file(s)') }}<template v-if="r.storage_bytes !== null"> · {{ fmt(r.storage_bytes) }}</template></span></span>
                  </Link>
                </div>
                <div v-else class="lg-table-wrap -mx-4 md:mx-0">
                  <table class="lg-table lg-responsive">
                    <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('Title') }}</th><th>{{ $t('Type') }}</th><th>{{ $t('Document Date') }}</th><th class="num">{{ $t('Files') }}</th><th>{{ $t('Confidentiality') }}</th><th>{{ $t('Status') }}</th></tr></thead>
                    <tbody>
                      <tr v-for="r in records" :key="r.id" class="is-click" @click="$inertia.visit(route('archive.records.show', r.id))">
                        <td :data-label="$t('Reference')"><span class="lg-chip lg-chip-blue" style="text-transform: none">{{ r.reference }}</span></td>
                        <td :data-label="$t('Title')"><span class="lg-main">{{ r.title }}</span></td>
                        <td :data-label="$t('Type')">{{ r.document_type || '—' }}</td>
                        <td :data-label="$t('Document Date')">{{ r.document_date ? $date(r.document_date) : '—' }}</td>
                        <td :data-label="$t('Files')" class="num">{{ r.files_count }}</td>
                        <td :data-label="$t('Confidentiality')"><span class="lg-chip" :class="confChip(r.confidentiality)" style="text-transform: none">{{ r.confidentiality }}</span></td>
                        <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status)" style="text-transform: none">{{ r.status }}</span></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div v-if="meta.last_page > 1" class="arx-pager">
                  <button type="button" class="lg-btn lg-btn-soft" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)"><icons name="chevron-down" class="w-4 h-4 rotate-90" />{{ $t('Previous') }}</button>
                  <span class="lg-subtext">{{ $t('Page') }} {{ meta.current_page }} / {{ meta.last_page }}</span>
                  <button type="button" class="lg-btn lg-btn-soft" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">{{ $t('Next') }}<icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
                </div>
              </template>
              <div v-else class="arx-lockcard">
                <folder-icon tone="slate" :size="64" />
                <h3>{{ hasFilters ? $t('No documents match your current filters.') : $t('No archive records found.') }}</h3>
                <button v-if="hasFilters" type="button" class="lg-btn lg-btn-soft" @click="clearFilters"><icons name="refresh" class="w-4 h-4" />{{ $t('Clear Filters') }}</button>
              </div>
            </div>
          </template>
        </section>
      </div>
    </div>

    <!-- Password prompt for a restricted folder -->
    <modal :show="unlock.show" max-width="md" @close="unlock.show = false">
      <form class="arx-pw" :class="{ 'arx-shake': unlock.shake }" @submit.prevent="submitUnlock">
        <div style="display: flex; align-items: center; gap: 14px">
          <folder-icon :tone="folderTone(title)" :size="56" locked />
          <div>
            <strong style="font-size: 1.05rem; color: var(--lg-ink, #0f172a)">{{ $t('Restricted folder') }}</strong>
            <p class="lg-subtext" style="margin: 2px 0 0">{{ title }}</p>
          </div>
        </div>
        <p class="lg-subtext">{{ $t('Enter your WIMS password to open this folder. It stays unlocked for') }} {{ unlockMinutes }} {{ $t('minutes.') }}</p>
        <input ref="pw" v-model="unlock.password" type="password" autocomplete="current-password" :placeholder="$t('Your password')" />
        <p v-if="unlock.error" style="color: #be123c; font-size: 0.8rem; font-weight: 600; margin: 0">{{ unlock.error }}</p>
        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px">
          <button type="button" class="lg-btn lg-btn-soft" @click="unlock.show = false">{{ $t('Cancel') }}</button>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="unlock.busy || !unlock.password"><icons name="lock-open" class="w-4 h-4" />{{ unlock.busy ? $t('Checking…') : $t('Unlock folder') }}</button>
        </div>
      </form>
    </modal>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DateRangePicker from '@/Shared/DateRangePicker.vue';
import Modal from '@/Jetstream/Modal.vue';
import FolderIcon from '@/Shared/Archive/FolderIcon.vue';
import { formatBytes, confidentialityChip, statusChip, folderTone } from '@/Core/archiveFormat';

export default {
  components: { AdminLayout, PageHeader, AutoComplete, DateRangePicker, Modal, FolderIcon, Link },

  props: { modes: Array, options: Object, unlockMinutes: Number, can: Object },

  data() {
    const qs = new URLSearchParams(window.location.search);
    let view = 'grid';
    try { view = localStorage.getItem('archive_tree_view') || 'grid'; } catch (e) { /* ignore */ }

    return {
      mode: this.modes.some(m => m.key === qs.get('mode')) ? qs.get('mode') : 'category',
      initialPath: qs.getAll('path[]').length ? qs.getAll('path[]') : qs.getAll('path'),
      roots: [],
      children: {},
      expanded: {},
      childLoading: {},
      loadingRoot: true,
      nodeFilter: '',
      panelOpen: true,
      selected: null,
      locked: false,
      restricted: false,
      records: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
      loading: false,
      view,
      f: { q: '', status: null, confidentiality: null, document_type: null, file_type: null },
      dateRange: { start: null, end: null },
      sortKey: 'newest',
      unlock: { show: false, password: '', error: null, busy: false, shake: false },
    };
  },

  computed: {
    selectedKey() { return this.selected ? this.selected.key : null; },
    title() { return this.selected ? this.selected.label : ''; },
    crumbs() { return this.selected ? this.selected.crumbs : []; },
    rootTotal() { return this.roots.reduce((a, n) => a + n.count, 0); },
    visibleRoots() {
      const q = this.nodeFilter.trim().toLowerCase();
      return q ? this.roots.filter(n => n.label.toLowerCase().includes(q)) : this.roots;
    },
    folderCards() {
      if (!this.selected) return this.roots;
      return this.selected.path.length === 1 ? (this.children[this.selected.key] || []) : [];
    },
    sortOptions() {
      const o = [
        { label: this.$t('Newest first'), value: 'newest' },
        { label: this.$t('Oldest first'), value: 'oldest' },
        { label: this.$t('Title A–Z'), value: 'title' },
        { label: this.$t('Document date'), value: 'document_date' },
        { label: this.$t('Recently updated'), value: 'updated' },
      ];
      if (this.can.storage) o.push({ label: this.$t('Largest first'), value: 'largest' });
      return o;
    },
    hasFilters() {
      return !!(this.f.q || this.f.status || this.f.confidentiality || this.f.document_type || this.f.file_type || this.dateRange.start || this.dateRange.end);
    },
    queryParams() {
      const sorts = { newest: ['created_at', 'desc'], oldest: ['created_at', 'asc'], title: ['title', 'asc'], document_date: ['document_date', 'desc'], updated: ['updated_at', 'desc'], largest: ['storage_bytes', 'desc'] };
      const [sort, direction] = sorts[this.sortKey] || sorts.newest;
      const p = { mode: this.mode, path: this.selected ? this.selected.path : [], sort, direction, ...this.f, document_from: this.dateRange.start, document_to: this.dateRange.end };
      return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== null && v !== '' && v !== undefined && !(Array.isArray(v) && !v.length)));
    },
  },

  watch: {
    f: { handler() { this.loadDebounced(1); }, deep: true },
    dateRange: { handler() { this.load(1); }, deep: true },
    sortKey() { this.load(1); },
  },

  created() {
    this.loadDebounced = debounce(page => this.load(page), 350);
  },

  mounted() {
    this.loadRoots().then(() => this.openInitial());
    if (window.innerWidth < 900) this.panelOpen = false;
  },

  methods: {
    fmt: formatBytes,
    folderTone,
    confChip: confidentialityChip,
    statusChip,
    opt(list) { return (list || []).map(v => ({ label: v, value: v })); },
    grad(category) { let h = 0; for (const ch of String(category)) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return h % 6; },
    docIcon(r) { return r.document_type === 'Contract' ? 'doc-text' : r.document_type === 'Report' ? 'chart-bar' : r.document_type === 'Invoice' ? 'cash' : 'doc'; },
    setView(v) { this.view = v; try { localStorage.setItem('archive_tree_view', v); } catch (e) { /* ignore */ } },

    async loadRoots() {
      this.loadingRoot = true;
      try {
        const res = await window.axios.get(this.route('archive.tree.nodes'), { params: { mode: this.mode } });
        this.roots = res.data.data.map(n => ({ ...n, path: [n.value], crumbs: [n.label] }));
      } finally {
        this.loadingRoot = false;
      }
    },
    async loadChildren(n) {
      this.childLoading[n.key] = true;
      try {
        const res = await window.axios.get(this.route('archive.tree.nodes'), { params: { mode: this.mode, path: n.path } });
        this.children[n.key] = res.data.data.map(c => ({ ...c, path: [...n.path, c.value], crumbs: [...n.crumbs, c.label] }));
      } finally {
        this.childLoading[n.key] = false;
      }
    },
    async toggle(n) {
      this.expanded[n.key] = !this.expanded[n.key];
      if (this.expanded[n.key] && !this.children[n.key]) await this.loadChildren(n);
    },
    async openInitial() {
      if (!this.initialPath.length) { await this.load(1); return; }
      const root = this.roots.find(n => n.value === this.initialPath[0] || n.label === this.initialPath[0]);
      if (!root) { await this.load(1); return; }
      this.expanded[root.key] = true;
      await this.loadChildren(root);
      const child = this.initialPath[1] && (this.children[root.key] || []).find(c => c.value === this.initialPath[1] || c.label === this.initialPath[1]);
      this.select(child || root);
    },
    async setMode(m) {
      if (m === this.mode) return;
      this.mode = m;
      this.selected = null; this.locked = false; this.children = {}; this.expanded = {};
      await this.loadRoots();
      this.load(1);
    },
    async select(n) {
      this.selected = n;
      this.locked = false;
      if (n.path.length === 1) {
        this.expanded[n.key] = true;
        if (!this.children[n.key]) this.loadChildren(n);
      }
      if (window.innerWidth < 900) this.panelOpen = false;
      await this.load(1);
    },
    clearSelection() { this.selected = null; this.locked = false; this.load(1); },
    selectCrumb(i) {
      const root = this.roots.find(n => n.key === this.selected.key) || this.roots.find(n => n.value === this.selected.path[0]);
      if (i === 0 && root) this.select(root);
    },
    clearFilters() {
      this.f = { q: '', status: null, confidentiality: null, document_type: null, file_type: null };
      this.dateRange = { start: null, end: null };
    },

    async load(page = 1) {
      this.loading = true;
      try {
        const res = await window.axios.get(this.route('archive.tree.records'), { params: { ...this.queryParams, page } });
        this.locked = false;
        this.restricted = !!res.data.restricted;
        this.records = res.data.data;
        this.meta = res.data.meta;
      } catch (e) {
        if (e.response && e.response.status === 423) {
          this.locked = true;
          this.records = [];
          this.meta = { current_page: 1, last_page: 1, total: 0 };
          this.openUnlock();
        } else {
          this.records = [];
        }
      } finally {
        this.loading = false;
      }
    },

    openUnlock() {
      this.unlock = { show: true, password: '', error: null, busy: false, shake: false };
      this.$nextTick(() => setTimeout(() => this.$refs.pw && this.$refs.pw.focus(), 120));
    },
    async submitUnlock() {
      this.unlock.busy = true;
      this.unlock.error = null;
      try {
        await window.axios.post(this.route('archive.tree.unlock'), { mode: this.mode, path: this.selected.path, password: this.unlock.password });
        this.unlock.show = false;
        this.markUnlocked(this.selected);
        await this.load(1);
      } catch (e) {
        this.unlock.password = '';
        this.unlock.error = e.response && e.response.status === 429 ? this.$t('Too many attempts. Please wait a minute.') : ((e.response && e.response.data && e.response.data.errors && e.response.data.errors.password && e.response.data.errors.password[0]) || this.$t('Unable to unlock this folder.'));
        this.unlock.shake = true;
        setTimeout(() => { this.unlock.shake = false; }, 400);
      } finally {
        this.unlock.busy = false;
      }
    },
    // The folder (and everything below it) no longer shows a padlock in the panel.
    markUnlocked(n) {
      const clear = x => { x.locked = false; };
      const node = this.roots.find(r => r.key === n.key);
      if (node) { clear(node); (this.children[node.key] || []).forEach(clear); }
      clear(n);
    },
  },
};
</script>

<style scoped>
.arx-main { min-width: 0; display: flex; flex-direction: column; }
.arx-main > .lg-card { margin-bottom: 1rem; }
.arx-crumb-now { font-weight: 800; color: var(--lg-ink); }
.ar-lbl { display: block; margin-bottom: 0.25rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.arx-pager { display: flex; align-items: center; justify-content: center; gap: 0.8rem; margin-top: 1rem; }
.arx-pop { animation: arx-pop 0.4s ease both; }
.arx-fcard { font: inherit; }
</style>
