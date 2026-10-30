<template>
  <admin-layout :title="$t('Scenarios And Checkpoints')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="collection" :title="$t('Scenarios And Checkpoints')" :subtitle="$t('A scenario is a way things could go. You work in one at a time; a checkpoint keeps a copy of it you can come back to. Nothing here can touch production.')" />

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="plus" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('New scenario') }}</h2><p class="lg-card-sub">{{ $t('It starts as a copy of what is open now.') }}</p></div></header>
        <form class="sim-form" @submit.prevent="create">
          <div class="ac-f"><label>{{ $t('Name') }} <b class="wims-req">*</b></label><input v-model="nf.name" :placeholder="$t('For example: Budget Revision')" /><span class="ac-err">{{ nf.errors.name }}</span></div>
          <div class="ac-f sim-grow"><label>{{ $t('What is it for?') }}</label><input v-model="nf.description" /></div>
          <div class="ac-f"><label>{{ $t('Start from') }}</label><css-select v-model="nf.from"><option :value="null">{{ $t('What is open now') }}</option><option v-for="s in scenarios.filter(x => !x.current && x.checkpoints.length)" :key="s.id" :value="s.id">{{ s.name }}</option></css-select></div>
          <div class="ac-f"><label>&nbsp;</label><button type="submit" class="lg-btn lg-btn-primary" :disabled="nf.processing"><icons name="plus" class="w-4 h-4" />{{ $t('Create') }}</button></div>
        </form>
      </section>

      <section v-for="s in scenarios" :key="s.id" class="lg-card" :style="s.current ? 'box-shadow: 0 0 0 2px #6258ff' : ''">
        <header class="lg-card-head"><span class="lg-card-ico" :class="s.current ? 'lg-ico-violet' : 'lg-ico-slate'"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ s.name }} <span v-if="s.current" class="lg-chip lg-chip-blue">{{ $t('Open now') }}</span> <span v-if="s.status === 'archived'" class="lg-chip lg-chip-slate">{{ $t('Archived') }}</span></h2><p class="lg-card-sub">{{ s.description }} <small class="lg-subtext">{{ s.created_by }} {{ s.created_at }}</small></p></div>
          <div class="ac-actions" style="margin: 0">
            <button v-if="!s.current && s.status === 'active' && s.checkpoints.length" type="button" class="lg-btn lg-btn-primary" @click="act('switch', s)"><icons name="refresh" class="w-4 h-4" />{{ $t('Open this scenario') }}</button>
            <button v-if="s.current" type="button" class="lg-btn t-teal" @click="ask(s, 'checkpoint')"><icons name="tick" class="w-4 h-4" />{{ $t('Save a checkpoint') }}</button>
            <button v-if="s.current" type="button" class="lg-btn t-violet" @click="ask(s, 'baseline')"><icons name="clip" class="w-4 h-4" />{{ $t('Set as baseline') }}</button>
            <button v-if="s.current" type="button" class="lg-btn t-amber" @click="ask(s, 'reset')"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset to baseline') }}</button>
            <button v-if="!s.current" type="button" class="lg-btn t-slate" @click="post(route('simulation.scenarios.archive', s.id))"><icons name="folder" class="w-4 h-4" />{{ s.status === 'archived' ? $t('Unarchive') : $t('Archive') }}</button>
            <button v-if="!s.current" type="button" class="lg-btn t-red" @click="ask(s, 'delete')"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button>
          </div></header>
        <form v-if="asking && asking.s.id === s.id" class="ac-reason" @submit.prevent="send">
          <input v-if="asking.kind === 'checkpoint'" v-model="name" :placeholder="$t('Name of the checkpoint')" required />
          <template v-else><span>{{ asking.kind === 'baseline' ? $t('The current state becomes the baseline of this scenario.') : asking.kind === 'reset' ? $t('Everything in this scenario goes back to its baseline (the present state is saved first).') : asking.kind === 'restore' ? $t('Go back to this checkpoint (the present state is saved first).') : $t('This permanently deletes the scenario and its checkpoints. Production is not touched.') }}</span>
            <input v-model="pw" type="password" :placeholder="$t('Your password')" required /><input v-model="word" :placeholder="$t('Type') + ' ' + word0" required /></template>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="asking = null"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
        </form>
        <ul class="sim-cps"><li v-for="c in s.checkpoints" :key="c.id"><span class="lg-chip" :class="c.kind === 'baseline' ? 'lg-chip-violet' : c.kind === 'auto' ? 'lg-chip-slate' : 'lg-chip-blue'">{{ c.kind }}</span> <b>{{ c.name }}</b> <small class="lg-subtext">{{ c.created_at }} {{ c.by }}</small>
          <span class="sim-cp-act"><button v-if="s.current" type="button" class="lg-btn t-amber" @click="ask(s, 'restore', c)"><icons name="refresh" class="w-4 h-4" />{{ $t('Restore') }}</button> <button type="button" class="lg-btn t-teal" @click="pick(c)"><icons name="chart-bar" class="w-4 h-4" />{{ cmpA === c.id ? $t('Chosen as A') : cmpB === c.id ? $t('Chosen as B') : $t('Compare') }}</button> <button v-if="c.kind !== 'baseline'" type="button" class="lg-btn t-red" @click="ask(s, 'cpdelete', c)"><icons name="trash" class="w-4 h-4" /></button></span></li>
          <li v-if="!s.checkpoints.length" class="lg-subtext">{{ $t('No checkpoints yet.') }}</li></ul>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="chart-bar" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Compare') }}</h2><p class="lg-card-sub">{{ $t('Pick two checkpoints with Compare, or compare one with what is open now.') }}</p></div>
          <div class="ac-actions" style="margin: 0"><button type="button" class="lg-btn t-teal" :disabled="cmpA === null" @click="runCompare"><icons name="chart-bar" class="w-4 h-4" />{{ cmpB === null ? $t('Compare with what is open now') : $t('Compare the two') }}</button> <button type="button" class="lg-btn t-slate" @click="cmpA = null; cmpB = null; figs = null">{{ $t('Clear') }}</button></div></header>
        <div v-if="figs" class="lg-table-wrap"><table class="lg-table"><thead><tr><th>{{ $t('Figure') }}</th><th class="ac-num">A</th><th class="ac-num">{{ cmpB === null ? $t('Open now') : 'B' }}</th><th class="ac-num">{{ $t('Difference') }}</th></tr></thead>
          <tbody><tr v-for="r in rowsOf" :key="r.k"><td class="lg-main">{{ r.k }}</td><td class="ac-num">{{ r.a }}</td><td class="ac-num">{{ r.b }}</td><td class="ac-num" :class="{ 'ac-neg': r.d < 0 }"><b>{{ r.d > 0 ? '+' : '' }}{{ r.d }}</b></td></tr></tbody></table></div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader },
  props: { scenarios: Array, current: Number },
  data() { return { nf: this.$inertia.form({ name: '', description: '', from: null }), asking: null, name: '', pw: '', word: '', busy: false, cmpA: null, cmpB: null, figs: null }; },
  computed: {
    word0() { return { reset: 'RESET', baseline: 'BASELINE', restore: 'RESTORE', delete: 'DELETE', cpdelete: 'DELETE' }[this.asking && this.asking.kind] || ''; },
    rowsOf() {
      const out = []; const f = this.figs; if (!f) return out;
      Object.keys(f.a.counts).forEach(k => out.push({ k, a: f.a.counts[k] ?? '-', b: f.b.counts[k] ?? '-', d: (f.b.counts[k] || 0) - (f.a.counts[k] || 0) }));
      Object.keys(f.a.money).forEach(k => { const cur = new Set([...Object.keys(f.a.money[k] && typeof f.a.money[k] === 'object' ? f.a.money[k] : {}), ...Object.keys(f.b.money[k] && typeof f.b.money[k] === 'object' ? f.b.money[k] : {})]); if (cur.size) { cur.forEach(c => out.push({ k: k + ' (' + c + ')', a: this.fmt(f.a.money[k][c]), b: this.fmt(f.b.money[k][c]), d: +((Number(f.b.money[k][c]) || 0) - (Number(f.a.money[k][c]) || 0)).toFixed(2) })); } else { out.push({ k, a: this.fmt(f.a.money[k]), b: this.fmt(f.b.money[k]), d: +((Number(f.b.money[k]) || 0) - (Number(f.a.money[k]) || 0)).toFixed(2) }); } });
      return out;
    },
  },
  methods: {
    fmt(v) { return v === null || v === undefined ? '-' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 }); },
    create() { this.nf.post(route('simulation.scenarios.store'), { preserveScroll: true, onSuccess: () => this.nf.reset() }); },
    post(url) { this.$inertia.post(url, {}, { preserveScroll: true }); },
    act(a, s) { this.$inertia.post(route('simulation.scenarios.' + a, s.id), {}, { preserveScroll: true }); },
    ask(s, kind, c) { this.asking = { s, kind, c }; this.name = ''; this.pw = ''; this.word = ''; },
    send() {
      const a = this.asking; this.busy = true; const done = { preserveScroll: true, onFinish: () => (this.busy = false), onSuccess: () => (this.asking = null) };
      const auth = { password: this.pw, confirm: this.word };
      if (a.kind === 'checkpoint') this.$inertia.post(route('simulation.scenarios.checkpoint', a.s.id), { name: this.name }, done);
      else if (a.kind === 'baseline') this.$inertia.post(route('simulation.scenarios.baseline'), auth, done);
      else if (a.kind === 'reset') this.$inertia.post(route('simulation.scenarios.reset'), auth, done);
      else if (a.kind === 'restore') this.$inertia.post(route('simulation.scenarios.restore', a.c.id), auth, done);
      else if (a.kind === 'cpdelete') this.$inertia.delete(route('simulation.scenarios.cp-delete', a.c.id), { ...done, data: auth });
      else this.$inertia.delete(route('simulation.scenarios.destroy', a.s.id), { ...done, data: auth });
    },
    pick(c) { if (this.cmpA === null) this.cmpA = c.id; else if (this.cmpB === null && c.id !== this.cmpA) this.cmpB = c.id; else { this.cmpA = c.id; this.cmpB = null; } this.figs = null; },
    async runCompare() { const r = await axios.get(route('simulation.scenarios.compare'), { params: { a: this.cmpA, b: this.cmpB || 0 } }); this.figs = r.data; },
  },
};
</script>

<style scoped>
.sim-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; } .sim-grow { flex: 1 1 260px; }
.sim-cps { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 6px; } .sim-cps li { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 6px 10px; border-radius: 12px; background: rgba(148, 163, 184, 0.1); } .sim-cp-act { margin-left: auto; display: flex; gap: 6px; }
</style>
