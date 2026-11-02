<template>
  <svg class="ni" :class="['k-' + shape, 'is-' + level]" :style="{ '--sig': color }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="shape === 'wifi'">
      <path class="a a3" :class="{ on: lit >= 4 }" d="M2.6 9.2a14 14 0 0 1 18.8 0" />
      <path class="a a2" :class="{ on: lit >= 3 }" d="M5.7 12.6a9.6 9.6 0 0 1 12.6 0" />
      <path class="a a1" :class="{ on: lit >= 2 }" d="M8.9 16a5.2 5.2 0 0 1 6.2 0" />
      <circle class="a a0" :class="{ on: lit >= 1 }" cx="12" cy="19.2" r="1.25" fill="currentColor" stroke="none" />
    </template>
    <template v-else-if="shape === 'ethernet'">
      <path class="a a3 on" d="M5 4h14v9.5a2 2 0 0 1-2 2h-1.6V19h-6.8v-3.5H7a2 2 0 0 1-2-2z" />
      <path class="a a2" :class="{ on: lit >= 2 }" d="M9 7.5v3M12 7.5v3M15 7.5v3" />
    </template>
    <template v-else>
      <path class="a a0" :class="{ on: lit >= 1 }" d="M4 19v-2.5" /><path class="a a1" :class="{ on: lit >= 2 }" d="M9 19v-6" /><path class="a a2" :class="{ on: lit >= 3 }" d="M14 19V9" /><path class="a a3" :class="{ on: lit >= 4 }" d="M19 19V4" />
    </template>
  </svg>
</template>

<script>
import { LEVELS } from '@/Core/networkSignal';

// The network icon of the top bar and of the status window: Wi-Fi waves, a cable plug (wired) or mobile bars. Its colour follows the strength and it never stops moving: the
// waves pulse one after the other (quicker when the link is strong, slow and hesitant when it is weak).
export default {
  props: { percent: { type: Number, default: null }, level: { type: String, default: 'unknown' }, type: { type: String, default: 'unknown' } },
  computed: {
    shape() { return this.type === 'ethernet' ? 'ethernet' : this.type === 'cellular' ? 'cellular' : 'wifi'; },
    color() { return (LEVELS[this.level] || LEVELS.unknown).color; },
    // how many of the four parts are lit
    lit() { return this.percent === null ? 4 : this.percent >= 80 ? 4 : this.percent >= 55 ? 3 : this.percent >= 30 ? 2 : this.percent > 0 ? 1 : 0; },
  },
};
</script>

<style scoped>
.ni { color: var(--sig); overflow: visible; }
.ni .a { opacity: 0.2; transition: opacity 0.4s ease, color 0.4s ease; }
.ni .a.on { opacity: 1; animation: ni-pulse 1.8s ease-in-out infinite; }
.ni .a0.on { animation-delay: 0s; } .ni .a1.on { animation-delay: 0.18s; } .ni .a2.on { animation-delay: 0.36s; } .ni .a3.on { animation-delay: 0.54s; }
@keyframes ni-pulse { 0%, 100% { opacity: 1; } 45% { opacity: 0.3; } }
.ni.is-poor .a.on, .ni.is-weak .a.on { animation-duration: 2.8s; }
.ni.is-excellent .a.on { animation-duration: 1.3s; }
.ni.is-offline .a.on { animation: none; opacity: 0.35; }
.ni.is-unknown .a.on { animation-duration: 2.4s; }
@media (prefers-reduced-motion: reduce) { .ni .a.on { animation: none; } }
</style>
