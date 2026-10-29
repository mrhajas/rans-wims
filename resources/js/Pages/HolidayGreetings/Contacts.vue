<template>
  <admin-layout :title="$t('Greeting List')">
    <div class="lg-page ac sc-ui tr-ui hg-ui px-4 md:px-0">
      <page-header icon="users" :title="$t('Greeting List')" :subtitle="$t('The people who receive the holiday greetings. Each email address and each mobile number is kept once.')">
        <button v-if="can.manage" type="button" class="lg-btn tone" style="--tone: #be185d" @click="openAdd"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Add a Person') }}</button>
        <button v-if="can.manage" type="button" class="lg-btn tone" style="--tone: #2563eb" @click="openImport"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('Import') }}</button>
        <Link :href="route('holiday-greetings.bundles.index')" class="lg-btn tone" style="--tone: #7c3aed"><span class="tone-ico"><icons name="collection" class="w-4 h-4" /></span>{{ $t('Bundles') }}</Link>
        <Link :href="route('holiday-greetings.dashboard')" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="calendar" class="w-4 h-4" /></span>{{ $t('Holidays') }}</Link>
      </page-header>

      <div class="hg-stats">
        <div class="hg-stat"><i><icons name="users" class="w-5 h-5" /></i><span><b>{{ stats.people }}</b>{{ $t('People in the list') }}</span></div>
        <div class="hg-stat is-green"><i><icons name="tick" class="w-5 h-5" /></i><span><b>{{ stats.active }}</b>{{ $t('Will receive greetings') }}</span></div>
        <div class="hg-stat is-blue"><i><icons name="email" class="w-5 h-5" /></i><span><b>{{ stats.emails }}</b>{{ $t('With an email') }}</span></div>
        <div class="hg-stat is-cyan"><i><icons name="sms" class="w-5 h-5" /></i><span><b>{{ stats.mobiles }}</b>{{ $t('With a mobile number') }}</span></div>
      </div>

      <section class="lg-card">
        <div class="tr-fbar">
          <div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Name, title, email, mobile or group')" autocomplete="off" /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><simple-autocomplete v-model="f.source" :options="sourceOptions" icon="users" :placeholder="$t('Everywhere')" /></div>
          <div class="ac-f"><label>{{ $t('Has') }}</label><simple-autocomplete v-model="f.has" :options="hasOptions" icon="email" :placeholder="$t('Anything')" /></div>
          <div class="ac-f"><label>{{ $t('Status') }}</label><simple-autocomplete v-model="f.state" :options="stateOptions" icon="tick" :placeholder="$t('All')" /></div>
          <div class="tr-f tr-end"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn t-slate" :disabled="!hasFilters" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div>
        </div>
        <div class="tr-bar" style="margin: 12px 0 0"><span class="tr-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Updating...') : $t('Results update as you type or choose') }}</span><span class="tr-count">{{ $t(':n people found', { n: rows.total }) }}</span></div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Person') }}</th><th>{{ $t('Email') }}</th><th>{{ $t('Mobile') }}</th><th>{{ $t('From') }}</th><th>{{ $t('Receives') }}</th><th class="lg-sticky-end"></th></tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id" :class="{ 'is-dim': !r.active }">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Person')" class="tr-wrap"><div class="tr-cell"><b>{{ r.name }}</b><small>{{ r.title || '' }}</small></div></td>
              <td :data-label="$t('Email')" class="tr-wrap">{{ r.email || '-' }}</td>
              <td :data-label="$t('Mobile')" class="tr-nw">{{ r.mobile || '-' }}</td>
              <td :data-label="$t('From')"><span class="lg-chip" :class="srcChip(r.source)" style="text-transform: none">{{ sources[r.source] || r.source }}</span><small v-if="r.group && r.source === 'contact'" class="hg-note" style="display: block">{{ r.group }}</small></td>
              <td :data-label="$t('Receives')">
                <button v-if="can.manage" type="button" class="lg-chip" :class="r.active ? 'lg-chip-green' : 'lg-chip-slate'" style="text-transform: none; cursor: pointer" @click="toggle(r)">{{ r.active ? $t('Yes') : $t('Switched off') }}</button>
                <span v-else class="lg-chip" :class="r.active ? 'lg-chip-green' : 'lg-chip-slate'" style="text-transform: none">{{ r.active ? $t('Yes') : $t('Switched off') }}</span>
              </td>
              <td class="lg-sticky-end tr-acts-cell">
                <span v-if="can.manage" class="tr-acts">
                  <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="openEdit(r)"><icons name="edit" class="w-4 h-4" /></button>
                  <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="remove(r)"><icons name="trash" class="w-4 h-4" /></button>
                </span>
              </td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ hasFilters ? $t('Nobody matches these filters.') : $t('The list is empty. Import users, staff and contacts, or add a person by hand.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
    </div>

    <!-- add or edit one person -->
    <travel-modal :show="personOpen" :title="editing ? $t('Edit Person') : $t('Add a Person')" :subtitle="$t('Title and name are needed, and an email address or a mobile number (one of them is enough).')" icon="user" :busy="form.processing" @close="personOpen = false">
      <form id="hg-person-form" @submit.prevent="savePerson">
        <div class="tm-grid">
          <div class="ac-f"><label>{{ $t('Title') }} <b class="hg-req">*</b></label><input ref="title" v-model="form.title" list="hg-titles" maxlength="40" autocomplete="off" :placeholder="$t('Mr., Mrs., Dr., Eng....')" /><datalist id="hg-titles"><option v-for="t in titles" :key="t" :value="t"></option></datalist><span class="ac-err">{{ form.errors.title }}</span></div>
          <div class="ac-f"><label>{{ $t('Name') }} <b class="hg-req">*</b></label><input v-model="form.name" maxlength="190" autocomplete="off" /><span class="ac-err">{{ form.errors.name }}</span></div>
          <div class="ac-f tm-full"><label>{{ $t('Email') }}</label><input v-model="form.email" type="email" maxlength="190" autocomplete="off" /><span class="ac-err">{{ form.errors.email }}</span></div>
          <div class="ac-f tm-full"><label>{{ $t('Mobile') }}</label><input v-model="form.mobile" type="tel" inputmode="tel" maxlength="25" autocomplete="off" :placeholder="$t('0674020223')" /><small class="hg-hint">{{ $t('You can type it without the country code: 0674020223 is written as +255674020223.') }}</small><span class="ac-err">{{ form.errors.mobile }}</span></div>
          <div v-if="mobileSeen" class="tm-full hg-hint" style="color: #047857; font-weight: 700">{{ $t('WIMS will save this number as :m', { m: mobileSeen }) }}</div>
        </div>
      </form>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="form.processing" @click="personOpen = false">{{ $t('Cancel') }}</button>
        <button type="submit" form="hg-person-form" class="lg-btn tone tone-solid" style="--tone: #059669" :disabled="form.processing"><span class="tone-ico"><icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /></span>{{ $t('Save') }}</button>
      </template>
    </travel-modal>

    <!-- import -->
    <travel-modal :show="importOpen" :title="$t('Import People')" :subtitle="$t('Only people with an email address or a mobile number are brought in. Somebody already in the list is never added twice.')" icon="download" :busy="importing" @close="importOpen = false">
      <div class="hg-imp">
        <label v-for="(label, key) in importSources" :key="key" class="hg-imp-row" :class="{ 'is-on': imp.sources.includes(key) }"><input v-model="imp.sources" type="checkbox" :value="key" /><span><b>{{ label }}</b><small>{{ importHelp[key] }}</small></span></label>
        <div v-if="imp.sources.includes('contacts') && Object.keys(categories).length" class="hg-field" style="margin-top: 12px"><label>{{ $t('Contact categories') }}</label>
          <div class="hg-chips"><button v-for="(n, c) in categories" :key="c" type="button" :class="{ 'is-on': imp.categories.includes(c) }" :style="imp.categories.includes(c) ? 'background:#be185d;color:#fff' : ''" @click="toggleCat(c)">{{ c }} ({{ n }})</button></div>
          <span class="hg-hint">{{ imp.categories.length ? $t('Only the chosen categories.') : $t('All categories. Choose some to narrow it down.') }}</span></div>
        <div v-if="preview" class="hg-imp-sum">
          <div><b>{{ preview.added }}</b> {{ $t('will be added') }}</div>
          <div><b>{{ preview.already }}</b> {{ $t('are already in the list') }}</div>
          <div><b>{{ preview.no_contact }}</b> {{ $t('have no email or mobile number') }}</div>
          <small v-if="preview.sample.length" class="hg-note">{{ $t('For example:') }} {{ preview.sample.join(', ') }}</small>
        </div>
        <div v-else-if="importing" class="hg-note">{{ $t('Checking...') }}</div>
      </div>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="importing" @click="importOpen = false">{{ $t('Cancel') }}</button>
        <button type="button" class="lg-btn tone tone-solid" style="--tone: #2563eb" :disabled="importing || !imp.sources.length || !preview || !preview.added" @click="doImport"><span class="tone-ico"><icons :name="importing ? 'refresh' : 'download'" class="w-4 h-4" :class="{ 'animate-spin': importing }" /></span>{{ preview ? $t('Import :n people', { n: preview.added }) : $t('Import') }}</button>
      </template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '@/Pages/Accounts/Pager.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';

export default {
  components: { AdminLayout, PageHeader, Link, Pager, TravelModal, SimpleAutocomplete },
  props: { rows: Object, filters: Object, stats: Object, sources: Object, titles: Array, importSources: Object, categories: Object, can: Object },
  data() {
    return {
      f: { q: this.filters.q || '', source: this.filters.source || '', has: this.filters.has || '', state: this.filters.state || '' }, busy: false, timer: null,
      personOpen: false, editing: null, form: this.$inertia.form({ title: '', name: '', email: '', mobile: '' }),
      importOpen: false, importing: false, preview: null, previewTimer: null, imp: { sources: ['users', 'staff', 'contacts'], categories: [] },
      importHelp: { users: this.$t('People who sign in to WIMS'), staff: this.$t('Active staff records'), contacts: this.$t('The Contacts of WIMS (suppliers, clients, engineers...)') },
    };
  },
  computed: {
    sourceOptions() { return Object.entries(this.sources).map(([value, label]) => ({ value, label })); },
    hasOptions() { return [{ value: 'email', label: this.$t('An email') }, { value: 'mobile', label: this.$t('A mobile number') }, { value: 'both', label: this.$t('Both') }]; },
    stateOptions() { return [{ value: 'active', label: this.$t('Receiving') }, { value: 'inactive', label: this.$t('Switched off') }]; },
    hasFilters() { return Object.values(this.f).some(v => v); },
    mobileSeen() {
      const d = (this.form.mobile || '').replace(/\D/g, '');
      if (!d) return '';
      let r = d.startsWith('00') ? d.slice(2) : d;
      if (r.startsWith('255')) { r = r.slice(3); if (r.length === 10 && r[0] === '0') r = r.slice(1); } else if (r.startsWith('0')) r = r.slice(1);
      return /^[67]\d{8}$/.test(r) ? '+255' + r : '';
    },
  },
  watch: {
    f: { deep: true, handler() { clearTimeout(this.timer); this.busy = true; this.timer = setTimeout(this.go, 380); } },
    'imp.sources': { handler() { this.queuePreview(); } },
    'imp.categories': { handler() { this.queuePreview(); } },
  },
  methods: {
    go() { router.get(route('holiday-greetings.contacts.index'), Object.fromEntries(Object.entries(this.f).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true, onFinish: () => { this.busy = false; } }); },
    reset() { this.f = { q: '', source: '', has: '', state: '' }; },
    srcChip(s) { return { user: 'lg-chip-indigo', staff: 'lg-chip-green', contact: 'lg-chip-cyan', manual: 'lg-chip-amber' }[s] || 'lg-chip-slate'; },
    openAdd() { this.editing = null; this.form.defaults({ title: '', name: '', email: '', mobile: '' }).reset(); this.form.clearErrors(); this.personOpen = true; this.$nextTick(() => this.$refs.title && this.$refs.title.focus()); },
    openEdit(r) { this.editing = r; this.form.defaults({ title: r.title || '', name: r.name, email: r.email || '', mobile: r.mobile || '' }).reset(); this.form.clearErrors(); this.personOpen = true; },
    savePerson() {
      this.form.clearErrors();
      if (!this.form.title.trim()) { this.form.setError('title', this.$t('Enter the title, for example Mr., Mrs., Dr. or Eng.')); return; }
      if (!this.form.name.trim()) { this.form.setError('name', this.$t('Enter the name.')); return; }
      if (!this.form.email.trim() && !this.form.mobile.trim()) { this.form.setError('email', this.$t('Enter an email address or a mobile number (one of them is enough).')); return; }
      const opts = { preserveScroll: true, onSuccess: () => { this.personOpen = false; } };
      this.editing ? this.form.put(route('holiday-greetings.contacts.update', this.editing.id), opts) : this.form.post(route('holiday-greetings.contacts.store'), opts);
    },
    toggle(r) { router.put(route('holiday-greetings.contacts.update', r.id), { active: !r.active }, { preserveScroll: true, preserveState: true }); },
    async remove(r) {
      if (!(await this.$confirm(this.$t('Remove :n from the greeting list?', { n: ((r.title ? r.title + ' ' : '') + r.name) })))) return;
      router.delete(route('holiday-greetings.contacts.destroy', r.id), { preserveScroll: true });
    },
    openImport() { this.importOpen = true; this.preview = null; this.queuePreview(); },
    toggleCat(c) { const i = this.imp.categories.indexOf(c); i === -1 ? this.imp.categories.push(c) : this.imp.categories.splice(i, 1); },
    queuePreview() {
      if (!this.importOpen) return;
      clearTimeout(this.previewTimer);
      this.preview = null;
      if (!this.imp.sources.length) return;
      this.previewTimer = setTimeout(this.loadPreview, 300);
    },
    async loadPreview() {
      this.importing = true;
      try { this.preview = (await window.axios.post(route('holiday-greetings.contacts.import-preview'), this.imp)).data; } catch (e) { this.preview = null; } finally { this.importing = false; }
    },
    doImport() {
      this.importing = true;
      router.post(route('holiday-greetings.contacts.import'), this.imp, { preserveScroll: true, onSuccess: () => { this.importOpen = false; }, onFinish: () => { this.importing = false; } });
    },
  },
};
</script>

<style scoped>
.hg-req { color: #dc2626; }
.hg-imp-row { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; margin-bottom: 8px; border-radius: 16px; cursor: pointer; background: var(--lg-card); box-shadow: inset 0 0 0 1px var(--lg-line); }
.hg-imp-row.is-on { box-shadow: inset 0 0 0 2px #be185d; background: rgba(190, 24, 93, 0.05); }
.hg-imp-row input { margin-top: 4px; }
.hg-imp-row small { display: block; color: var(--lg-mute); font-size: 0.76rem; }
.hg-imp-sum { display: grid; gap: 4px; margin-top: 14px; padding: 14px 16px; border-radius: 16px; background: rgba(5, 150, 105, 0.08); box-shadow: inset 0 0 0 1px rgba(5, 150, 105, 0.25); }
.hg-imp-sum b { font-size: 1.15rem; color: #047857; }
</style>
