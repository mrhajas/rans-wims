<template>
  <admin-layout :title="t.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="t.direction === 'in' ? 'in' : 'out'" :title="t.reference" :subtitle="t.description">
        <Link :href="route('acct.transactions.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <Link v-if="can.edit" :href="route('acct.transactions.edit', t.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
              <acct-export type="transaction_detail" :params="{ record_id: t.id }" :sheets="false" />
      </page-header>

      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div><div class="ac-cur-code">{{ t.direction === 'in' ? $t('Money in') : $t('Money out') }} - {{ $t(t.kind) }}</div><div class="ac-cur-total" :class="t.direction === 'in' ? 'ac-in' : 'ac-out'">{{ t.direction === 'in' ? '+' : '-' }}{{ mc(t.amount, t.currency) }}</div>
            <span class="lg-chip" :class="statusChip(t.status)">{{ t.status_label }}</span> <span v-if="t.reconciled === 'Yes' || t.reconciled === $t('Yes')" class="lg-chip lg-chip-cyan">{{ $t('Reconciled') }}</span>
            <span v-if="t.required_approvals" class="lg-chip lg-chip-slate" style="margin-left: 4px">{{ $t('Approvals') }} {{ t.approvals }}</span></div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="post('submit')"><icons name="send" class="w-4 h-4" />{{ $t('Submit for approval') }}</button>
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="post('approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.post" type="button" class="lg-btn lg-btn-green" @click="post('post')"><icons name="tick" class="w-4 h-4" />{{ $t('Post to balance') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.reverse" type="button" class="lg-btn lg-btn-soft" @click="ask('reverse')"><icons name="refresh" class="w-4 h-4" />{{ $t('Reverse') }}</button>
            <button v-if="can.cancel" type="button" class="lg-btn t-red" @click="ask('cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel it') }}</button>
          </div>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="send"><input v-model="reason" :placeholder="reasonLabel" required /><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="asking = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="t.reject_reason && t.status === 'rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}: {{ t.reject_reason }}</div>
        <div v-if="t.cancel_reason" class="lg-note lg-note-amber" style="margin-top: 10px">{{ $t('Cancelled') }}: {{ t.cancel_reason }}</div>
        <div v-if="reversal" class="lg-note lg-note-blue" style="margin-top: 10px">{{ $t('Reversed by') }} <Link :href="route('acct.transactions.show', reversal.id)">{{ reversal.reference }}</Link>.</div>
        <div v-if="reverses" class="lg-note lg-note-blue" style="margin-top: 10px">{{ $t('This reverses') }} <Link :href="route('acct.transactions.show', reverses.id)">{{ reverses.reference }}</Link>. <span v-if="t.reason">{{ t.reason }}</span></div>
        <div v-if="t.transfer_id" class="lg-note lg-note-blue" style="margin-top: 10px">{{ $t('This is part of a transfer.') }} <Link :href="route('acct.transfers.show', t.transfer_id)">{{ $t('Open the transfer') }}</Link></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv">
          <div><dt>{{ $t('Account') }}</dt><dd><Link :href="route('acct.accounts.show', t.account_id)">{{ t.account }}</Link></dd></div>
          <div><dt>{{ $t('Date') }}</dt><dd>{{ $date(t.date) }}<span v-if="t.txn_time"> {{ String(t.txn_time).slice(0, 5) }}</span></dd></div>
          <div v-if="t.value_date"><dt>{{ $t('Value date') }}</dt><dd>{{ $date(t.value_date) }}</dd></div>
          <div v-if="t.category"><dt>{{ $t('Category') }}</dt><dd>{{ t.category }}</dd></div>
          <div v-if="t.method_label"><dt>{{ $t('Payment method') }}</dt><dd>{{ t.method_label }}</dd></div>
          <div v-for="(v, k) in (t.method_details || {})" :key="k"><dt>{{ k.replace('_', ' ') }}</dt><dd>{{ v }}</dd></div>
          <div v-if="t.counterparty"><dt>{{ t.direction === 'in' ? $t('Received from') : $t('Paid to') }}</dt><dd>{{ t.counterparty }}</dd></div>
          <div v-if="t.receipt_no"><dt>{{ $t('Receipt number') }}</dt><dd>{{ t.receipt_no }}</dd></div>
          <div v-if="t.invoice_no"><dt>{{ $t('Invoice number') }}</dt><dd>{{ t.invoice_no }}</dd></div>
          <div v-if="t.voucher_no"><dt>{{ $t('Voucher number') }}</dt><dd>{{ t.voucher_no }}</dd></div>
          <div v-if="t.external_ref"><dt>{{ $t('External reference') }}</dt><dd>{{ t.external_ref }}</dd></div>
          <div v-if="t.project"><dt>{{ $t('Project') }}</dt><dd>{{ t.project }}</dd></div>
          <div v-if="t.department"><dt>{{ $t('Department') }}</dt><dd>{{ t.department }}</dd></div>
          <div v-if="t.cost_centre"><dt>{{ $t('Cost centre') }}</dt><dd>{{ t.cost_centre }}</dd></div>
          <div v-if="t.allocation"><dt>{{ $t('Funds allocation') }}</dt><dd><Link :href="route('acct.funds.show', t.fund_allocation_id)">{{ t.allocation }}</Link></dd></div>
          <div v-if="t.source_module"><dt>{{ $t('Came from') }}</dt><dd>{{ t.source_module }} {{ t.source_type }} #{{ t.source_id }}</dd></div>
          <div v-if="t.exchange_rate"><dt>{{ $t('Rate used') }}</dt><dd>{{ t.exchange_rate }}</dd></div>
          <div><dt>{{ $t('Created by') }}</dt><dd>{{ t.created_by_name || '-' }} <small class="lg-subtext">{{ t.created_at }}</small></dd></div>
          <div v-if="t.submitted_by_name"><dt>{{ $t('Submitted by') }}</dt><dd>{{ t.submitted_by_name }}</dd></div>
          <div v-if="t.posted_by_name"><dt>{{ $t('Posted by') }}</dt><dd>{{ t.posted_by_name }} <small class="lg-subtext">{{ t.posted_at }}</small></dd></div>
        </dl>
        <p v-if="t.reason && !reverses" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Reason') }}:</b> {{ t.reason }}</p>
        <p v-if="t.notes" class="lg-sub"><b>{{ $t('Notes') }}:</b> {{ t.notes }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Supporting Documents') }}</h2></div></header>
        <acct-file-list :files="attachments" :download-url="f => route('acct.transactions.download', [t.id, f.id])" :doc-types="doc_types" :removable="['draft', 'rejected'].includes(t.status)" @remove="removeFile" />
        <form v-if="can.attach" class="ac-attach" @submit.prevent="upload">
          <div class="ac-f" style="max-width: 320px"><label>{{ $t('Type of document') }}</label><acct-select v-model="up.doc_type" :options="doc_types" /></div>
          <acct-dropzone v-model="up.files" :hint="$t('PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ err('files') || err('files.0') }}</span>
          <div><button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.files.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ up.processing ? $t('Uploading...') : $t('Attach :n file(s)', { n: up.files.length }) }}</button></div>
        </form>
      </section>

      <section class="lg-card" v-if="timeline.length">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Approval Trail') }}</h2></div></header>
        <ul class="ac-timeline"><li v-for="(x, i) in timeline" :key="i"><b>{{ $t(x.action) }}</b> - {{ x.user }} <small>{{ x.at }}</small><div v-if="x.comment">{{ x.comment }}</div></li></ul>
      </section>

      <section class="lg-card" v-if="history && history.length">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="ac-timeline"><li v-for="(h, i) in history" :key="i"><b>{{ h.event }}</b> - {{ h.user || '-' }} <small>{{ h.at }}</small><div v-if="h.reason">{{ h.reason }}</div></li></ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '../AcctSelect.vue';
import AcctDropzone from '../AcctDropzone.vue';
import AcctFileList from '../AcctFileList.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, AcctDropzone, AcctFileList, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, t: Object, timeline: Array, attachments: Array, history: Array, reversal: Object, reverses: Object, doc_types: Array, can: Object },
  data() { return { asking: '', reason: '', busy: false, up: this.$inertia.form({ files: [], doc_type: 'receipt' }) }; },
  computed: { reasonLabel() { return { reject: this.$t('Why is it rejected? (required)'), reverse: this.$t('Why is it reversed? (required)'), cancel: this.$t('Why is it cancelled? (required)') }[this.asking]; } },
  methods: {
    post(action) { this.busy = true; this.$inertia.post(route('acct.transactions.' + action, this.t.id), {}, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    ask(a) { this.asking = a; this.reason = ''; },
    send() { this.busy = true; this.$inertia.post(route('acct.transactions.' + this.asking, this.t.id), { reason: this.reason }, { preserveScroll: true, onFinish: () => (this.busy = false), onSuccess: () => (this.asking = '') }); },
    upload() { this.up.post(route('acct.transactions.attach', this.t.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('files') }); },
    removeFile(a) { this.$inertia.delete(route('acct.transactions.remove-file', [this.t.id, a.id]), { preserveScroll: true }); },
  },
};
</script>
