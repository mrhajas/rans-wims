<template>
  <teleport to="body">
    <!-- slim countdown once the shutdown alert has been acknowledged -->
    <transition name="pl-bar">
      <div v-if="show && ph.phase === 'alert' && alertSeen" class="pl-bar" :class="{ 'pl-bar--pilled': topbarOn }" role="status">
        <i class="pl-bar__moon"></i>
        <span>{{ $t('Prayer break') }}: <b>{{ ph.e.label }}</b> · {{ sd ? $t('WIMS locks in') : $t('Prayer time in') }} <b class="pl-mono">{{ lockCount }}</b></span>
        <small>{{ $t('Please save your work') }}</small>
      </div>
    </transition>

    <transition name="pl-fade">
      <div v-if="show && (modal === 'alert' || modal === 'prayer')" class="pl-backdrop" role="dialog" aria-modal="true" :aria-label="modal === 'alert' ? (sd ? $t('Shutdown alert') : $t('Prayer reminder')) : $t('Prayer time')">
        <div class="pl-card" :class="'is-' + modal">
          <span class="pl-stars" aria-hidden="true"><i v-for="n in 22" :key="n" :style="{ '--x': (n * 37) % 100 + '%', '--y': (n * 53) % 100 + '%', '--d': (n % 7) * 0.4 + 's', '--s': 1 + (n % 3) }"></i></span>
          <span class="pl-arch" aria-hidden="true"></span>

          <prayer-sky :prayer="ph.e && ph.e.key" />

          <template v-if="modal === 'alert'">
            <p class="pl-eyebrow">{{ sd ? $t('Shutdown alert') : $t('Prayer reminder') }}</p>
            <h2 class="pl-title">{{ sd ? $t('WIMS will lock for') + ' ' + ph.e.label + ' ' + $t('prayer') : ph.e.label + ' ' + $t('prayer is coming') }}</h2>
            <div class="pl-ring">
              <svg viewBox="0 0 120 120"><circle class="pl-ring__bg" cx="60" cy="60" r="52" /><circle class="pl-ring__fg" cx="60" cy="60" r="52" :style="{ strokeDashoffset: ringOffset(ph.e.alertT, ph.e.lockT) }" /></svg>
              <div class="pl-ring__t"><b class="pl-mono">{{ lockCount }}</b><small>{{ sd ? $t('until the lock') : $t('until the prayer') }}</small></div>
            </div>
            <p v-if="!sd" class="pl-text">{{ $t('The prayer time is') }} <b>{{ at12(ph.e.at) }}</b>. {{ $t('WIMS stays open, this is a reminder only.') }}</p>
            <p v-else class="pl-text">{{ $t('WIMS locks at') }} <b>{{ at12(ph.e.lock_at) }}</b> {{ $t('and everyone is signed out. You can sign in again at') }} <b>{{ at12(ph.e.unlock_at) }}</b>.</p>
            <p class="pl-text pl-soft">{{ $t('Please finish what you are doing and save your work.') }}</p>
            <button type="button" class="pl-btn" @click="ackAlert"><span>{{ $t('I understand') }}</span></button>
          </template>

          <template v-else>
            <p class="pl-eyebrow">{{ $t('Prayer time') }}</p>
            <p class="pl-ar" lang="ar" dir="rtl">{{ arabicName(ph.e.key) }}</p>
            <h2 class="pl-title">{{ $t('It is time for') }} {{ ph.e.label }} {{ $t('prayer') }}</h2>
            <p class="pl-text pl-soft">{{ at12(ph.e.at) }} · {{ cityLine }}</p>
            <figure v-if="msg" class="pl-verse">
              <p v-if="msg.arabic" class="pl-verse__ar" lang="ar" dir="rtl">{{ msg.arabic }}</p>
              <blockquote>{{ msg.text }}</blockquote>
              <figcaption>{{ msg.kind === 'ayah' ? $t('Qur\'an') : $t('Hadith') }} · {{ msg.reference }}</figcaption>
            </figure>
            <p v-if="sd" class="pl-lockline"><i></i>{{ $t('WIMS locks in') }} <b class="pl-mono">{{ lockCount }}</b> · {{ $t('back at') }} {{ at12(ph.e.unlock_at) }}</p>
            <p v-else class="pl-lockline"><i></i>{{ $t('Prayer time in') }} <b class="pl-mono">{{ lockCount }}</b></p>
            <div class="pl-actions">
              <button v-if="adhan.enabled" type="button" class="pl-ghost" @click="toggleAdhan"><span class="pl-eq" :class="{ on: playing }"><i></i><i></i><i></i><i></i></span>{{ playing ? $t('Pause adhan') : $t('Play adhan') }}</button>
              <button type="button" class="pl-btn" @click="closePrayer"><span>{{ $t('Close') }}</span></button>
            </div>
          </template>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
import PrayerSky from './PrayerSky.vue';
import { prayerState, phase, countdown, clock12, adhan as adhanState, playAdhan, stopAdhan } from './prayerStore';

const ARABIC = { fajr: 'صلاة الفجر', dhuhr: 'صلاة الظهر', asr: 'صلاة العصر', maghrib: 'صلاة المغرب', isha: 'صلاة العشاء' };

export default {
  components: { PrayerSky },
  data() { return { alertSeen: false, prayerClosed: false, lastKey: '', reloaded: false }; },
  computed: {
    ph() { return phase.value; },
    // only people the prayer lock applies to see the alert and the prayer notice (a preview from Settings is shown to the admin)
    show() { return !!(prayerState.preview || (prayerState.data && prayerState.data.in_scope)); },
    modal() {
      if (this.ph.phase === 'alert' && !this.alertSeen) return 'alert';
      if (this.ph.phase === 'prayer' && !this.prayerClosed) return 'prayer';
      return '';
    },
    // true: Alert and Shutdown (WIMS locks at the prayer time). false: Alert Only, nobody is signed out
    sd() { return !(prayerState.data && prayerState.data.shutdown === false); },
    lockCount() { return this.ph.e ? countdown(this.ph.e.lockT) : ''; },
    adhan() { return (prayerState.data && prayerState.data.adhan) || { enabled: false }; },
    playing() { return adhanState.playing; },
    topbarOn() { return !!(prayerState.data && prayerState.data.topbar); },
    msg() { return this.ph.e && this.ph.e.message; },
    cityLine() { return prayerState.data ? prayerState.data.city + ' (EAT)' : ''; },
  },
  // The layout remounts on every page visit. Read what this person already acknowledged BEFORE the first paint,
  // otherwise the alert dialogue would flash for a moment on every page and then disappear.
  created() {
    const p = this.ph;
    this.lastKey = this.keyOf(p);
    this.loadSeen(p);
    if (p.phase === 'prayer' && !this.prayerClosed) this.$nextTick(() => this.startAdhan());
  },
  watch: {
    ph: {
      deep: false,
      handler(p) {
        const key = this.keyOf(p);
        if (key !== this.lastKey) {
          this.lastKey = key;
          this.alertSeen = false; this.prayerClosed = false;
          this.loadSeen(p);
          if (p.phase === 'prayer' && !this.prayerClosed) this.$nextTick(() => this.startAdhan());
          else this.stopAdhan();
        }
        // the lock begins: the server signs this person out on the next request
        if (this.sd && p.phase === 'locked' && this.show && !prayerState.preview && !this.reloaded && !(prayerState.data && prayerState.data.exempt)) { this.reloaded = true; this.stopAdhan(); window.location.href = '/login?prayer=1'; }
      },
    },
  },
  methods: {
    keyOf(p) { return p.e ? p.phase + '|' + p.e.key + '|' + p.e.at : p.phase; },
    loadSeen(p) {
      if (!p.e) return;
      try { this.alertSeen = sessionStorage.getItem('pl-alert-' + p.e.at) === '1'; this.prayerClosed = sessionStorage.getItem('pl-prayer-' + p.e.at) === '1'; } catch (e) { /* no storage */ }
    },
    at12(iso) { return clock12(new Date(iso).toLocaleTimeString('en-GB', { timeZone: 'Africa/Dar_es_Salaam', hour: '2-digit', minute: '2-digit' })); },
    arabicName(k) { return ARABIC[k] || ''; },
    ringOffset(from, to) { const f = Math.min(1, Math.max(0, (prayerState.now - from) / Math.max(1, to - from))); return (327 * f).toFixed(1); },
    ackAlert() { this.alertSeen = true; try { sessionStorage.setItem('pl-alert-' + this.ph.e.at, '1'); } catch (e) { /* no storage */ } },
    closePrayer() { this.prayerClosed = true; this.stopAdhan(); try { sessionStorage.setItem('pl-prayer-' + this.ph.e.at, '1'); } catch (e) { /* no storage */ } if (prayerState.preview) prayerState.preview = null; },
    startAdhan() { if (this.adhan.enabled) playAdhan(); },
    toggleAdhan() { if (adhanState.playing) stopAdhan(); else playAdhan(true); },
    stopAdhan() { stopAdhan(); },
  },
};
</script>

<style>
/* Light by default (soft greens and oranges); the dark look is kept for people who choose WIMS dark mode. */
.pl-mono { font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }
.pl-backdrop { --pl-ink: #14532d; --pl-text: #166534; --pl-soft: #5b7a66; --pl-gold: #c2410c; --pl-verse-bg: rgba(255, 255, 255, 0.72); --pl-verse-line: rgba(234, 88, 12, 0.3); --pl-verse-text: #334155; --pl-ring-bg: rgba(22, 101, 52, 0.12); --pl-ring: #f97316; --pl-btn-text: #fff; --pl-ghost: rgba(22, 163, 74, 0.1); --pl-ghost-line: rgba(22, 163, 74, 0.35); --pl-ghost-text: #166534; --pl-star: #f59e0b;
  position: fixed; inset: 0; z-index: 10050; display: grid; place-items: center; padding: 1rem; background: radial-gradient(120% 90% at 50% 0%, rgba(220, 252, 231, 0.82), rgba(255, 237, 213, 0.86)); backdrop-filter: blur(8px); }
.pl-card { position: relative; width: min(500px, 100%); max-height: calc(100vh - 2rem); overflow: auto; padding: 2.2rem 1.6rem 1.5rem; border-radius: 28px; text-align: center; color: var(--pl-ink); background: linear-gradient(165deg, #f0fdf4 0%, #fffbeb 55%, #fff1e0 100%); box-shadow: 0 40px 80px -34px rgba(22, 101, 52, 0.55), 0 0 0 1px rgba(34, 197, 94, 0.35), inset 0 0 60px rgba(251, 191, 36, 0.1); animation: pl-pop 0.6s cubic-bezier(0.2, 1.2, 0.3, 1) both; }
.pl-card.is-alert { background: linear-gradient(165deg, #fff7ed 0%, #fffbeb 55%, #f0fdf4 100%); box-shadow: 0 40px 80px -34px rgba(194, 65, 12, 0.5), 0 0 0 1px rgba(251, 146, 60, 0.5), inset 0 0 60px rgba(251, 146, 60, 0.1); }
.pl-arch { position: absolute; left: 50%; top: -2px; width: 220px; height: 60px; transform: translateX(-50%); border-bottom-left-radius: 120px 60px; border-bottom-right-radius: 120px 60px; background: radial-gradient(60% 100% at 50% 0, rgba(251, 146, 60, 0.28), transparent); pointer-events: none; }
.pl-stars { position: absolute; inset: 0; overflow: hidden; border-radius: inherit; pointer-events: none; }
.pl-stars i { position: absolute; left: var(--x); top: var(--y); width: calc(var(--s) * 2px); height: calc(var(--s) * 2px); border-radius: 50%; background: var(--pl-star); opacity: 0; animation: pl-twinkle 3s ease-in-out infinite; animation-delay: var(--d); }
.pl-moon { position: relative; width: 84px; height: 84px; margin: 0 auto 0.6rem; filter: drop-shadow(0 6px 14px rgba(245, 158, 11, 0.55)); animation: pl-float 4s ease-in-out infinite; }
.pl-star5 { transform-origin: 92px 48px; animation: pl-spin 9s linear infinite; }
.pl-eyebrow { margin: 0; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.22em; text-transform: uppercase; color: var(--pl-gold); }
.pl-ar { margin: 0.2rem 0 0; font-size: 1.7rem; font-weight: 700; color: #b45309; font-family: 'Amiri', 'Scheherazade New', 'Noto Naskh Arabic', serif; }
.pl-title { margin: 0.35rem 0 0.5rem; font-size: 1.35rem; font-weight: 800; line-height: 1.25; color: var(--pl-ink); }
.pl-text { margin: 0.35rem 0; font-size: 0.9rem; line-height: 1.5; color: var(--pl-text); } .pl-text b { color: #c2410c; } .pl-soft { color: var(--pl-soft); font-size: 0.82rem; }
.pl-ring { position: relative; width: 132px; height: 132px; margin: 0.6rem auto; } .pl-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.pl-ring circle { fill: none; stroke-width: 7; } .pl-ring__bg { stroke: var(--pl-ring-bg); } .pl-ring__fg { stroke: var(--pl-ring); stroke-linecap: round; stroke-dasharray: 327; filter: drop-shadow(0 0 5px rgba(249, 115, 22, 0.55)); transition: stroke-dashoffset 1s linear; }
.pl-ring__t { position: absolute; inset: 0; display: grid; place-content: center; } .pl-ring__t b { font-size: 1.6rem; color: var(--pl-ink); } .pl-ring__t small { font-size: 0.62rem; color: var(--pl-gold); letter-spacing: 0.1em; text-transform: uppercase; }
.pl-verse { margin: 0.9rem 0 0.4rem; padding: 1rem 1rem 0.8rem; border-radius: 18px; background: var(--pl-verse-bg); border: 1px solid var(--pl-verse-line); text-align: center; animation: pl-rise 0.7s 0.3s ease both; }
.pl-verse__ar { margin: 0 0 0.5rem; font-size: 1.55rem; line-height: 2.1; color: #14532d; font-family: 'Amiri', 'Scheherazade New', 'Noto Naskh Arabic', serif; }
.pl-verse blockquote { margin: 0; font-size: 0.92rem; line-height: 1.55; font-style: italic; color: var(--pl-verse-text); } .pl-verse figcaption { margin-top: 0.5rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; color: var(--pl-gold); }
.pl-lockline { display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin: 0.7rem 0 0.2rem; font-size: 0.8rem; color: #9a3412; } .pl-lockline b { color: #7c2d12; } .pl-lockline i { width: 8px; height: 8px; border-radius: 50%; background: #f97316; animation: pl-blink 1s ease-in-out infinite; }
.pl-actions { display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: center; margin-top: 0.9rem; }
.pl-btn, .pl-ghost { position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.7rem 1.4rem; border-radius: 14px; font-weight: 800; font-size: 0.88rem; cursor: pointer; border: 0; transition: transform 0.15s ease, box-shadow 0.2s ease; }
.pl-btn { color: var(--pl-btn-text); background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 10px 22px -10px rgba(234, 88, 12, 0.85); } .pl-btn:hover { transform: translateY(-1px); box-shadow: 0 14px 26px -10px rgba(234, 88, 12, 0.95); }
.pl-btn::after { content: ''; position: absolute; top: 0; bottom: 0; left: -60%; width: 40%; transform: skewX(-20deg); background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent); animation: pl-shine 3s ease-in-out infinite; }
.pl-ghost { color: var(--pl-ghost-text); background: var(--pl-ghost); box-shadow: inset 0 0 0 1px var(--pl-ghost-line); } .pl-ghost:hover { transform: translateY(-1px); background: rgba(22, 163, 74, 0.18); }
.pl-eq { display: inline-flex; align-items: flex-end; gap: 2px; height: 14px; } .pl-eq i { width: 3px; height: 4px; border-radius: 2px; background: #16a34a; } .pl-eq.on i { animation: pl-eq 0.8s ease-in-out infinite; } .pl-eq i:nth-child(2) { animation-delay: 0.15s; } .pl-eq i:nth-child(3) { animation-delay: 0.3s; } .pl-eq i:nth-child(4) { animation-delay: 0.45s; }
/* the small countdown after the alert is acknowledged: bottom of the screen, so it never sits on the top bar */
.pl-bar { position: fixed; left: 50%; bottom: 18px; transform: translateX(-50%); z-index: 10040; display: flex; align-items: center; gap: 0.7rem; padding: 0.5rem 1.1rem; border-radius: 99px; color: #7c2d12; font-size: 0.82rem; background: linear-gradient(90deg, #fff7ed, #ecfdf5); box-shadow: 0 14px 30px -14px rgba(194, 65, 12, 0.6), 0 0 0 1.5px rgba(251, 146, 60, 0.6); max-width: calc(100vw - 20px); }
.pl-bar small { color: #5b7a66; } .pl-bar b { color: #c2410c; } .pl-bar__moon { width: 14px; height: 14px; border-radius: 50%; box-shadow: inset -5px -2px 0 0 #f59e0b; animation: pl-blink 1.4s ease-in-out infinite; }
.pl-fade-enter-active, .pl-fade-leave-active { transition: opacity 0.35s ease; }
@media (min-width: 1366px) { .pl-bar--pilled { display: none; } } /* the top-bar pill already counts down on wide screens */ .pl-fade-enter-from, .pl-fade-leave-to { opacity: 0; }
.pl-bar-enter-active, .pl-bar-leave-active { transition: all 0.4s ease; } .pl-bar-enter-from, .pl-bar-leave-to { opacity: 0; transform: translate(-50%, 20px); }
@keyframes pl-pop { from { opacity: 0; transform: translateY(24px) scale(0.92); } to { opacity: 1; transform: none; } }
@keyframes pl-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes pl-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
@keyframes pl-spin { to { transform: rotate(360deg); } } @keyframes pl-blink { 0%, 100% { opacity: 0.35; } 50% { opacity: 1; } }
@keyframes pl-twinkle { 0%, 100% { opacity: 0; transform: scale(0.6); } 50% { opacity: 0.9; transform: scale(1.2); } }
@keyframes pl-shine { 0%, 55% { left: -60%; } 100% { left: 140%; } } @keyframes pl-eq { 0%, 100% { height: 4px; } 50% { height: 14px; } }

/* WIMS dark mode */
html.dark .pl-backdrop { --pl-ink: #ecfdf5; --pl-text: #d1fae5; --pl-soft: #94a3b8; --pl-gold: #fcd34d; --pl-verse-bg: rgba(255, 255, 255, 0.06); --pl-verse-line: rgba(250, 204, 21, 0.25); --pl-verse-text: #e2e8f0; --pl-ring-bg: rgba(255, 255, 255, 0.12); --pl-ring: #fbbf24; --pl-btn-text: #422006; --pl-ghost: rgba(255, 255, 255, 0.08); --pl-ghost-line: rgba(255, 255, 255, 0.25); --pl-ghost-text: #ecfdf5; --pl-star: #fde68a; background: radial-gradient(120% 90% at 50% 0%, rgba(6, 78, 59, 0.78), rgba(2, 6, 23, 0.9)); }
html.dark .pl-card { background: linear-gradient(165deg, #0b3b34 0%, #0a2a40 55%, #07142b 100%); box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(250, 204, 21, 0.35), inset 0 0 60px rgba(16, 185, 129, 0.12); }
html.dark .pl-card.is-alert { background: linear-gradient(165deg, #3b2a0b 0%, #0a2a40 55%, #07142b 100%); box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(251, 146, 60, 0.5), inset 0 0 60px rgba(251, 146, 60, 0.12); }
html.dark .pl-ar { color: #fde68a; } html.dark .pl-text b { color: #fde68a; } html.dark .pl-verse__ar { color: #fff; } html.dark .pl-lockline { color: #fed7aa; } html.dark .pl-lockline b { color: #fff; }
html.dark .pl-btn { background: linear-gradient(135deg, #fde68a, #f59e0b); } html.dark .pl-eq i { background: #34d399; }
html.dark .pl-bar { color: #fff7ed; background: linear-gradient(90deg, #7c2d12, #b45309); box-shadow: 0 12px 30px -12px rgba(124, 45, 18, 0.9), 0 0 0 1px rgba(253, 186, 116, 0.5); } html.dark .pl-bar small { color: #fed7aa; } html.dark .pl-bar b { color: #fde68a; }
@media (prefers-reduced-motion: reduce) { .pl-card, .pl-moon, .pl-star5, .pl-stars i, .pl-btn::after, .pl-verse { animation: none; } .pl-stars i { opacity: 0.6; } }
</style>
