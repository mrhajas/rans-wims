<template>
  <admin-layout :title="$t('ID Settings')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cog" :title="$t('ID Settings')" :subtitle="$t('Categories and numbering, the back of the card, validity, notices and QR verification.')">
        <Link :href="route('id-passes.dashboard')" class="lg-btn tone" style="--tone: #1e4467"><span class="tone-ico"><icons name="qr-code" class="w-4 h-4" /></span><span>{{ $t('Overview') }}</span></Link>
        <Link v-if="can.alerts" :href="route('id-passes.workflow')" class="lg-btn tone" style="--tone: #e11d48"><span class="tone-ico"><icons name="bell" class="w-4 h-4" /></span><span>{{ $t('Alerts') }}</span></Link>
        <button v-if="can.settings && can.templates && can.numbering" type="button" class="lg-btn tone tone-solid" @click="openNew" style="--tone: #2563eb"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span><span>{{ $t('New category') }}</span></button>
      </page-header>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Categories') }}</h2><p class="lg-card-sub">{{ $t('Each category has its own numbering, colours and rules. A number that was given out is never used again.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Category') }}</th><th>{{ $t('Kind') }}</th><th>{{ $t('Numbering') }}</th><th>{{ $t('Validity') }}</th><th>{{ $t('Rules') }}</th><th>{{ $t('Cards') }}</th><th></th></tr></thead>
          <tbody><tr v-for="c in categories" :key="c.id" :class="{ 'is-off': !c.is_active }">
            <td :data-label="$t('Category')"><category-sample :sample="samples[c.id]" :css="sampleCss || css" :color="iconColour(c.band_color)" :name="c.name" /><b>{{ c.name }}</b><span v-if="c.photo_background" class="st-bgdot lg-tip tip-left" :data-tip="$t('Photo background') + ': ' + c.photo_background" :style="{ background: c.photo_background }"></span><div v-if="c.label" class="lg-subtext">{{ $t('Card says') }}: {{ c.label }}</div></td>
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
            <div class="ac-f"><label>{{ $t('Holder of Gate Passes not yet given out') }} <b class="wims-req">*</b></label><input v-model="g.idpass_stock_holder" maxlength="120" /><small class="lg-subtext">{{ $t('A stock of Gate Passes is made in this name, and each one is given to a person later.') }}</small></div>
            <div class="ac-f"><label>{{ $t('Tell about expiry (days before)') }} <b class="wims-req">*</b></label><input v-model.number="g.idpass_expiry_notice_days" type="number" min="0" max="180" /><small class="lg-subtext">{{ $t('0 switches the notice off.') }}</small></div>
            <div class="ac-f"><label>{{ $t('Batch made at once up to (cards)') }} <b class="wims-req">*</b></label><input v-model.number="g.idpass_sync_batch" type="number" min="5" max="200" /><small class="lg-subtext">{{ $t('Larger batches are made in the background.') }}</small></div>
            <div class="ac-f st-all"><label>{{ $t('Gate pass authorisation types (one on each line)') }}</label><textarea v-model="g.idpass_gate_types" rows="4" maxlength="600"></textarea></div>
          </div>
          <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Changes show on the next card that is printed.') }}</span><div class="lg-actionbar-btns"><button type="button" class="lg-btn tone t-slate" @click="resetGeneral" style="--tone: #d97706"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Start again from the supplied wording') }}</button><button type="submit" class="lg-btn tone tone-solid" :disabled="gf.processing" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save') }}</button></div></div>
        </form>
        <aside class="st-prev"><b>{{ $t('Back of the card (as it prints now)') }}</b><card-face :html="backHtml" :css="css" /></aside>
      </div>

      <form v-if="can.qr" class="lg-card" @submit.prevent="saveQr">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="qr-code" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('QR verification') }}</h2><p class="lg-card-sub">{{ $t('The QR code holds a long random token, never a database number. Revoked, suspended, expired and replaced IDs never show as valid.') }}</p></div></header>
        <label class="st-opt" :class="{ 'is-on': q.idpass_qr_mode === 'public' }"><input v-model="q.idpass_qr_mode" type="radio" value="public" /><span><b>{{ $t('Anybody who scans sees the status') }}</b><small>{{ $t('Valid or not, type, number, dates and the holder’s first name with an initial. Signed-in staff also see the photograph and details.') }}</small></span></label>
        <label class="st-opt" :class="{ 'is-on': q.idpass_qr_mode === 'login' }"><input v-model="q.idpass_qr_mode" type="radio" value="login" /><span><b>{{ $t('Only signed-in staff') }}</b><small>{{ $t('Everybody else is asked to sign in first and sees nothing.') }}</small></span></label>
        <div class="lg-actionbar"><span class="lg-actionbar-note"></span><div class="lg-actionbar-btns"><button type="submit" class="lg-btn tone tone-solid" :disabled="qf.processing" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save') }}</button></div></div>
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
          <div class="ac-f st-all"><label>{{ $t('Photo background') }}</label>
            <div class="st-bg">
              <button v-for="sw in swatches" :key="sw.hex" type="button" class="st-sw" :class="{ 'is-on': (cf.photo_background || '').toLowerCase() === sw.hex }" :title="sw.name" :style="{ background: sw.hex }" @click="cf.photo_background = sw.hex"></button>
              <input v-model="bgColour" type="color" class="st-sw st-sw-pick" :title="$t('Another colour')" />
              <button type="button" class="lg-btn tone" :class="{ 'tone-solid': !cf.photo_background }" style="--tone: #64748b" @click="cf.photo_background = null"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Keep as taken') }}</button>
            </div>
            <small class="lg-subtext">{{ cf.photo_background ? $t('The person is cut out of the photograph and placed on this colour when the ID is made. A photograph that already has this background is left as it is.') : $t('Photographs go on the card exactly as they were taken.') }}</small>
            <span class="ac-err">{{ errs.photo_background }}</span>
            <div v-if="!isNew && cf.cards && cf.photo_background" class="st-apply">
              <button type="button" class="lg-btn tone tone-solid" :disabled="ab.running || cf.photo_background !== bgSaved" style="--tone: #7c3aed" @click="applyBg"><span class="tone-ico"><icons name="camera" class="w-4 h-4" /></span>{{ $t('Apply to the :n IDs already issued', { n: cf.cards }) }}</button>
              <small v-if="cf.photo_background !== bgSaved" class="lg-subtext">{{ $t('Save the colour first.') }}</small>
              <div v-if="ab.total || ab.msg" class="st-ab">
                <div v-if="ab.total" class="st-bar"><i :style="{ width: Math.round(ab.done / ab.total * 100) + '%' }"></i></div>
                <span>{{ ab.msg }}</span>
                <button v-if="ab.running" type="button" class="lg-btn tone" style="--tone: #dc2626" @click="ab.stop = true">{{ $t('Stop') }}</button>
              </div>
            </div>
          </div>
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
      <template #footer><button type="button" class="lg-btn tone" :disabled="cf.processing" @click="open = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="submit" form="st-cat" class="lg-btn tone tone-solid" :disabled="cf.processing" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save') }}</button></template>
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
import CategorySample from './Parts/CategorySample.vue';
import { removeBackground } from '../../Core/removeBackground';

export default {
  components: { AdminLayout, PageHeader, Link, AcctSelect, TravelModal, CardFace, CategorySample },
  props: { categories: Array, kinds: Array, icons: Array, values: Object, defaults: Object, contactCategories: Array, backHtml: String, css: String, can: Object },
  data() {
    return { g: { ...this.values }, gf: this.$inertia.form({}), q: { idpass_qr_mode: this.values.idpass_qr_mode }, qf: this.$inertia.form({}), open: false, isNew: false, editId: null, cf: this.$inertia.form({}), bgSaved: null, samples: {}, sampleCss: '', ab: { running: false, stop: false, done: 0, total: 0, msg: '' } };
  },
  computed: {
    errs() { return this.$page.props.errors || {}; },
    units() { return [{ value: 'days', label: this.$t('days') }, { value: 'months', label: this.$t('months') }, { value: 'years', label: this.$t('years') }]; },
    swatches() { return [{ hex: '#ffffff', name: this.$t('White') }, { hex: '#e5e7eb', name: this.$t('Light grey') }, { hex: '#1e6fd9', name: this.$t('Blue') }, { hex: '#ffa800', name: this.$t('Yellow orange') }, { hex: '#16a34a', name: this.$t('Green') }, { hex: '#dc2626', name: this.$t('Red') }]; },
    bgColour: { get() { return this.cf.photo_background || '#ffffff'; }, set(v) { this.cf.photo_background = v; } },
    iconOptions() { return this.icons.map(i => ({ value: i, label: i })); },
  },
  watch: {
    // the small IDs follow the settings: drawn again whenever a category is saved
    categories: { handler() { this.loadSamples(); } },
  },
  mounted() { this.loadSamples(); },
  methods: {
    async loadSamples() { try { const { data } = await window.axios.get(route('id-passes.settings.samples'), { headers: { 'X-Quiet': '1' } }); this.sampleCss = data.css; this.samples = data.cards; } catch (e) { /* the icon still shows */ } },
    // a band that is white or very light would make an invisible icon: it takes a slate blue instead
    iconColour(hex) {
      const h = String(hex || '').replace('#', ''), v = h.length === 3 ? h.split('').map(x => x + x).join('') : h;
      if (!/^[0-9a-f]{6}$/i.test(v)) return '#1565c0';
      const l = (0.299 * parseInt(v.slice(0, 2), 16) + 0.587 * parseInt(v.slice(2, 4), 16) + 0.114 * parseInt(v.slice(4, 6), 16)) / 255;
      return l > 0.8 ? '#475569' : '#' + v;
    },
    kindName(k) { return (this.kinds.find(x => x.value === k) || {}).label || k; },
    resetGeneral() { this.g = { ...this.g, ...this.defaults }; },
    saveGeneral() { this.gf = this.$inertia.form({ ...this.g }); this.gf.put(route('id-passes.settings.general'), { preserveScroll: true }); },
    saveQr() { this.qf = this.$inertia.form({ ...this.q }); this.qf.put(route('id-passes.settings.qr'), { preserveScroll: true }); },
    openNew() { this.isNew = true; this.editId = null; this.ab = { running: false, stop: false, done: 0, total: 0, msg: '' }; this.cf = this.$inertia.form({ kind: 'compound', name: '', label: '', band_color: '#1565c0', arrow_color: '#fff200', photo_background: null, icon: null, prefix: '', digits: 3, validity_value: 1, validity_unit: 'years', contact_categories: [], requires_approval: false, photo_required: true, vehicle_required: false, pooled: false }); this.cf.clearErrors(); this.open = true; },
    openEdit(c) { this.isNew = false; this.editId = c.id; this.bgSaved = c.photo_background || null; this.ab = { running: false, stop: false, done: 0, total: 0, msg: '' }; this.cf = this.$inertia.form({ ...c, contact_categories: [...(c.contact_categories || [])] }); this.cf.clearErrors(); this.open = true; },
    saveCat() {
      const o = { preserveScroll: true, onSuccess: () => { this.open = false; } };
      if (this.isNew) this.cf.post(route('id-passes.settings.categories.store'), o); else this.cf.put(route('id-passes.settings.categories.update', [this.editId]), o);
    },
    /** Re-colours the photographs of the IDs already issued: the server does those that kept their cut-out, this browser (it holds the cut-out engine) does the rest one by one. */
    async applyBg() {
      const ab = this.ab; Object.assign(ab, { running: true, stop: false, done: 0, total: 0, msg: this.$t('Working on the IDs already issued') });
      let r;
      try { r = (await window.axios.post(route('id-passes.settings.categories.photo-background', [this.editId]), {}, { headers: { 'X-Quiet': '1' } })).data; } catch (e) { ab.running = false; ab.msg = (e.response && e.response.data && e.response.data.message) || this.$t('This could not be done.'); return; }
      let ok = 0; const failed = []; ab.total = r.pending.length;
      for (const p of r.pending) {
        if (ab.stop) break;
        ab.msg = this.$t('Photograph of :n (:i of :t)', { n: p.name, i: ab.done + 1, t: ab.total });
        try {
          const img = (await window.axios.get(p.photo, { responseType: 'blob' })).data;
          const cut = await removeBackground(img, null, { trim: false });
          const fd = new FormData(); fd.append('photo', new File([img], 'photo.jpg', { type: 'image/jpeg' })); fd.append('photo_cutout', new File([cut], 'cutout.png', { type: 'image/png' })); fd.append('keep_original', '1');
          await window.axios.post(p.save, fd, { headers: { 'X-Quiet': '1' } }); ok++;
        } catch (e) { failed.push(p.number); }
        ab.done++;
      }
      ab.running = false;
      ab.msg = this.$t(':a photographs re-coloured, :k already had the colour, :b done here in the browser.', { a: r.recoloured + ok, k: r.kept, b: ok }) + (failed.length ? ' ' + this.$t('Could not be done: :l.', { l: failed.join(', ') }) : '') + (ab.stop ? ' ' + this.$t('Stopped.') : '');
      this.$inertia.reload({ only: ['categories'], preserveScroll: true });
    },
    remove(c) { this.$confirm(this.$t('Delete :n?', { n: c.name })).then(ok => { if (ok) this.$inertia.delete(route('id-passes.settings.categories.destroy', [c.id]), { preserveScroll: true }); }).catch(() => {}); },
  },
};
</script>

<style scoped>
.st-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; vertical-align: middle; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.9), 0 3px 8px -2px rgba(15, 37, 64, 0.5); }
tr.is-off td { opacity: 0.55; }
.st-two { display: grid; gap: 18px; align-items: start; margin: 18px 0; grid-template-columns: minmax(0, 1fr); } @media (min-width: 1100px) { .st-two { grid-template-columns: minmax(0, 1fr) 300px; } .st-prev { position: sticky; top: 84px; } }
.st-prev { display: grid; gap: 10px; padding: 16px; border-radius: 22px; background: var(--lg-card, #fff); box-shadow: 0 18px 34px -22px rgba(15, 37, 64, 0.5), 0 0 0 1px var(--lg-line, #e2e8f0); }
.st-all { grid-column: 1 / -1; }
.st-bgdot { display: inline-block; width: 14px; height: 14px; margin-left: 8px; border-radius: 4px; vertical-align: middle; box-shadow: 0 0 0 1px rgba(15, 37, 64, 0.3); }
.st-bg { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.st-sw { width: 38px; height: 38px; border-radius: 50%; border: 0; padding: 0; cursor: pointer; box-shadow: 0 0 0 2px #fff, 0 0 0 3px rgba(15, 37, 64, 0.25), 0 6px 12px -6px rgba(15, 37, 64, 0.6); transition: transform 0.15s; } .st-sw:hover { transform: scale(1.1); } .st-sw.is-on { box-shadow: 0 0 0 2px #fff, 0 0 0 5px #1565c0, 0 6px 12px -6px rgba(15, 37, 64, 0.6); }
.st-bg .st-sw-pick { width: 38px; min-width: 38px; max-width: 38px; height: 38px; padding: 2px; background: conic-gradient(red, yellow, lime, cyan, blue, magenta, red); overflow: hidden; }
.st-apply { display: grid; gap: 8px; justify-items: start; margin-top: 10px; padding: 12px; border-radius: 16px; background: var(--lg-soft, #f1f5f9); width: 100%; }
.st-ab { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; width: 100%; font-weight: 700; font-size: 0.82rem; } .st-bar { flex: 1 1 100%; height: 8px; border-radius: 99px; background: rgba(15, 37, 64, 0.12); overflow: hidden; } .st-bar i { display: block; height: 100%; background: #7c3aed; transition: width 0.3s; }
.st-color { height: 42px; padding: 3px 6px; width: 100%; border-radius: 12px; cursor: pointer; }
.st-chips { display: flex; flex-wrap: wrap; gap: 8px; } .st-chips label { display: inline-flex; gap: 6px; align-items: center; padding: 6px 12px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); font-weight: 700; font-size: 0.82rem; cursor: pointer; } .st-chips label.is-on { background: #1565c0; color: #fff; }
.st-checks { display: grid; gap: 8px; } .st-checks label { display: flex; gap: 8px; align-items: center; font-weight: 700; }
.st-opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; margin-bottom: 10px; border-radius: 16px; cursor: pointer; box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); } .st-opt.is-on { box-shadow: 0 0 0 2px #1565c0; } .st-opt span { display: grid; gap: 2px; } .st-opt small { color: var(--lg-mute, #64748b); }
</style>
