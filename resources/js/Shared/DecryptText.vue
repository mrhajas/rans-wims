<template>
  <span class="dt" :aria-label="text">
    <span v-for="(c, i) in chars" :key="i" class="dt-c" :class="{ 'dt-lock': c.done, 'dt-scr': !c.done }" aria-hidden="true">{{ c.ch }}</span>
  </span>
</template>

<script>
// Shows a text as if it were being decrypted: every character flickers through random symbols, then settles on the real one, from left to right.
const NOISE = '#%&*@$+=?ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';

export default {
  props: { text: { type: String, default: '' }, duration: { type: Number, default: 1200 } },
  data() { return { chars: [] }; },
  watch: { text() { this.run(); } },
  mounted() { this.run(); },
  beforeUnmount() { clearInterval(this._t); },
  methods: {
    run() {
      clearInterval(this._t);
      const text = this.text || '';
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce || !text) { this.chars = [...text].map(ch => ({ ch, done: true })); return; }
      const n = text.length, start = Date.now(), step = this.duration / (n + 2);
      const frame = () => {
        const t = Date.now() - start;
        // the first character settles after one step, the last when the time is up
        const locked = Math.min(n, Math.floor(t / step) - 1);
        this.chars = [...text].map((ch, i) => (i < locked ? { ch, done: true } : { ch: NOISE[Math.floor(Math.random() * NOISE.length)], done: false }));
        if (locked >= n) clearInterval(this._t);
      };
      frame();
      this._t = setInterval(frame, 45); // a timer, not animation frames: it also finishes in a tab that is in the background
    },
  },
};
</script>

<style>
.dt-c { display: inline-block; min-width: 0.62em; text-align: center; transition: color 0.2s, text-shadow 0.2s; }
.dt-scr { color: #6366f1; opacity: 0.75; }
.dt-lock { color: inherit; text-shadow: 0 0 10px rgba(16, 185, 129, 0.35); }
</style>
