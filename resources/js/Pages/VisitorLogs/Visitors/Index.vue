<template>
  <admin-layout :title="$t('Visitor Directory')">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="users" :title="$t('Visitor Directory')" :subtitle="$t('The people who visit. A returning visitor is chosen from here instead of being typed again.')">
        <button v-if="can.create" type="button" class="lg-btn lg-btn-primary" @click="addOpen = true"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Add Visitor') }}</span></button>
        <Link v-if="can.newVisit" :href="route('visitor-logs.visits.create')" class="lg-btn lg-btn-soft"><icons name="in" class="w-4 h-4" /><span>{{ $t('New Visitor Log') }}</span></Link>
        <Link :href="route('visitor-logs.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
      </page-header>

      <sc-tabs :tabs="tabs" :model-value="f.state || ''" @update:model-value="setState" />

      <section class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Name, company, phone, email or reference')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="f.category" :options="categories.map(c => ({ value: c, label: c }))" :placeholder="$t('Any')" clearable /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n visitor(s) found', { n: rows.total !== undefined ? rows.total : rows.data.length }) }}</span></div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Visitor') }}</th><th>{{ $t('Company') }}</th><th>{{ $t('Phone') }}</th><th>{{ $t('Category') }}</th><th v-if="can.history" class="tr-nw">{{ $t('Visits') }}</th><th v-if="can.history" class="tr-nw">{{ $t('Last visit') }}</th><th>{{ $t('Status') }}</th><th class="lg-sticky-end"></th></tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('visitor-logs.visitors.show', r.id))">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Visitor')" class="tr-wrap"><div class="tr-cell"><b>{{ r.name }}</b><small>{{ r.reference }}</small></div></td>
              <td :data-label="$t('Company')" class="tr-wrap">{{ r.company || '-' }}</td>
              <td :data-label="$t('Phone')" class="tr-nw">{{ r.phone || '-' }}</td>
              <td :data-label="$t('Category')">{{ r.category || '-' }}</td>
              <td v-if="can.history" :data-label="$t('Visits')" class="tr-nw">{{ r.visits }}</td>
              <td v-if="can.history" :data-label="$t('Last visit')" class="tr-nw">{{ r.last_visit_at ? $datetime(r.last_visit_at) : '-' }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="r.is_active ? 'lg-chip-green' : 'lg-chip-slate'" style="text-transform: none">{{ r.is_active ? $t('Active') : $t('Inactive') }}</span></td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop>
                <span class="tr-acts">
                  <Link v-if="can.newVisit && r.is_active" :href="route('visitor-logs.visits.create', { visitor_id: r.id })" class="lg-btn lg-btn-primary vl-go"><icons name="in" class="w-4 h-4" /><span>{{ $t('New Visit') }}</span></Link>
                  <Link :href="route('visitor-logs.visitors.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></Link>
                </span>
              </td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="9">{{ hasFilters ? $t('No visitor matches these filters.') : $t('No visitors yet. Add the first one.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
    </div>
    <visitor-form-modal :show="addOpen" :categories="categories" :fields="fields" :sensitive="can.sensitive" :require-phone="requirePhone" @close="addOpen = false" @saved="saved" />
  </admin-layout>
</template>

<script>
import ScTabs from '@/Pages/Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';
import VisitorFormModal from '../Parts/VisitorFormModal.vue';

export default {
  components: { ScTabs, AdminLayout, PageHeader, Link, AcctSelect, Pager, VisitorFormModal },
  props: { rows: Object, filters: Object, categories: Array, fields: Array, stats: Object, can: Object, requirePhone: Boolean },
  data() { return { f: { ...this.filters }, busy: false, timer: null, addOpen: false }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    tabs() { const s = this.stats; return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.total }, { key: 'active', label: this.$t('Active'), icon: 'tick', tone: 'emerald', count: s.active }, { key: 'inactive', label: this.$t('Inactive'), icon: 'x-circle', tone: 'slate', count: s.inactive }]; },
    hasFilters() { return ['search', 'category', 'state'].some(k => this.f[k]); },
  },
  methods: {
    queue() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('visitor-logs.visitors.index'), q, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) }); },
    setState(s) { this.f = { ...this.f, state: this.f.state === s && s ? '' : s }; },
    reset() { this.f = {}; },
    saved(v) { this.addOpen = false; this.$inertia.visit(route('visitor-logs.visitors.show', v.id)); },
  },
};
</script>

<style scoped>
.vl-go { min-height: 34px; padding: 0 12px; white-space: nowrap; }
</style>
