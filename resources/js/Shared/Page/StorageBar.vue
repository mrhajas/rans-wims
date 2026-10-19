<template>
  <section class="sb">
    <div class="sb-head">
      <h2 class="sb-title"><icons name="chart-bar" class="w-5 h-5" />{{ title }}</h2>
      <div class="sb-used"><b>{{ used }}</b><span v-if="sub" class="sb-sub">{{ sub }}</span></div>
    </div>
    <div class="sb-legend">
      <span v-for="s in rows" :key="s.key" class="sb-key">
        <i :style="{ background: s.color }"></i>{{ s.label }}<em>{{ s.pct }}%</em>
      </span>
    </div>
    <div class="sb-bar" role="img" :aria-label="title">
      <span v-for="(s, i) in rows" :key="s.key" class="sb-seg lg-tip" :data-tip="s.label + ' · ' + s.text" :style="{ flexGrow: Math.max(s.value, total * 0.012), background: s.color, animationDelay: i * 90 + 'ms' }"></span>
      <span v-if="!rows.length" class="sb-seg is-empty" style="flex-grow: 1"></span>
    </div>
    <div class="sb-rows">
      <div v-for="s in rows" :key="s.key" class="sb-row">
        <span class="sb-row-ico" :style="{ background: s.color }"><icons :name="s.icon || 'collection'" class="w-4 h-4" /></span>
        <span class="sb-row-t"><b>{{ s.label }}</b><small>{{ s.text }}</small></span>
        <span class="sb-row-pct">{{ s.pct }}%</span>
      </div>
    </div>
  </section>
</template>

<script>
// "Data Storage" card: a segmented bar with a legend and a row per segment. Each segment: { key, label, value, color, text, icon }.
export default {
  props: { title: String, used: String, sub: String, segments: { type: Array, default: () => [] } },
  computed: {
    total() { return this.segments.reduce((a, s) => a + (s.value || 0), 0) || 1; },
    rows() {
      return this.segments.map(s => ({ ...s, pct: Math.round(((s.value || 0) / this.total) * 100) }));
    },
  },
};
</script>

<style scoped>
.sb { padding: 1.1rem 1.2rem 1.2rem; border-radius: 1.5rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.45); }
.sb-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 14px; }
.sb-title { display: flex; align-items: center; gap: 9px; margin: 0; font-size: 1.05rem; font-weight: 900; color: var(--lg-ink); }
.sb-used { font-size: 0.9rem; color: var(--lg-mute); }
.sb-sub { margin-left: 6px; }
.sb-used b { color: var(--lg-ink); font-size: 1.05rem; }
.sb-legend { display: flex; flex-wrap: wrap; gap: 6px 22px; margin: 0.9rem 0 0.6rem; font-size: 0.82rem; color: var(--lg-ink); }
.sb-key { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; }
.sb-key i { width: 14px; height: 14px; border-radius: 0.3rem; }
.sb-key em { font-style: normal; color: var(--lg-faint); font-weight: 600; margin-left: 4px; }
.sb-bar { display: flex; gap: 5px; height: 14px; }
.sb-seg { min-width: 6px; border-radius: 0.3rem; transform-origin: left; animation: sb-grow 0.7s ease backwards; }
.sb-seg.is-empty { background: var(--lg-line); }
.sb-rows { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 1rem; }
.sb-row { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 1rem; background: var(--lg-soft); }
.sb-row-ico { flex: none; width: 34px; height: 34px; display: grid; place-items: center; border-radius: 0.8rem; color: #fff; }
.sb-row-t { display: flex; flex-direction: column; min-width: 0; }
.sb-row-t b { font-size: 0.86rem; color: var(--lg-ink); }
.sb-row-t small { font-size: 0.72rem; color: var(--lg-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-row-pct { margin-left: auto; font-size: 0.9rem; font-weight: 900; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
@keyframes sb-grow { from { transform: scaleX(0); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .sb-seg { animation: none; } }
</style>
