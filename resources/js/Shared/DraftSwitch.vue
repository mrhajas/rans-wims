<template>
  <div class="dsw" :class="{ 'is-draft': checked, 'is-final': !checked, 'is-shaking': shaking, 'is-moving': moving, 'has-error': !!error || invalid }">
    <button
      :id="id"
      type="button"
      role="switch"
      class="dsw-track"
      :aria-checked="checked ? 'false' : 'true'"
      :aria-label="$t('Record status')"
      :disabled="disabled"
      @click="toggle"
      @keydown.left.prevent="request(true)"
      @keydown.right.prevent="request(false)"
    >
      <!-- the two sides: each shows its word and icon; the one the thumb sits on is covered by it -->
      <span class="dsw-side dsw-left" :class="{ on: checked }"><icons name="edit" class="dsw-s-ico" /><b>{{ draftText || $t('Draft') }}</b></span>
      <span class="dsw-side dsw-right" :class="{ on: !checked }"><icons name="tick" class="dsw-s-ico" /><b>{{ finalText || $t('Final') }}</b></span>

      <!-- the thumb glides, stretches a little while it travels and settles with a soft bounce -->
      <span class="dsw-thumb" aria-hidden="true">
        <span class="dsw-thumb-in">
          <icons :key="checked ? 'd' : 'f'" :name="checked ? 'edit' : 'tick'" class="dsw-ico" />
          <b class="dsw-now">{{ checked ? draftText || $t('Draft') : finalText || $t('Final') }}</b>
        </span>
        <!-- a ring and a few sparks leave the thumb every time it lands -->
        <span v-if="burst" :key="burst" class="dsw-burst"><i class="dsw-ring"></i><i v-for="n in 8" :key="n" class="dsw-spark" :style="{ '--a': n * 45 + 'deg', '--d': (n % 3) * 0.04 + 's' }"></i></span>
      </span>
      <span class="dsw-gloss" aria-hidden="true"></span>
    </button>
    <transition name="dsw-fade" mode="out-in">
      <p :key="checked ? 'd' : 'f'" class="dsw-hint" aria-live="polite">{{ checked ? draftHint || $t('Saved as a draft. Nothing is applied until you switch it to Final.') : finalHint || $t('Saved as final. It takes effect as soon as you save.') }}</p>
    </transition>
    <p v-if="error" class="dsw-error" role="alert">{{ error }}</p>
  </div>
</template>

<script>
import Icons from '@/Shared/Icons.vue';

// The Draft / Final switch of a record form: a sliding two-sided pill instead of a plain tick box. `checked` is true while the record is a DRAFT
// (the same value the old tick box held), so a form swaps it in without changing what it sends. The switch only ASKS to change (update:checked):
// the form decides, so it can refuse (the knob stays put and the pill shakes) when the record fails its checks for leaving draft.
export default {
  components: { Icons },
  props: {
    id: { type: String, default: 'draft' },
    checked: { type: Boolean, default: false }, // true = Draft
    disabled: Boolean,
    error: String, // a problem to show under the switch (e.g. "fix the items marked in red first")
    invalid: Boolean,
    shake: { type: Number, default: 0 }, // bump this number to make the pill shake (a refused change)
    draftText: String,
    finalText: String,
    draftHint: String,
    finalHint: String,
  },
  emits: ['update:checked'],
  data() { return { shaking: false, moving: false, burst: 0, t1: null, t2: null }; },
  watch: {
    shake() {
      this.shaking = false;
      this.$nextTick(() => { this.shaking = true; setTimeout(() => { this.shaking = false; }, 520); });
    },
    // the parent accepted the change: the thumb has started to travel
    checked() {
      this.moving = true;
      clearTimeout(this.t1);
      clearTimeout(this.t2);
      this.t1 = setTimeout(() => { this.burst = Date.now(); }, 300);
      this.t2 = setTimeout(() => { this.moving = false; }, 620);
    },
  },
  beforeUnmount() { clearTimeout(this.t1); clearTimeout(this.t2); },
  methods: {
    toggle() { this.request(!this.checked); },
    request(draft) { if (!this.disabled && draft !== this.checked) this.$emit('update:checked', draft); },
  },
};
</script>

<style scoped>
.dsw { --g: 0.28rem; --h: 2.9rem; display: inline-flex; flex-direction: column; gap: 0.5rem; max-width: 100%; }
/* the track: a soft glass pill whose colour melts from amber (draft) to green (final) */
.dsw-track { position: relative; display: grid; grid-template-columns: 1fr 1fr; align-items: center; width: 16.5rem; max-width: 100%; height: var(--h); padding: 0; border: 0; border-radius: 999px; cursor: pointer; overflow: hidden; outline: none; isolation: isolate;
  background: linear-gradient(120deg, #fcd34d, #f59e0b 60%, #ea8a0c); box-shadow: inset 0 2px 6px rgba(120, 53, 15, 0.32), inset 0 -2px 4px rgba(255, 255, 255, 0.25), 0 8px 18px -10px rgba(217, 119, 6, 0.75); transition: box-shadow 0.5s ease; }
.dsw-track::before { content: ''; position: absolute; inset: 0; z-index: -1; border-radius: inherit; background: linear-gradient(120deg, #86efac, #22c55e 60%, #15a34a); opacity: 0; transition: opacity 0.55s ease; }
.dsw.is-final .dsw-track { box-shadow: inset 0 2px 6px rgba(20, 83, 45, 0.34), inset 0 -2px 4px rgba(255, 255, 255, 0.25), 0 8px 18px -10px rgba(22, 163, 74, 0.8); }
.dsw.is-final .dsw-track::before { opacity: 1; }
.dsw-track:focus-visible { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5), inset 0 2px 6px rgba(0, 0, 0, 0.2); }
.dsw-track:disabled { opacity: 0.55; cursor: not-allowed; }
.dsw-track:hover:not(:disabled) .dsw-thumb-in { transform: scale(1.04); }
/* a band of light that crosses the pill from time to time */
.dsw-gloss { position: absolute; top: 0; bottom: 0; left: -45%; width: 38%; z-index: 3; pointer-events: none; background: linear-gradient(105deg, transparent, rgba(255, 255, 255, 0.42), transparent); transform: skewX(-18deg); animation: dsw-gloss 5.5s ease-in-out infinite; }

/* the two sides, laid out in the same two halves the thumb travels between */
.dsw-side { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; height: 100%; color: rgba(255, 255, 255, 0.95); text-shadow: 0 1px 2px rgba(0, 0, 0, 0.28); pointer-events: none; transition: opacity 0.3s ease 0.1s, transform 0.4s ease; }
.dsw-side b { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; }
.dsw-s-ico { width: 0.95rem; height: 0.95rem; }
.dsw-side.on { opacity: 0; transform: scale(0.85); transition-delay: 0s; }

/* the thumb: the same gap on every side, in both positions (the width is half the track minus one and a half gaps, and it moves by its own width plus one gap) */
.dsw-thumb { position: absolute; z-index: 2; top: var(--g); left: var(--g); height: calc(100% - 2 * var(--g)); width: calc((100% - 3 * var(--g)) / 2); border-radius: 999px; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(180deg, #ffffff, #f1f5f9); box-shadow: 0 6px 12px -3px rgba(15, 23, 42, 0.45), 0 1px 0 rgba(255, 255, 255, 0.9) inset; transition: transform 0.55s cubic-bezier(0.34, 1.45, 0.5, 1); }
.dsw.is-final .dsw-thumb { transform: translateX(calc(100% + var(--g))); }
.dsw-thumb-in { display: inline-flex; align-items: center; gap: 0.4rem; transition: transform 0.2s ease; color: #b45309; }
.dsw.is-final .dsw-thumb-in { color: #15803d; }
.dsw-ico { width: 1rem; height: 1rem; animation: dsw-pop 0.5s cubic-bezier(0.34, 1.6, 0.5, 1) both; }
.dsw-now { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; }
/* stretch while travelling, then settle */
.dsw.is-moving .dsw-thumb { animation: dsw-stretch 0.6s ease-in-out; }

/* sparks and a ring where the thumb lands */
.dsw-burst { position: absolute; inset: 0; pointer-events: none; }
.dsw-ring { position: absolute; left: 50%; top: 50%; width: 100%; height: 100%; border-radius: 999px; border: 2px solid currentColor; color: #b45309; transform: translate(-50%, -50%) scale(0.8); opacity: 0.7; animation: dsw-ring 0.7s ease-out forwards; }
.dsw.is-final .dsw-ring { color: #16a34a; }
.dsw-spark { position: absolute; left: 50%; top: 50%; width: 0.32rem; height: 0.32rem; margin: -0.16rem; border-radius: 999px; background: #f59e0b; opacity: 0; animation: dsw-spark 0.65s ease-out var(--d) forwards; }
.dsw.is-final .dsw-spark { background: #22c55e; }

.dsw-hint { margin: 0; font-size: 0.78rem; line-height: 1.4; color: #6b7280; max-width: 26rem; }
.dsw-error { margin: 0; font-size: 0.78rem; font-weight: 700; color: #dc2626; max-width: 26rem; }
.dsw.has-error .dsw-track { box-shadow: inset 0 2px 6px rgba(120, 53, 15, 0.32), 0 0 0 2px #fca5a5, 0 0 14px rgba(220, 38, 38, 0.35); }
.dsw.is-shaking .dsw-track { animation: dsw-shake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both; }
.dsw-fade-enter-active, .dsw-fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.dsw-fade-enter-from { opacity: 0; transform: translateY(3px); } .dsw-fade-leave-to { opacity: 0; transform: translateY(-3px); }

@keyframes dsw-gloss { 0%, 60% { left: -45%; } 100% { left: 130%; } }
@keyframes dsw-pop { from { transform: scale(0.2) rotate(-90deg); opacity: 0; } to { transform: scale(1) rotate(0); opacity: 1; } }
@keyframes dsw-stretch { 0%, 100% { scale: 1 1; } 35% { scale: 1.16 0.9; } 70% { scale: 0.96 1.04; } }
@keyframes dsw-ring { to { transform: translate(-50%, -50%) scale(1.5); opacity: 0; } }
@keyframes dsw-spark { 0% { opacity: 1; transform: rotate(var(--a)) translateX(0.6rem) scale(1); } 100% { opacity: 0; transform: rotate(var(--a)) translateX(1.9rem) scale(0.2); } }
@keyframes dsw-shake { 10%, 90% { transform: translateX(-2px); } 20%, 80% { transform: translateX(4px); } 30%, 50%, 70% { transform: translateX(-6px); } 40%, 60% { transform: translateX(6px); } }
@media (max-width: 420px) { .dsw-track { width: 100%; } }
@media (prefers-reduced-motion: reduce) { .dsw-gloss, .dsw-ico, .dsw-ring, .dsw-spark, .dsw.is-moving .dsw-thumb { animation: none; } .dsw-thumb, .dsw-side, .dsw-track::before, .dsw-track { transition: none; } .dsw.is-shaking .dsw-track { animation: none; } }
html.dark .dsw-hint { color: #94a3b8; }
html.dark .dsw-thumb { background: linear-gradient(180deg, #f8fafc, #e2e8f0); }
</style>
