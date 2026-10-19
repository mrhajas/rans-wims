<template>
  <admin-layout :title="$t('Approvals')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="tick" :title="$t('Approvals')" :subtitle="$t('Everything waiting for a decision or for posting. You cannot approve what you created or submitted.')">
        <acct-export type="pending_approvals" />
      </page-header>
      <section class="ac-attn">
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span><div><div class="n">{{ counts.transactions }}</div><div class="l">{{ $t('Transactions') }}</div></div></div>
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-violet"><icons name="refresh" class="w-5 h-5" /></span><div><div class="n">{{ counts.transfers }}</div><div class="l">{{ $t('Transfers') }}</div></div></div>
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-teal"><icons name="adjustments" class="w-5 h-5" /></span><div><div class="n">{{ counts.rates }}</div><div class="l">{{ $t('Exchange rates') }}</div></div></div>
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-sky"><icons name="doc-text" class="w-5 h-5" /></span><div><div class="n">{{ counts.reconciliations }}</div><div class="l">{{ $t('Reconciliations') }}</div></div></div>
        <div class="ac-tile"><span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span><div><div class="n">{{ counts.toPost }}</div><div class="l">{{ $t('Approved, ready to post') }}</div></div></div>
      </section>
      <sc-tabs :tabs="tabs" v-model="tab" />
      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr><th>{{ $t('What') }}</th><th>{{ $t('Reference') }}</th><th>{{ $t('Details') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('From') }}</th><th>{{ $t('Waiting') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="i in shown" :key="i.type + i.id" class="is-click" @click="$inertia.visit(i.url)">
            <td :data-label="$t('What')"><span class="lg-chip lg-chip-slate">{{ labels[i.type] }}</span></td><td :data-label="$t('Reference')" class="lg-main">{{ i.reference }}</td>
            <td :data-label="$t('Details')"><span class="ac-clip">{{ i.title }}</span><div class="lg-subtext">{{ i.account }}</div></td>
            <td :data-label="$t('Amount')" class="ac-num" :class="i.direction === 'in' ? 'ac-in' : i.direction === 'out' ? 'ac-out' : ''">{{ i.amount !== null && i.currency ? mc(i.amount, i.currency) : '-' }}</td>
            <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(i.status)">{{ i.status === 'approved' ? $t('Ready to post') : $t('Needs approval') }}</span> <small v-if="i.approvals" class="lg-subtext">{{ i.approvals }}</small></td>
            <td :data-label="$t('From')">{{ i.by }}<small v-if="i.own" class="lg-subtext"> ({{ $t('you') }})</small></td><td :data-label="$t('Waiting')">{{ i.since }}</td>
            <td class="lg-sticky-end" @click.stop><Link :href="i.url" class="lg-btn lg-btn-soft"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Open') }}</Link></td>
          </tr>
          <tr v-if="!shown.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('Nothing is waiting. Well done.') }}</td></tr>
        </tbody></table></div>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from './AcctExport.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { acct } from './acct';

export default {
  components: { AcctExport, ScTabs, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, items: Array, counts: Object },
  data() { return { tab: 'all' }; },
  computed: {
    labels() { return { transaction: this.$t('Transaction'), transfer: this.$t('Transfer'), rate: this.$t('Rate'), reconciliation: this.$t('Reconciliation') }; },
    tabs() { return [{ key: 'all', label: this.$t('All'), icon: 'collection', tone: 'navy' }, { key: 'transaction', label: this.$t('Transactions'), icon: 'list', tone: 'sky' }, { key: 'transfer', label: this.$t('Transfers'), icon: 'refresh', tone: 'violet' }, { key: 'rate', label: this.$t('Rates'), icon: 'adjustments', tone: 'teal' }, { key: 'reconciliation', label: this.$t('Reconciliations'), icon: 'tick', tone: 'emerald' }, { key: 'post', label: this.$t('Ready to post'), icon: 'send', tone: 'amber' }]; },
    shown() { return this.items.filter(i => this.tab === 'all' || (this.tab === 'post' ? i.status === 'approved' : i.type === this.tab)); },
  },
};
</script>
