<template>
  <admin-layout :title="$t('Travels')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="plane" :title="$t('Travels')" :subtitle="$t('The travels of staff and users: where they go, the flight, the ticket and the visa, and what each cost. Every travel needs approval before the ticket agent is owed for it.')">
        <Link v-if="can.create" :href="route('travels.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Travel') }}</span></Link>
        <Link v-if="can.agents" :href="route('travels.agents.index')" class="lg-btn lg-btn-soft"><icons name="cash" class="w-4 h-4" /><span>{{ $t('Ticket Agents') }}</span></Link>
        <Link :href="route('travels.reports.index')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
        <travel-export type="register" :params="exportParams" />
      </page-header>

      <div class="tr-stats">
        <button type="button" class="tr-stat" :class="{ 'is-on': f.when === 'upcoming' }" @click="toggleUpcoming"><icons name="plane" class="w-5 h-5" /><span><b>{{ stats.upcoming }}</b>{{ $t('Upcoming') }}</span></button>
        <button type="button" class="tr-stat is-amber" :class="{ 'is-on': f.status === 'Pending Approval' }" @click="setStatus('Pending Approval')"><icons name="clock" class="w-5 h-5" /><span><b>{{ stats.pending }}</b>{{ $t('Waiting for approval') }}</span></button>
        <button type="button" class="tr-stat is-green" :class="{ 'is-on': f.status === 'Approved' }" @click="setStatus('Approved')"><icons name="tick" class="w-5 h-5" /><span><b>{{ stats.approved }}</b>{{ $t('Approved') }}</span></button>
      </div>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />

      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters tr-filters">
          <div class="ac-f tr-search"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Reference, traveller, destination, country, flight, ticket number or agent')" /></div>
          <div class="ac-f"><label>{{ $t('Ticket agent') }}</label><acct-select v-model="f.agent_id" :options="agents" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Local / international') }}</label><acct-select v-model="f.scope" :options="scopes" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Country') }}</label><acct-select v-model="f.country" :options="countries.map(c => ({ value: c, label: c }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Departs from') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('Departs to') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f tr-actions"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn t-slate" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
        </div>
      </form>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Travel') }}</th><th>{{ $t('Traveller(s)') }}</th><th>{{ $t('Trip') }}</th><th>{{ $t('Flight') }}</th><th>{{ $t('Ticket agent') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('travels.show', r.id))">
              <td :data-label="$t('Travel')"><span class="lg-main">{{ r.reference }}</span><div class="lg-subtext">{{ $date(r.depart_date) }}<template v-if="r.days_left !== null"> · <b class="tr-days" :class="{ 'is-soon': r.days_left <= 7 }">{{ r.days_left === 0 ? $t('today') : $t('in :n day(s)', { n: r.days_left }) }}</b></template></div></td>
              <td :data-label="$t('Traveller(s)')" class="tr-people"><div v-for="p in r.travellers.slice(0, 3)" :key="p.id">{{ p.name }}</div><div v-if="r.travellers.length > 3" class="lg-subtext">+ {{ r.travellers.length - 3 }} {{ $t('more') }}</div></td>
              <td :data-label="$t('Trip')"><b>{{ r.to_place }}</b><div class="lg-subtext">{{ [r.from_place, r.to_country].filter(Boolean).join(' → ') }}<span class="lg-chip tr-scope" :class="r.scope === 'international' ? 'lg-chip-violet' : 'lg-chip-slate'">{{ r.scope_label }}</span></div></td>
              <td :data-label="$t('Flight')">{{ [r.airline, r.flight_number].filter(Boolean).join(' ') || '-' }}<div v-if="r.has_visa" class="lg-subtext">{{ $t('Visa included') }}</div></td>
              <td :data-label="$t('Ticket agent')">{{ r.ticket_agent || '-' }}<div v-if="r.has_visa && r.visa_agent && r.visa_agent !== r.ticket_agent" class="lg-subtext">{{ $t('Visa') }}: {{ r.visa_agent }}</div></td>
              <td :data-label="$t('Amount')" class="ac-num"><b class="amt">{{ r.currency }} {{ money(r.total_amount) }}</b></td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status)">{{ r.status_label }}</span></td>
              <td class="lg-sticky-end" @click.stop><a v-if="can.print" :href="route('travels.print', r.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a><a v-if="can.print" :href="route('travels.pdf', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import TravelExport from './TravelExport.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';

export default {
  components: { TravelExport, ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager },
  props: { rows: Object, filters: Object, statuses: Array, agents: Array, currencies: Array, countries: Array, scopes: Array, stats: Object, can: Object },
  data() { return { f: { ...this.filters } }; },
  computed: {
    tabs() {
      const s = this.stats;
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.total }, { key: 'Pending Approval', label: this.$t('Waiting'), icon: 'clock', tone: 'amber', count: s.pending }, { key: 'Approved', label: this.$t('Approved'), icon: 'tick', tone: 'emerald', count: s.approved },
        { key: 'Rejected', label: this.$t('Rejected'), icon: 'x-circle', tone: 'rose', count: s.rejected }, { key: 'Cancelled', label: this.$t('Cancelled'), icon: 'x-circle', tone: 'slate', count: s.cancelled }];
    },
    exportParams() { return { search: this.f.search, status: this.f.status, contact_id: this.f.agent_id, scope: this.f.scope, country: this.f.country, from: this.f.from, to: this.f.to, currency: this.f.currency }; },
  },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    statusChip(s) { return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('travels.index'), q, { preserveState: true }); },
    setStatus(s) { this.f.status = this.f.status === s && s ? '' : s; this.go(); },
    toggleUpcoming() { this.f.when = this.f.when === 'upcoming' ? '' : 'upcoming'; this.go(); },
    reset() { this.f = {}; this.go(); },
  },
};
</script>

<style scoped>
.tr-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
.tr-stat { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; border: 0; text-align: left; cursor: pointer; background: var(--sc-card, #fff); color: var(--sc-ink, #0f2540); box-shadow: 0 0 0 1px var(--sc-line, #e3e9f2), 0 10px 16px -14px rgba(31, 70, 130, 0.55); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.tr-stat:hover { transform: translateY(-2px); }
.tr-stat > svg { flex: none; color: #0e7490; } .tr-stat.is-amber > svg { color: #b45309; } .tr-stat.is-green > svg { color: #0d8a5a; }
.tr-stat > span { display: grid; font-size: 0.78rem; font-weight: 700; color: var(--sc-mute, #64748b); } .tr-stat b { font-size: 1.3rem; color: var(--sc-ink, #0f2540); line-height: 1.1; }
.tr-stat.is-on { box-shadow: 0 0 0 2px #0e7490, 0 14px 22px -14px rgba(14, 116, 144, 0.6); }
@media (max-width: 640px) { .tr-stats { grid-template-columns: 1fr; } }
.tr-filters .tr-actions > div { display: flex; gap: 8px; }
@media (min-width: 1100px) { .tr-filters { grid-template-columns: minmax(0, 1.7fr) repeat(5, minmax(0, 1fr)) auto; } }
@media (max-width: 1099px) { .tr-filters .tr-search { grid-column: 1 / -1; } }
.tr-days { color: var(--sc-mute, #64748b); } .tr-days.is-soon { color: #b45309; }
.tr-scope { margin-left: 6px; text-transform: none; }
.tr-people > div { white-space: nowrap; }
</style>
