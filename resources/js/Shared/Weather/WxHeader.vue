<template>
  <div>
    <sb-hero icon="cloud" :title="title" :subtitle="subtitle">
      <slot />
      <Link v-if="isSuper" :href="route('weather.workflow')" class="sb-gbtn"><icons name="bell" class="w-4 h-4" />{{ $t('Alerts') }}</Link>
    </sb-hero>
    <nav v-if="items.length > 1" class="sb-seg" role="tablist" :aria-label="$t('Weather & Site Risk')">
      <Link v-for="i in items" :key="i.key" :href="route(i.route)" role="tab" :class="{ 'is-on': current === i.key }"><icons :name="i.icon" class="w-4 h-4" />{{ i.label }}</Link>
    </nav>
  </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';

// The hero and the page switcher shared by every Weather & Site Risk page. Entries show only what the person may open.
export default {
  components: { Link, SbHero },
  props: { current: String, title: String, subtitle: String },
  computed: {
    isSuper() { const u = this.$page.props.auth.user; return !!(u && u.roles && u.roles.find(r => r.name === 'Super Admin')); },
    items() {
      const f = this.$page.props.enabledFeatures || {};
      const on = k => f.weather_risk !== false && f[k] !== false;
      const all = [
        { key: 'overview', route: 'weather.overview', icon: 'cloud', label: this.$t('Overview'), perm: ['read-weather'], feature: 'weather_risk' },
        { key: 'observations', route: 'weather.observations.index', icon: 'edit', label: this.$t('Observations'), perm: ['read-weather'], feature: 'weather_observations' },
        { key: 'warnings', route: 'weather.warnings.index', icon: 'exclamation', label: this.$t('Warnings'), perm: ['read-weather'], feature: 'weather_warnings' },
        { key: 'reports', route: 'weather.reports.index', icon: 'doc-text', label: this.$t('Reports'), perm: ['read-weather'], feature: 'weather_reports' },
        { key: 'settings', route: 'weather.settings.index', icon: 'cog', label: this.$t('Settings'), perm: ['manage-weather-settings', 'manage-weather-rules'], feature: 'weather_settings' },
      ];
      return all.filter(i => on(i.feature) && this.$can(i.perm));
    },
  },
};
</script>
