<template>
  <admin-layout :title="isEdit ? $t('Edit Site Meeting') : $t('Schedule Site Meeting')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="calendar" :title="isEdit ? meeting.reference : $t('Schedule a meeting')" :subtitle="isEdit ? meeting.title : $t('Pick the site and the day. Invited people are notified.')">
        <Link :href="isEdit ? route('site-meetings.show', meeting.id) : route('site-meetings.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back') }}</Link>
      </page-header>

      <section v-if="copy" class="sm-note sm-c-indigo">
        <icons name="duplicate" class="w-5 h-5 flex-none" />
        <div class="min-w-0 flex-1">
          <b>{{ $t('Copy of :ref', { ref: copy.reference }) }}</b> - {{ copy.title }}<template v-if="copy.site"> · {{ copy.site }}</template>.
          {{ $t('Everything is filled in from that meeting; choose the new date and change what differs.') }}
          <label v-if="copy.has_minutes && can.report" class="sm-copyopt"><input v-model="form.copy_minutes" type="checkbox" />{{ $t('Also start the minutes from the same content (sections, rows and progress), so the engineer only updates them') }}</label>
        </div>
      </section>

      <form @submit.prevent="save">
        <section class="lg-card sm-card-section">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('When & where') }}</h2><p class="lg-card-sub">{{ $t('The site, the day, the time and the place of the meeting.') }}</p></div>
          </header>
          <div class="sm-fields">
            <div class="sm-field is-wide">
              <label>{{ $t('Site / Project') }} *<small v-if="!can.super" class="sm-hint"> - {{ $t('only your assigned projects') }}</small></label>
              <auto-complete id="sm_site" v-model="form.warehouse_id" :suggestions="sites" :placeholder="$t('Search site…')" :error="errors.warehouse_id" />
            </div>
            <div class="sm-field">
              <date-picker v-model="form.meeting_date" :label="$t('Date') + ' *'" :clearable="false" :error="errors.meeting_date" />
              <span v-if="dateNote" class="sm-datenote" :class="'is-' + dateNote.tone"><icons name="info" class="w-3.5 h-3.5 flex-none" />{{ dateNote.text }}</span>
            </div>
            <div v-if="clashes.length" class="sm-field is-wide">
              <div class="sm-clash" role="status">
                <icons name="exclamation" class="w-4 h-4 flex-none" />
                <div>
                  <b>{{ $t('This site already has :n on that day:', { n: clashes.length === 1 ? $t('a meeting') : clashes.length + ' ' + $t('meetings') }) }}</b>
                  <span v-for="c in clashes" :key="c.id" class="sm-clash-i"><a :href="route('site-meetings.show', c.id)" target="_blank" rel="noopener">{{ c.reference }}</a> {{ c.title }}<template v-if="c.start"> · {{ c.start }}<template v-if="c.end">–{{ c.end }}</template></template> · {{ c.status }}</span>
                  <small>{{ $t('You can still schedule this one. Several meetings on a day are allowed.') }}</small>
                </div>
              </div>
            </div>
            <div class="sm-field"><label for="sm_start">{{ $t('Start time') }}</label><input id="sm_start" v-model="form.start_time" type="time" class="sm-input" /><span v-if="errors.start_time" class="sm-err">{{ errors.start_time }}</span></div>
            <div class="sm-field"><label for="sm_end">{{ $t('End time') }}</label><input id="sm_end" v-model="form.end_time" type="time" class="sm-input" /><span v-if="errors.end_time" class="sm-err">{{ errors.end_time }}</span></div>
            <div class="sm-field"><label for="sm_venue">{{ $t('Venue') }}</label><input id="sm_venue" v-model="form.venue" type="text" maxlength="190" class="sm-input" :placeholder="$t('Site office')" /></div>
            <div class="sm-field">
              <label for="sm_no">{{ $t('Meeting number') }}</label>
              <input id="sm_no" v-model="form.meeting_no" type="number" min="1" class="sm-input" :placeholder="nextNo ? String(nextNo) : ''" />
              <span class="sm-hint">{{ nextNo ? $t('Next for this site: :n', { n: nextNo }) : $t('Leave empty to use the next number of the site.') }}</span>
            </div>
            <div class="sm-field">
              <label for="sm_title">{{ $t('Title') }}</label>
              <input id="sm_title" v-model="form.title" type="text" maxlength="190" class="sm-input" :placeholder="defaults.title_prefix + ' ' + String(form.meeting_no || nextNo || '').padStart(2, '0')" />
            </div>
            <div v-if="!isEdit && defaults.repeat_max > 0" class="sm-field">
              <label for="sm_repeat">{{ $t('Repeat weekly') }}</label>
              <select id="sm_repeat" v-model.number="form.repeat_weeks" class="sm-select">
                <option :value="0">{{ $t('Just this meeting') }}</option>
                <option v-for="n in repeatChoices" :key="n" :value="n">{{ $t('And the next :n weeks', { n }) }}</option>
              </select>
              <span class="sm-hint">{{ $t('Same site, day and time, one record per week.') }}</span>
            </div>
          </div>
        </section>

        <section class="lg-card sm-card-section">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('People') }}</h2><p class="lg-card-sub">{{ $t('Who chairs, who writes the minutes and who is invited.') }}</p></div>
          </header>
          <div class="sm-fields">
            <div class="sm-field"><label>{{ $t('Chaired by') }}</label><auto-complete id="sm_chair" v-model="form.chairperson_id" :suggestions="people" clearable :placeholder="$t('Search person…')" /></div>
            <div class="sm-field">
              <label>{{ $t('Meeting prepared by') }}</label>
              <auto-complete v-if="can.super" id="sm_prep" v-model="form.prepared_by" :suggestions="people" :placeholder="$t('Search person…')" />
              <div v-else class="sm-fixed"><icons name="user" class="w-4 h-4" />{{ isEdit ? meeting.prepared_by || me.name : me.name }}<small v-if="!isEdit || !meeting.prepared_by || meeting.prepared_by === me.name">{{ $t('you') }}</small></div>
            </div>
            <div class="sm-field">
              <label>{{ $t('Minutes written by (Site Engineer)') }}</label>
              <auto-complete v-if="can.super" id="sm_rec" v-model="form.recorder_id" :suggestions="people" clearable :placeholder="$t('Search person…')" />
              <div v-else class="sm-fixed"><icons name="edit" class="w-4 h-4" />{{ isEdit ? meeting.recorder || me.name : me.name }}<small v-if="!isEdit || !meeting.recorder || meeting.recorder === me.name">{{ $t('you') }}</small></div>
            </div>
            <div class="sm-field is-wide">
              <label>{{ $t('Invite WIMS users') }}</label>
              <auto-complete id="sm_pick" keep-focus reset-search :suggestions="freePeople" :placeholder="$t('Search a person to invite…')" @update:model-value="addPerson" />
            </div>
          </div>

          <div v-if="form.attendees.length" class="sm-people mt-3">
            <div v-for="(a, i) in form.attendees" :key="a.key" class="sm-person">
              <span class="sm-avatar" :class="{ 'is-ext': !a.user_id }">{{ initials(a.name) }}</span>
              <span class="sm-person-t"><b>{{ a.name }}</b><small>{{ [a.designation, a.organisation].filter(Boolean).join(' · ') || (a.user_id ? $t('WIMS user') : $t('Outside guest')) }}</small></span>
              <button type="button" class="sm-rowbtn" :aria-label="$t('Remove')" @click="form.attendees.splice(i, 1)"><icons name="cross" class="w-4 h-4" /></button>
            </div>
          </div>
          <p v-else class="sm-hint mt-2">{{ $t('Nobody invited yet.') }}</p>

          <div class="sm-addrow">
            <div class="sm-field"><label for="sm_x_name">{{ $t('Outside guest') }}</label><input id="sm_x_name" v-model="guest.name" type="text" maxlength="190" class="sm-input" :placeholder="$t('Name')" @keydown.enter.prevent="addGuest" /></div>
            <div class="sm-field"><label for="sm_x_des">{{ $t('Designation') }}</label><input id="sm_x_des" v-model="guest.designation" type="text" maxlength="190" class="sm-input" @keydown.enter.prevent="addGuest" /></div>
            <div class="sm-field"><label for="sm_x_org">{{ $t('Organisation') }}</label><input id="sm_x_org" v-model="guest.organisation" type="text" maxlength="190" class="sm-input" @keydown.enter.prevent="addGuest" /></div>
            <div class="sm-field"><button type="button" class="lg-btn lg-btn-soft" :disabled="!guest.name.trim()" @click="addGuest"><icons name="plus" class="w-4 h-4" />{{ $t('Add guest') }}</button></div>
          </div>
        </section>

        <section class="lg-card sm-card-section">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Agenda, information & remarks') }}</h2><p class="lg-card-sub">{{ $t('What will be discussed, what to prepare, and any notes.') }}</p></div>
          </header>
          <div class="sm-fields">
            <div class="sm-field is-wide"><label for="sm_agenda">{{ $t('Agenda') }}</label><textarea id="sm_agenda" v-model="form.agenda" rows="4" maxlength="10000" class="sm-textarea" :placeholder="$t('Points to be discussed…')"></textarea></div>
            <div class="sm-field is-wide"><label for="sm_info">{{ $t('Information') }}</label><textarea id="sm_info" v-model="form.info" rows="3" maxlength="10000" class="sm-textarea" :placeholder="$t('What the people attending should bring or know…')"></textarea></div>
            <div class="sm-field is-wide"><label for="sm_rem">{{ $t('Remarks') }}</label><textarea id="sm_rem" v-model="form.remarks" rows="3" maxlength="10000" class="sm-textarea"></textarea></div>
          </div>
        </section>

        <section class="lg-card sm-card-section">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2><p class="lg-card-sub">{{ $t('Drawings, previous minutes, notices…') }}</p></div>
          </header>
          <ul v-if="isEdit && existing.length" class="sm-files mb-3">
            <li v-for="a in existing" :key="a.id" class="sm-file">
              <icons :name="a.is_image ? 'image' : 'doc'" class="w-4 h-4 flex-none" />
              <span class="sm-file-name">{{ a.title }}</span>
              <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeAttachment(a)"><icons name="trash" class="w-4 h-4" /></button>
            </li>
          </ul>
          <attachment-picker v-model="form.attachments" input-id="sm-files" :help-text="$t('You can select .png, .jpg, .pdf, .docx, .xlsx & .zip files.')" />
          <p v-if="errors.attachments || errors['attachments.0']" class="sm-err mt-1">{{ errors.attachments || errors['attachments.0'] }}</p>
        </section>

        <div class="sm-bar">
          <span class="sm-bar-note">{{ form.attendees.length }} {{ $t('invited') }}<template v-if="!isEdit && form.repeat_weeks"> · {{ form.repeat_weeks + 1 }} {{ $t('weekly meetings') }}</template></span>
          <div class="sm-bar-btns">
            <Link :href="isEdit ? route('site-meetings.show', meeting.id) : route('site-meetings.index')" class="lg-btn lg-btn-soft"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-green" :disabled="saving"><icons name="tick" class="w-4 h-4" />{{ saving ? $t('Saving…') : isEdit ? $t('Save changes') : $t('Schedule') }}</button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';

let seq = 0;

export default {
  components: { AdminLayout, AutoComplete, DatePicker, PageHeader, AttachmentPicker },

  props: { meeting: Object, copy: Object, defaults: Object, sites: Array, people: Array, can: Object, me: Object },

  data() {
    const m = this.meeting;
    const c = this.copy;
    const siteOk = c && this.sites.some(s => s.value === c.warehouse_id);
    return {
      form: {
        warehouse_id: m ? m.warehouse_id : c ? (siteOk ? c.warehouse_id : null) : this.defaults.warehouse_id || null,
        meeting_date: m ? m.date : c ? c.date : this.defaults.date,
        start_time: m ? m.start || '' : c ? c.start_time || '' : this.defaults.start_time || '',
        end_time: m ? m.end || '' : c ? c.end_time || '' : this.defaults.end_time || '',
        venue: m ? m.venue || '' : c ? c.venue || '' : this.defaults.venue || '',
        copy_from: c ? c.id : null,
        copy_minutes: false,
        title: m ? m.raw_title || '' : '',
        meeting_no: m ? m.meeting_no : '',
        chairperson_id: m ? m.chairperson_id : c ? c.chairperson_id : null,
        recorder_id: m ? m.recorder_id : c ? c.recorder_id || this.me.id : this.me.id,
        prepared_by: m ? m.prepared_by_id : this.me.id,
        agenda: m ? m.agenda || '' : c ? c.agenda || '' : '',
        info: m ? m.info || '' : c ? c.info || '' : '',
        remarks: m ? m.remarks || '' : c ? c.remarks || '' : '',
        repeat_weeks: 0,
        attendees: m ? m.attendees.map(a => ({ key: 'e' + a.id, user_id: a.user_id, name: a.name, designation: a.designation, organisation: a.organisation, attendance: a.attendance })) : c ? c.attendees.map((a, i) => ({ key: 'c' + i, user_id: a.user_id, name: a.name, designation: a.designation, organisation: a.organisation, attendance: 'Invited' })) : [],
        attachments: [],
      },
      existing: m ? [...m.attachments] : [],
      guest: { name: '', designation: '', organisation: '' },
      errors: {},
      saving: false,
      nextNo: null,
      clashes: [],
    };
  },

  computed: {
    isEdit() {
      return !!this.meeting;
    },
    // Soft notes about the chosen date (never blocks): past, far away, or a different week than the meeting it was copied from.
    dateNote() {
      const d = this.form.meeting_date;
      if (!d) return null;
      const today = new Date().toISOString().slice(0, 10);
      const weekday = new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long' });
      if (d < today) return { tone: 'amber', text: this.$t('This date is in the past.') };
      if (this.copy) {
        const a = new Date(this.copy.date + 'T00:00:00');
        const b = new Date(d + 'T00:00:00');
        const weeks = Math.round((b - a) / 604800000);
        if (Math.abs(weeks) > 8) return { tone: 'blue', text: this.$t(':day, about :n weeks from the suggested date. It will appear on the board in that week.', { day: weekday, n: Math.abs(weeks) }) };
      }
      return { tone: 'blue', text: weekday + ' - ' + this.$t('it will appear on the board in the week of that day.') };
    },
    repeatChoices() {
      return Array.from({ length: Math.min(this.defaults.repeat_max || 0, 12) }, (_, i) => i + 1);
    },
    freePeople() {
      const taken = new Set(this.form.attendees.map(a => a.user_id).filter(Boolean));
      return this.people.filter(p => !taken.has(p.value));
    },
  },

  watch: {
    // is something already booked at this site on this day? (a warning only)
    'form.meeting_date'() {
      this.checkClash();
    },
    'form.warehouse_id': {
      immediate: true,
      handler(id) {
        this.checkClash();
        this.nextNo = null;
        if (!id) return;
        window.axios.get(this.route('site-meetings.next-number', { warehouse_id: id })).then(({ data }) => {
          if (this.form.warehouse_id === id) this.nextNo = data.next;
        }).catch(() => {});
      },
    },
  },

  methods: {
    checkClash() {
      clearTimeout(this.clashTimer);
      this.clashTimer = setTimeout(() => {
        const { warehouse_id: site, meeting_date: date } = this.form;
        if (!site || !date) {
          this.clashes = [];
          return;
        }
        window.axios.get(this.route('site-meetings.conflicts', { warehouse_id: site, date, except: this.isEdit ? this.meeting.id : undefined }))
          .then(({ data }) => {
            if (this.form.warehouse_id === site && this.form.meeting_date === date) this.clashes = data;
          })
          .catch(() => (this.clashes = []));
      }, 350);
    },
    initials(name) {
      return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase();
    },
    addPerson(value) {
      const p = this.people.find(x => x.value === value || (value && value.value === x.value));
      if (!p || this.form.attendees.some(a => a.user_id === p.value)) return;
      this.form.attendees.push({ key: 'n' + ++seq, user_id: p.value, name: p.name, designation: p.sub || '', organisation: '', attendance: 'Invited' });
    },
    addGuest() {
      const name = this.guest.name.trim();
      if (!name) return;
      this.form.attendees.push({ key: 'n' + ++seq, user_id: null, name, designation: this.guest.designation.trim(), organisation: this.guest.organisation.trim(), attendance: 'Invited' });
      this.guest = { name: '', designation: '', organisation: '' };
    },
    async removeAttachment(a) {
      if (!(await this.$confirm(this.$t('Remove the attachment ":name"?', { name: a.title }), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('site-meetings.attachments.destroy', [this.meeting.id, a.id]), {
        preserveScroll: true,
        onSuccess: () => (this.existing = this.existing.filter(x => x.id !== a.id)),
      });
    },
    save() {
      this.saving = true;
      this.errors = {};
      const f = this.form;
      const payload = {
        warehouse_id: f.warehouse_id, meeting_date: f.meeting_date, start_time: f.start_time || null, end_time: f.end_time || null, venue: f.venue || null,
        title: f.title || null, meeting_no: f.meeting_no || null, chairperson_id: f.chairperson_id || null,
        recorder_id: this.can.super ? f.recorder_id || null : undefined, prepared_by: this.can.super ? f.prepared_by || null : undefined,
        agenda: f.agenda || null, info: f.info || null, remarks: f.remarks || null, repeat_weeks: this.isEdit ? undefined : f.repeat_weeks || 0,
        copy_from: !this.isEdit && f.copy_from ? f.copy_from : undefined, copy_minutes: !this.isEdit && f.copy_from && f.copy_minutes ? 1 : undefined,
        attendees: f.attendees.map(a => ({ user_id: a.user_id, name: a.name, designation: a.designation, organisation: a.organisation, attendance: a.attendance })),
        attachments: f.attachments,
      };
      this.$inertia.post(this.isEdit ? this.route('site-meetings.update', this.meeting.id) : this.route('site-meetings.store'), payload, {
        forceFormData: true,
        preserveScroll: true,
        onError: e => (this.errors = e),
        onFinish: () => (this.saving = false),
      });
    },
  },
};
</script>

<style scoped>
.sm-datenote { display: inline-flex; align-items: flex-start; gap: 5px; font-size: 0.72rem; font-weight: 700; line-height: 1.35; }
.sm-datenote.is-amber { color: #b45309; }
.sm-datenote.is-blue { color: #4338ca; }
html.dark .sm-datenote.is-amber { color: #fcd34d; }
html.dark .sm-datenote.is-blue { color: #c7d2fe; }
.sm-clash { display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border-radius: 14px; font-size: 0.82rem; color: #92400e; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.4); }
html.dark .sm-clash { color: #fcd34d; }
.sm-clash b { display: block; margin-bottom: 2px; }
.sm-clash-i { display: block; font-weight: 600; }
.sm-clash-i a { font-weight: 800; text-decoration: underline; }
.sm-clash small { display: block; margin-top: 3px; opacity: 0.85; }
.sm-copyopt { display: flex; align-items: flex-start; gap: 8px; margin-top: 8px; font-size: 0.8rem; font-weight: 700; color: var(--lg-ink); cursor: pointer; }
.sm-copyopt input { margin-top: 3px; accent-color: #4f46e5; flex: none; }
.sm-fixed { display: flex; align-items: center; gap: 8px; min-height: 40px; padding: 8px 12px; border-radius: 12px; font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); background: var(--lg-soft); border: 1px dashed var(--lg-line); }
.sm-fixed small { margin-left: auto; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sm-hint { font-size: 0.72rem; color: var(--lg-faint); }
.sm-err { font-size: 0.74rem; font-weight: 600; color: #dc2626; }
</style>
