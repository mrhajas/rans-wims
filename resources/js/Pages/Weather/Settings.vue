<template>
  <admin-layout :title="$t('Weather Settings')">
    <div class="lg-page sb wx sc-ui px-4 md:px-0">
      <wx-header current="settings" :title="$t('Weather Settings')" :subtitle="$t('The weather provider and its key, how often data is refreshed, and the thresholds of weather-sensitive activities. Thresholds are starting values until a person has reviewed them.')" />

      <!-- provider and key -->
      <section v-if="can.settings" class="sb-card" style="--i: 1">
        <h3 class="sb-card__title"><span class="sb-ico g-sky"><icons name="cloud" class="w-4 h-4" /></span>{{ $t('Weather provider') }}
          <span class="wx-chip" :class="configuration.ok ? 'is-ok' : 'is-stale'">{{ configuration.ok ? $t('Ready') : $t('Not set up') }}</span></h3>
        <p v-if="!configuration.ok" class="wx-banner is-amber"><icons name="info" class="w-4 h-4" /><span class="wx-banner__body">{{ configuration.message }}</span></p>
        <div class="wx-form">
          <div class="sb-field"><label>{{ $t('Provider') }}</label><auto-complete v-model="form.provider" :suggestions="providerOptions" :placeholder="$t('Choose a provider...')" /></div>
          <div class="sb-field"><label for="wx-base">{{ $t('Address override (optional)') }}</label><input id="wx-base" v-model="form.base_url" type="text" :placeholder="selected && selected.default_base ? selected.default_base : 'https://'" /><span v-if="form.errors.base_url" class="wx-error">{{ form.errors.base_url }}</span></div>
        </div>
        <p v-if="selected && selected.note" class="wx-note" style="margin-top: 0.5rem">{{ selected.note }}</p>
        <p v-if="selected && selected.value !== 'none'" class="wx-note"><b>{{ $t('Severe-weather alerts') }}:</b> {{ selected.supports_alerts ? $t('supplied by this provider where national agencies publish them') : $t('not supported by this provider') }}.
          <template v-if="selected.commercial_base">&nbsp;{{ $t('With a key, the commercial address is used automatically:') }} {{ selected.commercial_base }}.</template></p>

        <h4 class="wx-h" style="margin-top: 1rem">{{ $t('Provider key') }}</h4>
        <div class="wx-key">
          <span class="wx-chip" :class="keyInfo.set ? 'is-ok' : 'is-stale'">{{ keyInfo.set ? $t('Key is set') : $t('No key') }}</span>
          <template v-if="keyInfo.set"><span>{{ $t('ends in') }} <code>{{ keyInfo.last4 }}</code></span><span class="wx-note">{{ keyInfo.source === 'environment' ? $t('(from the server environment, WEATHER_API_KEY)') : $t('(stored encrypted in WIMS)') }}</span></template>
          <span class="wx-actions">
            <button type="button" class="sb-btn" @click="keyMode = keyMode === 'replace' ? '' : 'replace'"><icons name="key" class="w-4 h-4" />{{ keyInfo.set ? $t('Replace key') : $t('Add key') }}</button>
            <button v-if="keyInfo.source === 'settings'" type="button" class="sb-btn" @click="keyMode = keyMode === 'remove' ? '' : 'remove'"><icons name="trash" class="w-4 h-4" />{{ $t('Remove key') }}</button>
          </span>
        </div>
        <p class="wx-note">{{ $t('The key is stored encrypted, is never shown again and is never written to the activity log. Changing it asks for your password.') }}</p>
        <div v-if="keyMode" class="wx-ack" style="margin-top: 0.6rem">
          <div v-if="keyMode === 'replace'" class="sb-field"><label for="wx-key">{{ $t('New key') }}</label><input id="wx-key" v-model="newKey" type="password" autocomplete="off" spellcheck="false" /></div>
          <div class="sb-field"><label for="wx-pw">{{ $t('Your password') }}</label><input id="wx-pw" v-model="password" type="password" autocomplete="current-password" /></div>
          <p v-if="keyError" class="wx-error" role="alert">{{ keyError }}</p>
          <div class="wx-actions">
            <button type="button" class="sb-btn is-primary" :disabled="busy || !password || (keyMode === 'replace' && !newKey)" @click="submitKey"><icons name="tick" class="w-4 h-4" />{{ keyMode === 'replace' ? $t('Save key') : $t('Remove the stored key') }}</button>
            <button type="button" class="sb-btn" @click="keyMode = ''; keyError = ''">{{ $t('Cancel') }}</button>
          </div>
        </div>

        <div class="wx-actions" style="margin-top: 0.8rem">
          <button type="button" class="sb-btn" :disabled="testing || !configuration.ok" @click="test"><icons name="refresh" class="w-4 h-4" />{{ testing ? $t('Testing...') : $t('Test connection') }}</button>
          <span v-if="testResult" class="wx-chip" :class="testResult.ok ? 'is-ok' : 'is-failed'">{{ testResult.ok ? $t('Worked') : $t('Failed') }}</span>
        </div>
        <p v-if="testResult" class="wx-note" style="margin-top: 0.4rem">{{ testResult.message }} <span v-if="testResult.at">({{ testResult.at }})</span></p>
      </section>

      <!-- refresh and wording -->
      <form v-if="can.settings" class="sb-card" style="--i: 2; margin-top: 0.9rem" @submit.prevent="save">
        <h3 class="sb-card__title"><span class="sb-ico g-teal"><icons name="clock" class="w-4 h-4" /></span>{{ $t('Refresh times and options') }}</h3>
        <div class="wx-form">
          <div class="sb-field"><label for="s1">{{ $t('Current reading kept for (min)') }}</label><input id="s1" v-model.number="form.ttl_current_min" type="number" min="5" max="1440" /></div>
          <div class="sb-field"><label for="s2">{{ $t('Forecast kept for (min)') }}</label><input id="s2" v-model.number="form.ttl_forecast_min" type="number" min="15" max="1440" /></div>
          <div class="sb-field"><label for="s3">{{ $t('Alerts kept for (min)') }}</label><input id="s3" v-model.number="form.ttl_alerts_min" type="number" min="5" max="1440" /></div>
          <div class="sb-field"><label for="s4">{{ $t('Out of date after (times the above)') }}</label><input id="s4" v-model.number="form.stale_multiplier" type="number" min="2" max="12" /></div>
          <div class="sb-field"><label for="s5">{{ $t('Refresh button once every (min)') }}</label><input id="s5" v-model.number="form.manual_refresh_min" type="number" min="1" max="120" /></div>
          <div class="sb-field"><label for="s6">{{ $t('After a failed call, wait (min)') }}</label><input id="s6" v-model.number="form.failure_backoff_min" type="number" min="1" max="720" /></div>
          <div class="sb-field"><label for="s7">{{ $t('Forecast hours to keep') }}</label><input id="s7" v-model.number="form.forecast_hours" type="number" min="24" max="168" /></div>
          <div class="sb-field"><label for="s8">{{ $t('Provider timeout (seconds)') }}</label><input id="s8" v-model.number="form.timeout_seconds" type="number" min="3" max="30" /></div>
          <div class="sb-field"><label for="s9">{{ $t('Retries on a connection problem') }}</label><input id="s9" v-model.number="form.retries" type="number" min="0" max="3" /></div>
          <div class="sb-field"><label for="s10">{{ $t('Re-open an acknowledged warning when :p% worse', { p: form.reopen_increase_pct }) }}</label><input id="s10" v-model.number="form.reopen_increase_pct" type="number" min="5" max="300" /></div>
          <div class="sb-field"><label for="s11">{{ $t('Keep saved forecasts for (days)') }}</label><input id="s11" v-model.number="form.snapshot_retention_days" type="number" min="7" max="365" /></div>
          <div class="sb-field"><label for="s12">{{ $t('Observations can be corrected for (hours)') }}</label><input id="s12" v-model.number="form.observation_edit_hours" type="number" min="1" max="720" /></div>
          <label class="wx-toggle wx-wide"><input v-model="form.refresh_on_view" type="checkbox" />{{ $t('Refresh an out-of-date project when somebody opens the Weather page (the scheduler also does it every 30 minutes)') }}</label>
          <label class="wx-toggle wx-wide"><input v-model="form.warn_without_scheduled_work" type="checkbox" />{{ $t('Also raise a general warning when no scheduled work matches an activity') }}</label>
          <div class="sb-field wx-wide"><label for="s13">{{ $t('Decisions people can record when acknowledging (one per line)') }}</label><textarea id="s13" v-model="decisionsText" class="wx-textarea" rows="4"></textarea></div>
        </div>
        <p class="wx-note" style="margin-top: 0.6rem">{{ $t('Units are metric: km/h, millimetres and degrees Celsius. Times are Africa/Dar_es_Salaam. Fewer provider calls: projects whose coordinates round to the same place (2 decimals) share one call.') }}</p>
        <div v-if="Object.keys(form.errors).length" class="ss-err"><p v-for="(e, k) in form.errors" :key="k">{{ e }}</p></div>
        <div class="wx-actions" style="margin-top: 0.8rem">
          <button type="submit" class="sb-btn is-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save settings') }}</button>
          <button type="button" class="sb-btn" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Restore defaults') }}</button>
          <Link v-if="can.workflow" :href="route('weather.workflow')" class="sb-btn"><icons name="bell" class="w-4 h-4" />{{ $t('Alerts and recipients') }}</Link>
        </div>
      </form>

      <!-- thresholds -->
      <template v-if="can.rules">
        <p class="wx-banner is-amber" style="margin-top: 0.9rem" role="note">
          <icons name="exclamation" class="w-4 h-4" />
          <span class="wx-banner__body"><b>{{ $t('Thresholds need review.') }}</b> {{ startingNotice }} {{ $t('A threshold is never an engineering or safety limit by itself. Editing a threshold clears its review.') }}</span>
        </p>
        <section v-for="(r, i) in rules" :key="r.id" class="sb-card wx-rule" style="margin-top: 0.9rem" :style="{ '--i': i + 3 }">
          <div class="wx-rule__top">
            <h3>{{ r.label }}</h3>
            <span v-if="r.reviewed" class="wx-chip is-reviewed">{{ $t('Reviewed by') }} {{ r.reviewed_by }}, {{ (r.reviewed_at || '').slice(0, 10) }}</span>
            <span v-else class="wx-chip is-starting">{{ r.differs_from_starting ? $t('Changed, not reviewed') : $t('Starting values, needs review') }}</span>
            <span v-if="!r.is_active" class="wx-chip">{{ $t('Switched off') }}</span>
          </div>
          <p v-if="r.reviewed && r.review_note" class="wx-note">{{ $t('Review note') }}: {{ r.review_note }}</p>
          <div class="wx-form">
            <div class="sb-field wx-wide"><label :for="'k' + r.id">{{ $t('Matches work packages containing (comma separated)') }}</label><input :id="'k' + r.id" v-model="edit[r.id].keywords" type="text" /></div>
            <div v-for="t in fields" :key="t.key" class="sb-field">
              <label :for="t.key + r.id">{{ t.label }}</label>
              <input :id="t.key + r.id" v-model="edit[r.id][t.key]" type="number" :step="t.step" :placeholder="$t('Not checked')" />
              <small class="wx-note">{{ $t('Starting') }}: {{ r.starting && r.starting[t.key] !== null ? r.starting[t.key] : $t('not checked') }}</small>
            </div>
            <div class="sb-field"><label :for="'l' + r.id">{{ $t('Look ahead (hours)') }}</label><input :id="'l' + r.id" v-model.number="edit[r.id].lookahead_hours" type="number" min="1" max="168" /></div>
            <div class="sb-field"><label>{{ $t('Level when exceeded') }}</label><auto-complete v-model="edit[r.id].severity" :suggestions="severities" /></div>
            <label class="wx-toggle"><input v-model="edit[r.id].is_active" type="checkbox" />{{ $t('Check this activity') }}</label>
          </div>
          <p v-for="(e, k) in errs[r.id] || {}" :key="k" class="wx-error">{{ e }}</p>
          <div v-if="reviewing === r.id" class="wx-ack">
            <div class="sb-field"><label>{{ $t('Review note (optional)') }}</label><input v-model="reviewNote" type="text" maxlength="500" :placeholder="$t('For example: checked against the crane manufacturer limits')" /></div>
            <div class="wx-actions">
              <button type="button" class="sb-btn is-primary" @click="review(r)"><icons name="tick" class="w-4 h-4" />{{ $t('I reviewed these thresholds') }}</button>
              <button type="button" class="sb-btn" @click="reviewing = null">{{ $t('Cancel') }}</button>
            </div>
          </div>
          <div class="wx-actions">
            <button type="button" class="sb-btn is-primary" @click="saveRule(r)"><icons name="tick" class="w-4 h-4" />{{ $t('Save thresholds') }}</button>
            <button type="button" class="sb-btn" :disabled="r.reviewed || dirty(r)" @click="reviewing = r.id; reviewNote = ''"><icons name="eye" class="w-4 h-4" />{{ $t('Mark as reviewed') }}</button>
            <button v-if="r.is_builtin" type="button" class="sb-btn" @click="restore(r)"><icons name="refresh" class="w-4 h-4" />{{ $t('Restore starting values') }}</button>
          </div>
        </section>
      </template>
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import WxHeader from '@/Shared/Weather/WxHeader.vue';

const EDITABLE = ['provider', 'base_url', 'ttl_current_min', 'ttl_forecast_min', 'ttl_alerts_min', 'stale_multiplier', 'manual_refresh_min', 'failure_backoff_min', 'refresh_on_view', 'forecast_hours', 'timeout_seconds', 'retries', 'warn_without_scheduled_work', 'reopen_increase_pct', 'snapshot_retention_days', 'observation_edit_hours'];

export default {
  components: { AdminLayout, Link, AutoComplete, WxHeader },
  props: { prefs: Object, defaults: Object, providers: Array, configuration: Object, rules: Array, startingNotice: String, can: Object },
  data() {
    const f = {};
    EDITABLE.forEach(k => { f[k] = this.prefs[k]; });
    f.decisions = [];
    const edit = {};
    this.rules.forEach(r => { edit[r.id] = this.rowEdit(r); });
    return { form: this.$inertia.form(f), decisionsText: (this.prefs.decisions || []).join('\n'), keyInfo: { ...this.prefs.key }, keyMode: '', newKey: '', password: '', keyError: '', busy: false, testing: false, testResult: this.prefs.last_test, edit, errs: {}, reviewing: null, reviewNote: '' };
  },
  computed: {
    providerOptions() { return this.providers.map(p => ({ value: p.value, label: p.label })); },
    selected() { return this.providers.find(p => p.value === this.form.provider); },
    severities() { return [{ value: 'caution', label: this.$t('Caution') }, { value: 'warning', label: this.$t('Warning') }]; },
    fields() {
      return [
        { key: 'max_wind_kmh', label: this.$t('Wind above (km/h)'), step: 1 }, { key: 'max_gust_kmh', label: this.$t('Gusts above (km/h)'), step: 1 },
        { key: 'max_rain_probability_pct', label: this.$t('Rain probability above (%)'), step: 1 }, { key: 'max_precip_mm_per_hour', label: this.$t('Rain above (mm per hour)'), step: 0.5 },
        { key: 'max_precip_mm_per_day', label: this.$t('Rain above (mm per day)'), step: 1 }, { key: 'min_temp_c', label: this.$t('Temperature below (°C)'), step: 0.5 }, { key: 'max_temp_c', label: this.$t('Temperature above (°C)'), step: 0.5 },
      ];
    },
  },
  methods: {
    rowEdit(r) {
      const e = { keywords: (r.keywords || []).join(', '), lookahead_hours: r.lookahead_hours, severity: r.severity, is_active: !!r.is_active, label: r.label };
      ['max_wind_kmh', 'max_gust_kmh', 'max_rain_probability_pct', 'max_precip_mm_per_hour', 'max_precip_mm_per_day', 'min_temp_c', 'max_temp_c'].forEach(k => { e[k] = r[k] === null ? '' : r[k]; });
      return e;
    },
    dirty(r) { const a = this.edit[r.id]; const b = this.rowEdit(r); return Object.keys(b).some(k => String(a[k]) !== String(b[k])); },
    save() {
      this.form.decisions = this.decisionsText.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
      this.form.put(route('weather.settings.update'), { preserveScroll: true });
    },
    reset() {
      EDITABLE.forEach(k => { if (k !== 'provider') this.form[k] = this.defaults[k]; });
      this.decisionsText = this.defaults.decisions.join('\n');
    },
    submitKey() {
      this.busy = true; this.keyError = '';
      const req = this.keyMode === 'replace' ? axios.put(route('weather.settings.key'), { api_key: this.newKey, password: this.password }) : axios.delete(route('weather.settings.key.remove'), { data: { password: this.password } });
      req.then(r => { this.keyInfo = r.data.key; this.keyMode = ''; this.newKey = ''; this.password = ''; router.reload({ only: ['configuration', 'prefs'], preserveScroll: true }); }).catch(e => {
        const d = e.response && e.response.data;
        this.keyError = (d && d.errors && Object.values(d.errors)[0][0]) || (d && d.message) || this.$t('The key could not be saved.');
      }).finally(() => { this.busy = false; });
    },
    test() {
      this.testing = true;
      axios.post(route('weather.settings.test')).then(r => { this.testResult = r.data; }).catch(e => { this.testResult = { ok: false, message: (e.response && e.response.data && e.response.data.message) || this.$t('The test could not be run.') }; }).finally(() => { this.testing = false; });
    },
    saveRule(r) {
      const e = this.edit[r.id];
      const n = v => (v === '' || v === null ? null : v);
      const body = { label: e.label, keywords: e.keywords.split(',').map(s => s.trim()).filter(Boolean), lookahead_hours: e.lookahead_hours, severity: e.severity, is_active: !!e.is_active };
      ['max_wind_kmh', 'max_gust_kmh', 'max_rain_probability_pct', 'max_precip_mm_per_hour', 'max_precip_mm_per_day', 'min_temp_c', 'max_temp_c'].forEach(k => { body[k] = n(e[k]); });
      this.errs = { ...this.errs, [r.id]: {} };
      router.put(route('weather.settings.rules.update', r.id), body, { preserveScroll: true, onError: er => { this.errs = { ...this.errs, [r.id]: er }; }, onSuccess: () => this.sync() });
    },
    review(r) { router.post(route('weather.settings.rules.review', r.id), { note: this.reviewNote }, { preserveScroll: true, onSuccess: () => { this.reviewing = null; this.sync(); } }); },
    async restore(r) {
      if (!(await this.$confirm(this.$t('Put the thresholds for :a back to the starting values?', { a: r.label })))) return;
      router.post(route('weather.settings.rules.restore', r.id), {}, { preserveScroll: true, onSuccess: () => this.sync() });
    },
    sync() { this.$nextTick(() => { this.rules.forEach(r => { this.edit[r.id] = this.rowEdit(r); }); }); },
  },
};
</script>
