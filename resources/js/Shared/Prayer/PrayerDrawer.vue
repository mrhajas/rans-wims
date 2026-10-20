<template>
  <teleport to="body">
    <transition name="pd">
      <div v-if="open" class="pd-wrap" role="dialog" aria-modal="true" :aria-label="$t('Prayer times')" @keydown.esc="close">
        <div class="pd-scrim" @click="close"></div>
        <aside class="pd" ref="panel" tabindex="-1">
          <header class="pd-head">
            <span class="pd-moon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15.5 3.2a8.8 8.8 0 1 0 5.3 15.8A7.2 7.2 0 0 1 15.5 3.2z" fill="currentColor" /></svg></span>
            <div class="min-w-0"><h2>{{ $t('Prayer times') }}</h2><p>{{ d.city }} · {{ dateLabel }}</p></div>
            <span class="pd-clock"><b>{{ clockNow }}</b><small>EAT</small></span>
            <button type="button" class="pd-x" :aria-label="$t('Close')" @click="close">×</button>
          </header>

          <div class="pd-body">
            <!-- where we are -->
            <section class="pd-status" :class="'is-' + ph.phase">
              <template v-if="ph.phase === 'alert'">
                <small>{{ sd ? $t('Shutdown alert') : $t('Prayer reminder') }}</small>
                <h3>{{ sd ? $t('WIMS locks in') : $t('Prayer time in') }} <b>{{ cd(ph.e.lockT) }}</b></h3>
                <p v-if="sd">{{ ph.e.label }} · {{ $t('locks at') }} {{ at12iso(ph.e.lock_at) }} · {{ $t('sign-in opens at') }} {{ at12iso(ph.e.unlock_at) }}</p>
                <p v-else>{{ ph.e.label }} · {{ at12iso(ph.e.at) }} · {{ $t('WIMS stays open') }}</p>
              </template>
              <template v-else-if="ph.phase === 'prayer'">
                <small>{{ $t('Prayer time') }}</small>
                <h3>{{ $t('It is time for') }} {{ ph.e.label }} <b>{{ sd ? $t('locks in') : $t('in') }} {{ cd(ph.e.lockT) }}</b></h3>
                <p v-if="sd">{{ at12iso(ph.e.at) }} · {{ $t('sign-in opens at') }} {{ at12iso(ph.e.unlock_at) }}</p>
                <p v-else>{{ at12iso(ph.e.at) }} · {{ $t('WIMS stays open') }}</p>
              </template>
              <template v-else-if="next">
                <small>{{ $t('Next prayer') }}</small>
                <h3>{{ next.label }} <b>{{ at12(next.time) }}</b></h3>
                <p>{{ $t('in') }} {{ rest }}</p>
                <div class="pd-bar"><span :style="{ width: progress + '%' }"></span></div>
              </template>
            </section>

            <!-- adhan -->
            <section v-if="d.adhan && d.adhan.url" class="pd-card pd-adhan" :class="{ on: player.playing }">
              <div class="pd-adhan__top">
                <span class="pd-eq" :class="{ on: player.playing }"><i></i><i></i><i></i><i></i><i></i></span>
                <div class="min-w-0"><h4>{{ $t('Adhan') }}</h4><p>{{ player.playing ? $t('Playing now') : (d.adhan.label || $t('Call to prayer')) }}</p></div>
                <button v-if="player.playing" type="button" class="pd-btn pd-btn--stop" @click="stop"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="4.5" y="4.5" width="11" height="11" rx="2" fill="currentColor" /></svg>{{ $t('Stop adhan') }}</button>
                <button v-else type="button" class="pd-btn pd-btn--play" @click="play"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6.5 4.2v11.6a.8.8 0 001.2.7l9-5.8a.8.8 0 000-1.4l-9-5.8a.8.8 0 00-1.2.7z" fill="currentColor" /></svg>{{ $t('Play adhan') }}</button>
              </div>
              <label class="pd-vol"><span>{{ $t('Volume') }}</span><input type="range" min="0" max="100" :value="volume" @input="vol($event.target.value)" /><b>{{ volume }}%</b></label>
              <p v-if="d.adhan.missing" class="pd-note">{{ $t('The recording of :w is not on this server, so :r plays instead.', { w: d.adhan.wanted, r: d.adhan.label }) }}</p>
              <p v-if="player.failed && !player.playing" class="pd-note">{{ $t('The adhan could not be played: its file is missing or cannot be read. Press Play adhan to try again.') }}</p>
              <p v-if="player.blocked" class="pd-note">{{ $t('Your browser blocked the sound. Press Play adhan once to allow it.') }}</p>
              <p v-if="!d.adhan.enabled" class="pd-note">{{ $t('The adhan is switched off in the settings, so it will not play on its own.') }}</p>
            </section>

            <!-- today -->
            <section class="pd-card">
              <h4>{{ $t('Today') }}</h4>
              <ul class="pd-list">
                <li v-for="t in times" :key="t.key" :class="{ next: t.isNext, passed: t.passed && !t.isNext, sun: !t.is_prayer }">
                  <span class="pd-dot"></span><b>{{ t.is_prayer ? t.label : $t('Sunrise') }}</b><time>{{ at12(t.time) }}</time><i v-if="t.isNext">{{ $t('next') }}</i>
                </li>
              </ul>
            </section>

            <!-- verse -->
            <section v-if="verse" class="pd-card pd-verse">
              <h4>{{ verse.kind === 'ayah' ? $t('Qur\'an') : $t('Hadith') }}</h4>
              <p v-if="verse.arabic" class="pd-verse__ar" dir="rtl" lang="ar">{{ verse.arabic }}</p>
              <blockquote>{{ verse.text }}</blockquote>
              <small>{{ verse.reference }}</small>
            </section>

            <!-- how it works for this person -->
            <section class="pd-card pd-how">
              <h4>{{ $t('How the prayer break works') }}</h4>
              <ol>
                <li><b>{{ d.alert_before_min }} {{ $t('min before') }}</b> {{ sd ? $t('a shutdown alert tells you to save your work') : $t('a reminder tells you the prayer is coming') }}</li>
                <li><b>{{ d.notice_before_min }} {{ $t('min left') }}</b> {{ $t('a notice with a verse or hadith appears and the adhan plays') }}</li>
                <template v-if="sd">
                  <li><b>{{ d.lock_start_after_min ? d.lock_start_after_min + ' ' + $t('min after') : $t('At the prayer time') }}</b> {{ $t('WIMS locks and signs everyone out') }}</li>
                  <li><b>{{ d.lock_minutes }} {{ $t('min later') }}</b> {{ $t('you can sign in again') }}</li>
                </template>
                <li v-else><b>{{ $t('At the prayer time') }}</b> {{ $t('the reminder ends. WIMS stays open, nobody is signed out') }}</li>
              </ol>
              <p class="pd-who" :class="d.in_scope ? 'yes' : 'no'">{{ d.in_scope ? $t('This applies to you.') : $t('You are not on the prayer-break list, so WIMS never locks for you.') }}</p>
              <a v-if="canManage" :href="route('prayer.settings')" class="pd-link">{{ $t('Prayer Times settings') }} →</a>
            </section>
          </div>
        </aside>
      </div>
    </transition>
  </teleport>
</template>

<script>
import { prayerState, phase, nextPrayer, today, words, countdown, clock12, eatClock, adhan, playAdhan, stopAdhan, setAdhanVolume } from './prayerStore';

export default {
  computed: {
    open: { get() { return prayerState.drawer; }, set(v) { prayerState.drawer = v; } },
    d() { return prayerState.data || { city: '', adhan: {} }; },
    ph() { return phase.value; },
    sd() { return !(prayerState.data && prayerState.data.shutdown === false); },
    next() { return nextPrayer.value; },
    times() { return today.value; },
    player() { return adhan; },
    volume() { return adhan.volume ?? (this.d.adhan && this.d.adhan.volume) ?? 70; },
    rest() { return this.next ? words(this.next.t) : ''; },
    clockNow() { return eatClock(true); },
    dateLabel() { return new Date(prayerState.now).toLocaleDateString('en-GB', { timeZone: 'Africa/Dar_es_Salaam', weekday: 'long', day: 'numeric', month: 'long' }); },
    verse() { const e = this.ph.e || this.next; return e && e.message ? e.message : null; },
    canManage() { return !!(this.$can && this.$can('manage-prayer-times')); },
    progress() {
      if (!this.next) return 0;
      const past = this.times.filter(t => t.is_prayer && t.t <= prayerState.now);
      const from = past.length ? past[past.length - 1].t : this.next.t - 6 * 3600000;
      return Math.round(Math.min(1, Math.max(0, (prayerState.now - from) / Math.max(1, this.next.t - from))) * 100);
    },
  },
  watch: { open(v) { document.body.classList.toggle('pd-lock', v); if (v) this.$nextTick(() => this.$refs.panel && this.$refs.panel.focus()); } },
  mounted() { this.onKey = e => { if (e.key === 'Escape' && this.open) this.close(); }; window.addEventListener('keydown', this.onKey); },
  beforeUnmount() { window.removeEventListener('keydown', this.onKey); document.body.classList.remove('pd-lock'); },
  methods: {
    close() { this.open = false; },
    at12: clock12,
    at12iso(iso) { return clock12(new Date(iso).toLocaleTimeString('en-GB', { timeZone: 'Africa/Dar_es_Salaam', hour: '2-digit', minute: '2-digit' })); },
    cd(t) { return countdown(t); },
    play() { playAdhan(true); },
    stop() { stopAdhan(); },
    vol(v) { setAdhanVolume(Number(v)); },
  },
};
</script>

<style>
/* Light (soft green and orange); dark only in WIMS dark mode */
.pd-wrap { position: fixed; inset: 0; z-index: 10030; } .pd-scrim { position: absolute; inset: 0; background: rgba(20, 83, 45, 0.28); backdrop-filter: blur(3px); }
.pd { --pd-ink: #14532d; --pd-mute: #5b7a66; --pd-line: rgba(22, 163, 74, 0.22); --pd-card: rgba(255, 255, 255, 0.78); --pd-or: #c2410c;
  position: absolute; top: 0; right: 0; bottom: 0; width: min(420px, 100vw); display: flex; flex-direction: column; color: var(--pd-ink); background: linear-gradient(170deg, #f0fdf4 0%, #fffbeb 52%, #fff1e0 100%); box-shadow: -30px 0 60px -30px rgba(22, 101, 52, 0.5); outline: none; }
.pd-head { display: flex; align-items: center; gap: 0.7rem; padding: 1rem 1.1rem; border-bottom: 1px solid var(--pd-line); } .pd-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; } .pd-head p { margin: 0; font-size: 0.74rem; color: var(--pd-mute); } .pd-head .min-w-0 { flex: 1; }
.pd-moon { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #fb923c, #ea580c); box-shadow: 0 8px 16px -8px rgba(234, 88, 12, 0.8); } .pd-moon svg { width: 22px; height: 22px; animation: pd-float 3.4s ease-in-out infinite; }
.pd-clock { display: grid; text-align: right; line-height: 1.1; } .pd-clock b { font-size: 0.95rem; font-variant-numeric: tabular-nums; color: var(--pd-or); } .pd-clock small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.12em; color: var(--pd-mute); }
.pd-x { width: 34px; height: 34px; border: 0; border-radius: 50%; font-size: 1.4rem; line-height: 1; cursor: pointer; color: var(--pd-mute); background: rgba(22, 101, 52, 0.08); } .pd-x:hover { background: rgba(22, 101, 52, 0.16); }
.pd-body { flex: 1; overflow: auto; display: grid; align-content: start; gap: 0.8rem; padding: 1rem 1.1rem 1.4rem; }
.pd-status { padding: 1rem 1.1rem; border-radius: 18px; background: linear-gradient(135deg, #dcfce7, #fef3c7); box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.35); } .pd-status small { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.16em; text-transform: uppercase; color: var(--pd-mute); } .pd-status h3 { margin: 0.2rem 0 0.1rem; font-size: 1.4rem; font-weight: 800; } .pd-status h3 b { color: var(--pd-or); font-variant-numeric: tabular-nums; } .pd-status p { margin: 0; font-size: 0.82rem; color: var(--pd-mute); }
.pd-status.is-alert, .pd-status.is-prayer { background: linear-gradient(135deg, #ffedd5, #fef9c3); box-shadow: inset 0 0 0 1.5px rgba(249, 115, 22, 0.6); animation: pd-pulse 1.8s ease-in-out infinite; }
.pd-bar { height: 6px; margin-top: 0.7rem; border-radius: 6px; background: rgba(22, 101, 52, 0.14); overflow: hidden; } .pd-bar span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #22c55e, #f59e0b, #f97316); transition: width 1s linear; }
.pd-card { padding: 0.9rem 1rem; border-radius: 16px; background: var(--pd-card); box-shadow: inset 0 0 0 1px var(--pd-line); } .pd-card h4 { margin: 0 0 0.5rem; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; color: var(--pd-or); }
.pd-adhan.on { box-shadow: inset 0 0 0 2px #f97316, 0 10px 24px -14px rgba(234, 88, 12, 0.7); } .pd-adhan__top { display: flex; align-items: center; gap: 0.7rem; } .pd-adhan__top .min-w-0 { flex: 1; } .pd-adhan__top h4 { margin: 0; } .pd-adhan__top p { margin: 0.1rem 0 0; font-size: 0.8rem; font-weight: 700; }
.pd-eq { display: inline-flex; align-items: flex-end; gap: 3px; height: 26px; } .pd-eq i { width: 4px; height: 6px; border-radius: 3px; background: #22c55e; } .pd-eq.on i { background: #f97316; animation: pd-eq 0.8s ease-in-out infinite; } .pd-eq i:nth-child(2) { animation-delay: 0.12s; } .pd-eq i:nth-child(3) { animation-delay: 0.24s; } .pd-eq i:nth-child(4) { animation-delay: 0.36s; } .pd-eq i:nth-child(5) { animation-delay: 0.48s; }
.pd-btn { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.55rem 1rem; border: 0; border-radius: 12px; font-weight: 800; font-size: 0.82rem; cursor: pointer; transition: transform 0.15s; } .pd-btn svg { width: 16px; height: 16px; } .pd-btn:hover { transform: translateY(-1px); }
.pd-btn--play { color: #fff; background: linear-gradient(135deg, #22c55e, #16a34a); box-shadow: 0 8px 16px -8px rgba(22, 163, 74, 0.9); } .pd-btn--stop { color: #fff; background: linear-gradient(135deg, #fb923c, #dc2626); box-shadow: 0 8px 16px -8px rgba(220, 38, 38, 0.8); animation: pd-pulse 1.4s ease-in-out infinite; }
.pd-vol { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 0.6rem; margin-top: 0.8rem; font-size: 0.78rem; color: var(--pd-mute); font-weight: 700; } .pd-vol input { width: 100%; accent-color: #f97316; } .pd-vol b { color: var(--pd-ink); font-variant-numeric: tabular-nums; }
.pd-note { margin: 0.6rem 0 0; padding: 0.4rem 0.6rem; border-radius: 10px; font-size: 0.75rem; font-weight: 700; color: #9a3412; background: #ffedd5; }
.pd-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 3px; } .pd-list li { display: flex; align-items: center; gap: 0.6rem; padding: 0.42rem 0.55rem; border-radius: 10px; font-size: 0.86rem; } .pd-list b { flex: 1; font-weight: 700; } .pd-list time { font-variant-numeric: tabular-nums; font-weight: 700; } .pd-list i { font-style: normal; padding: 1px 8px; border-radius: 99px; font-size: 0.64rem; font-weight: 800; color: #fff; background: #f97316; }
.pd-dot { width: 9px; height: 9px; border-radius: 50%; background: rgba(22, 101, 52, 0.25); } .pd-list li.passed { opacity: 0.55; } .pd-list li.passed .pd-dot { background: #22c55e; } .pd-list li.sun { opacity: 0.6; font-size: 0.78rem; } .pd-list li.next { background: linear-gradient(90deg, rgba(251, 146, 60, 0.25), rgba(251, 146, 60, 0.06)); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.45); } .pd-list li.next .pd-dot { background: #f97316; box-shadow: 0 0 10px #f97316; }
.pd-verse__ar { margin: 0 0 0.4rem; font-size: 1.45rem; line-height: 2.1; text-align: center; font-family: 'Amiri', 'Scheherazade New', 'Noto Naskh Arabic', serif; } .pd-verse blockquote { margin: 0; font-size: 0.88rem; line-height: 1.55; font-style: italic; color: #334155; } .pd-verse small { display: block; margin-top: 0.4rem; font-size: 0.7rem; font-weight: 700; color: var(--pd-or); }
.pd-how ol { margin: 0; padding-left: 1.1rem; display: grid; gap: 0.35rem; font-size: 0.82rem; color: var(--pd-mute); } .pd-how b { color: var(--pd-ink); } .pd-who { margin: 0.7rem 0 0; padding: 0.45rem 0.7rem; border-radius: 10px; font-size: 0.78rem; font-weight: 700; } .pd-who.yes { color: #9a3412; background: #ffedd5; } .pd-who.no { color: #166534; background: #dcfce7; }
.pd-link { display: inline-block; margin-top: 0.6rem; font-size: 0.8rem; font-weight: 800; color: var(--pd-or); text-decoration: none; } .pd-link:hover { text-decoration: underline; }
.pd-enter-active, .pd-leave-active { transition: opacity 0.25s ease; } .pd-enter-active .pd, .pd-leave-active .pd { transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.3, 1); } .pd-enter-from, .pd-leave-to { opacity: 0; } .pd-enter-from .pd, .pd-leave-to .pd { transform: translateX(100%); }
body.pd-lock { overflow: hidden; }
@keyframes pd-eq { 0%, 100% { height: 6px; } 50% { height: 26px; } } @keyframes pd-float { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-2px) rotate(-8deg); } }
@keyframes pd-pulse { 0%, 100% { filter: brightness(1); } 50% { filter: brightness(1.08); } }
html.dark .pd { --pd-ink: #ecfdf5; --pd-mute: #94a3b8; --pd-line: rgba(255, 255, 255, 0.12); --pd-card: rgba(255, 255, 255, 0.06); --pd-or: #fcd34d; background: linear-gradient(170deg, #0b3b34 0%, #0a2a40 55%, #07142b 100%); box-shadow: -30px 0 60px -20px rgba(0, 0, 0, 0.7); }
html.dark .pd-scrim { background: rgba(2, 6, 23, 0.6); } html.dark .pd-x { background: rgba(255, 255, 255, 0.08); } html.dark .pd-x:hover { background: rgba(255, 255, 255, 0.18); }
html.dark .pd-status { background: linear-gradient(135deg, rgba(6, 78, 59, 0.7), rgba(120, 53, 15, 0.45)); box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.3); } html.dark .pd-status.is-alert, html.dark .pd-status.is-prayer { box-shadow: inset 0 0 0 1.5px rgba(251, 146, 60, 0.8); } html.dark .pd-bar { background: rgba(255, 255, 255, 0.12); }
html.dark .pd-verse blockquote { color: #e2e8f0; } html.dark .pd-note, html.dark .pd-who.yes { color: #fed7aa; background: rgba(154, 52, 18, 0.45); } html.dark .pd-who.no { color: #bbf7d0; background: rgba(22, 101, 52, 0.4); } html.dark .pd-dot { background: rgba(255, 255, 255, 0.25); }
@media (prefers-reduced-motion: reduce) { .pd-moon svg, .pd-eq i, .pd-btn--stop, .pd-status { animation: none; } }
</style>
