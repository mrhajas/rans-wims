<template>
  <canvas ref="cv" class="aw" aria-hidden="true"></canvas>
</template>

<script>
import { adhan, adhanAnalyser } from './prayerStore';

// Audio waves for the adhan: bars that rise and fall with the real sound while it plays (through the analyser) and gently ripple on their own if the browser
// does not allow the analysis. Flat and calm while the adhan is paused. Drawn on a canvas, stopped when the popup closes.
export default {
  data() { return { raf: 0, t0: performance.now(), levels: [], reduce: false }; },
  mounted() {
    this.reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    this.levels = new Array(40).fill(0.05);
    this.loop();
  },
  beforeUnmount() { cancelAnimationFrame(this.raf); },
  methods: {
    loop() {
      this.draw();
      this.raf = requestAnimationFrame(this.loop);
    },
    draw() {
      const cv = this.$refs.cv;
      if (!cv) return;
      const dpr = window.devicePixelRatio || 1;
      const w = cv.clientWidth; const h = cv.clientHeight;
      if (!w || !h) return;
      if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) { cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); }
      const g = cv.getContext('2d');
      g.setTransform(dpr, 0, 0, dpr, 0, 0);
      g.clearRect(0, 0, w, h);
      const n = this.levels.length;
      const t = (performance.now() - this.t0) / 1000;
      const an = adhan.playing ? adhanAnalyser() : null;
      let data = null;
      if (an) { data = new Uint8Array(an.frequencyBinCount); an.getByteFrequencyData(data); }
      const gap = 3; const bw = Math.max(2, (w - gap * (n - 1)) / n);
      const grad = g.createLinearGradient(0, 0, w, 0);
      grad.addColorStop(0, '#16a34a'); grad.addColorStop(0.55, '#f59e0b'); grad.addColorStop(1, '#ea580c');
      g.fillStyle = grad;
      for (let i = 0; i < n; i++) {
        let target;
        if (!adhan.playing) target = 0.05 + (this.reduce ? 0 : 0.025 * (1 + Math.sin(t * 1.4 + i * 0.5)));
        else if (data) {
          // a voice lives in roughly 100 Hz to 5 kHz: the bars from the middle outwards read that range from low to high (finer steps for the low end),
          // and the quieter high end is lifted a little so the whole row moves
          const d = Math.abs(i - n / 2) / (n / 2);
          const k = 2 + Math.floor(Math.pow(d, 1.5) * 58);
          target = Math.min(1, (data[Math.min(k, data.length - 1)] / 255) * (1.15 + d * 1.1));
        } else target = 0.18 + 0.82 * Math.abs(Math.sin(t * 2.1 + i * 0.45) * Math.sin(t * 1.3 + i * 0.21));
        this.levels[i] += (target - this.levels[i]) * (target > this.levels[i] ? 0.45 : 0.18);
        const bh = Math.max(3, this.levels[i] * h);
        const x = i * (bw + gap);
        const y = (h - bh) / 2;
        const r = Math.min(bw / 2, bh / 2);
        g.beginPath();
        if (g.roundRect) g.roundRect(x, y, bw, bh, r); else g.rect(x, y, bw, bh);
        g.fill();
      }
    },
  },
};
</script>

<style>
.aw { display: block; width: 100%; height: 46px; }
</style>
