<template>
  <admin-layout :title="pageTitle">
    <id-make-overlay />
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="info.icon" :title="pageTitle" :subtitle="$t('Choose many people, see at once who still lacks something, give the numbers, and issue them in one run.')">
        <Link :href="route('id-passes.' + info.route + '.index')" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="list" class="w-4 h-4" /></span><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico" :style="{ background: cat ? cat.band : info.tone }"><icons :name="info.icon" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('1. Category and dates') }}</h2><p class="lg-card-sub">{{ $t('Everyone in this run gets this category. The numbers follow the order of the list below.') }}</p></div></header>
        <div class="lg-fields">
          <div class="ac-f"><label>{{ $t('Category') }} <b class="wims-req">*</b></label><acct-select v-model="categoryId" :options="catOptions" :placeholder="$t('Choose...')" /></div>
          <div class="ac-f"><label>{{ $t('Date of issue') }} <b class="wims-req">*</b></label><date-picker v-model="issuedAt" :clearable="false" /></div>
          <div class="ac-f"><label>{{ $t('Expiry date') }}</label><date-picker v-model="expiresAt" clearable @update:model-value="expTouched = true" /><small class="lg-subtext">{{ validityHelp }}</small></div>
          <div v-if="canNumber && cat" class="ac-f"><label>{{ $t('Start numbering at') }}</label><input v-model.number="startAt" type="number" min="1" inputmode="numeric" :placeholder="String(cat.next_seq)" /><small class="lg-subtext">{{ $t('Leave empty to take the next free number (:n).', { n: cat.next }) }}</small></div>
        </div>
        <p v-if="cat && cat.requires_approval" class="lg-note lg-note-amber"><icons name="info" class="w-4 h-4" /><span>{{ $t('This category needs approval: the IDs are saved as waiting for approval.') }}</span></p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('2. Choose the people') }}</h2><p class="lg-card-sub">{{ info.kind === 'staff' ? $t('Working staff. Each needs a full name, a contact number and a photograph (the profile photograph is used).') : $t('Contacts of the categories of this ID. Each needs a full name and a contact number, and you add the photograph here.') }}</p></div></header>
        <div class="bk-tools">
          <input v-model="q" type="search" class="bk-search" :placeholder="$t('Search by name, number or phone')" @input="debounce" />
          <button type="button" class="lg-btn tone" style="--tone: #2563eb" :disabled="!list.length" @click="addShown"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Add all shown') }}</button>
          <button type="button" class="lg-btn tone" style="--tone: #7c3aed" :disabled="busyAll || !total" @click="addAll"><span class="tone-ico"><icons :name="busyAll ? 'refresh' : 'collection'" class="w-4 h-4" :class="{ 'animate-spin': busyAll }" /></span>{{ $t('Add all :n that match', { n: Math.min(total, 300) }) }}</button>
        </div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th class="bk-chk"></th><th>{{ $t('Name') }}</th><th>{{ info.kind === 'staff' ? $t('Staff') : $t('Category') }}</th><th>{{ $t('Contact') }}</th><th v-if="info.kind === 'staff'">{{ $t('Photo') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in list" :key="p.id" class="bk-row" :class="{ 'is-sel': chosen(p.id), 'is-off': p.card }" @click="toggle(p)">
              <td class="bk-chk" @click.stop><input type="checkbox" :checked="chosen(p.id)" :disabled="!!p.card" @change="toggle(p)" /></td>
              <td :data-label="$t('Name')"><b>{{ p.name }}</b><small v-if="p.card" class="bk-has">{{ $t('Already holds :n', { n: p.card }) }}</small></td>
              <td :data-label="$t('Staff')">{{ p.sub }}</td>
              <td :data-label="$t('Contact')"><span v-if="p.phone" class="lg-chip lg-chip-green">{{ p.phone }}</span><span v-else class="lg-chip lg-chip-red">{{ $t('Missing') }}</span></td>
              <td v-if="info.kind === 'staff'" :data-label="$t('Photo')"><span class="lg-chip" :class="p.has_photo ? 'lg-chip-green' : 'lg-chip-red'">{{ p.has_photo ? $t('Has photo') : $t('Missing') }}</span></td>
            </tr>
            <tr v-if="!list.length && !loading" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('Nobody matches.') }}</td></tr>
          </tbody>
        </table></div>
        <div v-if="last > 1" class="bk-pages"><button type="button" class="lg-btn tone" :disabled="page <= 1" style="--tone: #2563eb" @click="load(page - 1)"><span class="tone-ico"><icons name="arrow-left" class="w-4 h-4" /></span>{{ $t('Previous') }}</button><span>{{ page }} / {{ last }}</span><button type="button" class="lg-btn tone" :disabled="page >= last" style="--tone: #2563eb" @click="load(page + 1)"><span class="tone-ico"><icons name="forward" class="w-4 h-4" /></span>{{ $t('Next') }}</button></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('3. Check and number') }} <span class="bk-count">{{ rows.length }}</span></h2><p class="lg-card-sub">{{ $t('The top of the list gets the first number. Move people up or down, or type a number if you may.') }}</p></div>
          <div v-if="rows.length" class="bk-head-btns"><button type="button" class="lg-btn tone" style="--tone: #64748b" @click="sortByName"><span class="tone-ico"><icons name="list" class="w-4 h-4" /></span>{{ $t('Sort by name') }}</button><button type="button" class="lg-btn tone" style="--tone: #dc2626" @click="clearAll"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Clear') }}</button></div></header>
        <div v-if="!rows.length" class="bk-empty"><icons name="users" class="w-8 h-8" /><span>{{ $t('Nobody chosen yet. Tick people above and they appear here, checked and numbered.') }}</span></div>
        <transition-group v-else name="bk-r" tag="div" class="bk-list">
          <div v-for="(r, i) in rows" :key="r.key" class="bk-item" :class="{ 'is-bad': !r.ok || r.state === 'bad', 'is-done': r.state === 'ok' }">
            <span class="bk-no">{{ i + 1 }}</span>
            <div class="bk-photo" @click="r.state !== 'ok' && openPhoto(r)" :class="{ 'is-add': !r.thumb && !r.hasPhoto }">
              <img v-if="r.thumb" :src="r.thumb" alt="" /><img v-else-if="info.kind === 'staff' && r.hasPhoto" :src="route('id-passes.lookup.staff-photo', r.id)" alt="" loading="lazy" /><icons v-else name="camera" class="w-5 h-5" />
            </div>
            <div class="bk-who"><b>{{ r.name }}</b><small>{{ r.sub }}</small>
              <div class="bk-flags"><span v-for="m in r.missing" :key="m" class="lg-chip lg-chip-red">{{ $t('Missing :x', { x: m }) }}</span><span v-if="!r.missing.length && r.state !== 'ok' && r.state !== 'bad'" class="lg-chip lg-chip-green">{{ $t('Ready') }}</span><span v-if="dup[i]" class="lg-chip lg-chip-red">{{ $t('Number used twice') }}</span></div>
              <small v-if="r.state === 'bad'" class="bk-err">{{ r.message }}</small>
            </div>
            <div class="bk-fix">
              <input v-if="r.needsPhone" v-model="r.phone" class="bk-phone" inputmode="tel" maxlength="60" :placeholder="$t('Contact number')" :disabled="r.state === 'ok'" />
              <button v-if="r.state !== 'ok'" type="button" class="lg-btn tone" style="--tone: #7c3aed" @click="openPhoto(r)"><span class="tone-ico"><icons name="camera" class="w-4 h-4" /></span>{{ r.thumb || r.hasPhoto ? $t('Change photo') : $t('Add photo') }}</button>
            </div>
            <div class="bk-num">
              <template v-if="r.state === 'ok'"><b class="bk-done">{{ r.number }}</b></template>
              <template v-else><b>{{ planned[i] || '-' }}</b><input v-if="canNumber" v-model.number="r.seq" class="bk-seq" type="number" min="1" inputmode="numeric" :placeholder="$t('Number')" :title="$t('Give this person a number of your choice')" /></template>
            </div>
            <div class="bk-move" v-if="r.state !== 'ok'"><button type="button" class="lg-icon-btn" :disabled="i === 0" :aria-label="$t('Move up')" @click="move(i, -1)"><icons name="chevron-up" class="w-4 h-4" /></button><button type="button" class="lg-icon-btn" :disabled="i === rows.length - 1" :aria-label="$t('Move down')" @click="move(i, 1)"><icons name="chevron-down" class="w-4 h-4" /></button><button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="remove(i)"><icons name="trash" class="w-4 h-4" /></button></div>
            <a v-else :href="route('id-passes.' + info.route + '.show', [r.card_id])" target="_blank" rel="noopener" class="lg-icon-btn" :aria-label="$t('Open')"><icons name="eye" class="w-4 h-4" /></a>
          </div>
        </transition-group>
      </section>

      <section v-if="bg.show" class="lg-card bk-bg">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="camera" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Photo backgrounds') }}</h2><p class="lg-card-sub">{{ bg.msg }}</p></div><button v-if="bg.running" type="button" class="lg-btn tone" style="--tone: #d97706" @click="bg.stop = true"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Stop') }}</button></header>
        <div class="bk-bar"><i :style="{ width: bg.total ? Math.round((bg.done / bg.total) * 100) + '%' : (bg.running ? '8%' : '100%') }"></i></div>
      </section>

      <div class="bk-dock" :class="{ 'is-empty': !readyCount }">
        <div class="bk-dock-info"><b>{{ readyCount }} {{ $t('ready') }}</b><small>{{ attentionCount ? $t(':n need attention (left out until fixed)', { n: attentionCount }) : (rows.length ? $t('Everyone is complete.') : $t('Choose people above.')) }}</small></div>
        <button v-if="failedCount" type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="running" @click="run"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Try the :n that failed again', { n: failedCount }) }}</button>
        <button type="button" class="lg-btn tone tone-solid bk-go" :disabled="running || !readyCount || !cat" style="--tone: #16a34a" @click="run"><span class="tone-ico"><icons :name="running ? 'refresh' : 'send'" class="w-4 h-4" :class="{ 'animate-spin': running }" /></span>{{ running ? $t('Issuing...') : $t('Issue :n IDs', { n: readyCount }) }}</button>
      </div>
      <div class="bk-spacer"></div>
    </div>

    <!-- after a run: the records of these people lacked what was added here. Yes or No. -->
    <travel-modal :show="!!ask" :title="$t('Save them to the company records too?')" :subtitle="info.kind === 'staff' ? $t('Sync with the Staff records') : $t('Sync with the Contacts')" icon="refresh" :busy="syncing" @close="ask = null">
      <div v-if="ask" class="bk-ask">
        <ul><li v-if="ask.phones"><span class="a-ico"><icons name="phone" class="w-4 h-4" /></span><b>{{ ask.phones }}</b> {{ $t('contact numbers you typed') }}</li><li v-if="ask.photos"><span class="a-ico"><icons name="camera" class="w-4 h-4" /></span><b>{{ ask.photos }}</b> {{ $t('photographs you added') }}</li></ul>
        <p>{{ $t('These people had nothing for them in their company record. Only empty fields are filled; nothing already in a record is replaced.') }}</p>
      </div>
      <template #footer><button type="button" class="lg-btn tone" :disabled="syncing" style="--tone: #64748b" @click="ask = null"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('No, keep them on the IDs only') }}</button><button type="button" class="lg-btn tone tone-solid" :disabled="syncing" style="--tone: #0d9488" @click="syncNow"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Yes, sync') }}</button></template>
    </travel-modal>

    <travel-modal :show="!!pr" :title="pr ? $t('Photograph of :n', { n: pr.name }) : ''" :subtitle="$t('Choose or take a picture; the part inside the frame goes on the card.')" icon="camera" max-width="2xl" :busy="photoBusy" @close="pr = null">
      <photo-cropper v-if="pr" v-model="prPhoto" :staff-photo-url="info.kind === 'staff' && pr.hasPhoto ? route('id-passes.lookup.staff-photo', pr.id) : ''" :min-width="minPhotoWidth" :background="(cat && cat.photo_bg) || ''" @busy="photoBusy = $event" />
      <template #footer><button type="button" class="lg-btn tone" style="--tone: #64748b" @click="pr = null"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="button" class="lg-btn tone tone-solid" style="--tone: #16a34a" :disabled="!prPhoto || photoBusy" @click="savePhoto"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Use this photograph') }}</button></template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import PhotoCropper from '../Parts/PhotoCropper.vue';
import IdMakeOverlay from '../Parts/IdMakeOverlay.vue';
import { idMake, idMakeStart, idMakeProgress, idMakeDone, idMakeFail, idMakeClose, idMakeBulkNow, idMakeBulkItem } from '@/Core/idMake';
import { removeBackground } from '@/Core/removeBackground';

const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const initialsOf = n => { const p = String(n || '').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); };

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect, TravelModal, PhotoCropper, IdMakeOverlay },
  props: { info: Object, categories: Array, today: String, canNumber: Boolean, minPhotoWidth: Number, chunk: Number },
  data() {
    const first = this.categories[0] || null;
    return { categoryId: first ? first.id : null, issuedAt: this.today, expiresAt: null, expTouched: false, startAt: null, q: '', list: [], total: 0, page: 1, last: 1, loading: false, busyAll: false, timer: null,
      rows: [], ask: null, syncing: false, pr: null, prPhoto: null, photoBusy: false, running: false, bg: { show: false, running: false, stop: false, done: 0, total: 0, msg: '' } };
  },
  computed: {
    pageTitle() { return this.$t('Issue many :k', { k: this.info.label }); },
    cat() { return this.categories.find(c => c.id === this.categoryId) || null; },
    catOptions() { return this.categories.map(c => ({ value: c.id, label: c.name })); },
    validityHelp() { const c = this.cat; if (!c || !c.validity_value) return this.$t('This category has no standard validity.'); return this.$t('This category is valid for :n :u unless you change the date.', { n: c.validity_value, u: this.$t(c.validity_unit) }); },
    // the number each person would get: the order of the list, the start number, and any number typed for a person
    plan() {
      const c = this.cat; if (!c) return [];
      const live = r => r.state === 'ok' ? +r.realSeq : (r.ok && r.seq ? +r.seq : null);
      const typed = new Set(this.rows.map(live).filter(Boolean));
      let n = this.startAt ? +this.startAt : c.next_seq;
      // only the people who are complete get a number; the others wait (and take the next number when they are fixed)
      return this.rows.map(r => { if (r.state === 'ok') return +r.realSeq || 0; if (!r.ok) return null; if (r.seq) return +r.seq; while (typed.has(n)) n++; return n++; });
    },
    planned() { const c = this.cat; return this.plan.map(s => (s === null ? '' : c ? c.prefix + String(s).padStart(c.digits, '0') : s)); },
    sendSeq() { return !!this.startAt || this.rows.some(r => r.seq && r.state !== 'ok'); },
    dup() { const seen = {}; return this.plan.map((s, i) => { if (s === null || this.rows[i].state === 'ok') return false; const d = seen[s] !== undefined; seen[s] = i; return d; }); },
    readyCount() { return this.rows.filter((r, i) => r.ok && r.state !== 'ok' && !this.dup[i]).length; },
    attentionCount() { return this.rows.filter((r, i) => r.state !== 'ok' && (!r.ok || this.dup[i])).length; },
    failedCount() { return this.rows.filter(r => r.state === 'bad').length; },
  },
  watch: {
    categoryId() { this.applyExpiry(); this.rows = this.rows.filter(r => r.state === 'ok'); this.load(1); },
    issuedAt() { this.applyExpiry(); },
    rows: { deep: true, handler() { this.rows.forEach(r => this.check(r)); } },
  },
  mounted() { this.applyExpiry(); this.load(1); },
  methods: {
    check(r) {
      const miss = [];
      if (!String(r.name || '').trim()) miss.push(this.$t('name'));
      if (!String(r.phone || '').trim()) miss.push(this.$t('contact'));
      if (!r.thumb && !r.hasPhoto) miss.push(this.$t('photograph'));
      const ok = miss.length === 0;
      if (r.ok !== ok) r.ok = ok;
      if (r.missing.join() !== miss.join()) r.missing = miss;
    },
    applyExpiry() {
      const c = this.cat; if (!c || this.expTouched) return;
      if (!c.validity_value || !this.issuedAt) { this.expiresAt = null; return; }
      const [y, m, d] = this.issuedAt.split('-').map(Number); const from = new Date(y, m - 1, d); let e = new Date(from);
      if (c.validity_unit === 'days') e.setDate(e.getDate() + c.validity_value - 1);
      else { const day = e.getDate(); e.setDate(1); e.setMonth(e.getMonth() + (c.validity_unit === 'years' ? c.validity_value * 12 : c.validity_value)); const lastDay = new Date(e.getFullYear(), e.getMonth() + 1, 0).getDate(); e.setDate(Math.min(day, lastDay)); e.setDate(e.getDate() - 1); }
      this.expiresAt = ymd(e);
    },
    debounce() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(1), 350); },
    async load(page) {
      if (!this.cat) return; this.loading = true; this.page = page;
      try { const { data } = await window.axios.get(route('id-passes.' + this.info.route + '.bulk.people'), { params: { category_id: this.cat.id, q: this.q, page }, headers: { 'X-Quiet': '1' } }); this.list = data.rows; this.total = data.total; this.last = data.last; } catch (e) { this.list = []; }
      this.loading = false;
    },
    chosen(id) { return this.rows.some(r => r.id === id); },
    makeRow(p) { return { key: p.id + ':' + Math.random().toString(36).slice(2, 7), id: p.id, name: p.name, sub: p.sub, phone: p.phone || '', title: p.title || '', hasPhoto: !!p.has_photo, thumb: null, file: null, seq: null, ok: false, missing: [], state: 'idle', message: '', number: '', card_id: null, realSeq: null, needsPhone: !p.phone }; },
    toggle(p) {
      if (p.card) return;
      const i = this.rows.findIndex(r => r.id === p.id);
      if (i >= 0) { if (this.rows[i].state !== 'ok') this.rows.splice(i, 1); } else this.rows.push(this.makeRow(p));
    },
    addShown() { this.list.filter(p => !p.card && !this.chosen(p.id)).forEach(p => this.rows.push(this.makeRow(p))); },
    async addAll() {
      this.busyAll = true;
      try { const { data } = await window.axios.get(route('id-passes.' + this.info.route + '.bulk.people'), { params: { category_id: this.cat.id, q: this.q, all: 1 }, headers: { 'X-Quiet': '1' } }); data.rows.filter(p => !p.card && !this.chosen(p.id)).forEach(p => this.rows.push(this.makeRow(p))); } catch (e) { /* the list stays */ }
      this.busyAll = false;
    },
    move(i, d) { const j = i + d; if (j < 0 || j >= this.rows.length) return; const r = this.rows.splice(i, 1)[0]; this.rows.splice(j, 0, r); },
    remove(i) { this.rows.splice(i, 1); },
    sortByName() { this.rows.sort((a, b) => (a.state === 'ok' ? -1 : 0) - (b.state === 'ok' ? -1 : 0) || a.name.localeCompare(b.name)); },
    clearAll() { this.rows = this.rows.filter(r => r.state === 'ok'); },
    openPhoto(r) { this.pr = r; this.prPhoto = null; },
    savePhoto() { const r = this.pr; if (!r || !this.prPhoto) return; r.file = this.prPhoto; r.thumb = this.prPhoto.dataUri; this.pr = null; },

    // ---- the run ----
    batches(list) {
      const out = []; let cur = [], bytes = 0;
      list.forEach(r => { const b = r.file ? (r.file.file ? r.file.file.size : 0) + (r.file.original ? r.file.original.size : 0) + (r.file.cutout ? r.file.cutout.size : 0) : 0; if (cur.length >= this.chunk || (cur.length && bytes + b > 5 * 1024 * 1024)) { out.push(cur); cur = []; bytes = 0; } cur.push(r); bytes += b; });
      if (cur.length) out.push(cur);
      return out;
    },
    async run() {
      if (this.running || !this.cat) return;
      const todo = this.rows.map((r, i) => ({ r, i })).filter(({ r, i }) => r.ok && r.state !== 'ok' && !this.dup[i]);
      if (!todo.length) return;
      this.running = true; this.bg.show = false;
      const t0 = Date.now(); const ref = 'BULK-' + Date.now().toString(36).toUpperCase(); const total = todo.length; let done = 0; const madeIds = [];
      idMakeStart({ mode: 'bulk', color: this.cat.band || '#1565c0', count: total, total, percent: 0, title: this.$t('Issuing :n IDs', { n: total }) });
      try {
        for (const group of this.batches(todo.map(t => t.r))) {
          idMakeBulkNow(group[0].name + (group.length > 1 ? ' +' + (group.length - 1) : ''));
          const fd = new FormData(); fd.append('category_id', this.cat.id); fd.append('issued_at', this.issuedAt); if (this.expiresAt) fd.append('expires_at', this.expiresAt); fd.append('ref', ref);
          group.forEach((r, j) => {
            const idx = this.rows.indexOf(r);
            fd.append(`rows[${j}][id]`, r.id); fd.append(`rows[${j}][phone]`, r.phone || ''); if (r.title) fd.append(`rows[${j}][title]`, r.title);
            if (this.sendSeq) fd.append(`rows[${j}][seq]`, this.plan[idx]);
            if (r.file) { fd.append(`rows[${j}][photo]`, r.file.file); if (r.file.original) fd.append(`rows[${j}][photo_original]`, r.file.original); if (r.file.cutout) fd.append(`rows[${j}][photo_cutout]`, r.file.cutout); }
          });
          let results = [];
          try { results = (await window.axios.post(route('id-passes.' + this.info.route + '.bulk.issue'), fd, { headers: { 'X-Quiet': '1' } })).data.results; }
          catch (e) { const m = (e.response && e.response.data && (e.response.data.message || Object.values(e.response.data.errors || {}).flat()[0])) || this.$t('This lot could not be made.'); results = group.map(r => ({ id: r.id, ok: false, message: m })); }
          group.forEach((r, j) => {
            const res = results.find(x => x.id === r.id) || { ok: false, message: this.$t('No answer for this person.') };
            if (res.ok) { r.state = 'ok'; r.number = res.number; r.realSeq = res.seq; r.card_id = res.card_id; madeIds.push(res.card_id); r.message = ''; } else { r.state = 'bad'; r.message = res.message; }
            idMakeBulkItem({ ok: !!res.ok, label: res.ok ? res.number : '!', initials: initialsOf(r.name) });
            done++;
          });
          const elapsed = (Date.now() - t0) / 1000; const left = Math.max(0, Math.round((elapsed / done) * (total - done)));
          idMake.eta = done < total ? this.$t('about :s s left', { s: left }) : '';
          idMakeProgress((done / total) * 100, done, total);
        }
        const ok = this.rows.filter(r => r.state === 'ok').length, bad = this.rows.filter(r => r.state === 'bad').length;
        idMakeDone(bad ? this.$t(':a IDs issued, :b need attention.', { a: ok, b: bad }) : this.$t(':a IDs issued.', { a: ok }));
      } catch (e) { idMakeFail(this.$t('The run stopped. The IDs already made are kept.')); }
      this.running = false;
      this.offerSync(madeIds);
      if (madeIds.length && this.cat.photo_bg) this.backgrounds(madeIds);
    },

    // what this page added that the company record lacked: ask once, Yes or No
    offerSync(ids) {
      const done = this.rows.filter(r => r.state === 'ok' && ids.includes(r.card_id));
      const phones = done.filter(r => r.needsPhone && String(r.phone || '').trim()).length;
      const photos = this.info.kind === 'staff' ? done.filter(r => !r.hasPhoto && r.thumb).length : 0;
      if (phones || photos) setTimeout(() => { this.ask = { phones, photos, ids: done.map(r => r.card_id) }; }, 2100);
    },
    async syncNow() {
      const a = this.ask; if (!a || this.syncing) return; this.syncing = true; this.ask = null;
      idMakeStart({ mode: 'sync', color: this.cat ? this.cat.band : '#0d9488', title: this.info.kind === 'staff' ? this.$t('Syncing with the Staff records') : this.$t('Syncing with the Contacts') });
      try { const { data } = await window.axios.post(route('id-passes.' + this.info.route + '.bulk.sync'), { ids: a.ids }, { headers: { 'X-Quiet': '1' } }); idMakeDone(data.message); }
      catch (e) { idMakeFail((e.response && e.response.data && e.response.data.message) || this.$t('The sync could not be made.')); }
      this.syncing = false;
    },
    // the photographs of the new IDs on the colour of the category: the server does what it can, the browser makes the cut-outs of the rest, one by one, without holding anything up
    async backgrounds(ids) {
      const bg = this.bg; Object.assign(bg, { show: true, running: true, stop: false, done: 0, total: 0, msg: this.$t('Checking the photograph backgrounds...') });
      let r;
      try { r = (await window.axios.post(route('id-passes.' + this.info.route + '.bulk.backgrounds'), { ids }, { headers: { 'X-Quiet': '1' } })).data; } catch (e) { bg.running = false; bg.msg = this.$t('The photograph backgrounds could not be checked now. The IDs are already issued; you can apply the colour later in ID Settings.'); return; }
      bg.total = r.pending.length; let okN = 0; const failed = [];
      for (const p of r.pending) {
        if (bg.stop) break;
        bg.msg = this.$t('Photograph of :n (:i of :t)', { n: p.name, i: bg.done + 1, t: bg.total });
        try {
          const img = (await window.axios.get(p.photo, { responseType: 'blob' })).data;
          const cut = await removeBackground(img, null, { trim: false });
          const fd = new FormData(); fd.append('photo', new File([img], 'photo.jpg', { type: 'image/jpeg' })); fd.append('photo_cutout', new File([cut], 'cutout.png', { type: 'image/png' })); fd.append('keep_original', '1');
          await window.axios.post(p.save, fd, { headers: { 'X-Quiet': '1' } }); okN++;
        } catch (e) { failed.push(p.number); }
        bg.done++;
      }
      bg.running = false;
      bg.msg = this.$t(':a photographs put on the colour, :b were already on it.', { a: r.recoloured + okN, b: r.kept }) + (failed.length ? ' ' + this.$t('Could not be done: :l. The photograph stays as taken.', { l: failed.join(', ') }) : '') + (bg.stop ? ' ' + this.$t('Stopped.') : '');
    },
  },
};

</script>

<style scoped>
.bk-tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 12px; } .bk-search { flex: 1 1 240px; min-width: 0; }
.bk-chk { width: 40px; } .bk-row { cursor: pointer; transition: background-color 0.15s; } .bk-row:hover { background: rgba(21, 101, 192, 0.05); } .bk-row.is-sel td { background: rgba(21, 101, 192, 0.08); } .bk-row.is-off { opacity: 0.55; cursor: not-allowed; } .bk-has { display: block; color: #b45309; font-weight: 700; }
.bk-pages { display: flex; gap: 12px; align-items: center; justify-content: center; margin-top: 12px; }
.bk-count { display: inline-grid; place-items: center; min-width: 26px; height: 22px; padding: 0 8px; margin-left: 6px; border-radius: 999px; background: #1565c0; color: #fff; font-size: 0.74rem; vertical-align: middle; }
.bk-head-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
.bk-empty { display: grid; justify-items: center; gap: 8px; padding: 34px 12px; color: var(--lg-mute, #64748b); text-align: center; }
.bk-list { display: grid; gap: 8px; }
.bk-item { display: grid; grid-template-columns: 30px 54px minmax(0, 1fr) auto auto auto; gap: 12px; align-items: center; padding: 10px 12px; border-radius: 18px; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); transition: box-shadow 0.25s, background 0.25s; }
.bk-item.is-bad { box-shadow: 0 0 0 1.5px #fca5a5; background: rgba(254, 226, 226, 0.35); } .bk-item.is-done { box-shadow: 0 0 0 1.5px #86efac; background: rgba(220, 252, 231, 0.4); }
.bk-no { font-weight: 800; color: var(--lg-mute, #64748b); text-align: center; font-variant-numeric: tabular-nums; }
.bk-photo { width: 54px; height: 66px; border-radius: 12px; overflow: hidden; display: grid; place-items: center; background: var(--lg-soft, #f1f5f9); color: #94a3b8; cursor: pointer; box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); } .bk-photo img { width: 100%; height: 100%; object-fit: cover; } .bk-photo.is-add { box-shadow: 0 0 0 2px #fca5a5; color: #dc2626; }
.bk-who { min-width: 0; display: grid; gap: 2px; } .bk-who b { overflow-wrap: anywhere; } .bk-who small { color: var(--lg-mute, #64748b); } .bk-flags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 3px; } .bk-err { color: #b91c1c !important; font-weight: 700; }
.bk-fix { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end; } .bk-phone { width: 150px; }
.bk-num { display: grid; gap: 4px; justify-items: end; } .bk-num b { font-size: 1.05rem; letter-spacing: 0.04em; color: #1e4467; font-variant-numeric: tabular-nums; } .bk-done { color: #15803d !important; } .bk-seq { width: 86px; height: 30px; font-size: 0.8rem; }
.bk-move { display: flex; gap: 4px; }
.bk-r-enter-active, .bk-r-leave-active { transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1); } .bk-r-enter-from { opacity: 0; transform: translateY(14px) scale(0.98); } .bk-r-leave-to { opacity: 0; transform: translateX(-24px); } .bk-r-move { transition: transform 0.3s; }
.bk-bar { height: 8px; border-radius: 99px; background: rgba(15, 37, 64, 0.12); overflow: hidden; } .bk-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #7c3aed, #34d399); transition: width 0.4s; }
.bk-dock { position: sticky; bottom: 14px; z-index: 40; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 14px 12px 18px; margin-top: 16px; border-radius: 22px; background: color-mix(in srgb, var(--lg-card, #fff) 90%, transparent); backdrop-filter: blur(14px) saturate(1.4); -webkit-backdrop-filter: blur(14px) saturate(1.4); box-shadow: 0 0 0 1px rgba(15, 37, 64, 0.1), 0 24px 44px -18px rgba(15, 37, 64, 0.55); }
.bk-dock-info { flex: 1 1 200px; display: grid; min-width: 0; } .bk-dock-info b { font-size: 1.05rem; color: #1e4467; } .bk-dock-info small { color: var(--lg-mute, #64748b); font-weight: 600; }
.bk-go { min-width: 190px; justify-content: center; }
.bk-spacer { height: 4px; }
.bk-ask ul { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 8px; } .bk-ask li { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 14px; background: rgba(13, 148, 136, 0.09); } .bk-ask b { font-size: 1.2rem; color: #0f766e; } .bk-ask p { margin: 0; line-height: 1.5; color: #334155; } .a-ico { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 10px; background: #0d9488; color: #fff; }
@media (max-width: 767px) {
  .bk-item { grid-template-columns: 26px 48px minmax(0, 1fr); } .bk-fix, .bk-num, .bk-move { grid-column: 2 / -1; justify-content: flex-start; justify-items: start; } .bk-num { grid-auto-flow: column; align-items: center; gap: 10px; }
  .bk-dock { position: fixed; left: 10px; right: 10px; bottom: 74px; } .bk-spacer { height: 130px; } .bk-go { width: 100%; }
}
@media (prefers-reduced-motion: reduce) { .bk-r-enter-active, .bk-r-leave-active, .bk-r-move { transition: none; } }
</style>
