<template>
  <span>{{ prefix }}{{ formattedDisplay }}</span>
</template>

<script>
export default {
  props: {
    value: { type: [Number, String], default: 0 },
    duration: { type: Number, default: 700 },
    prefix: { type: String, default: '' },
    useGrouping: { type: Boolean, default: false },
  },

  data() {
    return {
      display: 0,
    };
  },

  computed: {
    formattedDisplay() {
      return this.useGrouping ? this.display.toLocaleString('en-US') : this.display;
    },
  },

  watch: {
    value: {
      immediate: true,
      handler(newValue) {
        this.animateTo(Number(newValue) || 0);
      },
    },
  },

  beforeUnmount() {
    if (this.frame) cancelAnimationFrame(this.frame);
  },

  methods: {
    animateTo(target) {
      if (this.frame) cancelAnimationFrame(this.frame);

      const start = this.display;
      const delta = target - start;
      if (delta === 0) {
        this.display = target;
        return;
      }

      const startTime = performance.now();
      const step = now => {
        const progress = Math.min((now - startTime) / this.duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        this.display = Math.round(start + delta * eased);

        if (progress < 1) {
          this.frame = requestAnimationFrame(step);
        } else {
          this.display = target;
        }
      };

      this.frame = requestAnimationFrame(step);
    },
  },
};
</script>
