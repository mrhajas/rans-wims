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
            <!-- ongoing, gentle motion behind the text: a slowly turning eight-point star and light rising like embers -->
            <svg class="pl-star8" viewBox="0 0 100 100" aria-hidden="true"><path d="M50 2 61 27 88 12 73 39 98 50 73 61 88 88 61 73 50 98 39 73 12 88 27 61 2 50 27 39 12 12 39 27Z" /><circle cx="50" cy="50" r="22" /></svg>
            <span class="pl-embers" aria-hidden="true"><i v-for="n in 9" :key="n" :style="{ '--x': (n * 11 + 4) % 96 + '%', '--d': (n % 5) * 1.3 + 's', '--t': 6 + (n % 4) * 1.5 + 's', '--s': 3 + (n % 3) * 2 + 'px' }"></i></span>
            <p class="pl-eyebrow">{{ sd ? $t('Shutdown alert') : $t('Prayer reminder') }}</p>
            <h2 class="pl-title">{{ sd ? $t('WIMS will lock for') + ' ' + ph.e.label + ' ' + $t('prayer') : ph.e.label + ' ' + $t('prayer is coming') }}</h2>
            <div class="pl-ring">
              <span class="pl-ripples" aria-hidden="true"><i></i><i></i><i></i></span>
              <span class="pl-orbit" aria-hidden="true"><i></i></span>
              <svg viewBox="0 0 120 120"><circle class="pl-ring__bg" cx="60" cy="60" r="52" /><circle class="pl-ring__fg" cx="60" cy="60" r="52" :style="{ strokeDashoffset: ringOffset(ph.e.alertT, ph.e.lockT) }" /></svg>
              <div class="pl-ring__t"><b class="pl-mono">{{ lockCount }}</b><small>{{ sd ? $t('until the lock') : $t('until the prayer') }}</small></div>
            </div>
            <p v-if="!sd" class="pl-text">{{ $t('The prayer time is') }} <b>{{ at12(ph.e.at) }}</b>. {{ $t('WIMS stays open, this is a reminder only.') }}</p>
            <p v-else class="pl-text">{{ $t('WIMS locks at') }} <b>{{ at12(ph.e.lock_at) }}</b> {{ $t('and everyone is signed out. You can sign in again at') }} <b>{{ at12(ph.e.unlock_at) }}</b>.</p>
            <p v-if="sd" class="pl-text pl-soft">{{ $t('Please finish what you are doing and save your work.') }}</p>
            <p v-else class="pl-calm"><span class="pl-calm__dots" aria-hidden="true"><i></i><i></i><i></i></span>{{ $t('Take a calm moment to prepare. Your work stays safe while you pray.') }}</p>
            <button type="button" class="pl-btn" @click="ackAlert"><span>{{ $t('I understand') }}</span></button>
          </template>

          <template v-else>
            <svg class="pl-star8" viewBox="0 0 100 100" aria-hidden="true"><path d="M50 2 61 27 88 12 73 39 98 50 73 61 88 88 61 73 50 98 39 73 12 88 27 61 2 50 27 39 12 12 39 27Z" /><circle cx="50" cy="50" r="22" /></svg>
            <span class="pl-embers" aria-hidden="true"><i v-for="n in 9" :key="n" :style="{ '--x': (n * 11 + 4) % 96 + '%', '--d': (n % 5) * 1.3 + 's', '--t': 6 + (n % 4) * 1.5 + 's', '--s': 3 + (n % 3) * 2 + 'px' }"></i></span>
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
            <!-- the adhan now playing: who recites it, and waves that move with the sound -->
            <div v-if="adhan.enabled" class="pl-player" :class="{ 'is-on': playing }">
              <div class="pl-player__who"><span class="pl-player__ico"><span class="pl-eq" :class="{ on: playing }"><i></i><i></i><i></i><i></i></span></span><span class="pl-player__txt"><small>{{ playing ? $t('Now playing') : $t('Adhan') }}</small><b>{{ reciter }}</b></span></div>
              <adhan-waves />
            </div>
            <div class="pl-actions">
              <button v-if="adhan.enabled" type="button" class="pl-ghost" :class="{ 'is-playing': playing }" :disabled="playing" :aria-disabled="playing" @click="startAdhanAgain">
                <span v-if="playing" class="pl-eq on"><i></i><i></i><i></i><i></i></span>
                <svg v-else class="pl-playico" viewBox="0 0 20 20" aria-hidden="true"><path d="M6 4.2v11.6a.8.8 0 0 0 1.2.7l9.4-5.8a.8.8 0 0 0 0-1.4L7.2 3.5A.8.8 0 0 0 6 4.2Z" /></svg>
                {{ playing ? $t('Adhan playing') : $t('Play adhan') }}
              </button>
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
import AdhanWaves from './AdhanWaves.vue';
import { prayerState, phase, countdown, clock12, adhan as adhanState, playAdhan, stopAdhan } from './prayerStore';

const ARABIC = { fajr: 'صلاة الفجر', dhuhr: 'صلاة الظهر', asr: 'صلاة العصر', maghrib: 'صلاة المغرب', isha: 'صلاة العشاء' };

export default {
  components: { PrayerSky, AdhanWaves },
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
    // the stored name may be written without spaces (MuftMenk): show it as words
    reciter() { const l = (this.adhan && this.adhan.label) || ''; return l ? l.replace(/([a-z])([A-Z])/g, '$1 $2') : this.$t('Adhan'); },
    topbarOn() { return !!(prayerState.data && prayerState.data.topbar); },
    msg() { return this.ph.e && this.ph.e.message; },
    cityLine() { return prayerState.data ? prayerState.data.city + ' (EAT)' : ''; },
  },
  // The layout remounts on every page visit. Read what this person already acknowledged BEFORE the first paint,
  // otherwise the alert dialogue would flash for a moment on every page and then disappear.
  mounted() { window.addEventListener('keydown', this.onKey); },
  beforeUnmount() { window.removeEventListener('keydown', this.onKey); },
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
    // only offered when the adhan is not playing (it ended or was stopped): starts it again from the beginning
    startAdhanAgain() { if (!adhanState.playing) playAdhan(true); },
    onKey(e) { if (e.key !== 'Escape' || !this.show) return; if (this.modal === 'alert') { e.preventDefault(); this.ackAlert(); } else if (this.modal === 'prayer') { e.preventDefault(); this.closePrayer(); } },
    stopAdhan() { stopAdhan(); },
  },
};
</script>

<style>
/* Light by default (soft greens and oranges); the dark look is kept for people who choose WIMS dark mode. */
.pl-mono { font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }
.pl-backdrop { --pl-ink: #14532d; --pl-text: #166534; --pl-soft: #5b7a66; --pl-gold: #c2410c; --pl-verse-bg: rgba(255, 255, 255, 0.72); --pl-verse-line: rgba(234, 88, 12, 0.3); --pl-verse-text: #334155; --pl-ring-bg: rgba(22, 101, 52, 0.12); --pl-ring: #f97316; --pl-btn-text: #fff; --pl-ghost: rgba(22, 163, 74, 0.1); --pl-ghost-line: rgba(22, 163, 74, 0.35); --pl-ghost-text: #166534; --pl-star: #f59e0b;
  position: fixed; inset: 0; z-index: 10050; display: grid; place-items: center; padding: 1rem; background: radial-gradient(120% 90% at 50% 0%, rgba(220, 252, 231, 0.82), rgba(255, 237, 213, 0.86)); backdrop-filter: blur(8px); }
.pl-card { position: relative; width: min(500px, 100%); max-height: calc(100vh - 2rem); max-height: calc(100dvh - 2rem); overflow: hidden; padding: 2.2rem 1.6rem 1.5rem; border-radius: 28px; text-align: center; color: var(--pl-ink); background: linear-gradient(165deg, #f0fdf4 0%, #fffbeb 55%, #fff1e0 100%); box-shadow: 0 40px 80px -34px rgba(22, 101, 52, 0.55), 0 0 0 1px rgba(34, 197, 94, 0.35), inset 0 0 60px rgba(251, 191, 36, 0.1); animation: pl-pop 0.6s cubic-bezier(0.2, 1.2, 0.3, 1) both; }
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
.pl-ring { position: relative; width: 156px; height: 156px; margin: 0.8rem auto; } .pl-ring svg { position: relative; z-index: 1; width: 100%; height: 100%; transform: rotate(-90deg); }
.pl-ring circle { fill: none; stroke-width: 7; } .pl-ring__bg { stroke: var(--pl-ring-bg); } .pl-ring__fg { stroke: var(--pl-ring); stroke-linecap: round; stroke-dasharray: 327; filter: drop-shadow(0 0 5px rgba(249, 115, 22, 0.55)); transition: stroke-dashoffset 1s linear; }
.pl-ring__t { position: absolute; inset: 0; z-index: 2; display: grid; place-content: center; justify-items: center; } .pl-ring__t b { font-size: 1.75rem; line-height: 1.1; color: var(--pl-ink); animation: pl-breathe 2s ease-in-out infinite; } .pl-ring__t small { max-width: 92px; margin: 3px auto 0; font-size: 0.56rem; font-weight: 800; line-height: 1.3; color: var(--pl-gold); letter-spacing: 0.08em; text-align: center; text-transform: uppercase; text-wrap: balance; }
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


/* ongoing motion of the alert */
.pl-star8 { position: absolute; left: 50%; top: 34%; width: 380px; height: 380px; margin: -190px 0 0 -190px; fill: none; stroke: #ea580c; stroke-width: 0.5; opacity: 0.1; pointer-events: none; animation: pl-spin 80s linear infinite; }
.pl-star8 circle { stroke: #16a34a; stroke-width: 0.4; }
.pl-embers { position: absolute; inset: 0; overflow: hidden; border-radius: inherit; pointer-events: none; }
.pl-embers i { position: absolute; bottom: -12px; left: var(--x); width: var(--s); height: var(--s); border-radius: 50%; background: radial-gradient(circle, #fdba74, #f97316); box-shadow: 0 0 10px 2px rgba(249, 115, 22, 0.55); opacity: 0; animation: pl-ember var(--t) ease-in infinite; animation-delay: var(--d); }
.pl-ripples i { position: absolute; inset: 6px; border-radius: 50%; border: 2px solid rgba(249, 115, 22, 0.5); opacity: 0; animation: pl-ripple 3.6s ease-out infinite; } .pl-ripples i:nth-child(2) { animation-delay: 1.2s; border-color: rgba(22, 163, 74, 0.45); } .pl-ripples i:nth-child(3) { animation-delay: 2.4s; }
.pl-orbit { position: absolute; inset: 0; z-index: 3; pointer-events: none; animation: pl-spin 7s linear infinite; }
.pl-orbit i { position: absolute; left: 50%; top: 2px; width: 10px; height: 10px; margin-left: -5px; border-radius: 50%; background: #fff; box-shadow: 0 0 0 3px rgba(251, 146, 60, 0.55), 0 0 14px 4px rgba(249, 115, 22, 0.8); }
.pl-calm { display: inline-flex; align-items: center; gap: 0.6rem; max-width: 100%; margin: 0.5rem 0 0.15rem; padding: 0.55rem 1rem; border-radius: 16px; font-size: 0.86rem; font-weight: 700; line-height: 1.4; color: #166534; background: linear-gradient(120deg, rgba(220, 252, 231, 0.85), rgba(255, 237, 213, 0.85), rgba(220, 252, 231, 0.85)); background-size: 200% 100%; box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.25), 0 10px 20px -14px rgba(22, 163, 74, 0.8); animation: pl-sheen 6s ease-in-out infinite, pl-breathe 4s ease-in-out infinite; }
.pl-calm__dots { display: inline-flex; gap: 3px; flex: none; } .pl-calm__dots i { width: 6px; height: 6px; border-radius: 50%; background: #16a34a; animation: pl-wave 1.2s ease-in-out infinite; } .pl-calm__dots i:nth-child(2) { animation-delay: 0.15s; background: #f59e0b; } .pl-calm__dots i:nth-child(3) { animation-delay: 0.3s; background: #ea580c; }
.pl-card.is-alert .pl-btn { animation: pl-pulse 2.4s ease-in-out infinite; }
.pl-card > *:not(.pl-stars):not(.pl-star8):not(.pl-embers):not(.pl-arch) { position: relative; z-index: 1; }
@keyframes pl-ripple { 0% { transform: scale(0.82); opacity: 0.55; } 100% { transform: scale(1.45); opacity: 0; } }
@keyframes pl-ember { 0% { transform: translate(0, 0) scale(0.6); opacity: 0; } 15% { opacity: 0.9; } 100% { transform: translate(12px, -520px) scale(1.1); opacity: 0; } }
@keyframes pl-breathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.04); } }
@keyframes pl-sheen { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
@keyframes pl-wave { 0%, 100% { transform: translateY(0); opacity: 0.6; } 50% { transform: translateY(-4px); opacity: 1; } }
@keyframes pl-pulse { 0%, 100% { box-shadow: 0 10px 22px -10px rgba(234, 88, 12, 0.85), 0 0 0 0 rgba(249, 115, 22, 0.45); } 50% { box-shadow: 0 10px 22px -10px rgba(234, 88, 12, 0.85), 0 0 0 10px rgba(249, 115, 22, 0); } }
html.dark .pl-calm { color: #d1fae5; background: linear-gradient(120deg, rgba(6, 78, 59, 0.6), rgba(124, 45, 18, 0.5), rgba(6, 78, 59, 0.6)); background-size: 200% 100%; }
html.dark .pl-star8 { opacity: 0.16; stroke: #fcd34d; }

/* the adhan player of the prayer notice, and the shimmer of the Arabic name */
.pl-player { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: 0.5rem 1rem; margin: 0.75rem 0 0; padding: 0.6rem 0.9rem; border-radius: 18px; text-align: left; background: linear-gradient(120deg, rgba(220, 252, 231, 0.9), rgba(255, 237, 213, 0.9)); box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.28), 0 12px 22px -16px rgba(22, 163, 74, 0.9); }
.pl-player.is-on { animation: pl-player-glow 2.4s ease-in-out infinite; }
.pl-player__who { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
.pl-player__ico { flex: none; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 12px; color: #16a34a; background: #fff; box-shadow: inset 0 0 0 1.5px #16a34a, 0 6px 10px -6px #16a34a; }
.pl-player.is-on .pl-player__ico { animation: pl-breathe 1.2s ease-in-out infinite; }
.pl-player__txt { display: grid; min-width: 0; line-height: 1.2; } .pl-player__txt small { font-size: 0.58rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: var(--pl-gold); } .pl-player__txt b { font-size: 0.92rem; color: var(--pl-ink); overflow-wrap: anywhere; }
@media (max-width: 420px) { .pl-player { grid-template-columns: 1fr; } }
.pl-ar { background: linear-gradient(100deg, #b45309 0%, #b45309 40%, #f59e0b 50%, #b45309 60%, #b45309 100%); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; animation: pl-sheen-text 5s ease-in-out infinite; }
.pl-verse { animation: pl-rise 0.7s 0.3s ease both, pl-verse-glow 5s ease-in-out 1s infinite; }
@keyframes pl-player-glow { 0%, 100% { box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.28), 0 12px 22px -16px rgba(22, 163, 74, 0.9); } 50% { box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.6), 0 14px 28px -12px rgba(245, 158, 11, 0.9); } }
@keyframes pl-sheen-text { 0%, 100% { background-position: 100% 50%; } 50% { background-position: 0% 50%; } }
@keyframes pl-verse-glow { 0%, 100% { box-shadow: 0 0 0 0 rgba(234, 88, 12, 0); } 50% { box-shadow: 0 10px 24px -14px rgba(234, 88, 12, 0.55); } }
html.dark .pl-player { background: linear-gradient(120deg, rgba(6, 78, 59, 0.6), rgba(124, 45, 18, 0.5)); } html.dark .pl-player__ico { background: transparent; color: #34d399; box-shadow: inset 0 0 0 1.5px #34d399; }
html.dark .pl-ar { background: linear-gradient(100deg, #fde68a 0%, #fde68a 40%, #fff 50%, #fde68a 60%, #fde68a 100%); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }

/* WIMS dark mode */
html.dark .pl-backdrop { --pl-ink: #ecfdf5; --pl-text: #d1fae5; --pl-soft: #94a3b8; --pl-gold: #fcd34d; --pl-verse-bg: rgba(255, 255, 255, 0.06); --pl-verse-line: rgba(250, 204, 21, 0.25); --pl-verse-text: #e2e8f0; --pl-ring-bg: rgba(255, 255, 255, 0.12); --pl-ring: #fbbf24; --pl-btn-text: #422006; --pl-ghost: rgba(255, 255, 255, 0.08); --pl-ghost-line: rgba(255, 255, 255, 0.25); --pl-ghost-text: #ecfdf5; --pl-star: #fde68a; background: radial-gradient(120% 90% at 50% 0%, rgba(6, 78, 59, 0.78), rgba(2, 6, 23, 0.9)); }
html.dark .pl-card { background: linear-gradient(165deg, #0b3b34 0%, #0a2a40 55%, #07142b 100%); box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(250, 204, 21, 0.35), inset 0 0 60px rgba(16, 185, 129, 0.12); }
html.dark .pl-card.is-alert { background: linear-gradient(165deg, #3b2a0b 0%, #0a2a40 55%, #07142b 100%); box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(251, 146, 60, 0.5), inset 0 0 60px rgba(251, 146, 60, 0.12); }
html.dark .pl-ar { color: #fde68a; } html.dark .pl-text b { color: #fde68a; } html.dark .pl-verse__ar { color: #fff; } html.dark .pl-lockline { color: #fed7aa; } html.dark .pl-lockline b { color: #fff; }
html.dark .pl-btn { background: linear-gradient(135deg, #fde68a, #f59e0b); } html.dark .pl-eq i { background: #34d399; }
html.dark .pl-bar { color: #fff7ed; background: linear-gradient(90deg, #7c2d12, #b45309); box-shadow: 0 12px 30px -12px rgba(124, 45, 18, 0.9), 0 0 0 1px rgba(253, 186, 116, 0.5); } html.dark .pl-bar small { color: #fed7aa; } html.dark .pl-bar b { color: #fde68a; }
@media (prefers-reduced-motion: reduce) { .pl-card, .pl-moon, .pl-star5, .pl-stars i, .pl-btn::after, .pl-verse, .pl-star8, .pl-embers i, .pl-ripples i, .pl-orbit, .pl-calm, .pl-calm__dots i, .pl-card.is-alert .pl-btn, .pl-ring__t b, .pl-player.is-on, .pl-player.is-on .pl-player__ico, .pl-ar, .pl-verse { animation: none; } .pl-stars i { opacity: 0.6; } .pl-embers, .pl-ripples { display: none; } }

/* no scrolling inside the popups: on a short screen the spacing and the sizes shrink instead */
.pl-backdrop { overflow: hidden; }
.pl-card.is-alert .pl-btn { margin-top: 1.1rem; }
.pl-card.is-alert .pl-calm { margin-bottom: 0.25rem; }
.pl-ghost[disabled] { cursor: default; opacity: 1; transform: none; color: #166534; background: rgba(22, 163, 74, 0.14); }
.pl-ghost.is-playing { box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.45), 0 0 0 4px rgba(22, 163, 74, 0.1); }
.pl-playico { width: 14px; height: 14px; fill: currentColor; }
.pl-player .aw { height: 64px; grid-column: 1 / -1; }
@media (max-height: 820px) {
  .pl-card { padding: 1.5rem 1.4rem 1.1rem; }
  .pl-card .sky { width: 64px; height: 64px; margin-bottom: 0.3rem; }
  .pl-title { font-size: 1.2rem; margin: 0.2rem 0 0.35rem; }
  .pl-ring { width: 128px; height: 128px; margin: 0.45rem auto; } .pl-ring__t b { font-size: 1.5rem; }
  .pl-verse { margin: 0.5rem 0 0.25rem; padding: 0.65rem 0.8rem 0.55rem; } .pl-verse__ar { font-size: 1.3rem; line-height: 1.8; margin-bottom: 0.3rem; } .pl-verse blockquote { font-size: 0.85rem; line-height: 1.45; }
  .pl-player { margin-top: 0.5rem; padding: 0.45rem 0.8rem; } .pl-player .aw { height: 54px; }
  .pl-actions { margin-top: 0.6rem; } .pl-calm { margin-top: 0.3rem; padding: 0.45rem 0.85rem; font-size: 0.82rem; }
  .pl-card.is-alert .pl-btn { margin-top: 0.8rem; }
}
@media (max-height: 640px) {
  .pl-card { padding: 1rem 1.1rem 0.8rem; }
  .pl-card .sky { width: 46px; height: 46px; margin-bottom: 0.15rem; }
  .pl-ring { width: 104px; height: 104px; } .pl-ring__t b { font-size: 1.3rem; }
  .pl-verse__ar { font-size: 1.1rem; line-height: 1.6; } .pl-verse blockquote { font-size: 0.78rem; } .pl-text { font-size: 0.82rem; margin: 0.2rem 0; }
  .pl-player .aw { height: 42px; } .pl-btn, .pl-ghost { padding: 0.5rem 1rem; }
}
</style>
