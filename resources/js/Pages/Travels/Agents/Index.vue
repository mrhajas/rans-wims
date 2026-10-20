<template>
  <admin-layout :title="$t('Ticket Agents')">
    <div class="lg-page ac sc-ui tr-ui px-4 md:px-0">
      <page-header icon="cash" :title="$t('Ticket Agents')" :subtitle="$t('What each ticket agent and visa issuer is owed: the balance from before, the approved travels they issued, and the payments made through Payment Vouchers.')">
        <a v-if="can.create_contact" :href="newAgentUrl" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Ticket Agent') }}</span></a>
        <a v-if="can.create_contact" :href="newVisaUrl" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Visa Agent') }}</span></a>
        <Link :href="route('travels.index')" class="lg-btn lg-btn-soft"><icons name="plane" class="w-4 h-4" /><span>{{ $t('Travels') }}</span></Link>
        <travel-export type="agent_balances" :params="{ currency: f.currency, only_owing: f.owing, search: f.search }" />
      </page-header>

      <div v-if="totals.length" class="tr-tiles">
        <div v-for="t in totals" :key="t.currency" class="tr-tile">
          <span class="tr-cur">{{ t.currency }}</span>
          <div class="tr-big"><small>{{ $t('Owed to agents') }}</small><b class="tr-o" :class="{ 'ac-neg': Number(t.balance) < 0 }">{{ money(t.balance) }}</b></div>
          <div class="tr-sub"><span>{{ $t('Opening') }} <b class="tr-m">{{ money(t.opening) }}</b></span><span>{{ $t('Billed') }} <b class="tr-m">{{ money(t.billed) }}</b></span><span>{{ $t('Paid') }} <b class="tr-g">{{ money(t.paid) }}</b></span><span v-if="Number(t.reserved)">{{ $t('In waiting vouchers') }} <b class="tr-o">{{ money(t.reserved) }}</b></span></div>
        </div>
      </div>

      <section class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Agent name or phone')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies.map(c => ({ value: c, label: c }))" :placeholder="$t('Any')" clearable /></div>
          <label class="tr-check"><input v-model="f.owing" type="checkbox" true-value="1" false-value="" />{{ $t('Only agents we owe') }}</label>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n line(s) found', { n: rows.length }) }}</span></div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Agent') }}</th><th class="tr-c">{{ $t('Cur.') }}</th><th class="tr-num">{{ $t('Opening') }}</th><th class="tr-num">{{ $t('Tickets') }}</th><th class="tr-num">{{ $t('Visas') }}</th><th class="tr-num">{{ $t('Paid') }}</th><th class="tr-num">{{ $t('Balance owed') }}</th><th class="tr-num">{{ $t('In waiting vouchers') }}</th><th class="tr-nw">{{ $t('Last payment') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows" :key="r.contact_id + '|' + r.currency" class="is-click" @click="$inertia.visit(route('travels.agents.show', r.contact_id))">
              <td :data-label="$t('No')" class="tr-no">{{ n + 1 }}</td>
              <td :data-label="$t('Agent')" class="tr-wrap"><div class="tr-cell"><b class="lg-main">{{ r.agent }}</b><small>{{ [r.category, r.phone].filter(Boolean).join(' · ') }}</small></div></td>
              <td :data-label="$t('Cur.')" class="tr-c">{{ r.currency || '-' }}</td>
              <td :data-label="$t('Opening')" class="tr-num"><span v-if="r.currency" class="tr-m">{{ money(r.opening) }}</span><span v-else>-</span><small v-if="r.opening_date" class="lg-subtext" style="display: block">{{ $date(r.opening_date) }}</small></td>
              <td :data-label="$t('Tickets')" class="tr-num"><span v-if="r.currency" class="tr-m">{{ money(r.ticket) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Visas')" class="tr-num"><span v-if="r.currency" class="tr-m">{{ money(r.visa) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Paid')" class="tr-num"><span v-if="r.currency" class="tr-g">{{ money(r.paid) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Balance owed')" class="tr-num"><b class="tr-o" :class="{ 'tr-neg': Number(r.balance) < 0 }">{{ r.currency ? money(r.balance) : '-' }}</b><small v-if="Number(r.pending)" class="lg-subtext" style="display: block">+ {{ money(r.pending) }} {{ $t('pending approval') }}</small></td>
              <td :data-label="$t('In waiting vouchers')" class="tr-num"><span v-if="r.currency && Number(r.reserved)" class="tr-o">{{ money(r.reserved) }}</span><span v-else>-</span></td>
              <td :data-label="$t('Last payment')" class="tr-nw">{{ r.last_payment ? $date(r.last_payment) : '-' }}</td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop><span class="tr-acts"><Link :href="route('travels.agents.show', r.contact_id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open the account')" :aria-label="$t('Open the account')"><icons name="doc" class="w-4 h-4" /></Link></span></td>
            </tr>
            <tr v-if="!rows.length" class="lg-empty-row"><td class="lg-empty" colspan="11">{{ hasFilters ? $t('No agent matches these filters.') : $t('No ticket agents yet. Add a contact in the Ticket Agent category and it appears here.') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import TravelExport from '../TravelExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

export default {
  components: { TravelExport, AdminLayout, PageHeader, Link, AcctSelect },
  props: { rows: Array, filters: Object, totals: Array, currencies: Array, newAgentUrl: String, newVisaUrl: String, can: Object },
  data() { return { f: { ...this.filters }, busy: false, timer: null }; },
  watch: { f: { deep: true, handler() { this.queue(); } } },
  computed: { hasFilters() { return Object.keys(this.f).some(k => this.f[k]); } },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    queue() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('travels.agents.index'), q, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (this.busy = false) }); },
    reset() { this.f = {}; },
  },
};
</script>

<style scoped>
.tr-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-bottom: 12px; }
.tr-tile { position: relative; padding: 14px 16px; border-radius: 20px; background: linear-gradient(135deg, rgba(14, 116, 144, 0.1), rgba(79, 70, 229, 0.07)), var(--sc-card, #fff); box-shadow: 0 0 0 1px var(--sc-line, #e3e9f2), 0 14px 24px -18px rgba(14, 116, 144, 0.7); }
.tr-cur { position: absolute; top: 12px; right: 14px; padding: 2px 10px; border-radius: 999px; background: #0e7490; color: #fff; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.08em; }
.tr-big { display: grid; gap: 2px; } .tr-big small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--sc-mute, #64748b); } .tr-big b { font-size: 1.55rem; font-variant-numeric: tabular-nums; }
.tr-sub { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 8px; font-size: 0.76rem; color: var(--sc-mute, #64748b); } .tr-sub b { color: var(--sc-ink, #0f2540); font-variant-numeric: tabular-nums; }
</style>
