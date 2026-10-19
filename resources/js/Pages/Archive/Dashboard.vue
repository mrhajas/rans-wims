<template>
  <admin-layout :title="$t('Archive Dashboard')">
    <div class="lg-page arx ar-page ard px-4 md:px-0">
      <!-- app bar: title · quick search · actions -->
      <header class="ard-bar relative z-30">
        <div class="ard-title">
          <span class="ard-title-ico"><icons name="folder" class="w-6 h-6" /></span>
          <div class="min-w-0"><h1>{{ $t('Archive') }}</h1><p>{{ $t('Your documents are safe, organised, and always accessible.') }}</p></div>
        </div>
        <div class="ar-search ard-search">
          <icons name="search" class="w-5 h-5 ar-search-ico" />
          <input v-model="quick.q" type="text" class="ar-search-input" :placeholder="$t('Search: ARC-2026-000123, Ministry of Finance, Contract Extension…')" @input="onQuick" @keydown.enter.prevent="goSearch" autocomplete="off" />
          <button v-if="quick.q" type="button" class="ar-search-clear lg-tip" :data-tip="$t('Clear')" :aria-label="$t('Clear')" @click="quick.q = ''; quick.results = []"><icons name="x-circle" class="w-4 h-4" /></button>
          <ul v-if="quick.q.trim().length >= 2 && (quick.results.length || quick.done)" class="ar-suggest">
            <li v-for="r in quick.results" :key="r.id">
              <Link :href="route('archive.records.show', r.id)" class="ar-suggest-row">
                <span class="lg-chip lg-chip-blue" style="text-transform: none">{{ r.reference }}</span>
                <span class="ar-suggest-title">{{ r.title }}</span>
                <span class="lg-subtext">{{ r.category }}</span>
              </Link>
            </li>
            <li v-if="!quick.results.length" class="ar-suggest-empty">{{ $t('No archive records found.') }}</li>
          </ul>
        </div>
        <div class="ard-actions">
          <Link v-if="can.tree" :href="route('archive.tree')" class="ard-btn is-soft"><icons name="open-folder" class="w-4 h-4" /><span>{{ $t('Archive Tree') }}</span></Link>
          <Link :href="route('archive.records.index')" class="ard-btn is-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('All Records') }}</span></Link>
          <Link v-if="can.create" :href="route('archive.records.create')" class="ard-btn is-dark"><icons name="upload" class="w-4 h-4" /><span>{{ $t('Upload') }}</span></Link>
        </div>
      </header>

      <!-- filters (collapsible) -->
      <div class="ard-filter-row">
        <button type="button" class="ard-btn is-soft" :class="{ 'is-on': showFilters || hasFilters }" @click="showFilters = !showFilters"><icons name="filter" class="w-4 h-4" /><span>{{ $t('Filters') }}<template v-if="hasFilters"> ({{ activeChips.length }})</template></span></button>
        <span v-for="chip in activeChips" :key="chip.key" class="ar-chip">{{ chip.label }}<button type="button" :aria-label="$t('Remove')" @click="removeChip(chip.key)">&times;</button></span>
        <button v-if="hasFilters" type="button" class="ard-clear" @click="clearFilters">{{ $t('Clear Filters') }}</button>
      </div>
      <transition name="ard-slide">
        <div v-show="showFilters" class="ard-card ar-filters relative z-20">
          <div class="lg-fields ar-filter-grid">
            <date-range-picker :label="$t('Document Date')" v-model="range" />
            <auto-complete id="d_category" :label="$t('Category')" v-model="filters.category" :suggestions="opt(options.categories)" clearable />
            <auto-complete id="d_source" :label="$t('Source')" v-model="filters.source_type" :suggestions="opt(options.sources)" clearable />
            <auto-complete id="d_project" :label="$t('Project')" v-model="filters.warehouse_id" :suggestions="options.projects" clearable />
            <auto-complete id="d_ftype" :label="$t('File Type')" v-model="filters.file_type" :suggestions="opt(options.fileTypes)" clearable />
          </div>
        </div>
      </transition>

      <p v-if="error" class="lg-note lg-note-red mb-4"><icons name="exclamation" class="w-4 h-4" /><span>{{ error }} <button type="button" class="ar-retry" @click="load">{{ $t('Retry') }}</button></span></p>

      <!-- big stat cards -->
      <section class="ard-cards">
        <template v-if="loading && !stats"><div v-for="n in 4" :key="'sk' + n" class="ard-skel"></div></template>
        <template v-else-if="stats">
          <stat-card v-for="(c, i) in bigCards" :key="c.key" :index="i" :tone="c.tone" :icon="c.icon" :foot-icon="c.footIcon" :label="c.label" :value="c.value" :chip="c.chip" :to="c.to" />
        </template>
      </section>

      <template v-if="stats">
        <!-- data storage by file type -->
        <storage-bar
          class="ard-gap"
          :title="can.storage ? $t('Data Storage') : $t('File Types')"
          :used="can.storage ? fmt(stats.totals.storage_bytes) : Number(stats.totals.files).toLocaleString()"
          :sub="can.storage ? $t('used by :n files', { n: Number(stats.totals.files).toLocaleString() }) : $t('files')"
          :segments="storageSegments"
        />

        <!-- at a glance -->
        <section class="ard-section">
          <div class="ard-section-head"><h2>{{ $t('At a Glance') }}</h2></div>
          <div class="ar-tiles ard-tiles">
            <component :is="tile.to ? 'Link' : 'div'" v-for="(tile, i) in glanceTiles" :key="tile.key" :href="tile.to" class="ar-tile lg-tip" :data-tip="tile.tip" :style="{ '--i': i }">
              <span class="ar-tile-ico" :class="'lg-ico-' + tile.tone"><icons :name="tile.icon" class="w-4 h-4" /></span>
              <span class="ar-tile-body">
                <span class="ar-tile-value"><animated-number v-if="tile.num !== undefined" :value="tile.num" use-grouping /><template v-else>{{ tile.value }}</template></span>
                <span class="ar-tile-label">{{ tile.label }}</span>
                <span v-if="tile.sub" class="ar-tile-sub">{{ tile.sub }}</span>
              </span>
            </component>
          </div>
        </section>

        <!-- frequently used folders -->
        <section class="ard-section">
          <div class="ard-section-head"><h2>{{ $t('Folders') }}</h2><Link v-if="can.tree" :href="route('archive.tree')" class="ard-link">{{ $t('Open Archive Tree') }}</Link></div>
          <div v-if="stats.categories.length > 1" class="ard-fsearch">
            <icons name="search" class="ard-fsearch-ico w-4 h-4" />
            <input v-model="folderQ" type="search" class="ard-fsearch-input" :placeholder="$t('Search folders…')" :aria-label="$t('Search folders')" autocomplete="off" />
            <span class="ard-fsearch-count">{{ shownFolders.length }} / {{ stats.categories.length }}</span>
          </div>
          <div v-if="shownFolders.length" class="ard-folders">
            <folder-tile v-for="(c, i) in shownFolders" :key="c.label" :index="i" :scale="0.9" :tone="tone(c.label)" :name="c.label" :meta="c.count.toLocaleString() + ' ' + $t('docs') + ' · ' + c.files.toLocaleString() + ' ' + $t('files') + (can.storage && c.bytes ? ' · ' + fmt(c.bytes) : '')" :to="folderLink(c)" />
          </div>
          <p v-else-if="stats.categories.length" class="lg-empty">{{ $t('No folder matches “:q”.', { q: folderQ }) }}</p>
          <p v-else class="lg-empty">{{ $t('No archive records found.') }}</p>
        </section>

        <!-- recently viewed / downloaded -->
        <section class="ard-section">
          <div class="ard-section-head"><h2>{{ $t('Recently Viewed') }} <small>{{ can.access ? $t('by everyone') : $t('by you') }}</small></h2></div>
          <div v-if="stats.recent.viewed.length" class="ard-recent">
            <recent-card v-for="(r, i) in stats.recent.viewed" :key="'v' + i" :index="i" :to="route('archive.records.show', r.record_id)" :icon="fileIcon(r)" :tone="fileTone(r)" :title="r.filename || r.title" :size="r.reference" :sub="r.user + ' · ' + $datetime(r.at)" />
          </div>
          <p v-else class="lg-empty">{{ $t('Nothing viewed yet.') }}</p>
        </section>

        <section class="ard-section">
          <div class="ard-section-head"><h2>{{ $t('Recently Downloaded') }} <small>{{ can.access ? $t('by everyone') : $t('by you') }}</small></h2></div>
          <div v-if="stats.recent.downloaded.length" class="ard-recent">
            <recent-card v-for="(r, i) in stats.recent.downloaded" :key="'d' + i" :index="i" :to="route('archive.records.show', r.record_id)" icon="download" tone="green" :title="r.filename || r.title" :size="r.reference" :sub="r.user + ' · ' + $datetime(r.at)" />
          </div>
          <p v-else class="lg-empty">{{ $t('Nothing downloaded yet.') }}</p>
        </section>

        <!-- charts -->
        <div class="ard-grid">
          <section class="ard-card ard-wide">
            <div class="ard-card-head"><span class="ard-ico is-sky"><icons name="chart-bar" class="w-5 h-5" /></span><div><h2>{{ $t('Documents Added') }}</h2><small>{{ $t('last 12 months') }}</small></div></div>
            <column-chart :data="stats.monthly" :unit="$t('documents')" />
          </section>
          <section class="ard-card">
            <div class="ard-card-head"><span class="ard-ico is-violet"><icons name="collection" class="w-5 h-5" /></span><div><h2>{{ $t('Document Sources') }}</h2><small>{{ $t('where records come from') }}</small></div></div>
            <ul v-if="stats.sources.length" class="ar-bars">
              <li v-for="c in stats.sources" :key="c.label">
                <Link :href="listLink({ source_type: c.label })" class="ar-bar-link lg-tip" :data-tip="c.count.toLocaleString() + ' ' + $t('documents from') + ' ' + c.label">
                  <div class="ar-bar-top"><span class="ar-bar-name">{{ c.label }}</span><span class="ar-bar-meta"><b>{{ c.count.toLocaleString() }}</b></span></div>
                  <div class="ar-bar"><span class="ar-fill-amber" :style="{ width: share(c.count, stats.sources) + '%' }"></span></div>
                </Link>
              </li>
            </ul>
            <p v-else class="lg-empty">{{ $t('No archive records found.') }}</p>
          </section>
          <section v-if="stats.file_types.length" class="ard-card">
            <div class="ard-card-head"><span class="ard-ico is-orange"><icons name="clip" class="w-5 h-5" /></span><div><h2>{{ can.storage ? $t('Storage Usage') : $t('File Types') }}</h2><small>{{ $t('by file type') }}</small></div></div>
            <donut-chart :segments="donut" :center-value="donutCenter" :center-label="can.storage ? $t('Storage used') : $t('Files')" />
            <dl v-if="can.storage" class="arx-mini">
              <div class="lg-tip" :data-tip="$t('Total size of the files exactly as they were uploaded.')"><dt>{{ $t('Original') }}</dt><dd>{{ fmt(stats.totals.original_bytes) }}</dd></div>
              <div class="lg-tip" :data-tip="$t('Size actually kept on disk (optimised files + thumbnails; untouched files count once).')"><dt>{{ $t('Actual used') }}</dt><dd class="is-maroon">{{ fmt(stats.totals.storage_bytes) }}</dd></div>
              <div class="lg-tip" :data-tip="$t('Original minus actual storage. Grows as files are optimised.')"><dt>{{ $t('Space saved') }}</dt><dd>{{ fmt(stats.totals.saved_bytes) }}</dd></div>
              <div class="lg-tip" :data-tip="$t('Generated packages are not stored yet.')"><dt>{{ $t('Packages') }}</dt><dd>{{ fmt(stats.totals.package_bytes) }}</dd></div>
            </dl>
          </section>
        </div>
      </template>
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DateRangePicker from '@/Shared/DateRangePicker.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import FolderIcon from '@/Shared/Archive/FolderIcon.vue';
import StatCard from '@/Shared/Page/StatCard.vue';
import StorageBar from '@/Shared/Page/StorageBar.vue';
import RecentCard from '@/Shared/Page/RecentCard.vue';
import FolderTile from '@/Shared/Page/FolderTile.vue';
import DonutChart from '@/Shared/Archive/DonutChart.vue';
import ColumnChart from '@/Shared/Archive/ColumnChart.vue';
import { Link } from '@inertiajs/vue3';
import { formatBytes, formatMb, FILE_TYPE_LOOK, folderTone } from '@/Core/archiveFormat';

export default {
  components: { AdminLayout, PageHeader, AutoComplete, DateRangePicker, AnimatedNumber, FolderIcon, DonutChart, ColumnChart, StatCard, StorageBar, RecentCard, FolderTile, Link },

  props: { options: Object, can: Object },

  data() {
    return {
      showFilters: false,
      folderQ: '',
      stats: null,
      loading: true,
      error: null,
      range: { start: null, end: null },
      filters: { category: null, source_type: null, warehouse_id: null, file_type: null },
      quick: { q: '', results: [], done: false },
    };
  },

  computed: {
    params() {
      const p = { ...this.filters, date_from: this.range.start, date_to: this.range.end };
      return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== null && v !== '' && v !== undefined));
    },
    hasFilters() {
      return Object.keys(this.params).length > 0;
    },
    activeChips() {
      const chips = [];
      const projectName = id => (this.options.projects.find(p => p.value == id) || {}).label || id;
      if (this.params.date_from || this.params.date_to) chips.push({ key: 'range', label: (this.params.date_from || '…') + ' – ' + (this.params.date_to || '…') });
      if (this.params.category) chips.push({ key: 'category', label: this.params.category });
      if (this.params.source_type) chips.push({ key: 'source_type', label: this.params.source_type });
      if (this.params.warehouse_id) chips.push({ key: 'warehouse_id', label: projectName(this.params.warehouse_id) });
      if (this.params.file_type) chips.push({ key: 'file_type', label: this.params.file_type });
      return chips;
    },
    donut() {
      return this.stats.file_types.map(t => ({
        key: t.type,
        label: this.look(t.type).label,
        value: this.can.storage ? t.bytes : t.files,
        color: 'var(--arx-' + this.look(t.type).cls + ')',
        text: t.files.toLocaleString() + ' ' + this.$t('files') + (this.can.storage ? ' · ' + formatBytes(t.bytes) : ''),
      }));
    },
    donutCenter() {
      return this.can.storage ? formatBytes(this.stats.totals.storage_bytes) : Number(this.stats.totals.files).toLocaleString();
    },
    // search-as-you-type over the folder (category) names
    shownFolders() {
      const q = this.folderQ.trim().toLowerCase();
      return q ? this.stats.categories.filter(c => c.label.toLowerCase().includes(q)) : this.stats.categories;
    },
    bigCards() {
      const t = this.stats.totals;
      const n = v => Number(v || 0);
      const cards = [
        { key: 'documents', tone: 'orange', icon: 'collection', label: this.$t('Total Documents'), value: n(t.documents), chip: '+' + n(t.this_month) + ' ' + this.$t('this month'), to: this.listLink({}) },
        { key: 'files', tone: 'purple', icon: 'clip', label: this.$t('Total Files'), value: n(t.files), chip: '+' + n(t.today) + ' ' + this.$t('today') },
      ];
      if (this.can.storage) cards.push({ key: 'storage', tone: 'teal', icon: 'chart-bar', label: this.$t('Storage Used'), value: formatMb(t.storage_bytes), chip: this.$t('saved') + ' ' + formatBytes(t.saved_bytes) });
      else cards.push({ key: 'month', tone: 'teal', icon: 'calendar', label: this.$t('Added This Month'), value: n(t.this_month) });
      cards.push({ key: 'expiring', tone: 'rose', icon: 'clock', label: this.$t('Expiring Soon'), value: n(t.expiring), chip: n(t.expired) + ' ' + this.$t('expired'), to: this.listLink({ expiry: 'expiring' }) });
      return cards;
    },
    storageSegments() {
      const palette = { office: '#2563eb', pdf: '#e11d48', image: '#f97316', archive: '#8b5cf6', text: '#14b8a6', other: '#64748b' };
      return this.stats.file_types.map(t => {
        const look = this.look(t.type);
        return { key: t.type, label: look.label, value: this.can.storage ? Math.max(t.bytes, 1) : Math.max(t.files, 1), color: palette[look.cls] || '#64748b', icon: look.icon, text: t.files.toLocaleString() + ' ' + this.$t('files') + (this.can.storage ? ' · ' + formatBytes(t.bytes) : '') };
      });
    },
    glanceTiles() {
      return this.tiles.filter(t => !['documents', 'files', 'storage'].includes(t.key));
    },
    tiles() {
      const s = this.stats.totals;
      const n = v => Number(v || 0);
      const list = q => this.listLink(q);
      const tiles = [
        { key: 'documents', icon: 'collection', tone: 'navy', label: this.$t('Total Documents'), num: n(s.documents), to: list({}), tip: this.$t('Total number of document records currently archived in WIMS.') },
        { key: 'files', icon: 'clip', tone: 'sky', label: this.$t('Total Files'), num: n(s.files), tip: this.$t('Total number of individual files stored across all archive records.') },
      ];
      if (this.can.storage) {
        tiles.push(
          { key: 'storage', icon: 'chart-bar', tone: 'emerald', label: this.$t('Storage Used'), value: formatMb(s.storage_bytes), sub: formatBytes(s.storage_bytes), tip: this.$t('Total storage consumed by archived files: the optimised copy when one exists, plus thumbnails. Originals are not counted twice.') },
          { key: 'saved', icon: 'trend', tone: 'teal', label: this.$t('Storage Saved'), value: formatBytes(s.saved_bytes), sub: this.$t('of') + ' ' + formatBytes(s.original_bytes) + ' ' + this.$t('original'), tip: this.$t('Storage space saved through file optimisation: original size minus the size actually kept.') }
        );
      }
      tiles.push(
        { key: 'today', icon: 'calendar', tone: 'indigo', label: this.$t('Added Today'), num: n(s.today), tip: this.$t('Records created today (since midnight).') },
        { key: 'month', icon: 'calendar', tone: 'violet', label: this.$t('This Month'), num: n(s.this_month), tip: this.$t('Records created since the 1st of this month.') },
        { key: 'updated', icon: 'edit', tone: 'sky', label: this.$t('Recently Updated'), num: n(s.recently_updated), tip: this.$t('Records edited in the last 7 days (not counting brand-new ones).') },
        { key: 'expiring', icon: 'clock', tone: 'amber', label: this.$t('Expiring Soon'), num: n(s.expiring), to: list({ expiry: 'expiring' }), tip: this.$t('Documents whose expiry date falls within the next 30 days.') },
        { key: 'expired', icon: 'exclamation', tone: 'rose', label: this.$t('Expired'), num: n(s.expired), to: list({ expiry: 'expired' }), tip: this.$t('Documents whose expiry date has already passed.') },
        { key: 'confidential', icon: 'lock-closed', tone: 'slate', label: this.$t('Confidential'), num: n(s.confidential), to: this.can.confidential ? list({ confidentiality: 'Confidential' }) : null, tip: this.$t('Confidential and Restricted records you are allowed to see.') }
      );
      return tiles;
    },
  },

  watch: {
    params: { handler: debounce(function () { this.load(); }, 300), deep: true },
  },

  mounted() {
    this.load();
  },

  methods: {
    opt(list) {
      return (list || []).map(v => ({ label: v, value: v }));
    },
    fmt: formatBytes,
    fileIcon(r) {
      return /\.(jpe?g|png|gif|webp|bmp|tiff?)$/i.test(r.filename || '') ? 'image' : 'doc';
    },
    fileTone(r) {
      const f = (r.filename || '').toLowerCase();
      if (/\.pdf$/.test(f)) return 'red';
      if (/\.(docx?|rtf|odt)$/.test(f)) return 'blue';
      if (/\.(xlsx?|csv|ods)$/.test(f)) return 'green';
      if (/\.(jpe?g|png|gif|webp|bmp|tiff?)$/.test(f)) return 'orange';
      return 'purple';
    },
    look(type) {
      return FILE_TYPE_LOOK[type] || FILE_TYPE_LOOK.Other;
    },
    tone(name) {
      return folderTone(name);
    },
    weight(t) {
      return this.can.storage ? Math.max(t.bytes, 1) : Math.max(t.files, 1);
    },
    shareText(t) {
      return t.files.toLocaleString() + ' ' + this.$t('files') + (this.can.storage ? ' · ' + formatBytes(t.bytes) + ' · ' + t.percent + '%' : '');
    },
    pctFiles(t) {
      const total = this.stats.file_types.reduce((a, b) => a + b.files, 0) || 1;
      return Math.round((t.files / total) * 100);
    },
    share(count, rows) {
      const max = Math.max(...rows.map(r => r.count), 1);
      return Math.max(Math.round((count / max) * 100), 2);
    },
    // a folder opens the records list already filtered to that folder (plus any dashboard filters in force)
    folderLink(c) {
      return this.listLink({ category: c.label });
    },
    listLink(extra) {
      return this.route('archive.records.index', { ...this.paramsForList(), ...extra });
    },
    // Carry the dashboard filters into the list so a card/bar click shows exactly the records that were counted.
    paramsForList() {
      const p = this.params;
      const out = {};
      if (p.category) out.category = p.category;
      if (p.source_type) out.source_type = p.source_type;
      if (p.warehouse_id) out.warehouse_id = p.warehouse_id;
      if (p.file_type) out.file_type = p.file_type;
      if (p.date_from) out.document_from = p.date_from;
      if (p.date_to) out.document_to = p.date_to;
      return out;
    },
    load() {
      this.loading = this.stats === null;
      this.error = null;
      window.axios.get(this.route('archive.dashboard.stats'), { params: this.params })
        .then(res => { this.stats = res.data; })
        .catch(() => { this.error = this.$t('Unable to load the dashboard figures.'); })
        .finally(() => { this.loading = false; });
    },
    clearFilters() {
      this.filters = { category: null, source_type: null, warehouse_id: null, file_type: null };
      this.range = { start: null, end: null };
    },
    removeChip(key) {
      if (key === 'range') this.range = { start: null, end: null };
      else this.filters[key] = null;
    },
    onQuick: debounce(function () {
      const q = this.quick.q.trim();
      if (q.length < 2) { this.quick.results = []; this.quick.done = false; return; }
      window.axios.get(this.route('archive.records.quick'), { params: { q } })
        .then(res => { if (q === this.quick.q.trim()) { this.quick.results = res.data.data; this.quick.done = true; } })
        .catch(() => { this.quick.results = []; });
    }, 300),
    goSearch() {
      const q = this.quick.q.trim();
      this.$inertia.visit(this.route('archive.records.index', q ? { q } : {}));
    },
  },
};
</script>

<style scoped>
.ar-quick { padding: 0.9rem 1rem; }
.ar-search { position: relative; display: flex; align-items: center; gap: 0.6rem; }
.ar-search-ico { position: absolute; left: 0.95rem; color: var(--lg-faint); pointer-events: none; }
.ar-search-input { flex: 1 1 auto; min-width: 0; height: 2.9rem; padding: 0 2.6rem 0 2.8rem; border-radius: 1rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.92rem; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.ar-search-input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.18); }
.ar-search-clear { position: absolute; right: 7.6rem; top: 50%; transform: translateY(-50%); color: var(--lg-faint); }
.ar-search-go { flex: none; height: 2.9rem; }
.ar-suggest { position: absolute; left: 0; right: 0; top: calc(100% + 6px); z-index: 40; margin: 0; padding: 6px; list-style: none; border-radius: 1rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 22px 44px -18px rgba(15, 23, 42, 0.45); max-height: 340px; overflow-y: auto; }
.ar-suggest-row { display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.7rem; border-radius: 0.75rem; min-width: 0; }
.ar-suggest-row:hover { background: var(--lg-soft); }
.ar-suggest-title { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; color: var(--lg-ink); font-size: 0.85rem; }
.ar-suggest-empty { padding: 0.8rem; text-align: center; color: var(--lg-faint); font-size: 0.82rem; }

.ar-filters { padding: 0.9rem 1rem 1rem; }
.ar-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr)); gap: 0.75rem; align-items: end; }
.ar-active { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 0.75rem; }
.ar-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 6px 3px 11px; border-radius: 9999px; background: rgba(79, 70, 229, 0.12); color: #4338ca; font-size: 0.74rem; font-weight: 700; }
.ar-chip button { width: 18px; height: 18px; border-radius: 9999px; line-height: 1; font-size: 0.95rem; color: inherit; background: rgba(79, 70, 229, 0.14); }
html.dark .ar-chip { color: #c7d2fe; }

.ar-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(190px, 100%), 1fr)); gap: 10px; margin-bottom: 1rem; }
.ar-tile { position: relative; display: flex; align-items: center; gap: 10px; min-width: 0; padding: 10px 12px; border-radius: 16px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); transition: transform 0.15s ease, box-shadow 0.15s ease; animation: ar-rise 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; animation-delay: calc(var(--i, 0) * 45ms); }
.ar-tile:hover { transform: translateY(-2px); }
.ar-tile-ico { display: inline-flex; flex: none; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 11px; color: #fff; }
.ar-tile-body { display: flex; flex-direction: column; min-width: 0; line-height: 1.15; }
.ar-tile-value { font-size: 1.2rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; white-space: nowrap; }
.ar-tile-label { margin-top: 2px; font-size: 0.6rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.ar-tile-sub { margin-top: 1px; font-size: 0.66rem; font-weight: 700; color: var(--lg-mute); }
@keyframes ar-rise { from { opacity: 0; transform: translateY(10px) scale(0.97); } to { opacity: 1; transform: none; } }

.ar-skel { animation: none; }
.ar-skel-ico, .ar-skel-lines i, .ar-skel-panel i { background: linear-gradient(90deg, var(--lg-soft), rgba(148, 163, 184, 0.28), var(--lg-soft)); background-size: 200% 100%; animation: ar-shimmer 1.3s linear infinite; }
.ar-skel-ico { width: 34px; height: 34px; border-radius: 11px; flex: none; }
.ar-skel-lines { display: flex; flex-direction: column; gap: 6px; flex: 1; }
.ar-skel-lines i { height: 10px; border-radius: 6px; } .ar-skel-lines i:first-child { width: 55%; height: 14px; }
.ar-skel-panel { display: flex; flex-direction: column; gap: 10px; } .ar-skel-panel i { height: 14px; border-radius: 7px; }
@keyframes ar-shimmer { to { background-position: -200% 0; } }

.ar-bars { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 11px; }
.ar-bar-link { display: block; }
.ar-bar-top { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 4px; min-width: 0; }
.ar-bar-name { display: inline-flex; align-items: center; gap: 6px; font-weight: 800; font-size: 0.8rem; color: var(--lg-ink); min-width: 0; }
.ar-bar-meta { font-size: 0.72rem; color: var(--lg-mute); text-align: right; font-variant-numeric: tabular-nums; }
.ar-bar { height: 8px; border-radius: 9999px; background: var(--lg-soft); overflow: hidden; }
.ar-bar span { display: block; height: 100%; border-radius: 9999px; transition: width 0.5s ease; }
.ar-fill-amber { background: linear-gradient(90deg, #fbbf24, #b45309); }
.ar-retry { text-decoration: underline; font-weight: 800; }
@media (max-width: 575.98px) {
  .ar-search { flex-wrap: wrap; } .ar-search-go { width: 100%; } .ar-search-clear { right: 0.9rem; top: 1.45rem; }
  .ar-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; } .ar-tile { padding: 9px 10px; gap: 8px; } .ar-tile-value { font-size: 1.05rem; }
}
@media (prefers-reduced-motion: reduce) { .ar-tile { animation: none; } .ar-bar span { transition: none; } }

/* redesigned dashboard */
.ard-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; margin-bottom: 1rem; padding: 12px 14px; border-radius: 1.6rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.45); }
.ard-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
.ard-title-ico { flex: none; width: 48px; height: 48px; display: grid; place-items: center; border-radius: 1.1rem; color: #fff; background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 12px 20px -10px rgba(217, 119, 6, 0.8); }
.ard-title h1 { margin: 0; font-size: 1.15rem; font-weight: 900; color: var(--lg-ink); }
.ard-title p { margin: 1px 0 0; font-size: 0.78rem; color: var(--lg-faint); }
.ard-search { flex: 1 1 300px; min-width: 0; }
.ard-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.ard-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 2.8rem; padding: 0 1.1rem; border: 1px solid var(--lg-line); border-radius: 1rem; font-size: 0.85rem; font-weight: 800; text-decoration: none; cursor: pointer; transition: transform 0.12s ease; touch-action: manipulation; }
.ard-btn:active { transform: scale(0.97); }
.ard-btn.is-soft { background: var(--lg-card); color: var(--lg-ink); }
.ard-btn.is-soft.is-on { border-color: #d97706; color: #b45309; background: rgba(217, 119, 6, 0.08); }
.ard-btn.is-dark { background: #0b1220; color: #fff; border-color: #0b1220; box-shadow: 0 14px 24px -12px rgba(11, 18, 32, 0.8); }
html.dark .ard-btn.is-dark { background: #e2e8f0; color: #0b1220; border-color: #e2e8f0; }
.ard-filter-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 0.9rem; }
.ard-clear { font-size: 0.78rem; font-weight: 800; color: #be123c; text-decoration: underline; }
.ard-card { padding: 1.1rem; border-radius: 1.5rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.45); margin-bottom: 1rem; }
.ard-slide-enter-active, .ard-slide-leave-active { transition: opacity 0.2s ease; }
.ard-slide-enter-from, .ard-slide-leave-to { opacity: 0; }
.ard-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }
.ard-skel { min-height: 156px; border-radius: 1.5rem; background: linear-gradient(90deg, var(--lg-line), var(--lg-soft), var(--lg-line)); background-size: 200% 100%; animation: ard-shimmer 1.2s linear infinite; }
.ard-gap { margin-top: 1rem; }
.ard-section { margin-top: 1.3rem; }
.ard-section-head { display: flex; align-items: center; justify-content: space-between; margin: 0 2px 0.7rem; }
.ard-section-head h2 { margin: 0; font-size: 1.1rem; font-weight: 900; color: var(--lg-ink); }
.ard-section-head h2 small { font-size: 0.74rem; font-weight: 600; color: var(--lg-faint); margin-left: 6px; }
.ard-link { color: #2563eb; font-weight: 800; font-size: 0.82rem; }
.ard-folders { display: grid; grid-template-columns: repeat(auto-fill, minmax(171px, 1fr)); gap: 12px; }
.ard-fsearch { position: relative; display: flex; align-items: center; margin: 0 0 0.8rem; max-width: 520px; }
.ard-fsearch-ico { position: absolute; left: 14px; color: var(--lg-faint); pointer-events: none; }
.ard-fsearch-input { width: 100%; height: 2.7rem; padding: 0 4.2rem 0 2.5rem; border-radius: 1rem; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-size: 0.88rem; box-shadow: 0 8px 18px -14px rgba(15, 23, 42, 0.5); }
.ard-fsearch-input:focus { outline: 2px solid #d97706; outline-offset: 1px; }
.ard-fsearch-count { position: absolute; right: 14px; font-size: 0.74rem; font-weight: 800; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
.ard-recent { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.ard-tiles { margin-bottom: 0; }
.ard-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-top: 1.3rem; }
.ard-grid > * { min-width: 0; margin-bottom: 0; }
.ard-wide { grid-column: 1 / -1; overflow: hidden; }
.ard-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 0.9rem; }
.ard-card-head h2 { margin: 0; font-size: 1rem; font-weight: 900; color: var(--lg-ink); }
.ard-card-head small { color: var(--lg-faint); font-size: 0.74rem; }
.ard-ico { flex: none; width: 42px; height: 42px; display: grid; place-items: center; border-radius: 1rem; color: #fff; }
.ard-ico.is-sky { background: linear-gradient(145deg, #38bdf8, #0284c7); } .ard-ico.is-violet { background: linear-gradient(145deg, #8b5cf6, #5b3df5); } .ard-ico.is-orange { background: linear-gradient(145deg, #fb923c, #ea580c); }
@keyframes ard-shimmer { to { background-position: -200% 0; } }
@media (max-width: 860px) { .ard-grid { grid-template-columns: 1fr; } }
@media (max-width: 767.98px) { .ard-actions { width: 100%; } .ard-actions > * { flex: 1; } .ard-btn span { white-space: nowrap; } }
@media (max-width: 575.98px) { .ard-cards { grid-template-columns: 1fr 1fr; gap: 10px; } }
@media (prefers-reduced-motion: reduce) { .ard-skel { animation: none; } }
</style>
