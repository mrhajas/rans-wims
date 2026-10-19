<template>
  <section class="sb-card wx-card" :class="{ 'is-collapsed': !open }" :id="'wx-project-' + c.id" :style="{ '--i': index }">
    <header class="wx-card__head">
      <button type="button" class="wx-expander lg-tip tip-left" :data-tip="open ? $t('Collapse') : $t('Expand')" :aria-expanded="open ? 'true' : 'false'" :aria-label="(open ? $t('Collapse') : $t('Expand')) + ' ' + c.name" @click="$emit('toggle')">
        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 7.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </button>
      <div class="wx-card__title" @click="$emit('toggle')">
        <h2>{{ c.name }}</h2>
        <small>
          {{ c.address || $t('No address recorded') }}
          <template v-if="hasLocation">&nbsp;| {{ Number(c.latitude).toFixed(4) }}, {{ Number(c.longitude).toFixed(4) }}</template>
        </small>
      </div>
      <div class="wx-actions">
        <span v-if="!open && c.current" class="wx-mini"><b>{{ num(c.current.temperature_c, 0) }}&deg;C</b> {{ c.current.condition }}</span>
        <span v-if="!open && c.warnings.length" class="wx-chip is-warning">{{ c.warnings.length }} {{ $t('warning(s)') }}</span>
        <span class="wx-chip" :class="statusClass">{{ statusText }}</span>
        <button v-if="open && canFetch" type="button" class="sb-btn lg-tip" :data-tip="refreshTip" :disabled="busy || c.cooldown > 0" @click="refresh">
          <icons name="refresh" class="w-4 h-4" />{{ busy ? $t('Updating...') : $t('Refresh') }}
        </button>
        <button v-if="open && c.can.location && hasLocation" type="button" class="sb-btn" @click="editLoc = !editLoc"><icons name="map-pin" class="w-4 h-4" />{{ $t('Location') }}</button>
      </div>
    </header>

    <div v-if="open" class="wx-card__body">
    <p v-if="msg" class="wx-banner is-amber" role="status"><icons name="info" class="w-4 h-4" /><span class="wx-banner__body">{{ msg }}</span></p>

    <!-- location not set, or being changed -->
    <div v-if="!hasLocation || editLoc" class="wx-block">
      <div v-if="!hasLocation" class="wx-banner is-amber" style="margin: 0">
        <icons name="map-pin" class="w-4 h-4" />
        <div class="wx-banner__body">
          <b class="block">{{ $t('Location not set') }}</b>
          <span> {{ $t('This project has no latitude and longitude, so WIMS cannot show weather for it.') }}</span>
          <span v-if="c.address"> {{ $t('Its address is') }}: <b>{{ c.address }}</b>. {{ $t('Find that place on a map and enter its coordinates below.') }}</span>
          <span v-if="!c.can.location && !c.can.edit_project"> {{ $t('Ask a person who is allowed to set project locations.') }}</span>
        </div>
        <Link v-if="c.can.edit_project" :href="route('warehouses.edit', c.id) + '#location'" class="sb-btn is-primary wx-setup"><icons name="map-pin" class="w-4 h-4" />{{ $t('Setup Location') }}</Link>
      </div>
      <form v-if="c.can.location" class="grid gap-2" @submit.prevent="saveLocation">
        <div class="wx-loc">
          <div class="sb-field"><label :for="'lat' + c.id">{{ $t('Latitude') }}</label><input :id="'lat' + c.id" v-model="lat" type="text" inputmode="decimal" placeholder="-6.1659" /></div>
          <div class="sb-field"><label :for="'lng' + c.id">{{ $t('Longitude') }}</label><input :id="'lng' + c.id" v-model="lng" type="text" inputmode="decimal" placeholder="39.2026" /></div>
        </div>
        <div class="wx-geo">
          <div class="sb-field"><label :for="'geo' + c.id">{{ $t('Place to look up') }}</label><input :id="'geo' + c.id" v-model="geoQuery" type="text" :placeholder="c.address || c.name" @keydown.enter.prevent="fetchCoordinates" /></div>
          <button type="button" class="sb-btn" :disabled="geoBusy" @click="fetchCoordinates"><icons name="map-pin" class="w-4 h-4" />{{ geoBusy ? $t('Looking up...') : $t('Fetch coordinates') }}</button>
        </div>
        <p v-if="geoMsg" class="wx-note" :class="{ 'wx-error': geoBad }">{{ geoMsg }}</p>
        <ul v-if="geoList.length" class="wx-geo__list">
          <li v-for="(g, i) in geoList" :key="i"><button type="button" class="wx-geo__item" :class="{ 'is-on': String(lat) === String(g.latitude) && String(lng) === String(g.longitude) }" @click="useCandidate(g)"><b>{{ g.name }}</b><span>{{ [g.region, g.country].filter(Boolean).join(', ') }}</span><em>{{ g.latitude }}, {{ g.longitude }}</em></button></li>
        </ul>
        <p class="wx-note">{{ $t('Latitude is between -90 and 90 (Tanzania is about -1 to -12). Longitude is between -180 and 180 (Tanzania is about 29 to 41). The same coordinates are used by the project form.') }}</p>
        <p v-if="locErr" class="wx-error">{{ locErr }}</p>
        <div class="wx-actions">
          <button type="submit" class="sb-btn is-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Save location') }}</button>
          <button v-if="hasLocation" type="button" class="sb-btn" @click="editLoc = false">{{ $t('Cancel') }}</button>
        </div>
      </form>
    </div>

    <!-- not configured / nothing fetched yet -->
    <p v-if="c.status === 'not_configured'" class="wx-state">{{ c.message }}<template v-if="settingsOk">&nbsp;<Link :href="route('weather.settings.index')">{{ $t('Open Weather Settings') }}</Link></template></p>
    <p v-else-if="c.status === 'no_data' && !fetching" class="wx-state">{{ c.message }}</p>
    <p v-else-if="fetching && !c.forecast_ready" class="wx-state"><span class="sb-spin" style="display: inline-block; vertical-align: middle; margin-right: 8px"></span>{{ $t('Getting the latest weather from the provider...') }}</p>
    <p v-if="c.status === 'failed' && !hasData" class="wx-banner is-rose" role="alert"><icons name="exclamation" class="w-4 h-4" /><span class="wx-banner__body">{{ c.message }}</span></p>

    <template v-if="hasData">
      <p v-if="c.status === 'failed' || c.stale" class="wx-banner" :class="c.stale ? 'is-rose' : 'is-amber'" role="alert">
        <icons name="exclamation" class="w-4 h-4" /><span class="wx-banner__body">{{ c.message }}</span>
      </p>

      <!-- CURRENT reading from the provider -->
      <div v-if="c.current" class="wx-block">
        <div class="wx-block__top">
          <span class="wx-chip is-provider">{{ $t('Provider reading') }}</span>
          <span>{{ $t('Now') }}</span>
          <time>{{ $t('Source') }}: {{ c.provider_label }}, {{ $t('updated') }} {{ dt(c.current_at) }}<template v-if="c.current_due">&nbsp;({{ $t('older than') }} {{ prefs.ttl_current_min }} {{ $t('min, refreshing when possible') }})</template></time>
        </div>
        <div class="wx-now">
          <div class="wx-now__main">
            <wx-icon :name="c.current.icon" />
            <div><div class="wx-now__temp">{{ num(c.current.temperature_c, 0) }}&deg;C</div><div class="wx-now__cond">{{ c.current.condition }}</div></div>
          </div>
          <dl class="wx-stats">
            <div><dt>{{ $t('Feels like') }}</dt><dd>{{ num(c.current.feels_like_c, 0) }}&deg;C</dd></div>
            <div><dt>{{ $t('Wind') }}</dt><dd>{{ num(c.current.wind_kmh, 0) }} km/h {{ compass(c.current.wind_dir_deg) }}</dd></div>
            <div><dt>{{ $t('Gusts') }}</dt><dd>{{ c.current.gust_kmh !== null ? num(c.current.gust_kmh, 0) + ' km/h' : $t('not given') }}</dd></div>
            <div><dt>{{ $t('Humidity') }}</dt><dd>{{ num(c.current.humidity_pct, 0) }}%</dd></div>
            <div><dt>{{ $t('Rain (last hour)') }}</dt><dd>{{ num(c.current.precip_mm, 1) }} mm</dd></div>
            <div><dt>{{ $t('Cloud') }}</dt><dd>{{ num(c.current.cloud_pct, 0) }}%</dd></div>
          </dl>
        </div>
        <p class="wx-note">{{ $t('A provider reading is a model or station estimate for the area. It is not a measurement taken on this site.') }}</p>
      </div>

      <!-- OBSERVED on site -->
      <div class="wx-block is-observed">
        <div class="wx-block__top">
          <span class="wx-chip is-observed">{{ $t('Observed on site') }}</span>
          <span v-if="c.observed">{{ dt(c.observed.observed_at) }}</span>
          <time v-if="c.observed">{{ $t('Recorded by') }} {{ c.observed.recorder }}</time>
        </div>
        <template v-if="c.observed">
          <div class="wx-meta" style="font-size: 0.86rem; color: var(--lg-ink)">
            <span><b>{{ c.observed.conditions_label }}</b></span>
            <span v-if="c.observed.rainfall_label || c.observed.rainfall_mm !== null">{{ $t('Rain') }}: {{ c.observed.rainfall_label }}<template v-if="c.observed.rainfall_mm !== null">{{ ' ' + num(c.observed.rainfall_mm, 1) + ' mm' }}</template></span>
            <span v-if="c.observed.temperature_c !== null">{{ num(c.observed.temperature_c, 1) }}&deg;C</span>
            <span v-if="c.observed.wind_label || c.observed.wind_kmh !== null">{{ $t('Wind') }}: {{ c.observed.wind_label }}<template v-if="c.observed.wind_kmh !== null">{{ ' ' + num(c.observed.wind_kmh, 0) + ' km/h' }}</template></span>
          </div>
          <p v-if="c.observed.notes" class="wx-note">{{ c.observed.notes }}</p>
        </template>
        <p v-else class="wx-note">{{ $t('Nobody has recorded the weather on site in the last 24 hours.') }}</p>
        <div v-if="c.can.record" class="wx-actions"><Link :href="route('weather.observations.index', { project: c.id, record: 1 })" class="sb-btn"><icons name="plus" class="w-4 h-4" />{{ $t('Record observation') }}</Link></div>
      </div>

      <!-- FORECAST -->
      <div class="wx-block">
        <div class="wx-block__top">
          <span class="wx-chip is-forecast">{{ $t('Forecast') }}</span>
          <span>{{ $t('Next hours and days') }}</span>
          <time>{{ $t('Source') }}: {{ c.provider_label }}, {{ $t('fetched') }} {{ dt(c.fetched_at) }} ({{ ago(c.age_minutes) }})</time>
        </div>
        <div class="wx-strip" role="list" :aria-label="$t('Hourly forecast')">
          <div v-for="(h, i) in shownHours" :key="h.time" class="wx-hour" role="listitem">
            <b>{{ hour(h.time, i) }}</b>
            <wx-icon :name="h.icon" size="is-sm" />
            <span class="t">{{ num(h.temperature_c, 0) }}&deg;</span>
            <span class="r">{{ h.precip_probability_pct !== null ? h.precip_probability_pct + '%' : '-' }}</span>
            <span class="r">{{ num(h.precip_mm, 1) }} mm</span>
            <span class="w">{{ num(h.wind_kmh, 0) }}<template v-if="h.gust_kmh">&nbsp;/ {{ num(h.gust_kmh, 0) }}</template> km/h</span>
          </div>
        </div>
        <p class="wx-note">{{ $t('Each hour: temperature, rain probability, expected rain, wind (gusts) in km/h.') }}</p>
        <div v-if="c.hours.length > 12" class="wx-actions"><button type="button" class="sb-btn" @click="more = !more">{{ more ? $t('Show fewer hours') : $t('Show more hours') }}</button></div>
        <div class="wx-days">
          <div v-for="d in c.days" :key="d.date" class="wx-day">
            <b>{{ dayLabel(d.date) }}</b>
            <wx-icon :name="d.icon" size="is-sm" />
            <div class="wx-day__v">
              <span>{{ num(d.temp_min_c, 0) }} to {{ num(d.temp_max_c, 0) }}&deg;C</span>
              <span class="r">{{ d.precip_probability_pct !== null ? d.precip_probability_pct + '% ' : '' }}{{ num(d.precip_mm, 1) }} mm</span>
              <span>{{ num(d.wind_kmh, 0) }}<template v-if="d.gust_kmh">&nbsp;({{ num(d.gust_kmh, 0) }})</template> km/h</span>
            </div>
          </div>
        </div>
        <p class="wx-note"><b>{{ $t('Uncertainty') }}:</b> {{ c.uncertainty || $t('Uncertainty is not provided by this provider.') }}</p>
      </div>

      <!-- provider alerts -->
      <div class="wx-block">
        <div class="wx-block__top"><span class="wx-chip is-provider">{{ $t('Provider alerts') }}</span></div>
        <p v-if="!c.alerts_supported" class="wx-note">{{ $t('Severe-weather alerts are not supported by this provider.') }}</p>
        <p v-else-if="!activeAlerts.length" class="wx-note">{{ $t('No severe-weather alert is reported for this location by') }} {{ c.provider_label }} ({{ $t('checked') }} {{ dt(c.fetched_at) }}). {{ $t('No alert does not mean no risk.') }}</p>
        <ul v-else class="wx-acts">
          <li v-for="a in activeAlerts" :key="a.id" class="wx-act is-warning">
            <div class="wx-act__top"><b>{{ a.event }}</b><span class="wx-chip is-warning">{{ $t('Provider alert') }}</span></div>
            <p>{{ a.sender }} <template v-if="a.start">({{ dt(a.start) }}<template v-if="a.end">&nbsp;{{ $t('to') }} {{ dt(a.end) }}</template>)</template></p>
            <small>{{ a.description }}</small>
          </li>
        </ul>
      </div>

      <!-- weather-sensitive activities -->
      <div v-if="c.activities.length" class="wx-block">
        <div class="wx-block__top"><span class="wx-chip is-forecast">{{ $t('Forecast') }}</span><span>{{ $t('Weather-sensitive activities (advice only)') }}</span></div>
        <ul class="wx-acts">
          <li v-for="a in c.activities" :key="a.rule_id" class="wx-act" :class="'is-' + a.level">
            <div class="wx-act__top">
              <b>{{ a.label }}</b>
              <span class="wx-chip" :class="'is-' + a.level">{{ levelText(a.level) }}</span>
              <span v-if="a.starting_values" class="wx-chip is-starting lg-tip" :data-tip="$t('Starting values. Review against the applicable engineering and safety requirements.')">{{ $t('Starting values') }}</span>
            </div>
            <p v-if="a.sentence">{{ a.sentence }}</p>
            <p v-else>{{ $t('The forecast stays within the thresholds set for this activity for the next') }} {{ a.lookahead_hours }} {{ $t('hours. This is not a safety check.') }}</p>
            <small v-if="a.scheduled.length">{{ $t('Scheduled work') }}: <template v-for="(s, k) in a.scheduled" :key="s.id">{{ k ? '; ' : '' }}{{ s.reference }} {{ s.title }}</template></small>
            <small v-else>{{ $t('No scheduled work was matched to this activity for this period.') }}</small>
          </li>
        </ul>
        <p class="wx-note">{{ $t('These thresholds are starting values until a person has reviewed them. A warning is advice to review before proceeding. It does not approve or forbid any work.') }}</p>
      </div>
    </template>

    <!-- open warnings -->
    <div v-if="c.warnings.length" class="wx-block">
      <div class="wx-block__top"><span class="wx-chip is-warning">{{ $t('Warnings') }}</span></div>
      <ul class="wx-acts">
        <li v-for="w in c.warnings" :key="w.id" class="wx-act" :class="'is-' + w.severity">
          <div class="wx-act__top"><b>{{ w.title }}</b><span class="wx-chip" :class="'is-' + w.status">{{ statusWord(w.status) }}</span></div>
          <p>{{ w.message }}</p>
        </li>
      </ul>
      <div class="wx-actions"><Link :href="route('weather.warnings.index', { project: c.id })" class="sb-btn"><icons name="list" class="w-4 h-4" />{{ c.can.acknowledge ? $t('Review and acknowledge') : $t('Open warnings') }}</Link></div>
    </div>
    </div>
  </section>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import WxIcon from '@/Shared/Weather/WxIcon.vue';
import { hhmm, dayLabel, dateTime, hourLabel, ago, num, compass } from '@/Shared/Weather/wxFormat';

// One request at a time to the server for out-of-date cards, so opening the page never floods the provider.
let chain = Promise.resolve();

export default {
  components: { Link, WxIcon },
  props: { open: { type: Boolean, default: false }, card: Object, prefs: Object, configuration: Object, canSettings: Boolean, index: { type: Number, default: 0 } },
  emits: ['update', 'toggle'],
  data() {
    return { asked: false, c: { ...this.card, forecast_ready: !!(this.card.hours && this.card.hours.length) }, busy: false, fetching: false, msg: '', more: false, editLoc: false, lat: '', lng: '', locErr: '', geoQuery: this.card.address || this.card.name || '', geoBusy: false, geoMsg: '', geoBad: false, geoList: [] };
  },
  computed: {
    hasLocation() { return this.c.latitude !== null && this.c.longitude !== null && this.c.status !== 'no_location'; },
    hasData() { return !!(this.c.hours && this.c.hours.length) && this.c.status !== 'no_location' && this.c.status !== 'not_configured'; },
    canFetch() { return this.hasLocation && this.c.status !== 'not_configured'; },
    settingsOk() { return this.canSettings; },
    shownHours() { return this.c.hours.slice(0, this.more ? 36 : 12); },
    activeAlerts() { const now = new Date(); return (this.c.alerts || []).filter(a => !a.end || new Date(a.end.replace(' ', 'T')) >= now); },
    statusClass() { return { ok: 'is-ok', stale: 'is-stale', failed: 'is-failed', not_configured: 'is-stale', no_location: 'is-stale', no_data: '' }[this.c.status] || ''; },
    statusText() {
      const s = this.c.status;
      if (s === 'ok') return this.c.due || this.c.current_due ? this.$t('Update due') : this.$t('Up to date');
      return { stale: this.$t('Out of date'), failed: this.$t('Update failed'), not_configured: this.$t('Not set up'), no_location: this.$t('Location not set'), no_data: this.fetching ? this.$t('Updating') : this.$t('No data yet') }[s] || s;
    },
    refreshTip() { return this.c.cooldown > 0 ? this.$t('Refreshed a moment ago. Try again in :s seconds.', { s: this.c.cooldown }) : this.$t('Ask the provider for the latest data (limited to once every :m minutes per location).', { m: this.prefs.manual_refresh_min }); },
  },
  watch: {
    card: { handler(v) { this.c = { ...v, forecast_ready: !!(v.hours && v.hours.length) }; } },
    open() { this.ask(); },
    editLoc(v) { if (v) { this.lat = this.c.latitude ?? ''; this.lng = this.c.longitude ?? ''; this.locErr = ''; } },
  },
  mounted() { this.ask(); },
  methods: {
    num, compass, dayLabel,
    // out-of-date data is only fetched for a card somebody opened, so a long list of collapsed projects never floods the provider
    ask() { if (this.open && !this.asked && this.c.needs_fetch) { this.asked = true; this.fetchNow(); } },
    dt(s) { return dateTime(s); },
    hour(s, i) { return hourLabel(s, i); },
    ago(m) { return ago(m, x => this.$t(x)); },
    levelText(l) { return { clear: this.$t('Within thresholds'), caution: this.$t('Review advised'), warning: this.$t('May be unsuitable') }[l] || l; },
    statusWord(s) { return { open: this.$t('Open'), acknowledged: this.$t('Acknowledged'), expired: this.$t('Expired'), dismissed: this.$t('Not applicable') }[s] || s; },
    apply(card) { this.c = { ...card, forecast_ready: !!(card.hours && card.hours.length) }; this.$emit('update', card); },
    fetchNow() {
      this.fetching = true;
      chain = chain.then(() => axios.get(route('weather.card', this.c.id), { params: { fetch: 1 } }).then(r => this.apply(r.data)).catch(() => { this.msg = this.$t('The latest weather could not be loaded. Showing what is saved.'); }).finally(() => { this.fetching = false; }));
    },
    refresh() {
      this.busy = true; this.msg = '';
      axios.post(route('weather.refresh', this.c.id)).then(r => this.apply(r.data.card)).catch(e => {
        if (e.response && e.response.data && e.response.data.card) this.apply(e.response.data.card);
        this.msg = (e.response && e.response.data && e.response.data.message) || this.$t('The refresh failed.');
      }).finally(() => { this.busy = false; });
    },
    fetchCoordinates() {
      this.geoBusy = true; this.geoMsg = ''; this.geoBad = false; this.geoList = [];
      axios.get(route('weather.locations.geocode', this.c.id), { params: { q: this.geoQuery.trim() || undefined } }).then(r => {
        this.geoList = r.data.candidates || [];
        if (!this.geoList.length) { this.geoBad = true; this.geoMsg = (r.data.error || this.$t('No place was found for')) + ' ' + (r.data.tried || []).join(' | ') + '. ' + this.$t('Type a town or area name above and try again, or enter the coordinates by hand.'); return; }
        this.geoMsg = this.$t('Found by :s. Pick the right place: it fills the fields below, then press Save location. Check it against the site before you rely on it.', { s: r.data.source });
        if (this.geoList.length === 1) this.useCandidate(this.geoList[0]);
      }).catch(e => { this.geoBad = true; this.geoMsg = (e.response && e.response.data && e.response.data.message) || this.$t('The place search could not be done.'); }).finally(() => { this.geoBusy = false; });
    },
    useCandidate(g) { this.lat = String(g.latitude); this.lng = String(g.longitude); },
    saveLocation() {
      this.busy = true; this.locErr = '';
      axios.put(route('weather.locations.update', this.c.id), { latitude: this.lat === '' ? null : this.lat, longitude: this.lng === '' ? null : this.lng }).then(r => {
        this.editLoc = false; this.msg = r.data.warning || '';
        this.apply(r.data.card);
        if (r.data.card.needs_fetch) this.fetchNow();
      }).catch(e => {
        const er = e.response && e.response.data;
        this.locErr = (er && er.errors && Object.values(er.errors)[0][0]) || (er && er.message) || this.$t('The location could not be saved.');
      }).finally(() => { this.busy = false; });
    },
  },
};
</script>
