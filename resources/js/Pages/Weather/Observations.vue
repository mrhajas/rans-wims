<template>
  <admin-layout :title="$t('Weather Observations')">
    <div class="lg-page sb wx sc-ui px-4 md:px-0">
      <wx-header current="observations" :title="$t('Weather Observations')" :subtitle="$t('Weather seen on site by site staff. These are observations by people, not forecasts or provider data, and they appear on the Site Diary of the same day.')">
        <wx-export :project="f.project" :from="f.from" :to="f.to" />
      </wx-header>

      <div class="wx-split">
        <!-- record / correct -->
        <section v-if="can.record" id="wx-record" class="sb-card" style="--i: 1">
          <h3 class="sb-card__title"><span class="sb-ico g-green"><icons :name="editing ? 'edit' : 'plus'" class="w-4 h-4" /></span>{{ editing ? $t('Correct observation') : $t('Record weather seen on site') }}<span class="wx-chip is-observed">{{ $t('Observed') }}</span></h3>
          <form class="wx-form" @submit.prevent="save">
            <div class="sb-field wx-wide"><label>{{ $t('Project') }}</label><auto-complete v-model="form.warehouse_id" :suggestions="recordableProjects" :placeholder="$t('Search a project...')" :error="err('warehouse_id')" /></div>
            <div class="sb-field"><label>{{ $t('Date') }}</label><date-picker v-model="form.date" :max="today" :error="err('observed_at')" /></div>
            <div class="sb-field"><label for="wx-time">{{ $t('Time') }}</label><input id="wx-time" v-model="form.time" type="time" required /></div>
            <div class="sb-field wx-wide"><label>{{ $t('Conditions') }}</label><auto-complete v-model="form.conditions" :suggestions="options.conditions" :placeholder="$t('What does the sky look like?')" :error="err('conditions')" /></div>
            <div class="sb-field"><label>{{ $t('Rain') }}</label><auto-complete v-model="form.rainfall_level" :suggestions="options.rainfall" :placeholder="$t('Not recorded')" clearable /></div>
            <div class="sb-field"><label for="wx-mm">{{ $t('Rain measured (mm)') }}</label><input id="wx-mm" v-model="form.rainfall_mm" type="number" step="0.1" min="0" max="500" :placeholder="$t('Optional')" /><span v-if="err('rainfall_mm')" class="wx-error">{{ err('rainfall_mm') }}</span></div>
            <div class="sb-field"><label>{{ $t('Wind') }}</label><auto-complete v-model="form.wind_level" :suggestions="options.wind" :placeholder="$t('Not recorded')" clearable /></div>
            <div class="sb-field"><label for="wx-kmh">{{ $t('Wind measured (km/h)') }}</label><input id="wx-kmh" v-model="form.wind_kmh" type="number" step="1" min="0" max="250" :placeholder="$t('Optional')" /></div>
            <div class="sb-field"><label for="wx-temp">{{ $t('Temperature (°C)') }}</label><input id="wx-temp" v-model="form.temperature_c" type="number" step="0.5" min="-20" max="60" :placeholder="$t('Optional')" /></div>
            <div class="sb-field"><label>{{ $t('Related work package') }}</label><auto-complete v-model="form.work_package_id" :suggestions="projectPackages" :placeholder="$t('None')" clearable /></div>
            <div class="sb-field wx-wide"><label for="wx-notes">{{ $t('Notes') }}</label><textarea id="wx-notes" v-model="form.notes" class="wx-textarea" rows="3" maxlength="1000" :placeholder="$t('Anything the team should know, for example work stopped by rain at 10:30')"></textarea></div>
            <p v-if="formError" class="wx-error wx-wide" role="alert">{{ formError }}</p>
            <div class="wx-actions wx-wide">
              <button type="submit" class="sb-btn is-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ editing ? $t('Save correction') : $t('Record observation') }}</button>
              <button v-if="editing" type="button" class="sb-btn" @click="cancel">{{ $t('Cancel') }}</button>
            </div>
          </form>
        </section>

        <!-- history -->
        <section class="sb-card" style="--i: 2">
          <h3 class="sb-card__title"><span class="sb-ico g-navy"><icons name="list" class="w-4 h-4" /></span>{{ $t('Recorded observations') }}<small>{{ observations.meta.total }} {{ $t('in total') }}</small></h3>
          <div class="wx-form" style="margin-bottom: 0.8rem">
            <div class="sb-field"><label>{{ $t('Project') }}</label><auto-complete v-model="f.project" :suggestions="projectOptions" :placeholder="$t('All projects')" clearable /></div>
            <div class="sb-field"><label>{{ $t('From') }}</label><date-picker v-model="f.from" :max="f.to || undefined" clearable /></div>
            <div class="sb-field"><label>{{ $t('To') }}</label><date-picker v-model="f.to" :min="f.from || undefined" clearable /></div>
          </div>
          <ul v-if="observations.data.length" class="wx-list">
            <li v-for="o in observations.data" :key="o.id" class="wx-item">
              <div class="wx-item__top">
                <span class="wx-chip is-observed">{{ $t('Observed on site') }}</span>
                <b>{{ o.conditions_label }}</b>
                <span class="wx-source">{{ dt(o.observed_at) }}</span>
              </div>
              <p><b>{{ o.project }}</b></p>
              <div class="wx-meta">
                <span v-if="o.rainfall_label || o.rainfall_mm !== null">{{ $t('Rain') }}: {{ o.rainfall_label }}<template v-if="o.rainfall_mm !== null">{{ ' ' + o.rainfall_mm + ' mm' }}</template></span>
                <span v-if="o.wind_label || o.wind_kmh !== null">{{ $t('Wind') }}: {{ o.wind_label }}<template v-if="o.wind_kmh !== null">{{ ' ' + o.wind_kmh + ' km/h' }}</template></span>
                <span v-if="o.temperature_c !== null">{{ o.temperature_c }}&deg;C</span>
                <span v-if="o.work_package">{{ $t('Work package') }}: {{ o.work_package }}</span>
                <span>{{ $t('Recorded by') }}: {{ o.recorder }}</span>
                <span v-if="o.site_diary_id"><Link :href="route('site-diary.show', o.site_diary_id)">{{ $t('Site Diary of the day') }}</Link></span>
              </div>
              <p v-if="o.notes" class="wx-note">{{ o.notes }}</p>
              <div v-if="o.can_edit" class="wx-actions">
                <button type="button" class="sb-btn" @click="edit(o)"><icons name="edit" class="w-4 h-4" />{{ $t('Correct') }}</button>
                <button type="button" class="sb-btn" @click="remove(o)"><icons name="trash" class="w-4 h-4" />{{ $t('Remove') }}</button>
              </div>
            </li>
          </ul>
          <p v-else class="wx-state">{{ $t('No weather observations match.') }}</p>
          <div v-if="observations.meta.last_page > 1" class="wx-pager">
            <span>{{ $t('Page') }} {{ observations.meta.current_page }} / {{ observations.meta.last_page }}</span>
            <span class="wx-actions">
              <button type="button" class="sb-btn" :disabled="observations.meta.current_page <= 1" @click="go(observations.meta.current_page - 1)">{{ $t('Previous') }}</button>
              <button type="button" class="sb-btn" :disabled="observations.meta.current_page >= observations.meta.last_page" @click="go(observations.meta.current_page + 1)">{{ $t('Next') }}</button>
            </span>
          </div>
          <p class="wx-note" style="margin-top: 0.6rem">{{ $t('A person who recorded an observation can correct or remove it for :h hours. People who manage the weather settings can correct any.', { h: editHours }) }}</p>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import WxExport from './WxExport.vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import WxHeader from '@/Shared/Weather/WxHeader.vue';
import { dateTime } from '@/Shared/Weather/wxFormat';

const blank = (today, now, project) => ({ warehouse_id: project || '', date: today, time: now, conditions: '', rainfall_level: '', rainfall_mm: '', wind_level: '', wind_kmh: '', temperature_c: '', work_package_id: '', notes: '' });

export default {
  components: { WxExport, AdminLayout, Link, AutoComplete, DatePicker, WxHeader },
  props: { observations: Object, filters: Object, projectOptions: Array, recordableProjects: Array, workPackages: Array, options: Object, can: Object, editHours: Number, now: String },
  data() {
    const q = new URLSearchParams(window.location.search);
    const today = this.now.slice(0, 10);
    const proj = q.get('project') && this.recordableProjects.some(p => p.value === q.get('project')) ? q.get('project') : (this.recordableProjects.length === 1 ? this.recordableProjects[0].value : '');
    const form = blank(q.get('date') || today, this.now.slice(11, 16), proj);
    return { form, today, editing: null, busy: false, formError: '', errors: {}, f: { project: this.filters.project ? String(this.filters.project) : '', from: this.filters.from || '', to: this.filters.to || '' }, ready: false };
  },
  computed: {
    projectPackages() { return this.workPackages.filter(w => w.project === String(this.form.warehouse_id)); },
  },
  watch: {
    f: { deep: true, handler() { if (this.ready) this.go(1); } },
    'form.warehouse_id'() { if (!this.projectPackages.some(p => p.value === String(this.form.work_package_id))) this.form.work_package_id = ''; },
  },
  mounted() {
    this.$nextTick(() => { this.ready = true; });
    if (new URLSearchParams(window.location.search).get('record')) { const el = document.getElementById('wx-record'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  },
  methods: {
    dt(s) { return dateTime(s); },
    err(k) { return this.errors[k] ? this.errors[k][0] : null; },
    go(page) {
      const q = {};
      if (this.f.project) q.project = this.f.project;
      if (this.f.from) q.from = this.f.from;
      if (this.f.to) q.to = this.f.to;
      if (page > 1) q.page = page;
      router.get(route('weather.observations.index'), q, { preserveScroll: true, preserveState: true, only: ['observations', 'filters'] });
    },
    payload() {
      const f = this.form;
      const n = v => (v === '' || v === null ? null : v);
      return { warehouse_id: f.warehouse_id, observed_at: f.date + ' ' + f.time, conditions: f.conditions, rainfall_level: n(f.rainfall_level), rainfall_mm: n(f.rainfall_mm), wind_level: n(f.wind_level), wind_kmh: n(f.wind_kmh), temperature_c: n(f.temperature_c), work_package_id: n(f.work_package_id), notes: n(f.notes) };
    },
    save() {
      this.busy = true; this.formError = ''; this.errors = {};
      const req = this.editing ? axios.put(route('weather.observations.update', this.editing), this.payload()) : axios.post(route('weather.observations.store'), this.payload());
      req.then(() => {
        const keep = this.form.warehouse_id;
        this.editing = null;
        this.form = blank(this.today, this.now.slice(11, 16), keep);
        router.reload({ only: ['observations'], preserveScroll: true });
      }).catch(e => {
        const d = e.response && e.response.data;
        this.errors = (d && d.errors) || {};
        this.formError = (d && d.message) || this.$t('The observation could not be saved.');
      }).finally(() => { this.busy = false; });
    },
    edit(o) {
      this.editing = o.id;
      this.form = { warehouse_id: String(o.warehouse_id), date: o.observed_at.slice(0, 10), time: o.observed_at.slice(11, 16), conditions: o.conditions, rainfall_level: o.rainfall_level || '', rainfall_mm: o.rainfall_mm ?? '', wind_level: o.wind_level || '', wind_kmh: o.wind_kmh ?? '', temperature_c: o.temperature_c ?? '', work_package_id: o.work_package_id ? String(o.work_package_id) : '', notes: o.notes || '' };
      const el = document.getElementById('wx-record'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
    cancel() { this.editing = null; this.errors = {}; this.formError = ''; this.form = blank(this.today, this.now.slice(11, 16), this.form.warehouse_id); },
    async remove(o) {
      if (!(await this.$confirm(this.$t('Remove this weather observation? It stays in the activity log.'), { variant: 'danger', confirmText: this.$t('Remove') }))) return;
      router.delete(route('weather.observations.destroy', o.id), { preserveScroll: true });
    },
  },
};
</script>
