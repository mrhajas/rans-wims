<template>
  <admin-layout :title="$t('Site Diary Reports')">
    <div class="lg-page sdy px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Site Diary Reports')" :subtitle="$t('Pick a report, set the filters, then print, download PDF or export to Excel.')">
        <Link :href="route('site-diary.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All Site Diaries') }}</Link>
      </page-header>

      <!-- report type -->
      <nav class="sdy-types" role="tablist">
        <button v-for="t in types" :key="t.value" type="button" role="tab" class="sdy-type" :class="{ 'is-on': form.type === t.value }" :aria-selected="form.type === t.value" @click="form.type = t.value">{{ $t(t.label) }}</button>
      </nav>

      <section class="lg-card relative z-20 sdy-filter">
        <div class="lg-fields">
          <auto-complete id="r_project" :label="$t('Project')" v-model="form.warehouse_id" :suggestions="projects" clearable :placeholder="$t('All projects')" />
          <auto-complete v-if="can.viewAll" id="r_user" :label="form.type === 'media' ? $t('Uploaded by') : $t('Recorded by')" v-model="form.recorded_by" :suggestions="recorders" clearable :placeholder="$t('Anyone')" />
          <date-range-picker :label="$t('Dates')" v-model="range" />
          <div v-if="form.type === 'media'" class="sdy-media-type">
            <span class="sdy-k">{{ $t('Media type') }}</span>
            <div class="sdy-seg">
              <button type="button" :class="{ 'is-on': !form.media_type }" @click="form.media_type = ''">{{ $t('All') }}</button>
              <button type="button" :class="{ 'is-on': form.media_type === 'photo' }" @click="form.media_type = 'photo'">{{ $t('Photos') }}</button>
              <button type="button" :class="{ 'is-on': form.media_type === 'video' }" @click="form.media_type = 'video'">{{ $t('Videos') }}</button>
            </div>
          </div>
        </div>
        <div class="sdy-out">
          <a v-if="can.print" :href="url('site-diary.reports.print')" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a v-if="can.print" :href="url('site-diary.reports.pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
          <a v-if="can.export" :href="url('site-diary.reports.export')" class="lg-btn lg-btn-green"><icons name="table" class="w-4 h-4" />{{ $t('Excel') }}</a>
        </div>
      </section>

      <stat-tiles :items="tiles" />

      <section class="lg-card sdy-tablecard" :class="{ 'sdy-busy': busy }">
        <div class="lg-table-wrap -mx-4 md:mx-0">
          <table class="lg-table lg-responsive">
            <thead><tr><th v-for="c in columns" :key="c">{{ c }}</th></tr></thead>
            <tbody>
              <tr v-if="!rows.length" class="lg-empty-row"><td class="lg-empty" :colspan="columns.length">{{ $t('Nothing matches these filters.') }}</td></tr>
              <tr v-for="(r, i) in rows" :key="i">
                <td v-for="(c, j) in r" :key="j" :data-label="columns[j]">
                  <template v-if="c !== null && typeof c === 'object'">
                    <span v-if="c.tone" class="lg-chip" :class="chip(c.tone)" style="text-transform: none">{{ c.text }}</span>
                    <a v-else-if="c.url" :href="c.url" target="_blank" rel="noopener" class="sdr-link lg-tip" :data-tip="$t('Open in a new tab')"><icons name="link" class="w-3.5 h-3.5" />{{ c.text }}</a>
                    <span v-else :class="{ 'lg-main': c.strong }">{{ c.text }}</span>
                    <span v-if="c.sub" class="lg-subtext sdr-sub">{{ c.sub }}</span>
                  </template>
                  <template v-else>{{ c }}</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="total_rows > rows.length" class="lg-note lg-note-blue m-3"><icons name="info" class="w-4 h-4" /><span>{{ $t('Showing the first :n of :t rows. Print, PDF and Excel contain all of them.', { n: rows.length, t: total_rows.toLocaleString() }) }}</span></p>
      </section>

      <!-- photo gallery report -->
      <section v-if="can.viewPhotos && can.print" class="lg-card sdy-gallery">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="image" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Photo Gallery Report') }}</h2><p class="lg-card-sub">{{ $t('A printable sheet per diary: project, date, recorder, remarks and its photos. Uses the project and dates chosen above.') }}</p></div>
        </header>
        <div class="sdy-out">
          <a v-if="form.warehouse_id" :href="galleryUrl('site-diary.reports.gallery.print')" target="_blank" class="lg-btn lg-btn-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print gallery') }}</a>
          <a v-if="form.warehouse_id" :href="galleryUrl('site-diary.reports.gallery.pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Gallery PDF') }}</a>
          <span v-if="!form.warehouse_id" class="lg-subtext">{{ $t('Choose a project above to enable the gallery report.') }}</span>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import pickBy from 'lodash/pickBy';
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DateRangePicker from '@/Shared/DateRangePicker.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

const ICONS = ['collection', 'building', 'image', 'video', 'users'];

export default {
  components: { AdminLayout, PageHeader, Link, AutoComplete, DateRangePicker, StatTiles },

  props: { filters: Object, types: Array, projects: Array, recorders: Array, cards: Array, columns: Array, rows: Array, total_rows: Number, can: Object },

  data() {
    const f = this.filters || {};
    return {
      busy: false,
      form: {
        type: f.type || 'daily',
        warehouse_id: f.warehouse_id ? Number(f.warehouse_id) : null,
        recorded_by: f.recorded_by ? Number(f.recorded_by) : null,
        date_from: f.date_from || null,
        date_to: f.date_to || null,
        media_type: f.media_type || '',
      },
    };
  },

  computed: {
    range: {
      get() { return { start: this.form.date_from, end: this.form.date_to }; },
      set(v) { this.form.date_from = v.start; this.form.date_to = v.end; },
    },
    tiles() {
      return this.cards.map((c, i) => ({ icon: ICONS[i % ICONS.length], tone: c.tone, label: c.label, value: c.value }));
    },
  },

  watch: {
    form: { deep: true, handler() { this.applyDebounced(); } },
  },

  created() {
    this.applyDebounced = debounce(() => this.apply(), 400);
  },

  methods: {
    query() {
      return pickBy(this.form, v => v !== null && v !== '' && v !== undefined);
    },
    apply() {
      this.busy = true;
      this.$inertia.get(this.route('site-diary.reports'), this.query(), { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) });
    },
    chip(tone) {
      return { green: 'lg-chip-green', amber: 'lg-chip-amber', red: 'lg-chip-red', blue: 'lg-chip-blue', indigo: 'lg-chip-indigo', teal: 'lg-chip-cyan', slate: 'lg-chip-slate' }[tone] || 'lg-chip-slate';
    },
    url(name) {
      return this.route(name, this.query());
    },
    galleryUrl(name) {
      return this.route(name, pickBy({ warehouse_id: this.form.warehouse_id, recorded_by: this.form.recorded_by, date_from: this.form.date_from, date_to: this.form.date_to }, v => v));
    },
  },
};
</script>

<style scoped>
.sdy-types { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 6px; margin-bottom: 0.8rem; }
.sdy-type { flex: none; padding: 0.5rem 1rem; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-mute); font-size: 0.8rem; font-weight: 800; white-space: nowrap; transition: all 0.15s ease; }
.sdy-type:hover { border-color: #2f6fb3; }
.sdy-type.is-on { background: #1c3f66; border-color: #1c3f66; color: #fff; }
.sdy-filter { margin-bottom: 1rem; }
.sdy-k { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.sdy-media-type { display: flex; flex-direction: column; gap: 4px; }
.sdy-seg { display: inline-flex; border: 1px solid var(--lg-line); border-radius: 0.9rem; overflow: hidden; height: 2.6rem; }
.sdy-seg button { padding: 0 0.95rem; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); background: var(--lg-soft); border: 0; }
.sdy-seg button + button { border-left: 1px solid var(--lg-line); }
.sdy-seg button.is-on { background: rgba(47, 111, 179, 0.16); color: #2f6fb3; }
.sdy-out { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 0.9rem; }
.sdy-tablecard { padding: 0; overflow: hidden; margin-bottom: 1rem; }
.sdy-busy { opacity: 0.55; }
.sdy-gallery { margin-bottom: 1rem; }
.sdr-link { display: inline-flex; align-items: center; gap: 6px; color: #1d4ed8; font-weight: 800; text-decoration: underline; text-underline-offset: 2px; word-break: break-all; }
.sdr-link:hover { color: #1e40af; }
html.dark .sdr-link { color: #93c5fd; }
.sdr-sub { display: block; }
</style>
