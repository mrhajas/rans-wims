<template>
  <div class="sc">
    <section class="lg-card">
      <header class="lg-card-head">
        <span class="lg-card-ico lg-ico-sky"><icons name="doc-text" class="w-5 h-5" /></span>
        <div class="min-w-0">
          <h2 class="lg-card-title">{{ $t('Contracts') }}</h2>
          <p class="lg-card-sub">{{ $t('Attach the signed contract, set its start and end date, and renew it when it runs out.') }}</p>
        </div>
        <div class="sc-head-actions">
          <Link :href="route('staffs.contracts.calendar')" class="lg-btn lg-btn-soft"><icons name="calendar" class="w-4 h-4" />{{ $t('Calendar') }}</Link>
          <button v-if="canAdd && !active" type="button" class="lg-btn lg-btn-primary" @click="openForm('add')"><icons name="plus" class="w-4 h-4" />{{ $t('Add contract') }}</button>
        </div>
      </header>

      <!-- the contract in force -->
      <div v-if="active" class="sc-now" :class="'is-' + active.state">
        <div class="sc-now__ring" aria-hidden="true">
          <svg viewBox="0 0 100 100"><circle class="sc-ring-track" cx="50" cy="50" r="44" /><circle class="sc-ring-fill" cx="50" cy="50" r="44" pathLength="100" :style="{ strokeDasharray: ringFill(active) + ' 100' }" /></svg>
          <b>{{ active.days_left === null ? '∞' : Math.abs(active.days_left) }}</b>
          <small>{{ active.days_left === null ? (active.type === 'Permanent' ? $t('permanent') : $t('open')) : active.days_left < 0 ? $t('days over') : $t('days left') }}</small>
        </div>
        <div class="sc-now__body">
          <div class="sc-now__top"><b>{{ active.contract_number }}</b><span class="lg-chip" :class="stateChip(active.state)">{{ stateLabel(active.state, active.type) }}</span><span class="lg-chip lg-chip-violet" style="text-transform: none">{{ active.type }}</span></div>
          <div class="sc-now__dates">
            <span><small>{{ $t('Starts') }}</small>{{ $date(active.start_date) }}</span>
            <i></i>
            <span><small>{{ $t('Ends') }}</small>{{ active.end_date ? $date(active.end_date) : (active.type === 'Permanent' ? $t('Permanent') : $t('Open ended')) }}</span>
          </div>
          <div class="sc-bar" v-if="active.end_date"><span :style="{ width: progress(active) + '%' }"></span></div>
          <div class="sc-now__btns">
            <button v-if="canUpdate" type="button" class="lg-btn lg-btn-primary" @click="openForm('renew', active)"><icons name="refresh" class="w-4 h-4" />{{ active.type === 'Permanent' ? $t('New terms (amend)') : $t('Renew contract') }}</button>
            <a v-if="canPrint" :href="route('staffs.contracts.print', [staff.id, active.id])" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
            <button v-if="canStop" type="button" class="lg-btn sc-stopbtn" @click="openStop(active)"><icons name="cross" class="w-4 h-4" />{{ $t('Stop contract') }}</button>
          </div>
        </div>
      </div>
      <p v-else class="sc-none"><icons name="exclamation" class="w-4 h-4" />{{ contracts.length ? $t('There is no active contract. Add the new one.') : $t('No contract has been added yet.') }}</p>
    </section>

    <!-- every contract the person has had, newest first: renewals never hide what came before -->
    <section v-if="contracts.length > 1" class="lg-card sc-hist">
      <h3><icons name="clock" class="w-4 h-4" />{{ $t('Contract history') }} <span class="sc-cnt">{{ contracts.length }}</span></h3>
      <ol>
        <li v-for="c in contracts" :key="c.id" :class="'is-' + c.state">
          <span class="sc-hist__dot"></span>
          <div class="sc-hist__body">
            <b>{{ c.contract_number }}</b>
            <span class="lg-chip" :class="stateChip(c.state)">{{ stateLabel(c.state, c.type) }}</span>
            <small>{{ c.type }} · {{ $date(c.start_date) }} → {{ c.end_date ? $date(c.end_date) : (c.type === 'Permanent' ? $t('Permanent') : $t('open ended')) }}<template v-if="c.renewed_from_number"> · {{ $t('renews') }} {{ c.renewed_from_number }}</template></small>
          </div>
          <button v-if="c.attachments.length" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('View the attached contract')" :aria-label="$t('View')" @click="view(c.attachments[0], c)"><icons name="eye" class="w-4 h-4" /></button>
        </li>
      </ol>
    </section>

    <template v-for="(c, i) in ordered" :key="c.id">
    <button v-if="i === firstPrevious" type="button" class="sc-prev" :aria-expanded="showPrevious" @click="showPrevious = !showPrevious">
      <icons name="chevron-down" class="w-4 h-4 sc-prev__chev" :class="{ open: showPrevious }" />{{ $t('Previous contracts') }} <span class="sc-cnt">{{ previous.length }}</span>
      <small>{{ showPrevious ? $t('Hide') : $t('Show') }}</small>
    </button>
    <section v-show="c.status === 'Active' || showPrevious" class="lg-card sc-card sc-in" :class="'is-' + c.state" :style="{ animationDelay: Math.min(i, 6) * 70 + 'ms' }">
      <div class="sc-card__head">
        <div class="min-w-0">
          <div class="sc-card__title"><b>{{ c.contract_number }}</b><span class="lg-chip" :class="stateChip(c.state)">{{ stateLabel(c.state, c.type) }}</span><span class="lg-chip lg-chip-violet" style="text-transform: none">{{ c.type }}</span></div>
          <div class="lg-subtext">{{ c.position || staff.position || '-' }}<template v-if="c.renewed_from_number"> · {{ $t('renews') }} {{ c.renewed_from_number }}</template></div>
        </div>
        <div class="sc-card__btns">
          <button v-if="c.attachments.length" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('View the attached contract')" :aria-label="$t('View')" @click="view(c.attachments[0], c)"><icons name="eye" class="w-4 h-4" /></button>
          <a v-if="canPrint" :href="route('staffs.contracts.print', [staff.id, c.id])" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('Print contract sheet')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a>
          <a v-if="canPrint" :href="route('staffs.contracts.pdf', [staff.id, c.id])" class="lg-icon-btn lg-tip" :data-tip="$t('Download PDF')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
          <button v-if="canUpdate && c.status === 'Active'" type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Renew')" :aria-label="$t('Renew')" @click="openForm('renew', c)"><icons name="refresh" class="w-4 h-4" /></button>
          <button v-if="canUpdate" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="openForm('edit', c)"><icons name="edit" class="w-4 h-4" /></button>
          <button v-if="canStop && c.status === 'Active'" type="button" class="lg-icon-btn is-amber lg-tip" :data-tip="$t('Stop this contract (left or terminated)')" :aria-label="$t('Stop contract')" @click="openStop(c)"><icons name="cross" class="w-4 h-4" /></button>
          <button v-if="canDelete" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="removeContract(c)"><icons name="trash" class="w-4 h-4" /></button>
        </div>
      </div>
      <dl class="sc-grid">
        <div><dt><icons name="calendar" class="w-3.5 h-3.5" />{{ $t('Start Date') }}</dt><dd>{{ $date(c.start_date) }}</dd></div>
        <div><dt><icons name="calendar" class="w-3.5 h-3.5" />{{ $t('End Date') }}</dt><dd>{{ c.end_date ? $date(c.end_date) : (c.type === 'Permanent' ? $t('Permanent') : $t('Open ended')) }}<small v-if="c.days_left !== null" class="sc-left" :class="'is-' + c.state"> · {{ c.days_left < 0 ? Math.abs(c.days_left) + ' ' + $t('days ago') : c.days_left + ' ' + $t('days left') }}</small></dd></div>
        <div><dt><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Signed On') }}</dt><dd>{{ c.signed_date ? $date(c.signed_date) : '-' }}</dd></div>
        <div><dt><icons name="cash" class="w-3.5 h-3.5" />{{ $t('Basic Salary') }}</dt><dd>{{ c.basic_salary !== null ? $money(c.basic_salary) : '-' }}</dd></div>
        <div><dt><icons name="clock" class="w-3.5 h-3.5" />{{ $t('Probation') }}</dt><dd>{{ c.probation_months !== null ? c.probation_months + ' ' + $t('month(s)') : '-' }}</dd></div>
        <div><dt><icons name="exclamation" class="w-3.5 h-3.5" />{{ $t('Notice Period') }}</dt><dd>{{ c.notice_period_days !== null ? c.notice_period_days + ' ' + $t('day(s)') : '-' }}</dd></div>
        <div v-if="c.notes" class="lg-span-all"><dt>{{ $t('Notes') }}</dt><dd style="white-space: pre-line">{{ c.notes }}</dd></div>
      </dl>
      <div v-if="c.stopped_reason_type" class="sc-stopped">
        <b><icons name="cross" class="w-4 h-4" />{{ $t('Stopped') }}: {{ $t(stopReasons[c.stopped_reason_type] || c.stopped_reason_type) }}<template v-if="c.stopped_on"> · {{ $date(c.stopped_on) }}</template></b>
        <p v-if="c.stopped_reason">{{ c.stopped_reason }}</p>
      </div>
      <ul v-if="c.attachments.length" class="sc-files">
        <li v-for="a in c.attachments" :key="a.id">
          <icons :name="a.is_image ? 'image' : 'doc-text'" class="w-4 h-4 flex-none" />
          <button type="button" class="sc-file__name" @click="view(a, c)">{{ a.name }}</button><em v-if="a.group === 'stop'" class="sc-stoptag">{{ $t('Stop document') }}</em>
          <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
          <button v-if="canUpdate" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeFile(a)"><icons name="trash" class="w-4 h-4" /></button>
        </li>
      </ul>
      <p v-else class="sc-nofile"><icons name="exclamation" class="w-4 h-4" />{{ $t('No contract document attached yet.') }}<button v-if="canUpdate" type="button" class="sc-link" @click="openForm('edit', c)">{{ $t('Attach it') }}</button></p>
    </section>
    </template>

    <!-- add / edit / renew -->
    <jetstream-modal v-if="formOpen" :show="formOpen" max-width="2xl" @close="closeForm">
      <form class="scm" @submit.prevent="save">
        <div class="scm-head">
          <span class="scm-badge" :class="'is-' + mode"><icons :name="mode === 'renew' ? 'refresh' : mode === 'edit' ? 'edit' : 'plus'" class="w-6 h-6" /></span>
          <div class="min-w-0">
            <h3>{{ mode === 'renew' ? $t('Renew contract') : mode === 'edit' ? $t('Edit contract') : $t('Add contract') }}</h3>
            <p>{{ staff.staff_number }} · {{ staff.name }}<template v-if="mode === 'renew' && target"> · {{ $t('renewing') }} {{ target.contract_number }}</template></p>
          </div>
        </div>
        <div class="scm-grid">
          <div class="scm-f scm-wide"><span>{{ $t('Contract type') }}</span>
            <div class="scm-pills"><button v-for="t in types" :key="t" type="button" :class="{ on: form.type === t }" @click="form.type = t">{{ $t(t) }}</button></div>
          </div>
          <label class="scm-f"><span>{{ $t('Position / title') }}</span><input v-model="form.position" type="text" maxlength="190" class="scm-in" /></label>
          <label class="scm-f" :class="{ bad: errors.start_date }"><span>{{ $t('Start date') }}</span><date-picker v-model="form.start_date" flush /><em v-if="errors.start_date">{{ errors.start_date }}</em></label>
          <label v-if="form.type !== 'Permanent'" class="scm-f" :class="{ bad: errors.end_date }"><span>{{ $t('End date') }}</span><date-picker v-model="form.end_date" :min="form.start_date" flush /><em v-if="errors.end_date">{{ errors.end_date }}</em></label>
          <p v-else class="scm-perm"><icons name="tick" class="w-4 h-4" />{{ $t('A Permanent contract has a start date only. It has no end date.') }}</p>
          <div class="scm-quick" v-if="form.start_date && form.type !== 'Permanent'">
            <span>{{ $t('Ends after') }}:</span>
            <button v-for="m in [3, 6, 12, 24, 36]" :key="m" type="button" @click="endAfter(m)">{{ m }} {{ $t('months') }}</button>
          </div>
          <label class="scm-f"><span>{{ $t('Signed on') }}</span><date-picker v-model="form.signed_date" flush /></label>
          <label class="scm-f" :class="{ bad: errors.basic_salary }"><span>{{ $t('Basic salary') }}</span><input v-model="form.basic_salary" type="number" min="0" step="0.01" class="scm-in" /><em v-if="errors.basic_salary">{{ errors.basic_salary }}</em></label>
          <label class="scm-f"><span>{{ $t('Probation (months)') }}</span><input v-model="form.probation_months" type="number" min="0" max="36" class="scm-in" /></label>
          <label class="scm-f"><span>{{ $t('Notice period (days)') }}</span><input v-model="form.notice_period_days" type="number" min="0" max="365" class="scm-in" /></label>
          <label class="scm-f scm-wide"><span>{{ $t('Notes') }}</span><textarea v-model="form.notes" rows="2" maxlength="3000" class="scm-in"></textarea></label>
        </div>
        <p v-if="mode === 'renew'" class="scm-hint"><icons name="exclamation" class="w-4 h-4" />{{ $t('The old contract is closed as Renewed. If you fill in a position or basic salary, they also become the staff member\'s current position and basic salary.') }}</p>
        <div class="scm-attach">
          <span class="scm-label">{{ mode === 'renew' ? $t('Signed new contract') : $t('Contract documents') }}</span>
          <attachment-picker v-model="files" scan :max-files="5" input-id="contract-files" :help-text="$t('PDF, DOC, DOCX, JPG or PNG, up to 5 files of 10 MB.')" />
          <p v-if="errors.attachments || fileError" class="scm-err">{{ errors.attachments || fileError }}</p>
        </div>
        <div class="scm-actions">
          <button type="button" class="scm-btn scm-btn--ghost" @click="closeForm">{{ $t('Cancel') }}</button>
          <button type="submit" class="scm-btn scm-btn--go" :disabled="busy">{{ busy ? $t('Saving...') : mode === 'renew' ? $t('Renew') : $t('Save contract') }}</button>
        </div>
      </form>
    </jetstream-modal>

    <!-- stop a contract: somebody left, or management ended it -->
    <jetstream-modal v-if="stopTarget" :show="!!stopTarget" max-width="xl" @close="stopTarget = null">
      <form class="scm" @submit.prevent="stopContract">
        <div class="scm-head"><span class="scm-badge is-end"><icons name="cross" class="w-6 h-6" /></span><div><h3>{{ $t('Stop contract') }}</h3><p>{{ stopTarget.contract_number }} · {{ staff.name }}</p></div></div>
        <p class="scm-hint" style="margin-top: 0; margin-bottom: 0.9rem"><icons name="exclamation" class="w-4 h-4" />{{ $t('Use this when the person left or management ended the contract. The reason and the date are kept in the staff history for good.') }}</p>
        <div class="scm-grid">
          <label class="scm-f" :class="{ bad: stopErrors.reason_type }"><span>{{ $t('Why is it stopped?') }}</span>
            <css-select v-model="stop.reason_type" class="scm-in" @change="suggestStatus"><option v-for="(label, key) in stopReasons" :key="key" :value="key">{{ $t(label) }}</option></css-select><em v-if="stopErrors.reason_type">{{ stopErrors.reason_type }}</em></label>
          <label class="scm-f" :class="{ bad: stopErrors.effective_date }"><span>{{ $t('Effective date (last day)') }}</span><date-picker v-model="stop.effective_date" :min="stopTarget.start_date" flush /><em v-if="stopErrors.effective_date">{{ stopErrors.effective_date }}</em></label>
          <label class="scm-f scm-wide" :class="{ bad: stopErrors.reason }"><span>{{ $t('Reason (required)') }}</span><textarea v-model="stop.reason" rows="3" maxlength="2000" class="scm-in" :placeholder="$t('What happened? For example: resigned in a letter dated..., or terminated after the disciplinary hearing of...')"></textarea><em v-if="stopErrors.reason">{{ stopErrors.reason }}</em></label>
          <label class="scm-f scm-wide"><span>{{ $t('What happens to the staff record?') }}</span>
            <css-select v-model="stop.staff_status" class="scm-in">
              <option value="Terminated">{{ $t('Mark as Terminated (ended by management)') }}</option>
              <option value="Inactive">{{ $t('Mark as Inactive (the person left)') }}</option>
              <option value="Retired">{{ $t('Mark as Retired (off payroll)') }}</option>
              <option value="Retired Active">{{ $t('Mark as Retired Active (still on payroll)') }}</option>
              <option value="keep">{{ $t('Keep the status as it is') }}</option>
            </css-select>
          </label>
        </div>
        <div class="scm-attach">
          <span class="scm-label">{{ $t('Documents (optional, up to 3)') }}</span>
          <attachment-picker v-model="stopFiles" scan :max-files="3" input-id="stop-files" :help-text="$t('A resignation letter, a termination letter or a photo of it: PDF, DOC, DOCX, JPG or PNG, up to 3 files of 10 MB.')" />
          <p v-if="stopErrors.attachments || stopFileError" class="scm-err">{{ stopErrors.attachments || stopFileError }}</p>
        </div>
        <p v-if="stop.staff_status !== 'keep' && stop.staff_status !== 'Retired Active'" class="scm-warn"><icons name="exclamation" class="w-4 h-4" />{{ $t('After this the person is no longer on payroll: no salary, advance or loan is prepared for them.') }}</p>
        <div class="scm-actions"><button type="button" class="scm-btn scm-btn--ghost" @click="stopTarget = null">{{ $t('Cancel') }}</button><button type="submit" class="scm-btn scm-btn--warn" :disabled="busy">{{ busy ? $t('Saving...') : $t('Stop contract') }}</button></div>
      </form>
    </jetstream-modal>

    <!-- view / print the attached document -->
    <jetstream-modal v-if="viewer" :show="!!viewer" max-width="5xl" @close="viewer = null">
      <div class="scv">
        <div class="scv-bar">
          <div class="min-w-0"><b>{{ viewer.file.name }}</b><small>{{ viewer.contract.contract_number }}</small></div>
          <div class="scv-btns">
            <button type="button" class="lg-btn lg-btn-primary" @click="printViewer"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
            <a :href="viewer.file.download_url" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
            <a :href="viewer.file.preview_url" target="_blank" class="lg-btn lg-btn-soft"><icons name="eye" class="w-4 h-4" />{{ $t('Open') }}</a>
            <button type="button" class="lg-btn lg-btn-soft" @click="viewer = null"><icons name="cross" class="w-4 h-4" />{{ $t('Close') }}</button>
          </div>
        </div>
        <div class="scv-stage">
          <img v-if="viewer.file.is_image" :src="viewer.file.preview_url" :alt="viewer.file.name" />
          <iframe v-else-if="viewer.file.is_pdf" ref="frame" :src="viewer.file.preview_url" :title="viewer.file.name"></iframe>
          <div v-else class="scv-other"><icons name="doc-text" class="w-10 h-10" /><p>{{ $t('This kind of file cannot be shown here.') }}</p><a :href="viewer.file.download_url" class="lg-btn lg-btn-primary">{{ $t('Download it') }}</a></div>
        </div>
      </div>
    </jetstream-modal>
  </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import JetstreamModal from '@/Jetstream/Modal.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';

const blank = () => ({ type: 'Fixed term', position: '', start_date: '', end_date: '', signed_date: '', basic_salary: '', probation_months: '', notice_period_days: '', notes: '' });

export default {
  components: { Link, JetstreamModal, AttachmentPicker },
  props: {
    staff: { type: Object, required: true },
    contracts: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    canAdd: Boolean, canUpdate: Boolean, canDelete: Boolean, canPrint: Boolean, canStop: Boolean,
    stopReasons: { type: Object, default: () => ({}) },
  },
  data() { return { showPrevious: true, formOpen: false, mode: 'add', target: null, form: blank(), files: [], errors: {}, fileError: '', busy: false, stopFiles: [], stopFileError: '', stopTarget: null, stop: { reason_type: 'Resigned', effective_date: '', reason: '', staff_status: 'Inactive' }, stopErrors: {}, viewer: null }; },
  computed: {
    active() { return this.contracts.find(c => c.status === 'Active') || null; },
    previous() { return this.contracts.filter(c => c.status !== 'Active'); },
    // the current contract first, then everything before it (kept, with its documents)
    ordered() { return [...this.contracts.filter(c => c.status === 'Active'), ...this.previous]; },
    firstPrevious() { return this.ordered.findIndex(c => c.status !== 'Active'); },
  },
  methods: {
    stateLabel(s, type) { if (s === 'open' && type === 'Permanent') return this.$t('Permanent'); return { open: this.$t('Open ended'), ok: this.$t('Active'), soon: this.$t('Ending soon'), urgent: this.$t('Ending very soon'), expired: this.$t('Expired'), ended: this.$t('Ended'), renewed: this.$t('Renewed') }[s] || s; },
    stateChip(s) { return { open: 'lg-chip-blue', ok: 'lg-chip-green', soon: 'lg-chip-amber', urgent: 'lg-chip-red', expired: 'lg-chip-red', ended: 'lg-chip-slate', renewed: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    // how much of the ring is still full: the share of the contract that is left (permanent and open contracts keep a full ring)
    ringFill(c) { return c.end_date ? Math.max(4, 100 - this.progress(c)) : 100; },
    progress(c) {
      const a = new Date(c.start_date).getTime(), b = new Date(c.end_date).getTime();
      return b <= a ? 100 : Math.max(2, Math.min(100, Math.round(((Date.now() - a) / (b - a)) * 100)));
    },
    openForm(mode, c = null) {
      this.mode = mode; this.target = c; this.errors = {}; this.fileError = ''; this.files = [];
      if (mode === 'edit' && c) {
        this.form = { type: c.type, position: c.position || '', start_date: c.start_date || '', end_date: c.end_date || '', signed_date: c.signed_date || '', basic_salary: c.basic_salary ?? '', probation_months: c.probation_months ?? '', notice_period_days: c.notice_period_days ?? '', notes: c.notes || '' };
      } else if (mode === 'renew' && c) {
        const next = c.end_date ? new Date(new Date(c.end_date).getTime() + 86400000).toISOString().slice(0, 10) : new Date().toISOString().slice(0, 10);
        this.form = { ...blank(), type: c.type === 'Probation' ? 'Fixed term' : c.type, position: c.position || '', start_date: next, basic_salary: c.basic_salary ?? '', notice_period_days: c.notice_period_days ?? '' };
      } else {
        this.form = { ...blank(), position: this.staff.position || '', start_date: new Date().toISOString().slice(0, 10), basic_salary: this.staff.basic_salary || '' };
      }
      this.formOpen = true;
    },
    closeForm() { this.formOpen = false; },
    endAfter(months) {
      const d = new Date(this.form.start_date); d.setMonth(d.getMonth() + months); d.setDate(d.getDate() - 1);
      this.form.end_date = d.toISOString().slice(0, 10);
    },
    save() {
      this.errors = {}; this.fileError = '';
      if (this.form.type === 'Permanent') this.form.end_date = '';
      if (this.form.type !== 'Permanent' && !this.form.end_date) { this.errors = { end_date: this.$t('Enter the end date. Only a Permanent contract can be open ended.') }; return; }
      if (this.form.end_date && this.form.end_date < this.form.start_date) { this.errors = { end_date: this.$t('The end date cannot be before the start date.') }; return; }
      const big = this.files.find(f => f.size > 10 * 1024 * 1024);
      if (big) { this.fileError = big.name + ': ' + this.$t('this file is over 10 MB.'); return; }
      const payload = { ...this.form, attachments: this.files };
      Object.keys(payload).forEach(k => { if (payload[k] === '') payload[k] = null; });
      const opts = { forceFormData: true, preserveScroll: true, onSuccess: () => this.closeForm(), onError: e => (this.errors = e), onFinish: () => (this.busy = false) };
      this.busy = true;
      if (this.mode === 'edit') this.$inertia.post(this.route('staffs.contracts.update', [this.staff.id, this.target.id]), { ...payload, _method: 'put' }, opts);
      else if (this.mode === 'renew') this.$inertia.post(this.route('staffs.contracts.renew', [this.staff.id, this.target.id]), payload, opts);
      else this.$inertia.post(this.route('staffs.contracts.store', this.staff.id), payload, opts);
    },
    openStop(c) {
      this.stopErrors = {};
      this.stop = { reason_type: 'Resigned', effective_date: new Date().toISOString().slice(0, 10), reason: '', staff_status: 'Inactive' };
      this.stopFiles = []; this.stopFileError = '';
      this.stopTarget = c;
    },
    // a sensible staff status for the reason chosen (it can be changed)
    suggestStatus() {
      const t = this.stop.reason_type;
      this.stop.staff_status = ['Terminated by management', 'Dismissed', 'Redundancy'].includes(t) ? 'Terminated' : t === 'Retired' ? 'Retired' : t === 'Contract not renewed' ? 'Inactive' : t === 'Deceased' ? 'Inactive' : t === 'Other' ? 'keep' : 'Inactive';
    },
    stopContract() {
      this.stopErrors = {}; this.stopFileError = '';
      if (!this.stop.reason || this.stop.reason.trim().length < 3) { this.stopErrors = { reason: this.$t('Write the reason. It is kept in the staff history.') }; return; }
      const big = this.stopFiles.find(f => f.size > 10 * 1024 * 1024);
      if (big) { this.stopFileError = big.name + ': ' + this.$t('this file is over 10 MB.'); return; }
      this.busy = true;
      this.$inertia.post(this.route('staffs.contracts.stop', [this.staff.id, this.stopTarget.id]), { ...this.stop, attachments: this.stopFiles }, { forceFormData: true, preserveScroll: true, onSuccess: () => (this.stopTarget = null), onError: e => (this.stopErrors = e), onFinish: () => (this.busy = false) });
    },
    async removeContract(c) {
      if (await this.$confirm(this.$t('Delete contract') + ' ' + c.contract_number + '?')) this.$inertia.delete(this.route('staffs.contracts.destroy', [this.staff.id, c.id]), { preserveScroll: true });
    },
    async removeFile(a) {
      if (await this.$confirm(this.$t('Remove this document?') + ' ' + a.name)) this.$inertia.delete(a.delete_url, { preserveScroll: true });
    },
    view(file, contract) { this.viewer = { file, contract }; },
    printViewer() {
      const f = this.$refs.frame;
      try { if (f && f.contentWindow) { f.contentWindow.focus(); f.contentWindow.print(); return; } } catch (e) { /* the browser blocks printing inside the frame */ }
      window.open(this.viewer.file.preview_url, '_blank');
    },
  },
};
</script>

<style>
.sc > .lg-card { margin-bottom: 1rem; }
.sc-head-actions { display: flex; gap: 0.5rem; margin-left: auto; flex-wrap: wrap; }
.sc-now { display: flex; align-items: center; gap: 1.2rem; flex-wrap: wrap; padding: 1rem 1.1rem; border-radius: 18px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(59, 130, 246, 0.07)); box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.28); }
.sc-now.is-soon { background: linear-gradient(135deg, rgba(245, 158, 11, 0.14), rgba(250, 204, 21, 0.06)); box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.4); }
.sc-now.is-urgent, .sc-now.is-expired { background: linear-gradient(135deg, rgba(244, 63, 94, 0.12), rgba(245, 158, 11, 0.06)); box-shadow: inset 0 0 0 1px rgba(244, 63, 94, 0.4); }
.sc-now.is-open { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(99, 102, 241, 0.06)); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.3); }
.sc-now__ring { display: grid; place-content: center; flex: none; width: 92px; height: 92px; border-radius: 50%; text-align: center; background: #fff; box-shadow: 0 10px 22px -12px rgba(15, 37, 64, 0.5), inset 0 0 0 6px rgba(16, 185, 129, 0.25); } .sc-now.is-soon .sc-now__ring { box-shadow: 0 10px 22px -12px rgba(15, 37, 64, 0.5), inset 0 0 0 6px rgba(245, 158, 11, 0.35); } .sc-now.is-urgent .sc-now__ring, .sc-now.is-expired .sc-now__ring { box-shadow: 0 10px 22px -12px rgba(15, 37, 64, 0.5), inset 0 0 0 6px rgba(244, 63, 94, 0.4); }
.sc-now__ring b { font-size: 1.7rem; line-height: 1; color: #0f172a; font-variant-numeric: tabular-nums; } .sc-now__ring small { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #64748b; }
.sc-now__body { flex: 1 1 280px; min-width: 0; } .sc-now__top { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: 1rem; }
.sc-now__dates { display: flex; align-items: center; gap: 0.7rem; margin: 0.5rem 0; flex-wrap: wrap; font-weight: 700; } .sc-now__dates span { display: grid; } .sc-now__dates small { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; } .sc-now__dates i { width: 28px; height: 2px; border-radius: 2px; background: #94a3b8; }
.sc-bar { height: 8px; border-radius: 99px; background: rgba(100, 116, 139, 0.22); overflow: hidden; } .sc-bar span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #10b981, #f59e0b, #f43f5e); background-size: 400% 100%; }
.sc-now__btns { display: flex; gap: 0.5rem; margin-top: 0.8rem; flex-wrap: wrap; }
.sc-none, .sc-nofile { display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0.7rem 0.9rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600; color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a; } .sc-nofile { margin-top: 0.8rem; }
.sc-link { margin-left: 0.4rem; padding: 0; min-height: 0 !important; border: 0; background: none; font-weight: 800; color: #1d4ed8; text-decoration: underline; cursor: pointer; }
.sc-card { border-left: 4px solid #94a3b8; } .sc-card.is-ok, .sc-card.is-open { border-left-color: #10b981; } .sc-card.is-soon { border-left-color: #f59e0b; } .sc-card.is-urgent, .sc-card.is-expired { border-left-color: #f43f5e; }
.sc-card__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 0.8rem; } .sc-card__title { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: 1rem; }
.sc-card__btns { display: flex; gap: 0.35rem; flex-wrap: wrap; }
.sc-left { font-weight: 800; color: #047857; } .sc-left.is-soon { color: #b45309; } .sc-left.is-urgent, .sc-left.is-expired { color: #be123c; }
.sc-files { list-style: none; margin: 0.9rem 0 0; padding: 0; display: grid; gap: 0.4rem; } .sc-files li { display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.6rem; border-radius: 12px; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); }
.sc-file__name { flex: 1; min-width: 0; padding: 0; min-height: 0 !important; border: 0; background: none; text-align: left; font-size: 0.86rem; font-weight: 700; color: #1d4ed8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; } .sc-file__name:hover { text-decoration: underline; }
.sc-grid { display: grid; gap: 0.7rem 1rem; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); margin: 0; }
.sc-grid > div { min-width: 0; padding: 0.55rem 0.8rem; border-radius: 12px; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); }
.sc-grid .lg-span-all { grid-column: 1 / -1; }
.sc-grid dt { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; }
.sc-grid dd { margin: 3px 0 0; font-size: 0.88rem; font-weight: 600; color: var(--lg-ink, #0f172a); overflow-wrap: anywhere; }
.sc-hist h3 { display: flex; align-items: center; gap: 0.4rem; margin: 0 0 0.7rem; font-size: 0.95rem; font-weight: 800; } .sc-cnt { margin-left: 0.3rem; padding: 0 8px; border-radius: 99px; font-size: 0.74rem; background: rgba(100, 116, 139, 0.18); }
.sc-hist ol { list-style: none; margin: 0; padding: 0 0 0 4px; display: grid; gap: 0; }
.sc-hist li { position: relative; display: flex; align-items: center; gap: 0.7rem; padding: 0.45rem 0 0.45rem 1.1rem; border-left: 2px solid #cbd5e1; margin-left: 5px; } .sc-hist li:last-child { border-left-color: transparent; }
.sc-hist__dot { position: absolute; left: -7px; top: 50%; margin-top: -6px; width: 12px; height: 12px; border-radius: 50%; background: #94a3b8; box-shadow: 0 0 0 3px #fff; } .sc-hist li.is-ok .sc-hist__dot, .sc-hist li.is-open .sc-hist__dot { background: #10b981; } .sc-hist li.is-soon .sc-hist__dot { background: #f59e0b; } .sc-hist li.is-urgent .sc-hist__dot, .sc-hist li.is-expired .sc-hist__dot { background: #f43f5e; }
.sc-hist__body { flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; } .sc-hist__body small { flex-basis: 100%; font-size: 0.78rem; color: #64748b; }
.sc-prev { display: flex; align-items: center; gap: 0.5rem; width: 100%; min-height: 0 !important; margin: 0 0 0.8rem; padding: 0.55rem 0.9rem; border: 0; border-radius: 14px; font-size: 0.88rem; font-weight: 800; text-align: left; color: #334155; background: rgba(100, 116, 139, 0.1); box-shadow: inset 0 0 0 1px rgba(100, 116, 139, 0.22); cursor: pointer; } .sc-prev small { margin-left: auto; font-size: 0.74rem; font-weight: 700; color: #64748b; } .sc-prev__chev { transition: transform 0.2s; transform: rotate(-90deg); } .sc-prev__chev.open { transform: rotate(0); }
html.dark .sc-prev { color: #e2e8f0; } html.dark .sc-hist__dot { box-shadow: 0 0 0 3px #0f172a; }
/* the add / edit / renew box */
.scm { padding: 1.4rem 1.4rem 1.1rem; } .scm-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1rem; } .scm-head h3 { margin: 0; font-size: 1.1rem; font-weight: 800; } .scm-head p { margin: 2px 0 0; font-size: 0.8rem; color: #64748b; }
.scm-badge { display: grid; place-items: center; flex: none; width: 46px; height: 46px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.8); } .scm-badge.is-renew { background: linear-gradient(145deg, #10b981, #047857); } .scm-badge.is-end { background: linear-gradient(145deg, #f59e0b, #b45309); } .scm-badge.is-edit { background: linear-gradient(145deg, #6366f1, #4338ca); }
.scm-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem 1rem; } .scm-wide { grid-column: 1 / -1; }
.scm-f { display: grid; gap: 4px; align-content: start; } .scm-f > span { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; } .scm-f > span small { text-transform: none; letter-spacing: 0; font-weight: 600; }
.scm-in { width: 100%; padding: 0.6rem 0.75rem; border: 0; border-radius: 12px; box-shadow: inset 0 0 0 1.5px #cbd5e1; background: #fff; font-size: 0.9rem; } .scm-in:focus { outline: 0; box-shadow: inset 0 0 0 2px #3b82f6, 0 0 0 4px rgba(59, 130, 246, 0.16); } .scm-f.bad .scm-in { box-shadow: inset 0 0 0 2px #f43f5e; }
.scm-f em, .scm-err { font-style: normal; font-size: 0.78rem; font-weight: 700; color: #be123c; } .scm-err { margin: 0.4rem 0 0; }
.scm-quick { grid-column: 1 / -1; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; margin-top: -0.3rem; font-size: 0.78rem; color: #64748b; } .scm-quick button { min-height: 0 !important; padding: 0.2rem 0.7rem; border: 0; border-radius: 99px; font-size: 0.76rem; font-weight: 800; color: #1e3a8a; background: #e8f0fe; cursor: pointer; } .scm-quick button:hover { background: #d3e3fd; }
.scm-hint { display: flex; gap: 0.5rem; margin: 0.9rem 0 0; padding: 0.6rem 0.8rem; border-radius: 12px; font-size: 0.8rem; line-height: 1.45; color: #1e3a8a; background: #eff6ff; box-shadow: inset 0 0 0 1px #bfdbfe; } .scm-hint svg { flex: none; margin-top: 2px; }
.scm-attach { margin-top: 1rem; } .scm-label { display: block; margin-bottom: 0.4rem; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; }
.scm-actions { display: flex; gap: 0.6rem; margin-top: 1.2rem; } .scm-btn { flex: 1; min-height: 46px !important; border: 0; border-radius: 13px; font-size: 0.88rem; font-weight: 800; letter-spacing: 0.03em; cursor: pointer; }
.scm-btn--ghost { color: #334155; background: #fff; box-shadow: inset 0 0 0 1.5px #cbd5e1; } .scm-btn--go { color: #fff; background: linear-gradient(135deg, #1e3a8a, #0f2540); box-shadow: 0 12px 20px -12px rgba(15, 37, 64, 0.9); } .scm-btn--warn { color: #fff; background: linear-gradient(135deg, #f59e0b, #b45309); } .scm-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.scm-perm { grid-column: 1 / -1; display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.82rem; font-weight: 700; color: #065f46; background: #ecfdf5; box-shadow: inset 0 0 0 1px #a7f3d0; }
.scm-warn { display: flex; gap: 0.5rem; margin: 0.8rem 0 0; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a; }
.sc-stopbtn { color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1.5px #fcd34d; } .sc-stopbtn:hover { background: #fef3c7; }
.sc-stopped { margin-top: 0.8rem; padding: 0.6rem 0.85rem; border-radius: 12px; background: #fff7ed; box-shadow: inset 0 0 0 1px #fed7aa; } .sc-stopped b { display: flex; align-items: center; gap: 0.4rem; font-size: 0.86rem; color: #9a3412; } .sc-stopped p { margin: 0.3rem 0 0; font-size: 0.84rem; line-height: 1.45; color: #7c2d12; white-space: pre-line; }
html.dark .sc-stopped { background: rgba(249, 115, 22, 0.12); box-shadow: inset 0 0 0 1px rgba(249, 115, 22, 0.35); } html.dark .sc-stopped b, html.dark .sc-stopped p { color: #fed7aa; }
/* the document viewer */
.scv { display: grid; grid-template-rows: auto 1fr; height: min(86vh, 900px); } .scv-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; padding: 0.8rem 1rem; border-bottom: 1px solid #e2e8f0; } .scv-bar b { display: block; font-size: 0.95rem; overflow-wrap: anywhere; } .scv-bar small { font-size: 0.75rem; color: #64748b; } .scv-btns { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.scv-stage { min-height: 0; background: #e2e8f0; display: grid; place-items: center; overflow: auto; } .scv-stage iframe { width: 100%; height: 100%; border: 0; background: #fff; } .scv-stage img { max-width: 100%; max-height: 100%; object-fit: contain; } .scv-other { text-align: center; display: grid; gap: 0.6rem; justify-items: center; color: #475569; }
@media (max-width: 640px) { .scm-grid { grid-template-columns: 1fr; } .sc-now__ring { width: 76px; height: 76px; } .sc-now__ring b { font-size: 1.4rem; } }
html.dark .sc-now__ring { background: #0f172a; } html.dark .sc-now__ring b { color: #f1f5f9; } html.dark .scm-in { background: #0f172a; color: #f1f5f9; box-shadow: inset 0 0 0 1.5px #334155; } html.dark .scm-btn--ghost { color: #e2e8f0; background: transparent; box-shadow: inset 0 0 0 1.5px #475569; } html.dark .scm-hint { color: #bfdbfe; background: rgba(59, 130, 246, 0.14); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.35); } html.dark .scm-quick button { color: #bfdbfe; background: rgba(59, 130, 246, 0.2); } html.dark .scv-bar { border-color: #334155; } html.dark .scv-stage { background: #0b1220; }
@media (prefers-reduced-motion: reduce) { .sc-bar span { transition: none; } }

/* ---- polish: the contract in force ---- */
.sc-now { position: relative; overflow: hidden; animation: sc-rise 0.55s cubic-bezier(0.2, 0.9, 0.3, 1) both; }
.sc-now::before { content: ''; position: absolute; width: 220px; height: 220px; right: -60px; top: -90px; border-radius: 50%; background: radial-gradient(circle, rgba(16, 185, 129, 0.28), transparent 70%); animation: sc-drift 9s ease-in-out infinite; pointer-events: none; }
.sc-now.is-soon::before { background: radial-gradient(circle, rgba(245, 158, 11, 0.3), transparent 70%); } .sc-now.is-urgent::before, .sc-now.is-expired::before { background: radial-gradient(circle, rgba(244, 63, 94, 0.3), transparent 70%); } .sc-now.is-open::before { background: radial-gradient(circle, rgba(59, 130, 246, 0.28), transparent 70%); }
.sc-now::after { content: ''; position: absolute; top: 0; bottom: 0; left: -40%; width: 30%; background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.4), transparent); transform: skewX(-18deg); animation: sc-sheen 7s ease-in-out 1.2s infinite; pointer-events: none; }
.sc-now > * { position: relative; z-index: 1; }
.sc-now__ring { position: relative; box-shadow: 0 12px 24px -12px rgba(15, 37, 64, 0.55) !important; }
.sc-now__ring svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); overflow: visible; }
.sc-ring-track { fill: none; stroke: rgba(100, 116, 139, 0.16); stroke-width: 7; }
.sc-ring-fill { fill: none; stroke: #10b981; stroke-width: 7; stroke-linecap: round; filter: drop-shadow(0 0 4px rgba(16, 185, 129, 0.7)); animation: sc-ring-draw 1.3s cubic-bezier(0.3, 0.9, 0.3, 1) both, sc-ring-glow 3s ease-in-out 1.4s infinite; }
.sc-now.is-soon .sc-ring-fill { stroke: #f59e0b; filter: drop-shadow(0 0 4px rgba(245, 158, 11, 0.7)); } .sc-now.is-urgent .sc-ring-fill, .sc-now.is-expired .sc-ring-fill { stroke: #f43f5e; filter: drop-shadow(0 0 4px rgba(244, 63, 94, 0.7)); } .sc-now.is-open .sc-ring-fill { stroke: #3b82f6; filter: drop-shadow(0 0 4px rgba(59, 130, 246, 0.7)); }
.sc-now__ring b, .sc-now__ring small { position: relative; z-index: 1; }
.sc-bar { position: relative; } .sc-bar span { position: relative; transform-origin: left; animation: sc-grow 1.2s cubic-bezier(0.3, 0.9, 0.3, 1) both; } .sc-bar span::after { content: ''; position: absolute; inset: 0; background: linear-gradient(100deg, transparent 30%, rgba(255, 255, 255, 0.55), transparent 70%); background-size: 200% 100%; animation: sc-bar-shine 2.8s linear 1.2s infinite; }
.sc-now__btns .lg-btn { transition: transform 0.15s ease, box-shadow 0.2s ease; } .sc-now__btns .lg-btn:hover { transform: translateY(-2px); }

/* ---- the contract cards ---- */
.sc-in { animation: sc-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) both; }
.sc-card { transition: transform 0.2s ease, box-shadow 0.25s ease; } .sc-card:hover { transform: translateY(-2px); box-shadow: 0 22px 36px -24px rgba(15, 37, 64, 0.45); }
.sc-card.is-ok, .sc-card.is-open { border-image: linear-gradient(180deg, #10b981, #3b82f6) 1; } .sc-card.is-soon { border-image: linear-gradient(180deg, #f59e0b, #fbbf24) 1; } .sc-card.is-urgent, .sc-card.is-expired { border-image: linear-gradient(180deg, #f43f5e, #f59e0b) 1; }
.sc-grid dt { display: flex; align-items: center; gap: 5px; } .sc-grid dt svg { color: #6366f1; }
.sc-grid > div { transition: transform 0.15s ease, box-shadow 0.15s ease; } .sc-grid > div:hover { transform: translateY(-1px); box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.35), 0 10px 18px -14px rgba(99, 102, 241, 0.6); }
.sc-files li { transition: transform 0.15s ease, box-shadow 0.15s ease; } .sc-files li:hover { transform: translateX(3px); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.4); } .sc-files li > svg { padding: 5px; width: 28px; height: 28px; border-radius: 9px; color: #fff; background: linear-gradient(145deg, #3b82f6, #1d4ed8); box-sizing: border-box; }
.sc-hist li.is-ok .sc-hist__dot, .sc-hist li.is-open .sc-hist__dot, .sc-hist li.is-soon .sc-hist__dot, .sc-hist li.is-urgent .sc-hist__dot { animation: sc-dot 2.4s ease-out infinite; }
.sc-hist li { animation: sc-slide 0.45s ease both; } .sc-hist li:nth-child(2) { animation-delay: 0.08s; } .sc-hist li:nth-child(3) { animation-delay: 0.16s; } .sc-hist li:nth-child(4) { animation-delay: 0.24s; } .sc-hist li:nth-child(n+5) { animation-delay: 0.3s; }

/* ---- add / edit / renew / stop boxes ---- */
.scm { position: relative; padding: 0 0 1.1rem; overflow: hidden; }
.scm > * { margin-left: 1.4rem; margin-right: 1.4rem; }
.scm-head { margin: 0 0 1.1rem !important; padding: 1.2rem 1.4rem; color: #fff; background: linear-gradient(135deg, #1d4ed8, #0f2540); position: relative; overflow: hidden; }
.scm-head::before { content: ''; position: absolute; width: 180px; height: 180px; right: -40px; top: -80px; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, 0.28), transparent 70%); animation: sc-drift 8s ease-in-out infinite; }
.scm-head h3 { color: #fff; } .scm-head p { color: rgba(255, 255, 255, 0.78); }
.scm-badge { background: rgba(255, 255, 255, 0.18) !important; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.4) !important; animation: sc-pop 0.5s cubic-bezier(0.2, 1.5, 0.4, 1) both; }
.scm:has(.scm-badge.is-renew) .scm-head { background: linear-gradient(135deg, #059669, #064e3b); } .scm:has(.scm-badge.is-end) .scm-head { background: linear-gradient(135deg, #d97706, #7c2d12); }
.scm-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.scm-pills button { min-height: 0 !important; padding: 0.4rem 0.9rem; border: 0; border-radius: 99px; font-size: 0.8rem; font-weight: 800; color: #334155; background: #f1f5f9; box-shadow: inset 0 0 0 1.5px #e2e8f0; cursor: pointer; transition: all 0.18s ease; }
.scm-pills button:hover { transform: translateY(-1px); box-shadow: inset 0 0 0 1.5px #93c5fd; }
.scm-pills button.on { color: #fff; background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 10px 16px -10px #1d4ed8; }
.scm-in { transition: box-shadow 0.18s ease; }
.scm-btn { position: relative; overflow: hidden; transition: transform 0.15s ease, box-shadow 0.2s ease; } .scm-btn:hover { transform: translateY(-2px); }
.scm-btn--go::after, .scm-btn--warn::after { content: ''; position: absolute; top: 0; bottom: 0; left: -40%; width: 30%; background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.4), transparent); transform: skewX(-18deg); animation: sc-sheen 4.5s ease-in-out 1s infinite; }
.scm-grid > * { animation: sc-slide 0.4s ease both; } .scm-grid > *:nth-child(2) { animation-delay: 0.04s; } .scm-grid > *:nth-child(3) { animation-delay: 0.08s; } .scm-grid > *:nth-child(4) { animation-delay: 0.12s; } .scm-grid > *:nth-child(n+5) { animation-delay: 0.16s; }

@keyframes sc-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
@keyframes sc-slide { from { opacity: 0; transform: translateX(-12px); } to { opacity: 1; transform: none; } }
@keyframes sc-pop { from { opacity: 0; transform: scale(0.4) rotate(-20deg); } to { opacity: 1; transform: none; } }
@keyframes sc-drift { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(-18px, 14px); } }
@keyframes sc-sheen { 0%, 60% { left: -40%; } 100% { left: 130%; } }
@keyframes sc-ring-draw { from { stroke-dasharray: 0 100; } }
@keyframes sc-ring-glow { 0%, 100% { opacity: 1; } 50% { opacity: 0.72; } }
@keyframes sc-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes sc-bar-shine { from { background-position: 200% 0; } to { background-position: -200% 0; } }
@keyframes sc-dot { 0% { box-shadow: 0 0 0 3px #fff, 0 0 0 3px rgba(16, 185, 129, 0.5); } 100% { box-shadow: 0 0 0 3px #fff, 0 0 0 11px rgba(16, 185, 129, 0); } }
html.dark .scm-pills button { color: #e2e8f0; background: rgba(255, 255, 255, 0.06); box-shadow: inset 0 0 0 1.5px #334155; }
@media (prefers-reduced-motion: reduce) { .sc-now, .sc-now::before, .sc-now::after, .sc-ring-fill, .sc-bar span, .sc-bar span::after, .sc-in, .sc-hist li, .sc-hist__dot, .scm-badge, .scm-head::before, .scm-btn::after, .scm-grid > * { animation: none !important; } }
.sc-stoptag { flex: none; padding: 1px 9px; border-radius: 99px; font-style: normal; font-size: 0.68rem; font-weight: 800; color: #92400e; background: #fef3c7; }
</style>
