<template>
  <svg class="ol" aria-hidden="true" focusable="false" :width="w" :height="h" :viewBox="'0 0 ' + w + ' ' + h">
    <rect class="ol-tail" v-bind="box" pathLength="100" />
    <rect class="ol-mid" v-bind="box" pathLength="100" />
    <rect class="ol-head" v-bind="box" pathLength="100" />
  </svg>
</template>

<script>
// A thin light that runs round the edge of the bar it sits in. It is drawn on the bar's own outline (its real size and its real corner radius, read from the bar),
// so it bends round the corners instead of cutting across them.
export default {
  data() { return { w: 0, h: 0, r: 0 }; },
  computed: {
    box() { return { x: 1, y: 1, width: Math.max(0, this.w - 2), height: Math.max(0, this.h - 2), rx: Math.max(0, Math.min(this.r - 1, (this.h - 2) / 2)), fill: 'none' }; },
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
  },
  beforeUnmount() { if (this._ro) this._ro.disconnect(); },
};
</script>

<style>
.ol { position: absolute; left: -1px; top: -1px; pointer-events: none; z-index: 2; overflow: visible; filter: drop-shadow(0 0 3px rgba(29, 79, 143, 0.45)); }
.ol rect { stroke-width: 2; stroke-linecap: round; animation: ol-run 9s linear infinite; stroke-dashoffset: var(--o); }
.ol .ol-tail { --o: 0; stroke: rgba(52, 120, 220, 0.38); stroke-dasharray: 26 74; }
.ol .ol-mid { --o: -12; stroke: #1d4f8f; stroke-dasharray: 14 86; }
.ol .ol-head { --o: -22; stroke: #f2a93b; stroke-dasharray: 4 96; }
@keyframes ol-run { to { stroke-dashoffset: calc(var(--o) - 100); } }
html.dark .ol { filter: drop-shadow(0 0 4px rgba(96, 150, 240, 0.7)); }
html.dark .ol .ol-tail { stroke: rgba(96, 150, 240, 0.4); }
html.dark .ol .ol-mid { stroke: #9dc0ff; }
/* a phone: the light runs round the dark blue top bar, in blues only; on larger screens round the white bar */
.ol-rail { display: none; }
.ol-rail .ol-tail { stroke: rgba(52, 120, 232, 0.45); }
.ol-rail .ol-mid { stroke: #2f7cf0; }
.ol-rail .ol-head { stroke: #7db4ff; }
.ol-rail rect { animation-duration: 7s; }
html.dark .ol-rail .ol-mid { stroke: #2f7cf0; }
@media (max-width: 767.98px) { .ol-rail { display: block; } .ol-bar { display: none; } }
@media (prefers-reduced-motion: reduce) { .ol { display: none !important; } }
</style>
