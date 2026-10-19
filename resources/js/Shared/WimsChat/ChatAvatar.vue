<template>
  <span class="wc-av" :class="[round ? 'is-round' : '', 'sz-' + size]" :style="{ '--hue': hue }" aria-hidden="true">
    <img v-if="src" :src="src" :alt="''" loading="lazy" @error="broken = true" />
    <span v-else class="wc-av-ini">
      <icons v-if="group" name="users" class="wc-av-ico" />
      <template v-else>{{ initials }}</template>
    </span>
    <span v-if="dot" class="wc-dot" :style="{ background: dot.color }" :class="'is-' + dot.key" :title="dot.label"></span>
  </span>
</template>

<script>
// Round avatar: the person's WIMS photo, or initials on a colour taken from their name. The small dot is their presence.
export default {
  props: {
    name: { type: String, default: '' },
    photo: { type: String, default: null },
    presence: { type: Object, default: null },
    group: { type: Boolean, default: false },
    size: { type: String, default: 'md' }, // xs sm md lg xl
    round: { type: Boolean, default: true },
  },
  data() {
    return { broken: false };
  },
  computed: {
    src() {
      return !this.broken && this.photo && !/default-avatar/.test(this.photo) ? this.photo : null;
    },
    initials() {
      const p = String(this.name || '?').trim().split(/\s+/);
      return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
    },
    hue() {
      let h = 0;
      for (const c of String(this.name)) h = (h * 31 + c.charCodeAt(0)) % 360;
      return h;
    },
    dot() {
      const p = this.presence;
      return p && p.key && p.key !== 'none' ? p : null;
    },
  },
};
</script>
