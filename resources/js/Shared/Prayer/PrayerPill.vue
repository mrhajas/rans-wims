<template>
  <div v-if="show" class="pp" :class="{ 'is-soon': soon, 'is-live': live && !soon, 'is-alert': alerting }" @mouseenter="open = true" @mouseleave="open = false" @focusin="open = true" @focusout="open = false" tabindex="0" :aria-label="label">
    <span class="pp__orb" :style="{ '--p': progress + '%' }" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M15.5 3.2a8.8 8.8 0 1 0 5.3 15.8A7.2 7.2 0 0 1 15.5 3.2z" fill="currentColor" /></svg>
    </span>
    <span class="pp__text">
      <template v-if="alerting">
        <em>{{ ph.e.label }}</em> <span>{{ sd ? $t('WIMS locks in') : $t('Prayer time in') }}</span> <b>{{ lockLeft }}</b>
      </template>
      <template v-else>
        <small>{{ $t('Next prayer') }}</small> <em>{{ next.label }}</em> <b>{{ at12(next.time) }}</b> <i>{{ $t('in') }} {{ rest }}</i>
      </template>
    </span>
    <span class="pp__eat">EAT</span>

    <transition name="pp-pop">
      <div v-if="open" class="pp__pop" role="tooltip">
        <header><span>{{ $t('Prayer times') }}</span><small>{{ city }} · EAT</small></header>
        <ul>
          <li v-for="t in times" :key="t.key" :class="{ next: t.isNext, passed: t.passed && !t.isNext, sun: !t.is_prayer }">
            <span class="pp__dot"></span><b>{{ t.is_prayer ? t.label : $t('Sunrise') }}</b><time>{{ at12(t.time) }}</time>
            <i v-if="t.isNext">{{ $t('in') }} {{ rest }}</i>
          </li>
        </ul>
        <footer v-if="inScope && !sd">{{ $t('Reminder only, WIMS does not lock.') }}</footer>
        <footer v-else-if="inScope">{{ $t('WIMS locks') }} {{ lockStart }} {{ $t('min after each prayer for') }} {{ lockLen }} {{ $t('min') }}</footer>
      </div>
    </transition>
  </div>
</template>

<script>
import { prayerState, nextPrayer, today, words, clock12, phase, countdown } from './prayerStore';

export default {
  data() { return { open: false }; },
  computed: {
    d() { return prayerState.data; },
    show() { return !!(this.d && this.d.topbar && nextPrayer.value); },
    ph() { return phase.value; },
    // from the shutdown alert until the lock the pill itself counts down, so there is no separate bar over the top bar
    alerting() { return !!(this.d && this.d.in_scope && (this.ph.phase === 'alert' || this.ph.phase === 'prayer') && this.ph.e); },
    sd() { return !(this.d && this.d.shutdown === false); },
    lockLeft() { return this.ph.e ? countdown(this.ph.e.lockT) : ''; },
    next() { return nextPrayer.value; },
    times() { return today.value; },
    city() { return this.d ? this.d.city : ''; },
    rest() { return this.next ? words(this.next.t) : ''; },
    soon() { return !!(this.next && this.next.t - prayerState.now < (this.d.alert_before_min + 10) * 60000); },
    live() { return !!(this.next && this.next.t - prayerState.now < 60 * 60000); },
    inScope() { return !!(this.d && this.d.in_scope); },
    lockStart() { return this.d ? this.d.lock_start_after_min : 0; },
    lockLen() { return this.d ? this.d.lock_minutes : 0; },
    // how far we are between the previous prayer and the next one
    progress() {
      if (!this.next) return 0;
      const past = this.times.filter(t => t.is_prayer && t.t <= prayerState.now);
      const from = past.length ? past[past.length - 1].t : this.next.t - 6 * 3600000;
      return Math.round(Math.min(1, Math.max(0, (prayerState.now - from) / Math.max(1, this.next.t - from))) * 100);
    },
    label() { return this.next ? this.$t('Next prayer') + ': ' + this.next.label + ' ' + this.at12(this.next.time) : ''; },
  },
  methods: { at12: clock12 },
};
</script>

<style>
/* Light (soft green and orange) by default; the dark look only in WIMS dark mode */
.pp { position: relative; display: none; align-items: center; gap: 0.55rem; height: 44px; max-width: 330px; padding: 0 0.6rem 0 0.4rem; border-radius: 14px; color: #14532d; cursor: default; background: linear-gradient(135deg, #ecfdf5, #fff7ed); box-shadow: 0 8px 18px -12px rgba(22, 101, 52, 0.55), inset 0 0 0 1px rgba(34, 197, 94, 0.35); outline: none; white-space: nowrap; }
@media (min-width: 1366px) { .pp { display: inline-flex; } }
.pp:focus-visible { box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.45); }
.pp.is-live { animation: pp-glow 3.2s ease-in-out infinite; } .pp.is-soon, .pp.is-alert { animation: pp-glow-soon 1.7s ease-in-out infinite; }
.pp.is-alert { background: linear-gradient(135deg, #fff7ed, #ffedd5); color: #7c2d12; }
.pp__orb { flex: none; position: relative; display: grid; place-items: center; width: 32px; height: 32px; border-radius: 50%; color: #f59e0b; background: conic-gradient(#f97316 var(--p), rgba(34, 197, 94, 0.2) 0); }
.pp__orb::before { content: ''; position: absolute; inset: 3px; border-radius: 50%; background: #fffdf7; }
.pp__orb svg { position: relative; width: 17px; height: 17px; filter: drop-shadow(0 0 4px rgba(245, 158, 11, 0.7)); animation: pp-float 3.4s ease-in-out infinite; }
.pp__text { min-width: 0; font-size: 0.8rem; font-weight: 600; } .pp__text small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: #5b7a66; margin-right: 2px; }
.pp__text em { font-style: normal; font-weight: 800; color: #166534; } .pp.is-alert .pp__text em { color: #9a3412; } .pp__text b { font-weight: 800; color: #c2410c; font-variant-numeric: tabular-nums; } .pp__text i { font-style: normal; font-weight: 800; color: #15803d; margin-left: 2px; }
.pp__eat { flex: none; padding: 1px 6px; border-radius: 6px; font-size: 0.58rem; font-weight: 800; letter-spacing: 0.12em; color: #fff; background: linear-gradient(135deg, #fb923c, #ea580c); }
/* Room for the whole text ("Next prayer Dhuhr 11:44 PM in 23h 59m") and a clear gap before EAT; on narrower desktops the small "Next prayer" word is dropped instead of cutting the numbers. */
.pp { max-width: 440px; } .pp__text { flex: 0 0 auto; white-space: nowrap; } .pp__eat { margin-left: 8px; }
@media (min-width: 1366px) and (max-width: 1535.98px) { .pp__text small { display: none; } .pp { max-width: 300px; } .nb-root .nb-left .wh-picker-value { max-width: 4.8rem !important; } .nb-root .nb-left .wh-picker-trigger { padding-right: 0.4rem; } }
.pp__pop { position: absolute; top: calc(100% + 10px); right: 0; z-index: 60; width: 250px; padding: 0.8rem 0.9rem; border-radius: 16px; white-space: normal; color: #14532d; background: linear-gradient(165deg, #f0fdf4, #fff7ed); box-shadow: 0 24px 46px -18px rgba(22, 101, 52, 0.5), 0 0 0 1px rgba(34, 197, 94, 0.35); }
.pp__pop header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 0.5rem; font-weight: 800; font-size: 0.85rem; color: #c2410c; } .pp__pop header small { color: #5b7a66; font-weight: 600; font-size: 0.68rem; }
.pp__pop ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 2px; }
.pp__pop li { display: flex; align-items: center; gap: 0.55rem; padding: 0.38rem 0.5rem; border-radius: 10px; font-size: 0.82rem; } .pp__pop li b { flex: 1; font-weight: 700; } .pp__pop li time { font-variant-numeric: tabular-nums; font-weight: 700; } .pp__pop li i { font-style: normal; font-size: 0.68rem; color: #15803d; font-weight: 800; }
.pp__dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(22, 101, 52, 0.25); } .pp__pop li.passed { opacity: 0.55; } .pp__pop li.passed .pp__dot { background: #22c55e; } .pp__pop li.sun { opacity: 0.6; font-size: 0.75rem; }
.pp__pop li.next { background: linear-gradient(90deg, rgba(251, 146, 60, 0.25), rgba(251, 146, 60, 0.06)); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.45); } .pp__pop li.next .pp__dot { background: #f97316; box-shadow: 0 0 10px #f97316; animation: pp-pulse 1.2s ease-in-out infinite; }
.pp__pop footer { margin-top: 0.55rem; padding-top: 0.5rem; border-top: 1px solid rgba(22, 101, 52, 0.15); font-size: 0.68rem; color: #5b7a66; }
.pp-pop-enter-active, .pp-pop-leave-active { transition: all 0.2s ease; } .pp-pop-enter-from, .pp-pop-leave-to { opacity: 0; transform: translateY(-6px); }
@keyframes pp-float { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-1.5px) rotate(-6deg); } }
@keyframes pp-glow { 0%, 100% { box-shadow: 0 8px 18px -12px rgba(22, 101, 52, 0.55), inset 0 0 0 1px rgba(34, 197, 94, 0.35), 0 0 0 rgba(34, 197, 94, 0); } 50% { box-shadow: 0 8px 18px -12px rgba(22, 101, 52, 0.55), inset 0 0 0 1px rgba(34, 197, 94, 0.7), 0 0 16px rgba(34, 197, 94, 0.35); } }
@keyframes pp-glow-soon { 0%, 100% { box-shadow: 0 8px 18px -12px rgba(194, 65, 12, 0.5), inset 0 0 0 1px rgba(251, 146, 60, 0.55), 0 0 0 rgba(251, 146, 60, 0); } 50% { box-shadow: 0 8px 18px -12px rgba(194, 65, 12, 0.5), inset 0 0 0 1.5px rgba(234, 88, 12, 0.95), 0 0 20px rgba(251, 146, 60, 0.6); } }
@keyframes pp-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.4); } }
/* WIMS dark mode */
html.dark .pp { color: #ecfdf5; background: linear-gradient(135deg, #0b3b34, #0a2a40 60%, #07142b); box-shadow: 0 8px 18px -10px rgba(7, 20, 43, 0.9), inset 0 0 0 1px rgba(250, 204, 21, 0.28); }
html.dark .pp.is-alert { background: linear-gradient(135deg, #7c2d12, #92400e); color: #fff7ed; }
html.dark .pp__orb { color: #fde68a; background: conic-gradient(#facc15 var(--p), rgba(255, 255, 255, 0.14) 0); } html.dark .pp__orb::before { background: #0a2a40; }
html.dark .pp__text small { color: #94a3b8; } html.dark .pp__text em, html.dark .pp.is-alert .pp__text em { color: #fff; } html.dark .pp__text b { color: #fde68a; } html.dark .pp__text i { color: #6ee7b7; }
html.dark .pp__pop { color: #ecfdf5; background: linear-gradient(165deg, #0b3b34, #07142b); box-shadow: 0 24px 50px -16px rgba(0, 0, 0, 0.75), 0 0 0 1px rgba(250, 204, 21, 0.35); } html.dark .pp__pop header { color: #fde68a; } html.dark .pp__pop header small, html.dark .pp__pop footer { color: #94a3b8; } html.dark .pp__pop li i { color: #6ee7b7; } html.dark .pp__dot { background: rgba(255, 255, 255, 0.25); }
html.dark .pp__pop li.next { background: linear-gradient(90deg, rgba(250, 204, 21, 0.22), rgba(250, 204, 21, 0.06)); box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.5); } html.dark .pp__pop li.next .pp__dot { background: #facc15; box-shadow: 0 0 10px #facc15; }
@media (prefers-reduced-motion: reduce) { .pp, .pp__orb svg { animation: none; } }
</style>
