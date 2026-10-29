<template>
  <admin-layout :title="$t('Greeting Bundles')">
    <div class="lg-page ac sc-ui tr-ui hg-ui px-4 md:px-0">
      <page-header icon="collection" :title="$t('Greeting Bundles')" :subtitle="$t('Named groups of the people on the greeting list, such as Staff or Clients. When you send a greeting you choose which bundle receives it.')">
        <button v-if="can.manage" type="button" class="lg-btn tone" style="--tone: #be185d" @click="openNew"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('New Bundle') }}</button>
        <Link :href="route('holiday-greetings.contacts.index')" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="users" class="w-4 h-4" /></span>{{ $t('Greeting List') }}</Link>
        <Link :href="route('holiday-greetings.dashboard')" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="calendar" class="w-4 h-4" /></span>{{ $t('Holidays') }}</Link>
      </page-header>

      <div v-if="$page.props.errors.bundle" class="lg-note lg-note-red" role="alert" style="margin-bottom: 14px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $page.props.errors.bundle }}</span></div>

      <div class="hg-stats">
        <div class="hg-stat"><i><icons name="collection" class="w-5 h-5" /></i><span><b>{{ bundles.length }}</b>{{ $t('Bundles') }}</span></div>
        <div class="hg-stat is-green"><i><icons name="users" class="w-5 h-5" /></i><span><b>{{ people.length }}</b>{{ $t('People on the greeting list') }}</span></div>
      </div>

      <div v-if="!bundles.length" class="lg-card" style="text-align: center; padding: 34px 18px">
        <p class="hg-note" style="margin: 0 0 12px">{{ $t('No bundles yet. Without a bundle every greeting goes to the whole greeting list.') }}</p>
        <button v-if="can.manage" type="button" class="lg-btn tone tone-solid" style="--tone: #be185d" @click="openNew"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Create the First Bundle') }}</button>
      </div>

      <div v-else class="hg-bundles">
        <article v-for="b in bundles" :key="b.id" class="hg-bundle">
          <header><span class="hg-bundle-ico"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h3>{{ b.name }}</h3><p v-if="b.note">{{ b.note }}</p></div></header>
          <div class="hg-bundle-figs">
            <div><b>{{ b.people }}</b><span>{{ $t('people') }}</span></div>
            <div><b>{{ b.emails }}</b><span>{{ $t('emails') }}</span></div>
            <div><b>{{ b.mobiles }}</b><span>{{ $t('mobiles') }}</span></div>
          </div>
          <div class="hg-bundle-names">{{ names(b) }}</div>
          <footer v-if="can.manage">
            <button type="button" class="lg-btn tone" style="--tone: #2563eb" @click="openEdit(b)"><span class="tone-ico"><icons name="edit" class="w-4 h-4" /></span>{{ $t('Edit') }}</button>
            <button type="button" class="lg-btn tone" style="--tone: #dc2626" @click="remove(b)"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Remove') }}</button>
          </footer>
        </article>
      </div>
    </div>

    <travel-modal :show="open" :title="editing ? $t('Edit Bundle') : $t('New Bundle')" :subtitle="$t('A name and the people in it.')" icon="collection" :busy="form.processing" max-width="2xl" @close="open = false">
      <form id="hg-bundle-form" @submit.prevent="save">
        <div class="tm-grid">
          <div class="ac-f"><label>{{ $t('Name') }} <b class="hg-req">*</b></label><input ref="name" v-model="form.name" maxlength="120" autocomplete="off" :placeholder="$t('Staff, Clients, Directors...')" /><span class="ac-err">{{ form.errors.name }}</span></div>
          <div class="ac-f"><label>{{ $t('Note') }}</label><input v-model="form.note" maxlength="300" autocomplete="off" /><span class="ac-err">{{ form.errors.note }}</span></div>
        </div>
        <div class="hg-picker">
          <div class="hg-picker-bar">
            <input v-model="pf.q" type="search" :placeholder="$t('Find a person...')" autocomplete="off" />
            <simple-autocomplete v-model="pf.source" class="hg-sac" :options="sourceOptions" icon="users" :placeholder="$t('Everywhere')" />
            <simple-autocomplete v-model="pf.has" class="hg-sac" :options="hasOptions" icon="email" :placeholder="$t('Anything')" />
            <simple-autocomplete v-model="pf.role" class="hg-sac" :options="roleOpts" icon="key" :placeholder="$t('Any user role')" />
            <simple-autocomplete v-model="pf.cat" class="hg-sac hg-sac-wide" :options="catOpts" icon="folder" :placeholder="$t('Any category or position')" />
            <simple-autocomplete v-model="pf.pick" class="hg-sac" :options="pickOptions" icon="tick" :placeholder="$t('Everybody')" />
          </div>
          <div class="hg-picker-tools">
            <span><b>{{ form.ids.length }}</b> {{ $t('chosen') }} · {{ $t(':n shown', { n: shown.length }) }}</span>
            <button type="button" class="lg-btn lg-btn-soft" @click="pickShown(true)">{{ $t('Choose shown') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="pickShown(false)">{{ $t('Clear shown') }}</button>
          </div>
          <div class="hg-picker-list">
            <label v-for="p in shown" :key="p.id" class="hg-pick" :class="{ 'is-on': chosen.has(p.id) }">
              <input type="checkbox" :checked="chosen.has(p.id)" @change="toggle(p.id)" />
              <span class="hg-pick-main"><b>{{ p.full }}</b><small>{{ [p.email, p.mobile].filter(Boolean).join(' · ') || '-' }}</small></span>
              <span class="lg-chip" :class="srcChip(p.source)" style="text-transform: none">{{ sources[p.source] || p.source }}</span>
            </label>
            <p v-if="!shown.length" class="hg-note" style="text-align: center; padding: 18px">{{ $t('Nobody matches.') }}</p>
          </div>
          <span class="ac-err">{{ form.errors.ids }}</span>
        </div>
      </form>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="form.processing" @click="open = false">{{ $t('Cancel') }}</button>
        <button type="submit" form="hg-bundle-form" class="lg-btn tone tone-solid" style="--tone: #059669" :disabled="form.processing"><span class="tone-ico"><icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /></span>{{ $t('Save') }}</button>
      </template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';

export default {
  components: { AdminLayout, PageHeader, Link, TravelModal, SimpleAutocomplete },
  props: { roleOptions: { type: Array, default: () => [] }, catOptions: { type: Array, default: () => [] }, bundles: Array, people: Array, sources: Object, autoDefault: Number, can: Object },
  data() {
    return { open: false, editing: null, pf: { q: '', source: '', has: '', role: '', cat: '', pick: '' }, form: this.$inertia.form({ name: '', note: '', ids: [] }) };
  },
  computed: {
    chosen() { return new Set(this.form.ids); },
    sourceOptions() { return Object.entries(this.sources).map(([value, label]) => ({ value, label })); },
    hasOptions() { return [{ value: 'email', label: this.$t('An email') }, { value: 'mobile', label: this.$t('A mobile number') }, { value: 'both', label: this.$t('Both') }]; },
    roleOpts() { return this.roleOptions.map(r => ({ value: r, label: r })); },
    catOpts() { return this.catOptions.map(c => ({ value: c, label: c })); },
    pickOptions() { return [{ value: 'in', label: this.$t('Chosen') }, { value: 'out', label: this.$t('Not chosen') }]; },
    shown() {
      const q = this.pf.q.trim().toLowerCase();
      return this.people.filter(p => (!q || (p.full + ' ' + (p.email || '') + ' ' + (p.mobile || '') + ' ' + (p.group || '')).toLowerCase().includes(q))
        && (!this.pf.source || p.source === this.pf.source)
        && (!this.pf.role || (p.roles || []).includes(this.pf.role))
        && (!this.pf.cat || p.cat === this.pf.cat)
        && (!this.pf.has || (this.pf.has === 'email' ? p.email : this.pf.has === 'mobile' ? p.mobile : p.email && p.mobile))
        && (!this.pf.pick || (this.pf.pick === 'in') === this.chosen.has(p.id)));
    },
  },
  methods: {
    names(b) {
      const by = new Map(this.people.map(p => [p.id, p.full]));
      const n = b.ids.map(i => by.get(i)).filter(Boolean);
      return n.slice(0, 5).join(', ') + (n.length > 5 ? ' ' + this.$t('and :n more', { n: n.length - 5 }) : '') || this.$t('Nobody yet');
    },
    srcChip(s) { return { user: 'lg-chip-indigo', staff: 'lg-chip-green', contact: 'lg-chip-cyan', manual: 'lg-chip-amber' }[s] || 'lg-chip-slate'; },
    toggle(id) { this.form.ids = this.chosen.has(id) ? this.form.ids.filter(i => i !== id) : [...this.form.ids, id]; },
    pickShown(on) {
      const ids = new Set(this.shown.map(p => p.id));
      this.form.ids = on ? [...new Set([...this.form.ids, ...ids])] : this.form.ids.filter(i => !ids.has(i));
    },
    openNew() { this.editing = null; this.form.defaults({ name: '', note: '', ids: [] }).reset(); this.form.clearErrors(); this.pf = { q: '', source: '', has: '', pick: '' }; this.open = true; this.$nextTick(() => this.$refs.name && this.$refs.name.focus()); },
    openEdit(b) { this.editing = b; this.form.defaults({ name: b.name, note: b.note || '', ids: [...b.ids] }).reset(); this.form.clearErrors(); this.pf = { q: '', source: '', has: '', pick: '' }; this.open = true; },
    save() {
      const opts = { preserveScroll: true, onSuccess: () => { this.open = false; } };
      this.editing ? this.form.put(route('holiday-greetings.bundles.update', this.editing.id), opts) : this.form.post(route('holiday-greetings.bundles.store'), opts);
    },
    async remove(b) {
      if (!(await this.$confirm(this.$t('Remove the bundle :n? The people stay on the greeting list.', { n: b.name })))) return;
      this.$inertia.delete(route('holiday-greetings.bundles.destroy', b.id), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.hg-bundles { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
.hg-bundle { display: grid; gap: 14px; padding: 18px; border-radius: 22px; background: var(--lg-card); box-shadow: 0 10px 28px -14px rgba(15, 23, 42, 0.35), inset 0 0 0 1px var(--lg-line); }
.hg-bundle header { display: flex; align-items: center; gap: 12px; }
.hg-bundle h3 { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); }
.hg-bundle header p { margin: 2px 0 0; font-size: 0.8rem; color: var(--lg-mute); }
.hg-bundle-ico { flex: none; display: grid; place-items: center; width: 42px; height: 42px; border-radius: 14px; color: #fff; background: linear-gradient(135deg, #7c3aed, #be185d); box-shadow: 0 8px 18px -8px rgba(124, 58, 237, 0.7); }
.hg-bundle-figs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.hg-bundle-figs > div { display: grid; gap: 2px; padding: 10px; border-radius: 14px; background: var(--lg-soft); text-align: center; }
.hg-bundle-figs b { font-size: 1.3rem; color: var(--lg-ink); }
.hg-bundle-figs span { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute); }
.hg-bundle-names { font-size: 0.82rem; color: var(--lg-mute); line-height: 1.5; min-height: 2.4em; }
.hg-bundle footer { display: flex; gap: 8px; flex-wrap: wrap; }
.hg-picker { margin-top: 14px; display: grid; gap: 10px; }
.hg-picker-bar { display: flex; gap: 8px; flex-wrap: wrap; }
.hg-picker-bar input[type=search] { flex: 1 1 200px; min-height: 42px; padding: 0 14px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); box-shadow: 0 6px 14px -6px rgba(15, 23, 42, 0.35), 0 2px 4px rgba(15, 23, 42, 0.08); }
.hg-picker-bar input[type=search]:focus { outline: none; border-color: #be185d; box-shadow: 0 0 0 3px rgba(190, 24, 93, 0.16), 0 6px 14px -6px rgba(15, 23, 42, 0.35); }
.hg-picker-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 0.82rem; color: var(--lg-mute); }
.hg-picker-tools span { margin-right: auto; }
.hg-picker-list { max-height: 340px; overflow: auto; display: grid; gap: 6px; padding: 4px; }
.hg-pick { display: flex; align-items: center; gap: 12px; padding: 8px 12px; border-radius: 14px; background: var(--lg-soft); cursor: pointer; transition: box-shadow 0.15s ease; }
.hg-pick:hover { box-shadow: 0 6px 14px -8px rgba(15, 23, 42, 0.4); }
.hg-pick.is-on { background: rgba(124, 58, 237, 0.12); box-shadow: inset 0 0 0 1.5px rgba(124, 58, 237, 0.5); }
.hg-pick input { width: 18px; height: 18px; flex: none; accent-color: #7c3aed; }
.hg-pick-main { display: grid; min-width: 0; flex: 1; }
.hg-pick-main b { font-size: 0.9rem; color: var(--lg-ink); }
.hg-pick-main small { font-size: 0.76rem; color: var(--lg-mute); overflow-wrap: anywhere; }
.hg-sac { width: 170px; flex: 0 0 auto; }
.hg-sac-wide { width: 250px; }
</style>
