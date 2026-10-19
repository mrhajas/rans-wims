<template>
  <div class="flb" :class="{ 'is-done': shown >= 99.5 }" role="progressbar" :aria-valuenow="Math.round(shown)" aria-valuemin="0" aria-valuemax="100" :aria-label="label || $t('Preparing the face scanner')">
    <p v-if="label !== ''" class="flb-label">{{ label || $t('Preparing the face scanner') }}</p>
    <div class="flb-track">
      <span class="flb-fill" :style="{ width: Math.max(shown, 14) + '%' }"></span>
      <b class="flb-pct">{{ Math.round(shown) }}%</b>
    </div>
  </div>
</template>

<script>
/*
 * A pill progress bar (dark track, gradient fill, the percentage in the middle). The number glides towards the real progress and keeps
 * creeping a little between steps, so it never looks stuck while the engine does its longer steps.
 */
export default {
  props: {
    pct: { type: Number, default: 0 },
    label: { type: String, default: null },
  },
  data() {
    return { shown: 0, raf: null };
  },
  mounted() {
    const tick = () => {
      const target = Math.min(100, this.pct);
      const ceiling = target >= 100 ? 100 : Math.min(target + 12, 96);
      const goal = target >= 100 ? 100 : Math.max(target, Math.min(ceiling, this.shown + 0.06));
      this.shown += (goal - this.shown) * (target >= 100 ? 0.2 : 0.08);
      if (this.shown > 99.6 && target >= 100) this.shown = 100;
      this.raf = requestAnimationFrame(tick);
    };
    this.raf = requestAnimationFrame(tick);
  },
  beforeUnmount() {
    cancelAnimationFrame(this.raf);
  },
};
</script>

<style>
.flb { width: min(320px, 100%); margin: 0 auto; }
.flb-label { margin: 0 0 6px; text-align: center; font-size: 0.74rem; font-weight: 700; letter-spacing: 0.02em; color: var(--lg-mute, #64748b); }
.flb-track { position: relative; height: 32px; border-radius: 999px; background: #0f172a; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.16), 0 6px 14px -8px rgba(15, 23, 42, 0.7); overflow: hidden; padding: 4px; }
.flb-fill { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #0ea5e9, #34d399); transition: width 0.12s linear; box-shadow: 0 0 14px rgba(52, 211, 153, 0.35); }
.flb-pct { position: absolute; inset: 0; display: grid; place-items: center; font-size: 0.82rem; font-weight: 700; font-variant-numeric: tabular-nums; color: #fff; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45); pointer-events: none; }
@media (prefers-reduced-motion: reduce) { .flb-fill { transition: none; } }
</style>
