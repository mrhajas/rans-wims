<template>
  <component :is="to ? 'Link' : 'div'" :href="to || undefined" class="sc" :class="['is-' + tone, { 'is-link': !!to }]" :style="{ '--i': index }">
    <span class="sc-ring sc-ring-a"></span><span class="sc-ring sc-ring-b"></span>
    <div class="sc-top">
      <span class="sc-ico"><icons :name="icon" class="w-5 h-5" /></span>
      <span class="sc-more" aria-hidden="true">•••</span>
    </div>
    <div class="sc-value"><animated-number v-if="typeof value === 'number'" :value="value" use-grouping /><template v-else>{{ value }}</template></div>
    <div class="sc-foot">
      <span class="sc-label"><icons :name="footIcon || icon" class="w-4 h-4" />{{ label }}</span>
      <span v-if="chip" class="sc-chip">{{ chip }}</span>
    </div>
  </component>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';

// A big coloured stat card: gradient face with the number, a lighter strip underneath with the label and a small pill.
export default {
  components: { Link, AnimatedNumber },
  props: {
    tone: { type: String, default: 'orange' }, // orange | purple | teal | blue | rose | emerald | slate
    icon: { type: String, default: 'collection' },
    footIcon: String,
    label: String,
    value: [Number, String],
    chip: String,
    to: String,
    index: { type: Number, default: 0 },
  },
};
</script>

<style scoped>
.sc { --a: #f59e0b; --b: #fbbf24; --foot: #fcd34d; position: relative; display: flex; flex-direction: column; min-height: 156px; border-radius: 1.5rem; overflow: hidden; color: #fff; text-decoration: none; background: linear-gradient(150deg, var(--a), var(--b)); box-shadow: 0 22px 36px -22px var(--a); animation: sc-rise 0.45s ease backwards; animation-delay: calc(var(--i, 0) * 60ms); transition: transform 0.18s ease, box-shadow 0.18s ease; }
.sc.is-link:hover { transform: translateY(-3px); }
.sc.is-orange { --a: #f59e0b; --b: #f97316; --foot: #fde68a; }
.sc.is-purple { --a: #6d5dfc; --b: #8b5cf6; --foot: #c4b5fd; }
.sc.is-teal { --a: #0d9488; --b: #14b8a6; --foot: #99f6e4; }
.sc.is-blue { --a: #2563eb; --b: #38bdf8; --foot: #bae6fd; }
.sc.is-rose { --a: #e11d48; --b: #fb7185; --foot: #fecdd3; }
.sc.is-emerald { --a: #059669; --b: #34d399; --foot: #a7f3d0; }
.sc.is-slate { --a: #334155; --b: #64748b; --foot: #cbd5e1; }
.sc-ring { position: absolute; border-radius: 9999px; background: rgba(255, 255, 255, 0.13); pointer-events: none; }
.sc-ring-a { width: 150px; height: 150px; right: -40px; top: -60px; }
.sc-ring-b { width: 90px; height: 90px; right: 70px; top: 30px; background: rgba(255, 255, 255, 0.08); }
.sc-top { position: relative; display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 0; }
.sc-ico { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 1rem; background: rgba(255, 255, 255, 0.22); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35); }
.sc-more { letter-spacing: 2px; font-weight: 900; opacity: 0.7; }
.sc-value { position: relative; flex: 1; display: flex; align-items: center; padding: 6px 18px 14px; font-size: 2.2rem; font-weight: 900; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; line-height: 1; }
.sc-foot { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 18px; background: color-mix(in srgb, var(--foot) 55%, transparent); color: #fff; backdrop-filter: blur(2px); border-top: 1px solid rgba(255, 255, 255, 0.25); }
.sc-label { display: inline-flex; align-items: center; gap: 8px; font-size: 0.86rem; font-weight: 800; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sc-chip { flex: none; padding: 3px 11px; border-radius: 9999px; background: #fff; color: var(--a); font-size: 0.72rem; font-weight: 900; box-shadow: 0 4px 10px -4px rgba(15, 23, 42, 0.3); }
@keyframes sc-rise { from { opacity: 0; transform: translateY(12px); } }
@media (max-width: 575.98px) {
  .sc { min-height: 138px; } .sc-value { font-size: 1.9rem; }
  /* phones: the label gets the full strip (wrapped, never cut) and the pill sits under it */
  .sc-foot { flex-direction: column; align-items: flex-start; gap: 6px; padding: 10px 14px; }
  .sc-label { max-width: 100%; white-space: normal; overflow: visible; text-overflow: clip; line-height: 1.25; font-size: 0.8rem; }
  .sc-chip { max-width: 100%; }
  .sc-top { padding: 14px 14px 0; } .sc-value { padding-left: 14px; padding-right: 14px; }
}
@media (prefers-reduced-motion: reduce) { .sc { animation: none; transition: none; } }
</style>
