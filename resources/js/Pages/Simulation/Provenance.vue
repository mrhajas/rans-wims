<template>
  <admin-layout :title="$t('Imported And Synthetic Records')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="list" :title="$t('Imported And Synthetic Records')" :subtitle="$t('Which records were copied from production and left as they were, which were copied and then changed, and which were made in the simulation. No change here ever reaches production.')" />
      <section class="lg-card">
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Kind of record') }}</th><th class="ac-num">{{ $t('All') }}</th><th class="ac-num">{{ $t('Imported, unchanged') }}</th><th class="ac-num">{{ $t('Imported, then changed') }}</th><th class="ac-num">{{ $t('Made in the simulation') }}</th><th></th></tr></thead>
          <tbody><tr v-for="m in modules" :key="m.key">
            <td :data-label="$t('Kind of record')" class="lg-main">{{ m.label }}</td><td class="ac-num">{{ m.total }}</td>
            <td class="ac-num"><span class="lg-chip lg-chip-blue">{{ m.imported }}</span></td><td class="ac-num"><span class="lg-chip lg-chip-amber">{{ m.modified }}</span></td><td class="ac-num"><span class="lg-chip lg-chip-violet">{{ m.synthetic }}</span></td>
            <td class="ac-actions" style="margin: 0; justify-content: flex-end"><button type="button" class="lg-btn t-teal" @click="open(m)"><icons name="list" class="w-4 h-4" />{{ $t('Records') }}</button></td></tr></tbody>
        </table></div>
      </section>

      <section v-if="sel" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ sel.label }}</h2></div>
          <select v-model="state" @change="load(1)"><option value="">{{ $t('All states') }}</option><option value="imported">{{ $t('Imported, unchanged') }}</option><option value="modified">{{ $t('Imported, then changed') }}</option><option value="synthetic">{{ $t('Made in the simulation') }}</option><option value="removed">{{ $t('Imported, then removed') }}</option></select></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Record') }}</th><th>{{ $t('State') }}</th><th>{{ $t('Production id') }}</th><th></th></tr></thead>
          <tbody><tr v-for="r in rows" :key="r.id"><td :data-label="$t('Record')" class="lg-main">{{ r.title }}</td><td :data-label="$t('State')"><span class="lg-chip" :class="chip(r.state)">{{ label(r.state) }}</span></td><td :data-label="$t('Production id')">{{ r.source_id || '-' }}</td>
            <td class="ac-actions" style="margin: 0; justify-content: flex-end"><button v-if="r.state === 'modified' || r.state === 'imported'" type="button" class="lg-btn t-violet" @click="compare(r)"><icons name="adjustments" class="w-4 h-4" />{{ $t('Compare with the import') }}</button></td></tr>
            <tr v-if="!rows.length"><td colspan="4" style="text-align: center">{{ $t('No records.') }}</td></tr></tbody></table></div>
        <div class="sim-pager"><span class="lg-subtext">{{ total }} {{ $t('records') }}</span><span><button type="button" class="lg-btn t-slate" :disabled="page <= 1" @click="load(page - 1)">{{ $t('Previous') }}</button> <button type="button" class="lg-btn t-slate" :disabled="page * 25 >= total" @click="load(page + 1)">{{ $t('Next') }}</button></span></div>
      </section>

      <section v-if="cmp" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ cmp.title }}</h2><p class="lg-card-sub">{{ $t('Imported from production record') }} #{{ cmp.detail.source_id }} {{ $t('on') }} {{ cmp.detail.imported_at }}</p></div><button type="button" class="lg-btn t-slate" @click="cmp = null"><icons name="cross" class="w-4 h-4" />{{ $t('Close') }}</button></header>
        <p v-if="cmp.detail.removed" class="lg-note lg-note-red">{{ $t('This record was removed from the simulation after it was imported.') }}</p>
        <div v-else-if="!cmp.detail.changes.length" class="lg-note lg-note-green"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Unchanged since it was imported.') }}</span></div>
        <div v-else class="lg-table-wrap"><table class="lg-table"><thead><tr><th>{{ $t('Field') }}</th><th>{{ $t('As imported') }}</th><th>{{ $t('Now') }}</th></tr></thead><tbody><tr v-for="c in cmp.detail.changes" :key="c.field"><td class="lg-main">{{ c.field }}</td><td>{{ c.was === null ? '-' : c.was }}</td><td><b>{{ c.now === null ? '-' : c.now }}</b></td></tr></tbody></table></div>
        <form v-if="can.restore && cmp.detail.changes.length" class="ac-reason" @submit.prevent="restore"><span>{{ $t('Put it back as imported?') }}</span><input v-model="pw" type="password" :placeholder="$t('Your password')" required /><input v-model="word" :placeholder="$t('Type RESTORE')" required /><button type="submit" class="lg-btn lg-btn-red"><icons name="refresh" class="w-4 h-4" />{{ $t('Restore') }}</button></form>
        <div v-if="error" class="lg-note lg-note-red" role="alert">{{ error }}</div>
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
  props: { modules: Array, can: Object },
  data() { return { sel: null, state: '', rows: [], total: 0, page: 1, cmp: null, pw: '', word: '', error: '' }; },
  methods: {
    chip(s) { return { imported: 'lg-chip-blue', modified: 'lg-chip-amber', synthetic: 'lg-chip-violet', removed: 'lg-chip-red' }[s] || 'lg-chip-slate'; },
    label(s) { return { imported: this.$t('Imported'), modified: this.$t('Modified copy'), synthetic: this.$t('Synthetic'), removed: this.$t('Removed') }[s] || s; },
    open(m) { this.sel = m; this.state = ''; this.cmp = null; this.load(1); },
    async load(p) { this.page = p; const r = await axios.get(route('simulation.provenance.records'), { params: { module: this.sel.key, state: this.state || undefined, page: p } }); this.rows = r.data.rows; this.total = r.data.total; },
    async compare(r) { const x = await axios.get(route('simulation.provenance.compare'), { params: { module: this.sel.key, id: r.id } }); this.cmp = { title: r.title, id: r.id, ...x.data }; this.pw = ''; this.word = ''; this.error = ''; },
    async restore() { try { await axios.post(route('simulation.provenance.restore'), { module: this.sel.key, id: this.cmp.id, password: this.pw, confirm: this.word }); this.cmp = null; this.load(this.page); this.$inertia.reload({ only: ['modules'], preserveScroll: true }); } catch (e) { const d = e.response && e.response.data; this.error = d && d.errors ? Object.values(d.errors).flat().join(' ') : this.$t('Something went wrong.'); } },
  },
};
</script>

<style scoped>
.sim-pager { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; gap: 10px; flex-wrap: wrap; }
</style>
