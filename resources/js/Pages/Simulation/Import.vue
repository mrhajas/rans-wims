<template>
  <admin-layout :title="$t('Import Centre')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="upload" :title="$t('Import Centre')" :subtitle="$t('Copy chosen production records into the simulation as independent records. Production is only read, never changed; what you import here can be edited freely without touching the original.')" />
      <sc-tabs :tabs="tabs" v-model="tab" />

      <template v-if="tab === 'records'">
        <section class="lg-card">
          <div class="sim-bar">
            <div class="ac-f"><label>{{ $t('What to import') }}</label><css-select v-model="module" @change="reset"><option v-for="m in modules" :key="m.key" :value="m.key">{{ m.label }}</option></css-select></div>
            <div class="ac-f sim-grow"><label>{{ $t('Search production') }}</label><input v-model="q" type="search" :placeholder="$t('Name, reference or words')" @input="debounce" /></div>
            <div v-if="current && current.filter" class="ac-f"><label>{{ current.filter.label }}</label><css-select v-model="filter" @change="load(1)"><option value="">{{ $t('Any') }}</option><option v-for="f in found.filters" :key="f" :value="f">{{ f }}</option></css-select></div>
          </div>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive">
            <thead><tr><th style="width: 40px"><input type="checkbox" :checked="allOnPage" :disabled="!found.rows.length" @change="togglePage($event.target.checked)" :aria-label="$t('Select all on this page')" /></th><th>{{ $t('Record') }}</th><th>{{ $t('Details') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="r in found.rows" :key="r.id">
                <td><input type="checkbox" :checked="picked.includes(r.id)" @change="toggle(r.id, $event.target.checked)" /></td>
                <td :data-label="$t('Record')" class="lg-main">{{ r.title }} <span v-if="r.imported" class="lg-chip lg-chip-green">{{ $t('Already in the simulation') }}</span></td>
                <td :data-label="$t('Details')"><span v-for="(s, i) in r.sub" :key="i" class="lg-chip lg-chip-slate" style="margin-right: 4px">{{ s }}</span></td>
                <td class="ac-actions" style="margin: 0; justify-content: flex-end"><button type="button" class="lg-btn t-teal" @click="showPreview(r)"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Preview') }}</button></td>
              </tr>
              <tr v-if="!found.rows.length && !loading"><td colspan="4" style="text-align: center">{{ $t('No production records match.') }}</td></tr>
            </tbody>
          </table></div>
          <div class="sim-pager"><span class="lg-subtext">{{ found.total }} {{ $t('records') }}</span><span><button type="button" class="lg-btn t-slate" :disabled="found.page <= 1" @click="load(found.page - 1)"><icons name="chevron-left" class="w-4 h-4" />{{ $t('Previous') }}</button> <button type="button" class="lg-btn t-slate" :disabled="found.page * found.per >= found.total" @click="load(found.page + 1)">{{ $t('Next') }}<icons name="chevron-right" class="w-4 h-4" /></button></span></div>
        </section>

        <div v-if="previewing" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="eye-flat" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ previewing.title }}</h2><p class="lg-card-sub">{{ $t('A look at the production record. Personal details are masked when imported.') }}</p></div><button type="button" class="lg-btn t-slate" @click="previewing = null"><icons name="cross" class="w-4 h-4" />{{ $t('Close') }}</button></header>
          <dl class="ac-kv"><div v-for="f in previewing.facts" :key="f.key"><dt>{{ f.key }}</dt><dd>{{ f.value }}</dd></div></dl>
          <p v-for="k in previewing.children" :key="k.label" class="lg-sub">{{ k.label }}: <b>{{ k.count }}</b></p>
        </div>

        <section class="sim-act">
          <span><b>{{ picked.length }}</b> {{ $t('selected') }}<template v-if="picked.length"> <a href="#" @click.prevent="picked = []">{{ $t('clear') }}</a></template></span>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!picked.length || busy" @click="makePlan"><icons name="list" class="w-4 h-4" />{{ $t('Review what will be imported') }}</button>
        </section>

        <section v-if="plan" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('What this import brings in') }}</h2><p class="lg-card-sub">{{ plan.total }} {{ $t('records in total. Nothing is saved until you press Import.') }}</p></div></header>
          <div v-if="plan.blocked.length" class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ plan.blocked.join(' ') }}</span></div>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Kind of record') }}</th><th class="ac-num">{{ $t('Chosen') }}</th><th class="ac-num">{{ $t('Needed by them') }}</th><th class="ac-num">{{ $t('Related, ticked') }}</th><th class="ac-num">{{ $t('Parts of them') }}</th></tr></thead>
            <tbody><tr v-for="t in plan.tables" :key="t.table"><td :data-label="$t('Kind of record')" class="lg-main">{{ t.label }}</td><td class="ac-num">{{ t.selected }}</td><td class="ac-num">{{ t.required }}</td><td class="ac-num">{{ t.optional }}</td><td class="ac-num">{{ t.child }}</td></tr></tbody></table></div>
          <div v-if="plan.duplicates.length" class="lg-note lg-note-blue" style="margin-top: 8px"><icons name="info" class="w-4 h-4" /><span>{{ $t('Already in the simulation (reused, not copied again)') }}: <span v-for="d in plan.duplicates" :key="d.table"><b>{{ d.label }}</b> {{ d.count }}; </span></span></div>
          <div v-if="plan.optional.length" style="margin-top: 10px"><b>{{ $t('Related records you may also bring in') }}</b><p class="lg-sub">{{ $t('They are not needed for the import to work. If you leave one out, the link to it is simply empty in the copy.') }}</p>
            <label v-for="o in plan.optional" :key="o.table" class="ac-check"><input type="checkbox" v-model="opt.optional[o.table]" @change="makePlan" /><span>{{ o.label }} <small class="lg-subtext">({{ o.count }})</small></span></label></div>
          <div v-if="plan.children.length" style="margin-top: 10px"><b>{{ $t('Parts that belong to them') }}</b>
            <label v-for="c in plan.children" :key="c.table" class="ac-check"><input type="checkbox" v-model="opt.children[c.table]" @change="makePlan" /><span>{{ c.label }} <small class="lg-subtext">({{ c.count }} {{ $t('in') }} {{ c.of }})</small></span></label></div>
          <div style="margin-top: 10px"><b>{{ $t('Options') }}</b>
            <label class="ac-check"><input type="checkbox" v-model="opt.mask" /><span>{{ $t('Mask personal details (phone, email, bank and identity numbers)') }}</span></label>
            <label class="ac-check"><input type="checkbox" v-model="opt.files" /><span>{{ $t('Also copy the attached documents (kept in the simulation only)') }}</span></label></div>
          <div v-if="plan.dropped.length" class="lg-sub" style="margin-top: 8px">{{ $t('Links that cannot be carried over (left empty)') }}: {{ plan.dropped.join(', ') }}</div>
          <div v-if="plan.warnings.length" class="lg-note lg-note-amber" style="margin-top: 8px"><icons name="exclamation" class="w-4 h-4" /><span>{{ plan.warnings.join(' ') }}</span></div>
          <div class="sim-act"><span class="lg-subtext">{{ $t('The whole import is saved together or not at all.') }}</span><button v-if="can.run" type="button" class="lg-btn lg-btn-green" :disabled="busy || plan.blocked.length || !plan.total" @click="run"><icons name="upload" class="w-4 h-4" />{{ $t('Import') }} {{ plan.total }} {{ $t('records') }}</button></div>
        </section>
        <div v-if="result" class="lg-note lg-note-green" role="status"><icons name="tick" class="w-4 h-4" /><span><b>{{ result.reference }}</b>: {{ result.summary.total }} {{ $t('records imported') }}<template v-if="result.summary.files && result.summary.files.files"> ({{ result.summary.files.files }} {{ $t('documents') }})</template>. <Link :href="route('simulation.provenance.index')">{{ $t('See them') }}</Link></span></div>
        <div v-if="error" class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ error }}</span></div>
      </template>

      <section v-else-if="tab === 'file'" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Spreadsheet') }}</h2><p class="lg-card-sub">{{ $t('Excel or CSV of planned or fictional records. Every row is checked first; nothing is saved unless all rows are fine.') }}</p></div></header>
        <div class="sim-bar">
          <div class="ac-f"><label>{{ $t('Kind of record') }}</label><css-select v-model="kind"><option v-for="k in kinds" :key="k.key" :value="k.key">{{ k.label }}</option></css-select></div>
          <div class="ac-f sim-grow"><label>{{ $t('File') }} (.xlsx, .csv)</label><input type="file" accept=".xlsx,.csv,.txt" @change="file = $event.target.files[0]; fileResult = null" /></div>
        </div>
        <p class="lg-sub">{{ $t('First row names the columns') }}: <b>{{ (kinds.find(k => k.key === kind) || { columns: [] }).columns.join(', ') }}</b>. <a :href="route('simulation.import.template', kind)">{{ $t('Download a template') }}</a></p>
        <div class="sim-act"><span></span><span><button type="button" class="lg-btn t-teal" :disabled="!file || busy" @click="checkFile"><icons name="tick" class="w-4 h-4" />{{ $t('Check the file') }}</button> <button v-if="can.run" type="button" class="lg-btn lg-btn-green" :disabled="!fileResult || fileResult.error_count || busy" @click="runFile"><icons name="upload" class="w-4 h-4" />{{ $t('Import the file') }}</button></span></div>
        <div v-if="fileResult" :class="fileResult.error_count ? 'lg-note lg-note-red' : 'lg-note lg-note-green'"><icons name="info" class="w-4 h-4" /><span><b>{{ fileResult.rows }}</b> {{ $t('rows') }}<template v-if="fileResult.error_count">, <b>{{ fileResult.error_count }}</b> {{ $t('problems') }}:</template><template v-else>. {{ $t('Everything looks fine.') }}</template><ul v-if="fileResult.errors.length"><li v-for="(e, i) in fileResult.errors" :key="i">{{ e }}</li></ul></span></div>
        <div v-if="fileDone" class="lg-note lg-note-green"><icons name="tick" class="w-4 h-4" /><span>{{ fileDone }}</span></div>
        <div v-if="error" class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ error }}</span></div>
      </section>

      <section v-else class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Earlier imports') }}</h2></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Batch') }}</th><th>{{ $t('Kind') }}</th><th>{{ $t('Result') }}</th><th class="ac-num">{{ $t('Records') }}</th><th>{{ $t('When') }}</th><th></th></tr></thead>
          <tbody><tr v-for="b in batches" :key="b.id"><td :data-label="$t('Batch')" class="lg-main">{{ b.reference }}</td><td :data-label="$t('Kind')">{{ b.module }}</td><td :data-label="$t('Result')"><span class="lg-chip" :class="b.status === 'done' ? 'lg-chip-green' : b.status === 'failed' ? 'lg-chip-red' : 'lg-chip-amber'">{{ b.status }}</span><small v-if="b.status === 'failed' && b.problems" class="lg-subtext"> {{ b.problems[0] }}</small></td><td class="ac-num">{{ b.summary ? b.summary.total : '-' }}</td><td :data-label="$t('When')">{{ b.finished_at || b.started_at }}</td>
            <td class="ac-actions" style="margin: 0; justify-content: flex-end"><button v-if="can.run && b.status !== 'running' && b.module in moduleKeys" type="button" class="lg-btn t-amber" :disabled="busy" @click="retry(b)"><icons name="refresh" class="w-4 h-4" />{{ $t('Run again') }}</button></td></tr>
            <tr v-if="!batches.length"><td colspan="6" style="text-align: center">{{ $t('Nothing imported yet.') }}</td></tr></tbody></table></div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';

export default {
  components: { AdminLayout, PageHeader, ScTabs, Link },
  props: { modules: Array, batches: Array, kinds: Array, can: Object, limit: Number },
  data() {
    return { tab: 'records', module: this.modules[0].key, q: '', filter: '', found: { rows: [], total: 0, page: 1, per: 25, filters: [] }, loading: false, picked: [], previewing: null, plan: null, opt: { optional: {}, children: {}, files: false, mask: true }, busy: false, result: null, error: '', kind: this.kinds[0] ? this.kinds[0].key : '', file: null, fileResult: null, fileDone: '', timer: null };
  },
  computed: {
    tabs() { return [{ key: 'records', label: this.$t('Production records'), icon: 'collection', tone: 'sky' }, { key: 'file', label: this.$t('Spreadsheet'), icon: 'doc-text', tone: 'teal' }, { key: 'batches', label: this.$t('Earlier imports'), icon: 'clock', tone: 'violet' }]; },
    current() { return this.modules.find(m => m.key === this.module); },
    moduleKeys() { return Object.fromEntries(this.modules.map(m => [m.key, 1])); },
    allOnPage() { return this.found.rows.length > 0 && this.found.rows.every(r => this.picked.includes(r.id)); },
  },
  mounted() { this.load(1); },
  methods: {
    reset() { this.q = ''; this.filter = ''; this.picked = []; this.plan = null; this.result = null; this.previewing = null; this.opt = { optional: {}, children: {}, files: false, mask: true }; this.load(1); },
    debounce() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(1), 350); },
    async load(page) { this.loading = true; try { const r = await axios.get(route('simulation.import.search'), { params: { module: this.module, q: this.q, filter: this.filter, page } }); this.found = r.data; } catch (e) { this.error = this.msg(e); } this.loading = false; },
    toggle(id, on) { this.picked = on ? [...new Set([...this.picked, id])] : this.picked.filter(x => x !== id); this.plan = null; },
    togglePage(on) { const ids = this.found.rows.map(r => r.id); this.picked = on ? [...new Set([...this.picked, ...ids])] : this.picked.filter(x => !ids.includes(x)); this.plan = null; },
    async showPreview(r) { try { const x = await axios.get(route('simulation.import.preview'), { params: { module: this.module, id: r.id } }); this.previewing = { title: r.title, ...x.data }; } catch (e) { this.error = this.msg(e); } },
    body() { return { module: this.module, ids: this.picked, optional: this.opt.optional, children: this.opt.children, files: this.opt.files, mask: this.opt.mask }; },
    msg(e) { const d = e.response && e.response.data; if (d && d.errors) return Object.values(d.errors).flat().join(' '); return (d && d.message) || this.$t('Something went wrong.'); },
    async makePlan() { this.busy = true; this.error = ''; this.result = null; try { const r = await axios.post(route('simulation.import.plan'), this.body()); this.plan = r.data; r.data.optional.forEach(o => { if (!(o.table in this.opt.optional)) this.opt.optional[o.table] = o.included; }); r.data.children.forEach(c => { if (!(c.table in this.opt.children)) this.opt.children[c.table] = c.included; }); } catch (e) { this.error = this.msg(e); this.plan = null; } this.busy = false; },
    async run() { this.busy = true; this.error = ''; try { const r = await axios.post(route('simulation.import.run'), this.body()); this.result = r.data; this.plan = null; this.picked = []; this.load(this.found.page); this.$inertia.reload({ only: ['batches'], preserveState: true, preserveScroll: true }); } catch (e) { this.error = this.msg(e); } this.busy = false; },
    async retry(b) { this.busy = true; this.error = ''; try { await axios.post(route('simulation.import.retry', b.id)); this.$inertia.reload({ only: ['batches'], preserveScroll: true }); } catch (e) { this.error = this.msg(e); } this.busy = false; },
    form() { const f = new FormData(); f.append('kind', this.kind); f.append('file', this.file); return f; },
    async checkFile() { this.busy = true; this.error = ''; this.fileDone = ''; try { const r = await axios.post(route('simulation.import.file-check'), this.form()); this.fileResult = r.data; } catch (e) { this.error = this.msg(e); this.fileResult = null; } this.busy = false; },
    async runFile() { this.busy = true; this.error = ''; try { const r = await axios.post(route('simulation.import.file-run'), this.form()); this.fileDone = r.data.count + ' ' + this.$t('records saved.'); this.fileResult = null; this.file = null; this.$inertia.reload({ only: ['batches'], preserveScroll: true }); } catch (e) { this.error = this.msg(e); } this.busy = false; },
  },
};
</script>

<style scoped>
.sim-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 10px; } .sim-grow { flex: 1 1 240px; }
.sim-pager { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; gap: 10px; flex-wrap: wrap; }
.sim-act { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin: 12px 0; }
</style>
