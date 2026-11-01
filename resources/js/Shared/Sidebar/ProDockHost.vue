<template>
  <div v-if="proDock.visible" class="pro-dock-fixed" :class="{ 'is-collapsed': proDock.collapsed, 'is-glass': glass, 'is-autohide': auto, 'is-reveal': auto && reveal }" @mouseenter="hold" @mouseleave="leave">
    <glass-menu v-if="glass" :collapsed="proDock.collapsed" @toggle="proDockCollapse(!proDock.collapsed, true)" />
    <pro-menu v-else :collapsed="proDock.collapsed" @toggle="proDockCollapse(!proDock.collapsed, true)" />
  </div>

  <!-- Glass, auto-hide: a thin hot edge and a round handle; the pointer resting on either one brings the menu out -->
  <template v-if="proDock.visible && auto">
    <i class="gm-edge" aria-hidden="true" @mouseenter="show"></i>
    <button type="button" class="gm-hot" :class="{ 'is-gone': reveal }" :aria-label="$t('Show menu')" :data-tip="$t('Show menu')" @mouseenter="show" @focus="show" @click="reveal = !reveal">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" /></svg>
    </button>
  </template>

  <glass-profile-drawer v-if="proDock.visible" :open="proDock.profileOpen" :anchor="anchorEl" @close="proDock.profileOpen = false" />
</template>

<script>
import ProMenu from '@/Shared/Sidebar/ProMenu.vue';
import GlassMenu from '@/Shared/Sidebar/GlassMenu.vue';
import GlassProfileDrawer from '@/Shared/Sidebar/GlassProfileDrawer.vue';
import { proDock, proDockCollapse } from '@/Core/proDock';
import { glassPrefs } from '@/Core/glassPrefs';

export default {
  components: { ProMenu, GlassMenu, GlassProfileDrawer },
  data() { return { proDock, glassPrefs, reveal: false, anchorEl: null }; },
  computed: {
    glass() { return this.proDock.style === 'glass'; },
    // auto-hide belongs to the Glass menu (on a wide screen only: below that the menu is a drawer)
    auto() { return this.glass && this.glassPrefs.autohide; },
  },
  watch: {
    auto(v) { if (!v) this.reveal = false; },
    'proDock.profileOpen'(v) { if (v) this.findAnchor(); },
    'proDock.style'() { if (this.proDock.profileOpen) setTimeout(this.findAnchor, 700); },
  },
  mounted() {
    document.addEventListener('pointerdown', this.onOutside, true);
    document.addEventListener('keydown', this.onKey);
  },
  beforeUnmount() {
    document.removeEventListener('pointerdown', this.onOutside, true);
    document.removeEventListener('keydown', this.onKey);
    clearTimeout(this._t);
  },
  methods: {
    proDockCollapse,
    // the profile drawer sits beside the profile card of the Glass menu; with another menu it sits beside the menu itself
    findAnchor() { this.anchorEl = document.querySelector('.pro-dock-fixed .gm-user') || document.querySelector('.pro-dock-fixed'); },
    show() { clearTimeout(this._t); if (this.auto) this.reveal = true; },
    hold() { clearTimeout(this._t); },
    // the pointer leaving the menu hides it a moment later (a click elsewhere, Escape or a reload hides it at once)
    leave() { if (!this.auto || this.proDock.profileOpen) return; clearTimeout(this._t); this._t = setTimeout(() => { this.reveal = false; }, 520); },
    onOutside(e) {
      if (!this.auto || !this.reveal) return;
      const t = e.target;
      if (t && t.closest && t.closest('.pro-dock-fixed, .gm-hot, .gp, .gm-tip, [role=\'dialog\']')) return;
      this.reveal = false; this.proDock.profileOpen = false;
    },
    onKey(e) { if (e.key === 'Escape' && this.auto && this.reveal && !this.proDock.profileOpen) this.reveal = false; },
  },
};
</script>
