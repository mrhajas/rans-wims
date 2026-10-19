<template>
  <section class="lg-card sdy-card wx">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-sky"><icons name="cloud" class="w-5 h-5" /></span>
      <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Weather') }}</h2><p class="lg-card-sub">{{ $t('Observed on site is kept apart from the forecast. Neither is a decision about the work.') }}</p></div>
    </header>

    <div class="wx-block is-observed">
      <div class="wx-block__top"><span class="wx-chip is-observed">{{ $t('Observed on site') }}</span><span>{{ dayText }}</span></div>
      <ul v-if="weather.observations.length" class="wx-list">
        <li v-for="o in weather.observations" :key="o.id" class="wx-item">
          <div class="wx-item__top"><b>{{ o.conditions_label }}</b><span class="wx-source">{{ hhmm(o.observed_at) }} | {{ $t('Recorded by') }} {{ o.recorder }}</span></div>
          <div class="wx-meta">
            <span v-if="o.rainfall_label || o.rainfall_mm !== null">{{ $t('Rain') }}: {{ o.rainfall_label }}<template v-if="o.rainfall_mm !== null">{{ ' ' + o.rainfall_mm + ' mm' }}</template></span>
            <span v-if="o.wind_label || o.wind_kmh !== null">{{ $t('Wind') }}: {{ o.wind_label }}<template v-if="o.wind_kmh !== null">{{ ' ' + o.wind_kmh + ' km/h' }}</template></span>
            <span v-if="o.temperature_c !== null">{{ o.temperature_c }}&deg;C</span>
          </div>
          <p v-if="o.notes" class="wx-note">{{ o.notes }}</p>
        </li>
      </ul>
      <p v-else class="wx-note">{{ $t('Nobody recorded the weather on site for this day.') }}</p>
      <div v-if="weather.can_record" class="wx-actions"><Link :href="weather.record_url" class="sb-btn"><icons name="plus" class="w-4 h-4" />{{ $t('Record observation') }}</Link></div>
    </div>

    <div class="wx-block" style="margin-top: 0.6rem">
      <div class="wx-block__top"><span class="wx-chip is-forecast">{{ $t('Forecast') }}</span><span>{{ $t('As saved by WIMS for this day') }}</span></div>
      <template v-if="weather.forecast">
        <p class="wx-line">{{ weather.forecast.summary }}</p>
        <p class="wx-note">{{ $t('Source') }}: {{ weather.forecast.provider }}, {{ $t('fetched') }} {{ stamp(weather.forecast.fetched_at) }} ({{ $t(weather.forecast.timing) }}).</p>
        <p class="wx-note"><b>{{ $t('Uncertainty') }}:</b> {{ weather.forecast.uncertainty || $t('Uncertainty is not provided by this provider.') }}</p>
      </template>
      <p v-else class="wx-note">{{ $t('No forecast was saved for this project and day, so there is nothing to compare with.') }}</p>
    </div>

    <ul v-if="weather.warnings.length" class="wx-acts" style="margin-top: 0.6rem">
      <li v-for="w in weather.warnings" :key="w.id" class="wx-act" :class="'is-' + w.severity">
        <div class="wx-act__top"><b>{{ w.title }}</b><span class="wx-chip" :class="'is-' + w.status">{{ w.status }}</span></div>
      </li>
    </ul>
    <p class="wx-note" style="margin-top: 0.5rem"><Link :href="weather.overview_url">{{ $t('Open the weather overview for this project') }}</Link></p>
  </section>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import { hhmm, dateTime, dateOnly } from '@/Shared/Weather/wxFormat';

// The small Weather panel on a Site Diary: that day's observations (labelled "Observed on site") and the forecast saved for the date (provider and time).
export default {
  components: { Link },
  props: { weather: Object },
  computed: { dayText() { return dateOnly(this.weather.date); } },
  methods: { hhmm, stamp(s) { return dateTime(s); } },
};
</script>
