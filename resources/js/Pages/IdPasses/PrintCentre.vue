<template>
  <admin-layout :title="$t('ID Print Centre')">
    <id-make-overlay />
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="printer" :title="$t('ID Print Centre')" :subtitle="$t('Choose the IDs, see how many cards and sheets they make, and generate the PDF.')">
        <Link :href="route('id-passes.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Overview') }}</span></Link>
        <Link v-if="can.history" :href="route('id-passes.print-centre.history')" class="lg-btn lg-btn-soft"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Print History') }}</span></Link>
      </page-header>

      <div class="pcn-grid">
        <div class="pcn-left">
          <form class="lg-card" @submit.prevent="load(1)">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="filter" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('1. Which IDs') }}</h2><p class="lg-card-sub">{{ $t('Only active IDs are printed.') }}</p></div></header>
            <div class="pcn-kinds"><button v-for="k in kinds" :key="k.value" type="button" :class="{ 'is-on': f.kind === k.value }" :style="{ '--c': k.tone }" @click="f.kind = f.kind === k.value ? '' : k.value; f.category_id = ''; load(1)">{{ k.label }}</button></div>
            <div class="ac-filters pcn-filters">
              <div class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="f.category_id" :options="catOptions" :placeholder="$t('Any')" clearable @update:model-value="load(1)" /></div>
              <div class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statusOptions" :placeholder="$t('Active')" clearable @update:model-value="load(1)" /></div>
              <div class="ac-f"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Name or number')" @input="debounce" /></div>
              <div class="ac-f"><label>{{ $t('Issued from') }}</label><date-picker v-model="f.issued_from" @update:model-value="load(1)" /></div>
              <div class="ac-f"><label>{{ $t('Issued to') }}</label><date-picker v-model="f.issued_to" @update:model-value="load(1)" /></div>
              <div class="ac-f"><label>{{ $t('Expires to') }}</label><date-picker v-model="f.expires_to" @update:model-value="load(1)" /></div>
              <div class="ac-f"><label>{{ $t('Printed before?') }}</label><acct-select v-model="f.printed" :options="printedOptions" :placeholder="$t('Any')" clearable @update:model-value="load(1)" /></div>
            </div>
            <div class="pcn-mode">
              <label><input v-model="mode" type="radio" value="filter" /> {{ $t('All :n that match', { n: total }) }}</label>
              <label><input v-model="mode" type="radio" value="ids" /> {{ $t('Only the ticked ones') }} <b>({{ selected.length }})</b></label>
            </div>
          </form>

          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead><tr><th class="pcn-chk"><input type="checkbox" :checked="allOnPage" @change="togglePage" :aria-label="$t('Tick this page')" /></th><th>{{ $t('ID number') }}</th><th>{{ $t('Name') }}</th><th>{{ $t('Category') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Valid until') }}</th><th>{{ $t('Printed') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in rows" :key="r.id" class="pcn-row" :style="{ '--c': r.color }" :class="{ 'is-sel': selected.includes(r.id) }" @click="toggle(r)">
                  <td class="pcn-chk" @click.stop><input type="checkbox" :checked="selected.includes(r.id)" :disabled="r.status !== 'Active'" @change="toggle(r)" /></td>
                  <td :data-label="$t('ID number')"><b class="idl-no">{{ r.number }}</b></td><td :data-label="$t('Name')">{{ r.name }}</td><td :data-label="$t('Category')"><category-chip :name="r.category" :color="r.color" :ink="r.ink" :icon="r.icon" /></td>
                  <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status)">{{ $t(r.status) }}</span></td><td :data-label="$t('Valid until')">{{ r.expires_at ? $date(r.expires_at) : $t('No expiry') }}</td><td :data-label="$t('Printed')">{{ r.print_count }}</td>
                </tr>
                <tr v-if="!rows.length && !busy" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('No ID matches.') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div v-if="last > 1" class="pcn-pages"><button type="button" class="lg-btn lg-btn-soft" :disabled="page <= 1" @click="load(page - 1)">{{ $t('Previous') }}</button><span>{{ page }} / {{ last }}</span><button type="button" class="lg-btn lg-btn-soft" :disabled="page >= last" @click="load(page + 1)">{{ $t('Next') }}</button></div>
        </div>

        <aside class="pcn-right">
          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('2. How') }}</h2></div></header>
            <div class="ac-f"><label>{{ $t('Output') }}</label>
              <div class="pcn-seg"><button v-if="can.print" type="button" :class="{ 'is-on': action === 'print' }" @click="action = 'print'">{{ $t('Print sheets') }}</button><button v-if="can.pdf" type="button" :class="{ 'is-on': action === 'pdf' }" @click="action = 'pdf'">{{ $t('Export PDF') }}</button></div></div>
            <div class="ac-f"><label>{{ $t('Layout') }}</label>
              <label v-for="o in layoutOptions" :key="o.value" class="pcn-opt" :class="{ 'is-on': layout === o.value }"><input v-model="layout" type="radio" :value="o.value" /><span><b>{{ o.label }}</b><small>{{ o.help }}</small></span></label></div>
            <div v-if="layout === 'a4_side'" class="ac-f"><label>{{ $t('Sheet') }}</label><div class="pcn-seg"><button type="button" :class="{ 'is-on': orientation === 'landscape' }" @click="orientation = 'landscape'">{{ $t('Landscape') }}</button><button type="button" :class="{ 'is-on': orientation === 'portrait' }" @click="orientation = 'portrait'">{{ $t('Portrait') }}</button></div></div>
            <div class="pcn-sum" :class="{ 'is-bad': pv && !pv.cards }">
              <template v-if="pv"><b>{{ pv.cards }} {{ $t('cards') }}</b>
                <span v-if="layout === 'card'">{{ pv.pages }} {{ $t('pages of 55 x 85 mm') }}</span><span v-else>{{ pv.sheets }} {{ $t('A4 sheets') }} · {{ pv.per_sheet }} {{ layout === 'a4_duplex' ? $t('cards per sheet') : $t('pairs per sheet') }}</span>
                <span v-if="pv.skipped > 0" class="pcn-warn">{{ $t(':n not active, left out', { n: pv.skipped }) }}</span><span v-if="pv.parts > 1">{{ $t('Made as :n files of up to :m cards', { n: pv.parts, m: limits.part }) }}</span><span v-if="pv.background" class="pcn-warn">{{ $t('A large batch: it is made in the background.') }}</span>
                <span v-if="pv.over" class="pcn-bad">{{ $t('More than :n cards: narrow the filters.', { n: limits.max }) }}</span></template>
              <span v-else>{{ $t('Counting...') }}</span>
            </div>
            <div v-if="pv && pv.reprints > 0" class="ac-f pcn-again"><label>{{ $t(':n of these IDs were printed before. Why are they printed again?', { n: pv.reprints }) }} <b class="wims-req">*</b></label>
              <div class="pcn-seg pcn-reasons"><button v-for="r in reasons" :key="r" type="button" :class="{ 'is-on': reason === r }" @click="reason = r">{{ r }}</button></div>
              <input v-model="note" maxlength="150" :placeholder="$t('More about it (optional)')" /></div>
            <p v-if="layout === 'a4_duplex'" class="lg-note lg-note-amber"><icons name="info" class="w-4 h-4" /><span>{{ $t('Print double sided, flip on the long edge, at 100% (actual size).') }}</span></p>
            <button type="button" class="lg-btn lg-btn-primary pcn-go" :disabled="working || !pv || !pv.cards || pv.over || (pv.reprints > 0 && !reason)" @click="generate"><icons :name="working ? 'refresh' : 'printer'" class="w-4 h-4" :class="{ 'animate-spin': working }" />{{ working ? $t('Working...') : (action === 'pdf' ? $t('Generate PDF') : $t('Generate sheets')) }}</button>
            <span class="ac-err">{{ error }}</span>
          </section>

          <section v-if="batch" class="lg-card pcn-batch">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ batch.reference }}</h2><p class="lg-card-sub">{{ batch.cards }} {{ $t('cards') }} · {{ batch.status }}</p></div></header>
            <div class="pcn-bar"><i :style="{ width: batch.progress + '%' }"></i></div>
            <p v-if="batch.status === 'Failed'" class="ac-err">{{ batch.error }}</p>
            <div v-if="batch.status === 'Done'" class="pcn-files"><a v-for="p in batch.parts" :key="p" :href="route('id-passes.batch.download', [batch.id]) + '?part=' + p" :target="batch.action === 'print' ? '_blank' : null" class="lg-btn lg-btn-soft"><icons :name="batch.action === 'print' ? 'printer' : 'download'" class="w-4 h-4" />{{ batch.parts > 1 ? $t('Part :n', { n: p }) : (batch.action === 'print' ? $t('Open for printing') : $t('Download')) }}</a></div>
          </section>

          <section v-if="batches.length" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recent batches') }}</h2></div></header>
            <ul class="pcn-recent"><li v-for="b in batches" :key="b.id"><b>{{ b.reference }}</b><span>{{ b.cards }} {{ $t('cards') }} · {{ b.status }}</span><small>{{ b.by }} · {{ $datetime(b.at) }}</small>
              <span v-if="b.status === 'Done'" class="pcn-files"><a v-for="p in b.parts" :key="p" :href="route('id-passes.batch.download', [b.id]) + '?part=' + p" :target="b.action === 'print' ? '_blank' : null" class="lg-chip lg-chip-blue">{{ b.parts > 1 ? $t('Part :n', { n: p }) : $t('Open') }}</a></span></li></ul>
          </section>
        </aside>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import { statusChip } from './Parts/status';
import CategoryChip from './Parts/CategoryChip.vue';
import IdMakeOverlay from './Parts/IdMakeOverlay.vue';
import { idMakeStart, idMakeProgress, idMakeDone, idMakeFail } from '@/Core/idMake';

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect, CategoryChip, IdMakeOverlay },
  props: { kinds: Array, categories: Array, statuses: Array, batches: Array, can: Object, limits: Object },
  data() {
    return { f: { kind: '', category_id: '', status: 'Active', q: '', issued_from: '', issued_to: '', expires_to: '', printed: '' }, rows: [], total: 0, page: 1, last: 1, selected: [], mode: 'filter', action: this.can.print ? 'print' : 'pdf', layout: 'a4_side', orientation: 'landscape', pv: null, working: false, busy: false, error: '', batch: null, reason: '', note: '', timer: null, pvTimer: null, poll: null };
  },
  computed: {
    catOptions() { return this.categories.filter(c => !this.f.kind || c.kind === this.f.kind).map(c => ({ value: c.value, label: c.label })); },
    statusOptions() { return this.statuses.map(s => ({ value: s.value, label: s.label })); },
    printedOptions() { return [{ value: 'no', label: this.$t('Never printed') }, { value: 'yes', label: this.$t('Printed before') }]; },
    allOnPage() { const a = this.rows.filter(r => r.status === 'Active'); return a.length > 0 && a.every(r => this.selected.includes(r.id)); },
    reasons() { return [this.$t('Lost'), this.$t('Broken or damaged'), this.$t('Details changed'), this.$t('Other')]; },
    layoutOptions() { return [{ value: 'a4_side', label: this.$t('A4: front and back side by side'), help: this.$t('Pairs with cutting marks. Real size.') }, { value: 'a4_duplex', label: this.$t('A4: double sided'), help: this.$t('Sheets of fronts, then sheets of backs lined up behind them.') }, { value: 'card', label: this.$t('Card size PDF'), help: this.$t('Every card as two pages of 55 x 85 mm: front, back, next card.') }]; },
  },
  watch: {
    mode() { this.queuePreview(); }, selected() { if (this.mode === 'ids') this.queuePreview(); }, action() { this.load(1); }, layout() { this.queuePreview(); }, orientation() { this.queuePreview(); },
  },
  mounted() { this.load(1); },
  beforeUnmount() { clearInterval(this.poll); },
  methods: {
    statusChip,
    debounce() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(1), 350); },
    cleanFilters() { const o = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) o[k] = this.f[k]; }); if (!o.status) o.status = 'Active'; return o; },
    async load(page) {
      this.busy = true; this.page = page;
      try { const { data } = await window.axios.get(route('id-passes.print-centre.cards'), { params: { ...this.cleanFilters(), page, action: this.action } }); this.rows = data.data; this.total = data.total; this.last = data.last; } catch (e) { this.rows = []; }
      this.busy = false; this.queuePreview();
    },
    toggle(r) { if (r.status !== 'Active') return; const i = this.selected.indexOf(r.id); if (i >= 0) this.selected.splice(i, 1); else this.selected.push(r.id); if (this.selected.length) this.mode = 'ids'; },
    togglePage() { const ids = this.rows.filter(r => r.status === 'Active').map(r => r.id); if (this.allOnPage) this.selected = this.selected.filter(i => !ids.includes(i)); else this.selected = [...new Set([...this.selected, ...ids])]; if (this.selected.length) this.mode = 'ids'; },
    body() { return { action: this.action, mode: this.mode, ids: this.selected, filters: this.cleanFilters(), layout: this.layout, orientation: this.orientation, reason: this.reason ? this.reason + (this.note ? ': ' + this.note : '') : undefined }; },
    queuePreview() { clearTimeout(this.pvTimer); this.pvTimer = setTimeout(this.preview, 250); },
    async preview() {
      if (this.mode === 'ids' && !this.selected.length) { this.pv = { cards: 0, skipped: 0, sheets: 0, pages: 0, parts: 0, per_sheet: 0 }; return; }
      try { const { data } = await window.axios.post(route('id-passes.batch.preview'), this.body()); this.pv = data; } catch (e) { this.pv = null; this.error = (e.response && e.response.data && e.response.data.message) || ''; }
    },
    async generate() {
      this.working = true; this.error = '';
      const cards = this.pv ? this.pv.cards : 0;
      // the "ID press" animation runs for the whole batch, with the real progress of the background job
      idMakeStart({ mode: this.action === 'pdf' ? 'pdf' : 'batch', color: '#1565c0', count: cards, total: cards, hideable: true, percent: 3, title: this.action === 'pdf' ? this.$t('Creating the PDF files') : this.$t('Preparing :n cards for printing', { n: cards }) });
      try { const { data } = await window.axios.post(route('id-passes.batch.store'), this.body(), { headers: { 'X-Quiet': '1' } }); this.batch = data.batch; this.follow(); }
      catch (e) { this.error = (e.response && e.response.data && (e.response.data.message || Object.values(e.response.data.errors || {}).flat().join(' '))) || this.$t('The batch could not be made.'); this.working = false; idMakeFail(this.error); }
    },
    follow() {
      clearInterval(this.poll);
      const tick = async () => { try { const { data } = await window.axios.get(route('id-passes.batch.show', [this.batch.id])); this.batch = data; idMakeProgress(Math.max(3, data.progress), Math.round((data.progress / 100) * data.cards), data.cards); if (['Done', 'Failed'].includes(data.status)) { clearInterval(this.poll); this.working = false; if (data.status === 'Done') idMakeDone(this.$t(':n cards', { n: data.cards })); else idMakeFail(data.error || this.$t('The batch could not be made.')); if (data.status === 'Done' && data.parts === 1 && data.action === 'print') window.open(route('id-passes.batch.download', [data.id]) + '?part=1', '_blank', 'noopener'); this.load(this.page); } } catch (e) { /* try again */ } };
      tick(); if (this.working) this.poll = setInterval(tick, 1500);
    },
  },
};
</script>

<style scoped>
.pcn-grid { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1100px) { .pcn-grid { grid-template-columns: minmax(0, 1fr) 380px; } .pcn-right { position: sticky; top: 84px; } }
.pcn-left { display: grid; gap: 14px; } .pcn-right { display: grid; gap: 14px; }
.pcn-kinds { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; } .pcn-kinds button { border: 0; padding: 8px 16px; border-radius: 999px; font-weight: 800; font-size: 0.82rem; cursor: pointer; background: var(--lg-soft, #f1f5f9); color: var(--lg-ink, #0f172a); transition: all 0.2s; }
.pcn-kinds button.is-on { background: var(--c); color: #fff; box-shadow: 0 10px 18px -10px var(--c); }
.pcn-filters { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
.pcn-mode { display: flex; gap: 22px; flex-wrap: wrap; margin-top: 10px; padding-top: 12px; border-top: 1px solid var(--lg-line, #e2e8f0); font-weight: 700; } .pcn-mode label { display: flex; gap: 6px; align-items: center; cursor: pointer; }
.pcn-chk { width: 38px; } tr.is-sel td { background: rgba(21, 101, 192, 0.07); }
.pcn-row td:first-child { box-shadow: inset 5px 0 0 var(--c); }
.pcn-pages { display: flex; gap: 12px; align-items: center; justify-content: center; }
.pcn-seg { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); } .pcn-seg button { border: 0; padding: 7px 14px; border-radius: 999px; background: transparent; font-weight: 700; font-size: 0.82rem; color: var(--lg-mute, #64748b); cursor: pointer; } .pcn-seg button.is-on { background: #fff; color: #1e4467; box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); }
.pcn-opt { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; margin-top: 6px; border-radius: 14px; cursor: pointer; box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); } .pcn-opt.is-on { box-shadow: 0 0 0 2px #1565c0; } .pcn-opt span { display: grid; } .pcn-opt small { color: var(--lg-mute, #64748b); }
.pcn-sum { display: grid; gap: 3px; padding: 12px 14px; border-radius: 16px; background: rgba(21, 101, 192, 0.08); margin: 12px 0; font-size: 0.86rem; } .pcn-sum b { font-size: 1.15rem; color: #1e4467; } .pcn-warn { color: #b45309; font-weight: 700; } .pcn-bad { color: #be123c; font-weight: 800; } .pcn-sum.is-bad { background: rgba(190, 18, 60, 0.07); }
.pcn-again { margin: 10px 0; } .pcn-reasons { flex-wrap: wrap; border-radius: 18px; }
.pcn-go { width: 100%; justify-content: center; }
.pcn-bar { height: 8px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); overflow: hidden; margin-bottom: 12px; } .pcn-bar i { display: block; height: 100%; background: linear-gradient(90deg, #1565c0, #34d399); transition: width 0.4s; }
.pcn-files { display: flex; flex-wrap: wrap; gap: 8px; }
.pcn-recent { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; } .pcn-recent li { display: grid; gap: 2px; padding-bottom: 10px; border-bottom: 1px solid var(--lg-line, #e2e8f0); } .pcn-recent small { color: var(--lg-mute, #64748b); }
.idl-no { letter-spacing: 0.04em; color: #1e4467; }
</style>
