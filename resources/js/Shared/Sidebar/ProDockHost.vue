<template>
  <div v-if="proDock.visible" class="pro-dock-fixed" :class="{ 'is-collapsed': menuCollapsed, 'is-glass': glass, 'is-autohide': auto, 'is-reveal': auto && reveal }" @mouseenter="show" @mouseleave="leave">
    <glass-menu v-if="glass" :key="'g' + proDock.epoch" :collapsed="menuCollapsed" @toggle="proDockCollapse(!proDock.collapsed, true)" />
    <pro-menu v-else :key="'p' + proDock.epoch" :collapsed="proDock.collapsed" @toggle="proDockCollapse(!proDock.collapsed, true)" />
  </div>

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
    // auto-hide belongs to the Glass menu (on a wide screen only: below that the menu is a drawer): it rests as the narrow icon menu and opens wide while the pointer is on it
    auto() { return this.glass && this.glassPrefs.autohide; },
    menuCollapsed() { return this.auto ? !this.reveal : this.proDock.collapsed; },
  },
  watch: {
    auto(v) { if (!v) this.reveal = false; },
    'proDock.profileOpen'(v) { if (v) this.findAnchor(); },
    'proDock.style'() { if (this.proDock.profileOpen) setTimeout(this.findAnchor, 700); },
    'proDock.epoch'() { if (this.proDock.profileOpen) this.$nextTick(this.findAnchor); },
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
    // the pointer leaving the menu narrows it a moment later (a click elsewhere, Escape or a reload narrows it at once)
    leave() { if (!this.auto || this.proDock.profileOpen) return; clearTimeout(this._t); this._t = setTimeout(() => { this.reveal = false; }, 420); },
    onOutside(e) {
      if (!this.auto || !this.reveal) return;
      const t = e.target;
      if (t && t.closest && t.closest('.pro-dock-fixed, .gp, .gm-tip, [role=\'dialog\']')) return;
      this.reveal = false; this.proDock.profileOpen = false;
    },
    onKey(e) { if (e.key === 'Escape' && this.auto && this.reveal && !this.proDock.profileOpen) this.reveal = false; },
  },
};
</script>
