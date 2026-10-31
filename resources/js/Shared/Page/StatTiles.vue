<template>
  <section v-if="items && items.length" class="lg-stats print:hidden" :class="{ 'lg-stats-tight': items.length > 4, 'lg-stats-compact': compact, 'lg-stats-live': lively }">
    <component
      :is="item.active !== undefined ? 'button' : 'div'"
      v-for="(item, idx) in items"
      :key="item.label"
      :type="item.active !== undefined ? 'button' : undefined"
      class="lg-stat"
      :style="lively ? { '--d': idx * 70 + 'ms', '--sh': (idx * 900) % 5400 + 'ms' } : null"
      :class="{ 'is-clickable': item.active !== undefined, 'is-active': item.active, 'is-wide': String(format(item.value)).length > 11 }"
      @click="item.active !== undefined && $emit('select', item)"
    >
      <span class="lg-stat-ico" :class="'lg-ico-' + (item.tone || 'navy')"><icons :name="item.icon" class="w-5 h-5" /></span>
      <div class="min-w-0 text-left">
        <div class="lg-stat-value" :class="item.valueClass">{{ format(shown(item)) }}</div>
        <div class="lg-stat-label">{{ item.label }}</div>
      </div>
    </component>
  </section>
</template>

<script>
export default {
  // `lively` (opt in): the cards rise in one after another, their numbers count up, and a soft light sweeps across them now and then
  props: { items: { type: Array, default: () => [] }, compact: { type: Boolean, default: false }, lively: { type: Boolean, default: false } },
  emits: ['select'],
  data() { return { counted: {} }; },
  watch: { items: { handler() { if (this.lively) this.countUp(); }, deep: false } },
  mounted() { if (this.lively) this.countUp(); },
  beforeUnmount() { cancelAnimationFrame(this._raf); clearTimeout(this._done); },
  methods: {
    shown(item) { return this.lively && typeof item.value === 'number' && this.counted[item.label] !== undefined ? this.counted[item.label] : item.value; },
    countUp() {
      const from = {}, to = {};
      (this.items || []).forEach(i => { if (typeof i.value === 'number') { from[i.label] = this.counted[i.label] !== undefined ? this.counted[i.label] : 0; to[i.label] = i.value; } });
      if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { this.counted = { ...to }; return; }
      cancelAnimationFrame(this._raf); clearTimeout(this._done);
      const t0 = performance.now(), dur = 900;
      const step = now => {
        const k = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - k, 3), cur = {};
        Object.keys(to).forEach(l => { cur[l] = Math.round(from[l] + (to[l] - from[l]) * e); });
        this.counted = cur;
        if (k < 1) this._raf = requestAnimationFrame(step);
      };
      this._raf = requestAnimationFrame(step);
      // a tab in the background does not run animation frames: the real numbers are put in anyway
      this._done = setTimeout(() => { this.counted = { ...to }; }, dur + 250);
    },
    format(v) {
      return typeof v === 'number' ? v.toLocaleString() : v;
    },
  },
};
</script>
