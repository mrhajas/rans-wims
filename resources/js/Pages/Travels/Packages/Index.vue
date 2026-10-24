<template>
  <admin-layout :title="$t('Travel Packages')">
    <div class="lg-page ac sc-ui tr-ui px-4 md:px-0">
      <page-header icon="collection" :title="$t('Travel Packages')" :subtitle="$t('Ready-made travels, for example Unguja to Pemba, with what they cost for one person. A package starts a Travel Request for the people who travel.')">
        <button v-if="can.create" type="button" class="lg-btn tone tone-solid" style="--tone: #059669" @click="openForm(null)"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('New Package') }}</button>
        <Link :href="route('travels.index')" class="lg-btn tone" style="--tone: #0891b2"><span class="tone-ico"><icons name="plane" class="w-4 h-4" /></span>{{ $t('Travels') }}</Link>
      </page-header>

      <div class="tp-stats">
        <button type="button" class="tp-stat" :class="{ 'is-on': !f.status }" @click="setStatus('')"><icons name="collection" class="w-5 h-5" /><span><b>{{ stats.total }}</b>{{ $t('Packages') }}</span></button>
        <button type="button" class="tp-stat is-green" :class="{ 'is-on': f.status === 'active' }" @click="setStatus('active')"><icons name="tick" class="w-5 h-5" /><span><b>{{ stats.active }}</b>{{ $t('On') }}</span></button>
        <button type="button" class="tp-stat is-amber" :class="{ 'is-on': f.status === 'inactive' }" @click="setStatus('inactive')"><icons name="pause" class="w-5 h-5" /><span><b>{{ stats.inactive }}</b>{{ $t('Off') }}</span></button>
      </div>

      <section class="lg-card" style="margin-bottom: 12px">
        <div class="tr-fbar"><div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Name, place or country')" autocomplete="off" /></div></div>
      </section>

      <div v-if="!rows.data.length" class="tp-empty"><icons name="collection" class="w-8 h-8" /><b>{{ $t('No travel package yet.') }}</b><span v-if="can.create">{{ $t('Create the first one, for example Unguja to Pemba.') }}</span></div>

      <div class="tp-grid">
        <article v-for="p in rows.data" :key="p.id" class="tp-card" :class="{ 'is-off': !p.is_active }">
          <header class="tp-card__head">
            <span class="tp-card__ico"><icons name="plane" class="w-5 h-5" /></span>
            <div class="tp-card__ttl"><h3>{{ p.name }}</h3><small><icons name="map-pin" class="w-3 h-3" />{{ p.route }}</small></div>
            <span class="lg-chip" :class="p.is_active ? 'lg-chip-green' : 'lg-chip-slate'" style="text-transform: none">{{ p.is_active ? $t('On') : $t('Off') }}</span>
          </header>
          <div class="tp-amounts">
            <div v-for="a in amountCols" :key="a.k" class="tp-am" :style="{ '--c': a.c }"><small><icons :name="a.i" class="w-3.5 h-3.5" />{{ a.l }}</small><b>{{ money(p[a.k]) }}</b></div>
          </div>
          <div class="tp-total"><span>{{ $t('Per person') }} <em>{{ p.trip_label }}</em></span><b>{{ p.currency }} {{ money(p.total) }}</b></div>
          <p v-if="p.currency !== 'TZS'" class="tp-sub"><icons name="refresh" class="w-3 h-3" />{{ $t('Rate') }}: 1 {{ p.currency }} = {{ rate(p.exchange_rate) }} TZS</p>
          <p v-if="p.ticket_agent" class="tp-sub"><icons name="user" class="w-3 h-3" />{{ $t('Ticket agent') }}: {{ p.ticket_agent }}</p>
          <p v-if="p.notes" class="tp-note">{{ p.notes }}</p>
          <footer class="tp-foot">
            <span class="tp-meta"><icons name="plane" class="w-3 h-3" />{{ p.requests }} {{ $t('requests') }}<template v-if="can.audit"> · <icons name="clock" class="w-3 h-3" />{{ p.history }} {{ $t('in history') }}</template></span>
            <div class="tp-actions">
              <Link v-if="can.use && p.is_active" :href="route('travels.packages.request', p.id)" class="lg-btn tone tone-solid tp-use" style="--tone: #7c3aed"><span class="tone-ico"><icons name="send" class="w-4 h-4" /></span>{{ $t('Request Travel') }}</Link>
              <button v-if="can.audit" type="button" class="tp-ib" style="--c: #4f46e5" :aria-label="$t('History')" :data-tip="$t('History')" @click="history = p.id"><icons name="clock" class="w-4 h-4" /></button>
              <button v-if="can.update" type="button" class="tp-ib" style="--c: #2563eb" :aria-label="$t('Edit')" :data-tip="$t('Edit')" @click="openForm(p)"><icons name="edit" class="w-4 h-4" /></button>
              <button v-if="can.update" type="button" class="tp-ib" :style="{ '--c': p.is_active ? '#b45309' : '#16a34a' }" :aria-label="p.is_active ? $t('Switch off') : $t('Switch on')" :data-tip="p.is_active ? $t('Switch off') : $t('Switch on')" @click="toggle(p)"><icons :name="p.is_active ? 'pause' : 'play'" class="w-4 h-4" /></button>
              <button v-if="can.delete" type="button" class="tp-ib" style="--c: #dc2626" :aria-label="$t('Delete')" :data-tip="$t('Delete')" @click="remove(p)"><icons name="trash" class="w-4 h-4" /></button>
            </div>
          </footer>
        </article>
      </div>

      <pager :rows="rows" />

      <!-- create / edit -->
      <tec-dialog-modal :show="formOpen" max-width="3xl" @close="formOpen = false">
        <template #title><div class="tp-mt"><span class="tp-mt__ico"><icons :name="editing ? 'edit' : 'plus'" class="w-5 h-5" /></span><b>{{ editing ? $t('Change :n', { n: editing.name }) : $t('New Travel Package') }}</b></div></template>
        <template #content>
          <div class="lg-page ac sc-ui tp-form">
            <div v-if="editing" class="lg-note lg-note-blue"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Every change of the amounts and rates is kept in the history of this package, with the date and who made it.') }}</span></div>
            <div class="lg-fields">
              <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Package name') }} *</label><input v-model="form.name" :placeholder="$t('e.g. Unguja to Pemba')" /><span class="ac-err">{{ form.errors.name }}</span></div>
            </div>
            <sc-tabs :tabs="tripTabs" :model-value="form.trip_type" @update:model-value="k => (form.trip_type = k)" />
            <div class="lg-fields" style="margin-top: 8px">
              <div class="ac-f"><label>{{ $t('From (place)') }}</label><input v-model="form.from_place" :placeholder="$t('e.g. Unguja')" /></div>
              <div class="ac-f"><label>{{ $t('From (country)') }}</label><input v-model="form.from_country" :placeholder="$t('Tanzania')" /></div>
              <div class="ac-f"><label>{{ $t('To (place)') }} *</label><input v-model="form.to_place" :placeholder="$t('e.g. Pemba')" /><span class="ac-err">{{ form.errors.to_place }}</span></div>
              <div class="ac-f"><label>{{ $t('To (country)') }}</label><input v-model="form.to_country" :placeholder="$t('Tanzania')" /></div>
            </div>
            <h4 class="tp-h"><icons name="cash" class="w-4 h-4" />{{ $t('What it costs for ONE person') }}</h4>
            <div class="lg-fields">
              <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="form.currency" :options="currencies.map(c => ({ value: c, label: c }))" /></div>
              <div v-if="form.currency !== 'TZS'" class="ac-f"><label>{{ $t('Exchange rate (TZS per :c)', { c: form.currency }) }} *</label><input v-model="form.exchange_rate" inputmode="decimal" :placeholder="$t('e.g. 2600')" /><span class="ac-err">{{ form.errors.exchange_rate }}</span></div>
            </div>
            <div class="tp-money">
              <div v-for="a in amountCols" :key="a.k" class="ac-f tp-mf" :style="{ '--c': a.c }"><label><icons :name="a.i" class="w-3.5 h-3.5" />{{ a.l }}</label><input v-model="form[a.k]" inputmode="decimal" placeholder="0" /><span class="ac-err">{{ form.errors[a.k] }}</span></div>
            </div>
            <div class="tp-sum"><span>{{ $t('Total per person') }}</span><b>{{ form.currency }} {{ money(formTotal) }}</b><small v-if="form.currency !== 'TZS' && Number(form.exchange_rate) > 0">= TZS {{ money(formTotal * Number(form.exchange_rate)) }}</small></div>
            <div class="lg-fields" style="margin-top: 8px">
              <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Ticket agent (optional)') }}</label><acct-select v-model="form.ticket_agent_contact_id" :options="issuers" :placeholder="$t('Choose the ticket agent')" clearable /><span class="ac-err">{{ form.errors.ticket_agent_contact_id }}</span></div>
              <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Notes') }}</label><textarea v-model="form.notes" rows="2" /></div>
            </div>
          </div>
        </template>
        <template #footer>
          <div class="lg-page tp-foot-btns"><button type="button" class="lg-btn lg-btn-soft" @click="formOpen = false">{{ $t('Close') }}</button>
            <button type="button" class="lg-btn tone tone-solid" style="--tone: #059669" :disabled="form.processing" @click="save"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ editing ? $t('Save changes') : $t('Save package') }}</button></div>
        </template>
      </tec-dialog-modal>

      <package-history :show="!!history" :package-id="history" @close="history = null" />
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import ScTabs from '../../Subcontracts/ScTabs.vue';
import Pager from '../../Accounts/Pager.vue';
import PackageHistory from './PackageHistory.vue';

export default {
  components: { AdminLayout, PageHeader, Link, TecDialogModal, AcctSelect, ScTabs, Pager, PackageHistory },
  props: { rows: Object, filters: Object, issuers: Array, currencies: Array, stats: Object, can: Object },
  data() {
    return { f: { ...this.filters }, timer: null, formOpen: false, editing: null, history: null, form: this.blank() };
  },
  computed: {
    amountCols() {
      return [
        { k: 'ticket_amount', l: this.$t('Ticket'), i: 'plane', c: '#0284c7' },
        { k: 'accommodation_amount', l: this.$t('Accommodation'), i: 'building', c: '#7c3aed' },
        { k: 'per_diem_amount', l: this.$t('Expenses / Per Diem'), i: 'cash', c: '#d97706' },
        { k: 'transport_amount', l: this.$t('Inside Transport'), i: 'car', c: '#0d9488' },
      ];
    },
    tripTabs() { return [{ key: 'return', label: this.$t('Return trip'), icon: 'refresh', tone: 'sky' }, { key: 'one_way', label: this.$t('One way'), icon: 'forward', tone: 'indigo' }]; },
    formTotal() { return ['ticket_amount', 'accommodation_amount', 'per_diem_amount', 'transport_amount'].reduce((s, k) => s + (Number(String(this.form[k]).replace(/,/g, '')) || 0), 0); },
  },
  watch: { 'f.search'() { this.queue(); }, 'form.currency'(c) { if (c === 'TZS') this.form.exchange_rate = ''; } },
  methods: {
    blank() { return this.$inertia.form({ name: '', trip_type: 'return', from_place: '', from_country: '', to_place: '', to_country: '', currency: 'TZS', exchange_rate: '', ticket_amount: '', accommodation_amount: '', per_diem_amount: '', transport_amount: '', ticket_agent_contact_id: null, notes: '' }); },
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    rate(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 6 }); },
    queue() { clearTimeout(this.timer); this.timer = setTimeout(this.go, 350); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); router.get(route('travels.packages.index'), q, { preserveState: true, preserveScroll: true, replace: true }); },
    setStatus(s) { this.f = { ...this.f, status: s }; this.go(); },
    openForm(p) {
      this.editing = p;
      this.form = this.blank();
      if (p) {
        ['name', 'trip_type', 'from_place', 'from_country', 'to_place', 'to_country', 'currency', 'notes'].forEach(k => (this.form[k] = p[k] || (k === 'trip_type' ? 'return' : '')));
        this.form.exchange_rate = p.currency === 'TZS' ? '' : String(Number(p.exchange_rate));
        ['ticket_amount', 'accommodation_amount', 'per_diem_amount', 'transport_amount'].forEach(k => (this.form[k] = Number(p[k]) ? String(Number(p[k])) : ''));
        this.form.ticket_agent_contact_id = p.ticket_agent_id || null;
      }
      this.formOpen = true;
    },
    save() {
      const url = this.editing ? route('travels.packages.update', this.editing.id) : route('travels.packages.store');
      const send = d => ({ ...d, exchange_rate: d.currency === 'TZS' ? 1 : d.exchange_rate });
      const opts = { preserveScroll: true, onSuccess: () => { this.formOpen = false; } };
      if (this.editing) this.form.transform(send).put(url, opts); else this.form.transform(send).post(url, opts);
    },
    toggle(p) { router.post(route('travels.packages.toggle', p.id), {}, { preserveScroll: true }); },
    async remove(p) {
      const ok = await this.$confirm(this.$t('Delete the package :n? Travels already made from it stay as they are. This is kept in its history.', { n: p.name }), { variant: 'danger', confirmText: this.$t('Delete') });
      if (ok) router.delete(route('travels.packages.destroy', p.id), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.tp-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
.tp-stat { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; border: 0; text-align: left; cursor: pointer; background: var(--sc-card, #fff); color: var(--sc-ink, #0f2540); box-shadow: 0 10px 20px -14px rgba(15, 37, 64, 0.5), inset 0 0 0 1px var(--sc-line, #e3e9f2); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.tp-stat:hover { transform: translateY(-2px); } .tp-stat > svg { flex: none; color: #4f46e5; } .tp-stat.is-green > svg { color: #0d8a5a; } .tp-stat.is-amber > svg { color: #b45309; }
.tp-stat > span { display: grid; font-size: 0.78rem; font-weight: 700; color: var(--sc-mute, #64748b); } .tp-stat b { font-size: 1.3rem; color: var(--sc-ink, #0f2540); line-height: 1.1; }
.tp-stat.is-on { box-shadow: 0 0 0 2px #4f46e5, 0 14px 22px -14px rgba(79, 70, 229, 0.6); }
.tp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.tp-card { display: grid; gap: 10px; align-content: start; padding: 14px; border-radius: 22px; background: var(--sc-card, #fff); box-shadow: 0 16px 28px -20px rgba(15, 37, 64, 0.55), inset 0 0 0 1px var(--sc-line, #e3e9f2); } .tp-card.is-off { opacity: 0.72; }
.tp-card__head { display: flex; align-items: center; gap: 10px; } .tp-card__ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 42px; height: 42px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #0ea5e9, #4f46e5); box-shadow: 0 10px 16px -10px #3730a3; } .tp-card__ico > div { flex: none; margin: 0; }
.tp-card__ttl { min-width: 0; flex: 1; } .tp-card__ttl h3 { margin: 0; font-size: 1.02rem; font-weight: 800; color: var(--sc-ink, #0f2540); overflow-wrap: anywhere; } .tp-card__ttl small { display: flex; align-items: center; gap: 4px; font-size: 0.76rem; font-weight: 600; color: var(--sc-mute, #64748b); } .tp-card__ttl small :deep(div) { flex: none; margin: 0; }
.tp-amounts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.tp-am { display: grid; gap: 1px; padding: 8px 10px; border-radius: 14px; background: color-mix(in srgb, var(--c) 8%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 24%, transparent); } .tp-am small { display: flex; align-items: center; gap: 5px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--c); } .tp-am small :deep(div) { flex: none; margin: 0; } .tp-am b { font-size: 1rem; font-variant-numeric: tabular-nums; color: var(--sc-ink, #0f2540); }
.tp-total { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-radius: 14px; color: #fff; background: linear-gradient(135deg, #1c3f66, #0f2540); } .tp-total span { font-size: 0.78rem; font-weight: 700; opacity: 0.92; } .tp-total em { margin-left: 6px; font-style: normal; font-size: 0.68rem; padding: 1px 8px; border-radius: 999px; background: rgba(255, 255, 255, 0.18); } .tp-total b { font-size: 1.1rem; font-variant-numeric: tabular-nums; }
.tp-sub { display: flex; align-items: center; gap: 5px; margin: 0; font-size: 0.78rem; font-weight: 600; color: var(--sc-mute, #64748b); } .tp-sub :deep(div) { flex: none; margin: 0; } .tp-note { margin: 0; font-size: 0.8rem; color: var(--sc-mute, #64748b); overflow-wrap: anywhere; }
.tp-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding-top: 8px; border-top: 1px dashed var(--sc-line, #e3e9f2); }
.tp-meta { display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; font-weight: 700; color: var(--sc-mute, #64748b); } .tp-meta :deep(div) { flex: none; margin: 0; }
.tp-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-left: auto; }
.tp-ib { position: relative; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; flex: none; width: 32px; height: 32px; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; border: 0; border-radius: 11px; cursor: pointer; color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); transition: transform 0.15s ease, background 0.15s ease; } .tp-ib:hover { transform: translateY(-1px); background: color-mix(in srgb, var(--c) 22%, transparent); } .tp-ib > div { flex: none; margin: 0; }
.tp-ib[data-tip]::after { content: attr(data-tip); position: absolute; right: 0; bottom: calc(100% + 8px); z-index: 80; width: max-content; max-width: min(200px, 58vw); padding: 5px 9px; border-radius: 9px; font-size: 0.72rem; font-weight: 700; line-height: 1.35; white-space: normal; color: #fff; background: #0f172a; box-shadow: 0 12px 24px -10px rgba(15, 23, 42, 0.6); opacity: 0; transform: translateY(4px); transition: opacity 0.14s ease, transform 0.14s ease; pointer-events: none; } .tp-ib[data-tip]:hover::after, .tp-ib[data-tip]:focus-visible::after { opacity: 1; transform: translateY(0); }
.tp-use { padding-right: 14px; }
.tp-empty { display: grid; justify-items: center; gap: 6px; padding: 40px 12px; border-radius: 22px; text-align: center; color: var(--sc-mute, #64748b); background: var(--sc-card, #fff); box-shadow: inset 0 0 0 1px var(--sc-line, #e3e9f2); } .tp-empty b { color: var(--sc-ink, #0f2540); }
.tp-mt { display: flex; align-items: center; gap: 10px; } .tp-mt__ico { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #059669, #047857); } .tp-mt__ico > div { flex: none; margin: 0; }
.tp-h { display: flex; align-items: center; gap: 6px; margin: 14px 0 8px; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #475569; } .tp-h :deep(div) { flex: none; margin: 0; }
.tp-money { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 8px; } .tp-mf label { display: flex; align-items: center; gap: 5px; color: var(--c); } .tp-mf label :deep(div) { flex: none; margin: 0; }
.tp-sum { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; margin-top: 10px; padding: 10px 14px; border-radius: 14px; background: #eff6ff; box-shadow: inset 0 0 0 1px #bfdbfe; } .tp-sum span { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #1e40af; } .tp-sum b { font-size: 1.2rem; color: #0f2540; font-variant-numeric: tabular-nums; } .tp-sum small { color: #64748b; font-weight: 700; }
.tp-foot-btns { display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
html.dark .tp-sum { background: #14233a; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); } html.dark .tp-sum b { color: #eef0fa; } html.dark .tp-sum span { color: #93c5fd; }
@media (max-width: 640px) { .tp-stats { grid-template-columns: 1fr; } .tp-grid { grid-template-columns: 1fr; } .tp-money { grid-template-columns: 1fr; } .tp-actions { width: 100%; } .tp-use { flex: 1; } }
@media (prefers-reduced-motion: reduce) { .tp-ib, .tp-stat { transition: none; } }
</style>
