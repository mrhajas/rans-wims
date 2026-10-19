<template>
  <teleport to="body">
    <transition name="pln-fade">
      <div v-if="open" class="pln-backdrop" role="dialog" aria-modal="true" :aria-label="$t('Prayer break')" @click.self="close">
        <div class="pln-card">
          <span class="pln-stars" aria-hidden="true"><i v-for="n in 26" :key="n" :style="{ '--x': (n * 41) % 100 + '%', '--y': (n * 59) % 100 + '%', '--d': (n % 7) * 0.45 + 's', '--s': 1 + (n % 3) }"></i></span>
          <button type="button" class="pln-x" :aria-label="$t('Close')" @click="close">×</button>

          <prayer-sky :prayer="notice.prayer" />

          <p class="pln-eyebrow">{{ $t('Prayer break') }}</p>
          <h2 class="pln-title">{{ done ? $t('You can sign in now') : $t('WIMS is resting for') + ' ' + notice.prayer + ' ' + $t('prayer') }}</h2>

          <div v-if="!done" class="pln-ring">
            <svg viewBox="0 0 120 120"><circle class="pln-ring__bg" cx="60" cy="60" r="52" /><circle class="pln-ring__fg" cx="60" cy="60" r="52" :style="{ strokeDashoffset: offset }" /></svg>
            <div class="pln-ring__t"><b>{{ left }}</b><small>{{ $t('until sign-in opens') }}</small></div>
          </div>
          <p class="pln-text">{{ done ? $t('The prayer break is over. Welcome back.') : $t('You can sign in again at') + ' ' + notice.unlock_label + '.' }}</p>

          <figure v-if="notice.message && !done" class="pln-verse">
            <p v-if="notice.message.arabic" class="pln-verse__ar" lang="ar" dir="rtl">{{ notice.message.arabic }}</p>
            <blockquote>{{ notice.message.text }}</blockquote>
            <figcaption>{{ notice.message.kind === 'ayah' ? $t('Qur\'an') : $t('Hadith') }} · {{ notice.message.reference }}</figcaption>
          </figure>

          <div class="pln-actions">
            <button v-if="notice.adhan && notice.adhan.enabled && !done" type="button" class="pln-ghost" @click="toggle"><span class="pln-eq" :class="{ on: playing }"><i></i><i></i><i></i><i></i></span>{{ playing ? $t('Pause adhan') : $t('Play adhan') }}</button>
            <button type="button" class="pln-btn" @click="done ? reload() : close()"><span>{{ done ? $t('Sign in') : $t('OK') }}</span></button>
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
import PrayerSky from './PrayerSky.vue';
import { markRaw } from 'vue';

export default {
  components: { PrayerSky },
  props: { notice: { type: Object, required: true }, show: { type: Boolean, default: true } },
  data() { return { open: this.show, now: Date.now(), offset: 0, timer: null, audio: null, playing: false, startedAt: 0 }; },
  computed: {
    end() { return new Date(this.notice.unlock_at).getTime(); },
    done() { return this.now >= this.end; },
    left() {
      let s = Math.max(0, Math.floor((this.end - this.now) / 1000));
      const h = Math.floor(s / 3600); s -= h * 3600; const m = Math.floor(s / 60); s -= m * 60;
      const p = n => String(n).padStart(2, '0');
      return h > 0 ? `${h}:${p(m)}:${p(s)}` : `${p(m)}:${p(s)}`;
    },
    offsetRing() { return 0; },
  },
  watch: {
    show(v) { this.open = v; if (v) this.begin(); },
    done(v) { if (v) this.stop(); },
  },
  mounted() {
    this.offset = new Date(this.notice.server_now).getTime() - Date.now();
    this.now = Date.now() + this.offset;
    this.startedAt = this.now;
    this.timer = setInterval(() => (this.now = Date.now() + this.offset), 1000);
    if (this.open) this.begin();
  },
  beforeUnmount() { clearInterval(this.timer); this.stop(); },
  methods: {
    begin() {
      if (!(this.notice.adhan && this.notice.adhan.enabled) || this.audio) return;
      try {
        this.audio = markRaw(new Audio(this.notice.adhan.url));
        this.audio.volume = Math.max(0, Math.min(1, (this.notice.adhan.volume || 70) / 100));
        this.audio.addEventListener('ended', () => (this.playing = false));
        this.audio.play().then(() => (this.playing = true)).catch(() => (this.playing = false)); // may need one click first
      } catch (e) { this.playing = false; }
    },
    toggle() { if (!this.audio) return this.begin(); if (this.playing) { this.audio.pause(); this.playing = false; } else this.audio.play().then(() => (this.playing = true)).catch(() => {}); },
    stop() { if (this.audio) this.audio.pause(); this.audio = null; this.playing = false; },
    close() { this.open = false; this.stop(); this.$emit('close'); },
    reload() { window.location.href = '/login'; },
  },
  emits: ['close'],
};
</script>

<style>
/* Light by default (soft greens and oranges); dark only when WIMS dark mode is chosen. */
.pln-backdrop { --pln-ink: #14532d; --pln-text: #166534; --pln-gold: #c2410c; --pln-verse-bg: rgba(255, 255, 255, 0.72); --pln-verse-line: rgba(234, 88, 12, 0.3); --pln-verse-text: #334155; --pln-ring-bg: rgba(22, 101, 52, 0.12); --pln-ring: #22c55e; --pln-star: #f59e0b; --pln-btn-text: #fff; --pln-ghost: rgba(22, 163, 74, 0.1); --pln-ghost-line: rgba(22, 163, 74, 0.35); --pln-ghost-text: #166534; --pln-x: #5b7a66;
  position: fixed; inset: 0; z-index: 10060; display: grid; place-items: center; padding: 1rem; background: radial-gradient(120% 90% at 50% 0%, rgba(220, 252, 231, 0.86), rgba(255, 237, 213, 0.9)); backdrop-filter: blur(8px); }
.pln-card { position: relative; width: min(480px, 100%); max-height: calc(100vh - 2rem); overflow: auto; padding: 2rem 1.5rem 1.4rem; border-radius: 28px; text-align: center; color: var(--pln-ink); background: linear-gradient(165deg, #f0fdf4 0%, #fffbeb 55%, #fff1e0 100%); box-shadow: 0 40px 80px -34px rgba(22, 101, 52, 0.55), 0 0 0 1px rgba(34, 197, 94, 0.35), inset 0 0 60px rgba(251, 191, 36, 0.1); animation: pln-pop 0.6s cubic-bezier(0.2, 1.2, 0.3, 1) both; }
.pln-stars { position: absolute; inset: 0; overflow: hidden; border-radius: inherit; pointer-events: none; } .pln-stars i { position: absolute; left: var(--x); top: var(--y); width: calc(var(--s) * 2px); height: calc(var(--s) * 2px); border-radius: 50%; background: var(--pln-star); opacity: 0; animation: pln-twinkle 3s ease-in-out infinite; animation-delay: var(--d); }
.pln-x { position: absolute; top: 12px; right: 14px; width: 32px; height: 32px; border: 0; border-radius: 50%; font-size: 1.4rem; line-height: 1; color: var(--pln-x); background: rgba(22, 101, 52, 0.08); cursor: pointer; } .pln-x:hover { background: rgba(22, 101, 52, 0.16); }
.pln-moon { width: 80px; height: 80px; margin: 0 auto 0.5rem; filter: drop-shadow(0 6px 14px rgba(245, 158, 11, 0.55)); animation: pln-float 4s ease-in-out infinite; } .pln-star5 { transform-origin: 92px 48px; animation: pln-spin 9s linear infinite; }
.pln-eyebrow { margin: 0; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.22em; text-transform: uppercase; color: var(--pln-gold); } .pln-title { margin: 0.35rem 0 0.4rem; font-size: 1.3rem; font-weight: 800; line-height: 1.25; color: var(--pln-ink); }
.pln-ring { position: relative; width: 156px; height: 156px; margin: 0.5rem auto; } .pln-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); } .pln-ring circle { fill: none; stroke-width: 7; } .pln-ring__bg { stroke: var(--pln-ring-bg); } .pln-ring__fg { stroke: var(--pln-ring); stroke-linecap: round; stroke-dasharray: 327; stroke-dashoffset: 0; filter: drop-shadow(0 0 5px rgba(34, 197, 94, 0.5)); animation: pln-breathe 3s ease-in-out infinite; }
.pln-ring__t { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 0.15rem; } .pln-ring__t b { font-size: 1.7rem; line-height: 1; font-variant-numeric: tabular-nums; color: var(--pln-ink); } .pln-ring__t small { max-width: 92px; font-size: 0.58rem; line-height: 1.3; color: var(--pln-gold); letter-spacing: 0.06em; text-transform: uppercase; }
.pln-text { margin: 0.3rem 0; font-size: 0.92rem; color: var(--pln-text); }
.pln-verse { margin: 0.8rem 0 0.3rem; padding: 0.9rem 1rem 0.7rem; border-radius: 18px; background: var(--pln-verse-bg); border: 1px solid var(--pln-verse-line); animation: pln-rise 0.7s 0.3s ease both; } .pln-verse__ar { margin: 0 0 0.4rem; font-size: 1.5rem; line-height: 2.1; color: #14532d; font-family: 'Amiri', 'Scheherazade New', 'Noto Naskh Arabic', serif; } .pln-verse blockquote { margin: 0; font-size: 0.9rem; line-height: 1.55; font-style: italic; color: var(--pln-verse-text); } .pln-verse figcaption { margin-top: 0.45rem; font-size: 0.72rem; font-weight: 700; color: var(--pln-gold); }
.pln-actions { display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: center; margin-top: 0.9rem; }
.pln-btn, .pln-ghost { position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.7rem 1.5rem; border-radius: 14px; font-weight: 800; font-size: 0.88rem; cursor: pointer; border: 0; transition: transform 0.15s ease; } .pln-btn:hover, .pln-ghost:hover { transform: translateY(-1px); }
.pln-btn { color: var(--pln-btn-text); background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 10px 22px -10px rgba(234, 88, 12, 0.85); } .pln-ghost { color: var(--pln-ghost-text); background: var(--pln-ghost); box-shadow: inset 0 0 0 1px var(--pln-ghost-line); }
.pln-eq { display: inline-flex; align-items: flex-end; gap: 2px; height: 14px; } .pln-eq i { width: 3px; height: 4px; border-radius: 2px; background: #16a34a; } .pln-eq.on i { animation: pln-eq 0.8s ease-in-out infinite; } .pln-eq i:nth-child(2) { animation-delay: 0.15s; } .pln-eq i:nth-child(3) { animation-delay: 0.3s; } .pln-eq i:nth-child(4) { animation-delay: 0.45s; }
.pln-fade-enter-active, .pln-fade-leave-active { transition: opacity 0.35s ease; } .pln-fade-enter-from, .pln-fade-leave-to { opacity: 0; }
@keyframes pln-pop { from { opacity: 0; transform: translateY(24px) scale(0.92); } to { opacity: 1; transform: none; } } @keyframes pln-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes pln-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } } @keyframes pln-spin { to { transform: rotate(360deg); } }
@keyframes pln-twinkle { 0%, 100% { opacity: 0; transform: scale(0.6); } 50% { opacity: 0.9; transform: scale(1.2); } } @keyframes pln-eq { 0%, 100% { height: 4px; } 50% { height: 14px; } }
@keyframes pln-breathe { 0%, 100% { stroke-dashoffset: 0; opacity: 0.9; } 50% { stroke-dashoffset: 18; opacity: 1; } }
html.dark .pln-backdrop { --pln-ink: #ecfdf5; --pln-text: #d1fae5; --pln-gold: #fcd34d; --pln-verse-bg: rgba(255, 255, 255, 0.06); --pln-verse-line: rgba(250, 204, 21, 0.25); --pln-verse-text: #e2e8f0; --pln-ring-bg: rgba(255, 255, 255, 0.12); --pln-ring: #34d399; --pln-star: #fde68a; --pln-btn-text: #422006; --pln-ghost: rgba(255, 255, 255, 0.08); --pln-ghost-line: rgba(255, 255, 255, 0.25); --pln-ghost-text: #ecfdf5; --pln-x: #cbd5e1; background: radial-gradient(120% 90% at 50% 0%, rgba(6, 78, 59, 0.82), rgba(2, 6, 23, 0.92)); }
html.dark .pln-card { background: linear-gradient(165deg, #0b3b34 0%, #0a2a40 55%, #07142b 100%); box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(250, 204, 21, 0.35), inset 0 0 60px rgba(16, 185, 129, 0.12); }
html.dark .pln-verse__ar { color: #fff; } html.dark .pln-btn { background: linear-gradient(135deg, #fde68a, #f59e0b); } html.dark .pln-eq i { background: #34d399; } html.dark .pln-x { background: rgba(255, 255, 255, 0.08); } html.dark .pln-x:hover { background: rgba(255, 255, 255, 0.18); }
@media (prefers-reduced-motion: reduce) { .pln-card, .pln-moon, .pln-star5, .pln-stars i, .pln-ring__fg { animation: none; } .pln-stars i { opacity: 0.6; } }
</style>
