<template>
  <admin-layout :title="t.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="refresh" :title="t.reference" :subtitle="t.reason || $t('Transfer between accounts')">
        <Link :href="route('acct.transfers.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <Link v-if="can.edit" :href="route('acct.transfers.edit', t.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
              <acct-export type="transfer_detail" :params="{ record_id: t.id }" :sheets="false" />
      </page-header>

      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div>
            <span class="lg-chip" :class="statusChip(t.status)">{{ t.status_label }}</span> <span class="lg-chip lg-chip-slate">{{ $t('Approvals') }} {{ t.approvals }}</span>
            <div class="ac-cur-total ac-out" style="margin-top: 6px">- {{ mc(t.from_amount, t.from_currency) }}</div>
            <div class="ac-cur-total ac-in">+ {{ mc(t.to_amount, t.to_currency) }}</div>
          </div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="act('submit')"><icons name="send" class="w-4 h-4" />{{ $t('Submit for approval') }}</button>
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="act('approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.post" type="button" class="lg-btn lg-btn-green" @click="act('post')"><icons name="tick" class="w-4 h-4" />{{ $t('Post both balances') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.reverse" type="button" class="lg-btn t-violet" @click="ask('reverse')"><icons name="refresh" class="w-4 h-4" />{{ $t('Reverse') }}</button>
            <button v-if="can.cancel" type="button" class="lg-btn t-red" @click="ask('cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel it') }}</button>
          </div>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="send"><input v-model="reason" :placeholder="$t('Reason (required)')" required /><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="asking = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="t.reject_reason && t.status === 'rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}: {{ t.reject_reason }}</div>
        <div v-if="t.cancel_reason" class="lg-note lg-note-amber" style="margin-top: 10px">{{ $t('Cancelled') }}: {{ t.cancel_reason }}</div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv">
          <div><dt>{{ $t('From') }}</dt><dd><Link :href="route('acct.accounts.show', t.from_id)">{{ t.from }}</Link></dd></div>
          <div><dt>{{ $t('To') }}</dt><dd><Link :href="route('acct.accounts.show', t.to_id)">{{ t.to }}</Link></dd></div>
          <div><dt>{{ $t('Date') }}</dt><dd>{{ $date(t.date) }}</dd></div>
          <div v-if="t.cross"><dt>{{ $t('Rate used') }}</dt><dd>1 {{ t.from_currency }} = {{ trim(t.exchange_rate) }} {{ t.to_currency }} <small v-if="t.rate_type" class="lg-subtext">({{ t.rate_type }})</small></dd></div>
          <div v-if="t.cross"><dt>{{ $t('Exchange difference') }}</dt><dd :class="{ 'ac-neg': neg(t.rounding_difference) }">{{ mc(t.rounding_difference, t.to_currency) }}</dd></div>
          <div v-if="Number(t.fee_amount)"><dt>{{ $t('Fee') }}</dt><dd>{{ mc(t.fee_amount, t.from_currency) }}</dd></div>
          <div v-if="t.external_ref"><dt>{{ $t('Reference') }}</dt><dd>{{ t.external_ref }}</dd></div>
          <div v-if="t.project"><dt>{{ $t('Project') }}</dt><dd>{{ t.project }}</dd></div>
          <div><dt>{{ $t('Created by') }}</dt><dd>{{ t.created_by_name || '-' }} <small class="lg-subtext">{{ t.created_at }}</small></dd></div>
          <div v-if="t.posted_by_name"><dt>{{ $t('Posted by') }}</dt><dd>{{ t.posted_by_name }} <small class="lg-subtext">{{ t.posted_at }}</small></dd></div>
        </dl>
        <p v-if="t.notes" class="lg-sub" style="margin-top: 8px">{{ t.notes }}</p>
      </section>

      <section v-if="legs.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Posted Entries') }}</h2><p class="lg-card-sub">{{ $t('Each side of the transfer is its own ledger entry.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('Account') }}</th><th>{{ $t('Entry') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th></tr></thead>
          <tbody><tr v-for="l in legs" :key="l.id" class="is-click" @click="$inertia.visit(route('acct.transactions.show', l.id))">
            <td :data-label="$t('Reference')" class="lg-main">{{ l.reference }}</td><td :data-label="$t('Account')">{{ l.account }}</td><td :data-label="$t('Entry')">{{ $t(l.kind) }} ({{ l.direction === 'in' ? $t('in') : $t('out') }})</td>
            <td :data-label="$t('Amount')" class="ac-num" :class="l.direction === 'in' ? 'ac-in' : 'ac-out'">{{ l.direction === 'in' ? '+' : '-' }}{{ mc(l.amount, l.currency) }}</td><td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(l.status)">{{ $t(l.status) }}</span></td></tr></tbody></table></div>
      </section>

      <section class="lg-card" v-if="timeline.length"><header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Approval Trail') }}</h2></div></header>
        <ul class="ac-timeline"><li v-for="(x, i) in timeline" :key="i"><b>{{ $t(x.action) }}</b> - {{ x.user }} <small>{{ x.at }}</small><div v-if="x.comment">{{ x.comment }}</div></li></ul></section>
      <section class="lg-card" v-if="history && history.length"><header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="ac-timeline"><li v-for="(h, i) in history" :key="i"><b>{{ h.event }}</b> - {{ h.user || '-' }} <small>{{ h.at }}</small><div v-if="h.reason">{{ h.reason }}</div></li></ul></section>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, t: Object, legs: Array, timeline: Array, history: Array, can: Object },
  data() { return { asking: '', reason: '' }; },
  methods: {
    trim(r) { return String(r).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''); },
    act(a) { this.$inertia.post(route('acct.transfers.' + a, this.t.id), {}, { preserveScroll: true }); },
    ask(a) { this.asking = a; this.reason = ''; },
    send() { this.$inertia.post(route('acct.transfers.' + this.asking, this.t.id), { reason: this.reason }, { preserveScroll: true, onSuccess: () => (this.asking = '') }); },
  },
};
</script>
