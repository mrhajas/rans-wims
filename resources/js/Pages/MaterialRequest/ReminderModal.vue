<template>
  <travel-modal :show="show" :title="chase ? $t('Remind the approvers') : $t('Reminder')" :subtitle="info ? info.request.reference : ''" :icon="chase ? 'speakerphone' : 'bell'" :tone="chase ? 'indigo' : 'amber'" max-width="2xl" :busy="sending" @close="$emit('close')">
    <div v-if="loading" class="mrr-loading"><icons name="refresh" class="w-5 h-5 animate-spin" />{{ $t('Loading...') }}</div>
    <template v-else-if="info">
      <div v-if="chase && info.waiting" class="mrr-wait" role="status">
        <span class="mrr-wait-ico"><icons name="clock" class="w-5 h-5" /></span>
        <div><b>{{ info.waiting.days > 0 ? $t('Waiting for approval for :n day(s)', { n: info.waiting.days }) : $t('Waiting for approval since today') }}</b><small>{{ $t('Submitted') }} {{ info.waiting.since }}</small></div>
      </div>
      <div class="mrr-tiles">
        <div><small>{{ $t('Reference') }}</small><b>{{ info.request.reference }}</b></div>
        <div><small>{{ $t('Status') }}</small><b>{{ info.request.status }}</b></div>
        <div><small>{{ $t('Project / Warehouse / Site') }}</small><b>{{ info.request.project || '-' }}</b></div>
        <div><small>{{ $t('Requested by') }}</small><b>{{ info.request.requester || '-' }}</b></div>
        <div><small>{{ $t('Date requested') }}</small><b>{{ info.request.date || '-' }}</b></div>
        <div><small>{{ $t('Items') }}</small><b>{{ info.request.items }}</b></div>
      </div>

      <p v-if="!info.eligible" class="lg-note lg-note-amber" style="margin: 14px 0"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('A reminder can be sent only for a request that is waiting for approval, or approved but not yet at its final status (Cleared).') }}</span></p>

      <form v-if="info.canSend" id="mrr-form" class="mrr-form" @submit.prevent="send">
        <div class="mrr-who" :class="{ 'is-chase': chase }">
          <icons name="users" class="w-4 h-4" />
          <span v-if="told.length"><b>{{ info.approvalChase ? $t('Will be asked to approve (:n)', { n: told.length }) : $t('Will be told (:n)', { n: told.length }) }}:</b> {{ told.map(a => a.name + (a.me ? ' (' + $t('you') + ')' : '')).join(', ') }}<em> · {{ channelText }}</em></span>
          <span v-else class="is-none">{{ info.approvalChase ? $t('Nobody else can approve this request, so there is nobody to remind.') : $t('Nobody is set to receive the reminders. A Super Admin chooses them on the All Requests Reminders page.') }}</span>
        </div>
        <label v-if="info.audience.some(a => a.me)" class="mrr-leave"><input type="checkbox" v-model="leaveOut" /><span>{{ $t('Do not send this reminder to me') }}</span></label>
        <div class="ac-f"><label>{{ $t('Notes & Remarks') }}</label><textarea v-model="note" maxlength="2000" rows="4" :placeholder="chase ? $t('Why does this need a decision now? Anything the approver should know?') : $t('Why is this request being chased? What must happen next?')"></textarea></div>
        <div class="mrr-attach">
          <label>{{ $t('Files') }}</label>
          <button type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="files.length >= info.limits.files" @click="$refs.file.click()"><span class="tone-ico"><icons name="upload" class="w-4 h-4" /></span>{{ $t('Choose files') }}</button>
          <input ref="file" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="sr-only" @change="onFiles" />
          <span class="mrr-hint">{{ $t('Up to :n files, :mb MB each (PDF, DOC, DOCX, JPG or PNG).', { n: info.limits.files, mb: info.limits.mb }) }}</span>
          <ul v-if="files.length" class="mrr-flist">
            <li v-for="(f, i) in files" :key="f.name + f.size">
              <span class="mrr-ico"><icons name="doc-text" class="w-4 h-4" /></span><span class="mrr-fname">{{ f.name }}<small>{{ sizeText(f.size) }}</small></span>
              <button type="button" class="lg-icon-btn lg-tip tip-left" :data-tip="$t('Preview')" :aria-label="$t('Preview')" @click="preview(f)"><icons name="eye" class="w-4 h-4" /></button>
              <button type="button" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="files.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button>
            </li>
          </ul>
        </div>
        <p v-if="done" class="lg-note mrr-ok"><icons name="tick" class="w-4 h-4" /><span>{{ done }}</span></p>
        <p v-for="(m, i) in problems" :key="i" class="mrr-err">{{ m }}</p>
      </form>

      <section class="mrr-history">
        <div class="mrr-hhead">
          <h3>{{ $t('History of reminders') }} <span>({{ info.history.length }})</span><em v-if="info.unread" class="mrr-new">{{ $t(':n new', { n: info.unread }) }}</em></h3>
          <button v-if="info.unread" type="button" class="lg-btn tone" :class="{ 'is-busy': marking }" :style="{ '--tone': chase ? '#4f46e5' : '#d97706' }" :disabled="marking" @click="markAllRead">
            <span class="tone-ico"><icons :name="marking ? 'refresh' : 'check-double'" class="w-4 h-4" :class="{ 'animate-spin': marking }" /></span>{{ $t('Mark all read') }}
          </button>
        </div>
        <p v-if="!info.history.length" class="mrr-hint">{{ $t('No reminder has been sent for this request yet.') }}</p>
        <reminder-card v-for="r in info.history" :key="r.id" :reminder="r" :class="{ 'mrr-unread': r.unread }" />
      </section>
    </template>
    <template #footer>
      <button type="button" class="lg-btn lg-btn-soft" :disabled="sending" @click="$emit('close')">{{ $t('Close') }}</button>
      <button v-if="info && info.canSend" type="submit" form="mrr-form" class="lg-btn tone tone-solid" :style="{ '--tone': chase ? '#4f46e5' : '#d97706' }" :disabled="sending || !told.length"><span class="tone-ico"><icons :name="sending ? 'refresh' : (chase ? 'speakerphone' : 'bell')" class="w-4 h-4" :class="{ 'animate-spin': sending }" /></span>{{ chase ? $t('Send to approvers') : $t('Send Reminder') }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import ReminderCard from '@/Pages/MrReminders/ReminderCard.vue';

export default {
  components: { TravelModal, ReminderCard },
  props: { show: Boolean, requestId: { type: Number, default: null } },
  emits: ['close', 'sent', 'read'],
  data() { return { marking: false, loading: false, sending: false, info: null, note: '', leaveOut: false, files: [], problems: [], done: '' }; },
  computed: {
    chase() { return !!(this.info && this.info.approvalChase); },
    told() { return (this.info?.audience || []).filter(a => !(this.leaveOut && a.me)); },
    channelText() {
      const c = this.info.channels;
      return [c.app && this.$t('App alert'), c.email && this.$t('Email'), c.sms && this.$t('SMS')].filter(Boolean).join(' + ') || this.$t('no channel is on');
    },
  },
  watch: { show(v) { if (v) this.load(); } },
  methods: {
    // every reminder of this request becomes read for the person: the "new" marks and the number on the bell go
    async markAllRead() {
      if (this.marking || !this.info) return;
      this.marking = true;
      try {
        await window.axios.post(route('material-request-reminders.read', this.requestId));
        this.info.history.forEach(h => { h.unread = false; });
        this.info.unread = 0;
        this.$emit('read', { id: this.requestId });
      } catch (e) {
        this.problems = [e.response?.data?.message || this.$t('The reminders could not be marked as read. Please try again.')];
      } finally {
        this.marking = false;
      }
    },
    async load() {
      this.loading = true; this.info = null; this.note = ''; this.leaveOut = false; this.files = []; this.problems = []; this.done = '';
      try { this.info = (await window.axios.get(route('material-request-reminders.form', this.requestId))).data; } catch (e) { this.problems = [e.response?.data?.message || this.$t('The reminder window could not be opened.')]; this.$emit('close'); } finally { this.loading = false; }
    },
    sizeText(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; },
    onFiles(e) {
      this.problems = [];
      const ok = /\.(pdf|docx?|jpe?g|png)$/i;
      for (const f of Array.from(e.target.files)) {
        if (!ok.test(f.name)) this.problems.push(this.$t(':n is not a PDF, DOC, DOCX, JPG or PNG file.', { n: f.name }));
        else if (f.size > this.info.limits.mb * 1048576) this.problems.push(this.$t(':n is bigger than :mb MB.', { n: f.name, mb: this.info.limits.mb }));
        else if (this.files.length >= this.info.limits.files) this.problems.push(this.$t('A reminder holds up to :n files.', { n: this.info.limits.files }));
        else if (!this.files.some(x => x.name === f.name && x.size === f.size)) this.files.push(f);
      }
      e.target.value = '';
    },
    preview(f) { const u = URL.createObjectURL(f); window.open(u, '_blank', 'noopener'); setTimeout(() => URL.revokeObjectURL(u), 60000); },
    async send() {
      if (this.sending) return;
      this.sending = true; this.problems = [];
      const fd = new FormData();
      if (this.note.trim()) fd.append('note', this.note.trim());
      if (this.leaveOut) fd.append('leave_me_out', '1');
      this.files.forEach(f => fd.append('files[]', f));
      try {
        const r = await window.axios.post(route('material-request-reminders.store', this.requestId), fd);
        this.info.history.unshift(r.data.reminder); this.note = ''; this.files = [];
        this.done = r.data.message;
        this.$emit('sent', { id: this.requestId, message: r.data.message });
      } catch (e) {
        const d = e.response?.data;
        this.problems = d?.errors ? Object.values(d.errors).flat() : [d?.message || this.$t('The reminder could not be sent.')];
      } finally { this.sending = false; }
    },
  },
};
</script>

<style>
.mrr-loading { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 30px; color: var(--lg-mute); font-weight: 700; }
.mrr-tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
@media (max-width: 560px) { .mrr-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.mrr-tiles > div { display: grid; gap: 2px; padding: 10px 12px; border-radius: 14px; background: var(--lg-soft); box-shadow: 0 6px 14px -8px rgba(15, 23, 42, 0.3); min-width: 0; }
.mrr-tiles small { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.mrr-tiles b { font-size: 0.9rem; color: var(--lg-ink); overflow-wrap: anywhere; }
.mrr-form { display: grid; gap: 14px; margin-top: 16px; }
.mrr-who { display: flex; gap: 10px; align-items: flex-start; padding: 10px 14px; border-radius: 14px; background: rgba(217, 119, 6, 0.1); color: var(--lg-ink); font-size: 0.84rem; line-height: 1.5; }
.mrr-leave { display: flex; align-items: center; gap: 8px; margin: 2px 0 12px; font-size: 0.84rem; font-weight: 600; color: var(--lg-ink); cursor: pointer; }
.mrr-leave input[type='checkbox'] { width: 1.05rem !important; height: 1.05rem !important; flex: none; accent-color: #b45309; cursor: pointer; }
.mrr-who.is-chase { background: rgba(79, 70, 229, 0.1); }
.mrr-wait { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; padding: 12px 14px; border-radius: 16px; background: linear-gradient(135deg, rgba(79, 70, 229, 0.14), rgba(14, 165, 233, 0.1)); border: 1px solid rgba(79, 70, 229, 0.3); }
.mrr-wait-ico { display: grid; place-items: center; width: 36px; height: 36px; flex: none; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 8px 14px -8px rgba(67, 56, 202, 0.9); animation: mrr-tick 2.4s ease-in-out infinite; }
.mrr-wait b { display: block; font-size: 0.92rem; color: var(--lg-ink); } .mrr-wait small { display: block; font-size: 0.74rem; color: var(--lg-mute); }
.mrr-form:has(.mrr-who.is-chase) textarea:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2); }
.mrr-form:has(.mrr-who.is-chase) .mrr-leave input[type='checkbox'] { accent-color: #4f46e5; }
@keyframes mrr-tick { 0%, 100% { transform: rotate(0); } 8% { transform: rotate(-12deg); } 16% { transform: rotate(10deg); } 24% { transform: rotate(0); } }
@media (prefers-reduced-motion: reduce) { .mrr-wait-ico { animation: none; } }
.mrr-who em { color: var(--lg-mute); font-style: normal; } .mrr-who .is-none { color: #be123c; font-weight: 700; }
.mrr-form textarea { width: 100%; padding: 10px 14px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font: inherit; line-height: 1.5; box-shadow: 0 6px 14px -8px rgba(15, 23, 42, 0.3); }
.mrr-form textarea:focus { outline: none; border-color: #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.18); }
.mrr-attach { display: grid; gap: 8px; justify-items: start; }
.mrr-attach > label { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint, #64748b); }
.mrr-hint { font-size: 0.76rem; color: var(--lg-mute); }
.mrr-flist { display: grid; gap: 6px; width: 100%; margin: 0; padding: 0; list-style: none; }
.mrr-flist li { display: flex; align-items: center; gap: 10px; padding: 6px 10px; border-radius: 12px; background: var(--lg-soft); }
.mrr-ico { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 9px; color: #fff; background: linear-gradient(145deg, #34d399, #047857); flex: none; }
.mrr-fname { flex: 1; min-width: 0; font-weight: 600; font-size: 0.85rem; overflow-wrap: anywhere; } .mrr-fname small { display: block; font-size: 0.7rem; font-weight: 500; color: var(--lg-mute); }
.mrr-ok { color: #065f46; background: rgba(209, 250, 229, 0.7); border: 1px solid rgba(52, 211, 153, 0.45); }
.mrr-err { margin: 0; color: #be123c; font-size: 0.82rem; font-weight: 700; }
.mrr-history { display: grid; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--lg-line); }
.mrr-hhead { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.mrr-new { margin-left: 8px; padding: 2px 9px; border-radius: 9999px; font-style: normal; font-size: 0.7rem; font-weight: 800; color: #fff; background: #e11d48; vertical-align: middle; animation: mrr-pulse 2.2s ease-in-out infinite; }
.mrr-unread { position: relative; border-radius: 18px; box-shadow: -4px 0 0 0 #e11d48; background: rgba(225, 29, 72, 0.04); }
@keyframes mrr-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.45); } 50% { box-shadow: 0 0 0 6px rgba(225, 29, 72, 0); } }
@media (prefers-reduced-motion: reduce) { .mrr-new { animation: none; } }
.mrr-history h3 { margin: 0; font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); } .mrr-history h3 span { color: var(--lg-mute); font-weight: 600; }
</style>
