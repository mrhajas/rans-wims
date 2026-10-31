<template>
  <span class="al" :class="{ 'is-mark': mark }" :style="{ '--al-c': color, '--al-g': glint }" role="img" :aria-label="label">
    <span class="al-stage" :style="{ aspectRatio: mark ? '280 / 200' : '765 / 263' }">
      <i v-if="!mark" class="al-lt"></i>
      <b v-for="s in slices" :key="s.k" class="al-sl" :class="s.cls" :style="s.style"><i class="al-in" :style="s.inner"></i></b>
    </span>
  </span>
</template>

<script>
// The RANS logo in one colour (white on the menu), the temple cut into a roof, seven columns and a base that hop in turn every few seconds, with a glint across the whole logo.
// `mark`: only the temple (the house), for the collapsed menu. Pure CSS; "reduce motion" shows it still.
const X0 = 174; // where the temple starts on the logo picture (765 x 263 units)
const SLICES = [{ cls: 'roof', x: 174, y: 0, w: 280, h: 77 }, ...[174, 230, 264, 296, 329, 362, 396].map((x, i, a) => ({ cls: 'col', i, x, y: 77, w: (a[i + 1] || 454) - x, h: 90 })), { cls: 'base', x: 174, y: 167, w: 280, h: 33 }];
export default {
  props: { mark: Boolean, color: { type: String, default: '#ffffff' }, glint: { type: String, default: 'rgba(120, 170, 255, 0.95)' }, label: { type: String, default: 'RANS Building & Civil Contractor' } },
  computed: {
    slices() {
      const base = this.mark ? 280 : 765, ox = this.mark ? X0 : 0, oy = 0;
      const u = v => (v / base) * 100 + 'cqw';
      return SLICES.map((s, k) => ({ k, cls: s.cls, style: { left: u(s.x - ox), top: u(s.y - oy), width: u(s.w), height: u(s.h), '--i': s.i || 0 }, inner: { left: '-' + u(s.x), top: '-' + u(s.y), width: u(765), height: u(263) } }));
    },
  },
};
</script>

<style scoped>
.al { display: block; width: 100%; container-type: inline-size; }
.al-stage { position: relative; display: block; width: 100%; }
.al-lt, .al-in { position: absolute; left: 0; top: 0; background: linear-gradient(105deg, transparent 42%, var(--al-g) 50%, transparent 58%) no-repeat, var(--al-c); background-size: 320% 100%, 100% 100%; background-position: 85% 0, 0 0; animation: al-glint 7s ease-in-out infinite; }
.al-lt { width: 100cqw; height: calc(100cqw * 263 / 765); -webkit-mask: url('/images/rans-mark-letters.png') center / 100% 100% no-repeat; mask: url('/images/rans-mark-letters.png') center / 100% 100% no-repeat; animation-delay: 0.35s; }
.al-in { -webkit-mask: url('/images/rans-mark-temple.png') 0 0 / 100% 100% no-repeat; mask: url('/images/rans-mark-temple.png') 0 0 / 100% 100% no-repeat; }
.al-sl { position: absolute; display: block; overflow: hidden; }
@keyframes al-glint { 0%, 8% { background-position: 85% 0, 0 0; } 30%, 100% { background-position: 8% 0, 0 0; } }
.col { animation: al-hop 7s cubic-bezier(0.3, 1.4, 0.5, 1) infinite; animation-delay: calc(var(--i) * 90ms); }
.roof { animation: al-lift 7s cubic-bezier(0.3, 1.3, 0.5, 1) infinite; animation-delay: 0.75s; }
@keyframes al-hop { 0%, 14%, 100% { transform: translateY(0); } 4% { transform: translateY(-3.4cqw); } 9% { transform: translateY(0.5cqw); } }
@keyframes al-lift { 0%, 16%, 100% { transform: translateY(0) rotate(0); } 4% { transform: translateY(-5.5cqw) rotate(-1.2deg); } 9% { transform: translateY(0.7cqw) rotate(0.5deg); } 12% { transform: translateY(-0.8cqw); } }
.is-mark .col { animation-name: al-hop-m; } .is-mark .roof { animation-name: al-lift-m; }
@keyframes al-hop-m { 0%, 14%, 100% { transform: translateY(0); } 4% { transform: translateY(-5cqw); } 9% { transform: translateY(0.8cqw); } }
@keyframes al-lift-m { 0%, 16%, 100% { transform: translateY(0) rotate(0); } 4% { transform: translateY(-8cqw) rotate(-1.4deg); } 9% { transform: translateY(1cqw) rotate(0.6deg); } 12% { transform: translateY(-1.2cqw); } }
@media (prefers-reduced-motion: reduce) { .al *, .al { animation: none !important; } }
</style>
