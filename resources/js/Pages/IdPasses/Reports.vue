<template>
  <admin-layout :title="$t('ID Reports')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('ID Reports')" :subtitle="report.subtitle">
        <a :href="url('print')" target="_blank" rel="noopener" class="lg-btn tone tone-solid" :class="{ 'is-off': tooBig }" style="--tone: #4f46e5" @click.prevent="make('print')"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span><span>{{ $t('Print') }}</span></a>
        <a :href="url('pdf')" class="lg-btn tone" :class="{ 'is-off': tooBig }" style="--tone: #0d9488" @click.prevent="make('pdf')"><span class="tone-ico"><icons name="doc-text" class="w-4 h-4" /></span><span>{{ $t('PDF') }}</span></a>
        <a :href="url('csv')" class="lg-btn tone" style="--tone: #0d9488"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span><span>{{ $t('CSV') }}</span></a>
        <Link :href="route('id-passes.dashboard')" class="lg-btn tone" style="--tone: #1e4467"><span class="tone-ico"><icons name="qr-code" class="w-4 h-4" /></span><span>{{ $t('Overview') }}</span></Link>
      </page-header>

      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters rp-filters">
          <div class="ac-f rp-type"><label>{{ $t('Report') }}</label><acct-select v-model="f.type" :options="types" :clearable="false" @update:model-value="go" /></div>
          <div class="ac-f"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Name or number')" /></div>
          <div class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="f.category_id" :options="categories" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f rp-act"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn tone tone-solid" style="--tone: #2563eb"><span class="tone-ico"><icons name="search" class="w-4 h-4" /></span>{{ $t('Show') }}</button></div></div>
        </div>
      </form>

      <section v-if="tooBig" class="lg-note lg-note-amber" style="margin: 0 0 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('This report has :n rows: too many for a printed page or PDF (limit :m). Narrow the dates or filters, or download the CSV.', { n: report.total, m: pdfMax }) }}</span></section>
      <id-make-overlay />
      <div class="rp-tiles"><div v-for="t in report.tiles" :key="t.label"><b>{{ t.value }}</b><span>{{ t.label }}</span></div></div>

      <section v-for="s in report.sections" :key="s.title" class="lg-card"><h3 class="lg-card-title" style="margin: 0 0 8px">{{ s.title }}</h3>
        <div class="lg-table-wrap"><table class="lg-table"><thead><tr><th v-for="c in s.columns" :key="c.key">{{ c.label }}</th></tr></thead><tbody><tr v-for="(r, i) in s.rows" :key="i"><td v-for="c in s.columns" :key="c.key" :style="{ textAlign: c.align }">{{ r[c.key] }}</td></tr></tbody></table></div></section>

      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr><th v-for="c in report.columns" :key="c.key" :style="{ textAlign: c.align }">{{ c.label }}</th></tr></thead>
        <tbody><tr v-for="(r, i) in report.rows" :key="i"><td v-for="c in report.columns" :key="c.key" :data-label="c.label" :style="{ textAlign: c.align }">{{ r[c.key] === '' || r[c.key] === null ? '-' : r[c.key] }}</td></tr>
          <tr v-if="!report.rows.length" class="lg-empty-row"><td class="lg-empty" :colspan="report.columns.length">{{ $t('Nothing matches.') }}</td></tr></tbody></table></div>
      <p v-if="report.total > report.rows.length" class="lg-sub">{{ $t('The first :n of :t rows are shown here. Print, PDF and CSV hold all of them.', { n: report.rows.length, t: report.total }) }}</p>
      <ul v-if="report.notes && report.notes.length" class="rp-notes"><li v-for="n in report.notes" :key="n">{{ n }}</li></ul>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import IdMakeOverlay from './Parts/IdMakeOverlay.vue';
import { idMakeFile, idMakeFail } from '@/Core/idMake';

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect, IdMakeOverlay },
  props: { filters: Object, types: Array, report: Object, categories: Array, pdfMax: { type: Number, default: 600 } },
  computed: { tooBig() { return (this.report.total || 0) > this.pdfMax; } },
  data() { return { f: { ...this.filters } }; },
  methods: {
    query() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); return q; },
    go() { this.$inertia.get(route('id-passes.reports.index'), this.query(), { preserveState: true }); },
    // the report is made while the "ID press" animation plays; print opens it in a new tab, PDF saves it
    make(kind) {
      if (this.tooBig) { idMakeFail(this.$t('This report has :n rows, which is too many for a printed page or PDF (the limit is :m). Narrow the dates or the filters, or download the CSV, which has no limit.', { n: this.report.total, m: this.pdfMax })); return; }
      idMakeFile(this.url(kind), { mode: kind, title: kind === 'print' ? this.$t('Preparing the report for printing') : this.$t('Creating the PDF report'), doneText: this.report.title, fail: this.$t('The report could not be made.') });
    },
    url(kind) { return route('id-passes.reports.' + kind, this.query()); },
  },
};
</script>

<style scoped>
@media (min-width: 1100px) { .rp-filters { grid-template-columns: minmax(0, 1.5fr) repeat(4, minmax(0, 1fr)) auto; } }
.rp-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(140px, 100%), 1fr)); gap: 10px; margin: 14px 0; } .rp-tiles > div { padding: 12px 16px; border-radius: 16px; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); }
.rp-tiles b { display: block; font-size: 1.5rem; color: #1e4467; } .rp-tiles span { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.rp-notes { margin: 12px 0 0; padding-left: 18px; color: var(--lg-mute, #64748b); font-size: 0.8rem; }
.lg-btn.is-off { opacity: 0.55; }
</style>
