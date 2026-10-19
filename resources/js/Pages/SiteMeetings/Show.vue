<template>
  <admin-layout :title="meeting.title">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="calendar" :title="meeting.reference" :subtitle="$t('Site Meetings')">
        <Link :href="route('site-meetings.index', { week: meeting.date })" class="lg-btn lg-btn-soft"><icons name="calendar" class="w-4 h-4" />{{ $t('Schedule') }}</Link>
        <Link v-if="!meeting.deleted_at" :href="route('site-meetings.report', meeting.id)" class="lg-btn lg-btn-primary"><icons name="doc-text" class="w-4 h-4" />{{ $t('Minutes') }}</Link>
        <a v-if="can.print" :href="route('site-meetings.print', meeting.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print meeting sheet')" :aria-label="$t('Print meeting sheet')"><icons name="printer" class="w-4 h-4" /></a>
        <a v-if="can.print" :href="route('site-meetings.pdf', meeting.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download meeting sheet')" :aria-label="$t('Download meeting sheet')"><icons name="download" class="w-4 h-4" /></a>
        <Link v-if="can.create && can.work && !meeting.deleted_at" :href="route('site-meetings.create', { from: meeting.id })" class="lg-btn lg-btn-soft"><icons name="duplicate" class="w-4 h-4" />{{ $t('Duplicate') }}</Link>
        <button v-if="can.edit_this" type="button" class="lg-btn lg-btn-soft" @click="statusOpen = true"><icons name="refresh" class="w-4 h-4" />{{ $t('Change status') }}</button>
        <Link v-if="can.edit_this" :href="route('site-meetings.edit', meeting.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
        <button v-if="can.delete && can.work && !meeting.deleted_at" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="destroy"><icons name="trash" class="w-4 h-4" /></button>
      </page-header>

      <p v-if="can.readonly" class="sm-note sm-c-slate"><icons name="eye-flat" class="w-5 h-5 flex-none" /><span>{{ $t('Read only: you can view this meeting and its minutes, print and download them.') }}</span></p>
      <p v-else-if="!can.work && can.update" class="sm-note sm-c-slate"><icons name="lock-closed" class="w-5 h-5 flex-none" /><span>{{ $t('This meeting belongs to a project you are not assigned to. You can see it, but only the people of that project can work on it.') }}</span></p>
      <p v-if="meeting.deleted_at" class="sm-note sm-c-red"><icons name="trash" class="w-5 h-5 flex-none" /><span>{{ $t('This meeting was deleted.') }}</span></p>
      <p v-if="meeting.status_reason" class="sm-note" :class="'sm-c-' + meeting.status_color">
        <icons name="info" class="w-5 h-5 flex-none" />
        <span><b>{{ meeting.status_label }}:</b> {{ meeting.status_reason }}<template v-if="meeting.postponed_to"> - {{ $t('new date') }}: <b>{{ $date(meeting.postponed_to) }}</b></template></span>
      </p>

      <section class="sm-hero">
        <span class="sm-hero-ico"><icons name="calendar" class="w-7 h-7" /></span>
        <div class="min-w-0 flex-1">
          <div class="sm-hero-kicker">{{ meeting.site.name }}<template v-if="meeting.site.code"> · {{ meeting.site.code }}</template></div>
          <h2>{{ meeting.title }}</h2>
          <div class="sm-hero-chips">
            <span class="sm-chip" :class="'sm-c-' + meeting.status_color">{{ meeting.status_label }}</span>
            <span class="sm-chip" :class="'sm-c-' + meeting.report_color"><icons name="doc-text" class="w-3 h-3" />{{ meeting.report_label }}</span>
            <span class="sm-chip sm-chip-plain"><icons name="calendar" class="w-3 h-3" />{{ $date(meeting.date) }}</span>
            <span v-if="meeting.start" class="sm-chip sm-chip-plain"><icons name="clock" class="w-3 h-3" />{{ meeting.start }}<template v-if="meeting.end"> – {{ meeting.end }}</template></span>
            <span v-if="meeting.venue" class="sm-chip sm-chip-plain"><icons name="map-pin" class="w-3 h-3" />{{ meeting.venue }}</span>
            <span v-if="meeting.series > 1" class="sm-chip sm-c-indigo"><icons name="refresh" class="w-3 h-3" />{{ $t('Weekly series of :n', { n: meeting.series }) }}</span>
          </div>
        </div>
      </section>

      <section class="lg-card sm-card-section">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <div class="sm-kv">
          <div><span class="sm-k">{{ $t('Meeting number') }}</span><span class="sm-v">{{ String(meeting.meeting_no).padStart(2, '0') }}</span></div>
          <div><span class="sm-k">{{ $t('Chaired by') }}</span><span class="sm-v">{{ meeting.chairperson || '—' }}</span></div>
          <div><span class="sm-k">{{ $t('Minutes written by') }}</span><span class="sm-v">{{ meeting.recorder || '—' }}</span></div>
          <div><span class="sm-k">{{ $t('Prepared by') }}</span><span class="sm-v">{{ meeting.prepared_by || '—' }}</span></div>
          <div><span class="sm-k">{{ $t('Created') }}</span><span class="sm-v">{{ $datetime(meeting.created_at) }}</span></div>
          <div v-if="meeting.updated_at"><span class="sm-k">{{ $t('Last changed') }}</span><span class="sm-v">{{ $datetime(meeting.updated_at) }}<template v-if="meeting.updated_by"> · {{ meeting.updated_by }}</template></span></div>
        </div>
      </section>

      <section class="lg-card sm-card-section">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('People invited') }}</h2><p class="lg-card-sub">{{ meeting.attendees.length }} {{ $t('invited') }} · {{ presentCount }} {{ $t('present') }}</p></div>
        </header>
        <div v-if="meeting.attendees.length" class="sm-people">
          <div v-for="a in meeting.attendees" :key="a.id" class="sm-person">
            <span class="sm-avatar" :class="{ 'is-ext': !a.user_id }">{{ initials(a.name) }}</span>
            <span class="sm-person-t"><b>{{ a.name }}</b><small>{{ [a.designation, a.organisation].filter(Boolean).join(' · ') || (a.user_id ? $t('WIMS user') : $t('Outside guest')) }}</small></span>
            <span class="sm-chip" :class="attendanceColor(a.attendance)">{{ $t(a.attendance) }}</span>
          </div>
        </div>
        <p v-else class="sm-hint">{{ $t('Nobody invited yet.') }}</p>
      </section>

      <section v-if="meeting.agenda || meeting.info || meeting.remarks" class="lg-card sm-card-section">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Agenda, information & remarks') }}</h2></div></header>
        <div class="sm-kv sm-stack">
          <div v-if="meeting.agenda"><span class="sm-k">{{ $t('Agenda') }}</span><p class="sm-prose">{{ meeting.agenda }}</p></div>
          <div v-if="meeting.info"><span class="sm-k">{{ $t('Information') }}</span><p class="sm-prose">{{ meeting.info }}</p></div>
          <div v-if="meeting.remarks"><span class="sm-k">{{ $t('Remarks') }}</span><p class="sm-prose">{{ meeting.remarks }}</p></div>
        </div>
      </section>

      <section v-if="meeting.attachments.length" class="lg-card sm-card-section">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2></div></header>
        <ul class="sm-files">
          <li v-for="a in meeting.attachments" :key="a.id" class="sm-file">
            <icons :name="a.is_image ? 'image' : 'doc'" class="w-4 h-4 flex-none" />
            <span class="sm-file-name">{{ a.title }}</span>
            <a v-if="a.is_image || a.is_pdf" :href="a.preview_url" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></a>
            <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
          </li>
        </ul>
      </section>

      <section class="lg-card sm-card-section">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Minutes') }}</h2><p class="lg-card-sub"><span class="sm-chip" :class="'sm-c-' + meeting.report_color">{{ meeting.report_label }}</span></p></div>
          <Link v-if="!meeting.deleted_at" :href="route('site-meetings.report', meeting.id)" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" />{{ can.report_this && meeting.report_status !== 'Finalised' ? $t('Open & fill in') : $t('Open minutes') }}</Link>
          <a v-if="can.print && meeting.report_status !== 'Not started'" :href="route('site-meetings.report.print', meeting.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print minutes')" :aria-label="$t('Print minutes')"><icons name="printer" class="w-4 h-4" /></a>
          <a v-if="can.print && meeting.report_status !== 'Not started'" :href="route('site-meetings.report.pdf', meeting.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download minutes')" :aria-label="$t('Download minutes')"><icons name="download" class="w-4 h-4" /></a>
        </header>
        <div v-if="sections.length" class="sm-secsum">
          <div v-for="s in sections" :key="s.key" class="sm-secsum-i" :class="'sm-c-' + s.color">
            <span class="sm-ico is-sm"><icons :name="s.icon" class="w-4 h-4" /></span>
            <span class="sm-secsum-t">{{ s.title }}</span>
            <span class="sm-chip" :class="s.filled ? 'sm-c-green' : 'sm-c-slate'">{{ s.filled ? s.filled + ' ' + $t(s.type === 'text' ? 'written' : s.filled === 1 ? 'entry' : 'entries') : $t('empty') }}</span>
          </div>
        </div>
        <p v-if="meeting.report_finalised_at" class="sm-hint mt-2">{{ $t('Finalised on') }} {{ $datetime(meeting.report_finalised_at) }}</p>
      </section>

      <section v-if="can.history" class="lg-card sm-card-section">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2><p class="lg-card-sub">{{ $t('Who created, viewed and changed this meeting.') }}</p></div></header>
        <div class="sm-tabs" role="tablist">
          <button type="button" class="sm-tab" :class="{ 'is-on': tab === 'history' }" role="tab" @click="tab = 'history'">{{ $t('Timeline') }} ({{ history.length }})</button>
          <button type="button" class="sm-tab" :class="{ 'is-on': tab === 'viewers' }" role="tab" @click="tab = 'viewers'">{{ $t('Viewed by') }} ({{ viewers.length }})</button>
        </div>
        <div v-if="tab === 'history'" class="sm-timeline">
          <div v-for="h in history" :key="h.id" class="sm-tl">
            <span class="sm-ico is-sm" :class="'sm-c-' + actionColor(h.action)"><icons :name="actionIcon(h.action)" class="w-4 h-4" /></span>
            <div class="sm-tl-body">
              <p>{{ h.description }}</p>
              <div v-if="h.changes && Object.keys(h.changes).length" class="sm-tl-changes">
                <span v-for="(c, k) in changeList(h.changes)" :key="k"><i>{{ c.label }}</i>: {{ c.text }}<br /></span>
              </div>
              <small>{{ $datetime(h.at) }}<template v-if="h.device"> · {{ h.device }}</template><template v-if="h.ip"> · {{ h.ip }}</template></small>
            </div>
          </div>
          <p v-if="!history.length" class="sm-hint">{{ $t('No history yet.') }}</p>
        </div>
        <div v-else class="sm-people">
          <div v-for="v in viewers" :key="v.id" class="sm-person">
            <span class="sm-avatar">{{ initials(v.name) }}</span>
            <span class="sm-person-t"><b>{{ v.name }}</b><small>{{ v.views }} {{ $t(v.views === 1 ? 'view' : 'views') }} · {{ $t('last') }} {{ $datetime(v.last_at) }}</small></span>
          </div>
          <p v-if="!viewers.length" class="sm-hint">{{ $t('Nobody has opened this meeting yet.') }}</p>
        </div>
      </section>
    </div>

    <tec-dialog-modal :show="statusOpen" max-width="md" @close="statusOpen = false">
      <template #title><span class="sm-modal-title"><span class="lg-card-ico lg-ico-violet"><icons name="refresh" class="w-5 h-5" /></span>{{ $t('Change status of :ref', { ref: meeting.reference }) }}</span></template>
      <template #content>
        <div class="sm-opts" role="radiogroup" :aria-label="$t('New status')">
          <label v-for="k in ['Scheduled', 'Held', 'Postponed', 'Cancelled']" :key="k" class="sm-opt" :class="['sm-c-' + statuses[k].color, { 'is-on': st.status === k }]">
            <input v-model="st.status" type="radio" :value="k" class="sr-only" />
            <span class="sm-ico is-sm"><icons :name="statusIcon(k)" class="w-4 h-4" /></span>{{ statuses[k].label }}
          </label>
        </div>
        <template v-if="st.status === 'Postponed' || st.status === 'Cancelled'">
          <textarea v-model="st.reason" class="sm-textarea mt-3" rows="3" maxlength="1000" :placeholder="$t('Reason') + ' *'"></textarea>
          <div v-if="st.status === 'Postponed'" class="sm-field mt-3"><label for="sm_pp">{{ $t('New date (if known)') }}</label><date-picker id="sm_pp" v-model="st.postponed_to" /></div>
        </template>
        <p v-if="statusError" class="sm-err mt-2">{{ statusError }}</p>
      </template>
      <template #footer>
        <div class="flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="statusOpen = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="busy || st.status === meeting.status || ((st.status === 'Postponed' || st.status === 'Cancelled') && st.reason.trim().length < 2)" @click="saveStatus"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';

const ACTION_ICON = { MEETING_CREATE: 'plus', MEETING_VIEW: 'eye-flat', REPORT_VIEW: 'eye-flat', MEETING_UPDATE: 'edit', MEETING_STATUS: 'refresh', MEETING_MOVE: 'forward', MEETING_DELETE: 'trash', MEETING_RESTORE: 'refresh', ATTACHMENT_ADD: 'clip', ATTACHMENT_REMOVE: 'trash', ATTACHMENT_DOWNLOAD: 'download', REPORT_START: 'doc-text', REPORT_UPDATE: 'edit', REPORT_SUBMIT: 'send', REPORT_FINALISE: 'lock-closed', REPORT_REOPEN: 'lock-open', MEETING_PRINT: 'printer', REPORT_PRINT: 'printer', MEETING_DOWNLOAD: 'download', REPORT_DOWNLOAD: 'download' };
const ACTION_COLOR = { MEETING_CREATE: 'green', MEETING_VIEW: 'slate', REPORT_VIEW: 'slate', MEETING_UPDATE: 'amber', MEETING_STATUS: 'violet', MEETING_MOVE: 'indigo', MEETING_DELETE: 'red', ATTACHMENT_REMOVE: 'red', REPORT_FINALISE: 'green', REPORT_SUBMIT: 'indigo', REPORT_UPDATE: 'sky' };

export default {
  components: { AdminLayout, TecDialogModal, PageHeader, DatePicker },

  props: { meeting: Object, sections: Array, history: Array, viewers: Array, statuses: Object, can: Object },

  data() {
    return { tab: 'history', statusOpen: false, busy: false, statusError: '', st: { status: this.meeting.status, reason: '', postponed_to: '' } };
  },

  computed: {
    presentCount() {
      return this.meeting.attendees.filter(a => a.attendance === 'Present').length;
    },
  },

  watch: {
    statusOpen(v) {
      if (v) this.st = { status: this.meeting.status, reason: '', postponed_to: '' };
    },
  },

  methods: {
    initials(name) {
      return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase();
    },
    attendanceColor(a) {
      return { Present: 'sm-c-green', Absent: 'sm-c-red', Apology: 'sm-c-amber', Invited: 'sm-c-slate' }[a] || 'sm-c-slate';
    },
    statusIcon: k => ({ Scheduled: 'clock', Held: 'tick', Postponed: 'refresh', Cancelled: 'cross' }[k]),
    actionIcon: a => ACTION_ICON[a] || 'info',
    actionColor: a => ACTION_COLOR[a] || 'sky',
    changeList(changes) {
      return Object.entries(changes || {}).map(([label, c]) => ({ label, text: c && typeof c === 'object' && 'to' in c ? (c.from ?? '—') + ' → ' + (c.to ?? '—') : String(c) }));
    },
    destroy() {
      this.$confirm(this.$t('Delete :ref? It can be restored from the list of deleted meetings.', { ref: this.meeting.reference }), { variant: 'danger' }).then(ok => ok && this.$inertia.delete(this.route('site-meetings.destroy', this.meeting.id)));
    },
    saveStatus() {
      this.busy = true;
      this.statusError = '';
      this.$inertia.put(this.route('site-meetings.status', this.meeting.id), { status: this.st.status, reason: this.st.reason || null, postponed_to: this.st.postponed_to || null }, {
        preserveScroll: true,
        onSuccess: () => (this.statusOpen = false),
        onError: e => (this.statusError = Object.values(e)[0]),
        onFinish: () => (this.busy = false),
      });
    },
  },
};
</script>

<style scoped>
.sm-hint { font-size: 0.78rem; color: var(--lg-faint); }
.sm-err { font-size: 0.78rem; font-weight: 600; color: #dc2626; }
.sm-stack { grid-template-columns: 1fr; }
.sm-secsum { display: grid; gap: 8px; grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr)); }
.sm-secsum-i { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); min-width: 0; }
.sm-secsum-t { flex: 1; min-width: 0; font-size: 0.82rem; font-weight: 700; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sm-modal-title { display: inline-flex; align-items: center; gap: 12px; font-weight: 800; }
.sm-opts { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
.sm-opt { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 14px; border: 1px solid var(--lg-line); font-size: 0.84rem; font-weight: 800; color: var(--lg-ink); cursor: pointer; }
.sm-opt.is-on { border-color: var(--c); box-shadow: 0 0 0 3px var(--cb); background: var(--cb); }
</style>
