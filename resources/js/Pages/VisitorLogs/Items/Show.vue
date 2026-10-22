<template>
  <admin-layout :title="item.reference">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="email" :title="item.reference" :subtitle="[item.type, item.masked ? $t('Confidential') : item.subject].filter(Boolean).join(' · ')">
        <Link :href="$can('read-incoming-items') ? route('visitor-logs.items.index') : route('dashboard')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <button v-if="item.open && can.handover" type="button" class="lg-btn lg-btn-primary" @click="handOpen = true"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Hand Over') }}</span></button>
        <button v-if="item.open && can.status" type="button" class="lg-btn lg-btn-soft" @click="statusOpen = true"><icons name="refresh" class="w-4 h-4" /><span>{{ $t('Change Status') }}</span></button>
        <Link v-if="can.update && (item.open || can.correct) && !item.masked" :href="route('visitor-logs.items.edit', item.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
        <button v-if="!item.open && can.correct" type="button" class="lg-btn lg-btn-soft" @click="openCorrect"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Correct Delivery Record') }}</span></button>
      </page-header>

      <div v-if="errors.status || errors.handed_to" class="lg-note lg-note-red" role="alert" style="margin-bottom: 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ errors.status || errors.handed_to }}</span></div>
      <div v-if="item.masked" class="lg-note lg-note-amber" style="margin-bottom: 12px"><icons name="lock-closed" class="w-4 h-4" /><span>{{ $t('This item is confidential: its sender and subject are hidden from you.') }}</span></div>

      <section class="lg-card">
        <div class="vl-hero">
          <span class="lg-chip" :class="chip" style="text-transform: none; font-size: 0.8rem">{{ item.status }}</span>
          <span v-if="item.confidential" class="lg-chip lg-chip-red" style="text-transform: none"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Confidential') }}</span>
          <div class="vl-times">
            <div><small>{{ $t('Received') }}</small><b>{{ $datetime(item.received_at) }}</b></div>
            <div><small>{{ $t('For') }}</small><b>{{ item.recipient }}</b></div>
            <div v-if="item.open && item.waiting_days !== null"><small>{{ $t('Waiting') }}</small><b>{{ $t(':n day(s)', { n: item.waiting_days }) }}</b></div>
            <div v-if="!item.open && item.handed_at"><small>{{ $t('Handed over') }}</small><b>{{ $datetime(item.handed_at) }}</b></div>
          </div>
        </div>
      </section>

      <div class="vl-two">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="email" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('The Item') }}</h2></div></header>
          <dl class="ac-kv">
            <div v-if="item.project"><dt>{{ $t('Attended from') }}</dt><dd>{{ item.project }}</dd></div>
            <div><dt>{{ $t('Type') }}</dt><dd>{{ item.type || '-' }}</dd></div>
            <div><dt>{{ $t('Subject') }}</dt><dd>{{ item.masked ? $t('Hidden') : item.subject || '-' }}</dd></div>
            <div><dt>{{ $t('Number of items') }}</dt><dd>{{ item.count }}</dd></div>
            <div v-if="!item.masked && item.condition"><dt>{{ $t('Condition') }}</dt><dd>{{ item.condition }}</dd></div>
            <div><dt>{{ $t('Recipient') }}</dt><dd>{{ item.recipient }}</dd></div>
            <div v-if="item.department"><dt>{{ $t('Department') }}</dt><dd>{{ item.department }}</dd></div>
            <div><dt>{{ $t('Received by') }}</dt><dd>{{ item.received_by || '-' }}</dd></div>
            <div v-if="item.visit"><dt>{{ $t('Visit') }}</dt><dd><Link :href="route('visitor-logs.visits.show', item.visit.id)" target="_blank" class="vl-link">{{ item.visit.reference }}</Link></dd></div>
          </dl>
          <p v-if="item.remarks" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Remarks') }}:</b> {{ item.remarks }}</p>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="user" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Sender and Delivery') }}</h2></div></header>
          <p v-if="item.masked" class="lg-sub">{{ $t('Hidden: this item is confidential.') }}</p>
          <dl v-else class="ac-kv">
            <div><dt>{{ $t('Sender') }}</dt><dd>{{ item.sender || '-' }}</dd></div>
            <div v-if="item.sender_contact"><dt>{{ $t('Sender contact') }}</dt><dd>{{ item.sender_contact }}</dd></div>
            <div v-if="item.courier"><dt>{{ $t('Courier') }}</dt><dd>{{ item.courier }}</dd></div>
            <div v-if="item.tracking_number"><dt>{{ $t('Tracking number') }}</dt><dd>{{ item.tracking_number }}</dd></div>
            <div v-if="item.visitor"><dt>{{ $t('Brought by') }}</dt><dd>{{ item.visitor.name }}</dd></div>
          </dl>
          <template v-if="!item.open && item.handed_to">
            <h3 class="vl-sub">{{ $t('Hand-over') }}</h3>
            <dl class="ac-kv"><div><dt>{{ $t('Handed to') }}</dt><dd>{{ item.handed_to }}</dd></div><div><dt>{{ $t('When') }}</dt><dd>{{ item.handed_at ? $datetime(item.handed_at) : '-' }}</dd></div><div><dt>{{ $t('Released by') }}</dt><dd>{{ item.released_by || '-' }}</dd></div><div v-if="item.handover_remarks"><dt>{{ $t('Remarks') }}</dt><dd>{{ item.handover_remarks }}</dd></div></dl>
          </template>
        </section>
      </div>

      <section v-if="can.seeFiles" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Scans and Photos') }}</h2><p class="lg-card-sub">{{ $t('Opening or downloading a file is logged.') }}</p></div></header>
        <acct-file-list :files="files" :download-url="f => route('visitor-logs.items.download', [item.id, f.id])" :empty="$t('No file attached.')" />
        <form v-if="can.files" class="vl-attach" @submit.prevent="attach"><attach-camera v-model="newFiles" :hint="$t('PDF, images and office files up to 8 MB each.')" /><button v-if="newFiles.length" type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="upload" class="w-4 h-4" />{{ $t('Add the files') }}</button></form>
      </section>

      <section v-if="events.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2><p class="lg-card-sub">{{ $t('Every status of this item, who changed it and when. Nothing here can be edited.') }}</p></div></header>
        <ol class="vl-tl">
          <li v-for="e in events" :key="e.id"><span class="vl-tl__dot" :class="'is-' + e.event"><icons :name="icon(e.event)" class="w-3 h-3" /></span>
            <div><b>{{ label(e.event) }}</b><small>{{ $datetime(e.at) }}<template v-if="e.by"> · {{ e.by }}</template></small><p v-if="changes(e)">{{ changes(e) }}</p><p v-if="e.reason" class="vl-reason">{{ e.event === 'received' || e.event === 'status_changed' ? '' : $t('Reason') + ': ' }}{{ e.reason }}</p></div></li>
        </ol>
      </section>
    </div>

    <travel-modal :show="statusOpen" :title="$t('Change the Status')" :subtitle="$t('Notified, ready for collection, returned to sender... A hand-over is recorded with Hand Over.')" icon="refresh" :busy="busy" @close="statusOpen = false">
      <form id="vl-status" @submit.prevent="sendStatus"><div class="tm-grid">
        <div class="ac-f tm-full"><label>{{ $t('New status') }}</label><acct-select v-model="st.status" :options="statusOptions" :placeholder="$t('Choose...')" /></div>
        <div class="ac-f tm-full"><label>{{ $t('Remarks') }} <b v-if="needsRemark" class="vl-req">*</b></label><input v-model="st.remarks" maxlength="1000" :placeholder="needsRemark ? $t('Say why, for example to whom it was returned') : ''" /><span class="ac-err">{{ err('status') || err('remarks') }}</span></div>
      </div></form>
      <template #footer><button type="button" class="lg-btn lg-btn-soft" @click="statusOpen = false">{{ $t('Cancel') }}</button><button type="submit" form="vl-status" class="lg-btn lg-btn-primary" :disabled="busy || !st.status || (needsRemark && !st.remarks.trim())"><icons name="tick" class="w-4 h-4" />{{ $t('Save Status') }}</button></template>
    </travel-modal>

    <travel-modal :show="handOpen" :title="$t('Hand the Item Over')" :subtitle="$t('Record who received it. This is what marks it delivered or collected.')" icon="tick" :busy="busy" @close="handOpen = false">
      <form id="vl-hand" @submit.prevent="sendHand"><div class="tm-grid">
        <div class="ac-f tm-full"><label>{{ $t('Received by (the recipient or the authorized collector)') }} <b class="vl-req">*</b></label><input v-model="h.handed_to" maxlength="190" required autocomplete="off" /><span class="ac-err">{{ err('handed_to') }}</span></div>
        <div v-if="handedStatuses.length > 1" class="ac-f tm-full"><label>{{ $t('How') }}</label><acct-select v-model="h.status" :options="handedStatuses.map(s => ({ value: s.name, label: s.name }))" /></div>
        <div class="ac-f tm-full"><label>{{ $t('Remarks') }}</label><input v-model="h.remarks" maxlength="1000" /></div>
      </div></form>
      <template #footer><button type="button" class="lg-btn lg-btn-soft" @click="handOpen = false">{{ $t('Cancel') }}</button><button type="submit" form="vl-hand" class="lg-btn lg-btn-primary" :disabled="busy || !h.handed_to.trim()"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm Hand-over') }}</button></template>
    </travel-modal>

    <travel-modal :show="corrOpen" :title="$t('Correct the Delivery Record')" :subtitle="$t('The old and the new values are kept in the history, with your reason.')" icon="clock" tone="amber" :busy="busy" @close="corrOpen = false">
      <form id="vl-corr" @submit.prevent="sendCorrect"><div class="tm-grid">
        <div class="ac-f tm-full"><label>{{ $t('Received by') }} <b class="vl-req">*</b></label><input v-model="cr.handed_to" maxlength="190" required /><span class="ac-err">{{ err('handed_to') }}</span></div>
        <div class="ac-f"><label>{{ $t('Date') }}</label><date-picker v-model="cr.date" :clearable="false" /></div>
        <div class="ac-f"><label>{{ $t('Time') }}</label><time-field v-model="cr.time" /></div>
        <div class="ac-f tm-full"><label>{{ $t('Reason') }} <b class="vl-req">*</b></label><input v-model="cr.reason" maxlength="1000" required :placeholder="$t('Why is the record being corrected?')" /><span class="ac-err">{{ err('reason') || err('handed_at') }}</span></div>
      </div></form>
      <template #footer><button type="button" class="lg-btn lg-btn-soft" @click="corrOpen = false">{{ $t('Cancel') }}</button><button type="submit" form="vl-corr" class="lg-btn lg-btn-primary" :disabled="busy || !cr.reason.trim() || !cr.handed_to.trim()"><icons name="tick" class="w-4 h-4" />{{ $t('Save Correction') }}</button></template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AttachCamera from '../Parts/AttachCamera.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import TimeField from '../Parts/TimeField.vue';
import { itemChip, eventLabel, eventIcon, changeText } from '../Parts/vl.js';

export default {
  components: { Link, AdminLayout, PageHeader, DatePicker, AcctSelect, AttachCamera, AcctFileList, TravelModal, TimeField },
  props: { item: Object, events: Array, files: Array, statuses: Array, can: Object, now: String },
  data() {
    const handed = this.statuses.filter(s => s.flag === 'handed');
    return { busy: false, statusOpen: false, handOpen: false, corrOpen: false, newFiles: [], st: { status: '', remarks: '' }, h: { handed_to: this.item.recipient || '', status: handed[0] ? handed[0].name : '', remarks: '' }, cr: { handed_to: '', date: '', time: '', reason: '' } };
  },
  computed: {
    errors() { return this.$page.props.errors || {}; },
    chip() { return itemChip(this.item.flag, this.item.open); },
    statusOptions() { return this.statuses.filter(s => s.flag !== 'handed' && s.name !== this.item.status).map(s => ({ value: s.name, label: s.name })); },
    needsRemark() { const s = this.statuses.find(x => x.name === this.st.status); return !!s && s.flag === 'closed'; },
    handedStatuses() { return this.statuses.filter(s => s.flag === 'handed'); },
  },
  methods: {
    icon: eventIcon,
    label(e) { return eventLabel(k => this.$t(k), e); },
    changes(e) { return ['received', 'status_changed', 'handed_over', 'corrected', 'edited'].includes(e.event) ? changeText(k => this.$t(k), e) : ''; },
    err(k) { return this.errors[k]; },
    post(name, data, done, opts = {}) { this.busy = true; this.$inertia.post(route(name, this.item.id), data, { preserveScroll: true, onSuccess: done, onFinish: () => (this.busy = false), ...opts }); },
    sendStatus() { this.post('visitor-logs.items.status', this.st, () => { this.statusOpen = false; this.st = { status: '', remarks: '' }; }); },
    sendHand() { this.post('visitor-logs.items.handover', this.h, () => (this.handOpen = false)); },
    openCorrect() { const a = this.item.handed_at || this.now; this.cr = { handed_to: this.item.handed_to || '', date: a.slice(0, 10), time: a.slice(11, 16), reason: '' }; this.corrOpen = true; },
    sendCorrect() { this.post('visitor-logs.items.correct', { handed_to: this.cr.handed_to, handed_at: this.cr.date + ' ' + this.cr.time, reason: this.cr.reason }, () => (this.corrOpen = false)); },
    attach() { this.post('visitor-logs.items.attach', { attachments: this.newFiles }, () => (this.newFiles = []), { forceFormData: true }); },
  },
};
</script>

<style scoped>
.vl-req { color: #dc2626; }
.vl-hero { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 24px; }
.vl-times { display: flex; flex-wrap: wrap; gap: 12px 32px; margin-left: 8px; }
.vl-times > div { display: grid; gap: 2px; }
.vl-times small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.vl-times b { font-size: 1.05rem; }
.vl-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
.vl-two > .lg-card { margin-bottom: 0; }
@media (max-width: 900px) { .vl-two { grid-template-columns: 1fr; } .vl-two > .lg-card { margin-bottom: 16px; } .vl-times { margin-left: 0; } }
.vl-link { font-weight: 800; color: #0369a1; text-decoration: underline; }
.vl-sub { margin: 14px 0 4px; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.vl-attach { display: grid; gap: 10px; margin-top: 10px; }
.vl-tl { list-style: none; margin: 0; padding: 0; display: grid; gap: 0; }
.vl-tl > li { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 12px; padding-bottom: 14px; position: relative; }
.vl-tl > li:not(:last-child)::before { content: ''; position: absolute; left: 13px; top: 28px; bottom: 0; width: 2px; background: var(--lg-line, #e3e9f2); }
.vl-tl__dot { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; color: #fff; background: #64748b; }
.vl-tl__dot.is-received { background: #0369a1; } .vl-tl__dot.is-handed_over { background: #059669; } .vl-tl__dot.is-corrected { background: #d97706; }
.vl-tl b { display: block; } .vl-tl small { display: block; color: var(--lg-mute, #64748b); font-size: 0.78rem; }
.vl-tl p { margin: 3px 0 0; font-size: 0.84rem; overflow-wrap: anywhere; } .vl-reason { font-style: italic; color: #92400e; }
</style>
