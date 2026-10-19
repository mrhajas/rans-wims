<template>
  <admin-layout :title="$t('Weather & Site Risk')">
    <div class="lg-page sb wx sc-ui px-4 md:px-0">
      <wx-header current="overview" :title="$t('Weather & Site Risk')" :subtitle="$t('Current conditions, forecasts and advice for weather-sensitive work on your projects. Every figure says where it comes from and when. A warning is advice to review, never an instruction to start or stop work.')">
        <Link v-if="can.observe" :href="route('weather.observations.index', { record: 1 })" class="sb-gbtn"><icons name="plus" class="w-4 h-4" />{{ $t('Record observation') }}</Link>
      </wx-header>

      <p v-if="!configuration.ok" class="wx-banner is-amber" role="status">
        <icons name="info" class="w-4 h-4" />
        <span class="wx-banner__body"><b>{{ $t('Weather provider not set up.') }}</b> {{ configuration.message }}
          <Link v-if="can.settings" :href="route('weather.settings.index')">{{ $t('Open Weather Settings') }}</Link></span>
      </p>

      <section class="sb-card sb-bar" style="--i: 0">
        <span class="sb-ico g-sky"><icons name="building" class="w-4 h-4" /></span>
        <div class="wx-search">
          <span class="wx-search__ico"><icons name="search" class="w-4 h-4" /></span>
          <input id="wx-search" v-model="q" type="text" autocomplete="off" :placeholder="$t('Search projects...')" :aria-label="$t('Search projects')" @keydown.esc="q = ''" />
          <button v-if="q" type="button" class="wx-search__x" :aria-label="$t('Clear')" @click="q = ''"><icons name="cross" class="w-3.5 h-3.5" /></button>
        </div>
        <div class="wx-actions">
          <button type="button" class="sb-btn" :disabled="!shown.length || allOpen" @click="setAll(true)"><svg viewBox="0 0 20 20" class="w-5 h-5" aria-hidden="true"><path d="M5 4.5l5 5 5-5M5 10.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ $t('Expand all') }}</button>
          <button type="button" class="sb-btn" :disabled="!openCount" @click="setAll(false)"><svg viewBox="0 0 20 20" class="w-5 h-5" aria-hidden="true"><path d="M5 9.5l5-5 5 5M5 15.5l5-5 5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ $t('Collapse all') }}</button>
        </div>
        <span class="wx-note">{{ shown.length }}<template v-if="q"> / {{ list.length }}</template> {{ $t('project(s)') }}. {{ $t('Out-of-date data is refreshed automatically (at most every') }} {{ prefs.ttl_current_min }} {{ $t('minutes).') }} {{ $t('Open a project to see its weather.') }}</span>
      </section>

      <div v-if="!list.length" class="wx-state">{{ $t('No active project is available to you.') }}</div>
      <div v-else-if="!shown.length" class="wx-state">{{ $t('No project matches your search.') }}</div>
      <section v-for="g in groups" :key="g.name" class="wx-group">
        <header class="wx-group__head">
          <span class="wx-group__tag" :class="g.tone">{{ g.name }}</span>
          <span class="wx-group__count">{{ g.cards.length }} {{ $t('project(s)') }}</span>
          <span class="wx-group__line"></span>
        </header>
        <div class="wx-grid">
          <wx-project-card v-for="(c, i) in g.cards" :key="c.id" :open="!!opened[c.id]" :card="c" :prefs="prefs" :configuration="configuration" :can-settings="can.settings" :index="i" @update="onUpdate" @toggle="opened[c.id] = !opened[c.id]" />
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import WxHeader from '@/Shared/Weather/WxHeader.vue';
import WxProjectCard from '@/Shared/Weather/WxProjectCard.vue';

export default {
  components: { AdminLayout, Link, WxHeader, WxProjectCard },
  props: { cards: Array, configuration: Object, prefs: Object, projectOptions: Array, focus: { type: Number, default: null }, can: Object },
  data() { return { q: '', list: this.cards, opened: this.focus ? { [this.focus]: true } : {} }; },
  computed: {
    shown() {
      const q = this.q.trim().toLowerCase();
      return q ? this.list.filter(c => ((c.name || '') + ' ' + (c.address || '')).toLowerCase().includes(q)) : this.list;
    },
    // projects grouped by their category: Government first, the main warehouses last, the rest in between
    groups() {
      const rank = n => { const k = (n || '').toLowerCase(); if (k.startsWith('government')) return 0; if (k.startsWith('main warehouse')) return 99; return { private: 1, general: 2, directors: 3, 'rans warehouse': 4 }[k] ?? 50; };
      const tone = n => { const k = (n || '').toLowerCase(); return k.startsWith('government') ? 'is-gov' : k.startsWith('main warehouse') ? 'is-main' : ''; };
      const by = {};
      this.shown.forEach(c => { (by[c.category || 'Other'] = by[c.category || 'Other'] || []).push(c); });
      return Object.keys(by).sort((a, b) => rank(a) - rank(b) || a.localeCompare(b)).map(name => ({ name, tone: tone(name), cards: by[name].slice().sort((a, b) => (a.name || '').localeCompare(b.name || '')) }));
    },
    openCount() { return this.shown.filter(c => this.opened[c.id]).length; },
    allOpen() { return this.shown.length > 0 && this.openCount === this.shown.length; },
  },
  watch: { cards(v) { this.list = v; } },
  mounted() {
    if (this.focus) this.$nextTick(() => { const el = document.getElementById('wx-project-' + this.focus); if (el) el.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
  },
  methods: {
    setAll(v) { const o = { ...this.opened }; this.shown.forEach(c => { o[c.id] = v; }); this.opened = o; },
    onUpdate(card) { const i = this.list.findIndex(c => c.id === card.id); if (i >= 0) this.list.splice(i, 1, card); },
  },
};
</script>
