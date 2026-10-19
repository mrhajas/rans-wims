<template>
  <teleport to="body">
    <transition name="kbs">
      <div v-if="shown" class="kbs-backdrop" role="dialog" aria-modal="true" aria-labelledby="kbs-title" @click.self="$emit('close')">
        <div class="kbs-fit" :style="{ '--fit': fit }">
        <div class="kbs-card" ref="card">
          <span class="kbs-blob b1" aria-hidden="true"></span><span class="kbs-blob b2" aria-hidden="true"></span><span class="kbs-blob b3" aria-hidden="true"></span>
          <span v-for="n in 14" :key="n" class="kbs-spark" aria-hidden="true" :style="spark(n)"></span>

          <button ref="close" type="button" class="kbs-close" :aria-label="$t('Close')" @click="$emit('close')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg></button>

          <header class="kbs-head">
            <div class="kbs-orbit" aria-hidden="true">
              <i class="ring r1"></i><i class="ring r2"></i>
              <span class="core"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2Z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7Z" /></svg></span>
              <span class="sat s1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg></span>
              <span class="sat s2"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9Z" /></svg></span>
              <span class="sat s3"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3Z" /><circle cx="12" cy="13" r="3" /></svg></span>
              <span class="sat s4"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><rect x="6" y="14" width="12" height="8" /></svg></span>
            </div>
            <div class="kbs-title">
              <span class="kbs-pill"><i></i>{{ $t('Under development') }}</span>
              <h2 id="kbs-title">{{ $t('WIMS Knowledge Base') }}</h2>
              <p>{{ $t('The knowledge base for WIMS is being built right now.') }}</p>
            </div>
          </header>

          <section class="kbs-count" :aria-label="$t('Time left until launch')">
            <div v-for="u in units" :key="u.k" class="kbs-unit">
              <b :key="u.k + u.v" class="flip">{{ u.v }}</b>
              <span>{{ $t(u.l) }}</span>
            </div>
            <p v-if="done" class="kbs-done">{{ $t('Almost there. It opens any moment now.') }}</p>
          </section>

          <div class="kbs-bar" aria-hidden="true"><span></span></div>

          <p class="kbs-lead">{{ $t('A free, open guide to every part of WIMS. Anyone can read it without signing in, from a phone, tablet or computer.') }}</p>

          <ul class="kbs-grid">
            <li v-for="(f, i) in features" :key="f.t" :style="{ '--i': i }">
              <span class="ic" :style="{ '--c': f.c }"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" v-html="f.i"></svg></span>
              <div><b>{{ $t(f.t) }}</b><span>{{ $t(f.d) }}</span></div>
            </li>
          </ul>

          <footer class="kbs-foot">
            <span>{{ $t('When it launches you will open it from this menu.') }}</span>
            <button type="button" class="kbs-ok" @click="$emit('close')">{{ $t('Got it') }}</button>
          </footer>
        </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
const FEATURES = [
  { c: '#6366f1', t: 'Every module explained', d: 'What it is for and who uses it, from Checkins to Salary.', i: '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2Z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7Z"/>' },
  { c: '#0ea5e9', t: 'Step-by-step with screenshots', d: 'Exact clicks for every function, in light and dark mode.', i: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>' },
  { c: '#f59e0b', t: 'Hidden functions revealed', d: 'The automatic work WIMS does quietly in the background.', i: '<path d="M13 2 3 14h9l-1 8 10-12h-9Z"/>' },
  { c: '#10b981', t: 'Instant search', d: 'Press / and find any guide, step or setting in a second.', i: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>' },
  { c: '#e11d48', t: 'Print any guide', d: 'A clean copy of a whole page, or just one function.', i: '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>' },
  { c: '#7c3aed', t: 'Open to everyone', d: 'Share the link with new staff. No login needed.', i: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>' },
];

export default {
  props: { launchAt: { type: String, default: '2026-10-20T12:00:00+03:00' } },
  emits: ['close'],
  data() {
    return { shown: false, now: Date.now(), timer: null, features: FEATURES, previous: null, fit: 1 };
  },
  computed: {
    left() { return Math.max(0, Date.parse(this.launchAt) - this.now); },
    done() { return this.left <= 0; },
    units() {
      const l = this.left;
      const pad = n => String(n).padStart(2, '0');
      return [
        { k: 'd', v: pad(Math.floor(l / 86400000)), l: 'Days' },
        { k: 'h', v: pad(Math.floor(l / 3600000) % 24), l: 'Hours' },
        { k: 'm', v: pad(Math.floor(l / 60000) % 60), l: 'Minutes' },
        { k: 's', v: pad(Math.floor(l / 1000) % 60), l: 'Seconds' },
      ];
    },
  },
  mounted() {
    this.previous = document.activeElement;
    this.shown = true;
    this.timer = setInterval(() => (this.now = Date.now()), 1000);
    window.addEventListener('keydown', this.onKey);
    window.addEventListener('resize', this.refit);
    document.documentElement.style.overflow = 'hidden';
    this.$nextTick(() => { this.refit(); this.$refs.close?.focus({ preventScroll: true }); });
  },
  beforeUnmount() {
    clearInterval(this.timer);
    window.removeEventListener('keydown', this.onKey);
    window.removeEventListener('resize', this.refit);
    document.documentElement.style.overflow = '';
    this.previous?.focus?.();
  },
  methods: {
    // The box never scrolls: if the screen is too small for it, the whole box scales down to fit (and small screens also drop the long text, see the CSS).
    refit() {
      const card = this.$refs.card;
      if (!card) return;
      const fit = Math.min(1, (window.innerWidth - 24) / card.offsetWidth, (window.innerHeight - 24) / card.offsetHeight);
      this.fit = Math.max(0.35, Math.round(fit * 1000) / 1000);
    },
    onKey(e) {
      if (e.key === 'Escape') { e.stopPropagation(); this.$emit('close'); }
      // keep Tab inside the box
      if (e.key === 'Tab') {
        const items = [...this.$refs.card.querySelectorAll('button')];
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    },
    spark(n) {
      const r = (n * 9301 + 49297) % 233280 / 233280;
      return { left: ((n * 37) % 100) + '%', top: ((n * 53) % 100) + '%', '--d': (r * 4).toFixed(2) + 's', '--s': (4 + (n % 5)) + 'px' };
    },
  },
};
</script>

<style scoped>
.kbs-backdrop {
  --card-bg: radial-gradient(700px 320px at 0% 0%, rgba(99, 102, 241, 0.16), transparent 65%), radial-gradient(520px 300px at 100% 100%, rgba(14, 165, 233, 0.14), transparent 65%), linear-gradient(160deg, #ffffff, #f4f7ff);
  --card-line: rgba(100, 116, 139, 0.25); --card-shadow: 0 50px 100px -40px rgba(30, 41, 99, 0.45);
  --tx: #0f172a; --tx2: #475569; --tx3: #64748b; --lead: #334155;
  --tile: rgba(255, 255, 255, 0.85); --tile-line: rgba(100, 116, 139, 0.2); --tile-hover: #fff; --track: rgba(100, 116, 139, 0.16);
  --digit: #1e1b4b; --digit-glow: rgba(99, 102, 241, 0.35); --sat-bg: #fff; --sat-line: rgba(100, 116, 139, 0.3);
  --close-bg: rgba(15, 23, 42, 0.06); --close-line: rgba(15, 23, 42, 0.12); --close-tx: #334155; --pill-tx: #92400e; --blob: 0.22; --sep: rgba(100, 116, 139, 0.2);
  --title-grad: linear-gradient(90deg, #1e1b4b, #4f46e5, #0ea5e9, #1e1b4b);
  position: fixed; inset: 0; z-index: 10000; display: grid; place-items: center; padding: 12px; overflow: hidden; background: rgba(15, 23, 42, 0.42); backdrop-filter: blur(6px); }
.kbs-fit { width: 100%; max-width: 760px; transform: scale(var(--fit, 1)); transform-origin: center center; }
.kbs-card { position: relative; width: 100%; overflow: hidden; padding: 1.7rem 1.8rem 1.4rem; border-radius: 30px; color: var(--tx); background: var(--card-bg); border: 1px solid var(--card-line); box-shadow: var(--card-shadow); isolation: isolate; }
.kbs-card::before { content: ''; position: absolute; inset: 0; z-index: -1; border-radius: inherit; padding: 1.6px; background: conic-gradient(from var(--kbs-a, 0deg), #22c55e, #f59e0b, #6366f1, #0ea5e9, #22c55e); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; opacity: 0.6; animation: kbs-spin 9s linear infinite; pointer-events: none; }
@property --kbs-a { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
@keyframes kbs-spin { to { --kbs-a: 360deg; } }

.kbs-blob { position: absolute; z-index: -1; border-radius: 50%; filter: blur(50px); opacity: var(--blob); pointer-events: none; animation: kbs-drift 12s ease-in-out infinite; }
.kbs-blob.b1 { width: 220px; height: 220px; left: -60px; top: -60px; background: #6366f1; }
.kbs-blob.b2 { width: 200px; height: 200px; right: -50px; top: 30%; background: #0ea5e9; animation-delay: -4s; }
.kbs-blob.b3 { width: 180px; height: 180px; left: 30%; bottom: -80px; background: #f59e0b; opacity: 0.14; animation-delay: -8s; }
@keyframes kbs-drift { 0%, 100% { transform: translate(0, 0) scale(1); } 50% { transform: translate(26px, 18px) scale(1.15); } }
.kbs-spark { position: absolute; z-index: -1; width: var(--s); height: var(--s); border-radius: 50%; background: #6366f1; opacity: 0; animation: kbs-twinkle 3.6s ease-in-out infinite; animation-delay: var(--d); }
@keyframes kbs-twinkle { 0%, 100% { opacity: 0; transform: scale(0.4); } 50% { opacity: 0.7; transform: scale(1); } }

.kbs-close { position: absolute; top: 14px; right: 14px; z-index: 3; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; color: var(--close-tx); background: var(--close-bg); border: 1px solid var(--close-line); cursor: pointer; transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1), background 0.2s, color 0.2s; }
.kbs-close svg { width: 18px; height: 18px; }
.kbs-close:hover { transform: rotate(90deg) scale(1.08); background: #e11d48; color: #fff; border-color: transparent; }
.kbs-close:focus-visible { outline: 2px solid #fbbf24; outline-offset: 3px; }

.kbs-head { display: flex; align-items: center; gap: 1.4rem; padding-right: 2.6rem; }
.kbs-orbit { position: relative; flex: none; width: 118px; height: 118px; }
.kbs-orbit .ring { position: absolute; inset: 0; border-radius: 50%; border: 1.5px dashed rgba(99, 102, 241, 0.45); animation: kbs-rot 22s linear infinite; }
.kbs-orbit .ring.r2 { inset: 14px; border-style: solid; border-color: rgba(14, 165, 233, 0.3); animation-direction: reverse; animation-duration: 16s; }
.kbs-orbit .core { position: absolute; inset: 31px; display: grid; place-items: center; border-radius: 50%; color: #fff; background: linear-gradient(135deg, #6366f1, #0ea5e9); box-shadow: 0 0 30px rgba(99, 102, 241, 0.7); animation: kbs-breathe 3s ease-in-out infinite; }
.kbs-orbit .core svg { width: 26px; height: 26px; }
.kbs-orbit .sat { position: absolute; left: 50%; top: 50%; width: 28px; height: 28px; margin: -14px; display: grid; place-items: center; border-radius: 9px; background: var(--sat-bg); border: 1px solid var(--sat-line); box-shadow: 0 6px 14px -8px rgba(30, 41, 99, 0.5); animation: kbs-sat 14s linear infinite; animation-delay: calc(var(--n) * -3.5s); }
.kbs-orbit .sat svg { width: 14px; height: 14px; }
.kbs-orbit .s1 { --n: 0; color: #38bdf8; } .kbs-orbit .s2 { --n: 1; color: #fbbf24; } .kbs-orbit .s3 { --n: 2; color: #34d399; } .kbs-orbit .s4 { --n: 3; color: #fb7185; }
@keyframes kbs-rot { to { transform: rotate(360deg); } }
@keyframes kbs-breathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
@keyframes kbs-sat { from { transform: rotate(0deg) translateX(59px) rotate(0deg); } to { transform: rotate(360deg) translateX(59px) rotate(-360deg); } }

.kbs-pill { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: var(--pill-tx); background: rgba(245, 158, 11, 0.16); border: 1px solid rgba(245, 158, 11, 0.5); }
.kbs-pill i { width: 8px; height: 8px; border-radius: 50%; background: #f59e0b; animation: kbs-ping 1.6s ease-out infinite; }
@keyframes kbs-ping { 0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); } 100% { box-shadow: 0 0 0 10px transparent; } }
.kbs-title h2 { margin: 0.5rem 0 0.2rem; font-size: clamp(1.5rem, 4vw, 2.1rem); font-weight: 800; line-height: 1.1; letter-spacing: -0.02em; background: var(--title-grad); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: kbs-shine 6s linear infinite; }
@keyframes kbs-shine { to { background-position: -250% 0; } }
.kbs-title p { color: var(--tx3); font-weight: 600; }

.kbs-count { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.7rem; margin-top: 1.4rem; }
.kbs-unit { padding: 0.8rem 0.4rem 0.65rem; text-align: center; border-radius: 18px; background: var(--tile); border: 1px solid var(--tile-line); overflow: hidden; box-shadow: 0 10px 22px -16px rgba(30, 41, 99, 0.4); }
.kbs-unit { position: relative; isolation: isolate; animation: kbs-glow 3.2s ease-in-out infinite; animation-delay: calc(var(--k, 0) * -0.8s); }
.kbs-unit:nth-child(1) { --k: 0; --g1: #6366f1; --g2: #38bdf8; } .kbs-unit:nth-child(2) { --k: 1; --g1: #0ea5e9; --g2: #34d399; } .kbs-unit:nth-child(3) { --k: 2; --g1: #f59e0b; --g2: #6366f1; } .kbs-unit:nth-child(4) { --k: 3; --g1: #22c55e; --g2: #f59e0b; }
.kbs-unit::before, .kbs-grid li::before { content: ''; position: absolute; inset: 0; z-index: -1; border-radius: inherit; padding: 1.6px; background: conic-gradient(from var(--kbs-a, 0deg), var(--g1, #6366f1), transparent 30%, var(--g2, #0ea5e9) 55%, transparent 80%, var(--g1, #6366f1)); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; animation: kbs-spin 5s linear infinite; animation-delay: calc(var(--k, 0) * -1.2s); pointer-events: none; }
@keyframes kbs-glow { 0%, 100% { box-shadow: 0 0 6px -1px color-mix(in srgb, var(--g1, #6366f1) 45%, transparent), 0 10px 22px -16px rgba(30, 41, 99, 0.4); } 50% { box-shadow: 0 0 22px 1px color-mix(in srgb, var(--g2, #0ea5e9) 55%, transparent), 0 10px 22px -16px rgba(30, 41, 99, 0.4); } }
.kbs-grid li { position: relative; isolation: isolate; }
.kbs-grid li::before { opacity: 0.75; animation-duration: 7s; }
.kbs-grid li:nth-child(6n+1) { --g1: #6366f1; --g2: #38bdf8; --k: 0; } .kbs-grid li:nth-child(6n+2) { --g1: #0ea5e9; --g2: #8b5cf6; --k: 1; } .kbs-grid li:nth-child(6n+3) { --g1: #f59e0b; --g2: #ef4444; --k: 2; }
.kbs-grid li:nth-child(6n+4) { --g1: #10b981; --g2: #0ea5e9; --k: 3; } .kbs-grid li:nth-child(6n+5) { --g1: #e11d48; --g2: #f59e0b; --k: 4; } .kbs-grid li:nth-child(6n) { --g1: #7c3aed; --g2: #6366f1; --k: 5; }
.kbs-unit b { display: block; font-size: clamp(1.8rem, 6vw, 2.8rem); font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; color: var(--digit); text-shadow: 0 0 24px var(--digit-glow); }
.kbs-unit b.flip { animation: kbs-flip 0.45s cubic-bezier(0.22, 1, 0.36, 1); }
@keyframes kbs-flip { from { transform: translateY(-60%) rotateX(80deg); opacity: 0; } to { transform: none; opacity: 1; } }
.kbs-unit span { display: block; margin-top: 0.45rem; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; color: var(--tx3); }
.kbs-done { grid-column: 1 / -1; text-align: center; font-weight: 800; color: #059669; }

.kbs-bar { position: relative; height: 8px; margin: 1.1rem 0 0.2rem; border-radius: 999px; background: var(--track); overflow: hidden; }
.kbs-bar span { position: absolute; inset: 0 auto 0 0; width: 62%; border-radius: inherit; background: repeating-linear-gradient(135deg, #6366f1 0 14px, #0ea5e9 14px 28px); background-size: 40px 40px; animation: kbs-stripes 1.2s linear infinite; box-shadow: 0 0 18px rgba(99, 102, 241, 0.6); }
@keyframes kbs-stripes { to { background-position: 40px 0; } }
.kbs-lead { margin: 0.9rem 0 1rem; color: var(--lead); font-size: 0.97rem; line-height: 1.6; }

.kbs-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; margin: 0; padding: 0; list-style: none; }
.kbs-grid li { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.7rem 0.8rem; border-radius: 16px; background: var(--tile); border: 1px solid var(--tile-line); opacity: 0; animation: kbs-rise 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards; animation-delay: calc(0.25s + var(--i) * 70ms); transition: transform 0.3s, border-color 0.3s, background 0.3s; }
.kbs-grid li:hover { transform: translateY(-3px); background: var(--tile-hover); box-shadow: 0 14px 24px -16px rgba(30, 41, 99, 0.45); border-color: color-mix(in srgb, var(--c, #6366f1) 60%, transparent); }
.kbs-grid .ic { display: grid; place-items: center; flex: none; width: 36px; height: 36px; border-radius: 11px; color: var(--c); background: color-mix(in srgb, var(--c) 18%, transparent); }
.kbs-grid .ic svg { width: 19px; height: 19px; }
.kbs-grid b { display: block; font-size: 0.9rem; color: var(--tx); line-height: 1.25; } .kbs-grid li > div > span { display: block; margin-top: 0.1rem; font-size: 0.78rem; color: var(--tx3); line-height: 1.4; }
@keyframes kbs-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

.kbs-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.8rem; margin-top: 1.1rem; padding-top: 0.9rem; border-top: 1px solid var(--sep); font-size: 0.8rem; color: var(--tx3); }
.kbs-ok { padding: 0.55rem 1.3rem; border-radius: 12px; font-weight: 800; font-size: 0.88rem; color: #fff; background: linear-gradient(135deg, #4f46e5, #0ea5e9); border: 0; cursor: pointer; box-shadow: 0 10px 20px -10px #4f46e5; transition: transform 0.2s, box-shadow 0.2s; }
.kbs-ok:hover { transform: translateY(-2px); box-shadow: 0 16px 26px -12px #4f46e5; }

.kbs-enter-active { transition: opacity 0.3s ease; } .kbs-enter-active .kbs-card { animation: kbs-pop 0.55s cubic-bezier(0.22, 1, 0.36, 1); }
.kbs-leave-active { transition: opacity 0.2s ease; } .kbs-leave-active .kbs-card { transition: transform 0.2s, opacity 0.2s; transform: scale(0.96) translateY(8px); opacity: 0; }
.kbs-enter-from, .kbs-leave-to { opacity: 0; }
@keyframes kbs-pop { from { opacity: 0; transform: translateY(30px) scale(0.92) rotateX(8deg); } to { opacity: 1; transform: none; } }

@media (max-height: 760px) {
  .kbs-card { padding: 1.2rem 1.4rem 1rem; } .kbs-lead { display: none; } .kbs-grid li > div > span { display: none; } .kbs-grid li { padding: 0.5rem 0.7rem; } .kbs-count { margin-top: 1rem; }
}
@media (max-height: 560px) {
  .kbs-grid, .kbs-bar { display: none; } .kbs-orbit { width: 84px; height: 84px; transform: scale(0.72); transform-origin: left center; } .kbs-title p { display: none; }
}
@media (max-width: 640px) {
  .kbs-card { padding: 1.2rem 1rem 1rem; border-radius: 24px; } .kbs-grid li > div > span { display: none; } .kbs-lead { font-size: 0.88rem; }
  .kbs-head { flex-direction: column; text-align: center; padding-right: 0; gap: 0.8rem; }
  .kbs-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.45rem; } .kbs-grid li { padding: 0.5rem 0.55rem; gap: 0.5rem; } .kbs-grid .ic { width: 30px; height: 30px; } .kbs-grid b { font-size: 0.78rem; } .kbs-count { gap: 0.4rem; margin-top: 1rem; } .kbs-foot > span { display: none; } .kbs-foot { justify-content: flex-end; }
}
@media (prefers-reduced-motion: reduce) {
  .kbs-card *, .kbs-card::before { animation: none !important; transition: none !important; } .kbs-grid li { opacity: 1; }
}

/* WIMS dark mode: the same box in its night colours */
:global(html.dark) .kbs-backdrop {
  --card-bg: radial-gradient(700px 320px at 0% 0%, rgba(99, 102, 241, 0.42), transparent 65%), radial-gradient(520px 300px at 100% 100%, rgba(14, 165, 233, 0.3), transparent 65%), linear-gradient(160deg, #0b1226, #121c3d);
  --card-line: rgba(148, 163, 184, 0.22); --card-shadow: 0 50px 100px -40px rgba(0, 0, 0, 0.9);
  --tx: #e2e8f0; --tx2: #cbd5e1; --tx3: #94a3b8; --lead: #cbd5e1;
  --tile: rgba(255, 255, 255, 0.06); --tile-line: rgba(255, 255, 255, 0.12); --tile-hover: rgba(255, 255, 255, 0.1); --track: rgba(255, 255, 255, 0.08);
  --digit: #fff; --digit-glow: rgba(99, 102, 241, 0.7); --sat-bg: rgba(15, 23, 42, 0.8); --sat-line: rgba(255, 255, 255, 0.25);
  --close-bg: rgba(255, 255, 255, 0.08); --close-line: rgba(255, 255, 255, 0.18); --close-tx: #e2e8f0; --pill-tx: #fde68a; --blob: 0.35; --sep: rgba(255, 255, 255, 0.1);
  --title-grad: linear-gradient(90deg, #fff, #a5b4fc, #38bdf8, #fff); background: rgba(6, 10, 24, 0.66);
}
:global(html.dark) .kbs-spark { background: #fff; }
:global(html.dark) .kbs-grid .ic { background: color-mix(in srgb, var(--c) 18%, transparent); }
</style>
