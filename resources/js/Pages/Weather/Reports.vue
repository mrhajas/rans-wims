<template>
  <admin-layout :title="$t('Weather & Site Risk Report')">
    <div class="lg-page sb wx sc-ui px-4 md:px-0">
      <wx-header current="reports" :title="$t('Weather & Site Risk Report')" :subtitle="$t('Observations, advisory warnings and their acknowledgements, forecast against observed, and the source and time of every forecast used. Every export is logged.')" />

      <section class="sb-card" style="--i: 1">
        <div class="wx-form">
          <div class="sb-field" :class="{ 'is-bad': needProject }"><label>{{ $t('Project') }} <b class="wx-req">*</b></label><auto-complete v-model="form.project" :suggestions="projectOptions" :placeholder="$t('Select a project')" clearable /><small v-if="needProject" class="wx-fielderr" role="alert"><icons name="exclamation" class="w-3.5 h-3.5" />{{ $t('Select a project first.') }}</small></div>
          <div class="sb-field"><label>{{ $t('From') }}</label><date-picker v-model="form.from" :max="form.to || undefined" /></div>
          <div class="sb-field"><label>{{ $t('To') }}</label><date-picker v-model="form.to" :min="form.from || undefined" /></div>
        </div>
        <div class="sb-periods" role="group" :aria-label="$t('Quick periods')" style="margin-top: 0.8rem">
          <button v-for="p in quick" :key="p.days" type="button" class="wx-quick" :class="{ 'is-on': activeQuick === p.days }" :aria-pressed="activeQuick === p.days ? 'true' : 'false'" @click="setQuick(p.days)"><svg v-if="activeQuick === p.days" viewBox="0 0 20 20" class="w-4 h-4" aria-hidden="true"><path d="M4.5 10.5l3.7 3.7 7.3-8" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ p.label }}</button>
        </div>
        <div class="sbr-actions">
          <button type="button" class="sb-btn is-primary" :disabled="loading" @click="run"><icons name="refresh" class="w-4 h-4" />{{ loading ? $t('Preparing...') : $t('Run Report') }}</button>
          <span class="sbr-sep"></span>
          <template v-if="can.print">
            <a :href="url('print')" target="_blank" rel="noopener" class="sb-btn" :class="{ 'is-disabled': !runnable }" @click="guard($event)"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
            <a :href="url('pdf')" class="sb-btn" :class="{ 'is-disabled': !runnable }" @click="guard($event)"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
            <div class="sb-field" style="min-width: 200px; flex: 0 1 240px"><auto-complete v-model="section" :suggestions="sections" :placeholder="$t('CSV of...')" /></div>
            <a :href="url('csv') + '&section=' + section" class="sb-btn" :class="{ 'is-disabled': !runnable }" @click="guard($event)"><icons name="table" class="w-4 h-4" />{{ $t('CSV') }}</a>
          </template>
          <span v-else class="wx-note">{{ $t('Printing and downloading need the Print Weather Reports permission.') }}</span>
        </div>
      </section>

      <p v-if="error" class="sbr-error" role="alert" style="margin-top: 0.9rem"><icons name="exclamation" class="w-4 h-4" />{{ error }}</p>

      <section v-if="report" class="wx-doc" style="margin-top: 0.9rem">
        <div class="wx-doc__title">
          <div class="wx-doc__name">{{ report.title }}</div>
          <div class="wx-doc__ref">{{ $t('Reference') }}<strong>{{ report.reference }}</strong></div>
        </div>
        <dl class="wx-doc__meta">
          <div v-if="report.subtitle"><dt>{{ $t('Report') }}</dt><dd>{{ report.subtitle }}</dd></div>
          <div><dt>{{ $t('Filters') }}</dt><dd><span v-for="(v, k, n) in report.filters" :key="k"><template v-if="n">&nbsp;|&nbsp;</template>{{ k }}: {{ v }}</span></dd></div>
        </dl>
        <div class="wx-doc__tiles">
          <div v-for="t in report.tiles" :key="t.label" class="wx-doc__tile"><b>{{ t.value }}</b><span>{{ t.label }}</span></div>
        </div>

        <h3 class="wx-doc__h">{{ report.table_title }} <small>{{ report.total_rows }} {{ $t('rows') }}</small></h3>
        <div v-if="report.rows.length" class="wx-doc__scroll">
          <table class="wx-doc__table">
            <thead><tr><th v-for="c in obsCols" :key="c.key" :class="{ num: c.num }">{{ c.label }}</th></tr></thead>
            <tbody><tr v-for="(r, i) in report.rows" :key="i"><td v-for="c in obsCols" :key="c.key" :class="{ num: c.num }" :style="colStyle(report.rows, c.key)">{{ cell(r[c.key]) }}</td></tr></tbody>
          </table>
        </div>
        <p v-else class="wx-doc__empty">{{ $t('Nothing was recorded on site in this period.') }}</p>

        <template v-for="(s, i) in report.sections" :key="i">
          <h3 class="wx-doc__h">{{ s.title }}</h3>
          <div v-if="s.rows.length" class="wx-doc__scroll">
            <table class="wx-doc__table">
              <thead><tr><th v-for="c in s.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ c.label }}</th></tr></thead>
              <tbody><tr v-for="(r, j) in s.rows" :key="j"><td v-for="c in s.columns" :key="c.key" :class="{ num: c.align === 'right' }" :style="colStyle(s.rows, c.key)">{{ cell(r[c.key]) }}</td></tr></tbody>
            </table>
          </div>
          <p v-else class="wx-doc__empty">{{ s.empty }}</p>
        </template>

        <div class="wx-doc__notes">
          <h4>{{ $t('About these figures') }}</h4>
          <ul><li v-for="n in report.notes" :key="n">{{ n }}</li></ul>
        </div>
      </section>
      <p v-else-if="!loading && !error" class="sbr-idle">{{ $t('Choose the project and dates, then press Run Report.') }}</p>
      <div v-if="loading && !report" class="sb-state"><span class="sb-spin"></span>{{ $t('Preparing the report...') }}</div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import WxHeader from '@/Shared/Weather/WxHeader.vue';

export default {
  components: { AdminLayout, AutoComplete, DatePicker, WxHeader },
  props: { filters: Object, projectOptions: Array, can: Object, maxDays: Number, sections: Array },
  data() {
    return { form: { project: this.filters.project || '', from: this.filters.from, to: this.filters.to }, section: 'observations', report: null, loading: false, error: '', needProject: false, tones: ['g-green', 'g-rose', 'g-amber', 'g-teal', 'g-violet', 'g-sky', 'g-pink'], icons: ['edit', 'exclamation', 'clock', 'tick', 'bell', 'chart-bar', 'x-circle'] };
  },
  computed: {
    runnable() { return !!(this.form.from && this.form.to && this.form.project); },
    obsCols() { return [{ key: 'when', label: this.$t('Observed at') }, { key: 'project', label: this.$t('Project') }, { key: 'conditions', label: this.$t('Conditions') }, { key: 'rain', label: this.$t('Rain') }, { key: 'temp', label: this.$t('Temp (°C)'), num: true }, { key: 'wind', label: this.$t('Wind') }, { key: 'by', label: this.$t('Recorded by') }, { key: 'notes', label: this.$t('Notes') }]; },
    // which quick period the dates match right now (null when they were picked by hand)
    activeQuick() {
      const pad = n => String(n).padStart(2, '0'); const fmt = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      const today = fmt(new Date());
      for (const p of this.quick) { const f = new Date(); f.setDate(f.getDate() - (p.days - 1)); if (this.form.to === today && this.form.from === fmt(f)) return p.days; }
      return null;
    },
    quick() { return [{ days: 7, label: this.$t('Last 7 days') }, { days: 30, label: this.$t('Last 30 days') }, { days: 90, label: this.$t('Last 90 days') }]; },
    query() { const q = { from: this.form.from, to: this.form.to }; if (this.form.project) q.project = this.form.project; return q; },
  },
  watch: { form: { deep: true, handler() { this.report = null; this.error = ''; if (this.form.project) this.needProject = false; } } },
  mounted() { if (new URLSearchParams(window.location.search).has('from') && this.form.project) this.run(); },
  methods: {
    url(kind) { return route('weather.reports.' + kind) + '?' + new URLSearchParams(this.query).toString(); },
    setQuick(days) {
      const pad = n => String(n).padStart(2, '0');
      const fmt = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      const to = new Date(); const from = new Date(); from.setDate(from.getDate() - (days - 1));
      this.form.from = fmt(from); this.form.to = fmt(to);
    },
    // a long text column (the warning itself, notes) gets room to read; short columns stay narrow
    colStyle(rows, key) {
      const longest = Math.max(0, ...rows.slice(0, 40).map(r => String(r[key] ?? '').length));
      return { minWidth: Math.min(380, Math.max(84, longest * 6.4)) + 'px' };
    },
    cell(v) { return v === null || v === undefined || v === '' ? '-' : v; },
    // a project has to be chosen before a report is run, printed or downloaded
    guard(ev) {
      if (!this.form.project) { ev.preventDefault(); this.askProject(); return false; }
      if (!this.runnable) ev.preventDefault();
    },
    askProject() { this.needProject = true; this.error = this.$t('Select a project first, then press Run Report.'); },
    run() {
      if (!this.form.project) { this.askProject(); return; }
      if (!this.runnable) return;
      this.needProject = false;
      this.loading = true; this.error = '';
      axios.get(route('weather.reports.index'), { params: { ...this.query, preview: 1 } }).then(r => { this.report = r.data; }).catch(e => {
        this.report = null;
        this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The report could not be prepared.');
      }).finally(() => { this.loading = false; });
    },
  },
};
</script>
