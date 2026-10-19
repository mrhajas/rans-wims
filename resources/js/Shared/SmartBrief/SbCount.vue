<template><span>{{ shown.toLocaleString() }}</span></template>

<script>
// A number that counts up to its value (skipped when the person prefers reduced motion).
export default {
  props: { value: { type: Number, default: 0 } },
  data() { return { shown: 0 }; },
  watch: { value() { this.run(); } },
  mounted() { this.run(); },
  beforeUnmount() { cancelAnimationFrame(this.raf); },
  methods: {
    run() {
      cancelAnimationFrame(this.raf);
      const to = Number(this.value) || 0;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce || to === 0) { this.shown = to; return; }
      const t0 = performance.now();
      const step = t => {
        const p = Math.min(1, (t - t0) / 900);
        this.shown = Math.round(to * (1 - Math.pow(1 - p, 3)));
        if (p < 1) this.raf = requestAnimationFrame(step);
      };
      this.raf = requestAnimationFrame(step);
    },
  },
};
</script>
