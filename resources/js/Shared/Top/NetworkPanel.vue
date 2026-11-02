<template>
  <div ref="root" class="np" :class="['is-' + net.level, 't-' + net.trend, { 'is-flash': flash }]" :style="{ '--sig': color }">
    <div class="np-row">
      <!-- the gauge: the ring draws itself when the window opens and then follows the reading -->
      <div class="np-gauge" role="img" :aria-label="levelText + ' ' + (net.percent === null ? '' : net.percent + '%')">
        <svg viewBox="0 0 120 120" aria-hidden="true">
          <circle class="trk" cx="60" cy="60" r="52" />
          <circle class="arc" cx="60" cy="60" r="52" :stroke-dasharray="C" :style="{ '--off': offset }" transform="rotate(-90 60 60)" />
          <circle class="orbit" cx="60" cy="8" r="3.4" />
        </svg>
        <div class="mid"><b>{{ net.percent === null ? '--' : shown }}<em v-if="net.percent !== null">%</em></b><span>{{ levelText }}</span></div>
        <i class="halo"></i>
      </div>

      <div class="np-side">
        <div class="np-bars" aria-hidden="true">
          <i v-for="n in 5" :key="n" :class="{ on: lit >= n }" :style="{ '--n': n }"></i>
        </div>
        <span class="np-trend"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path v-if="net.trend === 'up'" d="M12 19V5M5 12l7-7 7 7" /><path v-else-if="net.trend === 'down'" d="M12 5v14M5 12l7 7 7-7" /><path v-else d="M5 12h14" /></svg>{{ trendText }}</span>
        <svg class="np-spark" viewBox="0 0 120 30" preserveAspectRatio="none" aria-hidden="true">
          <polyline :points="line" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" />
          <circle v-for="(l, i) in lostAt" :key="i" :cx="l" cy="26" r="1.8" fill="#ef4444" />
        </svg>
      </div>
    </div>

    <div class="np-tiles">
      <div v-for="(t, i) in tiles" :key="t.key" class="tile" :class="{ 'tile-detail': ['rtt', 'jit', 'loss', 'spd'].includes(t.key) }" :style="{ '--k': i, '--tc': t.color }">
        <span class="ti"><icons :name="t.icon" class="w-3.5 h-3.5" /></span>
        <span class="tx"><small>{{ t.label }}</small><b>{{ t.value }}</b></span>
      </div>
    </div>
    <p class="np-tip"><icons name="sparkles" class="w-3.5 h-3.5" />{{ tip }}</p>
  </div>
</template>

<script>
import { net, LEVELS, startNetworkSignal, watchNetworkClosely } from '@/Core/networkSignal';

// The network part of the status window: a live reading of how good the connection is (see Core/networkSignal.js). Everything on it moves with the reading: the ring, the five
// bars, the number, the trend arrow and the little graph; and when the window is opened they all play in from empty.
export default {
  data() { return { net, C: 2 * Math.PI * 52, shown: 0, raf: 0, io: null, watching: false, flash: false, flashT: 0 }; },
  computed: {
    color() { return (LEVELS[this.net.level] || LEVELS.unknown).color; },
    offset() { return this.C * (1 - (this.net.percent === null ? 0 : this.net.percent) / 100); },
    lit() { const p = this.net.percent; return p === null ? 0 : p >= 80 ? 5 : p >= 60 ? 4 : p >= 40 ? 3 : p >= 20 ? 2 : p > 0 ? 1 : 0; },
    levelText() {
      return { excellent: this.$t('Excellent'), good: this.$t('Good'), fair: this.$t('Fair'), weak: this.$t('Weak'), poor: this.$t('Very weak'), offline: this.$t('No network'), unknown: this.$t('Measuring...') }[this.net.level];
    },
    trendText() { return { up: this.$t('Getting stronger'), down: this.$t('Getting weaker'), flat: this.$t('Steady') }[this.net.trend]; },
    kind() {
      return { wifi: this.$t('Wireless (Wi-Fi)'), ethernet: this.$t('Wired (cable)'), cellular: this.$t('Mobile data'), other: this.$t('Other link') }[this.net.type] || this.$t('Not reported by this browser');
    },
    speed() {
      const e = this.net.effective ? String(this.net.effective).toUpperCase() : '';
      const d = this.net.downlink !== null ? this.net.downlink + ' Mbit/s' : '';
      return [e, d].filter(Boolean).join(' · ') || this.$t('Not reported');
    },
    tiles() {
      const n = this.net;
      const rttC = n.rtt === null ? '#94a3b8' : n.rtt < 120 ? '#16a34a' : n.rtt < 400 ? '#f59e0b' : '#ef4444';
      const jC = n.rtt === null ? '#94a3b8' : n.jitter <= 25 ? '#16a34a' : n.jitter <= 90 ? '#f59e0b' : '#ef4444';
      const lC = n.loss === 0 ? '#16a34a' : n.loss < 10 ? '#f59e0b' : '#ef4444';
      return [
        { key: 'kind', icon: n.type === 'ethernet' ? 'server' : n.type === 'cellular' ? 'mobile' : 'wifi', label: this.$t('Connection'), value: this.kind, color: '#0284c7' },
        { key: 'rtt', icon: 'clock', label: this.$t('Answer time'), value: n.rtt === null ? '-' : n.rtt + ' ms', color: rttC },
        { key: 'jit', icon: 'chart-bar', label: this.$t('Steadiness'), value: n.rtt === null ? '-' : (n.jitter <= 25 ? this.$t('Steady') : n.jitter <= 90 ? this.$t('Slightly uneven') : this.$t('Jumpy')) + ' · ' + n.jitter + ' ms', color: jC },
        { key: 'loss', icon: 'exclamation', label: this.$t('Lost requests'), value: n.loss + '%', color: lC },
        { key: 'spd', icon: 'lightning-bolt', label: this.$t('Speed class'), value: this.speed, color: '#7c3aed' },
      ];
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
      return s.map((v, i) => (v === null ? null : (i * step).toFixed(1) + ',' + (25 - Math.min(1, v / max) * 21).toFixed(1))).filter(Boolean).join(' ');
    },
    lostAt() {
      const n = this.net.samples.length;
      const step = 120 / Math.max(1, n - 1);
      return this.net.samples.map((v, i) => (typeof v === 'number' ? null : i * step)).filter(v => v !== null);
    },
  },
  watch: {
    'net.percent': { immediate: true, handler(to) { this.ease(to === null ? 0 : to); } },
    'net.changedAt'() { this.flash = true; clearTimeout(this.flashT); this.flashT = setTimeout(() => { this.flash = false; }, 900); },
  },
  mounted() {
    startNetworkSignal();
    // quicker measuring, and the opening animation of the number, only while this block can be seen (the status window is open)
    if (typeof IntersectionObserver !== 'undefined') {
      this.io = new IntersectionObserver(es => {
        const on = es.some(e => e.isIntersecting);
        if (on !== this.watching) {
          this.watching = on;
          watchNetworkClosely(on);
          if (on) { this.shown = 0; this.ease(this.net.percent === null ? 0 : this.net.percent); }
        }
      });
      this.io.observe(this.$refs.root);
    }
  },
  beforeUnmount() {
    cancelAnimationFrame(this.raf);
    clearTimeout(this.flashT);
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
        const k = Math.min(1, (now - t0) / 700);
        this.shown = Math.round(from + (to - from) * (1 - (1 - k) ** 3));
        if (k < 1) this.raf = requestAnimationFrame(step);
      };
      this.raf = requestAnimationFrame(step);
      setTimeout(() => { this.shown = to; }, 800); // a hidden tab pauses the frames
    },
  },
};
</script>

<style scoped>
.np { padding: 14px 16px 12px; color: var(--sig); --tc: #64748b; }
.np-row { display: flex; align-items: center; gap: 16px; }
.np-gauge { position: relative; flex: none; width: 112px; height: 112px; }
.np-gauge svg { width: 100%; height: 100%; overflow: visible; }
.trk { fill: none; stroke: rgba(148, 163, 184, 0.22); stroke-width: 9; }
.arc { fill: none; stroke: var(--sig); stroke-width: 9; stroke-linecap: round; stroke-dashoffset: var(--off); transition: stroke-dashoffset 0.9s cubic-bezier(0.2, 0.8, 0.2, 1), stroke 0.5s ease; animation: np-draw 1.1s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; filter: drop-shadow(0 0 6px color-mix(in srgb, var(--sig) 55%, transparent)); }
@keyframes np-draw { from { stroke-dashoffset: 327; } }
.orbit { fill: var(--sig); transform-origin: 60px 60px; animation: np-orbit 4.5s linear infinite; opacity: 0.9; } @keyframes np-orbit { to { transform: rotate(360deg); } }
.is-offline .orbit, .is-unknown .orbit { animation-duration: 9s; opacity: 0.45; }
.halo { position: absolute; inset: 14px; border-radius: 50%; background: radial-gradient(circle, color-mix(in srgb, var(--sig) 20%, transparent), transparent 70%); animation: np-halo 2.6s ease-in-out infinite; pointer-events: none; } @keyframes np-halo { 50% { transform: scale(1.12); opacity: 0.6; } }
.mid { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; line-height: 1.05; }
.mid b { font-size: 1.95rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--sig); transition: color 0.5s ease; } .mid b em { font-style: normal; font-size: 0.85rem; margin-left: 1px; }
.mid span { margin-top: 3px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--sig); transition: color 0.5s ease; }
.np.is-flash .np-gauge { animation: np-pop 0.7s cubic-bezier(0.2, 1.3, 0.3, 1); } @keyframes np-pop { 40% { transform: scale(1.09); } }

.np-side { flex: 1; min-width: 0; display: grid; gap: 8px; }
.np-bars { display: flex; align-items: flex-end; gap: 5px; height: 38px; }
.np-bars i { flex: 1; height: calc(24% + var(--n) * 15%); border-radius: 4px; background: rgba(148, 163, 184, 0.28); transform-origin: bottom; transition: background 0.45s ease, transform 0.45s cubic-bezier(0.2, 1.4, 0.3, 1); animation: np-rise 0.7s cubic-bezier(0.2, 1.2, 0.3, 1) backwards; animation-delay: calc(var(--n) * 90ms); }
.np-bars i.on { background: var(--sig); box-shadow: 0 0 10px -2px var(--sig); animation: np-rise 0.7s cubic-bezier(0.2, 1.2, 0.3, 1) backwards, np-bob 1.9s ease-in-out infinite; animation-delay: calc(var(--n) * 90ms), calc(0.8s + var(--n) * 120ms); }
@keyframes np-rise { from { transform: scaleY(0); } } @keyframes np-bob { 50% { transform: scaleY(0.82); } }
.np-trend { display: inline-flex; align-items: center; gap: 5px; width: max-content; padding: 2px 9px 2px 6px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; color: #64748b; background: rgba(100, 116, 139, 0.12); }
.np-trend svg { width: 13px; height: 13px; }
.t-up .np-trend { color: #15803d; background: rgba(22, 163, 74, 0.14); } .t-up .np-trend svg { animation: np-up 1.1s ease-in-out infinite; }
.t-down .np-trend { color: #b91c1c; background: rgba(220, 38, 38, 0.13); } .t-down .np-trend svg { animation: np-down 1.1s ease-in-out infinite; }
@keyframes np-up { 50% { transform: translateY(-3px); } } @keyframes np-down { 50% { transform: translateY(3px); } }
.np-spark { display: block; width: 100%; height: 30px; color: var(--sig); }
.np-spark polyline { stroke-dasharray: 240; animation: np-line 1.2s ease-out backwards; } @keyframes np-line { from { stroke-dashoffset: 240; } }

.np-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.tile { display: flex; align-items: center; gap: 9px; min-width: 0; padding: 7px 9px; border-radius: 12px; background: color-mix(in srgb, var(--tc) 9%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tc) 22%, transparent); animation: np-tile 0.5s cubic-bezier(0.2, 0.9, 0.3, 1.1) backwards; animation-delay: calc(0.25s + var(--k) * 70ms); }
.tile:first-child { grid-column: 1 / -1; }
.tile .ti { display: grid; place-items: center; flex: none; width: 26px; height: 26px; border-radius: 9px; color: #fff; background: var(--tc); transition: background 0.5s ease; }
.tile .tx { display: grid; min-width: 0; line-height: 1.15; } .tile small { font-size: 0.58rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; } .tile b { font-size: 0.74rem; font-weight: 700; color: #334155; overflow-wrap: anywhere; }
html.dark .tile b { color: #e2e8f0; }
@keyframes np-tile { from { opacity: 0; transform: translateY(8px) scale(0.96); } }
.np-tip { display: flex; align-items: flex-start; gap: 6px; margin: 10px 0 0; font-size: 0.72rem; line-height: 1.4; color: #64748b; } .np-tip svg { flex: none; margin-top: 1px; color: var(--sig); }
/* on a phone the four measurements (answer time, steadiness, lost requests, speed class) are left out so the whole window fits; the score and the connection tile still tell the story */
@media (max-width: 639.98px) { .tile-detail { display: none; } }
@media (prefers-reduced-motion: reduce) { .np *, .np { animation: none !important; } }
</style>
