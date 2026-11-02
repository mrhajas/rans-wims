<template>
  <teleport to="body">
    <transition name="ro">
      <div v-if="open" class="ro" role="dialog" aria-modal="true" :aria-label="$t('Optimising')">
        <div class="ro-card" :class="{ 'is-done': finished && !failed, 'is-fail': failed }" :style="{ '--tone': tone }">
          <header class="ro-head">
            <h2>{{ finished ? (failed ? $t('The optimisation stopped') : $t('Optimisation finished')) : title }}</h2>
            <span class="ro-time">{{ clock }}</span>
          </header>

          <!-- the picture of what is happening -->
          <div class="ro-stage" :data-scene="scene" aria-hidden="true">
            <!-- compressing pictures: two plates squeeze a stack of photos smaller -->
            <div v-if="scene === 'compress'" class="sc-compress">
              <i class="plate l"></i>
              <div class="cards"><b v-for="n in 3" :key="n" :style="{ '--n': n }"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3" /><circle cx="9" cy="10" r="1.8" /><path d="M4 18l5-5 4 4 3-3 4 4" /></svg></b></div>
              <i class="plate r"></i>
              <span class="size"><em class="big">2.4 MB</em><em class="small">240 KB</em></span>
            </div>
            <!-- cleaning: a broom sweeps leftovers away -->
            <div v-else-if="scene === 'clean'" class="sc-clean">
              <i v-for="n in 9" :key="n" class="dust" :style="{ '--i': n }"></i>
              <svg class="broom" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M40 6l-8 22" /><path d="M24 30h22l6 24H18z" fill="currentColor" fill-opacity="0.18" /><path d="M26 38l-2 16M34 38l-1 16M42 38l1 16" /></svg>
              <i v-for="n in 4" :key="'s' + n" class="spark" :style="{ '--i': n }"></i>
            </div>
            <!-- data: tables are rebuilt, the rows line up -->
            <div v-else class="sc-data">
              <div class="drum"><i v-for="n in 4" :key="n" :style="{ '--i': n }"></i></div>
              <div class="rows"><b v-for="n in 6" :key="n" :style="{ '--i': n }"></b></div>
              <div class="drum two"><i v-for="n in 4" :key="n" :style="{ '--i': n }"></i></div>
              <span class="pulse"></span>
            </div>
          </div>

          <p class="ro-now">
            <template v-if="!finished"><b>{{ currentTitle }}</b><span class="dots"><i></i><i></i><i></i></span></template>
            <template v-else><b>{{ ratingLabel }}</b><span v-if="savedText"> · {{ savedText }} {{ $t('gained') }}</span></template>
          </p>
          <p v-if="!finished && note" class="ro-note">{{ note }}</p>

          <div class="ro-bar" :aria-valuenow="percent" role="progressbar"><i :style="{ width: percent + '%' }"></i></div>

          <ul class="ro-steps">
            <li v-for="s in steps" :key="s.key" :class="'is-' + s.state">
              <span class="ico">
                <svg v-if="s.state === 'done'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10" /></svg>
                <svg v-else-if="s.state === 'error'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                <i v-else-if="s.state === 'running'" class="spin"></i>
                <i v-else class="idle"></i>
              </span>
              <span class="txt"><b>{{ s.title }}</b><small v-if="s.summary">{{ s.summary }}</small></span>
            </li>
          </ul>

          <p v-if="!finished" class="ro-warn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9L2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>{{ $t('Please keep this window open. Do not refresh or close the page until the process is complete.') }}</p>
          <p v-if="error" class="ro-err">{{ error }}</p>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
// The window shown while an optimisation runs: what is being done right now (compressing pictures, cleaning, optimising data) as a small moving picture, the list of steps with
// their result as each finishes, a progress bar and the time taken. The page that opens it does the work step by step and tells this window how far it has got.
export default {
  props: { open: Boolean, steps: { type: Array, default: () => [] }, finished: Boolean, failed: Boolean, error: { type: String, default: '' }, ratingLabel: { type: String, default: '' }, savedText: { type: String, default: '' }, started: { type: Number, default: 0 } },
  data() { return { now: Date.now(), timer: null }; },
  computed: {
    currentStep() { return this.steps.find(s => s.state === 'running') || null; },
    currentTitle() { return this.currentStep ? this.currentStep.title : this.$t('Getting ready'); },
    scene() {
      const k = this.currentStep ? this.currentStep.key : '';
      if (k === 'images') return 'compress';
      if (k === 'database') return 'data';
      return k ? 'clean' : 'data';
    },
    title() {
      return { compress: this.$t('Compressing pictures'), clean: this.$t('Cleaning up'), data: this.$t('Optimising data') }[this.scene];
    },
    note() {
      return { compress: this.$t('Big photos are saved again smaller. A copy of each original is kept.'), clean: this.$t('Old and expired leftovers are swept away.'), data: this.$t('Tables are checked and the database learns the fastest way to read.') }[this.scene];
    },
    tone() { return { compress: '#0d9488', clean: '#d97706', data: '#4f46e5' }[this.scene]; },
    percent() {
      if (!this.steps.length) return 0;
      if (this.finished) return 100;
      const done = this.steps.filter(s => s.state === 'done' || s.state === 'error').length;
      return Math.min(96, Math.round(((done + 0.45) / this.steps.length) * 100));
    },
    clock() {
      const s = Math.max(0, Math.floor((this.now - (this.started || this.now)) / 1000));
      return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    },
  },
  watch: {
    open(v) {
      clearInterval(this.timer);
      if (v) { this.now = Date.now(); this.timer = setInterval(() => { if (!this.finished) this.now = Date.now(); }, 500); }
    },
  },
  beforeUnmount() { clearInterval(this.timer); },
};
</script>

<style scoped>
.ro { position: fixed; inset: 0; z-index: 2000; display: grid; place-items: center; padding: 16px; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(8px); }
.ro-card { width: min(520px, 100%); max-height: calc(100vh - 32px); overflow: auto; padding: 18px 20px 20px; border-radius: 22px; background: #fff; color: #0f172a; box-shadow: 0 30px 70px -20px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.5) inset; }
html.dark .ro-card { background: #111a2e; color: #e2e8f0; }
.ro-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.ro-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; }
.ro-time { font: 700 0.8rem ui-monospace, Menlo, monospace; padding: 3px 10px; border-radius: 9999px; background: rgba(100, 116, 139, 0.14); }
.ro-stage { position: relative; height: 150px; margin: 6px 0 4px; border-radius: 18px; overflow: hidden; background: radial-gradient(circle at 50% 120%, color-mix(in srgb, var(--tone) 22%, transparent), transparent 70%), rgba(100, 116, 139, 0.07); color: var(--tone); }
.ro-now { margin: 8px 0 0; text-align: center; font-size: 0.95rem; } .ro-now b { font-weight: 800; }
.ro-note { margin: 2px 0 0; text-align: center; font-size: 0.78rem; opacity: 0.7; }
.dots { display: inline-flex; gap: 4px; margin-left: 8px; vertical-align: middle; } .dots i { width: 6px; height: 6px; border-radius: 50%; background: var(--tone); animation: ro-dot 1.1s ease-in-out infinite; } .dots i:nth-child(2) { animation-delay: 0.18s; } .dots i:nth-child(3) { animation-delay: 0.36s; }
@keyframes ro-dot { 0%, 80%, 100% { transform: scale(0.5); opacity: 0.4; } 40% { transform: scale(1.15); opacity: 1; } }
.ro-bar { height: 8px; margin: 12px 0; border-radius: 9999px; background: rgba(100, 116, 139, 0.18); overflow: hidden; }
.ro-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--tone), #22d3ee, var(--tone)); background-size: 200% 100%; animation: ro-flow 1.6s linear infinite; transition: width 0.6s ease; }
@keyframes ro-flow { to { background-position: -200% 0; } }
.ro-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.ro-steps li { display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; border-radius: 12px; background: rgba(100, 116, 139, 0.07); }
.ro-steps li.is-running { background: color-mix(in srgb, var(--tone) 12%, transparent); box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--tone) 45%, transparent); }
.ro-steps .ico { display: grid; place-items: center; flex: none; width: 22px; height: 22px; margin-top: 1px; border-radius: 50%; }
.ro-steps li.is-done .ico { background: #16a34a; color: #fff; } .ro-steps li.is-error .ico { background: #dc2626; color: #fff; }
.ro-steps .ico svg { width: 13px; height: 13px; }
.ro-steps .idle { width: 10px; height: 10px; border-radius: 50%; background: rgba(100, 116, 139, 0.5); }
.ro-steps .spin { width: 16px; height: 16px; border-radius: 50%; border: 2.5px solid color-mix(in srgb, var(--tone) 25%, transparent); border-top-color: var(--tone); animation: ro-spin 0.8s linear infinite; }
@keyframes ro-spin { to { transform: rotate(360deg); } }
.ro-steps .txt { display: grid; gap: 1px; min-width: 0; } .ro-steps b { font-size: 0.84rem; font-weight: 700; } .ro-steps small { font-size: 0.74rem; opacity: 0.7; }
.ro-warn { display: flex; align-items: flex-start; gap: 8px; margin: 12px 0 0; padding: 9px 12px; border-radius: 12px; font-size: 0.78rem; font-weight: 600; line-height: 1.4; background: rgba(245, 158, 11, 0.12); color: #92400e; box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.35); }
html.dark .ro-warn { color: #fcd34d; } .ro-warn svg { flex: none; width: 18px; height: 18px; }
.ro-err { margin: 10px 0 0; color: #dc2626; font-weight: 600; font-size: 0.82rem; }
.ro-card.is-done .ro-bar i { animation: none; background: #16a34a; } .ro-card.is-fail .ro-bar i { animation: none; background: #dc2626; }

/* compressing */
.sc-compress { position: absolute; inset: 0; display: grid; place-items: center; }
.sc-compress .cards { position: relative; display: flex; gap: 10px; align-items: center; animation: sc-squeeze 2.6s ease-in-out infinite; }
.sc-compress .cards b { display: grid; place-items: center; width: 54px; height: 66px; border-radius: 12px; background: #fff; box-shadow: 0 6px 14px -6px rgba(15, 23, 42, 0.35); transform: rotate(calc((var(--n) - 2) * 5deg)); } html.dark .sc-compress .cards b { background: #1e293b; }
.sc-compress .cards svg { width: 30px; height: 30px; }
.sc-compress .plate { position: absolute; top: 24px; bottom: 24px; width: 14px; border-radius: 8px; background: currentColor; opacity: 0.85; }
.sc-compress .plate.l { left: calc(50% - 128px); animation: sc-plate-l 2.6s ease-in-out infinite; } .sc-compress .plate.r { right: calc(50% - 128px); animation: sc-plate-r 2.6s ease-in-out infinite; }
@keyframes sc-plate-l { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(46px); } } @keyframes sc-plate-r { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(-46px); } }
@keyframes sc-squeeze { 0%, 100% { transform: scaleX(1); } 50% { transform: scaleX(0.52); } }
.sc-compress .size { position: absolute; bottom: 10px; left: 0; right: 0; text-align: center; font-size: 0.8rem; font-weight: 800; } .sc-compress .size em { font-style: normal; position: absolute; left: 0; right: 0; }
.sc-compress .size .big { animation: sc-big 2.6s ease-in-out infinite; } .sc-compress .size .small { animation: sc-small 2.6s ease-in-out infinite; color: #16a34a; }
@keyframes sc-big { 0%, 35% { opacity: 1; } 55%, 100% { opacity: 0; } } @keyframes sc-small { 0%, 40% { opacity: 0; } 60%, 95% { opacity: 1; } 100% { opacity: 0; } }

/* cleaning */
.sc-clean { position: absolute; inset: 0; }
.sc-clean .dust { position: absolute; top: calc(52% + (var(--i) % 3) * 12px); left: calc(14% + var(--i) * 8%); width: 9px; height: 9px; border-radius: 3px; background: #94a3b8; animation: sc-dust 3.2s ease-in-out infinite; animation-delay: calc(var(--i) * 0.12s); }
@keyframes sc-dust { 0%, 25% { transform: translate(0, 0) rotate(0); opacity: 1; } 55% { transform: translate(90px, -26px) rotate(160deg); opacity: 0; } 100% { transform: translate(90px, -26px); opacity: 0; } }
.sc-clean .broom { position: absolute; bottom: 10px; left: 8%; width: 82px; height: 82px; animation: sc-broom 3.2s ease-in-out infinite; transform-origin: 70% 90%; }
@keyframes sc-broom { 0% { transform: translateX(0) rotate(-14deg); } 55% { transform: translateX(210px) rotate(8deg); } 100% { transform: translateX(0) rotate(-14deg); } }
.sc-clean .spark { position: absolute; top: calc(30% + var(--i) * 9%); left: calc(16% + var(--i) * 17%); width: 8px; height: 8px; background: #facc15; clip-path: polygon(50% 0, 62% 38%, 100% 50%, 62% 62%, 50% 100%, 38% 62%, 0 50%, 38% 38%); animation: sc-spark 1.6s ease-in-out infinite; animation-delay: calc(var(--i) * 0.3s); }
@keyframes sc-spark { 0%, 100% { transform: scale(0); opacity: 0; } 50% { transform: scale(1.5); opacity: 1; } }

/* data */
.sc-data { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: 22px; }
.sc-data .drum { display: grid; gap: 3px; width: 48px; padding: 6px; border-radius: 14px; border: 2.5px solid currentColor; background: color-mix(in srgb, currentColor 10%, transparent); }
.sc-data .drum i { display: block; height: 9px; border-radius: 4px; background: currentColor; opacity: 0.55; animation: sc-fill 2.2s ease-in-out infinite; animation-delay: calc(var(--i) * 0.2s); }
.sc-data .drum.two i { animation-direction: reverse; }
@keyframes sc-fill { 0%, 100% { transform: scaleX(0.35); opacity: 0.35; } 50% { transform: scaleX(1); opacity: 0.9; } }
.sc-data .rows { display: grid; gap: 4px; } .sc-data .rows b { display: block; width: 62px; height: 7px; border-radius: 4px; background: currentColor; animation: sc-row 2.2s ease-in-out infinite; animation-delay: calc(var(--i) * 0.14s); }
@keyframes sc-row { 0%, 100% { transform: translateX(-16px); opacity: 0.3; } 50% { transform: translateX(16px); opacity: 1; } }
.sc-data .pulse { position: absolute; width: 150px; height: 150px; border-radius: 50%; border: 2px solid currentColor; opacity: 0; animation: sc-pulse 2.2s ease-out infinite; }
@keyframes sc-pulse { 0% { transform: scale(0.3); opacity: 0.5; } 100% { transform: scale(1.5); opacity: 0; } }

.ro-enter-active, .ro-leave-active { transition: opacity 0.25s ease; } .ro-enter-from, .ro-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) { .ro-stage *, .ro-bar i, .dots i, .spin { animation: none !important; } }
</style>
