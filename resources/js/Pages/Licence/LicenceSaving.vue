<template>
  <teleport to="body">
    <div class="lsv" :class="[phase === 'done' ? 'is-done' : 'is-working', 'k-' + kind]" role="status" aria-live="polite">
      <div class="lsv__card">
        <div class="lsv__stage">
          <svg viewBox="0 0 120 120" class="lsv__svg" aria-hidden="true">
            <circle class="lsv__track" cx="60" cy="60" r="50" />
            <circle class="lsv__arc" cx="60" cy="60" r="50" />
            <circle class="lsv__arc lsv__arc--b" cx="60" cy="60" r="38" />
            <g class="lsv__key">
              <circle cx="46" cy="60" r="11" />
              <path d="M57 60h27M76 60v9M68 60v6" />
            </g>
            <path class="lsv__tick" d="M38 62l15 15 30-34" />
          </svg>
          <i v-for="n in 14" :key="n" class="lsv__dot" :style="{ '--a': n * (360 / 14) + 'deg', '--d': 52 + (n % 3) * 12 + 'px', '--h': (n * 47) % 360 }"></i>
        </div>
        <h3>{{ phase === 'done' ? doneTitle : workTitle }}</h3>
        <p v-if="phase === 'done'">{{ doneText }}</p>
        <p v-else class="lsv__step" :key="step">{{ steps[step] }}</p>
        <div class="lsv__bar"><span></span></div>
      </div>
    </div>
  </teleport>
</template>

<script>
export default {
  props: { kind: { type: String, default: 'issue' }, phase: { type: String, default: 'working' } },
  data() { return { step: 0, timer: null }; },
  computed: {
    steps() {
      return this.kind === 'edit'
        ? [this.$t('Checking the new dates...'), this.$t('Re-signing the licence key...'), this.$t('Recording it in the history...'), this.$t('Refreshing the licence pages...')]
        : [this.$t('Generating a unique key...'), this.$t('Signing it with the system seal...'), this.$t('Writing the encrypted licence file...'), this.$t('Recording it in the history...')];
    },
    workTitle() { return this.kind === 'edit' ? this.$t('Saving changes') : this.$t('Generating licence'); },
    doneTitle() { return this.kind === 'edit' ? this.$t('Changes saved') : this.$t('Licence generated'); },
    doneText() { return this.kind === 'edit' ? this.$t('The Licence Manager and WIMS Licence pages are now up to date.') : this.$t('Your new licence is ready.'); },
  },
  mounted() { this.timer = setInterval(() => { this.step = (this.step + 1) % this.steps.length; }, 650); },
  beforeUnmount() { clearInterval(this.timer); },
};
</script>

<style>
.lsv { position: fixed; inset: 0; z-index: 10000; display: grid; place-items: center; padding: 1rem; background: rgba(8, 14, 28, 0.58); backdrop-filter: blur(5px); animation: lsv-fade 0.25s ease both; }
.lsv__card { position: relative; width: min(360px, 100%); padding: 1.6rem 1.4rem 1.3rem; border-radius: 24px; text-align: center; background: #fff; color: #0f172a; box-shadow: 0 30px 80px rgba(2, 6, 23, 0.45), 0 0 0 1px rgba(99, 102, 241, 0.18); animation: lsv-pop 0.45s cubic-bezier(0.2, 1.3, 0.4, 1) both; overflow: hidden; }
.dark .lsv__card { background: #111827; color: #f1f5f9; }
.lsv__card h3 { margin: 0.6rem 0 0.15rem; font-size: 1.15rem; font-weight: 800; }
.lsv__card p { margin: 0; min-height: 2.6em; font-size: 0.85rem; color: #64748b; }
.lsv__step { animation: lsv-step 0.45s ease both; }
.lsv__stage { position: relative; width: 132px; height: 132px; margin: 0 auto; }
.lsv__svg { width: 100%; height: 100%; overflow: visible; }
.lsv__track { fill: none; stroke: rgba(99, 102, 241, 0.14); stroke-width: 6; }
.lsv__arc { fill: none; stroke: #6366f1; stroke-width: 6; stroke-linecap: round; stroke-dasharray: 90 230; transform-origin: 60px 60px; animation: lsv-spin 1.1s linear infinite; }
.lsv__arc--b { stroke: #f59e0b; stroke-width: 4; stroke-dasharray: 50 190; animation-duration: 1.7s; animation-direction: reverse; }
.lsv__key { fill: none; stroke: #4f46e5; stroke-width: 5; stroke-linecap: round; stroke-linejoin: round; transform-origin: 60px 60px; animation: lsv-key 1.6s ease-in-out infinite; }
.dark .lsv__key { stroke: #a5b4fc; }
.lsv__tick { fill: none; stroke: #10b981; stroke-width: 9; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 90; stroke-dashoffset: 90; opacity: 0; }
.lsv__dot { position: absolute; left: 50%; top: 50%; width: 7px; height: 7px; margin: -3.5px; border-radius: 50%; background: hsl(calc(var(--h) * 1deg), 85%, 58%); opacity: 0; }

.lsv__bar { height: 5px; margin-top: 0.9rem; border-radius: 99px; background: rgba(99, 102, 241, 0.14); overflow: hidden; }
.lsv__bar span { display: block; height: 100%; width: 40%; border-radius: inherit; background: linear-gradient(90deg, #6366f1, #f59e0b, #10b981); animation: lsv-sweep 1.1s ease-in-out infinite; }

.lsv.is-done .lsv__arc, .lsv.is-done .lsv__key { animation: none; opacity: 0; transition: opacity 0.2s; }
.lsv.is-done .lsv__track { stroke: rgba(16, 185, 129, 0.35); animation: lsv-ring 0.6s ease both; }
.lsv.is-done .lsv__tick { opacity: 1; animation: lsv-draw 0.55s 0.12s ease forwards; }
.lsv.is-done .lsv__bar span { width: 100%; animation: none; transition: none; }
.lsv.is-done .lsv__card h3 { color: #059669; }
.lsv.is-done .lsv__dot { animation: lsv-burst 0.95s 0.2s cubic-bezier(0.2, 0.8, 0.3, 1) both; }

@keyframes lsv-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes lsv-pop { 0% { opacity: 0; transform: translateY(16px) scale(0.9); } 100% { opacity: 1; transform: none; } }
@keyframes lsv-spin { to { transform: rotate(360deg); } }
@keyframes lsv-key { 0%, 100% { transform: rotate(-14deg) scale(1); } 50% { transform: rotate(14deg) scale(1.08); } }
@keyframes lsv-sweep { 0% { transform: translateX(-110%); } 100% { transform: translateX(260%); } }
@keyframes lsv-step { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@keyframes lsv-draw { to { stroke-dashoffset: 0; } }
@keyframes lsv-ring { 0% { transform: scale(0.9); } 60% { transform: scale(1.08); } 100% { transform: scale(1); } }
@keyframes lsv-burst { 0% { opacity: 1; transform: rotate(var(--a)) translateX(0) scale(1); } 100% { opacity: 0; transform: rotate(var(--a)) translateX(var(--d)) scale(0.3); } }
@media (prefers-reduced-motion: reduce) { .lsv *, .lsv { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; } }
</style>
