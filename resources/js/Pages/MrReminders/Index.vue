<template>
  <admin-layout :title="$t('All Requests Reminders')">
    <div class="lg-page ac sc-ui tr-ui hg-ui px-4 md:px-0">
      <page-header icon="bell" :title="$t('All Requests Reminders')" :subtitle="$t('Every reminder sent about an approved Material Request that was not yet cleared, and who was told.')">
        <button v-if="can.settings" type="button" class="lg-btn tone" style="--tone: #7c3aed" @click="openSettings"><span class="tone-ico"><icons name="users" class="w-4 h-4" /></span>{{ $t('Who Is Told') }}</button>
        <template v-if="can.print">
          <a :href="exportUrl('material-request-reminders.print')" target="_blank" rel="noopener" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span>{{ $t('Print') }}</a>
          <a :href="exportUrl('material-request-reminders.pdf')" class="lg-btn tone" style="--tone: #dc2626"><span class="tone-ico"><icons name="doc-text" class="w-4 h-4" /></span>{{ $t('PDF') }}</a>
          <a :href="exportUrl('material-request-reminders.csv')" class="lg-btn tone" style="--tone: #059669"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('CSV') }}</a>
        </template>
        <Link :href="route('material-requests.index')" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="list" class="w-4 h-4" /></span>{{ $t('Material Requests') }}</Link>
      </page-header>

      <div v-if="$page.props.errors.roles" class="lg-note lg-note-red" role="alert" style="margin-bottom: 14px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $page.props.errors.roles }}</span></div>

      <div class="hg-stats">
        <div class="hg-stat"><i><icons name="bell" class="w-5 h-5" /></i><span><b>{{ stats.total }}</b>{{ $t('Reminders sent') }}</span></div>
        <div class="hg-stat is-blue"><i><icons name="clip" class="w-5 h-5" /></i><span><b>{{ stats.requests }}</b>{{ $t('Requests reminded') }}</span></div>
        <div class="hg-stat is-green"><i><icons name="calendar" class="w-5 h-5" /></i><span><b>{{ stats.week }}</b>{{ $t('In the last 7 days') }}</span></div>
        <div class="hg-stat is-red"><i><icons name="exclamation" class="w-5 h-5" /></i><span><b>{{ stats.failed }}</b>{{ $t('With a failed message') }}</span></div>
      </div>

      <section class="lg-card">
        <div class="tr-fbar mrr-row1">
          <div class="ac-f"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" class="mrr-search" :placeholder="$t('Request reference or note')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('Project / Site') }}</label><acct-select v-model="f.warehouse_id" :options="warehouses" :placeholder="$t('All projects')" clearable empty-null /></div>
        </div>
        <div class="tr-fbar mrr-row2">
          <div class="ac-f"><label>{{ $t('Request status') }}</label><simple-autocomplete v-model="f.status" :options="statuses" icon="tick" :placeholder="$t('Any status')" /></div>
          <div class="ac-f"><label>{{ $t('Reminder from') }}</label><simple-autocomplete v-model="f.sender" :options="senders" icon="users" :placeholder="$t('Anybody')" /></div>
          <div class="ac-f"><label>{{ $t('Told') }}</label><simple-autocomplete v-model="f.recipient" :options="recipients" icon="users" :placeholder="$t('Anybody')" /></div>
          <div class="ac-f"><label>{{ $t('How') }}</label><simple-autocomplete v-model="f.channel" :options="channelOptions" icon="bell" :placeholder="$t('Any way')" /></div>
          <div class="ac-f"><label>{{ $t('Result') }}</label><simple-autocomplete v-model="f.result" :options="resultOptions" icon="tick" :placeholder="$t('Any result')" /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><input v-model="f.from" type="date" class="mrr-date" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><input v-model="f.to" type="date" class="mrr-date" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n reminders found', { n: rows.total }) }}</span></div>
      </section>

      <div class="mrr-list">
        <reminder-card v-for="r in rows.data" :key="r.id" :reminder="r">
          <template #aside>
            <Link :href="route('material-requests.show', r.request_id)" class="mrr-ref">{{ r.reference }}</Link>
            <span class="mrr-proj">{{ r.project || '-' }}</span>
            <span class="lg-chip" :class="r.cleared ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">{{ $t('Now: :s', { s: r.current_status || '-' }) }}</span>
            <a :href="route('material-requests.show', r.request_id)" target="_blank" rel="noopener" class="lg-btn tone mrr-open" style="--tone: #2563eb"><span class="tone-ico"><icons name="clip" class="w-4 h-4" /></span>{{ $t('Open Request') }}</a>
          </template>
        </reminder-card>
        <div v-if="!rows.data.length" class="lg-card mrr-empty">{{ hasFilters ? $t('No reminder matches these filters.') : $t('No reminder has been sent yet. Open an approved Material Request that is not yet cleared and press Reminder.') }}</div>
      </div>
      <pager :links="rows.links" />
    </div>

    <travel-modal v-if="settings" :show="settingsOpen" :title="$t('Who Is Told')" :subtitle="$t('The people who receive a reminder and how.')" icon="users" max-width="2xl" :busy="sf.processing" @close="settingsOpen = false">
      <form id="mrr-settings" @submit.prevent="saveSettings">
        <div class="mrr-fld"><span class="mrr-lab">{{ $t('Roles') }}</span>
          <div class="mrr-chips"><button v-for="r in settings.roleOptions" :key="r" type="button" :class="{ 'is-on': sf.roles.includes(r) }" @click="toggle(sf.roles, r)">{{ r }}</button></div>
          <span class="mrr-hint">{{ $t('Everybody with one of these roles is told. Super Admin by default.') }}</span></div>
        <div class="mrr-fld"><span class="mrr-lab">{{ $t('Also these people') }}</span>
          <input v-model="userFind" type="search" class="mrr-search" :placeholder="$t('Find a person...')" autocomplete="off" />
          <div class="mrr-people-pick">
            <label v-for="u in userShown" :key="u.id" :class="{ 'is-on': sf.users.includes(u.id) }"><input type="checkbox" :checked="sf.users.includes(u.id)" @change="toggle(sf.users, u.id)" />{{ u.name }}</label>
          </div>
          <span class="ac-err">{{ sf.errors.roles }}</span></div>
        <div class="mrr-fld"><span class="mrr-lab">{{ $t('Tell them by') }}</span>
          <label class="mrr-check"><input v-model="sf.app" type="checkbox" /> {{ $t('App alert (the bell in WIMS)') }}</label>
          <label class="mrr-check"><input v-model="sf.email" type="checkbox" /> {{ $t('Email (with the files)') }}</label>
          <label class="mrr-check"><input v-model="sf.sms" type="checkbox" /> {{ $t('SMS (needs WIMS Connect SMS switched on)') }}</label></div>
        <p class="mrr-hint">{{ $t('Now :n people are told: :l', { n: settings.audience.length, l: settings.audience.slice(0, 8).join(', ') + (settings.audience.length > 8 ? '...' : '') }) }} {{ $t('The person who sends a reminder is not sent their own.') }}</p>
      </form>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="sf.processing" @click="settingsOpen = false">{{ $t('Cancel') }}</button>
        <button type="submit" form="mrr-settings" class="lg-btn tone tone-solid" style="--tone: #059669" :disabled="sf.processing"><span class="tone-ico"><icons :name="sf.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': sf.processing }" /></span>{{ $t('Save') }}</button>
      </template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '@/Pages/Accounts/Pager.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import ReminderCard from './ReminderCard.vue';

export default {
  components: { AdminLayout, PageHeader, Link, Pager, TravelModal, SimpleAutocomplete, AcctSelect, ReminderCard },
  props: { rows: Object, filters: Object, stats: Object, warehouses: Array, senders: Array, recipients: Array, statuses: Array, settings: Object, can: Object },
  data() {
    const g = k => this.filters[k] || '';
    return {
      f: { q: g('q'), warehouse_id: g('warehouse_id'), status: g('status'), sender: g('sender'), recipient: g('recipient'), channel: g('channel'), result: g('result'), from: g('from'), to: g('to') },
      busy: false, timer: null, settingsOpen: false, userFind: '',
      sf: this.$inertia.form({ roles: this.settings ? [...this.settings.roles] : [], users: this.settings ? [...this.settings.users] : [], email: this.settings ? this.settings.email : true, sms: this.settings ? this.settings.sms : true, app: this.settings ? this.settings.app : true }),
    };
  },
  computed: {
    hasFilters() { return Object.values(this.f).some(v => v !== '' && v !== null); },
    channelOptions() { return [{ value: 'app', label: this.$t('App alert') }, { value: 'email', label: this.$t('Email') }, { value: 'sms', label: this.$t('SMS') }]; },
    resultOptions() { return [{ value: 'Sent', label: this.$t('Sent') }, { value: 'Failed', label: this.$t('Failed') }, { value: 'Skipped', label: this.$t('Skipped') }]; },
    userShown() {
      const q = this.userFind.trim().toLowerCase();
      return (this.settings ? this.settings.userOptions : []).filter(u => !q || u.name.toLowerCase().includes(q));
    },
  },
  watch: {
    f: { deep: true, handler() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); } },
  },
  methods: {
    clean() { return Object.fromEntries(Object.entries(this.f).filter(([, v]) => v !== '' && v !== null)); },
    go() { router.get(route('material-request-reminders.index'), this.clean(), { preserveState: true, preserveScroll: true, replace: true, onFinish: () => { this.busy = false; } }); },
    reset() { Object.keys(this.f).forEach(k => { this.f[k] = ''; }); },
    exportUrl(name) { return route(name, this.clean()); },
    toggle(list, v) { const i = list.indexOf(v); i === -1 ? list.push(v) : list.splice(i, 1); },
    openSettings() { this.sf.clearErrors(); this.settingsOpen = true; },
    saveSettings() { this.sf.put(route('material-request-reminders.settings'), { preserveScroll: true, onSuccess: () => { this.settingsOpen = false; } }); },
  },
};
</script>

<style>
.tr-ui .tr-fbar.mrr-row1 { grid-template-columns: minmax(200px, 1fr) minmax(300px, 2fr); }
.tr-ui .tr-fbar.mrr-row2 { margin-top: 14px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
@media (max-width: 700px) { .tr-ui .tr-fbar.mrr-row1 { grid-template-columns: minmax(0, 1fr); } }
.mrr-list { display: grid; gap: 12px; margin-top: 14px; }
.mrr-ref { font-weight: 800; color: #2563eb; font-size: 0.9rem; }
.lg-btn.tone.mrr-open { box-shadow: 0 8px 18px -8px rgba(37, 99, 235, 0.65), 0 2px 4px rgba(15, 23, 42, 0.12); }
.lg-btn.tone.mrr-open:hover { transform: translateY(-1px); box-shadow: 0 12px 22px -8px rgba(37, 99, 235, 0.75), 0 3px 6px rgba(15, 23, 42, 0.14); }
.mrr-proj { font-size: 0.78rem; color: var(--lg-mute); }
.mrr-empty { text-align: center; padding: 28px 16px; color: var(--lg-mute); }
.mrr-search, .mrr-date { width: 100%; min-height: 42px; padding: 0 14px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); box-shadow: 0 6px 14px -6px rgba(15, 23, 42, 0.3), 0 2px 4px rgba(15, 23, 42, 0.06); }
.mrr-search:focus, .mrr-date:focus { outline: none; border-color: #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.18); }
.mrr-fld { display: grid; gap: 8px; margin: 0 0 16px; }
.mrr-lab { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint, #64748b); }
.mrr-hint { font-size: 0.78rem; color: var(--lg-mute); }
.mrr-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.mrr-chips button { padding: 5px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 800; color: #6d28d9; background: rgba(124, 58, 237, 0.09); cursor: pointer; }
.mrr-chips button.is-on { color: #fff; background: #7c3aed; box-shadow: 0 6px 14px -6px rgba(124, 58, 237, 0.7); }
.mrr-people-pick { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 6px; max-height: 190px; overflow: auto; padding: 4px; }
.mrr-people-pick label { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 12px; background: var(--lg-soft); font-size: 0.82rem; cursor: pointer; }
.mrr-people-pick label.is-on { background: rgba(124, 58, 237, 0.12); box-shadow: inset 0 0 0 1.5px rgba(124, 58, 237, 0.5); }
.mrr-people-pick input, .mrr-check input { width: 18px !important; height: 18px !important; min-height: 0 !important; padding: 0 !important; accent-color: #7c3aed; flex: none; }
.mrr-check { display: flex; align-items: center; gap: 8px; margin: 4px 0; font-weight: 600; }
</style>
