<template>
  <admin-layout :title="$t('Minutes') + ' - ' + meeting.title">
    <div ref="root" class="lg-page px-4 md:px-0">
      <page-header icon="doc-text" :title="meeting.title" :subtitle="meeting.site.name + ' · ' + $date(meeting.date)">
        <Link :href="route('site-meetings.show', meeting.id)" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Meeting') }}</Link>
        <button v-if="can.edit_report" type="button" class="lg-btn lg-btn-soft" @click="openCopy"><icons name="duplicate" class="w-4 h-4" />{{ $t('Copy from another meeting') }}</button>
        <a v-if="can.print" :href="route('site-meetings.report.print', meeting.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print minutes')" :aria-label="$t('Print minutes')"><icons name="printer" class="w-4 h-4" /></a>
        <a v-if="can.print" :href="route('site-meetings.report.pdf', meeting.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download minutes')" :aria-label="$t('Download minutes')"><icons name="download" class="w-4 h-4" /></a>
      </page-header>

      <section class="sm-hero">
        <span class="sm-hero-ico"><icons name="doc-text" class="w-7 h-7" /></span>
        <div class="min-w-0 flex-1">
          <div class="sm-hero-kicker">{{ $t('Project') }}: {{ meeting.site.name }}</div>
          <h2>{{ meeting.title }}</h2>
          <div class="sm-hero-chips">
            <span class="sm-chip" :class="'sm-c-' + meeting.report_color"><icons name="doc-text" class="w-3 h-3" />{{ meeting.report_label }}</span>
            <span class="sm-chip" :class="'sm-c-' + meeting.status_color">{{ meeting.status_label }}</span>
            <span class="sm-chip sm-chip-plain"><icons name="calendar" class="w-3 h-3" />{{ $date(meeting.date) }}</span>
            <span v-if="meeting.chairperson" class="sm-chip sm-chip-plain"><icons name="user" class="w-3 h-3" />{{ $t('Chair') }}: {{ meeting.chairperson }}</span>
            <span v-if="meeting.recorder" class="sm-chip sm-chip-plain"><icons name="edit" class="w-3 h-3" />{{ $t('Minutes') }}: {{ meeting.recorder }}</span>
            <span v-if="lastPrinted" class="sm-chip sm-chip-plain"><icons name="printer" class="w-3 h-3" />{{ $t(lastPrinted.download ? 'Last downloaded by' : 'Last printed by') }} {{ lastPrinted.user }} · {{ $datetime(lastPrinted.at) }}</span>
          </div>
        </div>
      </section>

      <p v-if="meeting.locked" class="sm-lock"><icons name="lock-closed" class="w-5 h-5 flex-none" /><span>{{ $t('These minutes were finalised on :d and are locked.', { d: $datetime(meeting.report_finalised_at) }) }}<template v-if="can.finalise">&nbsp;{{ $t('You can reopen them to make a correction.') }}</template></span></p>
      <p v-else-if="can.cancelled" class="sm-note sm-c-red"><icons name="info" class="w-5 h-5 flex-none" /><span>{{ $t('This meeting was cancelled, so minutes cannot be recorded.') }}</span></p>
      <p v-else-if="!can.edit_report" class="sm-note sm-c-sky"><icons name="eye-flat" class="w-5 h-5 flex-none" /><span>{{ $t('You can read these minutes but not change them.') }}</span></p>
      <p v-if="meeting.report_status === 'Not started' && !can.edit_report" class="sm-note sm-c-slate"><icons name="info" class="w-5 h-5 flex-none" /><span>{{ $t('The minutes have not been started yet.') }}</span></p>

      <!-- who attended -->
      <section class="sm-doc-sec sm-c-violet">
        <header class="sm-doc-head" @click="open.attendance = !open.attendance">
          <span class="sm-doc-no"><icons name="users" class="w-4 h-4" /></span>
          <span class="sm-doc-title"><b>{{ $t('Attendance') }}</b><small>{{ $t('Mark who was present at the meeting.') }}</small></span>
          <span class="sm-chip sm-c-violet sm-doc-count">{{ presentCount }} / {{ attendeeList.length }} {{ $t('present') }}</span>
          <icons name="chevron-down" class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open.attendance }" />
        </header>
        <div v-show="open.attendance" class="sm-doc-body">
          <div v-if="attendeeList.length" class="sm-people">
            <div v-for="(a, i) in attendees_" :key="a.key" class="sm-person">
              <span class="sm-avatar" :class="{ 'is-ext': !a.user_id }">{{ initials(a.name) }}</span>
              <span class="sm-person-t"><b>{{ a.name }}</b><small>{{ [a.designation, a.organisation].filter(Boolean).join(' · ') || (a.user_id ? $t('WIMS user') : $t('Outside guest')) }}</small></span>
              <css-select v-model="a.attendance" class="sm-select sm-att-select" :disabled="!can.edit_report" :aria-label="$t('Attendance')" @change="dirty = true">
                <option v-for="o in ['Invited', 'Present', 'Absent', 'Apology']" :key="o" :value="o">{{ $t(o) }}</option>
              </css-select>
              <button v-if="can.edit_report" type="button" class="sm-rowbtn" :aria-label="$t('Remove')" @click="attendees_.splice(i, 1); dirty = true"><icons name="cross" class="w-4 h-4" /></button>
            </div>
          </div>
          <p v-else class="sm-hint">{{ $t('Nobody was invited.') }}</p>
          <div v-if="can.edit_report" class="sm-addrow">
            <div class="sm-field is-wide-2"><label>{{ $t('Add a WIMS user') }}</label><auto-complete id="smr_pick" keep-focus reset-search :suggestions="freePeople" :placeholder="$t('Search a person…')" @update:model-value="addPerson" /></div>
            <div class="sm-field"><label for="smr_g">{{ $t('Or an outside guest') }}</label><input id="smr_g" v-model="guest.name" class="sm-input" type="text" maxlength="190" :placeholder="$t('Name')" @keydown.enter.prevent="addGuest" /></div>
            <div class="sm-field"><label for="smr_go">{{ $t('Organisation') }}</label><input id="smr_go" v-model="guest.organisation" class="sm-input" type="text" maxlength="190" @keydown.enter.prevent="addGuest" /></div>
            <div class="sm-field"><button type="button" class="lg-btn lg-btn-soft" :disabled="!guest.name.trim()" @click="addGuest"><icons name="plus" class="w-4 h-4" />{{ $t('Add guest') }}</button></div>
          </div>
        </div>
      </section>

      <!-- the sections of the minutes -->
      <section v-for="(sec, n) in sections" :key="sec.key" class="sm-doc-sec" :class="'sm-c-' + sec.color">
        <header class="sm-doc-head" @click="open[sec.key] = !open[sec.key]; $nextTick(growAll)">
          <span class="sm-doc-no">{{ n + 1 }}</span>
          <span class="sm-doc-title"><b>{{ sec.title }}</b><small v-if="sec.hint">{{ sec.hint }}</small></span>
          <span class="sm-chip sm-doc-count" :class="'sm-c-' + (count(sec) ? sec.color : 'slate')">{{ count(sec) ? count(sec) + ' ' + $t(sec.type === 'text' ? 'written' : count(sec) === 1 ? 'entry' : 'entries') : $t('empty') }}</span>
          <icons name="chevron-down" class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open[sec.key] }" />
        </header>

        <div v-show="open[sec.key]" class="sm-doc-body">
          <template v-if="sec.type === 'text'">
            <textarea v-model="texts[sec.key]" class="sm-textarea sm-doc-text" rows="6" maxlength="20000" :disabled="!can.edit_report" :placeholder="can.edit_report ? $t('Write here…') : ''" @input="dirty = true"></textarea>
          </template>
          <template v-else>
            <div class="sm-grid-wrap">
              <table class="sm-grid">
                <thead>
                  <tr>
                    <th class="sm-sn">{{ $t('No') }}</th>
                    <th v-for="c in sec.columns" :key="c.key" :style="c.width ? { width: c.width + '%' } : null">{{ c.label }}</th>
                    <th v-if="can.edit_report" class="sm-act"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, ri) in grid[sec.key]" :key="row._k" :class="{ 'is-group': row._group !== undefined }">
                    <template v-if="row._group !== undefined">
                      <td class="sm-sn" data-label="">{{ groupNo(sec.key, ri, n) }}</td>
                      <td :colspan="sec.columns.length" :data-label="$t('Heading')"><input v-model="row._group" type="text" maxlength="160" :disabled="!can.edit_report" :placeholder="$t('Heading, e.g. Substructure')" @input="dirty = true" /></td>
                    </template>
                    <template v-else>
                      <td class="sm-sn">{{ rowNo(sec.key, ri) }}</td>
                      <td v-for="c in sec.columns" :key="c.key" :data-label="c.label">
                        <textarea v-if="c.type === 'textarea'" v-model="row[c.key]" rows="1" maxlength="4000" :disabled="!can.edit_report" @input="grow($event); dirty = true"></textarea>
                        <date-picker v-else-if="c.type === 'date'" v-model="row[c.key]" :disabled="!can.edit_report" @update:model-value="dirty = true" />
                        <css-select v-else-if="c.type === 'select'" v-model="row[c.key]" :disabled="!can.edit_report" @change="dirty = true">
                          <option value="">—</option>
                          <option v-for="o in c.options || []" :key="o" :value="o">{{ o }}</option>
                        </css-select>
                        <input v-else v-model="row[c.key]" :type="'text'" :inputmode="c.type === 'number' || c.type === 'percent' ? 'decimal' : 'text'" maxlength="500" :disabled="!can.edit_report" @input="dirty = true" />
                      </td>
                    </template>
                    <td v-if="can.edit_report" class="sm-act"><button type="button" class="sm-rowbtn" :aria-label="$t('Remove row')" @click="removeRow(sec.key, ri)"><icons name="trash" class="w-4 h-4" /></button></td>
                  </tr>
                  <tr v-if="!grid[sec.key].length"><td :colspan="sec.columns.length + 2" class="sm-hint" style="padding: 14px; text-align: center">{{ $t('Nothing recorded yet.') }}</td></tr>
                </tbody>
              </table>
            </div>
            <div v-if="can.edit_report" class="sm-addbar">
              <button type="button" class="lg-btn lg-btn-soft" @click="addRow(sec)"><icons name="plus" class="w-4 h-4" />{{ $t('Add row') }}</button>
              <button v-if="sec.grouped" type="button" class="lg-btn lg-btn-soft" @click="addGroup(sec)"><icons name="plus" class="w-4 h-4" />{{ $t('Add heading') }}</button>
            </div>
          </template>
        </div>
      </section>

      <div class="sm-bar sm-save">
        <span class="sm-bar-note">
          <span v-if="saving">{{ $t('Saving…') }}</span>
          <span v-else-if="dirty" class="sm-chip sm-c-amber"><icons name="exclamation" class="w-3 h-3" />{{ $t('Unsaved changes') }}</span>
          <span v-else-if="savedAt" class="sm-chip sm-c-green"><icons name="tick" class="w-3 h-3" />{{ $t('Saved') }} {{ savedAt }}</span>
          <span v-else>{{ meeting.report_label }}</span>
        </span>
        <div class="sm-bar-btns">
          <button v-if="can.edit_report" type="button" class="lg-btn lg-btn-primary" :disabled="saving || !dirty" @click="save()"><icons name="tick" class="w-4 h-4" />{{ $t('Save minutes') }}</button>
          <button v-if="can.edit_report && meeting.report_status === 'Draft'" type="button" class="lg-btn lg-btn-soft" :disabled="saving" @click="submit"><icons name="send" class="w-4 h-4" />{{ $t('Submit') }}</button>
          <button v-if="can.finalise && can.work && can.edit_report && meeting.report_status !== 'Not started'" type="button" class="lg-btn lg-btn-green" :disabled="saving" @click="finalise"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Finalise') }}</button>
          <button v-if="can.finalise && can.work && meeting.locked" type="button" class="lg-btn lg-btn-soft" :disabled="saving" @click="reopen"><icons name="lock-open" class="w-4 h-4" />{{ $t('Reopen') }}</button>
        </div>
      </div>
    </div>

    <tec-dialog-modal :show="copyOpen" max-width="lg" @close="copyOpen = false">
      <template #title><span class="sm-modal-title"><span class="lg-card-ico lg-ico-amber"><icons name="duplicate" class="w-5 h-5" /></span>{{ $t('Copy the minutes of another meeting') }}</span></template>
      <template #content>
        <p class="sm-hint mb-2">{{ $t('Start from an earlier meeting instead of typing everything again: roles, administration items, progress and visitors. You then edit what changed.') }}</p>
        <div class="sm-field">
          <label>{{ $t('Meeting to copy from') }}</label>
          <auto-complete id="smr_src" v-model="copySource" :suggestions="copyOptions" :placeholder="copyLoading ? $t('Loading…') : $t('Search a meeting…')" />
          <span v-if="!copyLoading" class="sm-hint">{{ copyOptions.length ? $t(':n meeting(s) with minutes to copy from (only your own projects’ meetings whose minutes have been started).', { n: copyOptions.length }) : $t('No other meeting has minutes yet, open the minutes of an earlier meeting and fill them in first.') }}</span>
        </div>
        <div class="sm-opts mt-3" role="radiogroup">
          <label class="sm-opt sm-c-indigo" :class="{ 'is-on': copyMode === 'fill' }"><input v-model="copyMode" type="radio" value="fill" class="sr-only" /><span><b>{{ $t('Fill the empty sections') }}</b><small>{{ $t('Keeps everything already written here; only sections that are still empty are copied.') }}</small></span></label>
          <label class="sm-opt sm-c-amber" :class="{ 'is-on': copyMode === 'replace' }"><input v-model="copyMode" type="radio" value="replace" class="sr-only" /><span><b>{{ $t('Replace everything') }}</b><small>{{ $t('Removes what is written here and takes the other meeting’s minutes, with its layout.') }}</small></span></label>
        </div>
        <p v-if="dirty" class="sm-note sm-c-amber mt-3"><icons name="exclamation" class="w-4 h-4 flex-none" /><span>{{ $t('You have unsaved changes on this page; copying reloads the minutes and they would be lost.') }}</span></p>
      </template>
      <template #footer>
        <div class="flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="copyOpen = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!copySource || copying" @click="doCopy"><icons name="tick" class="w-4 h-4" />{{ copying ? $t('Copying…') : $t('Copy minutes') }}</button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';

let seq = 0;

export default {
  components: { AdminLayout, AutoComplete, PageHeader, DatePicker, TecDialogModal },

  props: { meeting: Object, sections: Array, rows: Object, attendees: Array, people: Array, can: Object, lastPrinted: Object },

  data() {
    const grid = {};
    const texts = {};
    const open = { attendance: false };
    this.sections.forEach((s, i) => {
      const rs = (this.rows && this.rows[s.key]) || [];
      if (s.type === 'text') {
        texts[s.key] = (rs[0] && rs[0].data && rs[0].data.text) || '';
      } else {
        grid[s.key] = rs.map(r => ({ ...(r.data || {}), _k: 'r' + ++seq }));
      }
      open[s.key] = i < 2 || rs.length > 0;
    });
    return {
      grid,
      texts,
      open,
      attendees_: null,
      guest: { name: '', organisation: '' },
      copyOpen: false,
      copyOptions: [],
      copySource: null,
      copyMode: 'fill',
      copyLoading: false,
      copying: false,
      dirty: false,
      saving: false,
      savedAt: '',
    };
  },

  computed: {
    presentCount() {
      return this.attendeeList.filter(a => a.attendance === 'Present').length;
    },
    freePeople() {
      const taken = new Set(this.attendeeList.map(a => a.user_id).filter(Boolean));
      return this.people.filter(p => !taken.has(p.value));
    },
    attendeeList() {
      return this.attendees_ || [];
    },
  },

  created() {
    this.attendees_ = (this.attendees || []).map(a => ({ ...a, key: 'a' + ++seq }));
  },

  mounted() {
    window.addEventListener('beforeunload', this.warn);
    this.$nextTick(this.growAll);
    [300, 800, 1800].forEach(ms => setTimeout(this.growAll, ms));
    if (window.ResizeObserver) {
      this.ro = new ResizeObserver(() => this.growAll());
      this.ro.observe(this.$refs.root);
    }
    window.addEventListener('resize', this.growAll);
  },

  beforeUnmount() {
    window.removeEventListener('beforeunload', this.warn);
    window.removeEventListener('resize', this.growAll);
    if (this.ro) this.ro.disconnect();
  },

  methods: {
    openCopy() {
      this.copyOpen = true;
      this.copySource = null;
      this.copyLoading = true;
      window.axios.get(this.route('site-meetings.report.sources', this.meeting.id)).then(({ data }) => (this.copyOptions = data)).catch(() => (this.copyOptions = [])).finally(() => (this.copyLoading = false));
    },
    async doCopy() {
      if (this.copyMode === 'replace' && !(await this.$confirm(this.$t('Replace everything written in these minutes?'), { variant: 'danger' }))) return;
      this.copying = true;
      this.dirty = false; // the page is reloaded with the copied content
      this.$inertia.post(this.route('site-meetings.report.copy', this.meeting.id), { source_id: this.copySource, mode: this.copyMode }, {
        preserveState: false,
        onSuccess: () => (this.copyOpen = false),
        onFinish: () => (this.copying = false),
      });
    },
    warn(e) {
      if (this.dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    },
    initials(name) {
      return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase();
    },
    count(sec) {
      if (sec.type === 'text') return (this.texts[sec.key] || '').trim() ? 1 : 0;
      return (this.grid[sec.key] || []).filter(r => r._group === undefined && sec.columns.some(c => String(r[c.key] || '').trim() !== '')).length;
    },
    rowNo(key, index) {
      let n = 0;
      const rows = this.grid[key];
      for (let i = index; i >= 0; i--) {
        if (rows[i]._group !== undefined) break;
        n++;
      }
      return n;
    },
    groupNo(key, index, sectionIndex) {
      let g = 0;
      this.grid[key].slice(0, index + 1).forEach(r => r._group !== undefined && g++);
      return sectionIndex + 1 + '.' + g;
    },
    blank(sec) {
      const r = { _k: 'r' + ++seq };
      sec.columns.forEach(c => (r[c.key] = ''));
      return r;
    },
    addRow(sec) {
      this.grid[sec.key].push(this.blank(sec));
      this.dirty = true;
      this.$nextTick(this.growAll);
    },
    addGroup(sec) {
      this.grid[sec.key].push({ _k: 'r' + ++seq, _group: '' });
      this.dirty = true;
    },
    async removeRow(key, index) {
      const row = this.grid[key][index];
      const empty = row._group !== undefined ? !row._group : Object.entries(row).every(([k, v]) => k === '_k' || !String(v || '').trim());
      if (!empty && !(await this.$confirm(this.$t('Remove this row?'), { variant: 'danger' }))) return;
      this.grid[key].splice(index, 1);
      this.dirty = true;
    },
    growAll() {
      if (this.$refs.root) this.$refs.root.querySelectorAll('.sm-grid textarea').forEach(el => this.grow({ target: el }));
    },
    grow(e) {
      const el = e.target;
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight + 2, 320) + 'px';
    },
    addPerson(value) {
      const p = this.people.find(x => x.value === value);
      if (!p || this.attendeeList.some(a => a.user_id === p.value)) return;
      this.attendees_.push({ key: 'a' + ++seq, user_id: p.value, name: p.name, designation: p.sub || '', organisation: '', attendance: 'Present' });
      this.dirty = true;
    },
    addGuest() {
      const name = this.guest.name.trim();
      if (!name) return;
      this.attendees_.push({ key: 'a' + ++seq, user_id: null, name, designation: '', organisation: this.guest.organisation.trim(), attendance: 'Present' });
      this.guest = { name: '', organisation: '' };
      this.dirty = true;
    },
    payload() {
      const sections = {};
      this.sections.forEach(s => {
        if (s.type === 'text') {
          sections[s.key] = [{ text: this.texts[s.key] || '' }];
        } else {
          sections[s.key] = this.grid[s.key].map(r => {
            const { _k, ...cells } = r;
            return cells;
          });
        }
      });

      return { sections, attendees: this.attendeeList.map(({ key, ...a }) => a) };
    },
    save(then) {
      this.saving = true;
      this.$inertia.put(this.route('site-meetings.report.save', this.meeting.id), this.payload(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          this.dirty = false;
          this.savedAt = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
          if (typeof then === 'function') then();
        },
        onFinish: () => (this.saving = false),
      });
    },
    act(name, confirmText, variant) {
      const run = () => this.$inertia.post(this.route(name, this.meeting.id), {}, { preserveScroll: true });
      const go = async () => {
        if (confirmText && !(await this.$confirm(confirmText, { variant }))) return;
        run();
      };
      if (this.dirty) this.save(go);
      else go();
    },
    submit() {
      this.act('site-meetings.report.submit', this.$t('Submit these minutes? The people involved are told they are ready.'));
    },
    finalise() {
      this.act('site-meetings.report.finalise', this.$t('Finalise and lock these minutes? Nobody can change them until they are reopened.'));
    },
    reopen() {
      this.act('site-meetings.report.reopen', this.$t('Reopen these minutes for editing?'));
    },
  },
};
</script>

<style scoped>
.sm-modal-title { display: inline-flex; align-items: center; gap: 12px; font-weight: 800; }
.sm-opts { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; }
.sm-opt { display: flex; gap: 10px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--lg-line); cursor: pointer; font-size: 0.84rem; color: var(--lg-ink); }
.sm-opt b { display: block; }
.sm-opt small { display: block; margin-top: 2px; font-size: 0.72rem; color: var(--lg-mute); line-height: 1.4; }
.sm-opt.is-on { border-color: var(--c); box-shadow: 0 0 0 3px var(--cb); background: var(--cb); }
.sm-hint { font-size: 0.78rem; color: var(--lg-faint); }
.is-wide-2 { grid-column: span 2; }
@media (max-width: 639.98px) { .is-wide-2 { grid-column: auto; } }
</style>
