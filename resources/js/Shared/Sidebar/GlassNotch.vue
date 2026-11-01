<template>
  <div class="gn print:hidden" :class="{ 'is-open': open }" role="toolbar" :aria-label="$t('Quick settings')" @mouseenter="hoverIn" @mouseleave="hoverOut" @focusin="hoverIn" @focusout="onFocusOut">
    <button type="button" class="gn-tab" :aria-expanded="String(open)" :aria-label="$t('Quick settings')" @click="pinned = !pinned; open = pinned || open">
      <svg class="gn-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3.2" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></svg>
    </button>

    <div class="gn-shell">
    <div class="gn-body">
      <div class="gn-inner">
        <button v-if="darkAllowed" type="button" class="gn-it lg-tip tip-left" :data-tip="dark ? $t('Switch to normal mode') : $t('Switch to dark mode')" :aria-label="dark ? $t('Switch to normal mode') : $t('Switch to dark mode')" @click="toggleTheme">
          <span class="gn-ring" style="--c: #fbbf24" :style="dark ? { '--c': '#a5b4fc' } : null"><i class="gn-arc" style="--p: 100"></i>
            <svg v-if="!dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6" /></svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.2A8.2 8.2 0 0 1 9.8 4 8.2 8.2 0 1 0 20 14.2z" /></svg>
          </span>
          <span class="gn-cap">{{ dark ? $t('Dark') : $t('Light') }}</span>
        </button>

        <button type="button" class="gn-it lg-tip tip-left" :data-tip="$t('Glass strength: :s (click to change)', { s: levelLabel })" :aria-label="$t('Glass strength: :s (click to change)', { s: levelLabel })" @click="cycleGlassLevel">
          <span class="gn-ring" style="--c: #22d3ee"><i class="gn-arc" :style="{ '--p': levelPct }"></i>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.2c3.4 4 6 6.7 6 10.3a6 6 0 0 1-12 0c0-3.6 2.6-6.3 6-10.3z" /></svg>
          </span>
          <span class="gn-cap">{{ levelLabel }}</span>
        </button>

        <button type="button" class="gn-it lg-tip tip-left" :data-tip="toastsOn ? $t('Toast alerts are on (click to turn off)') : $t('Toast alerts are off (click to turn on)')" :aria-pressed="String(toastsOn)" :aria-label="$t('Toast Alerts')" @click="toggleToasts">
          <span class="gn-ring" :style="{ '--c': toastsOn ? '#4ade80' : '#94a3b8' }"><i class="gn-arc" :style="{ '--p': toastsOn ? 100 : 0 }"></i><Icons name="bell" class-name="gn-ic" /></span>
          <span class="gn-cap">{{ toastsOn ? $t('Toasts') : $t('Muted') }}</span>
        </button>

        <button type="button" class="gn-it lg-tip tip-left" :data-tip="chimeOn ? $t('Alert sound is on (click to turn off)') : $t('Alert sound is off (click to turn on)')" :aria-pressed="String(chimeOn)" :aria-label="$t('Notification Sound')" @click="toggleChime">
          <span class="gn-ring" :style="{ '--c': chimeOn ? '#4ade80' : '#94a3b8' }"><i class="gn-arc" :style="{ '--p': chimeOn ? 100 : 0 }"></i>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5H4z" /><path v-if="chimeOn" d="M15.5 9a4.2 4.2 0 0 1 0 6M18 6.6a7.6 7.6 0 0 1 0 10.8" /><path v-else d="M16 9.5l5 5M21 9.5l-5 5" /></svg>
          </span>
          <span class="gn-cap">{{ chimeOn ? $t('Sound') : $t('Silent') }}</span>
        </button>

        <i class="gn-sep" aria-hidden="true"></i>

        <button v-if="chatAllowed" type="button" class="gn-it lg-tip tip-left" :data-tip="chat.unread ? $t('WIMSChat - :n unread', { n: chat.unread }) : $t('Open WIMSChat')" :aria-label="$t('Open WIMSChat')" @click="chat.drawerOpen = true">
          <span class="gn-ring" style="--c: #60a5fa"><i class="gn-arc" style="--p: 100"></i><Icons name="chat" class-name="gn-ic" /><b v-if="chat.unread" class="gn-badge">{{ chat.unread > 99 ? '99+' : chat.unread }}</b></span>
          <span class="gn-cap">{{ $t('Chat') }}</span>
        </button>

        <button type="button" class="gn-it lg-tip tip-left" :data-tip="$t('Quick Access Menu')" :aria-label="$t('Quick Access Menu')" @click="openQuick">
          <span class="gn-ring" style="--c: #fb923c"><i class="gn-arc" style="--p: 100"></i><Icons name="lightning-bolt" class-name="gn-ic" /></span>
          <span class="gn-cap">{{ $t('Quick') }}</span>
        </button>

        <i class="gn-sep" aria-hidden="true"></i>

        <div class="gn-jump">
          <button type="button" class="gn-mini lg-tip tip-left" :data-tip="$t('Scroll to the top')" :aria-label="$t('Scroll to the top')" @click="go('top')"><Icons name="chevron-up" class-name="gn-ic" /></button>
          <button type="button" class="gn-mini lg-tip tip-left" :data-tip="$t('Scroll to the bottom')" :aria-label="$t('Scroll to the bottom')" @click="go('bottom')"><Icons name="chevron-down" class-name="gn-ic" /></button>
        </div>
      </div>
    </div>
    </div>
  </div>
</template>

<script>
import { chat } from '@/Core/wimschat';
import { glassPrefs, cycleGlassLevel } from '@/Core/glassPrefs';
import { toastSettings } from '@/Core/alertToasts';

// The notch on the right edge of the Glass screen, in the middle of its height. At rest it is a slim tab with a gear; with the pointer on it (or a tap, or the keyboard) it
// opens to the quick settings: light / dark, how strong the glass is, toast alerts, alert sound, WIMSChat, the Quick Access Menu and the two scroll buttons.
// The floating round buttons of WIMSChat, the Quick Access Menu and Scroll jump are hidden on the Glass screen: this notch is where they live.
export default {
  data() { return { open: false, pinned: false, dark: false, glassPrefs, chat }; },
  computed: {
    me() { return this.$page.props.auth?.user || {}; },
    darkAllowed() { return this.$page.props.enabledFeatures?.dark_mode !== false || this.$super; },
    chatAllowed() { return !!this.me.id && this.$can('view-wimschat') && (this.$page.props.enabledFeatures?.wimschat !== false || this.$super); },
    toastsOn() { return toastSettings(this.me).enabled; },
    chimeOn() { return this.me.chime_enabled !== false; },
    levelLabel() { return { soft: this.$t('Soft'), medium: this.$t('Medium'), strong: this.$t('Strong') }[glassPrefs.level]; },
    levelPct() { return { soft: 34, medium: 67, strong: 100 }[glassPrefs.level]; },
  },
  created() { this.syncTheme(); },
  mounted() {
    this._mo = typeof MutationObserver !== 'undefined' ? new MutationObserver(this.syncTheme) : null;
    if (this._mo) this._mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    document.addEventListener('keydown', this.onKey);
    document.addEventListener('pointerdown', this.onOutside, true);
  },
  beforeUnmount() {
    if (this._mo) this._mo.disconnect();
    clearTimeout(this._t);
    document.removeEventListener('keydown', this.onKey);
    document.removeEventListener('pointerdown', this.onOutside, true);
  },
  methods: {
    cycleGlassLevel,
    syncTheme() { this.dark = document.documentElement.classList.contains('dark'); },
    toggleTheme() { this.$setTheme(this.dark ? 'light' : 'dark'); this.syncTheme(); },
    // the pointer opens it at once and the notch closes a moment after it leaves (a tap or a click keeps it open until the next tap elsewhere)
    hoverIn() { clearTimeout(this._t); this.open = true; },
    hoverOut() { clearTimeout(this._t); if (this.pinned) return; this._t = setTimeout(() => { this.open = false; }, 380); },
    onFocusOut(e) { if (!this.pinned && !(e.relatedTarget && this.$el.contains(e.relatedTarget))) this.hoverOut(); },
    onKey(e) { if (e.key === 'Escape' && this.open) { this.open = false; this.pinned = false; } },
    onOutside(e) { if (this.open && this.pinned && this.$el && !this.$el.contains(e.target)) { this.open = false; this.pinned = false; } },
    // the same saves the profile page makes (Toast Alerts and Notification Sound), so the two places always agree
    toggleToasts() {
      const cur = toastSettings(this.me);
      window.axios.put(this.route('notification-preferences.toasts'), { enabled: !cur.enabled, seconds: cur.seconds, max: cur.max }).then(res => {
        this.me.extra_attributes = { ...(this.me.extra_attributes || {}), toast_alerts: res.data };
      }).catch(() => {});
    },
    toggleChime() {
      window.axios.put(this.route('notification-preferences.update'), { chime_enabled: !this.chimeOn }).then(res => {
        this.me.chime_enabled = !!res.data.chime_enabled;
      }).catch(() => {});
    },
    // the Quick Access Menu keeps its own (hidden here) button: pressing it opens its panel as always
    openQuick() { const b = document.querySelector('.qam-trigger'); if (b) b.click(); },
    // the element that scrolls: the main content region when it has its own scrollbar, otherwise the whole page (as the round Scroll jump buttons did)
    go(where) {
      const region = document.querySelector('[scroll-region]');
      const t = region && region.scrollHeight > region.clientHeight + 4 && /(auto|scroll)/.test(getComputedStyle(region).overflowY) ? region : (document.scrollingElement || document.documentElement);
      t.scrollTo({ top: where === 'top' ? 0 : t.scrollHeight, behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    },
  },
};
</script>
