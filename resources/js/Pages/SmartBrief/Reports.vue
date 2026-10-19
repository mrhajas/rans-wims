<template>
  <admin-layout :title="$t('Smart Brief Reports')">
    <div class="lg-page sb sc-ui px-4 md:px-0">
      <sb-hero icon="doc-text" :title="$t('Smart Brief Reports')" :subtitle="$t('Printable and downloadable reports built only from what WIMS has recorded. Every export is logged.')">
        <Link :href="route('smart-brief.home')" class="sb-gbtn"><icons name="sparkles" class="w-4 h-4" />{{ $t('Smart Brief') }}</Link>
      </sb-hero>

      <!-- 1. which report -->
      <section class="sbr-types" role="radiogroup" :aria-label="$t('Report type')">
        <button
          v-for="(t, i) in types"
          :key="t.key"
          type="button"
          role="radio"
          :aria-checked="form.type === t.key"
          class="sbr-type lg-tip"
          :class="[theme(t, i).g, { 'is-on': form.type === t.key, 'is-off': !t.allowed }]"
          :style="{ '--i': i }"
          :data-tip="t.allowed ? '' : $t('You do not have access to this report.')"
          @click="pickType(t)"
        >
          <span class="sbr-type__top"><span class="sbr-type__ico"><icons :name="theme(t, i).icon" class="w-5 h-5" /></span><span class="sbr-type__kind">{{ kindLabel(t.kind) }}</span></span>
          <b>{{ $t(t.label) }}</b>
          <small>{{ $t(t.description) }}</small>
          <span class="sbr-check"><icons name="tick" class="w-3.5 h-3.5" /></span>
        </button>
      </section>

      <!-- 2. filters -->
      <section class="sb-card grid gap-4 mb-4" style="--i: 2">
        <div class="sbr-row">
          <span class="sbr-lbl">{{ $t('Period') }}</span>
          <div class="sb-periods" role="group" :aria-label="$t('Period')">
            <button v-for="p in periods" :key="p.key" type="button" class="sb-chip" :class="{ 'is-on': form.period === p.key }" @click="setPeriod(p.key)">{{ $t(p.label) }}</button>
          </div>
          <div v-if="form.period === 'custom'" class="sb-range">
            <date-picker v-model="form.from" :max="form.to || undefined" /><span>-</span><date-picker v-model="form.to" :min="form.from || undefined" />
          </div>
        </div>

        <div v-if="current && (current.person || current.person_optional)" class="sbr-row">
          <span class="sbr-lbl">{{ $t('Person') }}</span>
          <div class="sbr-person-area">
            <auto-complete v-if="can.management" v-model="personId" :suggestions="personOptions" :placeholder="$t('Search a person by name or role..')" clearable />
            <span v-else-if="me.own" class="sbr-me"><span class="sb-av">{{ initials(me.name) }}</span><b>{{ me.name }}</b></span>
            <span v-else class="sbr-hint">{{ $t('You cannot open anyone\'s brief.') }}</span>
            <p v-if="current.person_optional && can.management" class="sbr-hint mt-1">{{ $t('Optional: leave empty to cover everyone you may see.') }}</p>
          </div>
        </div>

        <div v-if="can.management && current && current.kind !== 'admin' && !current.person" class="sbr-row">
          <span class="sbr-lbl">{{ $t('Filter') }}</span>
          <div class="sbr-grid">
            <div class="sb-field"><label><icons name="users" class="w-3 h-3" />{{ $t('Role') }}</label><auto-complete v-model="form.role" :suggestions="roleOptions" :placeholder="$t('All roles')" clearable /></div>
            <div class="sb-field"><label><icons name="building" class="w-3 h-3" />{{ $t('Project') }}</label><auto-complete v-model="form.project" :suggestions="projectOptions" :placeholder="$t('All projects')" clearable /></div>
          </div>
        </div>

        <div class="sbr-actions">
          <button type="button" class="sb-btn is-primary" :disabled="!runnable || loading" @click="run"><icons name="refresh" class="w-4 h-4" />{{ loading ? $t('Preparing...') : $t('Run report') }}</button>
          <span class="sbr-sep"></span>
          <template v-for="x in exportButtons" :key="x.key">
            <a v-if="exportOk" :href="x.href" :target="x.key === 'print' ? '_blank' : null" rel="noopener" class="sb-btn" :class="{ 'is-disabled': !runnable }" :aria-disabled="!runnable"><icons :name="x.icon" class="w-4 h-4" />{{ x.label }}</a>
            <span v-else class="sb-btn is-disabled lg-tip" :data-tip="exportReason" aria-disabled="true"><icons :name="x.icon" class="w-4 h-4" />{{ x.label }}</span>
          </template>
        </div>
      </section>

      <p v-if="error" class="sbr-error" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ error }}</p>

      <!-- 3. the report -->
      <section v-if="report" class="wx-doc" :key="report.reference + report.type" style="margin-top: 0.9rem">
        <div class="wx-doc__title">
          <div class="wx-doc__name">{{ report.title }}</div>
          <div class="wx-doc__ref">{{ $t('Reference') }}<strong>{{ report.reference }}</strong></div>
        </div>
        <dl class="wx-doc__meta">
          <div v-if="report.subtitle"><dt>{{ $t('Report') }}</dt><dd>{{ report.subtitle }}</dd></div>
          <div><dt>{{ $t('Filters') }}</dt><dd><span v-for="(v, k, n) in report.filters" :key="k"><template v-if="n">&nbsp;|&nbsp;</template>{{ k }}: {{ v }}</span></dd></div>
        </dl>
        <div v-if="report.tiles.length" class="wx-doc__tiles">
          <div v-for="t in report.tiles" :key="t.label" class="wx-doc__tile"><b>{{ cell(t.value) }}</b><span>{{ t.label }}</span></div>
        </div>

        <!-- blocks before the main table (summary), the main table, then blocks after it: the order of the printed report -->
        <template v-for="(s, i) in report.sections.filter(x => x.placement === 'before')" :key="'b' + i">
          <h3 class="wx-doc__h">{{ s.title }}</h3>
          <template v-if="s.type === 'paragraphs'"><p v-for="(line, j) in s.items" :key="j" class="wx-doc__para">{{ line }}</p><p v-if="!s.items.length" class="wx-doc__empty">-</p></template>
          <div v-else-if="s.rows.length" class="wx-doc__scroll"><table class="wx-doc__table"><thead><tr><th v-for="c in s.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ c.label }}</th></tr></thead><tbody><tr v-for="(r, j) in s.rows" :key="j"><td v-for="c in s.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ cell(r[c.key]) }}</td></tr></tbody></table></div>
          <p v-else class="wx-doc__empty">{{ s.empty || '-' }}</p>
        </template>

        <h3 class="wx-doc__h">{{ report.table_title || report.title }} <small>{{ shownText }}</small></h3>
        <div v-if="report.rows.length" class="wx-doc__scroll">
          <table class="wx-doc__table">
            <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ c.label }}</th></tr></thead>
            <tbody><tr v-for="(r, i) in previewRows" :key="i"><td v-for="c in report.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ cell(r[c.key]) }}</td></tr></tbody>
          </table>
        </div>
        <p v-else class="wx-doc__empty">{{ $t('Nothing was recorded for this report in the chosen period.') }}</p>

        <template v-for="(s, i) in report.sections.filter(x => x.placement !== 'before')" :key="'a' + i">
          <h3 class="wx-doc__h">{{ s.title }}</h3>
          <template v-if="s.type === 'paragraphs'"><p v-for="(line, j) in s.items" :key="j" class="wx-doc__para">{{ line }}</p><p v-if="!s.items.length" class="wx-doc__empty">-</p></template>
          <div v-else-if="s.rows.length" class="wx-doc__scroll"><table class="wx-doc__table"><thead><tr><th v-for="c in s.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ c.label }}</th></tr></thead><tbody><tr v-for="(r, j) in s.rows" :key="j"><td v-for="c in s.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ cell(r[c.key]) }}</td></tr></tbody></table></div>
          <p v-else class="wx-doc__empty">{{ s.empty || '-' }}</p>
        </template>

        <div class="wx-doc__notes">
          <h4>{{ $t('About these figures') }}</h4>
          <ul><li v-for="n in report.notes" :key="n">{{ $t(n) }}</li></ul>
        </div>
      </section>
      <p v-else-if="!loading && !error" class="sbr-idle">{{ $t('Choose a report, set the period and press Run report.') }}</p>
      <div v-if="loading && !report" class="sb-state"><span class="sb-spin"></span>{{ $t('Preparing the report...') }}</div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';

const PREVIEW_ROWS = 200;
// each report type has its own icon and colour so the cards are easy to tell apart at a glance
const THEMES = {
  individual_daily: { icon: 'calendar', g: 'g-navy' }, individual_history: { icon: 'list', g: 'g-sky' }, team: { icon: 'group', g: 'g-teal' },
  role: { icon: 'users', g: 'g-violet' }, project: { icon: 'building', g: 'g-green' }, module: { icon: 'collection', g: 'g-amber' },
  sessions: { icon: 'key', g: 'g-pink' }, approvals: { icon: 'tick', g: 'g-green' }, changes: { icon: 'edit', g: 'g-rose' }, access_history: { icon: 'lock-closed', g: 'g-navy' },
};
const FALLBACK = ['g-navy', 'g-teal', 'g-violet', 'g-green', 'g-amber', 'g-rose', 'g-sky', 'g-pink'];
const TONES = ['g-navy', 'g-teal', 'g-violet', 'g-green', 'g-amber', 'g-sky'];
const TILE_ICONS = ['list', 'users', 'chart-bar', 'clock', 'sparkles', 'doc-text'];

export default {
  components: { AdminLayout, SbHero, AutoComplete, DatePicker, Link },
  props: { types: Array, filters: Object, selectedUser: Object, me: Object, periods: Array, prefs: Object, lookups: Object, can: Object },

  data() {
    return {
      form: {
        type: this.filters.type,
        period: this.filters.period || this.prefs.default_period,
        from: this.filters.from || '',
        to: this.filters.to || '',
        user_id: this.filters.user_id ? String(this.filters.user_id) : '',
        role: this.filters.role || '',
        project: this.filters.project ? String(this.filters.project) : '',
      },
      report: null, loading: false, error: '',
    };
  },

  computed: {
    current() { return this.types.find(t => t.key === this.form.type) || null; },
    needsUser() { return !!(this.current && this.current.person); },
    // the person field, as a number-or-empty value the auto search dropdown can hold
    personId: {
      get() { return this.form.user_id || ''; },
      set(v) { this.form.user_id = v ? String(v) : ''; this.report = null; this.error = ''; },
    },
    personOptions() {
      const list = (this.lookups.people || []).slice();
      const have = id => list.some(p => String(p.value) === String(id));
      if (this.me.own && !have(this.me.id)) list.unshift({ value: this.me.id, label: this.me.name + ' (' + this.$t('Myself') + ')', name: this.me.name });
      if (this.selectedUser && !have(this.selectedUser.id)) list.unshift({ value: this.selectedUser.id, label: this.selectedUser.name, name: this.selectedUser.name });
      return list;
    },
    roleOptions() { return (this.lookups.roles || []).map(r => ({ value: r, label: r })); },
    projectOptions() { return (this.lookups.projects || []).map(p => ({ value: String(p.id), label: p.name })); },
    runnable() {
      if (!this.current || !this.current.allowed) return false;
      if (this.needsUser && !this.form.user_id) return false;
      if (this.form.period === 'custom' && !(this.form.from && this.form.to)) return false;
      return true;
    },
    query() {
      const f = this.form;
      const p = { type: f.type, period: f.period };
      if (f.period === 'custom') { p.from = f.from; p.to = f.to; }
      if (this.current && (this.current.person || this.current.person_optional) && f.user_id) p.user_id = f.user_id;
      if (this.can.management && this.current && !this.current.person && this.current.kind !== 'admin') {
        if (f.role) p.role = f.role;
        if (f.project) p.project = f.project;
      }
      return p;
    },
    queryString() { return new URLSearchParams(this.query).toString(); },
    isOwnPerson() { return !!(this.current && this.current.person && this.form.user_id && String(this.form.user_id) === String(this.me.id)); },
    // the server decides; this only decides what to show on the buttons
    exportOk() { return this.isOwnPerson ? this.can.export_own : this.can.export_all; },
    exportReason() {
      if (!this.can.export_own) return this.$t('Export is switched off.');
      return this.$t('You may export only your own brief. Reports with other people\'s activity need the export permission.');
    },
    exportButtons() {
      const base = k => route('smart-brief.export.' + k) + '?' + this.queryString;
      return [
        { key: 'print', icon: 'printer', label: this.$t('Print'), href: base('pdf') + '&inline=1' },
        { key: 'pdf', icon: 'download', label: this.$t('PDF'), href: base('pdf') },
        { key: 'csv', icon: 'table', label: this.$t('CSV'), href: base('csv') },
      ];
    },
    previewRows() { return this.report ? this.report.rows.slice(0, PREVIEW_ROWS) : []; },
    shownText() {
      if (!this.report) return '';
      const n = this.report.rows.length;
      const total = this.report.total_rows;
      if (n > PREVIEW_ROWS) return this.$t('Preview shows the first :a of :b rows. Print, PDF and CSV hold all of them.', { a: PREVIEW_ROWS, b: total.toLocaleString() });
      return n + ' ' + this.$t('rows');
    },
  },

  watch: {
    'form.type'() { this.report = null; this.error = ''; },
  },

  mounted() {
    this.ensureDefaults();
    if (this.runnable && new URLSearchParams(window.location.search).has('type')) this.run();
  },

  methods: {
    theme(t, i) { return THEMES[t.key] || { icon: 'doc-text', g: FALLBACK[i % FALLBACK.length] }; },
    tileTone(i) { return TONES[i % TONES.length]; },
    tileIcon(i) { return TILE_ICONS[i % TILE_ICONS.length]; },
    kindLabel(k) { return { person: this.$t('One person'), group: this.$t('Group'), events: this.$t('Detail'), admin: this.$t('Administrative') }[k] || ''; },
    initials(n) { return (n || '').split(' ').filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join(''); },
    cell(v) { return v === '' || v === null || v === undefined ? '-' : (typeof v === 'number' ? v.toLocaleString() : v); },

    // somebody who may only open their own brief gets themselves as the person
    ensureDefaults() {
      if (this.current && this.current.person && !this.form.user_id && this.me.own && !this.can.management) this.personId = this.me.id;
    },
    pickType(t) {
      if (!t.allowed) return;
      this.form.type = t.key;
      this.$nextTick(this.ensureDefaults);
    },
    setPeriod(k) { this.form.period = k; },

    run() {
      if (!this.runnable) return;
      this.loading = true; this.error = '';
      axios.get(route('smart-brief.reports.index'), { params: { ...this.query, preview: 1 } })
        .then(r => {
          this.report = r.data;
          try { window.history.replaceState(null, '', route('smart-brief.reports.index') + '?' + this.queryString); } catch (e) { /* the address bar is a convenience only */ }
        })
        .catch(e => {
          this.report = null;
          this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The report could not be prepared.');
        })
        .finally(() => { this.loading = false; });
    },
  },
};
</script>
