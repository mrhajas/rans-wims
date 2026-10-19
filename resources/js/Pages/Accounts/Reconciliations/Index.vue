<template>
  <admin-layout :title="$t('Reconciliation')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="tick" :title="$t('Reconciliation')" :subtitle="$t('Count the cash in the box, or match the bank statement, and compare it with what WIMS says. A difference is recorded and explained, never hidden.')">
        <Link v-if="can.perform" :href="route('acct.reconciliations.create', { kind: 'cash' })" class="lg-btn lg-btn-green"><icons name="in" class="w-4 h-4" /><span>{{ $t('Count Cash') }}</span></Link>
        <Link v-if="can.perform" :href="route('acct.reconciliations.create', { kind: 'bank' })" class="lg-btn lg-btn-primary"><icons name="cash" class="w-4 h-4" /><span>{{ $t('Reconcile Bank') }}</span></Link>
              <acct-export type="reconciliation_summary" :params="f" />
      </page-header>

      <section v-if="due.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Due for Reconciliation') }}</h2><p class="lg-card-sub">{{ due.length }} {{ $t('accounts need a cash count or a bank reconciliation') }}</p></div></header>
        <div class="ac-due">
          <div v-for="d in due" :key="d.id" class="ac-due-item" :class="d.last ? 'tone-amber' : 'tone-rose'">
            <span class="ac-rico ac-due-ico"><icons :name="d.kind === 'bank_account' ? 'cash' : 'in'" class="w-5 h-5" /></span>
            <div class="ac-due-text"><b>{{ d.name }}</b><small><icons name="clock" class="w-3.5 h-3.5" />{{ d.last ? $t('last done :d (:n days ago)', { d: d.last, n: d.age }) : $t('never reconciled') }}</small></div>
            <Link v-if="can.perform" :href="route('acct.reconciliations.create', { account_id: d.id, kind: d.kind === 'bank_account' ? 'bank' : 'cash' })" class="lg-btn ac-due-go"><icons name="tick" class="w-4 h-4" />{{ d.kind === 'bank_account' ? $t('Reconcile') : $t('Count') }}</Link>
          </div>
        </div>
      </section>

      <form class="lg-card" @submit.prevent="go"><div class="ac-filters">
        <div class="ac-f"><label>{{ $t('Account') }}</label><acct-select v-model="f.account_id" :options="accounts" :placeholder="$t('All')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Type') }}</label><acct-select v-model="f.kind" :options="[{ value: 'cash', label: $t('Cash count') }, { value: 'bank', label: $t('Bank') }]" :placeholder="$t('Both')" clearable /></div>
        <div class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="(['draft', 'submitted', 'approved', 'rejected']).map(s => ({ value: s, label: $t(s) }))" :placeholder="$t('Any')" clearable /></div>
        <label class="ac-check"><input v-model="f.with_difference" type="checkbox" true-value="1" false-value="" />{{ $t('Only with a difference') }}</label>
        <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button></div></div></form>

      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('Account') }}</th><th>{{ $t('As of') }}</th><th class="ac-num">{{ $t('WIMS says') }}</th><th class="ac-num">{{ $t('Counted') }}</th><th class="ac-num">{{ $t('Difference') }}</th><th>{{ $t('Status') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in rows.data" :key="r.id" class="is-click" :class="{ 'ac-row-hot': Number(r.difference) !== 0 }" @click="$inertia.visit(route('acct.reconciliations.show', r.id))">
            <td :data-label="$t('Reference')" class="lg-main">{{ r.reference }}<div class="lg-subtext">{{ r.kind === 'cash' ? $t('Cash count') : $t('Bank statement') }}</div></td><td :data-label="$t('Account')">{{ r.account }}</td><td :data-label="$t('As of')">{{ $date(r.period_to) }}</td>
            <td :data-label="$t('WIMS says')" class="ac-num">{{ mc(r.expected, r.currency) }}</td><td :data-label="$t('Counted')" class="ac-num">{{ mc(r.counted, r.currency) }}</td>
            <td :data-label="$t('Difference')" class="ac-num" :class="Number(r.difference) !== 0 ? 'ac-out' : ''">{{ mc(r.difference, r.currency) }}</td><td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status === 'draft' ? 'draft' : r.status === 'submitted' ? 'submitted' : r.status === 'approved' ? 'posted' : 'rejected')">{{ $t(r.status) }}</span></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
        </tbody></table></div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '../Pager.vue';
import AcctSelect from '../AcctSelect.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, AcctSelect, AdminLayout, PageHeader, Pager, Link },
  mixins: [acct],
  props: { cur: Object, rows: Object, filters: Object, accounts: Array, due: Array, can: Object },
  data() { return { f: { ...this.filters } }; },
  methods: { go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('acct.reconciliations.index'), q, { preserveState: true }); } },
};
</script>
