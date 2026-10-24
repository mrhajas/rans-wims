<template>
  <admin-layout :title="$t('Site Diaries')">
    <div class="lg-page sdy sdi px-4 md:px-0">
      <!-- app bar: title · search · actions -->
      <header class="sdi-bar">
        <div class="sdi-bar-title">
          <span class="sdi-bar-ico"><icons name="camera" class="w-6 h-6" /></span>
          <div class="min-w-0"><h1>{{ $t('Site Diaries') }}</h1><p>{{ $t('Daily project diaries with remarks, photos and videos.') }}</p></div>
        </div>
        <div class="sdi-search">
          <icons name="search" class="sdi-search-ico w-5 h-5" />
          <input v-model="form.search" type="search" class="sdi-search-input" :placeholder="$t('Search reference, project, recorder, remarks, date…')" :aria-label="$t('Search')" />
        </div>
        <div class="sdi-bar-actions">
          <Link v-if="can.analytics" :href="route('site-diary.analytics')" class="sdi-btn is-soft"><icons name="chart-pie" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
          <Link v-if="can.reports" :href="route('site-diary.reports')" class="sdi-btn is-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
          <offline-lock v-if="can.create">
            <Link :href="route('site-diary.create', form.warehouse_id ? { warehouse_id: form.warehouse_id } : {})" class="sdi-btn is-dark"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Site Diary') }}</span></Link>
          </offline-lock>
        </div>
      </header>

      <!-- overview (hidden while searching / filtering) -->
      <template v-if="overview">
        <storage-bar
          :title="$t('Data Storage')"
          :used="fmt(storage.total)"
          :sub="$t('used by :n files', { n: (storage.photos.files + storage.videos.files).toLocaleString() })"
          :segments="storageSegments"
        />

        <section v-if="recent.length" class="sdi-section">
          <div class="sdi-section-head"><h2>{{ $t('Recently Added') }}</h2><Link v-if="can.analytics" :href="route('site-diary.analytics')" class="sdi-link">{{ $t('View All') }}</Link></div>
          <div class="sdi-recent">
            <recent-card v-for="(r, i) in recent" :key="r.id" :index="i" :to="route('site-diary.show', r.id)" :cover="r.cover" :icon="r.videos ? 'video' : 'camera'" :tone="r.videos ? 'purple' : 'orange'" :title="r.project" :size="fmt(r.bytes)" :sub="$date(r.date) + ' · ' + r.by" />
          </div>
        </section>

        <section v-if="top_projects.length" class="sdi-section">
          <div class="sdi-section-head"><h2>{{ $t('Frequently Used Projects') }}</h2><button v-if="!projQ && top_projects.length > 6" type="button" class="sdi-link sdi-linkbtn" @click="allProjects = !allProjects">{{ allProjects ? $t('Show fewer') : $t('Show all') + ' (' + top_projects.length + ')' }}</button></div>
          <folder-search v-if="top_projects.length > 6" v-model="projQ" :shown="shownProjects.length" :total="top_projects.length" :placeholder="$t('Search projects…')" />
          <div v-if="shownProjects.length" class="sdi-folders">
            <folder-tile v-for="(p, i) in shownProjects" :key="p.id" :index="i" :scale="0.9" :tone="tones[projectIndex(p) % tones.length]" :name="p.label" :meta="p.count + ' ' + $t('diaries') + ' · ' + fmt(p.bytes)" :to="route('site-diary.index', { warehouse_id: p.id })" />
          </div>
          <p v-else class="lg-empty">{{ $t('No project matches “:q”.', { q: projQ }) }}</p>
        </section>
      </template>

      <!-- project card (when one project is filtered) -->
      <section v-if="summary" class="sdi-card sdi-summary">
        <div class="sdi-sum-main">
          <span class="sdi-sum-ico"><icons name="building" class="w-5 h-5" /></span>
          <div>
            <h2>{{ summary.project }}</h2>
            <p>{{ summary.total }} {{ $t('diaries') }}<template v-if="summary.latest"> · {{ $t('latest') }} <Link :href="route('site-diary.show', summary.latest.id)" class="sdi-link">{{ $date(summary.latest.date) }}</Link> {{ $t('by') }} {{ summary.latest.by }}</template></p>
          </div>
        </div>
        <div v-if="summary.photos.length" class="sdi-sum-photos">
          <Link v-for="p in summary.photos" :key="p.id" :href="route('site-diary.show', p.diary)"><img :src="p.url" alt="" loading="lazy" /></Link>
        </div>
        <ul v-if="summary.remarks.length" class="sdi-sum-remarks">
          <li v-for="r in summary.remarks" :key="r.id"><b>{{ $date(r.date) }}</b> · {{ r.by }} - {{ r.text }}</li>
        </ul>
      </section>

      <!-- filters -->
      <section class="sdi-card sdi-filters relative z-20">
        <div class="lg-fields sdy-filter-grid">
          <auto-complete id="f_project" :label="$t('Project')" v-model="form.warehouse_id" :suggestions="projects" clearable :placeholder="$t('All projects')" />
          <auto-complete v-if="can.viewAll" id="f_user" :label="$t('Recorded by')" v-model="form.recorded_by" :suggestions="recorders" clearable :placeholder="$t('Anyone')" />
          <date-range-picker :label="$t('Diary date')" v-model="range" />
          <div class="sdy-toggles">
            <span class="sdy-fixed-k">{{ $t('Has media') }}</span>
            <div class="sdy-seg">
              <button type="button" :class="{ 'is-on': form.has_photos === '1' }" @click="toggleFlag('has_photos')"><icons name="image" class="w-3.5 h-3.5" />{{ $t('Photos') }}</button>
              <button v-if="limits.videos.enabled" type="button" :class="{ 'is-on': form.has_videos === '1' }" @click="toggleFlag('has_videos')"><icons name="video" class="w-3.5 h-3.5" />{{ $t('Videos') }}</button>
            </div>
          </div>
        </div>
        <div class="sdy-filter-foot">
          <button type="button" class="sdi-btn is-soft" @click="advanced = !advanced"><icons name="filter" class="w-4 h-4" />{{ advanced ? $t('Fewer filters') : $t('More filters') }}</button>
          <button v-if="activeCount" type="button" class="sdi-btn is-soft" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }} ({{ activeCount }})</button>
          <label v-if="can.restore" class="sdy-check"><input type="checkbox" class="sdy-cb" :checked="form.trashed === 'only'" @change="form.trashed = $event.target.checked ? 'only' : ''" /> {{ $t('Show deleted') }}</label>
        </div>
        <transition name="sdy-slide">
          <div v-show="advanced" class="lg-fields sdy-adv">
            <date-range-picker :label="$t('Created between')" v-model="createdRange" />
          </div>
        </transition>
      </section>

      <!-- list -->
      <div class="sdy-meta">
        <span>{{ diaries.meta.total.toLocaleString() }} {{ $t('result(s)') }}</span>
        <label class="sdy-per"><span>{{ $t('Per page') }}</span>
          <css-select v-model.number="form.per_page"><option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ n }}</option></css-select>
        </label>
      </div>

      <div class="sdi-card sdi-table" :class="{ 'sdy-busy': busy }">
        <div class="lg-table-wrap -mx-4 md:mx-0">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="num">#</th>
                <th v-for="c in columns" :key="c.key" :class="[{ 'sdy-sortable': c.sort, 'is-sorted': form.sort === c.sort }, c.cls]" :aria-sort="c.sort ? ariaSort(c.sort) : null" @click="c.sort && sortBy(c.sort)">
                  <span class="sdy-th">{{ c.label }}<span v-if="c.sort" class="sdy-arrows"><span :class="{ on: form.sort === c.sort && form.direction === 'asc' }">&#9650;</span><span :class="{ on: form.sort === c.sort && form.direction !== 'asc' }">&#9660;</span></span></span>
                </th>
                <th>{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!diaries.data.length" class="lg-empty-row"><td class="lg-empty" :colspan="columns.length + 2">{{ $t('No site diaries found.') }}</td></tr>
              <tr v-for="(d, i) in diaries.data" :key="d.id" class="is-click" :class="{ 'sdy-deleted': d.deleted }" @click="open(d)">
                <td class="num" :data-label="$t('No')">{{ diaries.meta.from + i }}</td>
                <td :data-label="$t('Reference')"><span class="sdi-ref"><span class="sdi-ref-ico lg-tip" :class="mediaKind(d).tone" :data-tip="mediaKind(d).tip"><icons :name="mediaKind(d).icon" class="w-4 h-4" /></span><span class="lg-main">{{ d.reference }}</span></span></td>
                <td class="sdy-project-cell" :data-label="$t('Project')"><span class="lg-main">{{ d.project }}</span></td>
                <td :data-label="$t('Date')">{{ $date(d.date) }}</td>
                <td :data-label="$t('Recorded by')"><span class="sdi-person"><i :style="{ background: color(d.recorded_by) }">{{ initials(d.recorded_by) }}</i>{{ d.recorded_by }}</span></td>
                <td class="sdy-remarks-cell" :data-label="$t('Remarks')"><span class="sdy-remarks-txt">{{ d.remarks || '—' }}</span></td>
                <td class="num" :data-label="$t('Photos')"><span class="lg-chip" :class="d.photos ? 'lg-chip-blue' : 'lg-chip-slate'" style="text-transform: none">{{ d.photos }}</span></td>
                <td v-if="limits.videos.enabled" class="num" :data-label="$t('Videos')"><span class="lg-chip" :class="d.videos ? 'lg-chip-indigo' : 'lg-chip-slate'" style="text-transform: none">{{ d.videos }}</span></td>
                <td class="num sdi-size" :data-label="$t('Attachments Size')"><b>{{ d.bytes ? fmt(d.bytes) : '—' }}</b></td>
                <td :data-label="$t('Created')"><span class="lg-subtext">{{ $datetime(d.created_at) }}</span></td>
                <td :data-label="$t('Updated')"><span class="lg-subtext">{{ $datetime(d.updated_at) }}</span></td>
                <td v-if="can.viewers" class="sdy-viewers-cell" :data-label="$t('Viewed By')">
                  <span v-if="!d.viewers.length" class="sdi-nobody">{{ $t('Not viewed yet') }}</span>
                  <span v-else class="sdy-viewers lg-tip tip-left" :data-tip="viewersTip(d.viewers)">
                    <span v-for="v in d.viewers.slice(0, 2)" :key="v.id" class="sdi-vchip" :class="'is-c' + (v.id % 7)">{{ v.name }}</span>
                    <span v-if="d.viewers.length > 2" class="sdi-vchip is-more">+{{ d.viewers.length - 2 }}</span>
                  </span>
                </td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="d.deleted ? 'lg-chip-red' : d.status === 'Draft' ? 'lg-chip-amber' : 'lg-chip-green'" style="text-transform: none">{{ d.status }}</span></td>
                <td class="sdy-actions-col" :data-label="$t('Actions')" @click.stop>
                  <div class="lg-actions">
                    <template v-if="!d.deleted">
                      <Link :href="route('site-diary.show', d.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></Link>
                      <Link v-if="can.history" :href="route('site-diary.show', d.id) + '#history'" class="lg-icon-btn lg-tip" :data-tip="$t('History')" :aria-label="$t('History')"><icons name="clock" class="w-4 h-4" /></Link>
                      <offline-lock v-if="can.create || can.update">
                        <Link :href="route('site-diary.edit', d.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit / Add media')" :aria-label="$t('Edit')"><icons name="edit" class="w-4 h-4" /></Link>
                      </offline-lock>
                    </template>
                    <button v-else-if="can.restore" type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Restore')" :aria-label="$t('Restore')" @click="restore(d)"><icons name="refresh" class="w-4 h-4" /></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <pagination v-if="diaries.data.length" class="mt-4" :meta="diaries.meta" :links="diaries.links" />
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import pickBy from 'lodash/pickBy';
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DateRangePicker from '@/Shared/DateRangePicker.vue';
import Pagination from '@/Shared/Pagination.vue';
import StorageBar from '@/Shared/Page/StorageBar.vue';
import RecentCard from '@/Shared/Page/RecentCard.vue';
import FolderTile from '@/Shared/Page/FolderTile.vue';
import FolderSearch from '@/Shared/Page/FolderSearch.vue';
import { formatBytes } from '@/Core/archiveFormat';

const PALETTE = ['#2563eb', '#059669', '#7c3aed', '#d97706', '#0891b2', '#e11d48', '#4f46e5'];

export default {
  components: { AdminLayout, Link, AutoComplete, DateRangePicker, Pagination, StorageBar, RecentCard, FolderTile, FolderSearch },

  props: { diaries: Object, filters: Object, projects: Array, recorders: Array, summary: Object, storage: Object, recent: Array, top_projects: Array, can: Object, limits: Object },

  data() {
    const f = this.filters || {};
    return {
      busy: false,
      projQ: '',
      allProjects: false,
      tones: ['amber', 'sky', 'emerald', 'violet', 'rose', 'indigo'],
      advanced: !!(f.created_from || f.created_to),
      form: {
        search: f.search || '',
        warehouse_id: f.warehouse_id ? Number(f.warehouse_id) : null,
        recorded_by: f.recorded_by ? Number(f.recorded_by) : null,
        date_from: f.date_from || null,
        date_to: f.date_to || null,
        created_from: f.created_from || null,
        created_to: f.created_to || null,
        has_photos: f.has_photos || '',
        has_videos: f.has_videos || '',
        sort: f.sort || null,
        direction: f.direction || 'desc',
        per_page: f.per_page ? Number(f.per_page) : 25,
        trashed: f.trashed || '',
      },
    };
  },

  computed: {
    range: {
      get() { return { start: this.form.date_from, end: this.form.date_to }; },
      set(v) { this.form.date_from = v.start; this.form.date_to = v.end; },
    },
    createdRange: {
      get() { return { start: this.form.created_from, end: this.form.created_to }; },
      set(v) { this.form.created_from = v.start; this.form.created_to = v.end; },
    },
    // folders: a search looks through every project; without one only the busiest few are shown (or all, on request)
    shownProjects() {
      const q = this.projQ.trim().toLowerCase();
      if (q) return this.top_projects.filter(p => p.label.toLowerCase().includes(q));
      return this.allProjects ? this.top_projects : this.top_projects.slice(0, 6);
    },
    overview() {
      return !this.activeCount && this.diaries.meta.current_page === 1;
    },
    storageSegments() {
      const s = this.storage;
      const out = [{ key: 'p', label: this.$t('Photos'), value: s.photos.bytes, color: '#fb923c', icon: 'image', text: s.photos.files.toLocaleString() + ' ' + this.$t('files') + ' · ' + this.fmt(s.photos.bytes) }];
      if (this.limits.videos.enabled || s.videos.files) out.push({ key: 'v', label: this.$t('Videos'), value: s.videos.bytes, color: '#14b8a6', icon: 'video', text: s.videos.files.toLocaleString() + ' ' + this.$t('files') + ' · ' + this.fmt(s.videos.bytes) });
      return out;
    },
    columns() {
      const c = [
        { key: 'ref', label: this.$t('Reference'), sort: 'reference' },
        { key: 'project', label: this.$t('Project'), sort: 'project' },
        { key: 'date', label: this.$t('Date'), sort: 'date' },
        { key: 'by', label: this.$t('Recorded by'), sort: 'recorded_by' },
        { key: 'remarks', label: this.$t('Remarks') },
        { key: 'photos', label: this.$t('Photos'), sort: 'photos', cls: 'num' },
      ];
      if (this.limits.videos.enabled) c.push({ key: 'videos', label: this.$t('Videos'), sort: 'videos', cls: 'num' });
      c.push({ key: 'size', label: this.$t('Attachments Size'), sort: 'size', cls: 'num' });
      c.push({ key: 'created', label: this.$t('Created'), sort: 'created' }, { key: 'updated', label: this.$t('Updated'), sort: 'updated' });
      if (this.can.viewers) c.push({ key: 'viewers', label: this.$t('Viewed By') });
      c.push({ key: 'status', label: this.$t('Status') });
      return c;
    },
    activeCount() {
      const f = this.form;
      return [f.search, f.warehouse_id, f.recorded_by, f.date_from || f.date_to, f.created_from || f.created_to, f.has_photos, f.has_videos, f.trashed].filter(Boolean).length;
    },
  },

  watch: {
    form: { deep: true, handler() { this.applyDebounced(); } },
  },

  created() {
    this.applyDebounced = debounce(() => this.apply(), 450);
  },

  methods: {
    fmt: formatBytes,
    projectIndex(p) {
      return this.top_projects.findIndex(x => x.id === p.id);
    },
    // which attachments a diary has decides the icon and the tooltip next to its reference
    mediaKind(d) {
      if (d.photos && d.videos) return { icon: 'media', tone: 'is-both', tip: this.$t('Photos and videos') + ' · ' + d.photos + ' ' + this.$t('photo(s)') + ', ' + d.videos + ' ' + this.$t('video(s)') };
      if (d.videos) return { icon: 'video', tone: 'is-video', tip: this.$t('Video only') + ' · ' + d.videos + ' ' + this.$t('video(s)') };
      if (d.photos) return { icon: 'camera', tone: 'is-photo', tip: this.$t('Photos only') + ' · ' + d.photos + ' ' + this.$t('photo(s)') };
      return { icon: 'camera', tone: 'is-none', tip: this.$t('No attachments') };
    },
    initials(name) {
      return String(name || '?').replace(/^demo\s+/i, '').split(/[\s-]+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
    },
    color(name) {
      let h = 0;
      for (const ch of String(name)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
      return PALETTE[h % PALETTE.length];
    },
    viewersTip(list) {
      return list.map(v => v.name + ' (' + v.views + '×)').join(', ');
    },
    toggleFlag(k) {
      this.form[k] = this.form[k] === '1' ? '' : '1';
    },
    apply() {
      const f = this.form;
      const q = pickBy({
        search: f.search, warehouse_id: f.warehouse_id, recorded_by: f.recorded_by, date_from: f.date_from, date_to: f.date_to,
        created_from: f.created_from, created_to: f.created_to, has_photos: f.has_photos, has_videos: f.has_videos,
        sort: f.sort, direction: f.sort ? f.direction : null, per_page: f.per_page !== 25 ? f.per_page : null, trashed: f.trashed,
      }, v => v !== null && v !== '' && v !== undefined);
      this.busy = true;
      this.$inertia.get(this.route('site-diary.index'), q, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) });
    },
    reset() {
      Object.assign(this.form, { search: '', warehouse_id: null, recorded_by: null, date_from: null, date_to: null, created_from: null, created_to: null, has_photos: '', has_videos: '', trashed: '' });
    },
    sortBy(col) {
      if (this.form.sort !== col) { this.form.sort = col; this.form.direction = 'asc'; }
      else if (this.form.direction === 'asc') this.form.direction = 'desc';
      else { this.form.sort = null; this.form.direction = 'desc'; }
    },
    ariaSort(col) {
      return this.form.sort === col ? (this.form.direction === 'asc' ? 'ascending' : 'descending') : 'none';
    },
    open(d) {
      if (!d.deleted) this.$inertia.visit(this.route('site-diary.show', d.id));
    },
    async restore(d) {
      if (await this.$confirm(this.$t('Restore :ref?', { ref: d.reference }))) {
        this.$inertia.put(this.route('site-diary.restore', d.id), {}, { preserveScroll: true });
      }
    },
  },
};
</script>

<style scoped>
/* app bar */
.sdi-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; margin-bottom: 1rem; padding: 12px 14px; border-radius: 1.6rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.45); }
.sdi-bar-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
.sdi-bar-ico { flex: none; width: 48px; height: 48px; display: grid; place-items: center; border-radius: 1.1rem; color: #fff; background: linear-gradient(145deg, #fb923c, #ea580c); box-shadow: 0 12px 20px -10px rgba(234, 88, 12, 0.8); }
.sdi-bar h1 { margin: 0; font-size: 1.15rem; font-weight: 900; color: var(--lg-ink); }
.sdi-bar p { margin: 1px 0 0; font-size: 0.78rem; color: var(--lg-faint); }
.sdi-search { position: relative; flex: 1 1 280px; min-width: 0; }
.sdi-search-ico { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--lg-faint); }
.sdi-search-input { width: 100%; height: 2.9rem; padding: 0 1rem 0 2.8rem; border-radius: 1.1rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.9rem; box-shadow: inset 0 2px 4px rgba(15, 23, 42, 0.05); }
.sdi-search-input:focus { outline: 2px solid #ea580c; outline-offset: 1px; }
.sdi-bar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.sdi-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 2.8rem; padding: 0 1.1rem; border: 1px solid var(--lg-line); border-radius: 1rem; font-size: 0.85rem; font-weight: 800; text-decoration: none; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease; touch-action: manipulation; }
.sdi-btn:active { transform: scale(0.97); }
.sdi-btn.is-soft { background: var(--lg-card); color: var(--lg-ink); }
.sdi-btn.is-dark { background: #0b1220; color: #fff; border-color: #0b1220; box-shadow: 0 14px 24px -12px rgba(11, 18, 32, 0.8); }
html.dark .sdi-btn.is-dark { background: #e2e8f0; color: #0b1220; border-color: #e2e8f0; }

/* sections */
.sdi-section { margin-top: 1.1rem; }
.sdi-section-head { display: flex; align-items: center; justify-content: space-between; margin: 0 2px 0.7rem; }
.sdi-section-head h2 { margin: 0; font-size: 1.1rem; font-weight: 900; color: var(--lg-ink); }
.sdi-link { color: #2563eb; font-weight: 800; font-size: 0.82rem; }
.sdi-recent { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; }
.sdi-folders { display: grid; grid-template-columns: repeat(auto-fill, minmax(171px, 1fr)); gap: 12px; }
.sdi-linkbtn { background: none; border: 0; cursor: pointer; padding: 0; }
.sdi-card { margin-top: 1.1rem; padding: 1.1rem; border-radius: 1.5rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.45); }
.sdi-table { padding: 0; overflow: hidden; }

/* project summary */
.sdi-summary { display: grid; gap: 0.9rem; }
.sdi-sum-main { display: flex; align-items: center; gap: 12px; }
.sdi-sum-ico { width: 44px; height: 44px; display: grid; place-items: center; border-radius: 1rem; color: #fff; background: linear-gradient(145deg, #14b8a6, #0f766e); }
.sdi-sum-main h2 { margin: 0; font-size: 1rem; font-weight: 900; color: var(--lg-ink); }
.sdi-sum-main p { margin: 0; font-size: 0.8rem; color: var(--lg-faint); }
.sdi-sum-photos { display: flex; flex-wrap: wrap; gap: 8px; }
.sdi-sum-photos img { width: 76px; height: 58px; object-fit: cover; border-radius: 0.8rem; border: 1px solid var(--lg-line); }
.sdi-sum-remarks { margin: 0; padding: 0; list-style: none; display: grid; gap: 4px; font-size: 0.82rem; color: var(--lg-mute); }
.sdi-sum-remarks b { color: var(--lg-ink); }

/* filters */
.sdy-fixed-k { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.sdy-filter-grid { align-items: end; }
.sdy-toggles { display: flex; flex-direction: column; gap: 4px; }
.sdy-seg { display: inline-flex; border: 1px solid var(--lg-line); border-radius: 0.9rem; overflow: hidden; height: 2.6rem; }
.sdy-seg button { display: inline-flex; align-items: center; gap: 6px; padding: 0 0.95rem; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); background: var(--lg-soft); border: 0; }
.sdy-seg button + button { border-left: 1px solid var(--lg-line); }
.sdy-seg button.is-on { background: rgba(234, 88, 12, 0.14); color: #c2410c; }
.sdy-filter-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 0.8rem; }
.sdy-check { display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 700; color: var(--lg-ink); margin-left: auto; }
.sdy-adv { margin-top: 0.8rem; }
.sdy-slide-enter-active, .sdy-slide-leave-active { transition: opacity 0.2s ease; }
.sdy-slide-enter-from, .sdy-slide-leave-to { opacity: 0; }
.sdy-meta { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin: 1.1rem 2px 0; font-size: 0.82rem; font-weight: 700; color: var(--lg-mute); }
.sdy-per { display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; }
.sdy-per select { height: 2rem; border-radius: 0.7rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); padding: 0 1.6rem 0 0.6rem; }
.sdy-busy { opacity: 0.55; }

/* table */
.sdi-table :deep(thead th) { background: var(--lg-soft); font-size: 0.7rem; letter-spacing: 0.05em; }
.sdy-sortable { cursor: pointer; user-select: none; }
.sdy-th { display: inline-flex; align-items: center; gap: 5px; }
.sdy-arrows { display: inline-flex; flex-direction: column; font-size: 0.5rem; line-height: 0.7; color: var(--lg-faint); }
.sdy-arrows .on { color: #ea580c; }
.sdi-ref { display: inline-flex; align-items: center; gap: 9px; white-space: nowrap; }
.sdi-ref-ico.is-photo { background: linear-gradient(145deg, #fb923c, #ea580c); }
.sdi-ref-ico.is-both { background: linear-gradient(145deg, #8b5cf6, #5b3df5); }
.sdi-ref-ico.is-video { background: linear-gradient(145deg, #14b8a6, #0f766e); }
.sdi-ref-ico.is-none { background: linear-gradient(145deg, #94a3b8, #64748b); }
.sdi-ref-ico { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 0.7rem; color: #fff; background: linear-gradient(145deg, #fb923c, #ea580c); flex: none; }
.sdi-person { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; font-weight: 700; color: var(--lg-ink); }
.sdi-person i { width: 28px; height: 28px; display: inline-grid; place-items: center; border-radius: 9999px; color: #fff; font-style: normal; font-size: 0.64rem; font-weight: 900; flex: none; }
.sdi-size b { color: #7f1d1d; font-variant-numeric: tabular-nums; }
html.dark .sdi-size b { color: #fca5a5; }
.sdy-project-cell { min-width: 11rem; }
.sdy-viewers-cell { min-width: 11rem; }
.sdy-viewers { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.sdi-vchip { display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; white-space: nowrap; }
.sdi-vchip.is-c0 { background: #dbeafe; color: #1d4ed8; } .sdi-vchip.is-c1 { background: #d1fae5; color: #047857; } .sdi-vchip.is-c2 { background: #ede9fe; color: #6d28d9; }
.sdi-vchip.is-c3 { background: #fef3c7; color: #b45309; } .sdi-vchip.is-c4 { background: #cffafe; color: #0e7490; } .sdi-vchip.is-c5 { background: #ffe4e6; color: #be123c; }
.sdi-vchip.is-c6 { background: #e0e7ff; color: #4338ca; } .sdi-vchip.is-more { background: #1e293b; color: #fff; }
html.dark .sdi-vchip.is-c0 { background: rgba(59, 130, 246, 0.22); color: #93c5fd; } html.dark .sdi-vchip.is-c1 { background: rgba(16, 185, 129, 0.22); color: #6ee7b7; }
html.dark .sdi-vchip.is-c2 { background: rgba(139, 92, 246, 0.25); color: #c4b5fd; } html.dark .sdi-vchip.is-c3 { background: rgba(245, 158, 11, 0.22); color: #fcd34d; }
html.dark .sdi-vchip.is-c4 { background: rgba(6, 182, 212, 0.22); color: #67e8f9; } html.dark .sdi-vchip.is-c5 { background: rgba(244, 63, 94, 0.22); color: #fda4af; }
html.dark .sdi-vchip.is-c6 { background: rgba(99, 102, 241, 0.25); color: #a5b4fc; } html.dark .sdi-vchip.is-more { background: #e2e8f0; color: #0f172a; }
.sdi-nobody { font-size: 0.74rem; color: var(--lg-faint); font-style: italic; }
.sdy-remarks-cell { min-width: 15rem; max-width: 22rem; color: var(--lg-mute); font-size: 0.82rem; white-space: normal; vertical-align: top; }
.sdy-remarks-txt { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; line-height: 1.4; }
.sdy-deleted { opacity: 0.6; }
@media (max-width: 767.98px) {
  .sdy-remarks-cell { max-width: none; min-width: 0; }
  .sdi-bar-actions { width: 100%; }
  .sdi-bar-actions > * { flex: 1; }
  .sdi-btn span { white-space: nowrap; }
}
</style>
