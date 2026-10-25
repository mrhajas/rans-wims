<template>
  <transition name="sp-pop">
    <div v-if="p.state !== 'idle'" class="sp-root" :class="'is-' + p.state" role="status" aria-live="polite">
      <div v-if="p.state === 'working'" class="sp-shield" aria-hidden="true"></div>
      <div class="sp-card" :class="{ 'has-stage': p.state === 'working' && p.anim, 'has-note': p.state === 'working' && p.long && !p.anim }">
        <div v-if="p.state === 'working' && p.anim" class="sp-stage" :class="'is-' + p.anim" aria-hidden="true">
          <span class="sp-trail"></span>
          <i v-for="n in 6" :key="n" class="sp-file" :class="'f' + n"></i>
          <span class="sp-db">
            <span class="sp-db-ring"></span><span class="sp-db-ring r2"></span>
            <svg v-if="p.anim === 'upload'" viewBox="0 0 64 72" class="sp-db-svg" aria-hidden="true">
              <defs><linearGradient id="sp-cg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7dd3fc" /><stop offset="0.6" stop-color="#6366f1" /><stop offset="1" stop-color="#a855f7" /></linearGradient></defs>
              <path d="M17 52c-8 0-14-5.5-14-12.5 0-6 4.4-11 10.4-12.3C15 18.7 22.4 13 31 13c8.5 0 15.6 5.4 17.8 12.9C56 26.4 61 32 61 39c0 7.2-6 13-13.5 13H17z" fill="url(#sp-cg)" />
              <path d="M32 44V28m0 0l-6 6m6-6l6 6" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else viewBox="0 0 64 72" class="sp-db-svg">
              <defs>
                <linearGradient id="sp-dbg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#818cf8" /><stop offset="0.55" stop-color="#6366f1" /><stop offset="1" stop-color="#0ea5e9" /></linearGradient>
              </defs>
              <path d="M6 14v44c0 6 11.2 11 26 11s26-5 26-11V14" fill="url(#sp-dbg)" />
              <ellipse cx="32" cy="14" rx="26" ry="10" fill="#c7d2fe" />
              <path d="M6 28c0 6 11.2 11 26 11s26-5 26-11M6 43c0 6 11.2 11 26 11s26-5 26-11" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="2" />
              <ellipse cx="32" cy="14" rx="26" ry="10" fill="none" stroke="rgba(255,255,255,.8)" stroke-width="1.5" />
            </svg>
          </span>
        </div>
        <span v-if="!(p.state === 'working' && p.anim)" class="sp-ico" aria-hidden="true">
          <span v-if="p.state === 'working'" class="sp-spin"><i></i><i></i><i></i></span>
          <svg v-else-if="p.state === 'done'" viewBox="0 0 24 24" class="sp-mark"><circle cx="12" cy="12" r="10" class="sp-mark-bg" /><path d="M7.5 12.5l3 3 6-7" class="sp-mark-line" /></svg>
          <svg v-else viewBox="0 0 24 24" class="sp-mark"><circle cx="12" cy="12" r="10" class="sp-mark-bg" /><path d="M8.5 8.5l7 7M15.5 8.5l-7 7" class="sp-mark-line" /></svg>
        </span>
        <span class="sp-txt">
          <b>{{ p.state === 'working' ? p.label.replace(/\.+$/, '') : p.label }}<span v-if="p.state === 'working'" class="sp-dots" aria-hidden="true"><i></i><i></i><i></i></span></b>
          <small v-if="p.text && p.state !== 'working'">{{ p.text }}</small>
        </span>
        <div v-if="p.state === 'working' && p.percent !== null" class="sp-up" aria-hidden="true">
          <span class="sp-up-bar"><i :style="{ width: Math.max(4, p.percent) + '%' }"></i></span>
          <small>{{ p.percent < 100 ? $t('Sending') + ' ' + p.percent + '%' : $t('Almost done, saving...') }}</small>
        </div>
        <p v-if="p.state === 'working' && p.long" class="sp-keep">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2 2.6 19.5h18.8L12 3.2z" fill="currentColor" opacity=".2" /><path d="M12 9v5m0 3.2h.01M12 3.2 2.6 19.5h18.8L12 3.2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          <span>{{ $t('Please keep this window open. Do not refresh or close the page until the process is complete.') }}</span>
        </p>
      </div>
    </div>
  </transition>
</template>

<script>
import { progress } from '@/Core/saveProgress';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { result, isBusy } from '@/Core/saveProgress';

export default {
  setup() {
    const page = usePage();
    // a message set by the page itself (a quick action that does not leave the page) or sent with the page props is shown the same way
    const strip = s => String(s || '').replace(/<[^>]*>/g, '');
    const quiet = () => String((page.component || '')).startsWith('Auth/');
    watch(
      () => page.props && page.props.flash,
      flash => {
        if (!flash || quiet() || isBusy()) return;
        if (flash.error) result('error', strip(flash.error));
        else if (flash.message) result('success', flash.message);
      },
      { deep: true },
    );
    watch(
      () => page.props && page.props.errors,
      errors => {
        const n = errors ? Object.keys(errors).length : 0;
        if (n && !quiet() && !isBusy()) result('error', n === 1 ? 'There is one form error.' : 'There are ' + n + ' form errors.');
      },
      { deep: true },
    );
    return { p: progress };
  },
};
</script>

<style>
.sp-root { position: fixed; inset: 0; z-index: 400; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.sp-shield { position: absolute; inset: 0; pointer-events: auto; cursor: progress; background: rgba(15, 23, 42, 0.16); backdrop-filter: blur(1.5px); }
.sp-card { position: relative; display: inline-flex; align-items: center; gap: 12px; max-width: min(88vw, 360px); min-width: 150px; padding: 12px 20px 12px 14px; border-radius: 9999px; background: #ffffff; color: #0f172a; box-shadow: 0 24px 48px -18px rgba(15, 23, 42, 0.45), 0 4px 12px rgba(15, 23, 42, 0.12), inset 0 0 0 1px rgba(148, 163, 184, 0.25); }
.sp-root.is-done .sp-card { box-shadow: 0 24px 48px -18px rgba(22, 163, 74, 0.5), 0 4px 12px rgba(15, 23, 42, 0.12), inset 0 0 0 1px rgba(34, 197, 94, 0.4); }
.sp-root.is-error .sp-card { border-radius: 1.1rem; box-shadow: 0 24px 48px -18px rgba(220, 38, 38, 0.5), 0 4px 12px rgba(15, 23, 42, 0.12), inset 0 0 0 1px rgba(248, 113, 113, 0.5); }
.sp-root.is-done .sp-card:has(small) { border-radius: 1.1rem; }
.sp-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 34px; height: 34px; }
.sp-txt { display: grid; min-width: 0; line-height: 1.25; }
.sp-txt b { font-size: 0.9rem; font-weight: 800; letter-spacing: 0.01em; }
.sp-txt small { margin-top: 1px; font-size: 0.76rem; font-weight: 600; color: #475569; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; word-break: break-word; }
.sp-root.is-done .sp-txt b { color: #15803d; }
.sp-root.is-error .sp-txt b { color: #b91c1c; }

/* backup / restore: coloured files fly into (backup) or out of (restore) a glowing database */
.sp-card.has-stage { flex-direction: column; align-items: stretch; gap: 8px; min-width: 340px; padding: 16px 24px 14px; border-radius: 1.5rem; }
.sp-card.has-stage .sp-txt { justify-items: center; text-align: center; }
.sp-stage { position: relative; width: 300px; height: 96px; margin: 0 auto; --c1: #f43f5e; --c2: #f59e0b; --c3: #22c55e; --c4: #06b6d4; --c5: #6366f1; --c6: #d946ef; }
.sp-trail { position: absolute; left: 6px; right: 64px; top: 47px; height: 2px; border-radius: 2px; background: repeating-linear-gradient(90deg, rgba(99, 102, 241, 0.45) 0 6px, transparent 6px 12px); opacity: 0.7; }
.sp-db { position: absolute; right: 0; top: 10px; width: 56px; height: 76px; display: grid; place-items: center; filter: drop-shadow(0 0 10px rgba(99, 102, 241, 0.75)); animation: sp-glow 1.3s ease-in-out infinite alternate; }
.sp-db-svg { position: relative; width: 56px; height: 63px; }
.sp-db-ring { position: absolute; inset: 14px 2px; border-radius: 9999px; border: 2px solid rgba(99, 102, 241, 0.7); animation: sp-ripple 1.6s ease-out infinite; }
.sp-db-ring.r2 { animation-delay: 0.8s; border-color: rgba(6, 182, 212, 0.7); }
@keyframes sp-glow { from { filter: drop-shadow(0 0 5px rgba(99, 102, 241, 0.55)); } to { filter: drop-shadow(0 0 16px rgba(14, 165, 233, 0.95)); } }
@keyframes sp-ripple { 0% { transform: scale(0.8); opacity: 0.9; } 100% { transform: scale(1.7); opacity: 0; } }
.sp-file { position: absolute; left: 4px; top: 34px; width: 20px; height: 26px; border-radius: 4px 8px 4px 4px; opacity: 0; background: linear-gradient(150deg, var(--fc), color-mix(in srgb, var(--fc) 62%, #000)); box-shadow: 0 0 12px 1px var(--fc), inset 0 0 0 1px rgba(255, 255, 255, 0.35); }
.sp-file::before { content: ''; position: absolute; left: 4px; right: 4px; top: 9px; height: 2px; border-radius: 2px; background: rgba(255, 255, 255, 0.85); box-shadow: 0 5px 0 rgba(255, 255, 255, 0.7), 0 10px 0 rgba(255, 255, 255, 0.55); }
.sp-file::after { content: ''; position: absolute; right: 0; top: 0; width: 7px; height: 7px; border-radius: 0 7px 0 3px; background: rgba(255, 255, 255, 0.55); }
.sp-file.f1 { --fc: var(--c1); top: 20px; animation-delay: 0s; } .sp-file.f2 { --fc: var(--c2); top: 46px; animation-delay: 0.35s; } .sp-file.f3 { --fc: var(--c3); top: 30px; animation-delay: 0.7s; }
.sp-file.f4 { --fc: var(--c4); top: 52px; animation-delay: 1.05s; } .sp-file.f5 { --fc: var(--c5); top: 14px; animation-delay: 1.4s; } .sp-file.f6 { --fc: var(--c6); top: 38px; animation-delay: 1.75s; }
.sp-stage.is-upload .sp-file,
.sp-stage.is-backup .sp-file { animation: sp-into 2.1s cubic-bezier(0.45, 0, 0.55, 1) infinite; }
.sp-stage.is-restore .sp-file { animation: sp-outof 2.1s cubic-bezier(0.45, 0, 0.55, 1) infinite; }
@keyframes sp-into { 0% { transform: translate(0, 0) rotate(-12deg) scale(1); opacity: 0; } 12% { opacity: 1; } 55% { transform: translate(150px, 0) rotate(8deg) scale(1); } 90% { transform: translate(232px, 0) rotate(0deg) scale(0.5); opacity: 1; } 100% { transform: translate(250px, 0) scale(0.15); opacity: 0; } }
@keyframes sp-outof { 0% { transform: translate(250px, 0) scale(0.15); opacity: 0; } 12% { transform: translate(232px, 0) scale(0.5); opacity: 1; } 55% { transform: translate(110px, 0) rotate(-8deg) scale(1); } 90% { transform: translate(14px, 0) rotate(10deg) scale(1); opacity: 1; } 100% { transform: translate(-6px, 0) rotate(14deg) scale(1.05); opacity: 0; } }
.sp-stage.is-restore .sp-db { filter: drop-shadow(0 0 10px rgba(244, 63, 94, 0.75)); animation-name: sp-glow-warm; }
.sp-stage.is-restore .sp-db-ring { border-color: rgba(244, 63, 94, 0.7); } .sp-stage.is-restore .sp-db-ring.r2 { border-color: rgba(245, 158, 11, 0.75); }
@keyframes sp-glow-warm { from { filter: drop-shadow(0 0 5px rgba(244, 63, 94, 0.55)); } to { filter: drop-shadow(0 0 16px rgba(245, 158, 11, 0.95)); } }
@media (max-width: 420px) { .sp-card.has-stage { min-width: 0; padding: 14px 14px 12px; } .sp-stage { width: 250px; transform-origin: center; } }
@media (prefers-reduced-motion: reduce) { .sp-db, .sp-db-ring, .sp-file { animation: none !important; } .sp-file.f1, .sp-file.f3, .sp-file.f5 { opacity: 1; transform: translateX(120px); } }

/* the upload bar under the flying photos */
.sp-up { display: grid; gap: 4px; justify-items: center; }
.sp-up-bar { display: block; width: 100%; height: 7px; border-radius: 9999px; background: rgba(148, 163, 184, 0.3); overflow: hidden; }
.sp-up-bar i { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #22c55e, #06b6d4, #6366f1, #d946ef); background-size: 200% 100%; animation: sp-flow 1.2s linear infinite; transition: width 0.25s ease; }
@keyframes sp-flow { to { background-position: -200% 0; } }
.sp-up small { font-size: 0.72rem; font-weight: 700; color: #64748b; font-variant-numeric: tabular-nums; }
html.dark .sp-up small { color: #cbd5e1; }
/* a long job without the animation: the notice sits under the label, inside the same card */
.sp-card.has-note { flex-wrap: wrap; row-gap: 10px; border-radius: 1.4rem; width: min(92vw, 380px); padding: 14px 16px; }
.sp-card.has-note .sp-keep { flex: 1 1 100%; margin: 0; }
.sp-txt b { white-space: nowrap; }
/* the "do not close" notice under a long job */
.sp-keep { display: flex; align-items: flex-start; gap: 8px; margin: 4px 0 0; padding: 9px 12px; border-radius: 0.9rem; background: #fffbeb; color: #92400e; font-size: 0.76rem; font-weight: 700; line-height: 1.35; box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.45); }
.sp-keep svg { flex: none; width: 18px; height: 18px; margin-top: 1px; color: #d97706; animation: sp-attn 1.6s ease-in-out infinite; }
@keyframes sp-attn { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.18); } }
html.dark .sp-keep { background: rgba(245, 158, 11, 0.14); color: #fcd34d; }
@media (prefers-reduced-motion: reduce) { .sp-keep svg { animation: none; } }

/* typing-status dots after the label: three colourful dots that bounce one after the other */
.sp-dots { display: inline-flex; align-items: flex-end; gap: 4px; margin-left: 6px; height: 0.95em; }
.sp-dots i { width: 8px; height: 8px; border-radius: 9999px; animation: sp-bounce 1.05s ease-in-out infinite; box-shadow: 0 2px 5px -1px currentColor; }
.sp-dots i:nth-child(1) { background: #f43f5e; color: #f43f5e; }
.sp-dots i:nth-child(2) { background: #f59e0b; color: #f59e0b; animation-delay: 0.16s; }
.sp-dots i:nth-child(3) { background: #06b6d4; color: #06b6d4; animation-delay: 0.32s; }
@keyframes sp-bounce { 0%, 60%, 100% { transform: translateY(0) scale(0.9); opacity: 0.8; } 30% { transform: translateY(-7px) scale(1.2); opacity: 1; } }

/* the colourful spinner: three dots chase each other round a ring */
.sp-spin { position: relative; width: 30px; height: 30px; border-radius: 9999px; animation: sp-turn 1s linear infinite; background: conic-gradient(from 0deg, #f43f5e, #f59e0b, #22c55e, #06b6d4, #6366f1, #f43f5e); -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 3px)); mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 3px)); }
.sp-spin i { display: none; }
@keyframes sp-turn { to { transform: rotate(360deg); } }

.sp-mark { width: 34px; height: 34px; }
.sp-mark-bg { fill: #22c55e; transform-origin: center; animation: sp-bg 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) both; }
.sp-root.is-error .sp-mark-bg { fill: #ef4444; }
.sp-mark-line { fill: none; stroke: #fff; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 24; stroke-dashoffset: 24; animation: sp-draw 0.35s 0.15s ease-out forwards; }
@keyframes sp-bg { from { transform: scale(0.4); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes sp-draw { to { stroke-dashoffset: 0; } }

.sp-pop-enter-active { transition: opacity 0.16s ease, transform 0.22s cubic-bezier(0.34, 1.4, 0.64, 1); }
.sp-pop-leave-active { transition: opacity 0.35s ease, transform 0.35s ease; }
.sp-pop-enter-from { opacity: 0; transform: scale(0.88); }
.sp-pop-leave-to { opacity: 0; transform: translateY(-8px) scale(0.97); }

html.dark .sp-card { background: #1e293b; color: #f1f5f9; box-shadow: 0 24px 48px -18px rgba(0, 0, 0, 0.7), inset 0 0 0 1px rgba(255, 255, 255, 0.12); }
html.dark .sp-txt small { color: #cbd5e1; }
html.dark .sp-root.is-done .sp-txt b { color: #4ade80; }
html.dark .sp-root.is-error .sp-txt b { color: #f87171; }
html.dark .sp-shield { background: rgba(2, 6, 23, 0.4); }
@media (prefers-reduced-motion: reduce) { .sp-spin { animation-duration: 2.4s; } .sp-dots i { animation: none; opacity: 0.8; } .sp-mark-bg, .sp-mark-line { animation: none; stroke-dashoffset: 0; } .sp-pop-enter-active, .sp-pop-leave-active { transition: opacity 0.15s ease; } }
@media print { .sp-root { display: none; } }
</style>
