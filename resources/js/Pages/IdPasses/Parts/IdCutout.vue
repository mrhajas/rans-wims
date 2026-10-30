<template>
  <div class="idc" :style="{ '--idc-w': size + 'px', '--idc-c': color || '#1565c0' }">
    <div class="idc-stage lg-tip" :data-tip="side === 'front' ? $t('Click the card to see the back') : $t('Click the card to see the front')" ref="stage" @pointermove="move" @pointerleave="leave" @click="flip" role="button" tabindex="0" :aria-label="$t('Turn the card over')" @keydown.enter.prevent="flip" @keydown.space.prevent="flip">
      <span class="idc-shadow"></span>
      <div class="idc-tilt" :style="tilt">
        <div class="idc-flip" :class="{ 'is-back': side === 'back' }">
          <div class="idc-side idc-front"><card-face v-if="front" :html="front" :css="css" /><div v-else class="idc-skel"><i></i></div></div>
          <div class="idc-side idc-rear"><card-face v-if="back" :html="back" :css="css" /><div v-else class="idc-skel"><i></i></div></div>
        </div>
        <span class="idc-glare" :style="glare"></span>
      </div>
    </div>

    <div class="idc-btns" role="group" :aria-label="$t('Card actions')">
      <button type="button" class="lg-btn tone lg-tip" :data-tip="$t('Show the front of the card')" :class="{ 'tone-solid': side === 'front' }" style="--tone: #2563eb" :aria-pressed="side === 'front'" @click="side = 'front'"><span class="tone-ico"><icons name="user" class="w-4 h-4" /></span>{{ $t('Front') }}</button>
      <button type="button" class="lg-btn tone lg-tip" :data-tip="$t('Show the back: QR code and terms')" :class="{ 'tone-solid': side === 'back' }" style="--tone: #d97706" :aria-pressed="side === 'back'" @click="side = 'back'"><span class="tone-ico"><icons name="qr-code" class="w-4 h-4" /></span>{{ $t('Back') }}</button>
      <button v-if="canPrint" type="button" class="lg-btn tone tone-solid lg-tip" style="--tone: #4f46e5" :disabled="blocked" :data-tip="blocked ? blockedText : $t('Print this card (55 x 85 mm)')" @click="$emit('print')"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span>{{ $t('Print') }}</button>
      <button v-if="canDownload" type="button" class="lg-btn tone tone-solid lg-tip" style="--tone: #0d9488" :disabled="blocked" :data-tip="blocked ? blockedText : $t('Download the card as a PDF')" @click="$emit('download')"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('Download') }}</button>
    </div>
  </div>
</template>

<script>
import CardFace from './CardFace.vue';

// An ID card as a cut-out (no frame around it) that floats, tilts towards the pointer and turns over, with Front / Back / Print / Download buttons under it.
export default {
  components: { CardFace },
  props: { front: String, back: String, css: String, color: String, size: { type: Number, default: 280 }, canPrint: Boolean, canDownload: Boolean, blocked: Boolean, blockedText: { type: String, default: '' }, startSide: { type: String, default: 'front' } },
  emits: ['print', 'download'],
  data() { return { side: this.startSide, tilt: {}, glare: {} }; },
  methods: {
    flip() { this.side = this.side === 'front' ? 'back' : 'front'; },
    move(e) {
      if (e.pointerType === 'touch') return;
      const r = this.$refs.stage.getBoundingClientRect(); const x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
      this.tilt = { '--rx': ((0.5 - y) * 12).toFixed(2) + 'deg', '--ry': ((x - 0.5) * 14).toFixed(2) + 'deg', '--lift': '-6px' };
      this.glare = { '--gx': (x * 100).toFixed(0) + '%', '--gy': (y * 100).toFixed(0) + '%', opacity: 1 };
    },
    leave() { this.tilt = {}; this.glare = {}; },
  },
};
</script>

<style scoped>
.idc { display: grid; justify-items: center; gap: 22px; }
.idc-stage { position: relative; width: var(--idc-w); max-width: 100%; aspect-ratio: 55 / 85; cursor: pointer; perspective: 1100px; outline: none; animation: idc-in 0.7s cubic-bezier(0.2, 0.9, 0.25, 1.15) both; -webkit-tap-highlight-color: transparent; }
.idc-stage:focus-visible { outline: 3px solid color-mix(in srgb, var(--idc-c) 55%, transparent); outline-offset: 8px; border-radius: 20px; }
@keyframes idc-in { from { opacity: 0; transform: translateY(26px) rotate(-3deg) scale(0.92); } to { opacity: 1; transform: none; } }
.idc-shadow { position: absolute; left: 12%; right: 12%; bottom: -16px; height: 22px; border-radius: 50%; background: radial-gradient(closest-side, color-mix(in srgb, var(--idc-c) 45%, rgba(8, 20, 40, 0.55)), transparent); filter: blur(6px); opacity: 0.55; animation: idc-shadow 4.6s ease-in-out infinite; }
@keyframes idc-shadow { 50% { transform: scale(0.88); opacity: 0.4; } }
.idc-tilt { position: absolute; inset: 0; transform-style: preserve-3d; transform: translateY(var(--lift, 0)) rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg)); transition: transform 0.18s ease-out; animation: idc-float 4.6s ease-in-out infinite; }
.idc-stage:hover .idc-tilt { animation: none; }
@keyframes idc-float { 50% { translate: 0 -6px; } }
.idc-flip { position: absolute; inset: 0; transform-style: preserve-3d; transition: transform 0.75s cubic-bezier(0.25, 0.8, 0.25, 1.05); }
.idc-flip.is-back { transform: rotateY(180deg); }
.idc-side { position: absolute; inset: 0; backface-visibility: hidden; -webkit-backface-visibility: hidden; }
.idc-rear { transform: rotateY(180deg); }
.idc-side :deep(.idp-face) { box-shadow: 0 30px 40px -24px rgba(8, 20, 40, 0.75), 0 0 0 1px rgba(8, 20, 40, 0.06); border-radius: 5%; }
.idc-glare { position: absolute; inset: 0; border-radius: 5%; pointer-events: none; opacity: 0; transition: opacity 0.25s; background: radial-gradient(circle at var(--gx, 50%) var(--gy, 50%), rgba(255, 255, 255, 0.28), transparent 55%); transform: translateZ(1px); }
.idc-skel { position: absolute; inset: 0; border-radius: 5%; overflow: hidden; background: linear-gradient(160deg, color-mix(in srgb, var(--idc-c) 22%, #e8eef6), #dfe7f2); box-shadow: 0 30px 40px -24px rgba(8, 20, 40, 0.5); }
.idc-skel i { position: absolute; inset: 0; background: linear-gradient(105deg, transparent 30%, rgba(255, 255, 255, 0.7) 50%, transparent 70%); transform: translateX(-100%); animation: idc-sheen 1.3s ease-in-out infinite; }
@keyframes idc-sheen { to { transform: translateX(100%); } }

.idc-stage.lg-tip::after { right: auto; left: 50%; bottom: calc(100% + 10px); transform: translate(-50%, 4px); }
.idc-stage.lg-tip:hover::after, .idc-stage.lg-tip:focus-visible::after { transform: translate(-50%, 0); transition-delay: 0.35s; }
.idc-btns .lg-tip::after { right: auto; left: 50%; transform: translate(-50%, 4px); }
.idc-btns .lg-tip:hover::after, .idc-btns .lg-tip:focus-visible::after { transform: translate(-50%, 0); }
/* hover: the card lifts, its shadow spreads, a ring in the colour of the ID glows and a band of light crosses it */
.idc-stage:hover .idc-shadow { opacity: 0.75; transform: scale(1.12); animation: none; }
.idc-shadow { transition: opacity 0.3s, transform 0.3s; }
.idc-side :deep(.idp-face) { transition: box-shadow 0.3s; }
.idc-stage:hover .idc-side :deep(.idp-face) { box-shadow: 0 34px 44px -22px rgba(8, 20, 40, 0.8), 0 0 0 3px color-mix(in srgb, var(--idc-c) 55%, transparent), 0 0 34px -4px color-mix(in srgb, var(--idc-c) 60%, transparent); }
.idc-glare::after { content: ''; position: absolute; inset: 0; background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, 0.55) 50%, transparent 65%); transform: translateX(-120%); }
.idc-stage:hover .idc-glare::after { transform: translateX(120%); transition: transform 0.9s ease; }
.idc-btns .lg-btn { transition: transform 0.15s, box-shadow 0.2s; } .idc-btns .lg-btn:not(:disabled):hover { transform: scale(1.05); } .idc-btns .lg-btn:not(:disabled):active { transform: translateY(0) scale(0.97); }
@media (hover: none) { .idc-stage.lg-tip::after { display: none; } }
.idc-btns { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; max-width: 100%; }
@media (max-width: 420px) { .idc-btns { display: grid; grid-template-columns: 1fr 1fr; width: 100%; max-width: 320px; } .idc-btns .lg-btn { justify-content: center; } }
@media (prefers-reduced-motion: reduce) { .idc-stage, .idc-tilt, .idc-shadow, .idc-skel i { animation: none !important; } .idc-flip, .idc-glare::after, .idc-btns .lg-btn { transition: none !important; } }
</style>
