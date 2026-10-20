<template>
  <canvas ref="cv" class="aw" aria-hidden="true"></canvas>
</template>

<script>
import { adhan, adhanAnalyser } from './prayerStore';

// Audio waves for the adhan: three layered, flowing voice waves (a mirrored sound shape) whose height follows the real sound while it plays (through the analyser,
// each part of the row following its own part of the voice) and that move with a lively rhythm of their own if the browser does not allow the analysis. When the adhan
// is not playing the waves rest as a calm, thin ripple. Drawn on a canvas and stopped when the popup closes.
const S = 56;

export default {
  data() { return { raf: 0, t0: performance.now(), levels: new Array(S).fill(0.05), reduce: false }; },
  mounted() {
    this.reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    this.loop();
  },
  beforeUnmount() { cancelAnimationFrame(this.raf); },
  methods: {
    loop() { this.draw(); this.raf = requestAnimationFrame(this.loop); },
    // the level (0 to 1) of every slice of the row
    spectrum(t) {
      const playing = adhan.playing;
      const an = playing ? adhanAnalyser() : null;
      const out = new Array(S);
      if (an) {
        const data = new Uint8Array(an.frequencyBinCount);
        an.getByteFrequencyData(data);
        // the voice sits roughly between 100 Hz and 5 kHz: spread that range over the row, lifting the quieter high end, and never let the row go dead
        const top = Math.floor(data.length * 0.45);
        let sum = 0;
        for (let k = 1; k < top; k++) sum += data[k];
        const energy = Math.min(1, (sum / Math.max(1, top - 1) / 255) * 2.4); // how loud the whole voice is right now: it lifts every part of the row, so the waves move as one
        for (let i = 0; i < S; i++) {
          const u = i / (S - 1);
          const k = Math.min(data.length - 1, 2 + Math.floor(Math.pow(u, 1.2) * top));
          out[i] = Math.min(1, 0.12 + energy * 0.5 * (0.7 + 0.3 * Math.sin(t * 2 + i * 0.45)) + (data[k] / 255) * (0.9 + u * 0.9));
        }
      } else if (playing) {
        for (let i = 0; i < S; i++) out[i] = 0.3 + 0.7 * Math.abs(Math.sin(t * 2.1 + i * 0.33) * Math.sin(t * 1.3 + i * 0.17 + 1) + 0.25 * Math.sin(t * 3.7 + i * 0.5));
      } else {
        for (let i = 0; i < S; i++) out[i] = 0.07 + (this.reduce ? 0 : 0.035 * (1 + Math.sin(t * 1.2 + i * 0.4)));
      }
      return out;
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
      const t = this.reduce ? 0 : (performance.now() - this.t0) / 1000;
      const target = this.spectrum(t);
      for (let i = 0; i < S; i++) this.levels[i] += (target[i] - this.levels[i]) * (target[i] > this.levels[i] ? 0.5 : 0.14);
      const mid = h / 2;
      const level = u => { const f = Math.min(S - 1, Math.max(0, u * (S - 1))); const a = Math.floor(f); const b = Math.min(S - 1, a + 1); return this.levels[a] + (this.levels[b] - this.levels[a]) * (f - a); };
      const grad = g.createLinearGradient(0, 0, w, 0);
      grad.addColorStop(0, '#16a34a'); grad.addColorStop(0.5, '#f59e0b'); grad.addColorStop(1, '#ea580c');
      const layers = [{ a: 1, f: 2.2, s: 2.2, alpha: 0.9 }, { a: 0.72, f: 3.4, s: -1.6, alpha: 0.5 }, { a: 0.5, f: 5.1, s: 1.1, alpha: 0.3 }];
      g.fillStyle = grad;
      layers.forEach((L, li) => {
        g.globalAlpha = L.alpha;
        g.beginPath();
        const step = 3;
        for (let x = 0; x <= w; x += step) {
          const u = x / w;
          const swing = 0.55 + 0.45 * Math.sin(u * Math.PI * 2 * L.f + t * L.s + li);
          const amp = Math.max(1.2, level(u) * L.a * swing * (mid - 2) * Math.sin(Math.PI * Math.min(1, u * 1.0 + 0.0) ) ** 0.35);
          if (x === 0) g.moveTo(x, mid - amp); else g.lineTo(x, mid - amp);
        }
        for (let x = w; x >= 0; x -= step) {
          const u = x / w;
          const swing = 0.55 + 0.45 * Math.sin(u * Math.PI * 2 * L.f + t * L.s + li);
          const amp = Math.max(1.2, level(u) * L.a * swing * (mid - 2) * Math.sin(Math.PI * Math.min(1, u * 1.0 + 0.0) ) ** 0.35);
          g.lineTo(x, mid + amp);
        }
        g.closePath();
        g.fill();
      });
      g.globalAlpha = 0.55;
      g.fillRect(0, mid - 0.5, w, 1);
      g.globalAlpha = 1;
    },
  },
};
</script>

<style>
.aw { display: block; width: 100%; height: 64px; }
</style>
