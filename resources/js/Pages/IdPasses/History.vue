<template>
  <admin-layout :title="$t('ID Print History')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="clock" :title="$t('ID Print History')" :subtitle="$t('Every print and PDF export of an ID: what WIMS made, for whom, by whom and when.')">
        <Link :href="route('id-passes.print-centre.index')" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print Centre') }}</span></Link>
        <a :href="route('id-passes.reports.print', { type: 'prints' })" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Print report') }}</span></a>
      </page-header>
      <p class="lg-note lg-note-blue" style="margin-bottom: 14px"><icons name="info" class="w-4 h-4" /><span>{{ $t('"Print initiated" means the PDF was made and opened for printing. WIMS cannot see the printer, so it never says paper came out.') }}</span></p>
      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters hs-filters">
          <div class="ac-f"><label>{{ $t('ID number or name') }}</label><input v-model="f.q" type="search" /></div>
          <div class="ac-f"><label>{{ $t('By') }}</label><acct-select v-model="f.user_id" :options="users" :placeholder="$t('Anyone')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Action') }}</label><acct-select v-model="f.action" :options="actions" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Layout') }}</label><acct-select v-model="f.layout" :options="layouts" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Batch') }}</label><input v-model="f.batch" placeholder="IDB-0001" /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f hs-act"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn t-slate" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
        </div>
      </form>
      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr><th>{{ $t('When') }}</th><th>{{ $t('ID number') }}</th><th>{{ $t('Name') }}</th><th>{{ $t('Action') }}</th><th>{{ $t('Layout') }}</th><th>{{ $t('Copies') }}</th><th>{{ $t('Result') }}</th><th>{{ $t('By') }}</th><th>{{ $t('Batch') }}</th></tr></thead>
        <tbody><tr v-for="r in rows.data" :key="r.id"><td :data-label="$t('When')">{{ $datetime(r.at) }}</td><td :data-label="$t('ID number')"><b>{{ r.number }}</b><div class="lg-subtext">{{ r.category }}</div></td><td :data-label="$t('Name')">{{ r.name }}</td>
          <td :data-label="$t('Action')">{{ actionText(r.action) }}</td><td :data-label="$t('Layout')">{{ layoutText(r.layout) }}</td><td :data-label="$t('Copies')">{{ r.copies }}</td><td :data-label="$t('Result')"><span class="lg-chip" :class="r.outcome === 'Failed' ? 'lg-chip-red' : 'lg-chip-green'">{{ r.outcome === 'Initiated' ? $t('Print initiated') : r.outcome === 'Generated' ? $t('PDF generated') : $t('Failed') }}</span></td>
          <td :data-label="$t('By')">{{ r.by }}</td><td :data-label="$t('Batch')">{{ r.batch || '-' }}</td></tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="9">{{ $t('Nothing has been printed yet.') }}</td></tr></tbody></table></div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager },
  props: { rows: Object, filters: Object, users: Array, actions: Array, layouts: Array },
  data() { return { f: { ...this.filters } }; },
  methods: {
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('id-passes.print-centre.history'), q, { preserveState: true }); },
    reset() { this.f = {}; this.go(); },
    actionText(a) { return (this.actions.find(x => x.value === a) || {}).label || a; },
    layoutText(l) { return (this.layouts.find(x => x.value === l) || {}).label || l; },
  },
};
</script>

<style scoped>
@media (min-width: 1100px) { .hs-filters { grid-template-columns: repeat(7, minmax(0, 1fr)) auto; } }
.hs-act > div { display: flex; gap: 8px; }
</style>
