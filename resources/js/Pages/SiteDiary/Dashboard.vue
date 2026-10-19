<template>
  <admin-layout :title="$t('Site Diary Dashboard')">
    <div class="lg-page sdy sdd px-4 md:px-0">
      <!-- welcome bar -->
      <header class="sdd-bar">
        <div class="sdd-hello">
          <span class="sdd-hello-ico"><icons name="camera" class="w-6 h-6" /></span>
          <div class="min-w-0">
            <h1>{{ $t('Welcome back, :name!', { name: firstName }) }}</h1>
            <p>{{ $t('Your site diaries are safe, organised, and always accessible.') }}</p>
          </div>
        </div>
        <div class="sdd-actions">
          <Link :href="route('site-diary.index')" class="sdd-btn is-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('All Site Diaries') }}</span></Link>
          <offline-lock v-if="can.create">
            <Link :href="route('site-diary.create')" class="sdd-btn is-dark"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Site Diary') }}</span></Link>
          </offline-lock>
        </div>
      </header>

      <!-- filters -->
      <section class="sdd-card relative z-20">
        <div class="lg-fields">
          <auto-complete id="d_project" :label="$t('Project')" v-model="filters.warehouse_id" :suggestions="projects" clearable :placeholder="$t('All projects')" />
          <date-range-picker :label="$t('Diary date')" v-model="range" />
        </div>
      </section>

      <p v-if="error" class="lg-note lg-note-red mt-4"><icons name="exclamation" class="w-4 h-4" /><span>{{ error }}</span> <button type="button" class="sdy-retry" @click="load">{{ $t('Retry') }}</button></p>

      <!-- big stat cards -->
      <section class="sdd-cards">
        <template v-if="loading && !stats">
          <div v-for="n in 4" :key="n" class="sdd-skel"></div>
        </template>
        <template v-else-if="stats">
          <stat-card :index="0" tone="orange" icon="camera" foot-icon="collection" :label="$t('Total Diaries')" :value="stats.cards.diaries" :chip="'+' + stats.cards.diaries_month + ' ' + $t('this month')" :to="route('site-diary.index')" />
          <stat-card :index="1" tone="purple" icon="image" :label="$t('Total Photos')" :value="stats.cards.photos" :chip="'+' + stats.cards.photos_month + ' ' + $t('this month')" />
          <stat-card v-if="limits_videos || stats.cards.videos" :index="2" tone="teal" icon="video" :label="$t('Total Videos')" :value="stats.cards.videos" :chip="'+' + stats.cards.videos_month + ' ' + $t('this month')" />
          <stat-card :index="3" tone="blue" icon="building" :label="$t('Projects With Diaries')" :value="stats.cards.projects" :chip="fmt(stats.storage.total)" />
        </template>
      </section>

      <!-- data storage -->
      <storage-bar
        v-if="stats"
        class="sdd-gap"
        :title="$t('Data Storage')"
        :used="fmt(stats.storage.total)"
        :sub="$t('used by :n files', { n: (stats.storage.photos.files + stats.storage.videos.files).toLocaleString() })"
        :segments="segments"
      />

      <template v-if="stats">
        <!-- recently added -->
        <section class="sdd-section">
          <div class="sdd-section-head"><h2>{{ $t('Recently Added') }}</h2><Link :href="route('site-diary.index')" class="sdd-link">{{ $t('View All') }}</Link></div>
          <p v-if="!stats.recent.length" class="lg-empty">{{ $t('No diaries yet.') }}</p>
          <div v-else class="sdd-recent">
            <recent-card v-for="(r, i) in stats.recent" :key="r.id" :index="i" :to="route('site-diary.show', r.id)" :cover="r.cover" :icon="r.videos ? 'video' : 'camera'" :tone="r.videos ? 'purple' : 'orange'" :title="r.project" :size="fmt(r.bytes)" :sub="$date(r.date) + ' · ' + r.by" />
          </div>
        </section>

        <!-- most active projects -->
        <section class="sdd-section">
          <div class="sdd-section-head"><h2>{{ $t('Most Active Projects') }}</h2><button v-if="!projQ && stats.by_project.length > 6" type="button" class="sdd-link sdd-linkbtn" @click="allProjects = !allProjects">{{ allProjects ? $t('Show fewer') : $t('Show all') + ' (' + stats.by_project.length + ')' }}</button></div>
          <p v-if="!stats.by_project.length" class="lg-empty">{{ $t('No diaries yet.') }}</p>
          <template v-else>
            <folder-search v-if="stats.by_project.length > 6" v-model="projQ" :shown="shownProjects.length" :total="stats.by_project.length" :placeholder="$t('Search projects…')" />
            <div v-if="shownProjects.length" class="sdd-folders">
              <folder-tile v-for="(p, i) in shownProjects" :key="p.id" :index="i" :scale="0.9" :tone="tones[stats.by_project.indexOf(p) % tones.length]" :name="p.label" :meta="p.count + ' ' + $t('diaries') + ' · ' + fmt(p.bytes)" :to="route('site-diary.index', { warehouse_id: p.id })" />
            </div>
            <p v-else class="lg-empty">{{ $t('No project matches “:q”.', { q: projQ }) }}</p>
          </template>
        </section>

        <!-- charts -->
        <div class="sdd-grid">
          <section class="sdd-card sdd-wide">
            <div class="sdd-card-head"><span class="sdd-ico is-sky"><icons name="chart-bar" class="w-5 h-5" /></span><div><h2>{{ $t('Media uploads by month') }}</h2><small>{{ $t('Photos and videos, last 12 months') }}</small></div></div>
            <column-chart :data="stats.monthly" :unit="$t('files')" />
          </section>

          <section v-if="can.viewAll" class="sdd-card">
            <div class="sdd-card-head"><span class="sdd-ico is-violet"><icons name="users" class="w-5 h-5" /></span><div><h2>{{ $t('Most active recorders') }}</h2><small>{{ $t('Diaries recorded') }}</small></div></div>
            <p v-if="!stats.by_user.length" class="lg-empty">{{ $t('No diaries yet.') }}</p>
            <ul v-else class="sdd-bars">
              <li v-for="(u, i) in stats.by_user" :key="u.id">
                <div class="sdd-bar-top"><span class="sdd-av" :style="{ background: palette[i % palette.length] }">{{ initials(u.label) }}</span><span class="sdd-bar-label">{{ u.label }}</span><b>{{ u.count }}</b></div>
                <div class="sdd-bar"><span :style="{ width: pct(u.count, maxUser) + '%', background: palette[i % palette.length] }"></span></div>
              </li>
            </ul>
          </section>
        </div>
      </template>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DateRangePicker from '@/Shared/DateRangePicker.vue';
import ColumnChart from '@/Shared/Archive/ColumnChart.vue';
import StatCard from '@/Shared/Page/StatCard.vue';
import StorageBar from '@/Shared/Page/StorageBar.vue';
import RecentCard from '@/Shared/Page/RecentCard.vue';
import FolderTile from '@/Shared/Page/FolderTile.vue';
import FolderSearch from '@/Shared/Page/FolderSearch.vue';
import { formatBytes } from '@/Core/archiveFormat';

export default {
  components: { AdminLayout, Link, AutoComplete, DateRangePicker, ColumnChart, StatCard, StorageBar, RecentCard, FolderTile, FolderSearch },

  props: { projects: Array, me: String, can: Object },

  data() {
    return {
      filters: { warehouse_id: null, date_from: null, date_to: null },
      stats: null,
      loading: true,
      projQ: '',
      allProjects: false,
      error: null,
      tones: ['amber', 'sky', 'emerald', 'violet', 'rose', 'indigo'],
      palette: ['#2563eb', '#059669', '#7c3aed', '#d97706', '#0891b2', '#e11d48', '#4f46e5', '#0f766e'],
    };
  },

  computed: {
    firstName() {
      return (this.me || '').split(' ')[0] || this.me;
    },
    range: {
      get() { return { start: this.filters.date_from, end: this.filters.date_to }; },
      set(v) { this.filters.date_from = v.start; this.filters.date_to = v.end; },
    },
    limits_videos() {
      return this.can.addVideos || this.can.viewVideos;
    },
    shownProjects() {
      const q = this.projQ.trim().toLowerCase();
      if (q) return this.stats.by_project.filter(p => p.label.toLowerCase().includes(q));
      return this.allProjects ? this.stats.by_project : this.stats.by_project.slice(0, 6);
    },
    maxUser() { return Math.max(1, ...this.stats.by_user.map(p => p.count)); },
    segments() {
      const s = this.stats.storage;
      const out = [{ key: 'p', label: this.$t('Photos'), value: s.photos.bytes, color: '#fb923c', icon: 'image', text: s.photos.files.toLocaleString() + ' ' + this.$t('files') + ' · ' + this.fmt(s.photos.bytes) }];
      if (this.limits_videos || s.videos.files) out.push({ key: 'v', label: this.$t('Videos'), value: s.videos.bytes, color: '#14b8a6', icon: 'video', text: s.videos.files.toLocaleString() + ' ' + this.$t('files') + ' · ' + this.fmt(s.videos.bytes) });
      return out;
    },
  },

  watch: {
    filters: { deep: true, handler() { this.loadDebounced(); } },
  },

  created() {
    this.loadDebounced = debounce(() => this.load(), 350);
  },

  mounted() {
    this.load();
  },

  methods: {
    fmt: formatBytes,
    pct(v, max) { return Math.max(4, Math.round((v / max) * 100)); },
    initials(name) { return String(name || '?').replace(/^demo\s+/i, '').split(/[\s-]+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase(); },
    async load() {
      this.loading = true;
      this.error = null;
      try {
        const params = Object.fromEntries(Object.entries(this.filters).filter(([, v]) => v));
        const { data } = await window.axios.get(this.route('site-diary.analytics.stats'), { params });
        this.stats = data;
      } catch (e) {
        this.error = this.$t('The dashboard could not be loaded.');
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>

<style scoped>
.sdd-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 16px; margin-bottom: 1rem; padding: 14px 16px; border-radius: 1.6rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.45); }
.sdd-hello { display: flex; align-items: center; gap: 12px; min-width: 0; }
.sdd-hello-ico { flex: none; width: 50px; height: 50px; display: grid; place-items: center; border-radius: 1.15rem; color: #fff; background: linear-gradient(145deg, #fb923c, #ea580c); box-shadow: 0 12px 20px -10px rgba(234, 88, 12, 0.8); }
.sdd-hello h1 { margin: 0; font-size: 1.2rem; font-weight: 900; color: var(--lg-ink); }
.sdd-hello p { margin: 2px 0 0; font-size: 0.8rem; color: var(--lg-faint); }
.sdd-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.sdd-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 2.8rem; padding: 0 1.1rem; border: 1px solid var(--lg-line); border-radius: 1rem; font-size: 0.85rem; font-weight: 800; text-decoration: none; cursor: pointer; transition: transform 0.12s ease; touch-action: manipulation; }
.sdd-btn:active { transform: scale(0.97); }
.sdd-btn.is-soft { background: var(--lg-card); color: var(--lg-ink); }
.sdd-btn.is-dark { background: #0b1220; color: #fff; border-color: #0b1220; box-shadow: 0 14px 24px -12px rgba(11, 18, 32, 0.8); }
html.dark .sdd-btn.is-dark { background: #e2e8f0; color: #0b1220; border-color: #e2e8f0; }

.sdd-card { padding: 1.1rem; border-radius: 1.5rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.45); }
.sdd-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; margin-top: 1rem; }
.sdd-skel { min-height: 156px; border-radius: 1.5rem; background: linear-gradient(90deg, var(--lg-line), var(--lg-soft), var(--lg-line)); background-size: 200% 100%; animation: sdd-shimmer 1.2s linear infinite; }
.sdd-gap { margin-top: 1rem; }
.sdd-section { margin-top: 1.3rem; }
.sdd-section-head { display: flex; align-items: center; justify-content: space-between; margin: 0 2px 0.7rem; }
.sdd-section-head h2 { margin: 0; font-size: 1.1rem; font-weight: 900; color: var(--lg-ink); }
.sdd-link { color: #2563eb; font-weight: 800; font-size: 0.82rem; }
.sdd-recent { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.sdd-folders { display: grid; grid-template-columns: repeat(auto-fill, minmax(171px, 1fr)); gap: 12px; }
.sdd-linkbtn { background: none; border: 0; cursor: pointer; padding: 0; }
.sdd-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-top: 1.3rem; }
.sdd-grid > * { min-width: 0; }
.sdd-wide { overflow: hidden; }
.sdd-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 0.9rem; }
.sdd-card-head h2 { margin: 0; font-size: 1rem; font-weight: 900; color: var(--lg-ink); }
.sdd-card-head small { color: var(--lg-faint); font-size: 0.74rem; }
.sdd-ico { flex: none; width: 42px; height: 42px; display: grid; place-items: center; border-radius: 1rem; color: #fff; }
.sdd-ico.is-sky { background: linear-gradient(145deg, #38bdf8, #0284c7); } .sdd-ico.is-violet { background: linear-gradient(145deg, #8b5cf6, #5b3df5); }
.sdd-bars { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
.sdd-bar-top { display: flex; align-items: center; gap: 10px; font-size: 0.85rem; color: var(--lg-ink); }
.sdd-bar-top b { margin-left: auto; font-variant-numeric: tabular-nums; }
.sdd-bar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; }
.sdd-av { width: 28px; height: 28px; display: inline-grid; place-items: center; border-radius: 9999px; color: #fff; font-size: 0.62rem; font-weight: 900; flex: none; }
.sdd-bar { height: 8px; margin-top: 6px; border-radius: 9999px; background: var(--lg-line); overflow: hidden; }
.sdd-bar span { display: block; height: 100%; border-radius: inherit; animation: sdd-grow 0.7s ease backwards; }
.sdy-retry { margin-left: 8px; font-weight: 800; text-decoration: underline; }
@keyframes sdd-grow { from { width: 0 !important; } }
@keyframes sdd-shimmer { to { background-position: -200% 0; } }
@media (max-width: 860px) { .sdd-grid { grid-template-columns: 1fr; } }
@media (max-width: 767.98px) { .sdd-actions { width: 100%; } .sdd-actions > * { flex: 1; } .sdd-btn span { white-space: nowrap; } }
@media (max-width: 575.98px) { .sdd-cards { grid-template-columns: 1fr 1fr; gap: 10px; } }
@media (prefers-reduced-motion: reduce) { .sdd-bar span, .sdd-skel { animation: none; } }
</style>
