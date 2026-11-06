<template><span class="cn-num">{{ prefix }}{{ shown }}{{ suffix }}</span></template>

<script>
// A number that rises to its value (and to a new value when that changes). It keeps its own state, so the page around it is not redrawn on every frame.
export default {
  props: { value: { type: [Number, String], default: 0 }, duration: { type: Number, default: 900 }, delay: { type: Number, default: 0 }, prefix: { type: String, default: '' }, suffix: { type: String, default: '' } },
  data() { return { cur: 0 }; },
  computed: { shown() { return this.$number(this.cur); } },
  watch: { value: { immediate: true, handler(v) { this.run(Number(v) || 0); } } },
  beforeUnmount() { cancelAnimationFrame(this.raf); clearTimeout(this.timer); },
  methods: {
    run(to) {
      cancelAnimationFrame(this.raf);
      clearTimeout(this.timer);
      const still = !window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches || to === this.cur;
      if (still) { this.cur = to; return; }
      const from = this.cur;
      const go = () => {
        const t0 = performance.now();
        const step = now => {
          const k = Math.min(1, (now - t0) / this.duration);
          this.cur = from + (to - from) * (1 - Math.pow(1 - k, 3));
          if (k < 1) this.raf = requestAnimationFrame(step);
          else this.cur = to;
        };
        this.raf = requestAnimationFrame(step);
      };
      this.delay ? (this.timer = setTimeout(go, this.delay)) : go();
    },
  },
};
</script>
