<template>
  <admin-layout :title="$t('Licence Manager')">
    <div class="lg-page px-4 md:px-0 lic">
      <page-header icon="lock-closed" :title="$t('Licence Manager')" :subtitle="$t('Issue, track and control the licences of WIMS. Visible to the Licence Manager only.')">
        <Link :href="route('wims-licence.show')" class="lg-btn lg-btn-soft"><icons name="check-double" class="w-4 h-4" />{{ $t('WIMS Licence page') }}</Link>
        <a v-if="current" :href="route('licence-manager.print', current.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print current licence') }}</a>
      </page-header>

      <licence-hero v-if="current" :licence="current" :server-now="serverNow" class="lic-rise" />
      <div v-else class="lic-warnbar"><icons name="exclamation" />{{ $t('No licence is active. Issue one below and activate it.') }}</div>

      <div class="lic-tiles">
        <div v-for="(t, i) in tiles" :key="t.label" class="lic-tile lic-rise" :class="'c-' + t.c" :style="{ '--i': i + 1 }">
          <span class="lic-tile__ico"><icons :name="t.icon" /></span>
          <div><small>{{ $t(t.label) }}</small><b>{{ t.value }}</b></div>
        </div>
        <div v-if="current" class="lic-tile lic-rise" :class="current.status === 'Deactivated' ? 'c-emerald' : 'c-rose'" style="--i: 6; cursor: pointer" @click="current.status === 'Deactivated' ? reactivate(current) : askDeactivate(current)">
          <span class="lic-tile__ico"><icons :name="current.status === 'Deactivated' ? 'lock-open' : 'lock-closed'" /></span>
          <div><small>{{ $t('Master switch') }}</small><b>{{ current.status === 'Deactivated' ? $t('Reactivate licence') : $t('Deactivate licence') }}</b></div>
        </div>
      </div>

      <section class="lic-card lic-rise" style="--i: 7; --c: #4f46e5">
        <div class="lic-card__head">
          <span class="lic-card__ico"><icons :name="editing ? 'edit' : 'plus'" /></span>
          <div class="flex-1"><h2>{{ editing ? $t('Edit licence') + ' ' + editing.number : $t('Issue a new licence') }}</h2><p>{{ editing ? $t('Every change is written to the licence history. Changing the licensee or the dates recalculates the last 32 characters of the key.') : $t('A 128-character key and an encrypted licence file are generated for you.') }}</p></div>
          <button v-if="editing" type="button" class="lic-btn lic-btn--sm" @click="cancelEdit"><icons name="cross" />{{ $t('Cancel edit') }}</button>
        </div>
        <form id="lic-form" class="lic-form" @submit.prevent="issue">
          <div class="lic-field w6"><label>{{ $t('Licensed to') }}</label>
            <auto-complete id="lic_licensee" v-model="form.licensee" :suggestions="licenseeOptions" freeform clearable :placeholder="$t('Search or type who the licence is for')" />
            <p v-if="errors.licensee" class="lic-err">{{ errors.licensee }}</p>
          </div>
          <div class="lic-field w6"><label>{{ $t('Edition') }}</label>
            <div class="lic-chips"><button v-for="e in editions" :key="e" type="button" class="lic-chipbtn" :class="{ on: form.edition === e }" @click="pickEdition(e)">{{ e }}<small class="ml-1 opacity-70">· {{ editionHint[e] }}</small></button></div>
          </div>
          <div class="lic-field"><label>{{ $t('Licence type') }}</label><auto-complete id="lic_type" v-model="form.licence_type" :suggestions="types" :placeholder="$t('Search the licence type')" /></div>
          <div class="lic-field"><label>{{ $t('Valid from') }}</label><date-picker v-model="form.starts_at" /><p v-if="errors.starts_at" class="lic-err">{{ errors.starts_at }}</p></div>
          <div class="lic-field"><label>{{ $t('Duration') }}</label>
            <div class="lic-chips">
              <button v-for="m in [6, 12, 24, 36, 48, 60]" :key="m" type="button" class="lic-chipbtn" :class="{ on: !custom && form.duration === m }" @click="pickDuration(m)">{{ m >= 12 ? m / 12 + ' ' + (m === 12 ? $t('year') : $t('years')) : m + ' ' + $t('months') }}</button>
              <button type="button" class="lic-chipbtn" :class="{ on: custom }" @click="custom = true">{{ $t('Custom') }}</button>
            </div>
          </div>
          <div v-if="custom" class="lic-field"><label>{{ $t('Valid until') }}</label><date-picker v-model="form.expires_at" :min="form.starts_at" /><p v-if="errors.expires_at" class="lic-err">{{ errors.expires_at }}</p></div>
          <div class="lic-field"><label>{{ $t('Valid until (calculated)') }}</label><input class="lic-input" :value="endPreview" readonly /></div>
          <div class="lic-field"><label>{{ $t('User limit (optional)') }}</label><input v-model.number="form.max_users" type="number" min="1" class="lic-input" :placeholder="$t('Unlimited')" /></div>
          <div class="lic-field"><label>{{ $t('Support level') }}</label><auto-complete id="lic_support" v-model="form.support_level" :suggestions="supportLevels" :placeholder="$t('Search the support level')" /></div>
          <div class="lic-field"><label>{{ $t('Modules covered') }}</label><auto-complete id="lic_modules" v-model="form.modules" :suggestions="moduleOptions" freeform clearable :placeholder="$t('Search or type the modules covered')" /></div>
          <div class="lic-field w12"><label>{{ $t('Notes (internal)') }}</label><textarea v-model="form.notes" class="lic-textarea" rows="2" maxlength="2000"></textarea></div>
          <div class="lic-field w12 flex flex-wrap items-center justify-between gap-3">
            <label v-if="!editing" class="lic-switch"><input v-model="form.activate_now" type="checkbox" /><i></i><span>{{ $t('Activate on this system straight away') }}</span></label>
            <span v-else class="text-xs text-slate-500">{{ $t('Editing keeps the licence number, history and status.') }}</span>
            <button class="lic-btn lic-btn--primary" type="submit" :disabled="busy"><icons :name="busy ? 'refresh' : editing ? 'tick' : 'key'" :class="{ 'animate-spin': busy }" />{{ editing ? $t('Save changes') : $t('Generate licence') }}</button>
          </div>
        </form>
      </section>

      <section class="lic-card lic-rise" style="--i: 7.5; --c: #7c3aed">
        <div class="lic-card__head"><span class="lic-card__ico"><icons name="eye" /></span><div><h2>{{ $t('Licence page access') }}</h2><p>{{ $t('Tick Allow Licence View Icon to let the people of a company see the WIMS Licence icon in their profile menu and open their Licence page. Untick it to hide both. You always see everything.') }}</p></div></div>
        <ul class="lic-access">
          <li v-for="v in viewAccess" :key="v.licensee" :class="{ off: !v.allowed }">
            <div class="min-w-0"><b>{{ v.licensee }}</b><span><template v-if="v.current">{{ $t('Running licence') }} · </template>{{ v.licences }} {{ v.licences === 1 ? $t('licence') : $t('licences') }}</span></div>
            <label class="lic-access__sw"><input type="checkbox" :checked="v.allowed" :disabled="accessBusy === v.licensee" @change="setAccess(v, $event.target.checked)" /><i></i><span>{{ $t('Allow Licence View Icon') }}</span></label>
          </li>
          <li v-if="!viewAccess.length"><span>{{ $t('No licence has been issued yet.') }}</span></li>
        </ul>
        <p v-if="viewAccess.some(v => !v.allowed && v.current)" class="lic-access__note">{{ $t('The running licence is switched off for its company: they cannot open the Licence page while it is valid. If it expires or is deactivated, the page opens again so they can apply a new licence.') }}</p>
      </section>

      <section class="lic-card lic-rise" style="--i: 8; --c: #059669">
        <div class="lic-card__head"><span class="lic-card__ico"><icons name="list" /></span><div><h2>{{ $t('Licence history') }}</h2><p>{{ $t('Every licence ever issued, from when to when, and what happened to it.') }}</p></div></div>
        <div class="lic-tbl-wrap">
          <table class="lic-tbl">
            <thead><tr><th>{{ $t('No') }}</th><th>{{ $t('Licence') }}</th><th>{{ $t('Valid from') }} → {{ $t('until') }}</th><th>{{ $t('Duration') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Issued') }}</th><th>{{ $t('Activated') }}</th><th>{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in licences" :key="l.id" :class="'st-' + l.state">
                <td class="num">{{ i + 1 }}</td>
                <td><b>{{ l.number }}</b><span class="sub">{{ l.licensee }} · {{ l.edition }}</span></td>
                <td>{{ $date(l.starts_at) }} → {{ $date(l.expires_at) }}<div class="lic-mini"><span :style="{ width: l.elapsed + '%' }"></span></div></td>
                <td>{{ l.duration }}<span v-if="['active', 'expiring'].includes(l.state)" class="sub">{{ l.days_left }} {{ $t('days left') }}</span></td>
                <td><span class="lic-pill" :class="{ live: ['active', 'expiring'].includes(l.state) }"><i></i>{{ stateLabel(l.state) }}</span><span v-if="l.deactivation_reason" class="sub">{{ l.deactivation_reason }}</span></td>
                <td>{{ $date(l.issued_at) }}<span class="sub">{{ l.issued_by || '-' }}</span></td>
                <td><template v-if="l.applied_at">{{ $date(l.applied_at) }}<span class="sub">{{ l.applied_by || '-' }}</span></template><span v-else class="sub">{{ $t('Not yet') }}</span></td>
                <td><div class="lic-acts">
                  <button class="lic-btn lic-btn--sm" type="button" :title="$t('View key')" @click="viewing = l"><icons name="eye" />{{ $t('Key') }}</button>
                  <button class="lic-btn lic-btn--sm" type="button" :title="$t('Edit this licence')" @click="startEdit(l)"><icons name="edit" />{{ $t('Edit') }}</button>
                  <a class="lic-btn lic-btn--sm" :href="route('licence-manager.download', l.id)" :title="$t('Download the encrypted licence file')"><icons name="download" />{{ $t('File') }}</a>
                  <a class="lic-btn lic-btn--sm" :href="route('licence-manager.print', l.id)" target="_blank" rel="noopener"><icons name="printer" />{{ $t('Print') }}</a>
                  <button v-if="['Issued', 'Superseded'].includes(l.status) && l.state !== 'expired'" class="lic-btn lic-btn--green lic-btn--sm" type="button" @click="activate(l)"><icons name="tick" />{{ $t('Activate') }}</button>
                  <button v-if="l.status === 'Active'" class="lic-btn lic-btn--danger lic-btn--sm" type="button" @click="askDeactivate(l)"><icons name="lock-closed" />{{ $t('Deactivate') }}</button>
                  <button v-if="l.status === 'Deactivated' && l.state !== 'expired'" class="lic-btn lic-btn--amber lic-btn--sm" type="button" @click="reactivate(l)"><icons name="refresh" />{{ $t('Reactivate') }}</button>
                  <button v-if="l.status !== 'Active'" class="lic-btn lic-btn--sm lic-btn--del" type="button" :title="$t('Delete this licence record')" @click="remove(l)"><icons name="trash" /></button>
                </div></td>
              </tr>
              <tr v-if="!licences.length"><td colspan="8" class="py-6 text-center text-slate-500">{{ $t('No licences yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lic-card lic-rise" style="--i: 9; --c: #d97706">
        <div class="lic-card__head"><span class="lic-card__ico"><icons name="clock" /></span><div><h2>{{ $t('Activity') }}</h2><p>{{ $t('Every licensee has its own history. Pick who to look at.') }}</p></div></div>
        <div class="lic-actbar">
          <div class="lic-actbar__pick"><auto-complete id="lic_act_licensee" v-model="actLicensee" :suggestions="actLicensees" clearable :placeholder="$t('All licensees')" /></div>
          <span class="lic-actbar__count">{{ actEvents.length }} {{ $t('event(s)') }}</span>
        </div>
        <ul class="lic-events">
          <li v-for="e in shownEvents" :key="e.id" :class="'ev-' + e.event">
            <span class="ico"><icons :name="eventIcon(e.event)" /></span>
            <div class="min-w-0"><b>{{ eventLabel(e.event) }} <small class="font-semibold text-slate-400">{{ e.licence }}<template v-if="!actLicensee"> · {{ e.licensee }}</template></small></b><span>{{ e.note }}</span><span>{{ $t('by') }} {{ e.by }}<template v-if="e.ip"> · {{ e.ip }}</template></span></div>
            <time>{{ when(e.at) }}</time>
          </li>
          <li v-if="!actEvents.length"><span>{{ $t('No activity for this licensee yet.') }}</span></li>
        </ul>
        <button v-if="actEvents.length > 10" type="button" class="lic-more" @click="actOpen = !actOpen">
          <icons :name="actOpen ? 'chevron-up' : 'chevron-down'" />{{ actOpen ? $t('Collapse') : $t('Expand') + ' (' + (actEvents.length - 10) + ' ' + $t('more') + ')' }}
        </button>
      </section>
    </div>

    <licence-saving v-if="anim" :kind="anim.kind" :phase="anim.phase" />

    <teleport to="body">
      <div v-if="viewing" class="lic-modal lic" @click.self="close">
        <div class="lic-modal__card" :class="'st-' + viewing.state">
          <div class="mb-3 flex items-center justify-between gap-3">
            <div><span v-if="justIssued" class="lic-live"><i></i>{{ $t('Licence generated') }}</span><h2 class="mt-1 text-lg font-extrabold">{{ viewing.number }}</h2><p class="text-sm text-slate-500">{{ viewing.licensee }} · {{ $date(viewing.starts_at) }} → {{ $date(viewing.expires_at) }}</p></div>
            <button class="lic-btn lic-btn--sm" type="button" @click="close"><icons name="cross" />{{ $t('Close') }}</button>
          </div>
          <licence-key :licence-key="viewing.key" :blocks="viewing.key_blocks" :start-revealed="justIssued" />
          <div class="mt-4 flex flex-wrap gap-2">
            <a class="lic-btn lic-btn--primary" :href="route('licence-manager.download', viewing.id)"><icons name="download" />{{ $t('Download licence file') }}</a>
            <a class="lic-btn" :href="route('licence-manager.print', viewing.id)" target="_blank" rel="noopener"><icons name="printer" />{{ $t('Print report') }}</a>
          </div>
          <p class="mt-3 text-xs text-slate-500">{{ $t('Send the file (or the key) to the Super Admins. They apply it on the WIMS Licence page.') }}</p>
        </div>
      </div>

      <div v-if="deactivating || reactivating" class="lic-modal lic" @click.self="closePw">
        <form class="lic-modal__card" style="max-width: 520px" autocomplete="off" @submit.prevent="submitPw">
          <div class="lic-card__head" :style="{ '--c': reactivating ? '#059669' : '#e11d48' }"><span class="lic-card__ico"><icons :name="reactivating ? 'refresh' : 'lock-closed'" /></span>
            <div v-if="deactivating"><h2>{{ $t('Deactivate') }} {{ deactivating.number }}?</h2><p>{{ $t('Everyone except you will be signed out and blocked from signing in until a licence is active again.') }}</p></div>
            <div v-else><h2>{{ $t('Reactivate') }} {{ reactivating.number }}?</h2><p>{{ $t('Everyone can sign in again once it is active.') }}</p></div>
          </div>
          <div v-if="deactivating" class="lic-field w12"><label>{{ $t('Reason (shown in the history)') }}</label><input v-model="reason" class="lic-input" maxlength="500" :placeholder="$t('e.g. payment not received')" /></div>
          <div class="lic-field w12 mt-3"><label>{{ $t('Confirm with your password') }}</label>
            <div class="lic-pw"><input ref="pw" v-model="password" :type="showPw ? 'text' : 'password'" class="lic-input" autocomplete="new-password" :placeholder="$t('Your password')" /><button type="button" :aria-label="$t('Show password')" @click="showPw = !showPw"><icons :name="showPw ? 'eye-off' : 'eye'" /></button></div>
            <p v-if="errors.password" class="lic-err lic-shake">{{ errors.password }}</p>
          </div>
          <div class="mt-4 flex justify-end gap-2">
            <button class="lic-btn" type="button" @click="closePw">{{ $t('Cancel') }}</button>
            <button class="lic-btn" :class="reactivating ? 'lic-btn--green' : 'lic-btn--danger'" type="submit" :disabled="busy || !password"><icons :name="reactivating ? 'refresh' : 'lock-closed'" />{{ reactivating ? $t('Reactivate licence') : $t('Deactivate licence') }}</button>
          </div>
        </form>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LicenceHero from './LicenceHero.vue';
import LicenceKey from './LicenceKey.vue';
import LicenceSaving from './LicenceSaving.vue';

const EVENTS = {
  issued: ['Licence issued', 'plus'], activated: ['Licence activated', 'tick'], reactivated: ['Licence reactivated', 'refresh'], deactivated: ['Licence deactivated', 'lock-closed'],
  superseded: ['Replaced by a newer licence', 'duplicate'], edited: ['Licence edited', 'edit'], 'file-downloaded': ['Licence file downloaded', 'download'], imported: ['Licence file imported', 'upload'], printed: ['Report printed', 'printer'],
};

export default {
  components: { Link, AdminLayout, PageHeader, DatePicker, AutoComplete, LicenceHero, LicenceKey, LicenceSaving },
  props: { viewAccess: { type: Array, default: () => [] }, current: Object, licences: Array, events: Array, stats: Object, defaults: Object, licenseeOptions: { type: Array, default: () => [] }, editions: Array, types: Array, supportLevels: { type: Array, default: () => ['Standard', 'Priority', 'Premium'] }, moduleOptions: { type: Array, default: () => ['All modules'] }, contact: String, serverNow: String },
  data() {
    return {
      accessBusy: '',
      form: { licensee: this.defaults.licensee, edition: 'Professional', licence_type: this.defaults.licence_type, starts_at: this.defaults.starts_at, duration: this.defaults.duration, expires_at: '', max_users: null, support_level: 'Priority', modules: 'All modules', notes: '', activate_now: false },
      custom: false, editing: null, syncing: false, busy: false, anim: null, animStart: 0, poller: null, viewing: null, justIssued: false, deactivating: null, reactivating: null, reason: '', password: '', showPw: false, actLicensee: '', actOpen: false,
    };
  },
  computed: {
    errors() { return this.$page.props.errors || {}; },
    actLicensees() { return [...new Set(this.events.map(e => e.licensee).filter(Boolean))].sort((a, b) => a.localeCompare(b)); },
    actEvents() { return this.actLicensee ? this.events.filter(e => e.licensee === this.actLicensee) : this.events; },
    shownEvents() { return this.actOpen ? this.actEvents : this.actEvents.slice(0, 10); },
    editionHint() { return { Standard: this.$t('6 months or 1 year'), Professional: this.$t('2 years'), Enterprise: this.$t('3 years and more') }; },
    tiles() {
      const s = this.stats;
      return [
        { icon: 'doc-text', c: 'indigo', label: 'Licences issued', value: s.issued },
        { icon: 'tick', c: 'emerald', label: 'Active now', value: s.active },
        { icon: 'clock', c: 'blue', label: 'Issued, not applied', value: s.pending },
        { icon: 'exclamation', c: 'amber', label: 'Expired', value: s.expired },
        { icon: 'lock-closed', c: 'rose', label: 'Deactivated', value: s.deactivated },
      ];
    },
    // The date the licence ends: the custom date, or the start date plus the chosen number of months. Used for the preview AND for saving.
    endDate() {
      if (this.custom) return this.form.expires_at || '';
      if (!this.form.starts_at) return '';
      const d = new Date(this.form.starts_at + 'T00:00:00');
      d.setMonth(d.getMonth() + Number(this.form.duration));
      return d.toLocaleDateString('en-CA');
    },
    endPreview() { return this.endDate ? this.$date(this.endDate) : '-'; },
  },
  created() { this.actLicensee = this.current?.licensee || ''; },
  mounted() {
    // Keeps this page current when the licence is changed in another tab or by another session.
    this.poller = setInterval(() => { if (!document.hidden && !this.busy && !this.editing && !this.anim) router.reload({ only: ['current', 'licences', 'events', 'stats', 'viewAccess'], preserveScroll: true, preserveState: true }); }, 20000);
  },
  beforeUnmount() { clearInterval(this.poller); },
  watch: {
    // A custom end date also picks the edition: up to 1 year Standard, 2 years Professional, 3 years or more Enterprise.
    'form.expires_at'(v) { if (this.custom && v && !this.syncing) this.form.edition = this.editionFor(this.monthsBetween(this.form.starts_at, v)); },
    'form.starts_at'() { if (this.custom && this.form.expires_at && !this.syncing) this.form.edition = this.editionFor(this.monthsBetween(this.form.starts_at, this.form.expires_at)); },
  },
  methods: {
    setAccess(v, allowed) {
      this.accessBusy = v.licensee;
      router.put(this.route('licence-manager.view-access'), { licensee: v.licensee, allowed }, { preserveScroll: true, onFinish: () => (this.accessBusy = '') });
    },
    monthsBetween(a, b) {
      if (!a || !b) return 0;
      const x = new Date(a + 'T00:00:00'), y = new Date(b + 'T00:00:00');
      let m = (y.getFullYear() - x.getFullYear()) * 12 + (y.getMonth() - x.getMonth());
      if (y.getDate() < x.getDate()) m -= 1;
      return Math.max(0, m);
    },
    addMonths(date, months) {
      const d = new Date(date + 'T00:00:00');
      d.setMonth(d.getMonth() + Number(months));
      return d.toLocaleDateString('en-CA');
    },
    editionFor(months) { return months >= 36 ? 'Enterprise' : months >= 24 ? 'Professional' : 'Standard'; },
    // Edition picks the usual duration (kept when it already fits): Standard 1 year (or 6 months), Professional 2 years, Enterprise 3 years or more.
    pickEdition(e) {
      this.form.edition = e;
      const m = this.custom ? this.monthsBetween(this.form.starts_at, this.form.expires_at) : Number(this.form.duration);
      const fits = e === 'Standard' ? m === 6 || m === 12 : e === 'Professional' ? m >= 24 && m < 36 : m >= 36;
      if (fits) return;
      this.custom = false;
      this.form.duration = e === 'Standard' ? 12 : e === 'Professional' ? 24 : 36;
    },
    pickDuration(m) {
      this.custom = false;
      this.form.duration = m;
      this.form.edition = this.editionFor(m);
    },
    startEdit(l) {
      this.syncing = true;
      this.editing = l;
      const months = this.monthsBetween(l.starts_at, l.expires_at);
      const exact = [6, 12, 24, 36, 48, 60].includes(months) && this.addMonths(l.starts_at, months) === l.expires_at;
      this.form = { licensee: l.licensee, edition: l.edition, licence_type: l.type, starts_at: l.starts_at, duration: exact ? months : 12, expires_at: l.expires_at, max_users: l.max_users, support_level: l.support_level || 'Standard', modules: l.modules || 'All modules', notes: l.notes || '', activate_now: false };
      this.custom = !exact;
      this.$nextTick(() => { this.syncing = false; document.getElementById('lic-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
    },
    cancelEdit() {
      this.syncing = true;
      this.editing = null;
      this.custom = false;
      this.form = { licensee: this.defaults.licensee, edition: 'Professional', licence_type: this.defaults.licence_type, starts_at: this.defaults.starts_at, duration: 24, expires_at: '', max_users: null, support_level: 'Priority', modules: 'All modules', notes: '', activate_now: false };
      this.$nextTick(() => (this.syncing = false));
    },
    async remove(l) {
      if (!(await this.$confirm(this.$t('Delete licence :n? This removes the record and its history.', { n: l.number }), { variant: 'danger' }))) return;
      router.delete(this.route('licence-manager.destroy', l.id), { preserveScroll: true });
    },
    stateLabel(s) { return this.$t({ active: 'Active', expiring: 'Expiring', expired: 'Expired', deactivated: 'Deactivated', scheduled: 'Scheduled', issued: 'Issued', superseded: 'Replaced' }[s] || s); },
    eventLabel(e) { return this.$t((EVENTS[e] || [e])[0]); },
    eventIcon(e) { return (EVENTS[e] || [0, 'info'])[1]; },
    when(iso) { return iso ? new Date(iso).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''; },
    close() { this.viewing = null; this.justIssued = false; },
    issue() {
      if (this.editing) return this.saveEdit();
      const before = new Set(this.licences.map(l => l.id));
      const payload = { ...this.form, duration: this.custom ? null : this.form.duration, expires_at: this.endDate };
      this.send('issue', () => router.post(this.route('licence-manager.store'), payload, this.callbacks(() => {
        const fresh = this.licences.find(l => !before.has(l.id));
        if (fresh) { this.viewing = fresh; this.justIssued = true; }
      })));
    },
    saveEdit() {
      const payload = { ...this.form, expires_at: this.endDate };
      this.send('edit', () => router.put(this.route('licence-manager.update', this.editing.id), payload, this.callbacks(() => {
        this.cancelEdit();
        window.scrollTo?.({ top: 0 });
        document.querySelector('[scroll-region]')?.scrollTo?.({ top: 0, behavior: 'smooth' });
      })));
    },
    // Shows the "forging / saving" animation for at least 1.3 seconds, then the success burst, then runs the follow-up (open the key box...).
    send(kind, run) {
      this.anim = { kind, phase: 'working' };
      this.animStart = Date.now();
      this.busy = true;
      run();
    },
    callbacks(afterDone) {
      const settle = fn => setTimeout(fn, Math.max(0, 1300 - (Date.now() - this.animStart)));
      return {
        preserveScroll: true,
        onSuccess: () => settle(() => { this.anim.phase = 'done'; setTimeout(() => { this.anim = null; afterDone(); }, 1250); }),
        onError: () => { this.anim = null; },
        onFinish: () => (this.busy = false),
      };
    },
    async activate(l) {
      if (!(await this.$confirm(this.$t('Make :n the active licence on this system?', { n: l.number })))) return;
      router.post(this.route('licence-manager.activate', l.id), {}, { preserveScroll: true });
    },
    askDeactivate(l) { this.deactivating = l; this.reactivating = null; this.openPw(); },
    reactivate(l) { this.reactivating = l; this.deactivating = null; this.openPw(); },
    openPw() { this.reason = ''; this.password = ''; this.showPw = false; this.$nextTick(() => this.$refs.pw?.focus()); },
    closePw() { this.deactivating = null; this.reactivating = null; this.password = ''; },
    submitPw() {
      if (!this.password) return;
      this.busy = true;
      const l = this.deactivating || this.reactivating;
      const route = this.deactivating ? 'licence-manager.deactivate' : 'licence-manager.reactivate';
      router.post(this.route(route, l.id), { password: this.password, reason: this.reason }, {
        preserveScroll: true,
        onSuccess: () => this.closePw(),
        onError: () => { this.password = ''; this.$nextTick(() => this.$refs.pw?.focus()); },
        onFinish: () => (this.busy = false),
      });
    },
  },
};
</script>
