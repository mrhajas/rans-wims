<template>
  <div class="auth-shell min-h-screen">
    <!-- today's prayers at the top right of every sign-in page (sign in, forgot / reset password, two-step code) -->
    <login-prayer-strip v-if="$page.props.loginPrayer" />
    <div class="auth-bg-orb auth-bg-orb-a"></div>
    <div class="auth-bg-orb auth-bg-orb-b"></div>

    <div class="relative z-10 mx-auto flex min-h-screen max-w-6xl items-center px-4 py-6 sm:px-8 sm:py-10">
      <div class="auth-glow-wrap w-full" :class="{ 'is-ctx': ctx }">
        <div class="auth-glow-halo"></div>
        <div class="auth-glow-spin-layer"></div>
        <transition name="auth-ctx">
          <div v-if="ctx" class="auth-ctx" aria-hidden="true"><span class="auth-ctx-halo"></span><span class="auth-ctx-ring"></span></div>
        </transition>

        <div class="auth-card auth-card-in w-full overflow-hidden lg:grid lg:grid-cols-2">
          <section class="auth-form-pane">
            <div class="auth-form-inner">
              <slot name="form" />
            </div>
          </section>

          <aside class="auth-art hidden lg:flex lg:flex-col lg:justify-between">
            <!-- Glass isometric tiles with a floating cube (decorative only). -->
            <div class="auth-iso-stage" aria-hidden="true">
              <div class="auth-iso">
                <span v-for="n in 25" :key="n" class="auth-tile" :style="{ '--i': n }"></span>
                <span class="auth-cube">
                  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.4 5.6L20 10l-5.6 2.4L12 18l-2.4-5.6L4 10l5.6-2.4z" /></svg>
                </span>
              </div>
            </div>

            <div class="auth-art-copy">
              <slot name="aside-head" />
            </div>

            <div class="auth-art-rows">
              <slot name="aside-items" />
            </div>
          </aside>
        </div>
      </div>
    </div>

    <slot name="after" />
  </div>
</template>

<script>
import LoginPrayerStrip from '@/Shared/Prayer/LoginPrayerStrip.vue';
import { syncPrayer } from '@/Shared/Prayer/prayerStore';

export default {
  components: { LoginPrayerStrip },
  props: { ctx: { type: Boolean, default: false } },
  created() {
    if (this.$page.props.loginPrayer) syncPrayer(this.$page.props.loginPrayer);
  },
};
</script>

<style>
/* Shared look for Login, Forgot Password and Reset Password.
   Unscoped on purpose (every selector is prefixed .auth-shell) so the page
   content passed through the slots can use the same classes. */
.auth-shell {
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(900px 520px at 8% -10%, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0) 60%),
    radial-gradient(800px 500px at 100% 100%, rgba(186, 205, 255, 0.55), rgba(186, 205, 255, 0) 62%),
    #e9ecf2;
}

.auth-shell .auth-bg-orb { position: absolute; border-radius: 9999px; pointer-events: none; filter: blur(2px); }
.auth-shell .auth-bg-orb-a {
  width: 380px; height: 380px; top: -150px; left: -120px;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.95), rgba(147, 178, 255, 0.22) 55%, rgba(147, 178, 255, 0) 75%);
  animation: auth-float-a 16s ease-in-out infinite;
}
.auth-shell .auth-bg-orb-b {
  width: 320px; height: 320px; right: -110px; bottom: -120px;
  background: radial-gradient(circle at 45% 45%, rgba(255, 255, 255, 0.8), rgba(110, 148, 255, 0.2) 52%, rgba(110, 148, 255, 0) 76%);
  animation: auth-float-b 20s ease-in-out infinite;
}
@keyframes auth-float-a { 0%, 100% { transform: translate3d(0, 0, 0); } 50% { transform: translate3d(24px, 18px, 0); } }
@keyframes auth-float-b { 0%, 100% { transform: translate3d(0, 0, 0); } 50% { transform: translate3d(-20px, -16px, 0); } }

/* ---- Spinning glow outline (unchanged from the previous design) ---- */
.auth-shell .auth-glow-wrap { position: relative; }

@property --auth-glow-angle {
  syntax: '<angle>';
  inherits: false;
  initial-value: 0deg;
}

.auth-shell .auth-glow-halo {
  position: absolute;
  inset: -22px;
  z-index: -2;
  border-radius: 40px;
  background: conic-gradient(from var(--auth-glow-angle), #3b82f6, #8b5cf6, #ec4899, #8b5cf6, #3b82f6);
  filter: blur(28px);
  opacity: 0.5;
  animation: auth-glow-spin 6s linear infinite;
}

.auth-shell .auth-glow-spin-layer {
  position: absolute;
  inset: -6px;
  z-index: -1;
  border-radius: 34px;
  padding: 6px;
  background: conic-gradient(from var(--auth-glow-angle), #3b82f6, #8b5cf6, #ec4899, #8b5cf6, #3b82f6);
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  filter: blur(10px);
  opacity: 0.85;
  animation: auth-glow-spin 6s linear infinite;
}

@keyframes auth-glow-spin { to { --auth-glow-angle: 360deg; } }

/* ---- The other WIMS has been chosen: the usual glow gives way to a thin blue > indigo > violet > crimson ring and a quiet halo. Never blocks the pointer. ---- */
.auth-shell .auth-glow-wrap .auth-glow-halo, .auth-shell .auth-glow-wrap .auth-glow-spin-layer { transition: opacity 0.7s ease; }
.auth-shell .auth-glow-wrap.is-ctx .auth-glow-halo, .auth-shell .auth-glow-wrap.is-ctx .auth-glow-spin-layer { opacity: 0; }
.auth-shell .auth-ctx { position: absolute; inset: 0; z-index: -1; pointer-events: none; transition: opacity 0.7s ease; }
.auth-shell .auth-ctx-ring, .auth-shell .auth-ctx-halo { position: absolute; pointer-events: none; background: conic-gradient(from var(--auth-glow-angle), #427bff, #6258ff, #a855f7, #f05270, #a855f7, #6258ff, #427bff); animation: auth-glow-spin 6.5s linear infinite; }
.auth-shell .auth-ctx-ring { inset: -2px; border-radius: 30px; padding: 2px; -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; }
.auth-shell .auth-ctx-halo { inset: -10px; border-radius: 36px; filter: blur(16px); opacity: 0.32; z-index: -1; }
.auth-ctx-enter-from, .auth-ctx-leave-to { opacity: 0; }
@media (max-width: 1023.98px) { .auth-shell .auth-ctx-ring { border-radius: 24px; } .auth-shell .auth-ctx-halo { border-radius: 30px; } }
@media (prefers-reduced-motion: reduce) { .auth-shell .auth-ctx-ring, .auth-shell .auth-ctx-halo { animation: none !important; --auth-glow-angle: 135deg; } }

/* ---- Window ---- */
.auth-shell .auth-card {
  position: relative;
  z-index: 1;
  border-radius: 28px;
  border: 1px solid rgba(255, 255, 255, 0.9);
  background: #f4f5f8;
  box-shadow: inset 0 1px 0 #fff, 0 40px 80px -30px rgba(30, 50, 110, 0.35);
}
.auth-shell .auth-card-in { animation: auth-card-in 0.8s cubic-bezier(0.16, 1, 0.3, 1) both; }
@keyframes auth-card-in { 0% { opacity: 0; transform: translateY(26px) scale(0.985); } 100% { opacity: 1; transform: none; } }

.auth-shell .auth-form-pane { display: flex; align-items: center; justify-content: center; padding: 2.25rem 1.5rem; }
@media (min-width: 640px) { .auth-shell .auth-form-pane { padding: 3rem 2.5rem; } }
@media (min-width: 1024px) { .auth-shell .auth-form-pane { padding: 3.5rem 3rem; } }
.auth-shell .auth-form-inner { width: 100%; max-width: 25rem; }

/* Form content rises in, one block after another. */
.auth-shell .auth-form-inner > * { animation: auth-rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
.auth-shell .auth-form-inner > *:nth-child(1) { animation-delay: 0.05s; }
.auth-shell .auth-form-inner > *:nth-child(2) { animation-delay: 0.12s; }
.auth-shell .auth-form-inner > *:nth-child(3) { animation-delay: 0.19s; }
.auth-shell .auth-form-inner > *:nth-child(4) { animation-delay: 0.26s; }
.auth-shell .auth-form-inner > *:nth-child(5) { animation-delay: 0.33s; }
.auth-shell .auth-form-inner > *:nth-child(6) { animation-delay: 0.4s; }
.auth-shell .auth-form-inner > *:nth-child(n + 7) { animation-delay: 0.46s; }
@keyframes auth-rise { 0% { opacity: 0; transform: translateY(14px); } 100% { opacity: 1; transform: none; } }

/* ---- Form controls ---- */
.auth-shell label { font-size: 0.78rem; font-weight: 700; color: #475569; }

.auth-shell .auth-input {
  height: 46px;
  border: 1px solid #e1e5ec !important;
  border-radius: 12px !important;
  background: #ffffff !important;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04) !important;
  font-size: 0.9rem;
  color: #0f172a;
  transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}
.auth-shell .auth-input:hover { border-color: #c9d2e3 !important; }
.auth-shell .auth-input:focus {
  border-color: #6f8dff !important;
  box-shadow: 0 0 0 4px rgba(111, 141, 255, 0.2) !important;
  outline: none;
}

.auth-shell .auth-field-icon { color: #94a3b8; transition: color 0.18s ease; }
.auth-shell .relative:focus-within > span > .auth-field-icon { color: #4f6df5; }

.auth-shell .auth-note {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid #e7ebf3;
  background: #ffffff;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
  font-size: 0.74rem;
  color: #475569;
}
.auth-shell .auth-note-icon {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  width: 24px; height: 24px; border-radius: 9999px; color: #fff;
  background: linear-gradient(145deg, #7aa2ff, #4f6df5);
  animation: auth-note-pulse 3s ease-in-out infinite;
}
@keyframes auth-note-pulse { 0%, 80%, 100% { box-shadow: 0 0 0 0 rgba(79, 109, 245, 0); } 90% { box-shadow: 0 0 0 6px rgba(79, 109, 245, 0.2); } }

.auth-shell .auth-submit {
  height: 48px;
  border-radius: 14px !important;
  background: linear-gradient(180deg, #1f2430, #0b0e14) !important;
  color: #fff;
  font-size: 0.82rem;
  letter-spacing: 0.08em;
  box-shadow: 0 14px 24px -12px rgba(11, 14, 20, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.14);
  transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
}
.auth-shell .auth-submit:hover:not(:disabled) { transform: translateY(-1px); filter: brightness(1.15); box-shadow: 0 18px 28px -12px rgba(11, 14, 20, 0.75); }
.auth-shell .auth-submit:active:not(:disabled) { transform: translateY(0) scale(0.99); }
.auth-shell .auth-submit:focus { box-shadow: 0 0 0 4px rgba(111, 141, 255, 0.35); }

.auth-shell .auth-link { color: #3d5af1; transition: color 0.15s ease; }
.auth-shell .auth-link:hover { color: #1e3a8a; }
.auth-shell .auth-link-chip {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  width: 22px; height: 22px; border-radius: 8px; color: #fff;
  background: linear-gradient(145deg, #7aa2ff, #3f63f0);
  box-shadow: 0 6px 10px -6px rgba(63, 99, 240, 0.9);
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.auth-shell .auth-link:hover .auth-link-chip { transform: rotate(-12deg) scale(1.12); }
.auth-shell .auth-back {
  display: block; width: 100%; padding: 10px 12px; margin-top: 14px; border-radius: 12px;
  text-align: center; font-size: 0.85rem; font-weight: 600; color: #475569;
  transition: background 0.15s ease, color 0.15s ease;
}
.auth-shell .auth-back:hover { background: #ffffff; color: #0f172a; }

/* ---- Right-hand art panel ---- */
.auth-shell .auth-art {
  position: relative;
  margin: 14px;
  padding: 2.25rem;
  overflow: hidden;
  border-radius: 22px;
  color: #0f172a;
  background:
    radial-gradient(420px 220px at 85% 12%, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0) 70%),
    linear-gradient(180deg, #fbfcff 0%, #eaf0ff 30%, #a9c0ff 62%, #5f86ff 100%);
  box-shadow: inset 0 1px 0 #fff, 0 20px 40px -26px rgba(40, 70, 170, 0.5);
}

.auth-shell .auth-art-copy { position: relative; z-index: 2; max-width: 20rem; }
.auth-shell .auth-art-badge {
  display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 9999px;
  border: 1px solid rgba(79, 109, 245, 0.28); background: rgba(255, 255, 255, 0.7);
  font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #3d5af1;
}
.auth-shell .auth-art-title { margin-top: 1.1rem; font-size: 1.65rem; font-weight: 800; line-height: 1.18; letter-spacing: -0.02em; color: #0b1220; }
.auth-shell .auth-art-text { margin-top: 0.9rem; font-size: 0.82rem; line-height: 1.55; color: #475569; text-align: justify; text-justify: inter-word; hyphens: auto; }

/* Isometric glass tiles + floating cube */
.auth-shell .auth-iso-stage {
  position: absolute; top: -34px; right: -70px; width: 380px; height: 360px; transform: scale(0.82); transform-origin: top right;
  perspective: 900px; pointer-events: none; z-index: 1;
}
.auth-shell .auth-iso {
  position: absolute; top: 46px; right: 20px; width: 300px; height: 300px;
  display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px;
  transform: rotateX(58deg) rotateZ(-45deg); transform-style: preserve-3d;
}
.auth-shell .auth-tile {
  border-radius: 14px;
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.4));
  border: 1px solid rgba(255, 255, 255, 0.95);
  box-shadow: 0 14px 22px -10px rgba(70, 105, 220, 0.35), inset 0 1px 0 #fff;
  animation: auth-tile-wave 5s ease-in-out infinite;
  animation-delay: calc(var(--i) * -0.22s);
}
@keyframes auth-tile-wave {
  0%, 100% { transform: translateZ(0); opacity: 0.85; }
  50% { transform: translateZ(12px); opacity: 1; }
}
.auth-shell .auth-cube {
  position: absolute; top: 4%; right: 4%; width: 22%; height: 22%;
  display: flex; align-items: center; justify-content: center; border-radius: 22%;
  color: #fff; background: linear-gradient(145deg, #6c93ff, #2f56f0);
  box-shadow: 0 26px 30px -10px rgba(47, 86, 240, 0.65), inset 0 2px 0 rgba(255, 255, 255, 0.45), inset 0 -6px 12px rgba(20, 40, 160, 0.35);
  transform: translateZ(34px);
  animation: auth-cube-float 4.2s ease-in-out infinite;
}
.auth-shell .auth-cube svg { width: 46%; height: 46%; filter: drop-shadow(0 2px 3px rgba(10, 30, 120, 0.4)); }
@keyframes auth-cube-float {
  0%, 100% { transform: translateZ(34px); }
  50% { transform: translateZ(62px); }
}

/* Feature rows: glass bars with a status that cycles "working" -> "done". */
.auth-shell .auth-art-rows { position: relative; z-index: 2; display: flex; flex-direction: column; gap: 12px; }
.auth-shell .auth-row {
  position: relative;
  display: flex; align-items: flex-start; gap: 12px;
  padding: 14px 46px 14px 14px;
  border-radius: 16px;
  border: 1px solid rgba(255, 255, 255, 0.85);
  background: rgba(255, 255, 255, 0.62);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  box-shadow: 0 14px 24px -16px rgba(20, 40, 150, 0.6), inset 0 1px 0 #fff;
  color: #1b2b63;
  font-weight: 600;
  font-size: 0.8rem;
  line-height: 1.45;
  animation: auth-row-loop 9s cubic-bezier(0.16, 1, 0.3, 1) infinite both;
  transition: background 0.2s ease;
}
.auth-shell .auth-row:hover { background: rgba(255, 255, 255, 0.82); }
.auth-shell .auth-row:nth-child(1) { animation-delay: 0s; }
.auth-shell .auth-row:nth-child(2) { animation-delay: 0.45s; }
.auth-shell .auth-row:nth-child(3) { animation-delay: 0.9s; }
.auth-shell .auth-row:nth-child(4) { animation-delay: 1.35s; }
/* Each card rises from below, rests, fades away and rises again - on a loop. */
@keyframes auth-row-loop {
  0% { opacity: 0; transform: translateY(46px); }
  12% { opacity: 1; transform: translateY(0); }
  80% { opacity: 1; transform: translateY(0); }
  92%, 100% { opacity: 0; transform: translateY(-14px); }
}
@media (prefers-reduced-motion: reduce) { .auth-shell .auth-row { animation: none; } }

.auth-shell .auth-row-icon {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  width: 34px; height: 34px; border-radius: 11px; color: #fff;
  background: linear-gradient(145deg, #7aa2ff, #3f63f0); box-shadow: 0 8px 14px -8px rgba(63, 99, 240, 0.8);
}

.auth-shell .auth-row::before,
.auth-shell .auth-row::after {
  content: '';
  position: absolute; top: 50%; right: 14px; width: 20px; height: 20px; margin-top: -10px; border-radius: 9999px;
}
.auth-shell .auth-row::before {
  border: 2px solid rgba(63, 99, 240, 0.25); border-top-color: #3f63f0;
  animation: auth-status-spin 6s linear infinite, auth-status-work 6s steps(1, end) infinite;
}
.auth-shell .auth-row::after {
  background: #34d399 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 10.5l3.2 3.2L15 7'/%3E%3C/svg%3E") center / 70% no-repeat;
  box-shadow: 0 0 0 4px rgba(52, 211, 153, 0.25);
  animation: auth-status-done 6s steps(1, end) infinite;
}
.auth-shell .auth-row:nth-child(2)::before, .auth-shell .auth-row:nth-child(2)::after { animation-delay: 1.4s; }
.auth-shell .auth-row:nth-child(3)::before, .auth-shell .auth-row:nth-child(3)::after { animation-delay: 2.8s; }
.auth-shell .auth-row:nth-child(4)::before, .auth-shell .auth-row:nth-child(4)::after { animation-delay: 4.2s; }
@keyframes auth-status-spin { to { transform: rotate(360deg); } }
@keyframes auth-status-work { 0% { opacity: 1; } 45% { opacity: 0; } 100% { opacity: 0; } }
@keyframes auth-status-done { 0% { opacity: 0; transform: scale(0.6); } 45% { opacity: 1; transform: scale(1); } 100% { opacity: 1; transform: scale(1); } }

@media (max-width: 1023.98px) {
  .auth-shell .auth-card { border-radius: 22px; }
  .auth-shell .auth-glow-spin-layer { border-radius: 28px; }
}

@media (prefers-reduced-motion: reduce) {
  .auth-shell *, .auth-shell *::before, .auth-shell *::after { animation: none !important; }
  .auth-shell .auth-row::before { opacity: 0; }
}
</style>
