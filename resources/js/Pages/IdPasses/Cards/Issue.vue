<template>
  <admin-layout :title="pageTitle">
    <id-make-overlay />
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="info.icon" :title="pageTitle" :subtitle="$t('Choose the person, check the details, add a photograph and look at the card before it is issued.')">
        <Link :href="route('id-passes.' + info.route + '.index')" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="list" class="w-4 h-4" /></span><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <div class="idi-grid">
        <form class="idi-main" @submit.prevent="save">
          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico" :style="{ background: cat ? cat.band : info.tone }"><icons :name="info.icon" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Category and number') }}</h2><p class="lg-card-sub">{{ $t('The number is taken when the ID is saved: nobody else can get the same one.') }}</p></div></header>
            <div class="lg-fields">
              <div class="ac-f"><label>{{ $t('Category') }} <b class="wims-req">*</b></label><acct-select v-model="form.category_id" :options="catOptions" :placeholder="$t('Choose...')" /><span class="ac-err">{{ err('category_id') }}</span></div>
              <div class="ac-f"><label>{{ $t('ID number') }}</label>
                <div class="idi-num"><b>{{ manual ? manualPreview : (cat ? cat.next : '-') }}</b><small v-if="cat && cat.requires_approval" class="lg-subtext">{{ $t('This category needs approval before the card can be printed.') }}</small></div>
                <label v-if="canNumber" class="ac-check"><input v-model="manual" type="checkbox" />{{ $t('Type the number by hand') }}</label>
                <input v-if="manual && canNumber" v-model="form.manual_number" inputmode="numeric" :placeholder="$t('Number, for example 17')" /><span class="ac-err">{{ err('number') || err('manual_number') }}</span>
              </div>
            </div>
          </section>

          <section v-if="cat && cat.pooled" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('How many passes') }}</h2><p class="lg-card-sub">{{ $t('These passes belong to nobody. Each is given to a visitor at check-in (from Visitor Logs) and taken back at check-out, and WIMS keeps who held which pass and when.') }}</p></div></header>
            <div class="lg-fields"><div class="ac-f"><label>{{ $t('Number of passes to make') }} <b class="wims-req">*</b></label><input v-model.number="form.quantity" type="number" min="1" max="200" inputmode="numeric" /><small class="lg-subtext">{{ $t('They get the next numbers in a row, from :a.', { a: cat.next }) }}</small><span class="ac-err">{{ err('quantity') }}</span></div></div>
          </section>

          <section v-if="!(cat && cat.pooled)" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Person') }}</h2><p class="lg-card-sub">{{ personHelp }}</p></div></header>
            <div v-if="sources.length > 1" class="idi-src"><button v-for="s in sources" :key="s.key" type="button" :class="{ 'is-on': source === s.key }" @click="setSource(s.key)">{{ s.label }}</button></div>
            <div v-if="source !== 'type'" class="ac-f"><label>{{ sourceLabel }} <b class="wims-req">*</b></label>
              <person-search :model-value="person" :source="source" :category-id="source === 'contact' && (info.kind === 'compound' || info.kind === 'gate') ? form.category_id : null" :placeholder="$t('Type a name, number or phone')" @update:model-value="setPerson" />
              <span class="ac-err">{{ err('staff_id') || err('contact_id') || err('full_name') }}</span></div>
            <div v-else class="lg-fields">
              <div class="ac-f"><label>{{ $t('Full name') }} <b class="wims-req">*</b></label><input v-model="form.full_name" maxlength="190" autocomplete="off" /><span class="ac-err">{{ err('full_name') }}</span></div>
              <div class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="form.phone" maxlength="60" inputmode="tel" /></div>
              <div class="ac-f"><label>{{ $t('Company') }}</label><input v-model="form.company" maxlength="150" /></div>
            </div>
          </section>

          <section v-if="!(cat && cat.pooled)" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2><p class="lg-card-sub">{{ $t('What is written on the card and kept with it.') }}</p></div></header>
            <div class="lg-fields">
              <div v-if="info.kind === 'staff' || info.kind === 'visitor' || info.kind === 'gate'" class="ac-f"><label>{{ info.kind === 'staff' ? $t('Designation') : $t('Title or description') }}</label><input v-model="form.title" maxlength="120" :placeholder="info.kind === 'staff' ? $t('Taken from the staff profile') : ''" /></div>
              <div v-if="info.kind === 'visitor'" class="ac-f"><label>{{ $t('Person being visited') }}</label><input v-model="form.host_name" maxlength="150" /></div>
              <div v-if="info.kind === 'visitor' || info.kind === 'gate'" class="ac-f"><label>{{ info.kind === 'gate' ? $t('Reason for access') : $t('Purpose of visit') }} <b v-if="info.kind === 'gate'" class="wims-req">*</b></label><input v-model="form.purpose" maxlength="255" /><span class="ac-err">{{ err('purpose') }}</span></div>
              <div v-if="info.kind === 'gate'" class="ac-f"><label>{{ $t('Authorisation') }}</label><acct-select v-model="form.authorisation_type" :options="gateOptions" :placeholder="$t('Choose...')" clearable /></div>
              <div v-if="info.kind === 'gate' || info.kind === 'visitor'" class="ac-f"><label>{{ $t('Vehicle registration') }} <b v-if="cat && cat.vehicle_required" class="wims-req">*</b></label><input v-model="form.vehicle_registration" maxlength="40" /><span class="ac-err">{{ err('vehicle_registration') }}</span></div>
              <div v-if="info.kind === 'gate' || info.kind === 'visitor'" class="ac-f"><label>{{ $t('Vehicle description') }}</label><input v-model="form.vehicle_description" maxlength="150" :placeholder="$t('Make, model, colour')" /></div>
              <div v-if="info.kind !== 'visitor' || true" class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="form.phone" maxlength="60" inputmode="tel" /></div>
              <div class="ac-f lg-span-all"><label>{{ $t('Restrictions') }}</label><textarea v-model="form.restrictions" rows="2" maxlength="2000" :placeholder="$t('For example: Gate A only, 08:00 to 17:00')"></textarea></div>
              <div class="ac-f lg-span-all"><label>{{ $t('Notes') }}</label><textarea v-model="form.notes" rows="2" maxlength="2000"></textarea></div>
            </div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="calendar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Validity') }}</h2><p class="lg-card-sub">{{ validityHelp }}</p></div></header>
            <div class="lg-fields">
              <div class="ac-f"><label>{{ $t('Date of issue') }} <b class="wims-req">*</b></label><date-picker v-model="form.issued_at" :clearable="false" /><span class="ac-err">{{ err('issued_at') }}</span></div>
              <div class="ac-f"><label>{{ $t('Expiry date') }}</label><date-picker v-model="form.expires_at" clearable @update:model-value="expTouched = true" /><span class="ac-err">{{ err('expires_at') }}</span>
                <small class="lg-subtext">{{ form.expires_at ? '' : $t('Without an expiry date the ID is valid until it is revoked.') }}</small></div>
            </div>
          </section>

          <section v-if="showPhoto && !(cat && cat.pooled)" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="camera" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Photograph') }} <b v-if="cat && cat.photo_required" class="wims-req">*</b></h2><p class="lg-card-sub">{{ $t('Upload a picture or take one now. The original is kept; the card gets the part inside the frame.') }}</p></div></header>
            <photo-cropper v-model="photo" :staff-photo-url="staffPhotoUrl" :min-width="minPhotoWidth" :background="(cat && cat.photo_bg) || ''" @busy="photoBusy = $event" />
            <span class="ac-err">{{ err('photo') || photoMissing }}</span>
          </section>

          <div class="lg-actionbar"><span class="lg-actionbar-note">{{ cat && cat.requires_approval ? $t('Saved as waiting for approval.') : $t('The ID is active as soon as it is saved.') }}</span>
            <div class="lg-actionbar-btns"><Link :href="route('id-passes.' + info.route + '.index')" class="lg-btn tone t-slate" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</Link>
              <button type="submit" class="lg-btn tone tone-solid" :disabled="form.processing || !canSave || photoBusy" :title="photoBusy ? $t('Wait until the photograph background is ready.') : ''" style="--tone: #16a34a"><span class="tone-ico"><icons name="send" class="w-4 h-4" /></span>{{ form.processing ? $t('Saving...') : (cat && cat.pooled ? $t('Make the passes') : cat && cat.requires_approval ? $t('Submit for approval') : $t('Issue ID')) }}</button></div></div>
        </form>

        <aside class="idi-preview">
          <div class="idi-pv-head"><b>{{ $t('Preview') }}</b><small>{{ loading ? $t('Updating...') : $t('As it will be printed (55 x 85 mm)') }}</small></div>
          <div v-if="pv" class="idi-pv-cards"><div><card-face :html="pv.front" :css="css" /><small>{{ $t('Front') }}</small></div><div><card-face :html="pv.back" :css="css" /><small>{{ $t('Back') }}</small></div></div>
          <div v-else class="idi-pv-empty">{{ cat ? $t('Loading the preview...') : $t('Choose a category to see the card.') }}</div>
        </aside>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import CardFace from '../Parts/CardFace.vue';
import PersonSearch from '../Parts/PersonSearch.vue';
import PhotoCropper from '../Parts/PhotoCropper.vue';
import IdMakeOverlay from '../Parts/IdMakeOverlay.vue';
import { idMakeStart, idMakeDone, idMakeClose } from '@/Core/idMake';

const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect, CardFace, PersonSearch, PhotoCropper, IdMakeOverlay },
  props: { info: Object, categories: Array, gateTypes: Array, today: String, presetStaff: Number, presetContact: Number, canNumber: Boolean, minPhotoWidth: Number },
  data() {
    const first = this.categories[0] || null;
    return {
      form: this.$inertia.form({ category_id: first ? first.id : null, staff_id: null, contact_id: null, visitor_id: null, full_name: '', title: '', phone: '', company: '', issued_at: this.today, expires_at: null, host_name: '', purpose: '', authorisation_type: null,
        vehicle_registration: '', vehicle_description: '', restrictions: '', notes: '', manual_number: '', quantity: 10 }),
      person: null, source: this.info.kind === 'staff' ? 'staff' : (this.info.kind === 'compound' || this.info.kind === 'gate') ? 'contact' : 'visitor', photo: null, photoBusy: false, manual: false, expTouched: false, pv: null, loading: false, timer: null, css: '', seq: 0, photoMissing: '',
    };
  },
  computed: {
    pageTitle() { return this.$t('Issue :k', { k: this.info.singular }); },
    cat() { return this.categories.find(c => c.id === this.form.category_id) || null; },
    catOptions() { return this.categories.map(c => ({ value: c.id, label: c.name })); },
    gateOptions() { return this.gateTypes.map(g => ({ value: g, label: g })); },
    sources() {
      if (this.info.kind === 'visitor') return [{ key: 'visitor', label: this.$t('Visitor directory') }, { key: 'type', label: this.$t('Type the details') }];
      if (this.info.kind === 'gate') return [{ key: 'contact', label: this.$t('Gate Pass contacts') }, { key: 'visitor', label: this.$t('Visitor directory') }, { key: 'type', label: this.$t('Type the details') }];
      return [];
    },
    sourceLabel() { return { staff: this.$t('Staff member'), contact: this.$t('Contact'), visitor: this.$t('Visitor') }[this.source]; },
    personHelp() { return { staff: this.$t('Staff come from the Staff module: nothing is copied, and the WIMS staff reference stays as it is.'), compound: this.$t('Contacts of the categories set for this ID come from the Contacts module.'), visitor: this.$t('Pick a returning visitor, or type the details of a new one.'), gate: this.$t('A Gate Pass is given to a contact of the category Gate Pass: it shows on that contact with the date it was given and the passes held before.') }[this.info.kind]; },
    manualPreview() { return this.cat ? this.cat.prefix + String(this.form.manual_number || '').padStart(this.cat.digits, '0') : ''; },
    validityHelp() { const c = this.cat; if (!c || !c.validity_value) return this.$t('This category has no standard validity.'); return this.$t('This category is valid for :n :u unless you change the date.', { n: c.validity_value, u: this.$t(c.validity_unit) }); },
    showPhoto() { return this.info.kind === 'staff' || this.info.kind === 'compound' || (this.cat && this.cat.photo_required) || this.info.kind === 'visitor' || this.info.kind === 'gate'; },
    staffPhotoUrl() { return this.info.kind === 'staff' && this.person && this.person.has_photo ? route('id-passes.lookup.staff-photo', this.person.id) : ''; },
    canSave() {
      if (!this.cat || !this.form.issued_at) return false;
      if (this.cat.pooled) return this.form.quantity >= 1 && this.form.quantity <= 200;
      if (this.source === 'type' ? !this.form.full_name.trim() : !this.person) return false;
      if (this.cat.photo_required && !this.photo && !this.staffPhotoUrl) return false;
      return true;
    },
  },
  watch: {
    'form.category_id'() { this.applyExpiry(); if (this.info.kind === 'compound' || this.info.kind === 'gate') this.setPerson(null); this.queue(); },
    'form.issued_at'() { this.applyExpiry(); this.queue(); },
    photo() { this.queue(); },
    person() { this.queue(); },
    'form.full_name'() { this.queue(); }, 'form.title'() { this.queue(); }, 'form.expires_at'() { this.queue(); }, 'form.host_name'() { this.queue(); }, 'form.vehicle_registration'() { this.queue(); }, 'form.authorisation_type'() { this.queue(); },
  },
  mounted() {
    this.applyExpiry();
    if (this.presetStaff) this.loadPreset('staff', this.presetStaff);
    if (this.presetContact) this.loadPreset('contact', this.presetContact);
    this.queue(0);
  },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    async loadPreset(source, id) {
      try { const { data } = await window.axios.get(route('id-passes.lookup.people'), { params: { source, q: '' } }); const hit = data.find(r => r.id === id); if (hit && !hit.card) this.setPerson(hit); } catch (e) { /* the person is chosen by hand */ }
    },
    setSource(k) { this.source = k; this.person = null; this.form.staff_id = this.form.contact_id = this.form.visitor_id = null; },
    setPerson(p) {
      this.person = p;
      this.form.staff_id = this.info.kind === 'staff' && p ? p.id : null;
      this.form.contact_id = this.source === 'contact' && p ? p.id : null;
      this.form.visitor_id = this.source === 'visitor' && p ? p.id : null;
      if (p) {
        this.form.full_name = p.name; this.form.phone = p.phone || this.form.phone;
        if (this.info.kind === 'staff') this.form.title = p.title || '';
        if (this.info.kind === 'compound') this.form.title = this.cat ? this.cat.name : p.title;
        if (p.company) this.form.company = p.company;
        if (p.vehicle && !this.form.vehicle_registration) this.form.vehicle_registration = p.vehicle;
      } else if (this.source !== 'type') this.form.full_name = '';
    },
    applyExpiry() {
      const c = this.cat; if (!c || this.expTouched) return;
      if (!c.validity_value || !this.form.issued_at) { this.form.expires_at = null; return; }
      const [y, m, d] = this.form.issued_at.split('-').map(Number); const from = new Date(y, m - 1, d);
      let e;
      if (c.validity_unit === 'days') { e = new Date(from); e.setDate(e.getDate() + c.validity_value - 1); }
      else { e = new Date(from); const day = e.getDate(); e.setDate(1); e.setMonth(e.getMonth() + (c.validity_unit === 'years' ? c.validity_value * 12 : c.validity_value)); const last = new Date(e.getFullYear(), e.getMonth() + 1, 0).getDate(); e.setDate(Math.min(day, last)); e.setDate(e.getDate() - 1); }
      this.form.expires_at = ymd(e);
    },
    queue(wait = 450) { clearTimeout(this.timer); if (!this.cat) return; this.timer = setTimeout(this.preview, wait); },
    async preview() {
      const n = ++this.seq; this.loading = true;
      try {
        const body = { category_id: this.cat.id, full_name: this.form.full_name || (this.person ? this.person.name : '') || this.$t('Full Name'), title: this.form.title || (this.info.kind === 'compound' ? this.cat.name : ''), issued_at: this.form.issued_at, expires_at: this.form.expires_at, host_name: this.form.host_name,
          vehicle_registration: this.form.vehicle_registration, authorisation_type: this.form.authorisation_type, staff_id: this.form.staff_id, number: this.manual && this.form.manual_number ? this.manualPreview : this.cat.next, photo_data: this.photo ? this.photo.dataUri : undefined };
        const { data } = await window.axios.post(route('id-passes.lookup.preview'), body);
        if (n === this.seq) { this.pv = data; }
      } catch (e) { /* the preview is only a help */ }
      if (n === this.seq) this.loading = false;
      if (!this.css) { try { const r = await window.axios.get(route('id-passes.lookup.css')); this.css = r.data; } catch (e) { /* ignore */ } }
    },
    save() {
      this.photoMissing = '';
      if (this.cat.photo_required && !this.photo && !this.staffPhotoUrl) { this.photoMissing = this.$t('A photograph is required.'); return; }
      const f = this.form;
      f.transform(d => {
        const o = { ...d }; if (!this.manual) o.manual_number = '';
        if (this.photo) { o.photo = this.photo.file; if (this.photo.original) o.photo_original = this.photo.original; if (this.photo.cutout) o.photo_cutout = this.photo.cutout; }
        Object.keys(o).forEach(k => { if (o[k] === null || o[k] === undefined) delete o[k]; });
        return o;
      }).post(route('id-passes.' + this.info.route + '.store'), {
        preserveScroll: true, forceFormData: true, headers: { 'X-Quiet': '1' },    // the "ID press" animation replaces the small save pill
        onStart: () => idMakeStart({ mode: 'issue', color: this.cat.band || '#1565c0', count: this.cat.pooled ? this.form.quantity : 1, title: this.cat.pooled ? this.$t('Making :n passes', { n: this.form.quantity }) : (this.cat.requires_approval ? this.$t('Sending the ID for approval') : this.$t('Making the ID card')) }),
        onSuccess: page => idMakeDone((page.props.flash && page.props.flash.message) || ''),
        onError: () => idMakeClose(),
      });
    },
  },
};
</script>

<style scoped>
.idi-grid { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1100px) { .idi-grid { grid-template-columns: minmax(0, 1fr) 380px; } .idi-preview { position: sticky; top: 84px; } }
.idi-main { display: grid; gap: 14px; }
.idi-src { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); margin-bottom: 12px; flex-wrap: wrap; }
.idi-src button { border: 0; padding: 7px 14px; border-radius: 999px; background: transparent; font-weight: 700; font-size: 0.82rem; color: var(--lg-mute, #64748b); cursor: pointer; }
.idi-src button.is-on { background: #fff; color: #1e4467; box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); }
.idi-num { display: grid; gap: 2px; padding: 8px 12px; border-radius: 12px; background: var(--lg-soft, #f1f5f9); } .idi-num b { font-size: 1.35rem; letter-spacing: 0.04em; color: #1e4467; }
.idi-preview { border-radius: 22px; padding: 16px; background: var(--lg-card, #fff); box-shadow: 0 18px 34px -22px rgba(15, 37, 64, 0.5), 0 0 0 1px var(--lg-line, #e2e8f0); }
.idi-pv-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; } .idi-pv-head small { color: var(--lg-mute, #64748b); font-size: 0.74rem; }
.idi-pv-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; } .idi-pv-cards small { display: block; text-align: center; margin-top: 6px; color: var(--lg-mute, #64748b); font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; }
.idi-pv-empty { padding: 40px 10px; text-align: center; color: var(--lg-mute, #64748b); }
.lg-span-all { grid-column: 1 / -1; }
</style>
