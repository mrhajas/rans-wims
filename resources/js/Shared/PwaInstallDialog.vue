<template>
  <teleport to="body">
    <transition name="pwd" appear>
      <div class="pwd-overlay" tabindex="-1" ref="root" @click.self="$emit('close')" @keydown.esc="$emit('close')">
        <div class="pwd-card" :class="'is-' + tone" role="dialog" aria-modal="true" :aria-label="title">
          <button type="button" class="pwd-x" :aria-label="$t('Close')" @click="$emit('close')"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg></button>

          <!-- hero: a soft moving glow, the WIMS app tile and what happened -->
          <div class="pwd-hero">
            <i class="pwd-blob pwd-blob--a"></i><i class="pwd-blob pwd-blob--b"></i><i class="pwd-blob pwd-blob--c"></i>
            <span v-if="celebrate" class="pwd-confetti" aria-hidden="true"><i v-for="n in 14" :key="n" :style="confetti(n)"></i></span>

            <!-- the RANS logo drops in, comes into focus, then a light sweeps across it -->
            <div class="pwd-logo" aria-hidden="true"><img src="/images/logo-white.png" alt="" /><i></i></div>

            <div class="pwd-stage">
              <span class="pwd-ring pwd-ring--1"></span><span class="pwd-ring pwd-ring--2"></span><span class="pwd-ring pwd-ring--3"></span>
              <span class="pwd-tile"><img src="/icons/icon-192x192.png" alt="" width="72" height="72" /></span>
              <!-- badge: a tick that draws itself when it is installed, a download arrow while it is not -->
              <span class="pwd-badge">
                <svg v-if="done" viewBox="0 0 24 24" class="pwd-tick" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                <svg v-else viewBox="0 0 24 24" class="pwd-down" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 11l5 5 5-5M5 20h14" /></svg>
              </span>
            </div>
            <span v-if="guide && guide.where" class="pwd-where">
              <svg v-if="guide.device === 'phone'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.4" /><path d="M11 18.5h2" /></svg>
              <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="12" rx="2" /><path d="M8 20h8M12 16.5V20" /></svg>
              {{ $t(guide.where) }}
            </span>
          </div>

          <div class="pwd-body">
            <h3 class="pwd-title">{{ title }}</h3>
            <p class="pwd-lead">{{ lead }}</p>

            <!-- where to find the app (it is already there) -->
            <ul v-if="kind === 'installed' || kind === 'accepted'" class="pwd-where-list">
              <li v-for="(w, i) in whereTo" :key="w.t" :style="{ animationDelay: 0.25 + i * 0.09 + 's' }"><span class="pwd-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="icon(w.i)"></svg></span>{{ w.t }}</li>
            </ul>

            <!-- how to install it (this browser has no install button of its own) -->
            <ol v-if="guide" class="pwd-steps">
              <li v-for="(s, i) in guide.steps" :key="i" :style="{ animationDelay: 0.3 + i * 0.13 + 's' }">
                <span class="pwd-num">{{ i + 1 }}</span>
                <span class="pwd-stepico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="icon(s.icon)"></svg></span>
                <span class="pwd-steptxt">{{ $t(s.text) }}</span>
              </li>
            </ol>

            <p v-if="guide" class="pwd-tip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 8v.01M11 12h1v4h1" /></svg><span>{{ $t('If WIMS is already installed here, open it from your apps, your home screen or the Start menu instead.') }}</span></p>
          </div>

          <div class="pwd-foot">
            <button ref="ok" type="button" class="pwd-btn" @click="$emit('close')"><span>{{ done ? $t('Great') : $t('Got it') }}</span><i class="pwd-shine"></i></button>
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
import { environment } from '@/Core/pwaInstall';

// The pictures for the steps (24x24, drawn with a stroke).
const ICONS = {
  share: '<path d="M12 15V3M8 7l4-4 4 4" /><path d="M6 11H5a2 2 0 00-2 2v6a2 2 0 002 2h14a2 2 0 002-2v-6a2 2 0 00-2-2h-1" />',
  plus: '<rect x="3.5" y="3.5" width="17" height="17" rx="4" /><path d="M12 8v8M8 12h8" />',
  tick: '<circle cx="12" cy="12" r="9" /><path d="M8 12.5l2.8 2.8L16 9.5" />',
  dots: '<circle cx="12" cy="5.5" r="1.4" /><circle cx="12" cy="12" r="1.4" /><circle cx="12" cy="18.5" r="1.4" />',
  menu: '<path d="M4 7h16M4 12h16M4 17h16" />',
  download: '<path d="M12 4v11M7 11l5 5 5-5M5 20h14" />',
  install: '<rect x="3" y="4" width="18" height="13" rx="2" /><path d="M12 7.5v6M9.5 11.5l2.5 2.5 2.5-2.5M8 21h8" />',
  compass: '<circle cx="12" cy="12" r="9" /><path d="M15.5 8.5l-2 5-5 2 2-5z" />',
  lock: '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 018 0v3" />',
  apps: '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5" /><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5" /><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5" /><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5" />',
  home: '<path d="M3.5 11L12 4l8.5 7" /><path d="M6 10v9.5h12V10" />',
  win: '<path d="M3 5.5l7.5-1v7H3zM12 4.3l9-1.3v8.5h-9zM3 12.5h7.5v7L3 18.5zM12 12.5h9V21l-9-1.3z" />',
  open: '<path d="M14 4h6v6M20 4l-9 9" /><path d="M18 14v4a2 2 0 01-2 2H6a2 2 0 01-2-2V8a2 2 0 012-2h4" />',
};

export default {
  props: {
    kind: { type: String, required: true }, // standalone | installed | accepted | manual
    guide: { type: Object, default: null },
  },
  emits: ['close'],
  computed: {
    done() { return this.kind !== 'manual'; },
    celebrate() { return this.kind === 'accepted'; },
    tone() { return this.kind === 'manual' ? 'blue' : this.kind === 'accepted' ? 'gold' : 'green'; },
    title() {
      return {
        standalone: this.$t('You are using the app'),
        installed: this.$t('WIMS is already installed'),
        accepted: this.$t('WIMS is installed!'),
      }[this.kind] || this.$t(this.guide ? this.guide.title : 'Install WIMS');
    },
    lead() {
      return {
        standalone: this.$t('WIMS is installed on this device and you are using it right now.'),
        installed: this.$t('You do not need to install it again. Open it from one of these:'),
        accepted: this.$t('Look for WIMS in one of these places:'),
      }[this.kind] || this.$t('To install WIMS on this device:');
    },
    // The Start menu and the address bar's "Open in app" only exist on a computer, so a phone is not shown them
    whereTo() {
      const all = [
        { i: 'apps', t: this.$t('Your apps') },
        { i: 'home', t: this.$t('Your home screen') },
        { i: 'win', t: this.$t('The Start menu'), desktop: true },
        { i: 'open', t: this.$t('"Open in app" in the address bar'), desktop: true },
      ];
      return environment().mobile ? all.filter(w => !w.desktop) : all;
    },
  },
  mounted() { this.$nextTick(() => { try { this.$refs.ok && this.$refs.ok.focus(); } catch (e) { /* closed already */ } }); },
  methods: {
    icon(name) { return ICONS[name] || ICONS.download; },
    // a few pieces of confetti, spread by index (no randomness needed)
    confetti(n) {
      const colors = ['#facc15', '#34d399', '#60a5fa', '#f472b6', '#fb923c'];
      return { left: (n * 7.3) % 100 + '%', background: colors[n % colors.length], animationDelay: (n % 5) * 0.08 + 's', animationDuration: 1.6 + (n % 4) * 0.25 + 's', transform: 'rotate(' + n * 31 + 'deg)' };
    },
  },
};
</script>

<style>
.pwd-overlay { position: fixed; inset: 0; z-index: 999999; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(8, 15, 32, 0.62); backdrop-filter: blur(7px); -webkit-backdrop-filter: blur(7px); outline: none; }
.pwd-card { --a: #2563eb; --b: #7c3aed; --soft: #eef4ff; --ink: #0f172a; --mute: #64748b; position: relative; width: 100%; max-width: 25rem; max-height: calc(100vh - 32px); overflow: auto; border-radius: 26px; background: #fff; color: var(--ink); box-shadow: 0 40px 80px -30px rgba(2, 8, 23, 0.75), 0 0 0 1px rgba(255, 255, 255, 0.5) inset; }
.pwd-card.is-green { --a: #059669; --b: #0d9488; --soft: #ecfdf5; }
.pwd-card.is-gold { --a: #f59e0b; --b: #ea580c; --soft: #fffbeb; }
.pwd-x { position: absolute; top: 12px; right: 12px; z-index: 5; display: grid; place-items: center; width: 32px; height: 32px; border: 0; border-radius: 99px; color: #fff; background: rgba(255, 255, 255, 0.18); cursor: pointer; transition: background 0.15s, transform 0.15s; }
.pwd-x:hover { background: rgba(255, 255, 255, 0.34); transform: rotate(90deg); } .pwd-x svg { width: 18px; height: 18px; }

/* the RANS logo */
.pwd-logo { position: relative; z-index: 2; width: 132px; height: 54px; animation: pwd-logo-in 0.9s 0.1s cubic-bezier(0.2, 1, 0.3, 1) both; }
.pwd-logo img { display: block; width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 6px 10px rgba(2, 8, 23, 0.35)); }
.pwd-logo i { position: absolute; inset: 0; -webkit-mask: url(/images/logo-white.png) center / contain no-repeat; mask: url(/images/logo-white.png) center / contain no-repeat; background: linear-gradient(105deg, transparent 38%, rgba(250, 204, 21, 0.95) 50%, transparent 62%) -160% 0 / 250% 100% no-repeat; animation: pwd-logo-shine 3.4s 1.1s ease-in-out infinite; }
@keyframes pwd-logo-in { 0% { opacity: 0; transform: translateY(-22px) scale(0.82); filter: blur(8px); letter-spacing: 0.3em; } 60% { opacity: 1; filter: blur(0); } 100% { opacity: 1; transform: none; filter: none; } }
@keyframes pwd-logo-shine { 0% { background-position: -160% 0; } 55%, 100% { background-position: 160% 0; } }

/* hero */
.pwd-hero { position: relative; display: grid; justify-items: center; gap: 8px; padding: 26px 20px 24px; overflow: hidden; background: linear-gradient(140deg, var(--a), var(--b)); }
.pwd-hero::after { content: ''; position: absolute; inset: 0; background-image: linear-gradient(rgba(255, 255, 255, 0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.07) 1px, transparent 1px); background-size: 22px 22px; mask-image: radial-gradient(60% 80% at 50% 45%, #000, transparent); pointer-events: none; }
.pwd-blob { position: absolute; border-radius: 50%; filter: blur(22px); opacity: 0.55; animation: pwd-float 7s ease-in-out infinite; }
.pwd-blob--a { width: 130px; height: 130px; left: -30px; top: -40px; background: rgba(255, 255, 255, 0.45); }
.pwd-blob--b { width: 110px; height: 110px; right: -24px; bottom: -34px; background: rgba(250, 204, 21, 0.55); animation-delay: -2.4s; }
.pwd-blob--c { width: 80px; height: 80px; right: 24%; top: -30px; background: rgba(34, 211, 238, 0.45); animation-delay: -4.6s; }
.pwd-stage { position: relative; z-index: 2; display: grid; place-items: center; width: 112px; height: 112px; }
.pwd-ring { position: absolute; inset: 14px; border-radius: 26px; border: 2px solid rgba(255, 255, 255, 0.7); animation: pwd-ring 2.6s ease-out infinite; }
.pwd-ring--2 { animation-delay: 0.85s; } .pwd-ring--3 { animation-delay: 1.7s; }
.pwd-tile { position: relative; display: grid; place-items: center; width: 84px; height: 84px; border-radius: 22px; background: #fff; box-shadow: 0 18px 34px -12px rgba(2, 8, 23, 0.6); animation: pwd-pop 0.7s cubic-bezier(0.2, 1.4, 0.4, 1) both, pwd-bob 3.6s ease-in-out 0.8s infinite; }
.pwd-tile img { width: 66px; height: 66px; border-radius: 16px; object-fit: cover; }
.pwd-badge { position: absolute; right: 6px; bottom: 6px; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 99px; color: #fff; background: linear-gradient(145deg, var(--b), var(--a)); box-shadow: 0 0 0 4px #fff, 0 8px 16px -6px rgba(2, 8, 23, 0.6); animation: pwd-pop 0.6s 0.35s cubic-bezier(0.2, 1.6, 0.4, 1) both; }
.pwd-badge svg { width: 18px; height: 18px; }
.pwd-tick path { stroke-dasharray: 26; stroke-dashoffset: 26; animation: pwd-draw 0.5s 0.8s ease forwards; }
.pwd-down { animation: pwd-drop 1.5s ease-in-out infinite; }
.pwd-where { position: relative; z-index: 2; display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 99px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.02em; color: #fff; background: rgba(255, 255, 255, 0.2); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35); }
.pwd-where svg { width: 14px; height: 14px; }
.pwd-confetti { position: absolute; inset: 0; z-index: 1; pointer-events: none; overflow: hidden; }
.pwd-confetti i { position: absolute; top: -10px; width: 7px; height: 12px; border-radius: 2px; animation: pwd-fall linear infinite; }

/* body */
.pwd-body { padding: 20px 22px 6px; text-align: center; }
.pwd-title { margin: 0; font-size: 1.28rem; font-weight: 800; letter-spacing: -0.01em; animation: pwd-up 0.5s 0.15s ease both; }
.pwd-lead { margin: 6px 0 14px; font-size: 0.88rem; line-height: 1.5; color: var(--mute); animation: pwd-up 0.5s 0.22s ease both; }
.pwd-where-list { display: grid; gap: 8px; margin: 0 0 8px; padding: 0; list-style: none; text-align: left; }
.pwd-where-list li { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 14px; font-size: 0.86rem; font-weight: 700; background: var(--soft); box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.05); animation: pwd-slide 0.45s ease both; }
.pwd-chip { display: grid; place-items: center; flex: none; width: 32px; height: 32px; border-radius: 10px; color: #fff; background: linear-gradient(145deg, var(--a), var(--b)); box-shadow: 0 8px 14px -8px var(--a); }
.pwd-chip svg { width: 17px; height: 17px; }
.pwd-steps { display: grid; gap: 9px; margin: 0 0 12px; padding: 0; list-style: none; text-align: left; counter-reset: s; }
.pwd-steps li { position: relative; display: flex; align-items: center; gap: 10px; padding: 10px 12px 10px 10px; border-radius: 16px; background: var(--soft); box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.1); animation: pwd-slide 0.5s ease both; }
.pwd-num { position: absolute; top: -7px; left: -5px; display: grid; place-items: center; width: 20px; height: 20px; border-radius: 99px; font-size: 0.68rem; font-weight: 800; color: #fff; background: #0f172a; box-shadow: 0 0 0 2px #fff; }
.pwd-stepico { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, var(--a), var(--b)); box-shadow: 0 10px 16px -10px var(--a); }
.pwd-stepico svg { width: 20px; height: 20px; }
.pwd-steptxt { font-size: 0.84rem; font-weight: 600; line-height: 1.4; }
.pwd-tip { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 6px; padding: 9px 11px; border-radius: 12px; text-align: left; font-size: 0.74rem; line-height: 1.45; color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a; animation: pwd-up 0.5s 0.7s ease both; }
.pwd-tip svg { flex: none; width: 16px; height: 16px; margin-top: 1px; }

/* button */
.pwd-foot { padding: 12px 22px 22px; }
.pwd-btn { position: relative; display: block; width: 100%; padding: 13px 16px; overflow: hidden; border: 0; border-radius: 15px; font-size: 0.9rem; font-weight: 800; color: #fff; cursor: pointer; background: linear-gradient(135deg, var(--a), var(--b)); box-shadow: 0 16px 26px -14px var(--a); transition: transform 0.15s, box-shadow 0.15s; }
.pwd-btn:hover { transform: translateY(-2px); box-shadow: 0 20px 30px -14px var(--a); } .pwd-btn:active { transform: translateY(0) scale(0.99); }
.pwd-btn:focus-visible { outline: 3px solid rgba(250, 204, 21, 0.9); outline-offset: 2px; }
.pwd-btn span { position: relative; z-index: 1; }
.pwd-shine { position: absolute; top: 0; bottom: 0; left: -40%; width: 30%; background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.45), transparent); transform: skewX(-18deg); animation: pwd-shine 3.2s ease-in-out 1s infinite; }

/* enter / leave */
.pwd-enter-active, .pwd-leave-active { transition: opacity 0.22s ease; }
.pwd-enter-active .pwd-card, .pwd-leave-active .pwd-card { transition: transform 0.38s cubic-bezier(0.2, 1.2, 0.3, 1), opacity 0.22s ease; }
.pwd-enter-from, .pwd-leave-to { opacity: 0; }
.pwd-enter-from .pwd-card { transform: translateY(26px) scale(0.92); opacity: 0; } .pwd-leave-to .pwd-card { transform: translateY(12px) scale(0.96); opacity: 0; }

@keyframes pwd-float { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(14px, 10px); } }
@keyframes pwd-ring { 0% { transform: scale(0.78); opacity: 0.85; } 100% { transform: scale(1.5); opacity: 0; } }
@keyframes pwd-pop { 0% { transform: scale(0.3); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
@keyframes pwd-bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
@keyframes pwd-draw { to { stroke-dashoffset: 0; } }
@keyframes pwd-drop { 0%, 100% { transform: translateY(-1.5px); } 50% { transform: translateY(2px); } }
@keyframes pwd-up { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes pwd-slide { from { opacity: 0; transform: translateX(-16px); } to { opacity: 1; transform: none; } }
@keyframes pwd-shine { 0%, 55% { left: -40%; } 100% { left: 130%; } }
@keyframes pwd-fall { 0% { transform: translateY(-14px) rotate(0); opacity: 1; } 100% { transform: translateY(260px) rotate(540deg); opacity: 0.9; } }

html.dark .pwd-card { background: #151c33; color: #eef0fa; --ink: #eef0fa; --mute: #9aa6c4; --soft: rgba(255, 255, 255, 0.06); box-shadow: 0 40px 80px -30px rgba(0, 0, 0, 0.9), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .pwd-tip { color: #fde68a; background: rgba(245, 158, 11, 0.12); box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.3); }
html.dark .pwd-badge { box-shadow: 0 0 0 4px #151c33, 0 8px 16px -6px rgba(0, 0, 0, 0.7); } html.dark .pwd-num { box-shadow: 0 0 0 2px #151c33; background: #38456b; }
@media (prefers-reduced-motion: reduce) { .pwd-logo, .pwd-logo i { animation: none !important; } .pwd-blob, .pwd-ring, .pwd-tile, .pwd-down, .pwd-shine, .pwd-confetti i { animation: none !important; } .pwd-title, .pwd-lead, .pwd-steps li, .pwd-where-list li, .pwd-tip { animation: none !important; opacity: 1; } .pwd-tick path { animation: none; stroke-dashoffset: 0; } }
@media (max-width: 420px) { .pwd-hero { padding: 24px 16px 20px; } .pwd-body { padding: 16px 16px 4px; } .pwd-foot { padding: 10px 16px 18px; } }
</style>
