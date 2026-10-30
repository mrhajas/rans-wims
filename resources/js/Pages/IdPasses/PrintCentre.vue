<template>
  <admin-layout :title="$t('ID Print Centre')">
    <id-make-overlay />
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="printer" :title="$t('ID Print Centre')" :subtitle="$t('Choose the IDs, see how many cards and sheets they make, and generate the PDF.')">
        <Link :href="route('id-passes.dashboard')" class="lg-btn tone" style="--tone: #7c3aed"><span class="tone-ico"><icons name="chart-bar" class="w-4 h-4" /></span><span>{{ $t('Overview') }}</span></Link>
        <Link v-if="can.history" :href="route('id-passes.print-centre.history')" class="lg-btn tone" style="--tone: #d97706"><span class="tone-ico"><icons name="clock" class="w-4 h-4" /></span><span>{{ $t('Print History') }}</span></Link>
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
                <tr v-for="r in rows" :key="r.id" class="pcn-row" :data-row="r.id" :style="{ '--c': r.color }" :class="{ 'is-sel': selected.includes(r.id) }" @click="toggle(r)">
                  <td class="pcn-chk" @click.stop><input type="checkbox" :checked="selected.includes(r.id)" :disabled="r.status !== 'Active'" @change="toggle(r)" /></td>
                  <td :data-label="$t('ID number')"><b class="idl-no">{{ r.number }}</b></td><td :data-label="$t('Name')">{{ r.name }}</td><td :data-label="$t('Category')"><category-chip :name="r.category" :color="r.color" :ink="r.ink" :icon="r.icon" /></td>
                  <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status)">{{ $t(r.status) }}</span></td><td :data-label="$t('Valid until')">{{ r.expires_at ? $date(r.expires_at) : $t('No expiry') }}</td><td :data-label="$t('Printed')">{{ r.print_count }}</td>
                </tr>
                <tr v-if="!rows.length && !busy" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('No ID matches.') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div v-if="last > 1" class="pcn-pages"><button type="button" class="lg-btn tone" :disabled="page <= 1" @click="load(page - 1)" style="--tone: #2563eb"><span class="tone-ico"><icons name="arrow-left" class="w-4 h-4" /></span>{{ $t('Previous') }}</button><span>{{ page }} / {{ last }}</span><button type="button" class="lg-btn tone" :disabled="page >= last" @click="load(page + 1)" style="--tone: #2563eb"><span class="tone-ico"><icons name="forward" class="w-4 h-4" /></span>{{ $t('Next') }}</button></div>
        </div>

        <aside class="pcn-right">
          <div class="pcn-stick">
          <section class="lg-card pcn-sheets">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Selected sheets') }}</h2><p class="lg-card-sub">{{ shown.example ? (mode === 'filter' && total ? $t('Nothing ticked: all :n IDs that match are made. Tick IDs to see them here.', { n: total }) : $t('Nothing ticked yet. Tick an ID and it moves onto the sheet.')) : $t(':n cards, as they will be printed', { n: selected.length }) }}</p></div></header>
            <div class="pcn-sheetbox" :class="{ 'is-empty': shown.example }"><sheet-preview :ids="shown.ids" :faces="faces" :meta="meta" :css="faceCss" :layout="layout" :orientation="orientation" :horizontal="sideBySide" :locate="locate" /></div>
            <p v-if="shown.example" class="pcn-hint"><icons name="collection" class="w-4 h-4" /><span>{{ $t('Ticked IDs appear on the sheet.') }}</span></p>
            <div v-if="shown.spreads > 1" class="pcn-pager">
              <button type="button" class="pcn-pg" :disabled="spread <= 0" :aria-label="$t('Previous')" @click="spread--"><icons name="arrow-left" class="w-4 h-4" /></button>
              <span :key="spread" class="pcn-pop">{{ $t('Cards :a to :b of :n', { a: shown.from, b: shown.to, n: selected.length }) }}</span>
              <button type="button" class="pcn-pg" :disabled="spread >= shown.spreads - 1" :aria-label="$t('Next')" @click="spread++"><icons name="forward" class="w-4 h-4" /></button>
            </div>
          </section>

          <section class="lg-card pcn-how">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('2. How') }}</h2></div></header>
            <div class="pcn-twin">
              <div class="pcn-field"><span class="pcn-lab">{{ $t('Output') }}</span>
                <div class="pcn-seg"><button v-if="can.print" type="button" :class="{ 'is-on': action === 'print' }" @click="action = 'print'">{{ $t('Print sheets') }}</button><button v-if="can.pdf" type="button" :class="{ 'is-on': action === 'pdf' }" @click="action = 'pdf'">{{ $t('Export PDF') }}</button></div></div>
              <div v-if="layout === 'a4_side'" class="pcn-field"><span class="pcn-lab">{{ $t('Sheet') }}</span><div class="pcn-seg"><button type="button" :class="{ 'is-on': orientation === 'landscape' }" @click="orientation = 'landscape'">{{ $t('Landscape') }}</button><button type="button" :class="{ 'is-on': orientation === 'portrait' }" @click="orientation = 'portrait'">{{ $t('Portrait') }}</button></div></div>
            </div>
            <div class="pcn-field"><span class="pcn-lab">{{ $t('Layout') }}</span>
              <div class="pcn-opts" role="radiogroup">
                <button v-for="o in layoutOptions" :key="o.value" type="button" role="radio" :aria-checked="layout === o.value" class="pcn-opt" :class="{ 'is-on': layout === o.value }" @click="layout = o.value">
                  <span class="pcn-opt-th"><sheet-preview compact horizontal :ids="thumbIds" :faces="faces" :meta="meta" :css="faceCss" :layout="o.value" :orientation="o.value === 'a4_side' ? orientation : 'landscape'" /></span>
                  <span class="pcn-opt-tx"><b>{{ o.short }}</b></span>
                </button>
              </div>
              <p class="pcn-help" :key="layout">{{ currentLayout.help }}</p></div>
            <div class="pcn-sum" :class="{ 'is-bad': pv && !pv.cards, 'is-stale': stale }">
              <template v-if="pv"><b :key="pv.cards" class="pcn-pop">{{ pv.cards }} {{ $t('cards') }}</b>
                <span>{{ sumLine }}</span>
                <span v-if="pv.skipped > 0" class="pcn-warn">{{ $t(':n not active, left out', { n: pv.skipped }) }}</span><span v-if="pv.parts > 1">{{ $t('Made as :n files of up to :m cards', { n: pv.parts, m: limits.part }) }}</span><span v-if="pv.background" class="pcn-warn">{{ $t('A large batch: it is made in the background.') }}</span>
                <span v-if="pv.over" class="pcn-bad">{{ $t('More than :n cards: narrow the filters.', { n: limits.max }) }}</span></template>
              <span v-else>{{ $t('Counting...') }}</span>
            </div>
            <p v-if="layout === 'a4_duplex'" class="lg-note lg-note-amber"><icons name="info" class="w-4 h-4" /><span>{{ $t('Print double sided, flip on the long edge, at 100% (actual size).') }}</span></p>
          </section>
          </div>

          <section v-if="batch" class="lg-card pcn-batch">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ batch.reference }}</h2><p class="lg-card-sub">{{ batch.cards }} {{ $t('cards') }} · {{ batch.status }}</p></div></header>
            <div class="pcn-bar"><i :style="{ width: batch.progress + '%' }"></i></div>
            <p v-if="batch.status === 'Failed'" class="ac-err">{{ batch.error }}</p>
            <div v-if="batch.status === 'Done'" class="pcn-files"><a v-for="p in batch.parts" :key="p" :href="route('id-passes.batch.download', [batch.id]) + '?part=' + p" :target="batch.action === 'print' ? '_blank' : null" class="lg-btn tone" style="--tone: #4f46e5"><span class="tone-ico"><icons :name="batch.action === 'print' ? 'printer' : 'download'" class="w-4 h-4" /></span>{{ batch.parts > 1 ? $t('Part :n', { n: p }) : (batch.action === 'print' ? $t('Open for printing') : $t('Download')) }}</a></div>
          </section>

          <section v-if="batches.length" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recent batches') }}</h2></div></header>
            <ul class="pcn-recent"><li v-for="b in batches" :key="b.id"><b>{{ b.reference }}</b><span>{{ b.cards }} {{ $t('cards') }} · {{ b.status }}</span><small>{{ b.by }} · {{ $datetime(b.at) }}</small>
              <span v-if="b.status === 'Done'" class="pcn-files"><a v-for="p in b.parts" :key="p" :href="route('id-passes.batch.download', [b.id]) + '?part=' + p" :target="b.action === 'print' ? '_blank' : null" class="lg-chip lg-chip-blue">{{ b.parts > 1 ? $t('Part :n', { n: p }) : $t('Open') }}</a></span></li></ul>
          </section>
        </aside>
      </div>

      <div class="pcn-dock-spacer" aria-hidden="true"></div>
      <transition name="pcn-dock-t" appear>
        <div class="pcn-dock" :class="{ 'is-empty': !pv || !pv.cards }" role="region" :aria-label="$t('Generate')">
          <div v-if="pv && pv.reprints > 0" class="pcn-dock-why">
            <span class="pcn-lab">{{ $t(':n of these IDs were printed before. Why are they printed again?', { n: pv.reprints }) }} <b class="wims-req">*</b></span>
            <div class="pcn-why-row"><div class="pcn-seg pcn-reasons"><button v-for="r in reasons" :key="r" type="button" :class="{ 'is-on': reason === r }" @click="reason = r">{{ r }}</button></div>
              <input v-model="note" class="pcn-note" maxlength="150" :placeholder="$t('More about it (optional)')" /></div>
          </div>
          <div class="pcn-dock-info">
            <span class="pcn-dock-ico"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0"><b :key="pv ? pv.cards : 0" class="pcn-pop">{{ pv ? pv.cards : 0 }} {{ $t('cards') }}</b><small>{{ pv && pv.cards ? sumLine : $t('Tick IDs to make sheets.') }}</small></div>
          </div>
          <span v-if="error" class="pcn-dock-err">{{ error }}</span>
          <button type="button" class="lg-btn tone tone-solid pcn-go" :disabled="working || !pv || !pv.cards || pv.over || (pv.reprints > 0 && !reason)" @click="generate" style="--tone: #4f46e5"><span class="tone-ico"><icons :name="working ? 'refresh' : 'printer'" class="w-4 h-4" :class="{ 'animate-spin': working }" /></span>{{ working ? $t('Working...') : (action === 'pdf' ? $t('Generate PDF') : $t('Generate sheets')) }}</button>
        </div>
      </transition>
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
import SheetPreview, { sheetGrid } from './Parts/SheetPreview.vue';
import { idMakeStart, idMakeProgress, idMakeDone, idMakeFail } from '@/Core/idMake';

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect, CategoryChip, IdMakeOverlay, SheetPreview },
  props: { kinds: Array, categories: Array, statuses: Array, batches: Array, can: Object, limits: Object },
  data() {
    return { f: { kind: '', category_id: '', status: 'Active', q: '', issued_from: '', issued_to: '', expires_to: '', printed: '' }, rows: [], total: 0, page: 1, last: 1, selected: [], mode: 'filter', action: this.can.print ? 'print' : 'pdf', layout: 'a4_side', orientation: 'landscape', pv: null, working: false, busy: false, error: '', batch: null, reason: '', note: '', timer: null, pvTimer: null, poll: null, spread: 0, faces: {}, faceCss: '', meta: {}, stale: false, faceTimer: null, faceBusy: {} };
  },
  computed: {
    catOptions() { return this.categories.filter(c => !this.f.kind || c.kind === this.f.kind).map(c => ({ value: c.value, label: c.label })); },
    statusOptions() { return this.statuses.map(s => ({ value: s.value, label: s.label })); },
    printedOptions() { return [{ value: 'no', label: this.$t('Never printed') }, { value: 'yes', label: this.$t('Printed before') }]; },
    allOnPage() { const a = this.rows.filter(r => r.status === 'Active'); return a.length > 0 && a.every(r => this.selected.includes(r.id)); },
    landscape() { return this.layout !== 'a4_duplex' && this.orientation !== 'portrait'; },
    perSheet() { const g = sheetGrid(this.layout, this.landscape); return this.layout === 'card' ? 6 : g.cols * g.rows; },
    // portrait paper is drawn side by side (two sheets in a row), landscape paper one under the other
    sideBySide() { return this.layout === 'a4_duplex' || (this.layout === 'a4_side' && !this.landscape); },
    // the cards drawn at one time: one sheet of landscape paper, two sheets of portrait paper side by side, the fronts and backs of one lot of double sided, four card pairs
    drawCap() { return this.layout === 'card' ? 4 : this.layout === 'a4_duplex' ? this.perSheet : this.landscape ? this.perSheet : this.perSheet * 2; },
    currentLayout() { return this.layoutOptions.find(o => o.value === this.layout) || this.layoutOptions[0]; },
    examples() { return this.rows.filter(r => r.status === 'Active').slice(0, 4).map(r => r.id); },
    // what the sheet preview draws: the ticked cards, or the first cards of the list as an example
    shown() {
      if (this.mode === 'ids' && this.selected.length) {
        const cap = this.drawCap, n = this.selected.length, sp = Math.min(this.spread, Math.ceil(n / cap) - 1);
        return { example: false, ids: this.selected.slice(sp * cap, sp * cap + cap), spreads: Math.ceil(n / cap), from: sp * cap + 1, to: Math.min(n, sp * cap + cap) };
      }
      return { example: true, ids: [], spreads: 1, from: 0, to: 0 };
    },
    thumbIds() { return (this.mode === 'ids' && this.selected.length ? this.selected : this.examples).slice(0, 4); },
    sumLine() {
      const pv = this.pv; if (!pv) return '';
      if (this.layout === 'card') return pv.pages + ' ' + this.$t('pages of 55 x 85 mm');
      return pv.sheets + ' ' + this.$t('A4 sheets') + ' · ' + pv.per_sheet + ' ' + (this.layout === 'a4_duplex' ? this.$t('cards per sheet') : this.$t('pairs per sheet'));
    },
    reasons() { return [this.$t('Lost'), this.$t('Broken or damaged'), this.$t('Details changed'), this.$t('Other')]; },
    layoutOptions() { return [{ value: 'a4_side', short: this.$t('Front + back on A4'), help: this.$t('A4: front and back side by side. Pairs with cutting marks, real size.') }, { value: 'a4_duplex', short: this.$t('A4 double sided'), help: this.$t('A4: double sided. Sheets of fronts, then sheets of backs lined up behind them.') }, { value: 'card', short: this.$t('Card size PDF'), help: this.$t('Card size PDF: every card as two pages of 55 x 85 mm: front, back, next card.') }]; },
  },
  watch: {
    mode() { this.instant(); this.queuePreview(); },
    selected: { deep: true, handler() { this.followSpread(); this.instant(); if (this.mode === 'ids') this.queuePreview(); this.wantFaces(); } },
    action() { this.load(1); }, layout() { this.spread = 0; this.followSpread(true); this.instant(); this.queuePreview(); }, orientation() { this.spread = 0; this.followSpread(true); this.instant(); this.queuePreview(); },
    examples() { this.wantFaces(); },
  },
  mounted() { this.load(1); },
  beforeUnmount() { clearInterval(this.poll); },
  methods: {
    statusChip,
    debounce() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(1), 350); },
    cleanFilters() { const o = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) o[k] = this.f[k]; }); if (!o.status) o.status = 'Active'; return o; },
    async load(page) {
      this.busy = true; this.page = page;
      try { const { data } = await window.axios.get(route('id-passes.print-centre.cards'), { params: { ...this.cleanFilters(), page, action: this.action } }); this.rows = data.data; this.total = data.total; this.last = data.last; this.rows.forEach(r => { if (!this.meta[r.id]) this.remember(r); }); } catch (e) { this.rows = []; }
      this.busy = false; this.queuePreview();
    },
    // the sheet preview shows the lot the newest tick belongs to, and never an empty one
    followSpread(keep) {
      const n = this.selected.length, cap = this.drawCap, was = this._len || 0;
      // one tick: the lot of that card; many at once (a whole page): the lot of the first of them, so the cards that were added are seen flying in
      if (!keep && n > was) this.spread = n - was > 1 ? Math.floor(was / cap) : Math.floor((n - 1) / cap);
      this.spread = Math.max(0, Math.min(this.spread, Math.ceil(n / cap) - 1));
      this._len = n;
    },
    remember(r) { this.meta[r.id] = { number: r.number, name: r.name, color: r.color, ink: r.ink }; },
    toggle(r) { if (r.status !== 'Active') return; this.remember(r); const i = this.selected.indexOf(r.id); if (i >= 0) this.selected.splice(i, 1); else this.selected.push(r.id); if (this.selected.length) this.mode = 'ids'; },
    togglePage() { const act = this.rows.filter(r => r.status === 'Active'); act.forEach(this.remember); const ids = act.map(r => r.id); if (this.allOnPage) this.selected = this.selected.filter(i => !ids.includes(i)); else this.selected = [...new Set([...this.selected, ...ids])]; if (this.selected.length) this.mode = 'ids'; },
    // where a card's row is on the screen (the ID flies from there onto the sheet and back); nothing when the row is not in view
    locate(id) {
      const el = document.querySelector('tr[data-row="' + id + '"] td'); if (!el) return null;
      const b = el.getBoundingClientRect(); if (b.bottom < 0 || b.top > window.innerHeight) return null;
      return { x: b.left + 30, y: b.top + b.height / 2, w: 60 };
    },
    // the numbers on the right answer the tick at once; the server's count follows
    instant() {
      if (!this.pv) return;
      const n = this.mode === 'ids' ? this.selected.length : this.pv.cards, g = sheetGrid(this.layout, this.landscape), per = g.cols * g.rows;
      this.pv = { ...this.pv, cards: n, skipped: this.mode === 'ids' ? 0 : this.pv.skipped, per_sheet: this.layout === 'card' ? null : per, pages: this.layout === 'card' ? n * 2 : null, sheets: !n ? 0 : this.layout === 'card' ? n * 2 : Math.ceil(n / per) * (this.layout === 'a4_duplex' ? 2 : 1), parts: Math.ceil(Math.max(1, n) / this.limits.part), over: n > this.limits.max, background: n > this.limits.sync };
      this.stale = true;
    },
    // the real faces of the cards that are drawn on the sheets (fetched once, a few at a time)
    wantFaces() { clearTimeout(this.faceTimer); this.faceTimer = setTimeout(this.fetchFaces, 90); },
    async fetchFaces() {
      const need = [...new Set([...this.shown.ids, ...this.thumbIds])].filter(id => !this.faces[id] && !this.faceBusy[id]).slice(0, 40);
      if (!need.length) return;
      need.forEach(id => { this.faceBusy[id] = true; });
      try {
        const { data } = await window.axios.get(route('id-passes.print-centre.faces'), { params: { ids: need, action: this.action } });
        this.faceCss = data.css || this.faceCss; this.faces = { ...this.faces, ...data.cards };
      } catch (e) { /* the placeholders stay */ }
      need.forEach(id => { delete this.faceBusy[id]; });
    },
    body() { return { action: this.action, mode: this.mode, ids: this.selected, filters: this.cleanFilters(), layout: this.layout, orientation: this.orientation, reason: this.reason ? this.reason + (this.note ? ': ' + this.note : '') : undefined }; },
    queuePreview() { clearTimeout(this.pvTimer); this.pvTimer = setTimeout(this.preview, 250); },
    async preview() {
      if (this.mode === 'ids' && !this.selected.length) { this.pv = { cards: 0, skipped: 0, sheets: 0, pages: 0, parts: 0, per_sheet: 0, reprints: 0 }; this.stale = false; return; }
      try { const { data } = await window.axios.post(route('id-passes.batch.preview'), this.body()); this.pv = data; this.stale = false; } catch (e) { this.pv = null; this.error = (e.response && e.response.data && e.response.data.message) || ''; }
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
@media (min-width: 1100px) { .pcn-grid { grid-template-columns: minmax(0, 1fr) 420px; } .pcn-stick { position: sticky; top: 84px; } .pcn-right { align-self: stretch; } }
.pcn-left { display: grid; gap: 14px; min-width: 0; } .pcn-right { display: grid; gap: 14px; align-content: start; min-width: 0; }
.pcn-field { display: grid; gap: 6px; margin-bottom: 12px; } .pcn-lab { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint, #94a3b8); }
.pcn-note { width: 100%; box-sizing: border-box; }
.pcn-kinds { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; } .pcn-kinds button { border: 0; padding: 8px 16px; border-radius: 999px; font-weight: 800; font-size: 0.82rem; cursor: pointer; background: var(--lg-soft, #f1f5f9); color: var(--lg-ink, #0f172a); transition: all 0.2s; }
.pcn-kinds button.is-on { background: var(--c); color: #fff; box-shadow: 0 10px 18px -10px var(--c); }
.pcn-filters { grid-template-columns: repeat(auto-fit, minmax(min(150px, 100%), 1fr)); }
.pcn-mode { display: flex; gap: 22px; flex-wrap: wrap; margin-top: 10px; padding-top: 12px; border-top: 1px solid var(--lg-line, #e2e8f0); font-weight: 700; } .pcn-mode label { display: flex; gap: 6px; align-items: center; cursor: pointer; }
.pcn-chk { width: 38px; } tr.is-sel td { background: rgba(21, 101, 192, 0.07); }
.pcn-row td:first-child { box-shadow: inset 5px 0 0 var(--c); }
.pcn-pages { display: flex; gap: 12px; align-items: center; justify-content: center; }
.pcn-seg { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); } .pcn-seg button { border: 0; padding: 7px 14px; border-radius: 999px; background: transparent; font-weight: 700; font-size: 0.82rem; color: var(--lg-mute, #64748b); cursor: pointer; } .pcn-seg button.is-on { background: #fff; color: #1e4467; box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); }
.pcn-opts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.pcn-opt { display: grid; grid-template-rows: auto 1fr; gap: 8px; align-items: start; justify-items: center; text-align: center; width: 100%; padding: 8px; border: 0; border-radius: 16px; cursor: pointer; background: var(--lg-card, #fff); color: inherit; font: inherit; box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); transition: box-shadow 0.2s, transform 0.2s, background 0.2s; }
.pcn-opt:hover { transform: scale(1.03); box-shadow: 0 0 0 1px #93b4e3, 0 14px 24px -16px rgba(21, 101, 192, 0.55); }
.pcn-opt.is-on { box-shadow: 0 0 0 2px #1565c0, 0 14px 24px -16px rgba(21, 101, 192, 0.6); background: rgba(21, 101, 192, 0.05); }
.pcn-opt-th { display: block; width: 100%; box-sizing: border-box; padding: 5px; border-radius: 10px; background: var(--lg-soft, #f1f5f9); }
.pcn-opt-tx b { font-size: 0.74rem; line-height: 1.2; display: block; overflow-wrap: anywhere; }
.pcn-sum { transition: opacity 0.2s; } .pcn-sum.is-stale { opacity: 0.75; }
.pcn-dock { position: sticky; bottom: 14px; z-index: 40; margin-top: 18px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 12px 14px 12px 18px; border-radius: 22px; background: color-mix(in srgb, var(--lg-card, #fff) 90%, transparent); backdrop-filter: blur(14px) saturate(1.4); -webkit-backdrop-filter: blur(14px) saturate(1.4); box-shadow: 0 0 0 1px rgba(15, 37, 64, 0.1), 0 24px 44px -18px rgba(15, 37, 64, 0.55); }
.pcn-dock-info { display: flex; align-items: center; gap: 12px; flex: 1 1 220px; min-width: 0; } .pcn-dock-info > div { display: grid; min-width: 0; } .pcn-dock-info b { font-size: 1.05rem; color: #1e4467; } .pcn-dock-info small { color: var(--lg-mute, #64748b); font-weight: 600; overflow-wrap: anywhere; }
.pcn-dock-ico { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 14px; background: rgba(79, 70, 229, 0.12); color: #4f46e5; flex: 0 0 auto; }
.pcn-dock.is-empty .pcn-dock-ico { background: var(--lg-soft, #f1f5f9); color: #94a3b8; }
.pcn-dock-note { display: inline-flex; gap: 6px; align-items: center; border: 0; padding: 8px 12px; border-radius: 999px; background: rgba(217, 119, 6, 0.14); color: #92400e; font-weight: 800; font-size: 0.8rem; cursor: pointer; }
.pcn-dock-err { color: #be123c; font-weight: 700; font-size: 0.82rem; flex: 1 1 100%; }
.pcn-dock-spacer { display: none; } @media (max-width: 767px) { .pcn-dock-spacer { display: block; } }
.pcn-dock-t-enter-active { transition: transform 0.5s cubic-bezier(.2,.8,.2,1), opacity 0.4s; } .pcn-dock-t-enter-from { transform: translateY(40px); opacity: 0; }
@media (max-width: 640px) { .pcn-dock .pcn-go { width: 100%; } .pcn-dock-why .pcn-seg { gap: 2px; padding: 3px; } .pcn-dock-why .pcn-seg button { padding: 5px 9px; font-size: 0.74rem; } .pcn-dock-info { flex-basis: 100%; } }
/* on a phone the page itself does not scroll inside its frame, so the bar is fixed to the screen instead of sticking */
@media (max-width: 767px) { .pcn-dock { position: fixed; left: 10px; right: 10px; bottom: 74px; margin: 0; padding: 10px 12px; max-height: 60vh; overflow: auto; } .pcn-dock-spacer { height: 150px; } }
@media (prefers-reduced-motion: reduce) { .pcn-pop, .pcn-more, .pcn-dock-t-enter-active { animation: none; transition: none; } }
.pcn-sum { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 10px; padding: 10px 14px; border-radius: 16px; background: rgba(21, 101, 192, 0.08); margin: 10px 0 0; font-size: 0.84rem; } .pcn-sum b { font-size: 1.15rem; color: #1e4467; } .pcn-warn { color: #b45309; font-weight: 700; } .pcn-bad { color: #be123c; font-weight: 800; } .pcn-sum.is-bad { background: rgba(190, 18, 60, 0.07); }
.pcn-again { margin: 10px 0; } .pcn-reasons { flex-wrap: wrap; border-radius: 18px; }
.pcn-go { justify-content: center; flex: 0 0 auto; min-width: 190px; }
.pcn-bar { height: 8px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); overflow: hidden; margin-bottom: 12px; } .pcn-bar i { display: block; height: 100%; background: linear-gradient(90deg, #1565c0, #34d399); transition: width 0.4s; }
.pcn-files { display: flex; flex-wrap: wrap; gap: 8px; }
.pcn-recent { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; } .pcn-recent li { display: grid; gap: 2px; padding-bottom: 10px; border-bottom: 1px solid var(--lg-line, #e2e8f0); } .pcn-recent small { color: var(--lg-mute, #64748b); }
.idl-no { letter-spacing: 0.04em; color: #1e4467; }
</style>
