<template>
  <div v-if="next" class="lps" :class="{ 'is-soon': soon }" role="status">
    <!-- next prayer: shown on every screen size -->
    <div class="lps-next">
      <span class="lps-moon" aria-hidden="true"></span>
      <span class="lps-next__t"><small>{{ $t('Next prayer') }}</small><b>{{ next.label }} <em>{{ at12(next.time) }}</em></b></span>
      <i class="lps-in">{{ $t('in') }} {{ rest }}</i>
    </div>
    <!-- every prayer of today: wide screens only -->
    <ul class="lps-list" :aria-label="$t('Prayer times today')">
      <li v-for="t in list" :key="t.key" :class="{ next: t.isNext, passed: t.passed && !t.isNext }"><b>{{ t.label }}</b><time>{{ at12(t.time) }}</time></li>
    </ul>
  </div>
</template>

<script>
import { prayerState, nextPrayer, today, words, clock12 } from './prayerStore';

// The prayers of the day at the top right of the sign-in page (all of them on a computer, only the next one on a phone).
export default {
  computed: {
    next() { return nextPrayer.value; },
    rest() { return this.next ? words(this.next.t) : ''; },
    soon() { return !!(this.next && this.next.t - prayerState.now < 30 * 60000); },
    // the highlighted one is the prayer that comes next (after Isha, tomorrow's Fajr)
    list() { const n = this.next; return today.value.filter(t => t.is_prayer).map(t => ({ ...t, isNext: !!n && t.key === n.key })); },
  },
  methods: {
    at12(hhmm) { return clock12(hhmm).replace(/^0/, ''); },
  },
};
</script>

<style>
.lps { position: fixed; top: 14px; right: 16px; z-index: 40; display: flex; align-items: center; gap: 0.7rem; padding: 0.4rem 0.5rem 0.4rem 0.45rem; border-radius: 18px; color: #14532d; background: linear-gradient(135deg, rgba(240, 253, 244, 0.96), rgba(255, 247, 237, 0.96)); box-shadow: 0 14px 30px -16px rgba(22, 101, 52, 0.6), 0 0 0 1px rgba(22, 163, 74, 0.25); backdrop-filter: blur(8px); }
.lps.is-soon { animation: lps-soon 1.8s ease-in-out infinite; }
.lps-next { display: flex; align-items: center; gap: 0.55rem; padding: 0.25rem 0.7rem 0.25rem 0.35rem; border-radius: 14px; background: rgba(255, 255, 255, 0.7); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.22); }
.lps-moon { flex: none; width: 22px; height: 22px; border-radius: 50%; box-shadow: inset -7px -3px 0 0 #f59e0b; background: transparent; animation: lps-blink 2.4s ease-in-out infinite; }
.lps-next__t { display: grid; line-height: 1.15; } .lps-next__t small { font-size: 0.58rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: #5b7a66; }
.lps-next__t b { font-size: 0.86rem; font-weight: 800; color: #14532d; white-space: nowrap; } .lps-next__t em { font-style: normal; color: #c2410c; }
.lps-in { font-style: normal; font-size: 0.72rem; font-weight: 800; color: #c2410c; white-space: nowrap; }
.lps-list { display: none; margin: 0; padding: 0; list-style: none; gap: 0.3rem; }
.lps-list li { display: grid; gap: 1px; padding: 0.22rem 0.55rem; border-radius: 11px; text-align: center; background: rgba(255, 255, 255, 0.55); box-shadow: inset 0 0 0 1px rgba(22, 101, 52, 0.14); }
.lps-list b { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #5b7a66; } .lps-list time { font-size: 0.78rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #14532d; white-space: nowrap; }
.lps-list li.passed { opacity: 0.55; }
.lps-list li.next { background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 6px 14px -8px rgba(234, 88, 12, 0.9); } .lps-list li.next b, .lps-list li.next time { color: #fff; }
@media (min-width: 1100px) { .lps-list { display: flex; } }
/* phones: only the next prayer, centred at the top */
@media (max-width: 640px) { .lps { top: 8px; right: 50%; transform: translateX(50%); padding: 0.3rem; } .lps.is-soon { animation: none; } .lps-next { padding-right: 0.6rem; } }
@keyframes lps-soon { 0%, 100% { box-shadow: 0 14px 30px -16px rgba(22, 101, 52, 0.6), 0 0 0 1px rgba(22, 163, 74, 0.25); } 50% { box-shadow: 0 14px 30px -12px rgba(234, 88, 12, 0.7), 0 0 0 2px rgba(251, 146, 60, 0.7); } }
@keyframes lps-blink { 0%, 100% { opacity: 0.45; } 50% { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .lps, .lps-moon { animation: none; } }
</style>
