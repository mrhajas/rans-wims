<template>
  <admin-layout :title="$t('ID Settings')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cog" :title="$t('ID Settings')" :subtitle="$t('Categories and numbering, the back of the card, validity, notices and QR verification.')">
        <Link :href="route('id-passes.dashboard')" class="lg-btn lg-btn-soft"><icons name="qr-code" class="w-4 h-4" /><span>{{ $t('Overview') }}</span></Link>
        <Link v-if="can.alerts" :href="route('id-passes.workflow')" class="lg-btn lg-btn-soft"><icons name="bell" class="w-4 h-4" /><span>{{ $t('Alerts') }}</span></Link>
        <button v-if="can.settings && can.templates && can.numbering" type="button" class="lg-btn lg-btn-primary" @click="openNew"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New category') }}</span></button>
      </page-header>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Categories') }}</h2><p class="lg-card-sub">{{ $t('Each category has its own numbering, colours and rules. A number that was given out is never used again.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Category') }}</th><th>{{ $t('Kind') }}</th><th>{{ $t('Numbering') }}</th><th>{{ $t('Validity') }}</th><th>{{ $t('Rules') }}</th><th>{{ $t('Cards') }}</th><th></th></tr></thead>
          <tbody><tr v-for="c in categories" :key="c.id" :class="{ 'is-off': !c.is_active }">
            <td :data-label="$t('Category')"><span class="st-dot" :style="{ background: c.band_color }"></span><b>{{ c.name }}</b><div v-if="c.label" class="lg-subtext">{{ $t('Card says') }}: {{ c.label }}</div></td>
            <td :data-label="$t('Kind')">{{ kindName(c.kind) }}</td>
            <td :data-label="$t('Numbering')"><b>{{ c.example }}</b><div class="lg-subtext">{{ $t('prefix') }} {{ c.prefix || '-' }} · {{ c.digits }} {{ $t('digits') }}</div></td>
            <td :data-label="$t('Validity')">{{ c.validity_value ? c.validity_value + ' ' + $t(c.validity_unit) : $t('No expiry') }}</td>
            <td :data-label="$t('Rules')"><span v-if="c.requires_approval" class="lg-chip lg-chip-amber">{{ $t('Approval') }}</span> <span v-if="c.photo_required" class="lg-chip lg-chip-blue">{{ $t('Photo') }}</span> <span v-if="c.vehicle_required" class="lg-chip lg-chip-violet">{{ $t('Vehicle') }}</span> <span v-if="c.pooled" class="lg-chip lg-chip-sky">{{ $t('Stock of passes') }}</span> <span v-if="!c.is_active" class="lg-chip lg-chip-slate">{{ $t('Off') }}</span></td>
            <td :data-label="$t('Cards')">{{ c.cards }}</td>
            <td class="lg-sticky-end"><button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" @click="openEdit(c)"><icons name="edit" class="w-4 h-4" /></button><button v-if="can.settings && !c.cards" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" @click="remove(c)"><icons name="trash" class="w-4 h-4" /></button></td></tr></tbody></table></div>
        <span class="ac-err">{{ errs.category }}</span>
      </section>

      <div class="st-two">
        <form v-if="can.settings" class="lg-card" @submit.prevent="saveGeneral">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Back of the card') }}</h2><p class="lg-card-sub">{{ $t('The same back is printed on every ID. The dates are the card’s own.') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f st-all"><label>{{ $t('Statement at the top') }} <b class="wims-req">*</b></label><textarea v-model="g.idpass_intro" rows="2" maxlength="300"></textarea><span class="ac-err">{{ errs.idpass_intro }}</span></div>
            <div class="ac-f st-all"><label>{{ $t('Terms & Conditions (one on each line, up to 10)') }} <b class="wims-req">*</b></label><textarea v-model="g.idpass_terms" rows="8" maxlength="1500"></textarea><span class="ac-err">{{ errs.idpass_terms }}</span></div>
            <div class="ac-f"><label>{{ $t('Address') }}</label><input v-model="g.idpass_address" maxlength="120" /></div>
            <div class="ac-f"><label>{{ $t('Box number') }}</label><input v-model="g.idpass_pobox" maxlength="120" /></div>
            <div class="ac-f"><label>{{ $t('Telephones') }}</label><input v-model="g.idpass_phones" maxlength="120" /></div>
            <div class="ac-f"><label>{{ $t('Email') }}</label><input v-model="g.idpass_email" maxlength="120" /></div>
            <div class="ac-f"><label>{{ $t('Website') }}</label><input v-model="g.idpass_website" maxlength="120" /></div>
            <div class="ac-f"><label>{{ $t('Back colour') }} <b class="wims-req">*</b></label><input v-model="g.idpass_back_color" type="color" class="st-color" /></div>
            <div class="ac-f"><label>{{ $t('Text when there is no expiry') }} <b class="wims-req">*</b></label><input v-model="g.idpass_no_expiry_text" maxlength="40" /></div>
            <div class="ac-f"><label>{{ $t('Tell about expiry (days before)') }} <b class="wims-req">*</b></label><input v-model.number="g.idpass_expiry_notice_days" type="number" min="0" max="180" /><small class="lg-subtext">{{ $t('0 switches the notice off.') }}</small></div>
            <div class="ac-f"><label>{{ $t('Batch made at once up to (cards)') }} <b class="wims-req">*</b></label><input v-model.number="g.idpass_sync_batch" type="number" min="5" max="200" /><small class="lg-subtext">{{ $t('Larger batches are made in the background.') }}</small></div>
            <div class="ac-f st-all"><label>{{ $t('Gate pass authorisation types (one on each line)') }}</label><textarea v-model="g.idpass_gate_types" rows="4" maxlength="600"></textarea></div>
          </div>
          <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Changes show on the next card that is printed.') }}</span><div class="lg-actionbar-btns"><button type="button" class="lg-btn t-slate" @click="resetGeneral"><icons name="refresh" class="w-4 h-4" />{{ $t('Start again from the supplied wording') }}</button><button type="submit" class="lg-btn lg-btn-primary" :disabled="gf.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></div></div>
        </form>
        <aside class="st-prev"><b>{{ $t('Back of the card (as it prints now)') }}</b><card-face :html="backHtml" :css="css" /></aside>
      </div>

      <form v-if="can.qr" class="lg-card" @submit.prevent="saveQr">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="qr-code" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('QR verification') }}</h2><p class="lg-card-sub">{{ $t('The QR code holds a long random token, never a database number. Revoked, suspended, expired and replaced IDs never show as valid.') }}</p></div></header>
        <label class="st-opt" :class="{ 'is-on': q.idpass_qr_mode === 'public' }"><input v-model="q.idpass_qr_mode" type="radio" value="public" /><span><b>{{ $t('Anybody who scans sees the status') }}</b><small>{{ $t('Valid or not, type, number, dates and the holder’s first name with an initial. Signed-in staff also see the photograph and details.') }}</small></span></label>
        <label class="st-opt" :class="{ 'is-on': q.idpass_qr_mode === 'login' }"><input v-model="q.idpass_qr_mode" type="radio" value="login" /><span><b>{{ $t('Only signed-in staff') }}</b><small>{{ $t('Everybody else is asked to sign in first and sees nothing.') }}</small></span></label>
        <div class="lg-actionbar"><span class="lg-actionbar-note"></span><div class="lg-actionbar-btns"><button type="submit" class="lg-btn lg-btn-primary" :disabled="qf.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></div></div>
      </form>
    </div>

    <travel-modal :show="open" :title="isNew ? $t('New category') : $t('Edit :n', { n: cf.name })" :subtitle="$t('What you can change depends on your permissions.')" icon="collection" max-width="3xl" :busy="cf.processing" @close="open = false">
      <form id="st-cat" class="lg-fields" @submit.prevent="saveCat">
        <div v-if="isNew" class="ac-f"><label>{{ $t('Kind') }} <b class="wims-req">*</b></label><acct-select v-model="cf.kind" :options="kinds" :clearable="false" /></div>
        <template v-if="isNew || can.templates">
          <div class="ac-f"><label>{{ $t('Name') }} <b class="wims-req">*</b></label><input v-model="cf.name" maxlength="80" /><span class="ac-err">{{ errs.name }}</span></div>
          <div class="ac-f"><label>{{ $t('Word on a pass card') }}</label><input v-model="cf.label" maxlength="40" :placeholder="$t('For example Visitor Pass')" /></div>
          <div class="ac-f"><label>{{ $t('Band colour') }} <b class="wims-req">*</b></label><input v-model="cf.band_color" type="color" class="st-color" /></div>
          <div class="ac-f"><label>{{ $t('Arrow colour') }} <b class="wims-req">*</b></label><input v-model="cf.arrow_color" type="color" class="st-color" /></div>
          <div class="ac-f"><label>{{ $t('Icon (compound worker front)') }}</label><acct-select v-model="cf.icon" :options="iconOptions" :placeholder="$t('None')" clearable /></div>
        </template>
        <template v-if="isNew || can.numbering">
          <div class="ac-f"><label>{{ $t('Prefix') }}</label><input v-model="cf.prefix" maxlength="12" /><span class="ac-err">{{ errs.prefix }}</span></div>
          <div class="ac-f"><label>{{ $t('Digits') }} <b class="wims-req">*</b></label><input v-model.number="cf.digits" type="number" min="1" max="8" /></div>
          <div v-if="!isNew" class="ac-f"><label>{{ $t('Next number') }} <b class="wims-req">*</b></label><input v-model.number="cf.next_number" type="number" :min="cf.min_next" /><small class="lg-subtext">{{ $t('At least :n: used numbers never come back.', { n: cf.min_next }) }}</small><span class="ac-err">{{ errs.next_number }}</span></div>
        </template>
        <template v-if="isNew || can.settings">
          <div class="ac-f"><label>{{ $t('Valid for') }}</label><input v-model.number="cf.validity_value" type="number" min="1" :placeholder="$t('Empty: no expiry')" /></div>
          <div class="ac-f"><label>{{ $t('Unit') }}</label><acct-select v-model="cf.validity_unit" :options="units" :clearable="false" /></div>
          <div v-if="(cf.kind || '') === 'compound'" class="ac-f st-all"><label>{{ $t('Contact categories that can have this ID') }}</label><div class="st-chips"><label v-for="n in contactCategories" :key="n" :class="{ 'is-on': (cf.contact_categories || []).includes(n) }"><input type="checkbox" :value="n" v-model="cf.contact_categories" />{{ n }}</label></div></div>
          <div class="ac-f st-all st-checks"><label><input v-model="cf.requires_approval" type="checkbox" /> {{ $t('Needs approval before it can be printed') }}</label><label><input v-model="cf.photo_required" type="checkbox" /> {{ $t('A photograph is required') }}</label><label><input v-model="cf.vehicle_required" type="checkbox" /> {{ $t('The vehicle registration is required') }}</label><label v-if="(cf.kind || '') === 'visitor'"><input v-model="cf.pooled" type="checkbox" /> {{ $t('A stock of numbered passes: handed to a visitor at check-in, taken back at check-out') }}</label><label v-if="!isNew"><input v-model="cf.is_active" type="checkbox" /> {{ $t('This category is switched on') }}</label></div>
        </template>
      </form>
      <template #footer><button type="button" class="lg-btn lg-btn-soft" :disabled="cf.processing" @click="open = false">{{ $t('Cancel') }}</button><button type="submit" form="st-cat" class="lg-btn lg-btn-primary" :disabled="cf.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import CardFace from './Parts/CardFace.vue';

export default {
  components: { AdminLayout, PageHeader, Link, AcctSelect, TravelModal, CardFace },
  props: { categories: Array, kinds: Array, icons: Array, values: Object, defaults: Object, contactCategories: Array, backHtml: String, css: String, can: Object },
  data() {
    return { g: { ...this.values }, gf: this.$inertia.form({}), q: { idpass_qr_mode: this.values.idpass_qr_mode }, qf: this.$inertia.form({}), open: false, isNew: false, editId: null, cf: this.$inertia.form({}) };
  },
  computed: {
    errs() { return this.$page.props.errors || {}; },
    units() { return [{ value: 'days', label: this.$t('days') }, { value: 'months', label: this.$t('months') }, { value: 'years', label: this.$t('years') }]; },
    iconOptions() { return this.icons.map(i => ({ value: i, label: i })); },
  },
  methods: {
    kindName(k) { return (this.kinds.find(x => x.value === k) || {}).label || k; },
    resetGeneral() { this.g = { ...this.g, ...this.defaults }; },
    saveGeneral() { this.gf = this.$inertia.form({ ...this.g }); this.gf.put(route('id-passes.settings.general'), { preserveScroll: true }); },
    saveQr() { this.qf = this.$inertia.form({ ...this.q }); this.qf.put(route('id-passes.settings.qr'), { preserveScroll: true }); },
    openNew() { this.isNew = true; this.editId = null; this.cf = this.$inertia.form({ kind: 'compound', name: '', label: '', band_color: '#1565c0', arrow_color: '#fff200', icon: null, prefix: '', digits: 3, validity_value: 1, validity_unit: 'years', contact_categories: [], requires_approval: false, photo_required: true, vehicle_required: false, pooled: false }); this.cf.clearErrors(); this.open = true; },
    openEdit(c) { this.isNew = false; this.editId = c.id; this.cf = this.$inertia.form({ ...c, contact_categories: [...(c.contact_categories || [])] }); this.cf.clearErrors(); this.open = true; },
    saveCat() {
      const o = { preserveScroll: true, onSuccess: () => { this.open = false; } };
      if (this.isNew) this.cf.post(route('id-passes.settings.categories.store'), o); else this.cf.put(route('id-passes.settings.categories.update', [this.editId]), o);
    },
    remove(c) { this.$confirm(this.$t('Delete :n?', { n: c.name })).then(ok => { if (ok) this.$inertia.delete(route('id-passes.settings.categories.destroy', [c.id]), { preserveScroll: true }); }).catch(() => {}); },
  },
};
</script>

<style scoped>
.st-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; vertical-align: middle; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.9), 0 3px 8px -2px rgba(15, 37, 64, 0.5); }
tr.is-off td { opacity: 0.55; }
.st-two { display: grid; gap: 18px; align-items: start; margin: 18px 0; } @media (min-width: 1100px) { .st-two { grid-template-columns: minmax(0, 1fr) 300px; } .st-prev { position: sticky; top: 84px; } }
.st-prev { display: grid; gap: 10px; padding: 16px; border-radius: 22px; background: var(--lg-card, #fff); box-shadow: 0 18px 34px -22px rgba(15, 37, 64, 0.5), 0 0 0 1px var(--lg-line, #e2e8f0); }
.st-all { grid-column: 1 / -1; }
.st-color { height: 42px; padding: 3px 6px; width: 100%; border-radius: 12px; cursor: pointer; }
.st-chips { display: flex; flex-wrap: wrap; gap: 8px; } .st-chips label { display: inline-flex; gap: 6px; align-items: center; padding: 6px 12px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); font-weight: 700; font-size: 0.82rem; cursor: pointer; } .st-chips label.is-on { background: #1565c0; color: #fff; }
.st-checks { display: grid; gap: 8px; } .st-checks label { display: flex; gap: 8px; align-items: center; font-weight: 700; }
.st-opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; margin-bottom: 10px; border-radius: 16px; cursor: pointer; box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); } .st-opt.is-on { box-shadow: 0 0 0 2px #1565c0; } .st-opt span { display: grid; gap: 2px; } .st-opt small { color: var(--lg-mute, #64748b); }
</style>
