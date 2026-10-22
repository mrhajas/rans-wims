<template>
  <admin-layout :title="visit.reference">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="in" :title="visit.reference" :subtitle="visit.visitor + (visit.company ? ' · ' + visit.company : '')">
        <Link :href="$can('read-visitor-logs') ? route('visitor-logs.visits.index') : route('dashboard')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <button v-if="visit.status === 'Checked In' && can.checkout" type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="checkout"><icons name="out" class="w-4 h-4" /><span>{{ $t('Check Out') }}</span></button>
        <Link v-if="can.update && visit.status !== 'Cancelled'" :href="route('visitor-logs.visits.edit', visit.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
        <Link v-if="can.itemCreate" :href="route('visitor-logs.items.create', { visit_id: visit.id })" class="lg-btn lg-btn-soft"><icons name="email" class="w-4 h-4" /><span>{{ $t('Register an Item') }}</span></Link>
        <button v-if="can.correct && visit.status !== 'Cancelled'" type="button" class="lg-btn lg-btn-soft" @click="openCorrect"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Correct Times') }}</span></button>
        <button v-if="can.cancel && visit.status !== 'Cancelled'" type="button" class="lg-btn t-red" @click="cancelOpen = true"><icons name="x-circle" class="w-4 h-4" /><span>{{ $t('Cancel Visit') }}</span></button>
      </page-header>

      <div v-if="errors.checkout" class="lg-note lg-note-red" role="alert" style="margin-bottom: 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ errors.checkout }}</span></div>
      <div v-if="visit.status === 'Cancelled'" class="lg-note lg-note-red" style="margin-bottom: 12px"><icons name="x-circle" class="w-4 h-4" /><span>{{ $t('Cancelled') }}<template v-if="visit.cancelled_at"> {{ $datetime(visit.cancelled_at) }}</template>: {{ visit.cancel_reason }}</span></div>
      <div v-else-if="longStay" class="lg-note lg-note-amber" style="margin-bottom: 12px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('This visitor has been on the premises for :d, more than the :h hour(s) set in the settings. Check whether they have left.', { d: visit.duration, h: overstayHours }) }}</span></div>

      <section class="lg-card">
        <div class="vl-hero">
          <span class="lg-chip" :class="chip(visit.status)" style="text-transform: none; font-size: 0.8rem">{{ $t(visit.status) }}</span>
          <div class="vl-times">
            <div><small>{{ $t('Check-in') }}</small><b>{{ $datetime(visit.check_in_at) }}</b></div>
            <div><small>{{ $t('Check-out') }}</small><b>{{ visit.check_out_at ? $datetime(visit.check_out_at) : '-' }}</b></div>
            <div><small>{{ visit.status === 'Checked In' ? $t('On premises for') : $t('Time on premises') }}</small><b :class="{ 'vl-long': longStay }">{{ visit.duration || '-' }}</b></div>
          </div>
        </div>
      </section>

      <div class="vl-two">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('The Visitor') }}</h2></div></header>
          <dl class="ac-kv">
            <div><dt>{{ $t('Name') }}</dt><dd><Link v-if="can.visitorPage && visitor" :href="route('visitor-logs.visitors.show', visitor.id)" target="_blank" class="vl-link">{{ visit.visitor }}</Link><template v-else>{{ visit.visitor }}</template></dd></div>
            <div v-if="visitor"><dt>{{ $t('Reference') }}</dt><dd>{{ visitor.reference }}</dd></div>
            <div v-if="visit.company"><dt>{{ $t('Company') }}</dt><dd>{{ visit.company }}</dd></div>
            <div v-if="visit.phone"><dt>{{ $t('Phone') }}</dt><dd>{{ visit.phone }}</dd></div>
            <div v-if="visitor && visitor.category"><dt>{{ $t('Category') }}</dt><dd>{{ visitor.category }}</dd></div>
            <div v-if="visitor && visitor.visits !== undefined && visitor.visits !== null"><dt>{{ $t('Visits so far') }}</dt><dd>{{ visitor.visits }}</dd></div>
          </dl>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('The Visit') }}</h2></div></header>
          <dl class="ac-kv">
            <div v-if="visit.project"><dt>{{ $t('Attended from') }}</dt><dd>{{ visit.project }}</dd></div>
            <div><dt>{{ $t('Person visited') }}</dt><dd>{{ visit.host || '-' }}</dd></div>
            <div v-if="visit.department"><dt>{{ $t('Department') }}</dt><dd>{{ visit.department }}</dd></div>
            <div><dt>{{ $t('Purpose') }}</dt><dd>{{ visit.purpose_name }}<template v-if="visit.purpose_other">: {{ visit.purpose_other }}</template></dd></div>
            <div v-if="visit.appointment_ref"><dt>{{ $t('Appointment') }}</dt><dd>{{ visit.appointment_ref }}</dd></div>
            <div v-if="visit.companions"><dt>{{ $t('Accompanying visitors') }}</dt><dd>{{ visit.companions }}</dd></div>
            <div v-if="visit.vehicle"><dt>{{ $t('Vehicle') }}</dt><dd>{{ visit.vehicle }}</dd></div>
            <div><dt>{{ $t('Checked in by') }}</dt><dd>{{ visit.registered_by || '-' }}</dd></div>
            <div v-if="visit.checked_out_by"><dt>{{ $t('Checked out by') }}</dt><dd>{{ visit.checked_out_by }}</dd></div>
          </dl>
          <p v-if="visit.remarks" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Remarks') }}:</b> {{ visit.remarks }}</p>
        </section>
      </div>

      <section v-if="visit.items.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="email" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Items Received With This Visit') }}</h2></div></header>
        <ul class="vl-list"><li v-for="i in visit.items" :key="i.id"><Link :href="route('visitor-logs.items.show', i.id)" target="_blank" class="vl-link">{{ i.reference }}</Link> <span>{{ i.type }}</span> <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ i.status }}</span></li></ul>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Documents') }}</h2></div></header>
        <acct-file-list :files="files" :download-url="f => route('visitor-logs.visits.download', [visit.id, f.id])" :empty="$t('No documents attached.')" />
        <form v-if="can.update && visit.status !== 'Cancelled'" class="vl-attach" @submit.prevent="attach"><attach-camera v-model="newFiles" :hint="$t('PDF, images and office files up to 8 MB each.')" /><button v-if="newFiles.length" type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="upload" class="w-4 h-4" />{{ $t('Add the files') }}</button></form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2><p class="lg-card-sub">{{ $t('Everything that happened to this visit, with who did it and when. Nothing here can be edited.') }}</p></div></header>
        <ol class="vl-tl">
          <li v-for="e in events" :key="e.id">
            <span class="vl-tl__dot" :class="'is-' + e.event"><icons :name="icon(e.event)" class="w-3 h-3" /></span>
            <div><b>{{ label(e.event) }}</b><small>{{ $datetime(e.at) }}<template v-if="e.by"> · {{ e.by }}</template></small><p v-if="changes(e)">{{ changes(e) }}</p><p v-if="e.reason" class="vl-reason">{{ $t('Reason') }}: {{ e.reason }}</p></div>
          </li>
        </ol>
      </section>
    </div>

    <travel-modal :show="correctOpen" :title="$t('Correct the Times')" :subtitle="$t('For a time that was recorded wrongly. The old and the new times are kept in the history.')" icon="clock" tone="amber" :busy="busy" @close="correctOpen = false">
      <form id="vl-correct" @submit.prevent="correct">
        <div class="tm-grid">
          <div class="ac-f"><label>{{ $t('Check-in date') }}</label><date-picker v-model="c.in_date" :clearable="false" /></div>
          <div class="ac-f"><label>{{ $t('Check-in time') }}</label><time-field v-model="c.in_time" /></div>
          <template v-if="visit.status === 'Checked Out'"><div class="ac-f"><label>{{ $t('Check-out date') }}</label><date-picker v-model="c.out_date" :clearable="false" /></div><div class="ac-f"><label>{{ $t('Check-out time') }}</label><time-field v-model="c.out_time" /></div></template>
          <div class="ac-f tm-full"><label>{{ $t('Reason') }} <b class="vl-req">*</b></label><input v-model="c.reason" maxlength="1000" required :placeholder="$t('Why is the time being corrected?')" /><span class="ac-err">{{ err('reason') || err('check_in_at') || err('check_out_at') }}</span></div>
        </div>
      </form>
      <template #footer><button type="button" class="lg-btn lg-btn-soft" @click="correctOpen = false">{{ $t('Cancel') }}</button><button type="submit" form="vl-correct" class="lg-btn lg-btn-primary" :disabled="busy || !c.reason.trim()"><icons name="tick" class="w-4 h-4" />{{ $t('Save Correction') }}</button></template>
    </travel-modal>

    <travel-modal :show="cancelOpen" :title="$t('Cancel This Visit')" :subtitle="$t('For a visit that was registered by mistake. It stays in the register and the history.')" icon="x-circle" tone="amber" :busy="busy" @close="cancelOpen = false">
      <form id="vl-cancel" @submit.prevent="cancel"><div class="tm-grid"><div class="ac-f tm-full"><label>{{ $t('Reason') }} <b class="vl-req">*</b></label><input v-model="reason" maxlength="1000" required :placeholder="$t('Why is it cancelled?')" /><span class="ac-err">{{ err('reason') }}</span></div></div></form>
      <template #footer><button type="button" class="lg-btn lg-btn-soft" @click="cancelOpen = false">{{ $t('Keep it') }}</button><button type="submit" form="vl-cancel" class="lg-btn lg-btn-red" :disabled="busy || !reason.trim()"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel Visit') }}</button></template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AttachCamera from '../Parts/AttachCamera.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import TimeField from '../Parts/TimeField.vue';
import { visitChip, eventLabel, eventIcon, changeText } from '../Parts/vl.js';

export default {
  components: { Link, AdminLayout, PageHeader, DatePicker, AttachCamera, AcctFileList, TravelModal, TimeField },
  props: { visit: Object, visitor: Object, events: Array, files: Array, can: Object, overstayHours: Number },
  data() { return { busy: false, correctOpen: false, cancelOpen: false, reason: '', newFiles: [], c: { in_date: '', in_time: '', out_date: '', out_time: '', reason: '' } }; },
  computed: {
    errors() { return this.$page.props.errors || {}; },
    longStay() { return this.visit.status === 'Checked In' && this.overstayHours > 0 && this.visit.minutes >= this.overstayHours * 60; },
  },
  methods: {
    chip: visitChip,
    icon: eventIcon,
    label(e) { return eventLabel(k => this.$t(k), e); },
    changes(e) { return ['checked_in', 'checked_out', 'edited', 'corrected', 'status_changed', 'handed_over'].includes(e.event) ? changeText(k => this.$t(k), e) : ''; },
    err(k) { return this.errors[k]; },
    // the server writes its own timezone into the time ("2026-10-22T09:15:00+03:00"): the first characters are the wall-clock date and time to show and edit
    openCorrect() {
      const v = this.visit;
      this.c = { in_date: v.check_in_at.slice(0, 10), in_time: v.check_in_at.slice(11, 16), out_date: v.check_out_at ? v.check_out_at.slice(0, 10) : '', out_time: v.check_out_at ? v.check_out_at.slice(11, 16) : '', reason: '' };
      this.correctOpen = true;
    },
    post(name, data, done) { this.busy = true; this.$inertia.post(route(name, this.visit.id), data, { preserveScroll: true, onSuccess: done, onFinish: () => (this.busy = false) }); },
    checkout() { this.post('visitor-logs.visits.checkout', {}); },
    correct() { this.post('visitor-logs.visits.correct', { check_in_at: this.c.in_date + ' ' + this.c.in_time, check_out_at: this.c.out_date ? this.c.out_date + ' ' + this.c.out_time : '', reason: this.c.reason }, () => (this.correctOpen = false)); },
    cancel() { this.post('visitor-logs.visits.cancel', { reason: this.reason }, () => { this.cancelOpen = false; this.reason = ''; }); },
    attach() { this.busy = true; this.$inertia.post(route('visitor-logs.visits.attach', this.visit.id), { attachments: this.newFiles }, { preserveScroll: true, forceFormData: true, onSuccess: () => (this.newFiles = []), onFinish: () => (this.busy = false) }); },
  },
};
</script>

<style scoped>
.vl-req { color: #dc2626; }
.vl-hero { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 28px; }
.vl-times { display: flex; flex-wrap: wrap; gap: 12px 32px; }
.vl-times > div { display: grid; gap: 2px; }
.vl-times small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.vl-times b { font-size: 1.05rem; font-variant-numeric: tabular-nums; }
.vl-long { color: #b45309; }
.vl-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
.vl-two > .lg-card { margin-bottom: 0; }
@media (max-width: 900px) { .vl-two { grid-template-columns: 1fr; } .vl-two > .lg-card { margin-bottom: 16px; } }
.vl-link { font-weight: 800; color: #0369a1; text-decoration: underline; }
.vl-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.vl-list li { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; }
.vl-attach { display: grid; gap: 10px; margin-top: 10px; }
.vl-tl { list-style: none; margin: 0; padding: 0; display: grid; gap: 0; }
.vl-tl > li { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 12px; padding-bottom: 14px; position: relative; }
.vl-tl > li:not(:last-child)::before { content: ''; position: absolute; left: 13px; top: 28px; bottom: 0; width: 2px; background: var(--lg-line, #e3e9f2); }
.vl-tl__dot { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; color: #fff; background: #64748b; }
.vl-tl__dot.is-checked_in { background: #059669; } .vl-tl__dot.is-checked_out { background: #0369a1; } .vl-tl__dot.is-corrected { background: #d97706; } .vl-tl__dot.is-cancelled { background: #dc2626; }
.vl-tl b { display: block; } .vl-tl small { display: block; color: var(--lg-mute, #64748b); font-size: 0.78rem; }
.vl-tl p { margin: 3px 0 0; font-size: 0.84rem; overflow-wrap: anywhere; } .vl-reason { font-style: italic; color: #92400e; }
</style>
