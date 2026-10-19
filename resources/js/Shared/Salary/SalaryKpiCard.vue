<template>
  <div class="kpi-card" :style="{ '--kpi-accent': accentColor }">
    <div class="kpi-card-top">
      <span class="kpi-icon"><icons :name="icon" class="w-4.5 h-4.5" /></span>
      <span v-if="trend !== null" class="kpi-trend" :class="trend >= 0 ? 'kpi-trend-up' : 'kpi-trend-down'">
        <icons :name="trend >= 0 ? 'up' : 'down'" class="w-3 h-3" />
        {{ Math.abs(trend) }}%
      </span>
    </div>
    <div class="kpi-value">
      <span v-if="prefix" class="kpi-prefix">{{ prefix }}</span>
      <animated-number :value="value" :use-grouping="true" />
    </div>
    <div class="kpi-label">{{ label }}</div>
    <div v-if="hint" class="kpi-hint">{{ hint }}</div>
  </div>
</template>

<script>
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';

export default {
  components: { AnimatedNumber },
  props: {
    icon: { type: String, required: true },
    label: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    prefix: { type: String, default: '' },
    hint: { type: String, default: '' },
    trend: { type: Number, default: null },
    accent: { type: String, default: 'indigo' },
  },
  computed: {
    accentColor() {
      const map = { indigo: '#4f46e5', emerald: '#059669', rose: '#e11d48', amber: '#d97706', slate: '#475569', sky: '#0284c7' };
      return map[this.accent] || map.indigo;
    },
  },
};
</script>

<style scoped>
.kpi-card {
  position: relative;
  display: flex; flex-direction: column; min-width: 0;
  padding: 0.9rem 1.05rem;
  border-radius: 20px;
  background: var(--lg-card, #fff);
  border: 1px solid var(--lg-line, #e6ebf3);
  box-shadow: var(--lg-shadow, 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 30px -18px rgba(15, 23, 42, 0.24));
  transition: transform 0.15s ease;
  overflow: hidden;
}
.kpi-card::before {
  content: '';
  position: absolute; inset: 0 auto 0 0; width: 4px;
  background: linear-gradient(180deg, var(--kpi-accent), color-mix(in srgb, var(--kpi-accent) 35%, transparent));
}
.kpi-card:hover { transform: translateY(-2px); }
.kpi-card-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 0.55rem; }
.kpi-icon {
  display: inline-flex; align-items: center; justify-content: center; height: 2.4rem; width: 2.4rem; border-radius: 14px; flex: none;
  color: #fff; background: linear-gradient(145deg, color-mix(in srgb, var(--kpi-accent) 70%, white), var(--kpi-accent));
  box-shadow: 0 10px 18px -8px color-mix(in srgb, var(--kpi-accent) 80%, transparent);
}
.kpi-trend { display: inline-flex; align-items: center; gap: 3px; font-size: 0.7rem; font-weight: 800; padding: 0.15rem 0.5rem; border-radius: 9999px; }
.kpi-trend-up { color: #047857; background: rgba(16, 185, 129, 0.14); }
.kpi-trend-down { color: #be123c; background: rgba(244, 63, 94, 0.13); }
.kpi-value { font-size: 1.3rem; font-weight: 800; line-height: 1.15; color: var(--lg-ink, #0f172a); font-variant-numeric: tabular-nums; display: flex; flex-wrap: wrap; align-items: baseline; column-gap: 4px; white-space: nowrap; }
.kpi-prefix { font-size: 0.72rem; font-weight: 800; color: var(--lg-faint, #94a3b8); letter-spacing: 0.04em; }
.kpi-label { margin-top: 3px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint, #94a3b8); }
.kpi-hint { font-size: 0.7rem; color: var(--lg-mute, #64748b); margin-top: 4px; }
@media (max-width: 480px) { .kpi-card { padding: 0.75rem 0.85rem; } .kpi-value { font-size: 1.1rem; } }
</style>
