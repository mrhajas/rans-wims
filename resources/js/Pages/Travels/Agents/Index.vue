<template>
  <admin-layout :title="$t('Ticket Agents')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cash" :title="$t('Ticket Agents')" :subtitle="$t('What each ticket agent and visa issuer is owed: the balance from before, the approved travels they issued, and the payments made through Payment Vouchers.')">
        <a v-if="can.create_contact" :href="newAgentUrl" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Ticket Agent') }}</span></a>
        <a v-if="can.create_contact" :href="newVisaUrl" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Visa Agent') }}</span></a>
        <Link :href="route('travels.index')" class="lg-btn lg-btn-soft"><icons name="plane" class="w-4 h-4" /><span>{{ $t('Travels') }}</span></Link>
        <travel-export type="agent_balances" :params="{ currency: f.currency, only_owing: f.owing, search: f.search }" />
      </page-header>

      <div v-if="totals.length" class="tr-tiles">
        <div v-for="t in totals" :key="t.currency" class="tr-tile">
          <span class="tr-cur">{{ t.currency }}</span>
          <div class="tr-big"><small>{{ $t('Owed to agents') }}</small><b :class="{ 'ac-neg': Number(t.balance) < 0 }">{{ money(t.balance) }}</b></div>
          <div class="tr-sub"><span>{{ $t('Opening') }} <b>{{ money(t.opening) }}</b></span><span>{{ $t('Billed') }} <b>{{ money(t.billed) }}</b></span><span>{{ $t('Paid') }} <b>{{ money(t.paid) }}</b></span><span v-if="Number(t.reserved)">{{ $t('In waiting vouchers') }} <b>{{ money(t.reserved) }}</b></span></div>
        </div>
      </div>

      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters tr-filters">
          <div class="ac-f tr-search"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Agent name or phone')" /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies.map(c => ({ value: c, label: c }))" :placeholder="$t('Any')" clearable /></div>
          <label class="ac-check"><input v-model="f.owing" type="checkbox" true-value="1" false-value="" />{{ $t('Only agents we owe') }}</label>
          <div class="ac-f tr-actions"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn t-slate" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
        </div>
      </form>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Agent') }}</th><th>{{ $t('Cur.') }}</th><th class="ac-num">{{ $t('Opening') }}</th><th class="ac-num">{{ $t('Tickets') }}</th><th class="ac-num">{{ $t('Visas') }}</th><th class="ac-num">{{ $t('Paid') }}</th><th class="ac-num">{{ $t('Balance owed') }}</th><th class="ac-num">{{ $t('In waiting vouchers') }}</th><th>{{ $t('Last payment') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rows" :key="r.contact_id + '|' + r.currency" class="is-click" @click="$inertia.visit(route('travels.agents.show', r.contact_id))">
              <td :data-label="$t('Agent')"><span class="lg-main">{{ r.agent }}</span><div class="lg-subtext">{{ [r.category, r.phone].filter(Boolean).join(' · ') }}</div></td>
              <td :data-label="$t('Cur.')">{{ r.currency || '-' }}</td>
              <td :data-label="$t('Opening')" class="ac-num">{{ r.currency ? money(r.opening) : '-' }}<div v-if="r.opening_date" class="lg-subtext">{{ $date(r.opening_date) }}</div></td>
              <td :data-label="$t('Tickets')" class="ac-num">{{ r.currency ? money(r.ticket) : '-' }}</td>
              <td :data-label="$t('Visas')" class="ac-num">{{ r.currency ? money(r.visa) : '-' }}</td>
              <td :data-label="$t('Paid')" class="ac-num">{{ r.currency ? money(r.paid) : '-' }}</td>
              <td :data-label="$t('Balance owed')" class="ac-num"><b class="amt" :class="{ 'ac-neg': Number(r.balance) < 0 }">{{ r.currency ? money(r.balance) : '-' }}</b><div v-if="Number(r.pending)" class="lg-subtext">+ {{ money(r.pending) }} {{ $t('pending approval') }}</div></td>
              <td :data-label="$t('In waiting vouchers')" class="ac-num">{{ r.currency && Number(r.reserved) ? money(r.reserved) : '-' }}</td>
              <td :data-label="$t('Last payment')">{{ r.last_payment ? $date(r.last_payment) : '-' }}</td>
              <td class="lg-sticky-end" @click.stop><Link :href="route('travels.agents.show', r.contact_id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open the account')" :aria-label="$t('Open the account')"><icons name="doc" class="w-4 h-4" /></Link></td>
            </tr>
            <tr v-if="!rows.length" class="lg-empty-row"><td class="lg-empty" colspan="10">{{ $t('No ticket agents yet. Add a contact in the Ticket Agent category and it appears here.') }}</td></tr>
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
  data() { return { f: { ...this.filters } }; },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('travels.agents.index'), q, { preserveState: true }); },
    reset() { this.f = {}; this.go(); },
  },
};
</script>

<style scoped>
.tr-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-bottom: 12px; }
.tr-tile { position: relative; padding: 14px 16px; border-radius: 20px; background: linear-gradient(135deg, rgba(14, 116, 144, 0.1), rgba(79, 70, 229, 0.07)), var(--sc-card, #fff); box-shadow: 0 0 0 1px var(--sc-line, #e3e9f2), 0 14px 24px -18px rgba(14, 116, 144, 0.7); }
.tr-cur { position: absolute; top: 12px; right: 14px; padding: 2px 10px; border-radius: 999px; background: #0e7490; color: #fff; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.08em; }
.tr-big { display: grid; gap: 2px; } .tr-big small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--sc-mute, #64748b); } .tr-big b { font-size: 1.55rem; font-variant-numeric: tabular-nums; }
.tr-sub { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 8px; font-size: 0.76rem; color: var(--sc-mute, #64748b); } .tr-sub b { color: var(--sc-ink, #0f2540); font-variant-numeric: tabular-nums; }
.tr-filters .tr-actions > div { display: flex; gap: 8px; }
@media (min-width: 900px) { .tr-filters { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) auto auto; align-items: end; } }
</style>
