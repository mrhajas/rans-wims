<template>
  <svg class="ol" aria-hidden="true" focusable="false" :width="w + 2 * PAD" :height="h + 2 * PAD" :viewBox="'0 0 ' + (w + 2 * PAD) + ' ' + (h + 2 * PAD)" :style="{ left: -(PAD + bw) + 'px', top: -(PAD + bw) + 'px' }">
    <rect v-for="l in layers" :key="l.i" ref="seg" v-bind="box" :stroke-dasharray="l.len + ' ' + Math.max(1, per - l.len)" :style="{ stroke: l.color }" :data-len="l.len" />
  </svg>
</template>

<script>
const N = 14, SHORT = 2, WARM = 0.8, PAD = 5;

// A thin light that runs round the edge of the bar it sits in. It is drawn on the bar's own outline (its real size and its real corner radius, read from the bar),
// so it bends round the corners instead of cutting across them. The light has a fixed length in pixels (not a share of the bar), so it stays short on a wide bar. Fourteen short-to-long strokes end at the same point, so the light fades smoothly from a faint tail
// to a bright head, and its colour moves from deep blue through light blue to a touch of orange at the tip (the colours come from --ol-a, --ol-b, --ol-c).
export default {
  props: { length: { type: Number, default: 120 }, speed: { type: Number, default: 200 }, out: { type: Number, default: 2 } },
  data() { return { w: 0, h: 0, r: 0, bw: 0, PAD }; },
  computed: {
    // the outline the light runs on: the bar's own edge pushed outwards by `out` pixels, so the light sits just outside the container (PAD is the room round the bar for the stroke and its blur)
    box() { const o = this.out, rx = Math.max(0, Math.min(this.r + o, (this.h + 2 * o) / 2)); return { x: PAD - o, y: PAD - o, width: Math.max(0, this.w + 2 * o), height: Math.max(0, this.h + 2 * o), rx, fill: 'none' }; },
    // the length of the outline of the bar, in pixels
    per() { const rx = this.box.rx, W = this.box.width, H = this.box.height; return Math.max(1, 2 * (W - 2 * rx) + 2 * (H - 2 * rx) + 2 * Math.PI * rx); },
    layers() {
      const out = [];
      for (let i = 0; i < N; i++) {
        const t = i / (N - 1), len = this.length - (this.length - SHORT) * t;
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
      const cs = getComputedStyle(p);
      this.r = parseFloat(cs.borderTopLeftRadius) || 0;
      this.bw = parseFloat(cs.borderTopWidth) || 0;
    };
    measure();
    if (window.ResizeObserver) { this._ro = new ResizeObserver(measure); this._ro.observe(p); }
    this.$nextTick(() => this.run());
  },
  watch: { per() { this.$nextTick(() => this.run()); } },
  beforeUnmount() { if (this._ro) this._ro.disconnect(); (this._an || []).forEach(a => a.cancel()); },
  methods: {
    run() {
      (this._an || []).forEach(a => a.cancel());
      this._an = [];
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const els = [].concat(this.$refs.seg || []);
      if (reduce || !els.length || !els[0].animate) return;
      const per = this.per, head = per * 0.4;
      this._an = els.map(el => {
        const o = parseFloat(el.dataset.len) - head;
        return el.animate([{ strokeDashoffset: o }, { strokeDashoffset: o - per }], { duration: per / this.speed * 1000, iterations: Infinity, easing: 'linear' });
      });
    },
  },
};
</script>

<style>
.ol { --ol-a: #1d4f8f; --ol-b: #5b9bf0; --ol-c: #f2a93b; position: absolute; pointer-events: none; z-index: 2; overflow: visible; filter: drop-shadow(0 0 6px rgba(29, 79, 143, 0.6)) blur(1.6px); }
.ol rect { stroke-width: 1.4; stroke-linecap: round; stroke-opacity: 0.26; }
html.dark .ol { --ol-a: #4f86e0; --ol-b: #9dc0ff; filter: drop-shadow(0 0 7px rgba(96, 150, 240, 0.8)) blur(1.6px); }
/* a phone: the light runs round the dark blue top bar, in blues only; on larger screens round the white bar */
.ol-rail { display: none; --ol-a: #1f5fd0; --ol-b: #5fa0ff; --ol-c: #b5d6ff; }
html.dark .ol-rail { --ol-a: #1f5fd0; --ol-b: #5fa0ff; }
@media (max-width: 767.98px) { .ol-rail { display: block; } .ol-bar { display: none; } }
@media (prefers-reduced-motion: reduce) { .ol { display: none !important; } }
</style>
