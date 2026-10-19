<template>
  <admin-layout :title="$t('Prayer Times')">
    <div class="lg-page px-4 md:px-0 ps-page">
      <page-header icon="clock" :title="$t('Prayer Times')" :subtitle="$t('Prayer times for Tanzania, a shutdown alert before each prayer, the prayer notice with a verse or hadith and the adhan, then a prayer break. Everything below is adjustable.')">
        <span class="lg-chip" :class="form.enabled ? 'lg-chip-green' : 'lg-chip-slate'"><span class="ps-dot"></span>{{ form.enabled ? $t('ON') : $t('OFF') }}</span>
      </page-header>

      <!-- today + master switch -->
      <section class="lg-card ps-hero" :class="{ on: form.enabled }">
        <div class="ps-hero__main">
          <label class="ps-switch ps-switch--big"><input v-model="form.enabled" type="checkbox" /><i></i><span>{{ $t('Prayer Times module') }} <small>{{ form.enabled ? $t('Alerts and prayer breaks are active') : $t('Nothing is shown and nobody is locked') }}</small></span></label>
          <div class="ps-today">
            <div v-for="t in today" :key="t.key" class="ps-today__i" :class="{ sun: t.key === 'sunrise' }"><small>{{ t.label }}</small><b>{{ t.time }}</b></div>
          </div>
          <p class="ps-hint">{{ form.city }} · {{ $t('East Africa Time') }} · {{ $t('saved settings') }}</p>
        </div>
        <div class="ps-hero__try">
          <b>{{ $t('See how it looks') }}</b>
          <button type="button" class="ps-btn ps-btn--soft" @click="preview('alert')"><icons name="exclamation" class="w-4 h-4" />{{ form.shutdown ? $t('Shutdown alert') : $t('Prayer reminder') }}</button>
          <button type="button" class="ps-btn ps-btn--soft" @click="preview('prayer')"><icons name="bell" class="w-4 h-4" />{{ $t('Prayer notice') }}</button>
        </div>
      </section>

      <form @submit.prevent="save">
        <!-- timeline -->
        <section class="lg-card ps-card">
          <header class="ps-head"><span class="ps-ico ps-ico--amber"><icons name="clock" class="w-5 h-5" /></span><div><h2>{{ $t('Alert and lock') }}</h2><p>{{ $t('Choose what happens at the prayer time, then set the numbers. The steps below show what people will see, using the real time of one prayer as an example.') }}</p></div></header>
          <div class="ps-mode" role="radiogroup" :aria-label="$t('When the prayer time comes')">
            <label class="ps-mode__opt" :class="{ on: form.shutdown }"><input v-model="form.shutdown" type="radio" :value="true" /><span class="ps-mode__dot"></span><span><b>{{ $t('Alert and Shutdown') }}</b><small>{{ $t('People are warned, then WIMS locks and signs them out for the prayer break.') }}</small></span></label>
            <label class="ps-mode__opt" :class="{ on: !form.shutdown }"><input v-model="form.shutdown" type="radio" :value="false" /><span class="ps-mode__dot"></span><span><b>{{ $t('Alert Only') }}</b><small>{{ $t('People are warned and the adhan plays, but WIMS never locks and nobody is signed out.') }}</small></span></label>
          </div>
          <div class="ps-grid ps-grid--times">
            <label class="ps-field" :class="{ 'ps-field--bad': timeErrors.alert }"><span>{{ $t('Shutdown alert starts this many minutes BEFORE the prayer') }}</span><input v-model.number="form.alert_before_min" type="number" min="1" max="120" step="1" class="ps-input" /><small v-if="timeErrors.alert" class="ps-err">{{ timeErrors.alert }}</small></label>
            <label class="ps-field" :class="{ 'ps-field--bad': timeErrors.notice }"><span>{{ form.shutdown ? $t('Prayer notice and adhan start when this many minutes are LEFT before the lock') : $t('Prayer notice and adhan start when this many minutes are LEFT before the prayer') }}</span><input v-model.number="form.notice_before_min" type="number" min="0" :max="form.alert_before_min" step="1" class="ps-input" /><small v-if="timeErrors.notice" class="ps-err">{{ timeErrors.notice }}</small><small v-else class="ps-hint">{{ $t('It cannot be bigger than the shutdown alert time, which is') }} {{ form.alert_before_min }} {{ $t('minutes') }}.</small></label>
            <label v-if="form.shutdown" class="ps-field" :class="{ 'ps-field--bad': timeErrors.lock }"><span>{{ $t('Keep WIMS locked for this many minutes') }}</span><input v-model.number="form.lock_minutes" type="number" min="1" max="180" step="1" class="ps-input" /><small v-if="timeErrors.lock" class="ps-err">{{ timeErrors.lock }}</small></label>
          </div>
          <div class="ps-steps">
            <p class="ps-steps__h">{{ $t('Example') }}: {{ example.label }} {{ $t('is at') }} {{ example.time }}</p>
            <ol>
              <li class="a"><time>{{ example.t(-form.alert_before_min) }}</time><div><b>{{ form.shutdown ? $t('Shutdown alert') : $t('Prayer reminder') }}</b><span>{{ form.shutdown ? $t('The countdown starts. A message says WIMS will lock soon, so people can save their work. Everyone keeps working.') : $t('The countdown starts. A message says the prayer is coming. Everyone keeps working.') }}</span></div></li>
              <li class="p"><time>{{ example.t(noticeAt) }}</time><div><b>{{ $t('Prayer notice and adhan') }}</b><span>{{ $t('When') }} {{ form.notice_before_min }} {{ $t('minutes are left, the notice with a verse or hadith shows and the adhan plays. Everyone keeps working.') }}</span></div></li>
              <li v-if="form.shutdown" class="l"><time>{{ example.t(form.lock_start_after_min) }}</time><div><b>{{ $t('WIMS locks') }}</b><span>{{ $t('The countdown ends. Everyone is signed out and nobody can sign in.') }}</span></div></li>
              <li v-if="form.shutdown" class="u"><time>{{ example.t(form.lock_start_after_min + form.lock_minutes) }}</time><div><b>{{ $t('WIMS opens again') }}</b><span>{{ $t('People can sign in and work as usual.') }} {{ $t('The lock lasts') }} {{ form.lock_minutes }} {{ $t('minutes') }}.</span></div></li>
            </ol>
          </div>
        </section>

        <!-- who -->
        <section class="lg-card ps-card">
          <header class="ps-head"><span class="ps-ico ps-ico--emerald"><icons name="users" class="w-5 h-5" /></span><div><h2>{{ form.shutdown ? $t('Who is locked') : $t('Who gets the alert') }}</h2><p>{{ $t('The alert, the notice and the lock only apply to these people. Everyone else keeps working.') }}</p></div></header>
          <label class="ps-switch"><input v-model="form.all_users" type="checkbox" /><i></i><span>{{ $t('All users') }}</span></label>

          <div v-if="!form.all_users" class="ps-people">
            <h3>{{ $t('Selected users') }} <small>{{ form.user_ids.length }}</small></h3>
            <div class="ps-add"><auto-complete id="ps_add_user" v-model="pickInclude" :suggestions="userOptions" reset-search :placeholder="$t('Search a user to add')" /><button type="button" class="ps-btn ps-btn--soft" @click="addAllUsers"><icons name="users" class="w-4 h-4" />{{ $t('Add all users') }}</button></div>
            <div class="ps-chips"><span v-for="u in chips(form.user_ids)" :key="u.id" class="ps-chip">{{ u.name }}<button type="button" :aria-label="$t('Remove')" @click="remove('user_ids', u.id)">×</button></span><em v-if="!form.user_ids.length">{{ $t('Nobody selected yet. Nobody is locked.') }}</em></div>
          </div>
          <div v-else class="ps-people">
            <h3>{{ $t('Except these users') }} <small>{{ form.excluded_user_ids.length }}</small></h3>
            <div class="ps-add"><auto-complete id="ps_add_excl" v-model="pickExclude" :suggestions="userOptions" reset-search :placeholder="$t('Search a user to leave out')" /></div>
            <div class="ps-chips"><span v-for="u in chips(form.excluded_user_ids)" :key="u.id" class="ps-chip ps-chip--out">{{ u.name }}<button type="button" :aria-label="$t('Remove')" @click="remove('excluded_user_ids', u.id)">×</button></span><em v-if="!form.excluded_user_ids.length">{{ $t('Nobody left out.') }}</em></div>
          </div>
          <label v-if="form.shutdown" class="ps-switch mt-3"><input v-model="form.exempt_super_admins" type="checkbox" /><i></i><span>{{ $t('Never lock Super Admins') }} <small>{{ $t('So someone can always sign in to change these settings') }}</small></span></label>
        </section>

        <!-- adhan -->
        <section class="lg-card ps-card">
          <header class="ps-head"><span class="ps-ico ps-ico--violet"><icons name="microphone" class="w-5 h-5" /></span><div><h2>{{ $t('Adhan') }}</h2><p>{{ $t('Plays in the background when the prayer notice appears.') }}</p></div></header>
          <label class="ps-switch"><input v-model="form.adhan_enabled" type="checkbox" /><i></i><span>{{ $t('Play the adhan') }}</span></label>
          <div class="ps-adhans" :class="{ off: !form.adhan_enabled }">
            <label v-for="a in adhans" :key="a.value" class="ps-adhan" :class="{ on: form.adhan === a.value }">
              <input v-model="form.adhan" type="radio" :value="a.value" />
              <b>{{ a.label }}</b><small>{{ a.note }}</small>
              <span class="ps-adhan__row">
                <button type="button" class="ps-btn ps-btn--soft ps-btn--sm" @click.prevent="toggleSample(a)"><icons :name="sample === a.value ? 'pause' : 'play'" class="w-4 h-4" />{{ sample === a.value ? $t('Stop') : $t('Listen') }}</button>
                <button v-if="a.custom" type="button" class="ps-btn ps-btn--danger ps-btn--sm" @click.prevent="delAdhan(a)"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button>
                <em v-else class="ps-adhan__lock">{{ $t('Built in') }}</em>
              </span>
            </label>
          </div>

          <!-- add your own -->
          <form class="ps-addadhan" @submit.prevent="addAdhan">
            <h3>{{ $t('Add an adhan') }}</h3>
            <p>{{ $t('Give it a name and choose the audio file (MP3, WAV, OGG, M4A or AAC, up to 12 MB). It is listed with the others and you can delete it any time.') }}</p>
            <div class="ps-addadhan__row">
              <label class="ps-field" :class="{ 'ps-field--bad': newAdhan.errors.name }"><span>{{ $t('Name') }}</span><input v-model="newAdhan.name" type="text" maxlength="80" class="ps-input" :placeholder="$t('For example: Adhan, Makkah')" /><small v-if="newAdhan.errors.name" class="ps-err">{{ newAdhan.errors.name }}</small></label>
              <div class="ps-field" :class="{ 'ps-field--bad': newAdhan.errors.file }">
                <span>{{ $t('Audio file') }}</span>
                <input ref="adhanFile" type="file" class="ps-drop__input" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac" @change="pickAdhanFile" />
                <div v-if="!newAdhan.file" class="ps-drop" :class="{ over: dragging }" role="button" tabindex="0" @click="$refs.adhanFile.click()" @keydown.enter.prevent="$refs.adhanFile.click()" @keydown.space.prevent="$refs.adhanFile.click()" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dropAdhan">
                  <span class="ps-drop__ico"><icons name="microphone" class="w-5 h-5" /></span>
                  <span class="ps-drop__t"><b>{{ $t('Choose a file') }}</b> {{ $t('or drop it here') }}<small>{{ $t('MP3, WAV, OGG, M4A or AAC, up to 12 MB') }}</small></span>
                </div>
                <div v-else class="ps-filechip">
                  <span class="ps-drop__ico ps-drop__ico--ok"><icons name="tick" class="w-5 h-5" /></span>
                  <span class="ps-filechip__t"><b :title="newAdhan.file.name">{{ newAdhan.file.name }}</b><small>{{ fileSize(newAdhan.file.size) }}</small></span>
                  <audio v-if="newAdhan.preview" :src="newAdhan.preview" controls preload="metadata" class="ps-filechip__audio"></audio>
                  <button type="button" class="ps-filechip__x" :aria-label="$t('Remove')" @click="clearAdhanFile">×</button>
                </div>
                <small v-if="newAdhan.errors.file" class="ps-err">{{ newAdhan.errors.file }}</small>
              </div>
              <button type="submit" class="ps-btn ps-btn--go" :disabled="newAdhan.busy"><icons name="plus" class="w-4 h-4" />{{ newAdhan.busy ? $t('Uploading...') : $t('Add adhan') }}</button>
            </div>
          </form>
          <label class="ps-field ps-vol"><span>{{ $t('Volume') }} <b>{{ form.adhan_volume }}%</b></span><input v-model.number="form.adhan_volume" type="range" min="0" max="100" class="ps-range" @input="applyVolume" /></label>
        </section>

        <!-- place -->
        <section class="lg-card ps-card">
          <header class="ps-head"><span class="ps-ico ps-ico--sky"><icons name="map-pin" class="w-5 h-5" /></span><div><h2>{{ $t('Place and calculation') }}</h2><p>{{ $t('Times are calculated from the sun for this place, no outside service. The default place is Zanzibar.') }}</p></div></header>
          <div class="ps-grid">
            <div class="ps-field"><span>{{ $t('City') }}</span><auto-complete id="ps_city" v-model="form.city" :suggestions="cities" freeform :placeholder="$t('Search a city, or type your own')" /></div>
            <label class="ps-field"><span>{{ $t('Latitude') }}</span><input v-model.number="form.lat" type="number" step="0.0001" class="ps-input" /></label>
            <label class="ps-field"><span>{{ $t('Longitude') }}</span><input v-model.number="form.lng" type="number" step="0.0001" class="ps-input" /></label>
            <div class="ps-field"><span>{{ $t('Time zone') }}</span><auto-complete id="ps_tz" v-model="form.timezone" :suggestions="timezones" :placeholder="$t('Search a time zone')" /></div>
            <div class="ps-field ps-wide"><span>{{ $t('Calculation method') }}</span><auto-complete id="ps_method" v-model="form.method" :suggestions="methods" :placeholder="$t('Search a calculation method')" /></div>
            <div class="ps-field"><span>{{ $t('Asr') }}</span><auto-complete id="ps_asr" v-model="form.asr" :suggestions="asrOptions" /></div>
          </div>
        </section>

        <section class="lg-card ps-card">
          <header class="ps-head"><span class="ps-ico ps-ico--rose"><icons name="eye" class="w-5 h-5" /></span><div><h2>{{ $t('Where it shows') }}</h2></div></header>
          <label class="ps-switch"><input v-model="form.show_in_topbar" type="checkbox" /><i></i><span>{{ $t('Next prayer marquee in the top bar') }}</span></label>
          <label class="ps-switch"><input v-model="form.show_on_dashboard" type="checkbox" /><i></i><span>{{ $t('Prayer Times card and next prayer on the dashboard') }}</span></label>
        </section>

        <div class="ps-save"><span v-if="$page.props.flash && $page.props.flash.message" class="ps-saved">{{ $page.props.flash.message }}</span><button type="submit" class="ps-btn ps-btn--go" :disabled="busy || hasTimeErrors"><icons name="tick" class="w-4 h-4" />{{ $t('Save settings') }}</button></div>
      </form>

      <!-- messages -->
      <section class="lg-card ps-card">
        <header class="ps-head"><span class="ps-ico ps-ico--teal"><icons name="list" class="w-5 h-5" /></span><div><h2>{{ $t('Verses and sayings') }}</h2><p>{{ $t('One is shown with each prayer notice, a different one each time. Switch off any you do not want, or add your own. Please have an Imam review the wording.') }}</p></div></header>
        <div class="ps-msgs">
          <div v-for="m in messages" :key="m.id" class="ps-msg" :class="{ off: !m.is_active }">
            <label class="ps-switch"><input type="checkbox" :checked="m.is_active" @change="toggleMsg(m, $event.target.checked)" /><i></i></label>
            <div class="ps-msg__b"><p v-if="m.arabic" class="ps-msg__ar" dir="rtl" lang="ar">{{ m.arabic }}</p><p>{{ m.text_en }}</p><small><b>{{ m.kind === 'ayah' ? $t('Qur\'an') : $t('Hadith') }}</b> · {{ m.reference }} · {{ m.prayer === 'any' ? $t('any prayer') : m.prayer }}</small></div>
            <button v-if="m.is_custom" type="button" class="ps-x" :aria-label="$t('Delete')" @click="delMsg(m)"><icons name="trash" class="w-4 h-4" /></button>
          </div>
        </div>
        <form class="ps-newmsg" @submit.prevent="addMsg">
          <h3 class="ps-sub">{{ $t('Add your own') }}</h3>
          <div class="ps-grid">
            <div class="ps-field"><span>{{ $t('Type') }}</span><auto-complete id="ps_kind" v-model="nm.kind" :suggestions="kindOptions" /></div>
            <div class="ps-field"><span>{{ $t('For prayer') }}</span><auto-complete id="ps_prayer" v-model="nm.prayer" :suggestions="prayerOptions" /></div>
            <label class="ps-field"><span>{{ $t('Reference') }}</span><input v-model="nm.reference" class="ps-input" maxlength="190" :placeholder="$t('e.g. Surah Al-Baqarah 2:153')" /></label>
          </div>
          <label class="ps-field"><span>{{ $t('Arabic text (optional)') }}</span><textarea v-model="nm.arabic" rows="2" dir="rtl" class="ps-input"></textarea></label>
          <label class="ps-field"><span>{{ $t('English text') }}</span><textarea v-model="nm.text_en" rows="2" class="ps-input" required></textarea></label>
          <label class="ps-field"><span>{{ $t('Kiswahili text (optional)') }}</span><textarea v-model="nm.text_sw" rows="2" class="ps-input"></textarea></label>
          <button type="submit" class="ps-btn ps-btn--go" :disabled="!nm.text_en"><icons name="plus" class="w-4 h-4" />{{ $t('Add message') }}</button>
        </form>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { router } from '@inertiajs/vue3';
import { markRaw } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import { prayerState } from '@/Shared/Prayer/prayerStore';

export default {
  components: { AdminLayout, PageHeader, AutoComplete },
  props: { config: Object, methods: Array, adhans: Array, today: Array, phase: Object, users: Array, messages: Array, timezones: Array, cities: Array, serverNow: String },
  data() {
    return {
      newAdhan: { name: '', file: null, preview: '', busy: false, errors: {} }, dragging: false,
      form: JSON.parse(JSON.stringify(this.config)), busy: false, pickInclude: '', pickExclude: '', sample: '', audio: null,
      nm: { kind: 'ayah', prayer: 'any', arabic: '', text_en: '', text_sw: '', reference: '' },
      prayerKeys: [{ key: 'fajr', label: 'Fajr' }, { key: 'dhuhr', label: 'Dhuhr' }, { key: 'asr', label: 'Asr' }, { key: 'maghrib', label: 'Maghrib' }, { key: 'isha', label: 'Isha' }],
    };
  },
  computed: {
    // a real prayer of today used to explain the three numbers with actual clock times
    // minutes from the prayer time at which the notice starts (never before the alert, never after the lock)
    noticeAt() { const f = this.form; return Math.min(f.lock_start_after_min, Math.max(-f.alert_before_min, f.lock_start_after_min - f.notice_before_min)); },
    // checked as the person types; the same rules are enforced again on the server
    timeErrors() {
      const f = this.form, whole = v => typeof v === 'number' && Number.isInteger(v);
      const e = {};
      if (!whole(f.alert_before_min) || f.alert_before_min < 1 || f.alert_before_min > 120) e.alert = this.$t('Enter a whole number of minutes from 1 to 120.');
      if (!whole(f.notice_before_min) || f.notice_before_min < 0) e.notice = this.$t('Enter a whole number of minutes, 0 or more.');
      else if (!e.alert && f.notice_before_min > f.alert_before_min) e.notice = this.$t('This cannot be bigger than the shutdown alert time:') + ' ' + f.alert_before_min + ' ' + this.$t('minutes') + '.';
      if (f.shutdown && !whole(f.lock_minutes) || f.lock_minutes < 1 || f.lock_minutes > 180) e.lock = this.$t('Enter a whole number of minutes from 1 to 180.');
      return e;
    },
    hasTimeErrors() { return Object.keys(this.timeErrors).length > 0; },
    example() {
      const row = (this.today || []).find(t => t.key === 'dhuhr') || (this.today || []).find(t => t.key !== 'sunrise') || { label: 'Dhuhr', time: '12:10' };
      // the time may arrive as "12:10 PM" or "12:10"; work in minutes and show the same 12-hour style
      const mt = String(row.time || '12:10').match(/(\d{1,2}):(\d{2})\s*([AP]M)?/i) || [null, '12', '10'];
      let h = Number(mt[1]); const m = Number(mt[2]);
      if (mt[3]) { const pm = mt[3].toUpperCase() === 'PM'; h = (h % 12) + (pm ? 12 : 0); }
      const clock = d => { const t = (((h * 60 + m + d) % 1440) + 1440) % 1440; const hh = Math.floor(t / 60); return ((hh % 12) || 12) + ':' + String(t % 60).padStart(2, '0') + ' ' + (hh < 12 ? 'AM' : 'PM'); };
      return { label: row.label, time: row.time, t: clock };
    },
    asrOptions() { return [{ value: 1, label: this.$t('Standard (Shafi\'i, Maliki, Hanbali)') }, { value: 2, label: this.$t('Hanafi') }]; },
    kindOptions() { return [{ value: 'ayah', label: this.$t('Qur\'an verse') }, { value: 'hadith', label: this.$t('Hadith') }]; },
    prayerOptions() { return [{ value: 'any', label: this.$t('Any prayer') }, ...this.prayerKeys.map(p => ({ value: p.key, label: p.label }))]; },
    userOptions() { return this.users.map(u => ({ id: u.id, label: u.name + (u.username ? ' (@' + u.username + ')' : '') })); },
  },
  watch: {
    // picking a known city fills in its coordinates and time zone (typed values stay as they are, and can still be edited by hand)
    'form.city'(name) {
      const c = (this.cities || []).find(x => x.value === name);
      if (c) { this.form.lat = c.lat; this.form.lng = c.lng; this.form.timezone = c.tz; }
    },
    pickInclude(v) { if (v) { this.add('user_ids', v); this.$nextTick(() => (this.pickInclude = '')); } },
    pickExclude(v) { if (v) { this.add('excluded_user_ids', v); this.$nextTick(() => (this.pickExclude = '')); } },
  },
  beforeUnmount() { if (this.newAdhan.preview) URL.revokeObjectURL(this.newAdhan.preview); this.stopSample(); prayerState.preview = null; },
  methods: {
    pc(n) { const total = this.form.alert_before_min + this.form.lock_start_after_min + this.form.lock_minutes || 1; return Math.max(4, Math.round((Number(n) / total) * 100)); },
    chips(ids) { return ids.map(id => this.users.find(u => u.id === id)).filter(Boolean); },
    add(list, id) { if (!this.form[list].includes(id)) this.form[list].push(id); },
    remove(list, id) { this.form[list] = this.form[list].filter(x => x !== id); },
    addAllUsers() { this.form.user_ids = this.users.map(u => u.id); },
    save() {
      if (this.hasTimeErrors) return;
      this.busy = true;
      router.put(this.route('prayer.settings.update'), this.form, { preserveScroll: true, onFinish: () => (this.busy = false) });
    },
    // shows the real alert / prayer notice (with the adhan) to the admin, without locking anybody
    preview(kind) {
      const at = Date.now() + (kind === 'alert' ? this.form.alert_before_min : this.form.notice_before_min) * 60000;
      const e = {
        key: 'dhuhr', label: 'Dhuhr', at: new Date(at).toISOString(), alert_at: new Date(at - this.form.alert_before_min * 60000).toISOString(), notice_at: new Date(at - this.form.notice_before_min * 60000).toISOString(),
        lock_at: new Date(at + this.form.lock_start_after_min * 60000).toISOString(), unlock_at: new Date(at + (this.form.lock_start_after_min + this.form.lock_minutes) * 60000).toISOString(), time: '12:10',
        message: this.messages.filter(m => m.is_active)[0] ? { kind: this.messages.filter(m => m.is_active)[0].kind, arabic: this.messages.filter(m => m.is_active)[0].arabic, text: this.messages.filter(m => m.is_active)[0].text_en, reference: this.messages.filter(m => m.is_active)[0].reference } : null,
      };
      Object.assign(e, { t: at, alertT: new Date(e.alert_at).getTime(), noticeT: new Date(e.notice_at).getTime(), lockT: new Date(e.lock_at).getTime(), unlockT: new Date(e.unlock_at).getTime() });
      if (prayerState.data) prayerState.data.shutdown = !!this.form.shutdown;
      if (!prayerState.data) prayerState.data = { shutdown: !!this.form.shutdown, in_scope: true, adhan: { enabled: this.form.adhan_enabled, url: this.adhans.find(a => a.value === this.form.adhan)?.url, volume: this.form.adhan_volume }, city: this.form.city, upcoming: [], times: [] };
      prayerState.preview = { phase: kind, e };
    },
    fileSize(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; },
    pickAdhanFile(e) { this.setAdhanFile(e.target.files && e.target.files[0]); },
    dropAdhan(e) { this.dragging = false; this.setAdhanFile(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]); },
    clearAdhanFile() { this.setAdhanFile(null); if (this.$refs.adhanFile) this.$refs.adhanFile.value = ''; },
    setAdhanFile(f) {
      if (this.newAdhan.preview) URL.revokeObjectURL(this.newAdhan.preview);
      this.newAdhan.errors = {}; this.newAdhan.file = null; this.newAdhan.preview = '';
      if (!f) return;
      if (f.size > 12 * 1024 * 1024) { this.newAdhan.errors = { file: this.$t('The audio file is too big. The most allowed is 12 MB.') }; return; }
      if (!/\.(mp3|wav|ogg|oga|m4a|aac|mpga|webm)$/i.test(f.name) && !String(f.type).startsWith('audio/')) { this.newAdhan.errors = { file: this.$t('Choose an audio file (MP3, WAV, OGG, M4A or AAC).') }; return; }
      this.newAdhan.file = f;
      this.newAdhan.preview = URL.createObjectURL(f);
      if (!this.newAdhan.name.trim()) this.newAdhan.name = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').slice(0, 80);
    },
    addAdhan() {
      const e = {};
      if (!this.newAdhan.name.trim()) e.name = this.$t('Enter a name.');
      if (!this.newAdhan.file) e.file = this.$t('Choose an audio file.');
      this.newAdhan.errors = { ...this.newAdhan.errors, ...e };
      if (Object.keys(this.newAdhan.errors).length) return;
      this.newAdhan.busy = true;
      router.post(this.route('prayer.adhans.store'), { name: this.newAdhan.name.trim(), file: this.newAdhan.file }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { this.newAdhan.name = ''; this.clearAdhanFile(); },
        onError: errs => { this.newAdhan.errors = errs; },
        onFinish: () => (this.newAdhan.busy = false),
      });
    },
    async delAdhan(a) {
      if (!(await this.$confirm(this.$t('Delete this adhan?') + ' ' + a.label))) return;
      if (this.sample === a.value) this.stopSample();
      const wasChosen = this.form.adhan === a.value;
      router.delete(this.route('prayer.adhans.destroy', a.id), { preserveScroll: true, onSuccess: () => { if (wasChosen) this.form.adhan = 'sabah'; } });
    },
    toggleSample(a) {
      if (this.sample === a.value) return this.stopSample();
      this.stopSample();
      this.audio = markRaw(new Audio(a.url));
      this.audio.volume = this.form.adhan_volume / 100;
      this.audio.addEventListener('ended', () => (this.sample = ''));
      this.audio.play().then(() => (this.sample = a.value)).catch(() => (this.sample = ''));
    },
    stopSample() { if (this.audio) this.audio.pause(); this.audio = null; this.sample = ''; },
    applyVolume() { if (this.audio) this.audio.volume = this.form.adhan_volume / 100; },
    toggleMsg(m, on) { router.put(this.route('prayer.messages.update', m.id), { only_active: 1, is_active: on ? 1 : 0 }, { preserveScroll: true }); },
    async delMsg(m) { if (await this.$confirm(this.$t('Delete this message?'))) router.delete(this.route('prayer.messages.destroy', m.id), { preserveScroll: true }); },
    addMsg() { router.post(this.route('prayer.messages.store'), { ...this.nm, is_active: 1 }, { preserveScroll: true, onSuccess: () => (this.nm = { kind: 'ayah', prayer: 'any', arabic: '', text_en: '', text_sw: '', reference: '' }) }); },
  },
};
</script>

<style>
.ps-page { display: grid; gap: 1rem; }
.ps-card, .ps-hero { padding: 1.2rem 1.3rem; }
.ps-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; margin-right: 4px; }
.ps-hero { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 1.2rem; align-items: center; background: linear-gradient(135deg, #ecfdf5, #fffbeb 55%, #fff1e0) !important; color: #14532d; box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.35) !important; }
.ps-hero.on { animation: ps-glow 3.4s ease-in-out infinite; }
@keyframes ps-glow { 0%, 100% { box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.35), 0 0 0 rgba(251, 146, 60, 0); } 50% { box-shadow: inset 0 0 0 1px rgba(249, 115, 22, 0.6), 0 0 24px rgba(251, 146, 60, 0.25); } }
.ps-today { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 0.5rem; margin: 0.9rem 0 0.4rem; }
.ps-today__i { padding: 0.5rem 0.4rem; border-radius: 12px; text-align: center; background: rgba(255, 255, 255, 0.78); box-shadow: inset 0 0 0 1px rgba(22, 101, 52, 0.15), 0 6px 12px -6px rgba(22, 101, 52, 0.4), 0 1px 2px rgba(22, 101, 52, 0.12); } .ps-today__i small { display: block; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #c2410c; } .ps-today__i b { font-size: 0.95rem; font-variant-numeric: tabular-nums; } .ps-today__i.sun { opacity: 0.65; }
.ps-err { display: block; margin-top: 0.3rem; font-size: 0.74rem; font-weight: 700; color: #be123c; } .ps-field--bad .ps-input { border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.15); } .ps-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ps-grid--times .ps-field > span { min-height: 2.5em; display: flex; align-items: flex-end; } .ps-grid--times .ps-field > .ps-hint, .ps-grid--times .ps-field > .ps-err { margin-top: 0; }
.ps-hint { margin: 0; font-size: 0.74rem; color: #5b7a66; }
.ps-hero__try { display: grid; gap: 0.5rem; justify-items: stretch; min-width: 190px; } .ps-hero__try b { font-size: 0.7rem; letter-spacing: 0.14em; text-transform: uppercase; color: #c2410c; }
.ps-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.9rem; } .ps-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); } .ps-head p { margin: 0.1rem 0 0; font-size: 0.8rem; color: var(--lg-mute); }
.ps-ico { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: 14px; color: #fff; box-shadow: 0 10px 18px -10px rgba(15, 23, 42, 0.7); } .ps-ico--amber { background: linear-gradient(145deg, #f59e0b, #b45309); } .ps-ico--emerald { background: linear-gradient(145deg, #10b981, #047857); } .ps-ico--violet { background: linear-gradient(145deg, #8b5cf6, #5b21b6); } .ps-ico--sky { background: linear-gradient(145deg, #38bdf8, #0369a1); } .ps-ico--rose { background: linear-gradient(145deg, #fb7185, #be123c); } .ps-ico--teal { background: linear-gradient(145deg, #2dd4bf, #0f766e); }
.ps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.8rem; margin-bottom: 0.8rem; align-items: start; } .ps-wide { grid-column: span 2; }
.ps-field { display: grid; align-content: start; gap: 0.3rem; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); } .ps-field > span b { color: var(--lg-ink); }
.ps-input { width: 100%; min-height: 42px; padding: 0.5rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.9rem; color: var(--lg-ink); background: var(--lg-card, #fff); border: 1px solid var(--lg-line); box-shadow: 0 3px 8px -6px rgba(15, 23, 42, 0.4); transition: border-color 0.2s, box-shadow 0.2s; } .ps-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.ps-switch { position: relative; display: inline-flex; align-items: center; gap: 0.7rem; margin: 0.25rem 0.9rem 0.25rem 0; font-weight: 700; font-size: 0.88rem; cursor: pointer; color: inherit; } .ps-switch input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.ps-switch i { position: relative; flex: none; width: 42px; height: 24px; border-radius: 99px; background: #cbd5e1; transition: background 0.25s; } .ps-switch i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3); transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.ps-switch input:checked + i { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 0 12px rgba(16, 185, 129, 0.55); } .ps-switch input:checked + i::after { transform: translateX(18px); } .ps-switch input:focus-visible + i { outline: 3px solid rgba(99, 102, 241, 0.5); }
.ps-switch small { display: block; font-weight: 600; font-size: 0.72rem; opacity: 0.7; } .ps-switch--big i { width: 54px; height: 30px; } .ps-switch--big i::after { width: 24px; height: 24px; } .ps-switch--big input:checked + i::after { transform: translateX(24px); } .ps-switch--big { font-size: 1.05rem; }
.ps-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem; padding: 0.6rem 1.1rem; border-radius: 12px; font-weight: 800; font-size: 0.84rem; border: 0; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.2s ease; } .ps-btn:hover:not(:disabled) { transform: translateY(-1px); } .ps-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ps-btn--soft { color: var(--lg-ink); background: var(--lg-soft, #eef2ff); box-shadow: inset 0 0 0 1px var(--lg-line); } .ps-hero .ps-btn--soft { color: #166534; background: rgba(255, 255, 255, 0.8); box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.35); }
html.dark .ps-hero { background: linear-gradient(135deg, #0b3b34, #0a2a40 60%, #07142b) !important; color: #ecfdf5; box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.3) !important; } html.dark .ps-today__i { background: rgba(255, 255, 255, 0.07); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); } html.dark .ps-today__i small, html.dark .ps-hero__try b { color: #fcd34d; } html.dark .ps-hint { color: #94a3b8; } html.dark .ps-hero .ps-btn--soft { color: #ecfdf5; background: rgba(255, 255, 255, 0.1); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.25); } .ps-btn--sm { padding: 0.35rem 0.8rem; font-size: 0.76rem; }
.ps-btn--go { color: #fff; background: linear-gradient(135deg, #059669, #10b981); box-shadow: 0 12px 22px -12px rgba(5, 150, 105, 0.9); }
.ps-save { position: sticky; bottom: 12px; display: flex; align-items: center; justify-content: flex-end; gap: 1rem; padding: 0.7rem 1rem; border-radius: 16px; background: var(--lg-card, #fff); box-shadow: 0 14px 30px -14px rgba(15, 23, 42, 0.5), 0 0 0 1px var(--lg-line); z-index: 5; } .ps-saved { font-size: 0.82rem; font-weight: 700; color: #059669; }
.ps-hint { display: block; margin-top: 0.3rem; font-size: 0.72rem; font-weight: 600; color: var(--lg-mute); }
.ps-steps { margin-top: 0.8rem; padding: 0.9rem 1rem; border-radius: 16px; background: rgba(240, 253, 244, 0.7); box-shadow: inset 0 0 0 1px rgba(22, 101, 52, 0.14); } .ps-steps__h { margin: 0 0 0.6rem; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-mute); }
.ps-steps ol { display: grid; gap: 0.55rem; margin: 0; padding: 0; list-style: none; } .ps-steps li { display: flex; align-items: center; gap: 0.8rem; } .ps-steps time { flex: none; min-width: 5.8rem; white-space: nowrap; padding: 0.3rem 0.5rem; border-radius: 10px; text-align: center; font-weight: 800; font-size: 0.95rem; font-variant-numeric: tabular-nums; color: #fff; }
.ps-steps .a time { background: #f59e0b; } .ps-steps .p time { background: #10b981; } .ps-steps .l time { background: linear-gradient(135deg, #ef4444, #be123c); } .ps-steps .u time { background: #3b82f6; }
.ps-steps li b { display: block; font-size: 0.88rem; color: var(--lg-ink); } .ps-steps li span { font-size: 0.78rem; color: var(--lg-mute); } html.dark .ps-steps { background: rgba(255, 255, 255, 0.05); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); }
.ps-timeline { margin-top: 0.4rem; } .ps-timeline__bar { display: flex; height: 12px; border-radius: 99px; overflow: hidden; background: var(--lg-line); } .ps-timeline__bar i { display: block; height: 100%; transition: width 0.3s; } .ps-timeline__bar .a { background: #f59e0b; } .ps-timeline__bar .p { background: #10b981; } .ps-timeline__bar .l { background: linear-gradient(90deg, #ef4444, #be123c); }
.ps-timeline ol { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.5rem; margin: 0.6rem 0 0; padding: 0; list-style: none; font-size: 0.74rem; color: var(--lg-mute); } .ps-timeline li b { display: block; font-size: 0.82rem; color: var(--lg-ink); } .ps-timeline .a b { color: #d97706; } .ps-timeline .p b { color: #059669; } .ps-timeline .l b { color: #be123c; }
.ps-people h3, .ps-sub { margin: 0.9rem 0 0.4rem; font-size: 0.82rem; font-weight: 800; color: var(--lg-ink); } .ps-people h3 small { padding: 1px 7px; border-radius: 99px; font-size: 0.7rem; background: var(--lg-soft); }
.ps-add { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0.6rem; align-items: start; } .ps-add .mb-2 { margin-bottom: 0; }
.ps-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; max-height: 220px; overflow: auto; } .ps-chips em { font-size: 0.78rem; color: var(--lg-faint); }
.ps-chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 3px 4px 3px 10px; border-radius: 99px; font-size: 0.78rem; font-weight: 700; color: #065f46; background: #d1fae5; } .ps-chip--out { color: #9a3412; background: #ffedd5; } .ps-chip button { display: grid; place-items: center; width: 20px; height: 20px; border: 0; border-radius: 50%; background: rgba(0, 0, 0, 0.12); color: inherit; cursor: pointer; line-height: 1; } .ps-chip button:hover { background: rgba(0, 0, 0, 0.25); }
.ps-adhan__row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; } .ps-adhan__lock { font-style: normal; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.ps-btn--danger { color: #be123c; background: rgba(244, 63, 94, 0.1); box-shadow: inset 0 0 0 1px rgba(244, 63, 94, 0.3); } .ps-btn--danger:hover { background: rgba(244, 63, 94, 0.18); }
.ps-addadhan { margin-top: 0.8rem; padding: 0.9rem 1rem; border-radius: 16px; background: rgba(255, 247, 237, 0.7); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.2); } .ps-addadhan h3 { margin: 0; font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); } .ps-addadhan p { margin: 0.2rem 0 0.7rem; font-size: 0.78rem; color: var(--lg-mute); }
.ps-drop__input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.ps-drop { display: flex; align-items: center; gap: 0.8rem; min-height: 56px; padding: 0.55rem 0.9rem; border-radius: 14px; cursor: pointer; border: 1.5px dashed rgba(234, 88, 12, 0.45); background: rgba(255, 255, 255, 0.7); transition: all 0.2s; } .ps-drop:hover, .ps-drop:focus-visible, .ps-drop.over { border-color: #ea580c; background: rgba(255, 237, 213, 0.8); box-shadow: 0 0 0 3px rgba(251, 146, 60, 0.2); outline: none; }
.ps-drop__ico { display: grid; place-items: center; flex: none; width: 36px; height: 36px; border-radius: 12px; color: #fff; background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 6px 14px -6px rgba(234, 88, 12, 0.8); } .ps-drop__ico--ok { background: linear-gradient(135deg, #34d399, #059669); box-shadow: 0 6px 14px -6px rgba(5, 150, 105, 0.8); }
.ps-drop__t { display: grid; font-size: 0.82rem; font-weight: 600; color: var(--lg-mute); } .ps-drop__t b { display: inline; color: #c2410c; } .ps-drop__t small { margin-top: 1px; font-size: 0.7rem; font-weight: 600; opacity: 0.8; }
.ps-filechip { display: flex; align-items: center; gap: 0.7rem; min-height: 56px; padding: 0.45rem 0.6rem 0.45rem 0.7rem; border-radius: 14px; border: 1.5px solid rgba(16, 185, 129, 0.45); background: rgba(236, 253, 245, 0.8); } .ps-filechip__t { display: grid; min-width: 0; flex: 1; } .ps-filechip__t b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.82rem; color: var(--lg-ink); } .ps-filechip__t small { font-size: 0.7rem; font-weight: 600; color: var(--lg-mute); }
.ps-filechip__audio { flex: none; width: 190px; height: 34px; } .ps-filechip__x { display: grid; place-items: center; flex: none; width: 28px; height: 28px; border: 0; border-radius: 50%; cursor: pointer; font-size: 1.1rem; line-height: 1; color: #be123c; background: rgba(244, 63, 94, 0.12); } .ps-filechip__x:hover { background: rgba(244, 63, 94, 0.22); }
html.dark .ps-drop { background: rgba(255, 255, 255, 0.05); } html.dark .ps-drop:hover, html.dark .ps-drop.over { background: rgba(251, 146, 60, 0.12); } html.dark .ps-filechip { background: rgba(16, 185, 129, 0.1); }
@media (max-width: 760px) { .ps-addadhan__row { grid-template-columns: 1fr; } .ps-filechip { flex-wrap: wrap; } .ps-filechip__audio { width: 100%; order: 3; } }
.ps-addadhan__row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 0.8rem; align-items: end; } .ps-file { padding: 0.55rem 0.7rem; } html.dark .ps-addadhan { background: rgba(255, 255, 255, 0.05); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); }
.ps-adhans { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.8rem; margin: 0.6rem 0; } .ps-adhans.off { opacity: 0.45; pointer-events: none; }
.ps-adhan { position: relative; display: grid; gap: 0.35rem; padding: 0.9rem 1rem; border-radius: 16px; cursor: pointer; border: 1.5px solid var(--lg-line); transition: all 0.2s; } .ps-adhan input { position: absolute; opacity: 0; } .ps-adhan b { font-size: 0.95rem; } .ps-adhan small { font-size: 0.74rem; color: var(--lg-mute); }
.ps-adhan.on { border-color: #10b981; background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(250, 204, 21, 0.1)); box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
.ps-range { width: 100%; accent-color: #10b981; } .ps-vol { max-width: 420px; }
.ps-adj { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.7rem; } .ps-adj__i { padding: 0.7rem 0.8rem; border-radius: 14px; background: var(--lg-soft, #f1f5f9); } .ps-adj__i.off { opacity: 0.5; }
.ps-step { display: flex; align-items: center; gap: 0.35rem; margin-top: 0.4rem; } .ps-step .ps-input { width: 64px; min-height: 34px; text-align: center; padding: 0.2rem; } .ps-step button { width: 30px; height: 30px; border-radius: 9px; border: 0; font-size: 1.1rem; font-weight: 800; cursor: pointer; background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1px var(--lg-line); color: var(--lg-ink); } .ps-step small { color: var(--lg-faint); }
.ps-msgs { position: relative; display: grid; gap: 0.5rem; max-height: 480px; overflow: auto; padding-right: 4px; } .ps-msg { display: flex; align-items: flex-start; gap: 0.7rem; padding: 0.7rem 0.8rem; border-radius: 14px; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line); } .ps-msg.off { opacity: 0.5; } .ps-msg__b { flex: 1; min-width: 0; } .ps-msg__b p { margin: 0 0 0.2rem; font-size: 0.84rem; line-height: 1.5; } .ps-msg__ar { font-size: 1.15rem !important; line-height: 2 !important; font-family: 'Amiri', 'Noto Naskh Arabic', serif; } .ps-msg__b small { color: var(--lg-faint); font-size: 0.72rem; }
.ps-x { display: grid; place-items: center; width: 30px; height: 30px; border: 0; border-radius: 9px; cursor: pointer; color: #be123c; background: rgba(244, 63, 94, 0.1); }
.ps-newmsg { margin-top: 1rem; display: grid; gap: 0.7rem; }
@media (max-width: 760px) { .ps-hero { grid-template-columns: 1fr; } .ps-today { grid-template-columns: repeat(3, minmax(0, 1fr)); } .ps-timeline ol { grid-template-columns: repeat(2, 1fr); } .ps-wide { grid-column: auto; } .ps-add { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .ps-hero.on { animation: none; } }

.ps-mode { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; margin-bottom: 0.9rem; } @media (max-width: 640px) { .ps-mode { grid-template-columns: 1fr; } }
.ps-mode__opt { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.8rem 0.9rem; border-radius: 14px; cursor: pointer; background: rgba(255, 255, 255, 0.6); box-shadow: inset 0 0 0 1px var(--lg-line); transition: box-shadow 0.15s, background 0.15s; }
.ps-mode__opt input { position: absolute; opacity: 0; pointer-events: none; } .ps-mode__opt b { display: block; font-size: 0.92rem; color: var(--lg-ink); } .ps-mode__opt small { display: block; margin-top: 0.15rem; font-size: 0.76rem; line-height: 1.4; color: var(--lg-mute); }
.ps-mode__dot { flex: none; width: 18px; height: 18px; margin-top: 2px; border-radius: 50%; box-shadow: inset 0 0 0 2px var(--lg-line); } .ps-mode__opt.on { background: rgba(22, 101, 52, 0.09); box-shadow: inset 0 0 0 2px #166534; } .ps-mode__opt.on .ps-mode__dot { box-shadow: inset 0 0 0 5px #166534; }
.ps-mode__opt:focus-within { outline: 2px solid #166534; outline-offset: 2px; } html.dark .ps-mode__opt { background: rgba(255, 255, 255, 0.05); } html.dark .ps-mode__opt.on { background: rgba(74, 222, 128, 0.12); box-shadow: inset 0 0 0 2px #4ade80; } html.dark .ps-mode__opt.on .ps-mode__dot { box-shadow: inset 0 0 0 5px #4ade80; }
</style>
