<template>
  <div ref="root" class="np" :class="'is-' + net.level" :style="{ '--sig': color }">
    <div class="np-top">
      <span class="np-badge"><i class="ring r1"></i><i class="ring r2"></i><network-icon :percent="net.percent" :level="net.level" :type="net.type" class="np-ico" /></span>
      <div class="np-main">
        <small>{{ $t('Network signal') }}</small>
        <b class="np-pct">{{ net.percent === null ? '--' : shown }}<em v-if="net.percent !== null">%</em></b>
      </div>
      <span class="np-level">{{ levelText }}</span>
    </div>

    <div class="np-meter" role="img" :aria-label="levelText + ' ' + (net.percent === null ? '' : net.percent + '%')">
      <i v-for="n in 20" :key="n" :class="{ on: net.percent !== null && n <= Math.round(net.percent / 5) }" :style="{ '--n': n }"></i>
    </div>

    <svg class="np-spark" viewBox="0 0 120 28" preserveAspectRatio="none" aria-hidden="true">
      <polyline :points="line" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />
      <circle v-for="(l, i) in lostAt" :key="i" :cx="l" cy="25" r="1.7" fill="#ef4444" />
    </svg>

    <div class="np-grid">
      <div><small>{{ $t('Connection') }}</small><b>{{ kind }}</b></div>
      <div><small>{{ $t('Answer time') }}</small><b>{{ net.rtt === null ? '-' : net.rtt + ' ms' }}</b></div>
      <div><small>{{ $t('Steadiness') }}</small><b>{{ net.rtt === null ? '-' : (net.jitter <= 25 ? $t('Steady') : net.jitter <= 90 ? $t('Slightly uneven') : $t('Jumpy')) + ' (' + net.jitter + ' ms)' }}</b></div>
      <div><small>{{ $t('Lost requests') }}</small><b>{{ net.loss }}%</b></div>
      <div><small>{{ $t('Speed class') }}</small><b>{{ speed }}</b></div>
    </div>
    <p class="np-tip">{{ tip }}</p>
  </div>
</template>

<script>
import NetworkIcon from '@/Shared/Top/NetworkIcon.vue';
import { net, LEVELS, startNetworkSignal, watchNetworkClosely } from '@/Core/networkSignal';

// The network part of the status window: a live reading of how good the connection is (see Core/networkSignal.js), with a meter, the recent answer times and what they mean.
export default {
  components: { NetworkIcon },
  data() { return { net, shown: 0, raf: 0, io: null, watching: false }; },
  computed: {
    color() { return (LEVELS[this.net.level] || LEVELS.unknown).color; },
    levelText() {
      return { excellent: this.$t('Excellent'), good: this.$t('Good'), fair: this.$t('Fair'), weak: this.$t('Weak'), poor: this.$t('Very weak'), offline: this.$t('No network'), unknown: this.$t('Measuring...') }[this.net.level];
    },
    kind() {
      const t = { wifi: this.$t('Wireless (Wi-Fi)'), ethernet: this.$t('Wired (cable)'), cellular: this.$t('Mobile data'), other: this.$t('Other link') }[this.net.type];
      return t || this.$t('Not reported by this browser');
    },
    speed() {
      const e = this.net.effective ? String(this.net.effective).toUpperCase() : '';
      const d = this.net.downlink !== null ? this.net.downlink + ' Mbit/s' : '';
      return [e, d].filter(Boolean).join(' · ') || this.$t('Not reported by this browser');
    },
    tip() {
      return {
        excellent: this.$t('Everything will feel instant.'),
        good: this.$t('Pages and saves are quick.'),
        fair: this.$t('Usable. Large lists and uploads may take a moment.'),
        weak: this.$t('Slow. Move closer to the router or switch to a cable if you can.'),
        poor: this.$t('Very weak. Saving may fail; WIMS keeps trying and falls back to Offline Read Mode if the connection is lost.'),
        offline: this.$t('This device has no network. WIMS is in Offline Read Mode.'),
        unknown: this.$t('Measuring the connection...'),
      }[this.net.level];
    },
    line() {
      const s = this.net.samples.map(v => (typeof v === 'number' ? v : null));
      if (s.length < 2) return '';
      const max = Math.max(200, ...s.filter(v => v !== null));
      const step = 120 / Math.max(1, s.length - 1);
      return s.map((v, i) => (v === null ? null : (i * step).toFixed(1) + ',' + (24 - Math.min(1, v / max) * 20).toFixed(1))).filter(Boolean).join(' ');
    },
    lostAt() {
      const n = this.net.samples.length;
      const step = 120 / Math.max(1, n - 1);
      return this.net.samples.map((v, i) => (typeof v === 'number' ? null : i * step)).filter(v => v !== null);
    },
  },
  watch: {
    'net.percent': { immediate: true, handler(to) { this.ease(to === null ? 0 : to); } },
  },
  mounted() {
    startNetworkSignal();
    // the quicker measuring runs only while this block can be seen (the status window is open)
    if (typeof IntersectionObserver !== 'undefined') {
      this.io = new IntersectionObserver(es => {
        const on = es.some(e => e.isIntersecting);
        if (on !== this.watching) { this.watching = on; watchNetworkClosely(on); }
      });
      this.io.observe(this.$refs.root);
    }
  },
  beforeUnmount() {
    cancelAnimationFrame(this.raf);
    if (this.io) this.io.disconnect();
    if (this.watching) watchNetworkClosely(false);
  },
  methods: {
    // the number counts up or down to the new reading
    ease(to) {
      cancelAnimationFrame(this.raf);
      const from = this.shown;
      const t0 = performance.now();
      const step = now => {
        const k = Math.min(1, (now - t0) / 600);
        this.shown = Math.round(from + (to - from) * (1 - (1 - k) ** 3));
        if (k < 1) this.raf = requestAnimationFrame(step);
      };
      this.raf = requestAnimationFrame(step);
      setTimeout(() => { this.shown = to; }, 700); // a hidden tab pauses the frames
    },
  },
};
</script>

<style scoped>
.np { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: var(--sig); }
.np-top { display: flex; align-items: center; gap: 12px; }
.np-badge { position: relative; display: grid; place-items: center; flex: none; width: 44px; height: 44px; border-radius: 14px; background: color-mix(in srgb, var(--sig) 14%, transparent); }
.np-ico { width: 24px; height: 24px; position: relative; z-index: 1; }
.np-badge .ring { position: absolute; inset: 0; border-radius: 14px; border: 2px solid var(--sig); opacity: 0; animation: np-ring 2.4s ease-out infinite; } .np-badge .r2 { animation-delay: 1.2s; }
.is-offline .np-badge .ring, .is-unknown .np-badge .ring { animation-duration: 3.6s; }
@keyframes np-ring { 0% { transform: scale(0.85); opacity: 0.55; } 100% { transform: scale(1.7); opacity: 0; } }
.np-main { display: grid; line-height: 1.1; min-width: 0; flex: 1; }
.np-main small { font-size: 0.62rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; }
.np-pct { font-size: 1.7rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--sig); transition: color 0.5s ease; } .np-pct em { font-style: normal; font-size: 0.9rem; margin-left: 2px; }
.np-level { padding: 3px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #fff; background: var(--sig); transition: background 0.5s ease; white-space: nowrap; }
.np-meter { display: flex; gap: 3px; height: 14px; margin: 10px 0 6px; align-items: flex-end; }
.np-meter i { flex: 1; height: 40%; border-radius: 3px; background: #e2e8f0; transition: background 0.4s ease, height 0.4s ease; }
.np-meter i.on { background: var(--sig); height: calc(40% + var(--n) * 3%); animation: np-bar 1.6s ease-in-out infinite; animation-delay: calc(var(--n) * 60ms); }
@keyframes np-bar { 0%, 100% { transform: scaleY(1); } 50% { transform: scaleY(0.72); } }
.np-spark { display: block; width: 100%; height: 28px; color: var(--sig); opacity: 0.9; }
.np-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 12px; margin-top: 8px; }
.np-grid div { display: grid; line-height: 1.2; min-width: 0; } .np-grid small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #94a3b8; } .np-grid b { font-size: 0.76rem; font-weight: 700; color: #334155; overflow-wrap: anywhere; }
.np-tip { margin: 8px 0 0; font-size: 0.72rem; line-height: 1.4; color: #64748b; }
@media (prefers-reduced-motion: reduce) { .np-badge .ring, .np-meter i.on { animation: none; } }
</style>
