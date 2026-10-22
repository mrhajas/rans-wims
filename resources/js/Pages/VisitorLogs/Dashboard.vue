<template>
  <admin-layout :title="$t('Visitor Dashboard')">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="users" :title="$t('Visitor Dashboard')" :subtitle="location ? $t('Reception: :l', { l: location }) : $t('Who is on the premises, and what is waiting at reception.')">
        <Link v-if="can.create" :href="route('visitor-logs.visits.create')" class="lg-btn lg-btn-primary"><icons name="in" class="w-4 h-4" /><span>{{ $t('New Visitor Log') }}</span></Link>
        <Link v-if="can.createItem" :href="route('visitor-logs.items.create')" class="lg-btn lg-btn-soft"><icons name="email" class="w-4 h-4" /><span>{{ $t('Register an Item') }}</span></Link>
        <Link v-if="can.visits" :href="route('visitor-logs.visits.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Visitor Register') }}</span></Link>
        <Link v-if="can.directory" :href="route('visitor-logs.visitors.index')" class="lg-btn lg-btn-soft"><icons name="users" class="w-4 h-4" /><span>{{ $t('Directory') }}</span></Link>
        <Link v-if="can.items" :href="route('visitor-logs.items.index')" class="lg-btn lg-btn-soft"><icons name="email" class="w-4 h-4" /><span>{{ $t('Incoming Mail') }}</span></Link>
        <Link v-if="can.reports" :href="route('visitor-logs.reports.index')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
      </page-header>

      <div class="vl-stats">
        <template v-if="can.visits">
          <Link :href="route('visitor-logs.visits.index', { status: 'Checked In' })" class="vl-stat is-green"><icons name="in" class="w-5 h-5" /><span><b>{{ stats.on_premises }}</b>{{ $t('On premises now') }}</span></Link>
          <Link :href="route('visitor-logs.visits.index', { from: today, to: today })" class="vl-stat"><icons name="users" class="w-5 h-5" /><span><b>{{ stats.in_today }}</b>{{ $t('Checked in today') }}</span></Link>
          <Link :href="route('visitor-logs.visits.index', { status: 'Checked Out', from: today, to: today })" class="vl-stat is-slate"><icons name="out" class="w-5 h-5" /><span><b>{{ stats.out_today }}</b>{{ $t('Checked out today') }}</span></Link>
          <Link :href="route('visitor-logs.visits.index', { from: monthStart })" class="vl-stat is-violet"><icons name="calendar" class="w-5 h-5" /><span><b>{{ stats.month }}</b>{{ $t('Visits this month') }}</span></Link>
        </template>
        <template v-if="can.items">
          <Link :href="route('visitor-logs.items.index', { from: today, to: today })" class="vl-stat is-amber"><icons name="email" class="w-5 h-5" /><span><b>{{ stats.items_today }}</b>{{ $t('Items received today') }}</span></Link>
          <Link :href="route('visitor-logs.items.index', { when: 'open' })" class="vl-stat is-amber"><icons name="clock" class="w-5 h-5" /><span><b>{{ stats.items_open }}</b>{{ $t('Items awaiting their recipients') }}</span></Link>
          <Link :href="route('visitor-logs.items.index', { when: 'open', status: readyName })" class="vl-stat is-cyan"><icons name="tick" class="w-5 h-5" /><span><b>{{ stats.items_ready }}</b>{{ $t('Ready for collection') }}</span></Link>
          <Link v-if="collectionDays > 0" :href="route('visitor-logs.items.index', { when: 'overdue' })" class="vl-stat is-red"><icons name="exclamation" class="w-5 h-5" /><span><b>{{ stats.items_overdue }}</b>{{ $t('Waiting more than :n day(s)', { n: collectionDays }) }}</span></Link>
        </template>
      </div>

      <div v-if="error" class="lg-note lg-note-red" role="alert" style="margin-bottom: 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ error }}</span></div>

      <section v-if="can.visits" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="in" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Currently on Premises') }} ({{ onPremises.length }})</h2><p class="lg-card-sub">{{ $t('Visitors who are checked in. The list refreshes by itself every minute.') }}</p></div>
          <input v-model="find" class="vl-find" type="search" :placeholder="$t('Find a visitor...')" :aria-label="$t('Find a visitor')" autocomplete="off" />
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive tr-table tr-wide">
            <thead><tr><th>{{ $t('Visitor') }}</th><th>{{ $t('Person visited') }}</th><th>{{ $t('Purpose') }}</th><th class="tr-nw">{{ $t('Check-in') }}</th><th class="tr-nw">{{ $t('Time on premises') }}</th><th>{{ $t('Receptionist') }}</th><th class="lg-sticky-end"></th></tr></thead>
            <tbody>
              <tr v-for="v in shown" :key="v.id" class="is-click" @click="$inertia.visit(route('visitor-logs.visits.show', v.id))">
                <td :data-label="$t('Visitor')" class="tr-wrap"><div class="tr-cell"><b>{{ v.visitor }}</b><small v-if="v.company">{{ v.company }}</small></div></td>
                <td :data-label="$t('Person visited')" class="tr-wrap">{{ v.host || '-' }}</td>
                <td :data-label="$t('Purpose')" class="tr-wrap">{{ v.purpose || '-' }}</td>
                <td :data-label="$t('Check-in')" class="tr-nw">{{ $datetime(v.check_in_at) }}</td>
                <td :data-label="$t('Time on premises')" class="tr-nw"><span :class="{ 'vl-long': v.long_stay }">{{ v.duration }}</span><span v-if="v.long_stay" class="lg-chip lg-chip-amber vl-chip" style="text-transform: none"><icons name="clock" class="w-3 h-3" />{{ $t('Long stay') }}</span></td>
                <td :data-label="$t('Receptionist')" class="tr-wrap">{{ v.registered_by || '-' }}</td>
                <td class="lg-sticky-end tr-acts-cell" @click.stop>
                  <span class="tr-acts"><button v-if="can.checkout" type="button" class="lg-btn lg-btn-primary vl-out" :disabled="busyId === v.id" @click="checkout(v)"><icons name="out" class="w-4 h-4" /><span>{{ $t('Check Out') }}</span></button></span>
                </td>
              </tr>
              <tr v-if="!shown.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ find ? $t('Nobody on the premises matches.') : $t('Nobody is on the premises right now.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="vl-two">
        <section v-if="can.visits" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Recent Visits') }}</h2></div><Link :href="route('visitor-logs.visits.index')" class="vl-more">{{ $t('All visits') }}</Link></header>
          <ul class="vl-feed">
            <li v-for="v in recentVisits" :key="v.id"><Link :href="route('visitor-logs.visits.show', v.id)"><b>{{ v.visitor }}</b><small>{{ v.host ? $t('to see') + ' ' + v.host + ' · ' : '' }}{{ $datetime(v.check_in_at) }}</small></Link><span class="lg-chip" :class="chip(v.status)" style="text-transform: none">{{ $t(v.status) }}</span></li>
            <li v-if="!recentVisits.length" class="vl-empty">{{ $t('No visits yet.') }}</li>
          </ul>
        </section>
        <section v-if="can.items" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="email" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Recent Incoming Items') }}</h2></div><Link :href="route('visitor-logs.items.index')" class="vl-more">{{ $t('All items') }}</Link></header>
          <ul class="vl-feed">
            <li v-for="i in recentItems" :key="i.id"><Link :href="route('visitor-logs.items.show', i.id)"><b>{{ i.reference }} · {{ i.type }}</b><small>{{ $t('for') }} {{ i.recipient }} · {{ i.masked ? $t('Confidential') : i.sender || '' }} · {{ $datetime(i.received_at) }}</small></Link><span class="lg-chip" :class="itemChip(i)" style="text-transform: none">{{ i.status }}</span></li>
            <li v-if="!recentItems.length" class="vl-empty">{{ $t('No items yet.') }}</li>
          </ul>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { visitChip, itemChip } from './Parts/vl.js';

export default {
  components: { Link, AdminLayout, PageHeader },
  props: { can: Object, stats: Object, onPremises: Array, recentVisits: Array, recentItems: Array, overstayHours: Number, collectionDays: Number, location: String, readyStatus: String },
  data() { return { find: '', busyId: null, error: '', timer: null }; },
  computed: {
    today() { return new Date().toLocaleDateString('en-CA'); },
    monthStart() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01'; },
    readyName() { return this.readyStatus || ''; },
    shown() { const q = this.find.trim().toLowerCase(); return q ? this.onPremises.filter(v => [v.visitor, v.company, v.host, v.purpose, v.reference].some(x => (x || '').toLowerCase().includes(q))) : this.onPremises; },
  },
  mounted() { this.timer = setInterval(() => { if (!document.hidden && !this.busyId) this.$inertia.reload({ preserveScroll: true, preserveState: true }); }, 60000); },
  beforeUnmount() { clearInterval(this.timer); },
  methods: {
    chip: visitChip,
    itemChip(i) { return itemChip(i.flag, i.flag !== 'handed' && i.flag !== 'closed'); },
    checkout(v) { this.busyId = v.id; this.error = ''; this.$inertia.post(route('visitor-logs.visits.checkout', v.id), {}, { preserveScroll: true, onError: e => (this.error = e.checkout || Object.values(e)[0]), onFinish: () => (this.busyId = null) }); },
  },
};
</script>

<style scoped>
.vl-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(150px, 100%), 1fr)); gap: 10px; margin-bottom: 14px; }
@media (max-width: 420px) { .vl-stat { flex-direction: column; align-items: flex-start; gap: 6px; padding: 12px; } .vl-stat b { font-size: 1.25rem; } }
.vl-stat { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; text-decoration: none; background: var(--sc-card, #fff); color: var(--sc-ink, #0f2540); box-shadow: 0 0 0 1px var(--sc-line, #e3e9f2), 0 10px 16px -14px rgba(31, 70, 130, 0.55); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.vl-stat:hover { transform: translateY(-2px); box-shadow: 0 0 0 1px #0369a1, 0 14px 22px -14px rgba(3, 105, 161, 0.6); }
.vl-stat > svg { flex: none; color: #0369a1; } .vl-stat.is-green > svg { color: #0d8a5a; } .vl-stat.is-slate > svg { color: #64748b; } .vl-stat.is-violet > svg { color: #7c3aed; } .vl-stat.is-amber > svg { color: #b45309; } .vl-stat.is-cyan > svg { color: #0e7490; } .vl-stat.is-red > svg { color: #b91c1c; }
.vl-stat > span { display: grid; font-size: 0.78rem; font-weight: 700; color: var(--sc-mute, #64748b); min-width: 0; } .vl-stat b { font-size: 1.4rem; line-height: 1.1; color: var(--sc-ink, #0f2540); }
@media (prefers-reduced-motion: reduce) { .vl-stat { transition: none; } .vl-stat:hover { transform: none; } }
.vl-find { margin-left: auto; min-height: 40px; width: min(260px, 100%); padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line, #e3e9f2); background: var(--lg-card, #fff); color: inherit; }
.vl-long { color: #b45309; font-weight: 800; }
.vl-chip { margin-left: 6px; }
.vl-out { min-height: 34px; padding: 0 12px; white-space: nowrap; }
.vl-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
.vl-two > .lg-card { margin-bottom: 0; }
@media (max-width: 900px) { .vl-two { grid-template-columns: 1fr; } .vl-two > .lg-card { margin-bottom: 16px; } .vl-find { margin-left: 0; width: 100%; } }
.vl-more { font-size: 0.8rem; font-weight: 800; color: #0369a1; white-space: nowrap; }
.vl-feed { list-style: none; margin: 0; padding: 0; display: grid; gap: 0; }
.vl-feed > li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--lg-line, #e3e9f2); }
.vl-feed > li:last-child { border-bottom: 0; }
.vl-feed a { display: grid; gap: 2px; min-width: 0; text-decoration: none; color: inherit; }
.vl-feed b { overflow-wrap: anywhere; } .vl-feed small { color: var(--lg-mute, #64748b); font-size: 0.76rem; overflow-wrap: anywhere; }
.vl-empty { color: var(--lg-mute, #64748b); }
</style>
