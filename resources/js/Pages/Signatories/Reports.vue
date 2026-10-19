<template>
  <admin-layout :title="$t('Signatories Reports')">
    <div class="lg-page sb px-4 md:px-0">
      <sb-hero icon="chart-bar" :title="$t('Signatories Reports')" :subtitle="$t('Who is registered, what is missing, how signatures changed and who did what. Signature pictures never appear in a report. Every export is logged.')">
        <Link :href="route('signatories.index')" class="sb-gbtn"><icons name="edit" class="w-4 h-4" />{{ $t('Signatories') }}</Link>
      </sb-hero>

      <section class="sbr-types" role="radiogroup" :aria-label="$t('Report type')">
        <button v-for="(t, i) in types" :key="t.key" type="button" role="radio" :aria-checked="form.type === t.key" class="sbr-type lg-tip" :class="[tone(i), { 'is-on': form.type === t.key, 'is-off': !t.allowed }]" :style="{ '--i': i }" :data-tip="t.allowed ? '' : $t('You do not have access to this report.')" @click="pick(t)">
          <span class="sbr-type__top"><span class="sbr-type__ico"><icons :name="icon(t.key)" class="w-5 h-5" /></span></span>
          <b>{{ $t(t.label) }}</b><small>{{ $t(t.description) }}</small>
          <span class="sbr-check"><icons name="tick" class="w-3.5 h-3.5" /></span>
        </button>
      </section>

      <section class="sb-card grid gap-4 mb-4" style="--i: 2">
        <div v-if="usesPeriod" class="sbr-row">
          <span class="sbr-lbl">{{ $t('Period') }}</span>
          <div class="sb-periods"><button v-for="p in periods" :key="p.key" type="button" class="sb-chip" :class="{ 'is-on': form.period === p.key }" @click="form.period = p.key">{{ $t(p.label) }}</button></div>
          <div v-if="form.period === 'custom'" class="sb-range"><date-picker v-model="form.from" :max="form.to || undefined" /><span>-</span><date-picker v-model="form.to" :min="form.from || undefined" /></div>
        </div>
        <div class="sbr-actions">
          <button type="button" class="sb-btn is-primary" :disabled="!runnable || loading" @click="run"><icons name="refresh" class="w-4 h-4" />{{ loading ? $t('Preparing...') : $t('Run report') }}</button>
          <span class="sbr-sep"></span>
          <template v-for="x in exportButtons" :key="x.key">
            <a v-if="can.export" :href="x.href" :target="x.key === 'print' ? '_blank' : null" rel="noopener" class="sb-btn" :class="{ 'is-disabled': !runnable }"><icons :name="x.icon" class="w-4 h-4" />{{ x.label }}</a>
            <span v-else class="sb-btn is-disabled lg-tip" :data-tip="$t('You do not have permission to export Signatories reports.')"><icons :name="x.icon" class="w-4 h-4" />{{ x.label }}</span>
          </template>
        </div>
      </section>

      <p v-if="error" class="sbr-error" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ error }}</p>

      <section v-if="report" class="sbr-report" :key="report.reference">
        <header class="sbr-report__head"><h2>{{ report.title }}</h2><p>{{ report.reference }}<span v-for="(v, k) in report.filters" :key="k"> | {{ k }}: {{ v }}</span></p></header>
        <div v-if="report.tiles.length" class="sb-tiles"><div v-for="(t, i) in report.tiles" :key="t.label" class="sb-tile" :class="tile(i)" :style="{ '--i': i }"><b>{{ cell(t.value) }}</b><span class="sb-tile__lbl">{{ t.label }}</span></div></div>
        <section class="sb-card"><h3 class="sb-card__title"><span class="sb-ico g-navy"><icons name="table" class="w-4 h-4" /></span>{{ report.table_title || report.title }}<small>{{ shownText }}</small></h3>
          <div class="sb-tablewrap"><table class="sb-table"><thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ c.label }}</th></tr></thead>
            <tbody><tr v-for="(r, i) in rows" :key="i"><td v-for="c in report.columns" :key="c.key" :class="{ num: c.align === 'right' }">{{ cell(r[c.key]) }}</td></tr>
              <tr v-if="!report.rows.length"><td :colspan="report.columns.length" class="sbr-empty">{{ $t('Nothing to show for this report.') }}</td></tr></tbody></table></div></section>
        <section v-for="(s, i) in report.sections" :key="'s' + i" class="sb-card"><h3 class="sb-card__title"><span class="sb-ico g-violet"><icons name="list" class="w-4 h-4" /></span>{{ s.title }}</h3>
          <div class="sb-tablewrap"><table class="sb-table"><thead><tr><th v-for="c in s.columns" :key="c.key">{{ c.label }}</th></tr></thead><tbody><tr v-for="(r, j) in s.rows" :key="j"><td v-for="c in s.columns" :key="c.key">{{ cell(r[c.key]) }}</td></tr><tr v-if="!s.rows.length"><td :colspan="s.columns.length" class="sbr-empty">{{ s.empty }}</td></tr></tbody></table></div></section>
        <ul class="sbr-notes"><li v-for="n in report.notes" :key="n"><icons name="info" class="w-4 h-4 flex-none" /><span>{{ $t(n) }}</span></li></ul>
      </section>
      <p v-else-if="!loading && !error" class="sbr-idle">{{ $t('Choose a report and press Run report.') }}</p>
      <div v-if="loading && !report" class="sb-state"><span class="sb-spin"></span>{{ $t('Preparing the report...') }}</div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';

const TONES = ['g-navy', 'g-teal', 'g-violet', 'g-green', 'g-amber', 'g-sky', 'g-rose'];
const ICONS = { registered: 'users', missing: 'exclamation', status: 'chart-pie', versions: 'list', roles: 'edit', documents: 'doc-text', activity: 'clock' };
const PREVIEW = 200;

export default {
  components: { AdminLayout, Link, DatePicker, SbHero },
  props: { types: Array, periods: Array, filters: Object, can: Object },
  data() { return { form: { type: this.filters.type, period: this.filters.period, from: this.filters.from || '', to: this.filters.to || '' }, report: null, loading: false, error: '' }; },
  computed: {
    usesPeriod() { return ['missing', 'versions', 'activity'].includes(this.form.type); },
    current() { return this.types.find(t => t.key === this.form.type); },
    runnable() { return !!(this.current && this.current.allowed) && !(this.usesPeriod && this.form.period === 'custom' && !(this.form.from && this.form.to)); },
    query() { const q = { type: this.form.type }; if (this.usesPeriod) { q.period = this.form.period; if (this.form.period === 'custom') { q.from = this.form.from; q.to = this.form.to; } } return q; },
    exportButtons() { const qs = new URLSearchParams(this.query).toString(); return [{ key: 'print', icon: 'printer', label: this.$t('Print'), href: route('signatories.export.print') + '?' + qs }, { key: 'pdf', icon: 'download', label: this.$t('PDF'), href: route('signatories.export.pdf') + '?' + qs }, { key: 'csv', icon: 'table', label: 'CSV', href: route('signatories.export.csv') + '?' + qs }]; },
    rows() { return this.report ? this.report.rows.slice(0, PREVIEW) : []; },
    shownText() { if (!this.report) return ''; const n = this.report.rows.length; return n > PREVIEW ? this.$t('Preview shows the first :a of :b rows. Print, PDF and CSV hold all of them.', { a: PREVIEW, b: this.report.total_rows }) : n + ' ' + this.$t('rows'); },
  },
  watch: { 'form.type'() { this.report = null; this.error = ''; } },
  mounted() { if (new URLSearchParams(window.location.search).has('type') && this.runnable) this.run(); },
  methods: {
    tone(i) { return TONES[i % TONES.length]; }, tile(i) { return TONES[i % TONES.length]; }, icon(k) { return ICONS[k] || 'doc-text'; },
    cell(v) { return v === '' || v === null || v === undefined ? '-' : (typeof v === 'number' ? v.toLocaleString() : v); },
    pick(t) { if (t.allowed) this.form.type = t.key; },
    run() {
      if (!this.runnable) return;
      this.loading = true; this.error = '';
      axios.get(route('signatories.reports.index'), { params: { ...this.query, preview: 1 } }).then(r => { this.report = r.data; }).catch(e => { this.report = null; this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The report could not be prepared.'); }).finally(() => { this.loading = false; });
    },
  },
};
</script>
