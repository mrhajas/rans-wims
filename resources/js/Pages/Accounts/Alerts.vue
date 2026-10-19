<template>
  <admin-layout :title="$t('Financial Alerts')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="exclamation" :title="$t('Financial Alerts')" :subtitle="$t('Low balances, unusual payments, late approvals, reconciliation differences, missing rates and security events. Each one stays until someone deals with it.')">
        <button v-if="counts.unread" type="button" class="lg-btn lg-btn-soft" @click="$inertia.post(route('acct.alerts.read-all'), {}, { preserveScroll: true })"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Mark all read') }}</span></button>
      </page-header>
      <section class="ac-attn">
        <div class="ac-tile" :class="{ 'is-hot': counts.critical }"><span class="lg-stat-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span><div><div class="n">{{ counts.critical }}</div><div class="l">{{ $t('High or critical') }}</div></div></div>
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-amber"><icons name="bell" class="w-5 h-5" /></span><div><div class="n">{{ counts.open }}</div><div class="l">{{ $t('Open') }}</div></div></div>
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-sky"><icons name="eye-flat" class="w-5 h-5" /></span><div><div class="n">{{ counts.unread }}</div><div class="l">{{ $t('Not yet read') }}</div></div></div>
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-violet"><icons name="user" class="w-5 h-5" /></span><div><div class="n">{{ counts.mine }}</div><div class="l">{{ $t('Assigned to me') }}</div></div></div>
      </section>
      <sc-tabs :tabs="catTabs.map(c => ({ key: c.key, label: c.label, icon: c.icon, tone: c.tone, count: c.key && counts.byCategory[c.key] ? counts.byCategory[c.key] : undefined }))" :model-value="f.category || ''" @update:model-value="k => { f.category = k; go(); }" />
      <form class="lg-card" @submit.prevent="go"><div class="ac-filters">
        <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Alert number or words')" /></div>
        <div class="ac-f"><label>{{ $t('Show') }}</label><acct-select v-model="f.status" :options="[{ value: 'active', label: $t('Open and acknowledged') }, { value: 'open', label: $t('Open') }, { value: 'acknowledged', label: $t('Acknowledged') }, { value: 'resolved', label: $t('Resolved') }, { value: 'all', label: $t('Everything') }]" /></div>
        <div class="ac-f"><label>{{ $t('Severity') }}</label><acct-select v-model="f.severity" :options="severities" :placeholder="$t('Any')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Account') }}</label><acct-select v-model="f.account_id" :options="accounts" :placeholder="$t('Any')" clearable /></div>
        <label class="ac-check"><input v-model="f.mine" type="checkbox" true-value="1" false-value="" />{{ $t('Assigned to me') }}</label>
        <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button></div></div></form>

      <ul class="ac-timeline">
        <li v-for="a in rows.data" :key="a.id" class="lg-card" :style="a.read ? '' : 'box-shadow: 0 0 0 1.5px #3b82c4, var(--lg-shadow)'" style="margin-bottom: 4px">
          <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-start; justify-content: space-between">
            <div class="min-w-0" style="flex: 1 1 320px">
              <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center"><span class="lg-chip" :class="sevChip(a.severity)">{{ a.severity_label }}</span><span class="lg-chip lg-chip-slate">{{ a.category_label }}</span><span class="lg-chip" :class="statusChip(a.status)">{{ $t(a.status) }}</span><span v-if="a.escalated" class="lg-chip lg-chip-red">{{ $t('Escalated') }}</span><small class="lg-subtext">{{ a.reference }}</small></div>
              <div class="lg-main" style="margin-top: 4px; white-space: normal">{{ a.title }}</div>
              <div v-if="a.description" class="lg-sub" style="white-space: normal">{{ a.description }}</div>
              <div class="ac-split" style="margin-top: 4px"><span v-if="a.account">{{ $t('Account') }}: <b>{{ a.account }}</b></span><span v-if="a.amount !== null">{{ $t('Amount') }}: <b>{{ mc(a.amount, a.currency) }}</b></span><span>{{ $t('First seen') }} {{ a.since }}</span><span v-if="a.occurrences > 1">{{ a.occurrences }}x</span><span v-if="a.assigned">{{ $t('Assigned to') }} <b>{{ a.assigned }}</b></span></div>
              <div v-if="a.resolution_notes" class="lg-note lg-note-blue" style="margin-top: 6px">{{ a.resolved_by }}: {{ a.resolution_notes }}</div>
            </div>
            <div class="ac-actions" style="margin: 0; justify-content: flex-end">
              <Link v-if="a.link" :href="a.link" class="lg-btn lg-btn-soft"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Open') }}</Link>
              <template v-if="can.resolve && a.status !== 'resolved'">
                <button v-if="a.status === 'open'" type="button" class="lg-btn t-teal" @click="post('acknowledge', a)"><icons name="check-double" class="w-4 h-4" />{{ $t('Acknowledge') }}</button>
                <button type="button" class="lg-btn t-violet" @click="open(a, 'assign')"><icons name="user" class="w-4 h-4" />{{ $t('Assign') }}</button>
                <button type="button" class="lg-btn t-amber" @click="open(a, 'escalate')"><icons name="up" class="w-4 h-4" />{{ $t('Escalate') }}</button>
                <button type="button" class="lg-btn lg-btn-green" @click="open(a, 'resolve')"><icons name="tick" class="w-4 h-4" />{{ $t('Resolve') }}</button>
              </template>
              <button v-if="!a.read" type="button" class="lg-btn t-teal" @click="post('read', a)"><icons name="check-double" class="w-4 h-4" />{{ $t('Mark read') }}</button>
            </div>
          </div>
          <form v-if="active && active.id === a.id" class="ac-reason" @submit.prevent="send">
            <acct-select v-if="mode === 'assign'" v-model="user_id" :options="users" :placeholder="$t('Choose a person')" clearable style="min-width: 240px; flex: 1 1 240px" />
            <input v-else v-model="notes" :placeholder="mode === 'resolve' && ['high', 'critical'].includes(a.severity) ? $t('How was it dealt with? (required)') : $t('Notes (optional)')" :required="mode === 'resolve' && ['high', 'critical'].includes(a.severity)" />
            <button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="active = null"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          </form>
        </li>
        <li v-if="!rows.data.length" class="lg-card" style="text-align: center">{{ $t('No alerts. Everything is in order.') }}</li>
      </ul>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from './Pager.vue';
import AcctSelect from './AcctSelect.vue';
import { acct } from './acct';

export default {
  components: { ScTabs, AcctSelect, AdminLayout, PageHeader, Pager, Link },
  mixins: [acct],
  props: { cur: Object, rows: Object, filters: Object, counts: Object, categories: Array, severities: Array, accounts: Array, users: Array, can: Object },
  data() { return { f: { ...this.filters }, active: null, mode: '', notes: '', user_id: null }; },
  computed: { catTabs() { const look = { balance: ['cash', 'sky'], transaction: ['list', 'amber'], transfer: ['refresh', 'violet'], reconciliation: ['tick', 'teal'], currency: ['adjustments', 'emerald'], security: ['lock-closed', 'rose'] }; return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy' }, ...this.categories.map(c => ({ key: c.value, label: c.label, icon: (look[c.value] || ['bell', 'navy'])[0], tone: (look[c.value] || ['bell', 'navy'])[1] }))]; } },
  methods: {
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('acct.alerts.index'), q, { preserveState: true }); },
    post(a, row) { this.$inertia.post(route('acct.alerts.' + a, row.id), {}, { preserveScroll: true }); },
    open(a, m) { this.active = a; this.mode = m; this.notes = ''; this.user_id = null; },
    send() { const body = this.mode === 'assign' ? { user_id: this.user_id } : { notes: this.notes }; this.$inertia.post(route('acct.alerts.' + this.mode, this.active.id), body, { preserveScroll: true, onSuccess: () => (this.active = null) }); },
  },
};
</script>
