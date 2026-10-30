<template>
  <teleport to="body">
    <transition name="idm-fade">
      <div v-if="s.open" class="idm-root" role="status" aria-live="polite">
        <div class="idm-dialog" :class="['is-' + s.phase, 'm-' + scene]" :style="{ '--band': s.color }">
          <div class="idm-scene" :class="{ 'is-dim': s.phase !== 'working' }" aria-hidden="true">
            <div class="idm-glow"></div>
            <i v-for="n in 7" :key="'p' + n" class="idm-spark" :style="{ '--x': (n * 41) % 100 + '%', '--y': (n * 23) % 70 + 10 + '%', '--t': 2.6 + (n % 3) * 0.7 + 's', '--d': n * -0.45 + 's' }"></i>

            <!-- everything inside is replayed every few seconds while the work goes on -->
            <div :key="cycle" class="idm-play" :style="{ '--cycle': cycleMs + 'ms' }">
              <!-- the printer or the PDF page the card travels to -->
              <div v-if="scene === 'print'" class="idm-printer">
                <div class="idm-clip"><div class="idm-sheet">
                  <b class="idm-mk a"></b><b class="idm-mk b"></b><b class="idm-mk c"></b><b class="idm-mk d"></b>
                  <span class="idm-pair p1"><i class="f"></i><i class="b"></i></span>
                  <span class="idm-pair p2"><i class="f"></i><i class="b"></i></span>
                </div></div>
                <svg class="idm-printer-svg" viewBox="0 0 150 110" width="150" height="110">
                  <defs>
                    <linearGradient id="idm-pb" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8aa4c2" /><stop offset="1" stop-color="#5d7a9d" /></linearGradient>
                    <linearGradient id="idm-pl" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#dbe6f3" /><stop offset="1" stop-color="#b6c8de" /></linearGradient>
                  </defs>
                  <rect x="14" y="26" width="122" height="30" rx="9" fill="url(#idm-pl)" />
                  <rect x="30" y="44" width="90" height="7" rx="3.5" fill="#1b3556" />
                  <rect x="2" y="48" width="146" height="54" rx="15" fill="url(#idm-pb)" />
                  <rect x="22" y="76" width="106" height="30" rx="6" fill="#f4f8fc" />
                  <path d="M32 88h56M32 96h38" stroke="#c7d6e8" stroke-width="3" stroke-linecap="round" />
                  <circle class="idm-led" cx="124" cy="62" r="3.2" fill="#4ade80" />
                  <circle cx="112" cy="62" r="3.2" fill="#fbbf24" opacity=".85" />
                </svg>
              </div>
              <div v-else-if="scene === 'pdf'" class="idm-doc">
                <span class="idm-doc-fold"></span>
                <span class="idm-doc-badge">PDF</span>
                <span class="idm-doc-l l1"></span><span class="idm-doc-l l2"></span><span class="idm-doc-l l3"></span>
                <svg class="idm-doc-arrow" viewBox="0 0 24 24" width="22" height="22"><path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 20h14" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </div>

              <!-- the card builds itself, layer by layer -->
              <div class="idm-flight">
                <div class="idm-stack" v-if="scene === 'issue' && s.count > 1"><i></i><i></i></div>
                <div class="idm-card">
                  <span class="idm-c-panel"></span>
                  <span class="idm-c-logo"><i></i><i></i><i></i></span>
                  <span class="idm-c-photo"><em class="head"></em><em class="body"></em></span>
                  <span class="idm-c-band"><i class="n1"></i><i class="n2"></i></span>
                  <span class="idm-c-qrbox"><i v-for="(c, k) in qr" :key="k" class="q" :class="{ off: !c.on }" :style="{ '--qd': c.d + 's' }"></i></span>
                  <span class="idm-c-num"><i></i><i></i><i></i></span>
                  <svg class="idm-c-arrow" viewBox="0 0 20 20" width="19" height="19"><path d="M16 16L5 5m0 0v9m0-9h9" fill="none" stroke="#fff200" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  <span class="idm-c-shine"></span>
                </div>
                <span class="idm-count" v-if="scene === 'issue' && s.count > 1">× {{ s.count }}</span>
              </div>
              <span class="idm-shadow"></span>
            </div>

            <!-- the moment it is ready -->
            <div v-if="s.phase === 'done'" class="idm-result ok">
              <span class="idm-ring r1"></span><span class="idm-ring r2"></span>
              <svg viewBox="0 0 52 52" width="76" height="76"><circle cx="26" cy="26" r="23" class="idm-bg" /><path d="M15 27l8 8 15-17" class="idm-tick" /></svg>
              <i v-for="n in 10" :key="n" class="idm-burst" :style="{ '--a': n * 36 + 'deg', '--r': 44 + (n % 3) * 9 + 'px' }"></i>
            </div>
            <div v-else-if="s.phase === 'error'" class="idm-result bad">
              <svg viewBox="0 0 52 52" width="76" height="76"><circle cx="26" cy="26" r="23" class="idm-bg" /><path d="M18 18l16 16M34 18L18 34" class="idm-cross" /></svg>
            </div>
          </div>

          <div class="idm-text">
            <h3>{{ heading }}</h3>
            <transition name="idm-step" mode="out-in"><p v-if="s.phase === 'working'" :key="step" class="idm-step">{{ steps[step] }}</p><p v-else-if="s.message" key="msg" class="idm-step msg">{{ s.message }}</p></transition>
            <div v-if="s.phase === 'working'" class="idm-bar" :class="{ 'is-fixed': s.percent !== null }"><i :style="s.percent !== null ? { width: Math.max(5, s.percent) + '%' } : null"></i></div>
            <small v-if="s.phase === 'working' && s.total" class="idm-nums">{{ $t(':a of :b cards', { a: s.done, b: s.total }) }}</small>
            <small v-else-if="s.phase === 'working' && long" class="idm-nums">{{ $t('Please keep this window open until it is ready.') }}</small>
          </div>

          <div v-if="s.phase === 'error' || (s.phase === 'done' && s.openUrl) || (s.phase === 'working' && s.hideable && late)" class="idm-actions">
            <a v-if="s.phase === 'done' && s.openUrl" :href="s.openUrl" target="_blank" rel="noopener" class="lg-btn lg-btn-primary" @click="close"><icons name="printer" class="w-4 h-4" />{{ $t('Open for printing') }}</a>
            <button v-else-if="s.phase === 'working'" type="button" class="lg-btn lg-btn-soft" @click="close">{{ $t('Keep working in the background') }}</button>
            <button v-else type="button" class="lg-btn lg-btn-soft" @click="close">{{ $t('Close') }}</button>
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
import { idMake, idMakeClose } from '@/Core/idMake';

// The ID press: a card builds itself (panel, photo frame, name band, QR squares, number, arrow, a sweep of light) and then travels into a printer (print, batch)
// or onto a PDF page (download); when the file is ready a tick bursts over it. Pure CSS, replayed every few seconds; with "reduce motion" it shows still.
export default {
  data() { return { s: idMake, cycle: 0, step: 0, timer: null, stepTimer: null, lateTimer: null, late: false }; },
  computed: {
    scene() { return this.s.mode === 'batch' ? 'print' : this.s.mode; },
    cycleMs() { return this.scene === 'issue' ? 3800 : this.scene === 'pdf' ? 5000 : 5600; },
    long() { return this.s.mode === 'batch' && !this.s.total; },
    qr() {
      // a small fixed pattern with the three corner finders of a QR code; every square pops in at its own moment
      const rows = ['1111011', '1001010', '1011110', '0100101', '1110010', '1011101', '1111011'];
      const out = []; rows.forEach((r, y) => [...r].forEach((c, x) => out.push({ on: c === '1', d: 0.85 + ((x * 7 + y * 13) % 11) * 0.075 })));
      return out;
    },
    heading() {
      if (this.s.phase === 'done') return { issue: this.$t('Your ID is ready'), print: this.$t('Ready to print'), pdf: this.$t('PDF is ready'), batch: this.$t('Your cards are ready') }[this.s.mode] || this.$t('Ready');
      if (this.s.phase === 'error') return this.$t('That did not work');
      return this.s.title || { issue: this.$t('Making the ID card'), print: this.$t('Preparing the print'), pdf: this.$t('Creating the PDF'), batch: this.$t('Making the cards') }[this.s.mode];
    },
    steps() {
      return {
        issue: [this.$t('Checking the details'), this.$t('Fitting the name on the card'), this.$t('Placing the photograph'), this.$t('Drawing the QR code'), this.$t('Numbering the ID')],
        print: [this.$t('Making the card faces'), this.$t('Fitting the names'), this.$t('Laying out the sheet'), this.$t('Adding the cutting marks'), this.$t('Opening it for printing')],
        pdf: [this.$t('Making the card faces'), this.$t('Embedding the fonts'), this.$t('Building the PDF pages'), this.$t('Almost ready')],
        batch: [this.$t('Making the cards'), this.$t('Fitting the names'), this.$t('Laying out the sheets'), this.$t('Building the files')],
      }[this.s.mode] || [];
    },
  },
  watch: {
    's.open'(v) { v ? this.run() : this.stop(); },
    's.mode'() { this.cycle++; this.step = 0; },
  },
  mounted() { if (this.s.open) this.run(); },
  beforeUnmount() { this.stop(); },
  methods: {
    run() {
      this.stop(); this.late = false; this.step = 0;
      this.timer = setInterval(() => { if (this.s.phase === 'working') this.cycle++; }, this.cycleMs);
      this.stepTimer = setInterval(() => { if (this.s.phase === 'working') this.step = (this.step + 1) % Math.max(1, this.steps.length); }, 1250);
      this.lateTimer = setTimeout(() => { this.late = true; }, 8000);
    },
    stop() { clearInterval(this.timer); clearInterval(this.stepTimer); clearTimeout(this.lateTimer); },
    close() { idMakeClose(); },
  },
};
</script>

<style scoped>
.idm-root { position: fixed; inset: 0; z-index: 100000; display: grid; place-items: center; padding: 16px; background: rgba(10, 24, 44, 0.46); backdrop-filter: blur(7px) saturate(1.2); -webkit-backdrop-filter: blur(7px) saturate(1.2); }
.idm-fade-enter-active, .idm-fade-leave-active { transition: opacity 0.28s ease; } .idm-fade-enter-from, .idm-fade-leave-to { opacity: 0; }
.idm-fade-enter-active .idm-dialog { animation: idm-dlg 0.45s cubic-bezier(0.2, 0.9, 0.25, 1.15) both; }
@keyframes idm-dlg { from { opacity: 0; transform: translateY(18px) scale(0.94); } to { opacity: 1; transform: none; } }

.idm-dialog { width: min(380px, 100%); border-radius: 30px; padding: 16px 18px 22px; background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(244, 248, 253, 0.97)); color: #0f2540; box-shadow: 0 40px 70px -30px rgba(8, 20, 40, 0.75), 0 0 0 1px rgba(255, 255, 255, 0.6) inset; text-align: center; }
@media (prefers-color-scheme: dark) { :root:not([data-theme='light']) .idm-dialog { background: linear-gradient(180deg, #16263d, #101c30); color: #e8f0fb; } }
:global(html[data-theme='dark']) .idm-dialog, :global(.dark) .idm-dialog { background: linear-gradient(180deg, #16263d, #101c30); color: #e8f0fb; }

/* ---------- the stage ---------- */
.idm-scene { position: relative; width: 300px; max-width: 100%; height: 190px; margin: 0 auto; transition: opacity 0.4s, filter 0.4s; }
.idm-scene.is-dim .idm-play { animation: none; opacity: 0.3; filter: blur(1.5px) saturate(0.8); }
.idm-glow { position: absolute; inset: 6px 10px; border-radius: 26px; background: radial-gradient(70% 80% at 50% 45%, color-mix(in srgb, var(--band) 24%, transparent), transparent 70%), radial-gradient(60% 70% at 85% 90%, rgba(99, 102, 241, 0.14), transparent 70%); animation: idm-breathe 4s ease-in-out infinite; }
@keyframes idm-breathe { 50% { transform: scale(1.04); opacity: 0.85; } }
.idm-spark { position: absolute; left: var(--x); top: var(--y); width: 5px; height: 5px; border-radius: 50%; background: var(--band); opacity: 0; animation: idm-float var(--t) var(--d) ease-in-out infinite; }
@keyframes idm-float { 0%, 100% { opacity: 0; transform: translateY(8px) scale(0.5); } 50% { opacity: 0.55; transform: translateY(-10px) scale(1); } }
.idm-play { position: absolute; inset: 0; animation: idm-cycle var(--cycle) linear both; }
@keyframes idm-cycle { 0% { opacity: 0; } 4% { opacity: 1; } 92% { opacity: 1; } 100% { opacity: 0; } }

/* ---------- the card (74 x 114 px = 55 x 85 mm) ---------- */
.idm-flight { position: absolute; left: 113px; top: 36px; z-index: 2; }
.m-print .idm-flight { left: 20px; animation: idm-to-printer 0.95s 2.55s cubic-bezier(0.55, 0.05, 0.85, 0.4) both; }
.m-pdf .idm-flight { left: 24px; animation: idm-to-doc 0.9s 2.45s cubic-bezier(0.5, 0, 0.2, 1) both; }
@keyframes idm-to-printer { 0% { transform: none; } 35% { transform: translate(34px, -6px) scale(0.92) rotate(-3deg); } 100% { transform: translate(168px, 36px) scale(0.34) rotate(4deg); opacity: 0.2; } }
@keyframes idm-to-doc { 0% { transform: none; } 60% { transform: translate(120px, -6px) scale(0.62) rotate(-4deg); } 100% { transform: translate(135px, 6px) scale(0.5) rotate(0); } }
.m-issue .idm-flight { animation: idm-hover 3.8s 1.8s ease-in-out both; }
@keyframes idm-hover { 0% { transform: none; } 50% { transform: translateY(-5px) rotate(-1.5deg); } 100% { transform: translateY(-2px); } }
.idm-shadow { position: absolute; left: 124px; top: 156px; width: 54px; height: 10px; border-radius: 50%; background: radial-gradient(closest-side, rgba(15, 37, 64, 0.35), transparent); animation: idm-in 0.6s 0.2s both; }
.m-print .idm-shadow { left: 31px; animation: idm-in 0.6s 0.2s both, idm-fadeout 0.4s 2.6s forwards; }
.m-pdf .idm-shadow { left: 35px; animation: idm-in 0.6s 0.2s both, idm-fadeout 0.4s 2.6s forwards; }
@keyframes idm-fadeout { to { opacity: 0; } }
.idm-card { position: relative; width: 74px; height: 114px; border-radius: 9px; background: #1e4467; box-shadow: 0 16px 22px -12px rgba(15, 37, 64, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.12) inset; overflow: hidden; animation: idm-rise 0.55s cubic-bezier(0.2, 0.9, 0.3, 1.1) both; }
.idm-card > * { position: absolute; display: block; }
.idm-stack { position: absolute; inset: 0; } .idm-stack i { position: absolute; inset: 0; border-radius: 9px; background: #2b5b87; box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); } .idm-stack i:nth-child(1) { transform: translate(7px, -5px) rotate(5deg); opacity: 0.85; } .idm-stack i:nth-child(2) { transform: translate(-6px, -3px) rotate(-5deg); opacity: 0.7; }
.idm-count { position: absolute; right: -16px; top: -10px; z-index: 3; padding: 3px 9px; border-radius: 999px; background: var(--band); color: #fff; font-size: 0.78rem; font-weight: 800; box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.7); animation: idm-pop 0.5s 1s both; }

.idm-c-panel { left: 3px; top: 4px; width: 68px; height: 56px; border-radius: 0 12px 0 0; background: #f0efeb; transform-origin: top; animation: idm-wipe-y 0.55s 0.25s cubic-bezier(0.3, 0.7, 0.2, 1) both; }
.idm-c-logo { left: 8px; top: 10px; width: 16px; height: 36px; display: flex; flex-direction: column; justify-content: space-between; animation: idm-in 0.4s 0.55s both; } .idm-c-logo i { height: 4px; border-radius: 2px; background: #1e4467; } .idm-c-logo i:nth-child(2) { width: 70%; } .idm-c-logo i:nth-child(3) { width: 45%; }
.idm-c-photo { left: 28px; top: 9px; width: 38px; height: 47px; border-radius: 6px; background: #b6bcc2; overflow: hidden; animation: idm-rise 0.5s 0.6s cubic-bezier(0.2, 0.9, 0.3, 1.2) both; }
.idm-c-photo em { position: absolute; background: #f4f6f8; } .idm-c-photo .head { left: 13px; top: 8px; width: 12px; height: 12px; border-radius: 50%; } .idm-c-photo .body { left: 7px; top: 24px; width: 24px; height: 26px; border-radius: 12px 12px 0 0; }
.idm-c-band { left: 0; top: 52px; width: 56px; height: 18px; border-radius: 0 7px 0 0; background: var(--band); transform-origin: left; animation: idm-wipe-x 0.5s 1.05s cubic-bezier(0.3, 0.7, 0.2, 1) both; }
.idm-c-band i { position: absolute; left: 5px; height: 3.5px; border-radius: 2px; transform-origin: left; } .idm-c-band .n1 { top: 4px; width: 38px; background: #fff200; animation: idm-wipe-x 0.45s 1.45s both; } .idm-c-band .n2 { top: 10.5px; width: 26px; background: rgba(255, 255, 255, 0.9); animation: idm-wipe-x 0.4s 1.65s both; }
.idm-c-qrbox { left: 3px; top: 73px; width: 38px; height: 38px; padding: 4px; border-radius: 0 10px 0 7px; background: #fffbf3; display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.6px; animation: idm-in 0.4s 1.2s both; }
.idm-c-qrbox .q { background: #1e4467; border-radius: 0.7px; animation: idm-pop 0.35s var(--qd) both; } .idm-c-qrbox .q.off { background: transparent; }
.idm-c-num { right: 4px; top: 76px; width: 28px; height: 9px; display: flex; gap: 2px; justify-content: flex-end; } .idm-c-num i { width: 8px; height: 9px; border-radius: 2px; background: #fff; animation: idm-rise 0.3s both; } .idm-c-num i:nth-child(1) { animation-delay: 1.85s; } .idm-c-num i:nth-child(2) { animation-delay: 1.98s; } .idm-c-num i:nth-child(3) { animation-delay: 2.11s; }
.idm-c-arrow { right: 6px; bottom: 6px; stroke-dasharray: 40; animation: idm-draw 0.55s 2.0s ease-out both; }
.idm-c-shine { inset: -20% auto -20% -60%; width: 46%; transform: skewX(-18deg); background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.7), transparent); animation: idm-shine 0.9s 2.2s ease-in-out both; }
.m-issue .idm-c-shine { animation-delay: 2.35s; }

@keyframes idm-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes idm-rise { from { opacity: 0; transform: translateY(12px) scale(0.9); } to { opacity: 1; transform: none; } }
@keyframes idm-wipe-y { from { transform: scaleY(0); } to { transform: scaleY(1); } }
@keyframes idm-wipe-x { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes idm-pop { 0% { transform: scale(0); opacity: 0; } 65% { transform: scale(1.35); opacity: 1; } 100% { transform: scale(1); opacity: 1; } }
@keyframes idm-draw { from { stroke-dashoffset: 40; opacity: 0; } 15% { opacity: 1; } to { stroke-dashoffset: 0; opacity: 1; } }
@keyframes idm-shine { from { left: -60%; } to { left: 150%; } }

/* ---------- the printer and the sheet that comes out ---------- */
.idm-printer { position: absolute; left: 146px; top: 66px; width: 150px; height: 110px; animation: idm-in 0.5s 0.3s both; }
.idm-printer-svg { position: absolute; inset: 0; z-index: 3; filter: drop-shadow(0 12px 12px rgba(15, 37, 64, 0.3)); animation: idm-bump 0.5s 3.35s ease-in-out both; }
@keyframes idm-bump { 40% { transform: translateY(2px) scale(1.012); } }
.idm-led { animation: idm-blink 0.5s 3.3s 4 alternate both; } @keyframes idm-blink { to { opacity: 0.25; } }
.idm-clip { position: absolute; left: 30px; top: -60px; width: 90px; height: 106px; overflow: hidden; z-index: 2; }
.idm-sheet { position: absolute; left: 2px; top: 0; width: 86px; height: 104px; border-radius: 4px 4px 0 0; background: #fff; box-shadow: 0 0 0 1px #d9e2ee; transform: translateY(106px); animation: idm-out 1.35s 3.45s cubic-bezier(0.25, 0.8, 0.3, 1) both; }
@keyframes idm-out { to { transform: none; } }
.idm-pair { position: absolute; left: 12px; display: flex; gap: 4px; opacity: 0; animation: idm-in 0.3s both; } .idm-pair.p1 { top: 9px; animation-delay: 3.95s; } .idm-pair.p2 { top: 45px; animation-delay: 4.2s; }
.idm-pair i { width: 25px; height: 34px; border-radius: 3px; background: #1e4467; position: relative; } .idm-pair i.f::after { content: ''; position: absolute; left: 0; top: 13px; width: 18px; height: 6px; border-radius: 0 3px 0 0; background: var(--band); } .idm-pair i.b { background: #cf7d1c; }
.idm-mk { position: absolute; width: 6px; height: 6px; border: 0 solid #94a3b8; } .idm-mk.a { left: 4px; top: 4px; border-width: 1px 0 0 1px; } .idm-mk.b { right: 4px; top: 4px; border-width: 1px 1px 0 0; } .idm-mk.c { left: 4px; top: 76px; border-width: 0 0 1px 1px; } .idm-mk.d { right: 4px; top: 76px; border-width: 0 1px 1px 0; }

/* ---------- the PDF page ---------- */
.idm-doc { position: absolute; left: 160px; top: 34px; width: 98px; height: 124px; border-radius: 8px 22px 8px 8px; background: #fff; box-shadow: 0 0 0 1px #d9e2ee, 0 20px 24px -14px rgba(15, 37, 64, 0.6); animation: idm-rise 0.55s 0.35s cubic-bezier(0.2, 0.9, 0.3, 1.1) both; }
.idm-doc-fold { position: absolute; right: 0; top: 0; width: 24px; height: 24px; border-radius: 0 0 0 9px; background: linear-gradient(225deg, transparent 50%, #e2eaf5 50%); }
.idm-doc-badge { position: absolute; left: 10px; bottom: 12px; padding: 3px 9px; border-radius: 6px; background: #e0442b; color: #fff; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.06em; animation: idm-pop 0.45s 3.5s both; }
.idm-doc-l { position: absolute; left: 12px; height: 4px; border-radius: 2px; background: #dbe4ef; transform-origin: left; animation: idm-wipe-x 0.4s both; } .idm-doc-l.l1 { top: 18px; width: 46px; animation-delay: 3.5s; } .idm-doc-l.l2 { top: 28px; width: 62px; animation-delay: 3.65s; } .idm-doc-l.l3 { top: 38px; width: 40px; animation-delay: 3.8s; }
.idm-doc-arrow { position: absolute; right: 9px; bottom: 9px; padding: 4px; box-sizing: content-box; border-radius: 50%; background: #1565c0; animation: idm-bounce 0.9s 3.9s ease-in-out 2 both; } @keyframes idm-bounce { 0% { opacity: 0; transform: translateY(-14px); } 40% { opacity: 1; transform: translateY(2px); } 100% { opacity: 1; transform: none; } }

/* ---------- the result ---------- */
.idm-result { position: absolute; inset: 0; display: grid; place-items: center; z-index: 5; }
.idm-result svg { position: relative; z-index: 2; animation: idm-pop 0.55s both; }
.idm-bg { fill: #16a34a; } .idm-tick, .idm-cross { fill: none; stroke: #fff; stroke-width: 4.5; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 40; animation: idm-tickdraw 0.5s 0.25s ease-out both; }
.bad .idm-bg { fill: #dc2626; } .bad svg { animation: idm-pop 0.5s both, idm-shake 0.5s 0.4s both; }
@keyframes idm-tickdraw { from { stroke-dashoffset: 40; } to { stroke-dashoffset: 0; } }
@keyframes idm-shake { 25% { transform: translateX(-5px); } 50% { transform: translateX(5px); } 75% { transform: translateX(-3px); } }
.idm-ring { position: absolute; left: 50%; top: 50%; width: 76px; height: 76px; margin: -38px 0 0 -38px; border-radius: 50%; border: 3px solid #16a34a; opacity: 0; animation: idm-ripple 1.3s 0.2s ease-out both; } .idm-ring.r2 { animation-delay: 0.55s; }
@keyframes idm-ripple { 0% { transform: scale(0.8); opacity: 0.7; } 100% { transform: scale(2.3); opacity: 0; } }
.idm-burst { position: absolute; left: 50%; top: 50%; width: 7px; height: 7px; margin: -3.5px; border-radius: 50%; background: var(--band); opacity: 0; animation: idm-burst 0.9s 0.3s ease-out both; } .idm-burst:nth-child(odd) { background: #fff200; width: 5px; height: 5px; }
@keyframes idm-burst { 0% { opacity: 1; transform: rotate(var(--a)) translateX(30px) scale(0.6); } 100% { opacity: 0; transform: rotate(var(--a)) translateX(var(--r)) scale(1); } }

/* ---------- the words ---------- */
.idm-text { display: grid; gap: 7px; padding: 4px 6px 0; }
.idm-text h3 { margin: 0; font-size: 1.14rem; font-weight: 800; letter-spacing: -0.01em; }
.idm-step { margin: 0; min-height: 1.3em; font-size: 0.88rem; font-weight: 600; opacity: 0.68; } .idm-step.msg { opacity: 0.85; }
.idm-step-enter-active, .idm-step-leave-active { transition: opacity 0.25s, transform 0.25s; } .idm-step-enter-from { opacity: 0; transform: translateY(6px); } .idm-step-leave-to { opacity: 0; transform: translateY(-6px); }
.idm-bar { height: 6px; margin: 4px auto 0; width: min(220px, 80%); border-radius: 999px; background: rgba(100, 116, 139, 0.2); overflow: hidden; position: relative; }
.idm-bar i { position: absolute; inset: 0 auto 0 0; width: 40%; border-radius: inherit; background: linear-gradient(90deg, var(--band), #6366f1, var(--band)); background-size: 200% 100%; animation: idm-run 1.4s ease-in-out infinite, idm-grad 1.6s linear infinite; }
.idm-bar.is-fixed i { animation: idm-grad 1.6s linear infinite; transition: width 0.5s ease; }
@keyframes idm-run { 0% { left: -40%; } 100% { left: 100%; } } @keyframes idm-grad { to { background-position: -200% 0; } }
.idm-nums { font-size: 0.78rem; font-weight: 700; opacity: 0.6; }
.idm-actions { display: flex; justify-content: center; gap: 8px; margin-top: 14px; }

@media (max-width: 380px) { .idm-scene { transform: scale(0.88); transform-origin: top center; margin-bottom: -20px; } }
@media (prefers-reduced-motion: reduce) {
  .idm-root *, .idm-dialog * { animation: none !important; transition: none !important; }
  .idm-play { opacity: 1; } .idm-sheet { transform: none; } .idm-flight { transform: none; }
  .m-print .idm-flight, .m-pdf .idm-flight { display: none; }
  .idm-bar i { width: 60%; }
}
</style>
