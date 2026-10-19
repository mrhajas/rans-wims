<template>
  <div class="arx-donut" :style="{ width: size + 'px', height: size + 'px' }">
    <svg :viewBox="'0 0 ' + size + ' ' + size" role="img" :aria-label="ariaLabel">
      <circle class="arx-donut-track" :cx="c" :cy="c" :r="r" :stroke-width="stroke" fill="none" />
      <circle
        v-for="(a, i) in arcs"
        :key="a.key"
        class="arx-donut-seg"
        :class="{ 'is-dim': active !== null && active !== i }"
        :cx="c" :cy="c" :r="r" fill="none" stroke-linecap="butt"
        :stroke-width="active === i ? stroke + 3 : stroke"
        :style="{ stroke: a.color, strokeDasharray: shown ? a.dash : '0 ' + circ, strokeDashoffset: a.offset, transitionDelay: i * 90 + 'ms' }"
        @mouseenter="active = i" @mouseleave="active = null" @focus="active = i" @blur="active = null" tabindex="0"
      >
        <title>{{ a.label }}: {{ a.text }}</title>
      </circle>
    </svg>
    <div class="arx-donut-center">
      <template v-if="active !== null && arcs[active]">
        <strong>{{ arcs[active].percent }}%</strong>
        <span>{{ arcs[active].label }}</span>
      </template>
      <template v-else>
        <strong>{{ centerValue }}</strong>
        <span>{{ centerLabel }}</span>
      </template>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    // [{ key, label, value, color, text }]  — value drives the arc length, text is what the tooltip shows
    segments: { type: Array, default: () => [] },
    size: { type: Number, default: 188 },
    stroke: { type: Number, default: 18 },
    centerValue: { type: String, default: '' },
    centerLabel: { type: String, default: '' },
  },

  data() {
    return { active: null, shown: false };
  },

  computed: {
    c() { return this.size / 2; },
    r() { return (this.size - this.stroke - 8) / 2; },
    circ() { return 2 * Math.PI * this.r; },
    total() { return this.segments.reduce((a, s) => a + (Number(s.value) || 0), 0) || 1; },
    arcs() {
      const gap = this.segments.length > 1 ? 3 : 0; // spacer between neighbouring arcs
      let used = 0;
      return this.segments.filter(s => Number(s.value) > 0).map(s => {
        const len = (Number(s.value) / this.total) * this.circ;
        const arc = { key: s.key, label: s.label, color: s.color, text: s.text || '', percent: Math.round((Number(s.value) / this.total) * 1000) / 10, dash: Math.max(len - gap, 1) + ' ' + this.circ, offset: -used };
        used += len;
        return arc;
      });
    },
    ariaLabel() {
      return this.centerLabel + ' ' + this.centerValue + ': ' + this.arcs.map(a => a.label + ' ' + a.percent + '%').join(', ');
    },
  },

  mounted() {
    // Draw-in animation: arcs start empty and grow to their length.
    requestAnimationFrame(() => requestAnimationFrame(() => { this.shown = true; }));
  },
};
</script>
