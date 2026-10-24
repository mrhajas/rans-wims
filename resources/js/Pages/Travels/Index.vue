<template>
  <admin-layout :title="$t('Travels')">
    <div class="lg-page ac sc-ui tr-ui px-4 md:px-0">
      <page-header icon="plane" :title="$t('Travels')" :subtitle="$t('The travels of staff and users: where they go, the flight, the ticket and the visa, and what each cost. Every travel needs approval before the ticket agent is owed for it.')">
        <Link v-if="can.create" :href="route('travels.create')" class="lg-btn tone tone-solid" style="--tone: #059669"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span><span>{{ $t('New Travel Request') }}</span></Link>
        <Link v-if="can.packages" :href="route('travels.packages.index')" class="lg-btn tone" style="--tone: #7c3aed"><span class="tone-ico"><icons name="collection" class="w-4 h-4" /></span><span>{{ $t('Travel Packages') }}</span></Link>
        <Link v-if="can.agents" :href="route('travels.agents.index')" class="lg-btn lg-btn-soft"><icons name="cash" class="w-4 h-4" /><span>{{ $t('Ticket Agents') }}</span></Link>
        <Link :href="route('travels.reports.index')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
        <travel-export type="register" :params="exportParams" />
      </page-header>

      <div class="tr-stats">
        <button type="button" class="tr-stat" :class="{ 'is-on': f.when === 'upcoming' }" @click="toggleUpcoming"><icons name="plane" class="w-5 h-5" /><span><b>{{ stats.upcoming }}</b>{{ $t('Upcoming') }}</span></button>
        <button type="button" class="tr-stat is-amber" :class="{ 'is-on': f.status === 'Travel Request' }" @click="setStatus('Travel Request')"><icons name="clock" class="w-5 h-5" /><span><b>{{ stats.pending }}</b>{{ $t('Travel Requests') }}</span></button>
        <button type="button" class="tr-stat is-green" :class="{ 'is-on': f.status === 'Approved' }" @click="setStatus('Approved')"><icons name="tick" class="w-5 h-5" /><span><b>{{ stats.approved }}</b>{{ $t('Approved') }}</span></button>
      </div>

      <div v-if="totals.length" class="tr-totals">
        <div v-for="m in totals" :key="m.currency" class="tr-tot"><small>{{ $t('Total amount') }} {{ m.currency }}</small><b class="tr-tm">{{ m.currency }} {{ moneyFmt(m.total) }}</b><span><em style="font-style: normal">{{ $t('Ticket and visa') }} <i class="tr-tm">{{ m.currency }} {{ moneyFmt(m.travel) }}</i></em><em style="font-style: normal">{{ $t('Other costs') }} <i class="tr-tm">{{ m.currency }} {{ moneyFmt(m.items) }}</i></em></span></div>
      </div>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />

      <section class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Reference, traveller, destination, country, flight, ticket number or agent')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('Ticket agent') }}</label><acct-select v-model="f.agent_id" :options="agents" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Local / international') }}</label><acct-select v-model="f.scope" :options="scopes" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Country') }}</label><acct-select v-model="f.country" :options="countries.map(c => ({ value: c, label: c }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Departs from') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('Departs to') }}</label><date-picker v-model="f.to" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n travel(s) found', { n: rows.total !== undefined ? rows.total : rows.data.length }) }}</span></div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table tr-wide tr-cards">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Travel') }}</th><th>{{ $t('Traveller(s)') }}</th><th>{{ $t('Trip') }}</th><th class="tr-nw">{{ $t('Dates') }}</th><th>{{ $t('Flight') }}</th><th>{{ $t('Issued by') }}</th><th class="tr-num">{{ $t('Exchange rate agreed') }}</th><th class="tr-num">{{ $t('Amount') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('travels.show', r.id))">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Travel')" class="tr-head"><span class="tr-ci tc-b"><icons name="plane" class="w-4 h-4" /></span><div class="tr-cell"><b class="lg-main">{{ r.reference }}</b><div class="tr-line"><span class="lg-chip" :class="statusChip(r.status)" style="text-transform: none">{{ r.status_label }}</span></div><small v-if="r.days_left !== null && ['Travel Request', 'Approved'].includes(r.status)" class="tr-days" :class="{ 'is-soon': r.days_left <= 7 }">{{ r.days_left === 0 ? $t('leaves today') : $t('leaves in :n day(s)', { n: r.days_left }) }}</small></div></td>
              <td :data-label="$t('Traveller(s)')" class="tr-wrap"><div class="tr-names"><span v-for="p in r.travellers" :key="p.id"><b>{{ p.name }}</b><small v-if="p.ticket_number" class="lg-subtext"> · {{ p.ticket_number }}</small></span></div></td>
              <td :data-label="$t('Trip')" class="tr-wrap"><div class="tr-cell"><b>{{ r.to_place }}<template v-if="r.to_country">, {{ r.to_country }}</template></b><small>{{ $t('from') }} {{ [r.from_place, r.from_country].filter(Boolean).join(', ') || '-' }}</small><div class="tr-line"><span class="lg-chip" :class="r.scope === 'international' ? 'lg-chip-violet' : 'lg-chip-slate'" style="text-transform: none">{{ r.scope_label }}</span><small v-if="r.travel_type">{{ r.travel_type }}</small></div></div></td>
              <td :data-label="$t('Dates')" class="tr-nw"><div class="tr-cell"><span>{{ $t('Departs') }} <b>{{ $date(r.depart_date) }}</b></span><span v-if="r.trip_type === 'return'">{{ $t('Returns') }} <b>{{ r.return_date ? $date(r.return_date) : '-' }}</b></span><small v-else>{{ $t('One way') }}</small><small>{{ $t('Booked') }} {{ r.booking_date ? $date(r.booking_date) : '-' }}</small></div></td>
              <td :data-label="$t('Flight')" class="tr-wrap"><div class="tr-cell"><b>{{ [r.airline, r.flight_number].filter(Boolean).join(' ') || '-' }}</b><small v-if="r.return_flight_number">{{ $t('Return') }}: {{ [r.return_airline || r.airline, r.return_flight_number].filter(Boolean).join(' ') }}</small><small v-if="r.booking_reference">PNR {{ r.booking_reference }}</small></div></td>
              <td :data-label="$t('Issued by')" class="tr-wrap"><div class="tr-cell"><span><small>{{ $t('Ticket') }}</small> {{ r.ticket_agent || '-' }}</span><span v-if="r.has_visa"><small>{{ $t('Visa') }}</small> {{ r.visa_agent || '-' }}</span></div></td>
              <td :data-label="$t('Exchange rate agreed')" class="tr-num"><div class="tr-money"><b v-if="r.currency !== 'TZS'" class="tr-o">1 {{ r.currency }} = TZS {{ rate(r.exchange_rate) }}</b><small v-else>{{ $t('TZS (no rate)') }}</small></div></td>
              <td :data-label="$t('Amount')" class="tr-num"><div class="tr-money"><b class="tr-tm">{{ r.currency }} {{ money(r.total_amount) }}</b><small class="tr-m">{{ $t('Ticket') }} {{ money(r.ticket_amount) }}</small><small v-if="r.has_visa" class="tr-m">{{ $t('Visa') }} {{ money(r.visa_amount) }}</small><small v-if="r.items.length" class="tr-o">{{ $t('Other costs') }} TZS {{ money(r.items_total_tzs) }}</small></div></td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop><span class="tr-acts"><a v-if="can.print" :href="route('travels.print', r.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a><a v-if="can.print" :href="route('travels.pdf', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a></span></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="10">{{ hasFilters ? $t('No travel matches these filters.') : $t('There is no data to display.') }}</td></tr>
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
  props: { rows: Object, totals: { type: Array, default: () => [] }, filters: Object, statuses: Array, agents: Array, currencies: Array, countries: Array, scopes: Array, stats: Object, can: Object },
  data() { return { f: { ...this.filters }, busy: false, timer: null }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: {
    tabs() {
      const s = this.stats;
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.total }, { key: 'Travel Request', label: this.$t('Waiting'), icon: 'clock', tone: 'amber', count: s.pending }, { key: 'Approved', label: this.$t('Approved'), icon: 'tick', tone: 'emerald', count: s.approved },
        { key: 'Rejected', label: this.$t('Rejected'), icon: 'x-circle', tone: 'rose', count: s.rejected }, { key: 'Cancelled', label: this.$t('Cancelled'), icon: 'x-circle', tone: 'slate', count: s.cancelled }];
    },
    hasFilters() { return Object.keys(this.f).some(k => this.f[k]); },
    // what is on the screen is what is printed: the same search, status, agent, scope, country, departure and dates
    exportParams() { return { search: this.f.search, status: this.f.status, contact_id: this.f.agent_id, scope: this.f.scope, country: this.f.country, when: this.f.when, from: this.f.from, to: this.f.to, currency: this.f.currency }; },
  },
  methods: {
    moneyFmt(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    rate(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 6 }); },
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    statusChip(s) { return { 'Travel Request': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    // the list follows every change by itself: typing waits a moment so it does not search on every key
    queue() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('travels.index'), q, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) }); },
    setStatus(s) { this.f = { ...this.f, status: this.f.status === s && s ? '' : s }; },
    toggleUpcoming() { this.f = { ...this.f, when: this.f.when === 'upcoming' ? '' : 'upcoming' }; },
    reset() { this.f = {}; },
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
.tr-days { color: var(--sc-mute, #64748b); font-weight: 700; } .tr-days.is-soon { color: #b45309; }
</style>
