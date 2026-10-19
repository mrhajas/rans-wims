<template>
  <admin-layout :title="title">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="group === 'bank' ? 'cash' : group === 'cash' ? 'in' : 'collection'" :title="title" :subtitle="$t('The accounts of Funds Allocation (Settings > Accounts), each with its balance in its own currency and who looks after it.')">
        <template v-if="can.export">
          <a :href="exportUrl('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="doc" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
          <a :href="exportUrl('pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>PDF</span></a>
          <a :href="exportUrl('xlsx')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>Excel</span></a>
        </template>
        <button v-if="can.link" type="button" class="lg-btn lg-btn-soft" @click="link"><icons name="refresh" class="w-4 h-4" /><span>{{ $t('Refresh') }}</span></button>
        <a :href="fundSettingsUrl" class="lg-btn lg-btn-primary"><icons name="cog" class="w-4 h-4" /><span>{{ $t('Add or change accounts') }}</span></a>
      </page-header>

      <sc-tabs :tabs="tabs" :model-value="group" @update:model-value="k => $inertia.get(route('acct.accounts.index', k === 'all' ? {} : { group: k }))" />

      <section class="ac-grid">
        <div v-for="(v, c, ci) in totals" :key="c" class="ac-cur ac-glow ac-light" :class="['tone-navy', 'tone-teal', 'tone-violet', 'tone-sky'][ci % 4]"><div class="ac-cur-code">{{ c }} {{ $t('in these accounts') }}</div><div class="ac-cur-total" :class="{ 'ac-neg': neg(v) }">{{ m(v, c) }}</div><div class="ac-split">{{ count }} {{ $t('accounts') }}</div></div>
      </section>

      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters">
          <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Name, code, bank or place')" /></div>
          <div class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="(['active', 'inactive', 'suspended', 'closed']).map(s => ({ value: s, label: $t(s) }))" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="curOptions" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Search') }}</button></div>
        </div>
      </form>

      <div class="ac-cards">
        <Link v-for="a in rows.data" :key="a.id" :href="route('acct.accounts.show', a.id)" class="ac-acct ac-glow ac-light" :class="[{ 'is-low': a.low }, a.group === 'bank' ? 'tone-sky' : a.group === 'cash' ? 'tone-emerald' : 'tone-violet']">
          <div class="ac-acct-top">
            <span class="lg-stat-ico" :class="a.group === 'bank' ? 'lg-ico-navy' : a.group === 'cash' ? 'lg-ico-emerald' : 'lg-ico-violet'"><icons :name="a.group === 'bank' ? 'cash' : a.group === 'cash' ? 'in' : 'collection'" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><div class="ac-acct-name">{{ a.name }}<span v-if="a.is_primary" class="lg-chip lg-chip-cyan" style="margin-left: 6px">{{ $t('Main') }}</span></div><div class="ac-acct-sub">{{ a.code }} - {{ a.kind_label }}</div></div>
            <span class="lg-chip" :class="statusChip(a.status)">{{ $t(a.status) }}</span>
          </div>
          <div class="ac-acct-bal" :class="{ 'ac-neg': neg(a.balance) }">{{ mc(a.balance, a.currency) }}</div>
          <div class="ac-split"><span v-if="a.institution">{{ a.institution }}</span><span v-if="a.masked">{{ a.masked }}</span><span v-if="a.custodian">{{ $t('Custodian') }}: <b>{{ a.custodian }}</b></span><span v-if="a.low" class="ac-out"><b>{{ $t('Below minimum') }}</b></span></div>
        </Link>
      </div>
      <p v-if="!rows.data.length" class="lg-card" style="text-align: center">{{ $t('There is no data to display.') }}</p>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '../../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '../Pager.vue';
import AcctSelect from '../AcctSelect.vue';
import { acct } from '../acct';

export default {
  components: { ScTabs, AcctSelect, AdminLayout, PageHeader, Pager, Link },
  mixins: [acct],
  props: { cur: Object, rows: Object, group: String, title: String, filters: Object, totals: Object, count: Number, can: Object, fundSettingsUrl: String },
  data() { return { f: { ...this.filters } }; },
  computed: { tabs() { return [{ key: 'all', label: this.$t('All'), icon: 'collection', tone: 'navy' }, { key: 'bank', label: this.$t('Bank accounts'), icon: 'cash', tone: 'sky' }, { key: 'cash', label: this.$t('Cash in hand'), icon: 'in', tone: 'emerald' }, { key: 'other', label: this.$t('Other'), icon: 'folder', tone: 'violet' }]; } },
  methods: {
    go() { const q = { group: this.group === 'all' ? undefined : this.group }; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('acct.accounts.index'), q, { preserveState: true }); },
    // Print and downloads are the WIMS account report, for the tab and filters on screen; every one is written to the activity log and the audit trail
    exportUrl(fmt) { return route('acct.reports.' + fmt, { type: 'account_balances', group: this.group === 'all' ? undefined : this.group, currency: this.f.currency || undefined, status: this.f.status || undefined, q: this.f.q || undefined }); },
    link() { this.$inertia.post(route('acct.accounts.link-funds')); },
  },
};
</script>
