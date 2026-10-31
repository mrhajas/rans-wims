<template>
  <svg class="ol" aria-hidden="true" focusable="false" :width="w" :height="h" :viewBox="'0 0 ' + w + ' ' + h">
    <rect v-for="l in layers" :key="l.i" ref="seg" v-bind="box" pathLength="100" :stroke-dasharray="l.len + ' ' + (100 - l.len)" :style="{ stroke: l.color }" :data-off="l.len - HEAD" />
  </svg>
</template>

<script>
const N = 14, LONG = 34, SHORT = 2, HEAD = 40, WARM = 0.8;

// A thin light that runs round the edge of the bar it sits in. It is drawn on the bar's own outline (its real size and its real corner radius, read from the bar),
// so it bends round the corners instead of cutting across them. Fourteen short-to-long strokes end at the same point, so the light fades smoothly from a faint tail
// to a bright head, and its colour moves from deep blue through light blue to a touch of orange at the tip (the colours come from --ol-a, --ol-b, --ol-c).
export default {
  props: { seconds: { type: Number, default: 9 } },
  data() { return { w: 0, h: 0, r: 0, HEAD }; },
  computed: {
    box() { return { x: 1, y: 1, width: Math.max(0, this.w - 2), height: Math.max(0, this.h - 2), rx: Math.max(0, Math.min(this.r - 1, (this.h - 2) / 2)), fill: 'none' }; },
    layers() {
      const out = [];
      for (let i = 0; i < N; i++) {
        const t = i / (N - 1), len = LONG - (LONG - SHORT) * t;
        const color = t <= WARM
          ? 'color-mix(in srgb, var(--ol-b) ' + (t / WARM * 100).toFixed(0) + '%, var(--ol-a))'
          : 'color-mix(in srgb, var(--ol-c) ' + ((t - WARM) / (1 - WARM) * 100).toFixed(0) + '%, var(--ol-b))';
        out.push({ i, len: +len.toFixed(2), color });
      }
      return out;
    },
  },
  mounted() {
    const p = this.$el.parentElement;
    if (!p) return;
    const measure = () => {
      this.w = p.offsetWidth; this.h = p.offsetHeight;
      this.r = parseFloat(getComputedStyle(p).borderTopLeftRadius) || 0;
    };
    measure();
    if (window.ResizeObserver) { this._ro = new ResizeObserver(measure); this._ro.observe(p); }
    this.run();
  },
  beforeUnmount() { if (this._ro) this._ro.disconnect(); (this._an || []).forEach(a => a.cancel()); },
  methods: {
    run() {
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const els = [].concat(this.$refs.seg || []);
      if (reduce || !els.length || !els[0].animate) return;
      this._an = els.map(el => {
        const o = parseFloat(el.dataset.off);
        return el.animate([{ strokeDashoffset: o }, { strokeDashoffset: o - 100 }], { duration: this.seconds * 1000, iterations: Infinity, easing: 'linear' });
      });
    },
  },
};
</script>

<style>
.ol { --ol-a: #1d4f8f; --ol-b: #5b9bf0; --ol-c: #f2a93b; position: absolute; left: -1px; top: -1px; pointer-events: none; z-index: 2; overflow: visible; filter: drop-shadow(0 0 4px rgba(29, 79, 143, 0.5)) blur(0.7px); }
.ol rect { stroke-width: 2; stroke-linecap: round; stroke-opacity: 0.26; }
html.dark .ol { --ol-a: #4f86e0; --ol-b: #9dc0ff; filter: drop-shadow(0 0 5px rgba(96, 150, 240, 0.7)) blur(0.7px); }
/* a phone: the light runs round the dark blue top bar, in blues only; on larger screens round the white bar */
.ol-rail { display: none; --ol-a: #1f5fd0; --ol-b: #5fa0ff; --ol-c: #b5d6ff; }
html.dark .ol-rail { --ol-a: #1f5fd0; --ol-b: #5fa0ff; }
@media (max-width: 767.98px) { .ol-rail { display: block; } .ol-bar { display: none; } }
@media (prefers-reduced-motion: reduce) { .ol { display: none !important; } }
</style>
