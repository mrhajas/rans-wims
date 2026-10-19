<template>
  <admin-layout :title="m.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="truck" :title="m.reference" :subtitle="m.kind_label + ' - ' + (m.contact || '')">
        <Link :href="route('external-stock.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <a v-if="can.print" :href="route('external-stock.print', m.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
        <a v-if="can.print" :href="route('external-stock.pdf', m.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
        <Link v-if="can.edit" :href="route('external-stock.edit', m.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
      </page-header>

      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div>
            <span class="lg-chip" :class="statusChip(m.status)">{{ m.status_label }}</span> <span class="lg-chip" :class="{ withdrawal: 'lg-chip-red', deposit: 'lg-chip-green', opening: 'lg-chip-violet' }[m.kind]" style="text-transform: none">{{ m.kind_label }}</span>
            <div class="ac-cur-total" style="margin-top: 6px">{{ qty(m.total_qty) }} <small class="lg-subtext">{{ $t('units in :n line(s)', { n: m.lines.length }) }}</small></div>
          </div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="approve"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.cancel" type="button" class="lg-btn t-red" @click="ask('cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel it') }}</button>
          </div>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="send"><input v-model="reason" :placeholder="asking === 'reject' ? $t('Why is it rejected? (required)') : $t('Why is it cancelled? (required)')" required /><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="asking = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="m.status === 'Pending Approval'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Waiting for approval. The balance of :p does not change until it is approved.', { p: m.contact }) }}</span></div>
        <div v-if="m.status === 'Rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}<template v-if="m.decided_by"> {{ $t('by') }} {{ m.decided_by }}</template>: {{ m.decision_remarks }}</div>
        <div v-if="m.status === 'Cancelled'" class="lg-note lg-note-amber" style="margin-top: 10px">{{ $t('Cancelled') }}: {{ m.cancel_reason }}</div>
      </section>

      <section v-if="m.status === 'Approved' || gate_pass.note || delivery_note.note || transport_route.route" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Paperwork and Transport') }}</h2><p class="lg-card-sub">{{ $t('Issued from the approved movement. The Gate Pass holds the driver and vehicle; the Transport Route is logged from it.') }}</p></div></header>
        <div class="ex-paper"><delivery-note-action :state="delivery_note" /><gate-pass-action :state="gate_pass" /><transport-route-action :state="transport_route" /></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv">
          <div><dt>{{ $t('External person') }}</dt><dd>{{ m.contact }} <small v-if="m.contact_phone" class="lg-subtext">{{ m.contact_phone }}</small></dd></div>
          <div v-if="m.location"><dt>{{ $t('Location') }}</dt><dd>{{ m.location }}</dd></div>
          <div><dt>{{ $t('Project(s) / Warehouse(s)') }}</dt><dd>{{ m.warehouse }}</dd></div>
          <div><dt>{{ $t('Date') }}</dt><dd>{{ $date(m.date) }}</dd></div>
          <div v-if="m.collector_name"><dt>{{ m.kind === 'withdrawal' ? $t('Collected by') : $t('Brought by') }}</dt><dd>{{ m.collector_name }}</dd></div>
          <div v-if="m.collector_phone"><dt>{{ $t('Mobile') }}</dt><dd>{{ m.collector_phone }}</dd></div>
          <div v-if="m.collector_id_no"><dt>{{ $t('ID / licence number') }}</dt><dd>{{ m.collector_id_no }}</dd></div>
          <div v-if="m.driver"><dt>{{ $t('Driver') }}</dt><dd>{{ m.driver }}</dd></div>
          <div v-if="m.vehicle"><dt>{{ $t('Vehicle') }}</dt><dd>{{ m.vehicle }}</dd></div>
          <div><dt>{{ $t('Recorded by') }}</dt><dd>{{ m.by }} <small class="lg-subtext">{{ m.created_at }}</small></dd></div>
          <div v-if="m.decided_by && m.status === 'Approved'"><dt>{{ $t('Approved by') }}</dt><dd>{{ m.decided_by }} <small class="lg-subtext">{{ m.decided_at }}</small></dd></div>
        </dl>
        <p v-if="m.purpose" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Purpose') }}:</b> {{ m.purpose }}</p>
        <p v-if="m.decision_remarks && m.status === 'Approved'" class="lg-sub"><b>{{ $t('Approval remarks') }}:</b> {{ m.decision_remarks }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2><p class="lg-card-sub">{{ $t('Supporting documents for this record.') }}</p></div></header>
        <acct-file-list :files="attachments" :download-url="f => route('external-stock.download', [m.id, f.id])" :removable="can.remove_file" @remove="removeFile" />
        <form v-if="can.attach" class="ac-attach" @submit.prevent="upload">
          <acct-dropzone v-model="up.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ ($page.props.errors || {}).attachments || ($page.props.errors || {})['attachments.0'] }}</span>
          <div><button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.attachments.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ up.processing ? $t('Uploading...') : $t('Attach :n file(s)', { n: up.attachments.length }) }}</button></div>
        </form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Items') }}</h2></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Item') }}</th><th>{{ $t('Code') }}</th><th class="ac-num">{{ $t('Quantity') }}</th><th class="ac-num">{{ $t('Held now') }}</th><th class="ac-num">{{ $t('Promised') }}</th></tr></thead>
          <tbody><tr v-for="l in m.lines" :key="l.id"><td :data-label="$t('Item')" class="lg-main">{{ l.name }}</td><td :data-label="$t('Code')">{{ l.code }}</td><td :data-label="$t('Quantity')" class="ac-num"><b :class="m.kind === 'withdrawal' ? 'ac-out' : 'ac-in'">{{ m.kind === 'withdrawal' ? '-' : '+' }}{{ qty(l.quantity) }}</b> {{ l.unit }}</td>
            <td :data-label="$t('Held now')" class="ac-num">{{ positions[l.item_id] ? qty(positions[l.item_id].balance) : '-' }}</td><td :data-label="$t('Promised')" class="ac-num">{{ positions[l.item_id] ? qty(positions[l.item_id].reserved) : '-' }}</td></tr></tbody></table></div>
        <p class="lg-sub" style="margin-top: 8px">{{ $t('"Held now" is what :p holds in this warehouse after all approved movements; "Promised" is in waiting Stock Outs.', { p: m.contact }) }}</p>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import GatePassAction from '@/Shared/GatePassAction.vue';
import DeliveryNoteAction from '@/Shared/DeliveryNoteAction.vue';
import TransportRouteAction from '@/Shared/TransportRouteAction.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';

export default {
  components: { AdminLayout, PageHeader, Link, GatePassAction, DeliveryNoteAction, TransportRouteAction, AcctDropzone, AcctFileList },
  props: { m: Object, positions: Object, gate_pass: Object, delivery_note: Object, transport_route: Object, attachments: Array, can: Object },
  data() { return { asking: '', reason: '', busy: false, up: this.$inertia.form({ attachments: [] }) }; },
  methods: {
    qty(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 4 }); },
    statusChip(s) { return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    upload() { this.up.post(route('external-stock.attach', this.m.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('attachments') }); },
    removeFile(f) { this.$inertia.delete(route('external-stock.remove-file', [this.m.id, f.id]), { preserveScroll: true }); },
    approve() { this.busy = true; this.$inertia.post(route('external-stock.approve', this.m.id), {}, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    ask(a) { this.asking = a; this.reason = ''; },
    send() { this.busy = true; this.$inertia.post(route('external-stock.' + this.asking, this.m.id), { reason: this.reason }, { preserveScroll: true, onFinish: () => (this.busy = false), onSuccess: () => (this.asking = '') }); },
  },
};
</script>

<style scoped>
.ex-paper { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
</style>
