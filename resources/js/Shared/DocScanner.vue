<template>
  <teleport to="body">
    <transition name="dsc" appear>
      <div class="dsc-overlay" tabindex="-1" @keydown.esc="close">
        <div class="dsc-card" role="dialog" aria-modal="true" :aria-label="$t('Scan a document')">
          <header class="dsc-head">
            <span class="dsc-badge"><icons name="camera" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3>{{ $t('Scan a document') }}</h3><p>{{ hint }}</p></div>
            <button type="button" class="dsc-x" :aria-label="$t('Close')" @click="close"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="dsc-stage" ref="stage">
            <!-- 1. camera -->
            <template v-if="phase === 'camera'">
              <video ref="video" class="dsc-video" autoplay muted playsinline></video>
              <svg v-if="live" class="dsc-live" viewBox="0 0 100 100" preserveAspectRatio="none"><polygon :points="polyPoints(live)" /></svg>
              <div v-if="!ready" class="dsc-cover"><span class="dsc-spin"></span>{{ $t('Opening the camera...') }}</div>
              <div class="dsc-frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
            </template>

            <!-- 2. adjust the four corners -->
            <div v-else-if="phase === 'adjust'" class="dsc-adjust" ref="adjust">
              <canvas ref="shotView" class="dsc-shot"></canvas>
              <svg class="dsc-quad" viewBox="0 0 100 100" preserveAspectRatio="none"><polygon :points="polyPoints(quad)" /></svg>
              <button v-for="(p, i) in quad" :key="i" type="button" class="dsc-handle" :style="{ left: p.x * 100 + '%', top: p.y * 100 + '%' }" :aria-label="$t('Move this corner')" @pointerdown.prevent="startDrag(i, $event)"></button>
            </div>

            <!-- 3. the result -->
            <div v-else-if="phase === 'result'" class="dsc-result">
              <canvas ref="resultView" class="dsc-out"></canvas>
              <div v-if="working" class="dsc-cover"><span class="dsc-spin"></span>{{ $t('Cleaning the picture...') }}</div>
            </div>

            <div v-else-if="phase === 'error'" class="dsc-cover dsc-cover--bad"><icons name="exclamation" class="w-8 h-8" /><b>{{ error }}</b></div>
          </div>

          <div v-if="phase === 'result'" class="dsc-modes" role="tablist">
            <button v-for="m in modes" :key="m.k" type="button" role="tab" :aria-selected="mode === m.k" :class="{ on: mode === m.k }" @click="setMode(m.k)">{{ m.label }}</button>
          </div>

          <footer class="dsc-foot">
            <template v-if="phase === 'camera'">
              <label class="dsc-btn dsc-btn--ghost"><icons name="image" class="w-4 h-4" />{{ $t('Choose a photo') }}<input type="file" accept="image/*" class="sr-only" @change="pickFile" /></label>
              <button type="button" class="dsc-shutter" :disabled="!ready" :aria-label="$t('Take the photo')" @click="capture"><span></span></button>
              <button type="button" class="dsc-btn dsc-btn--ghost" @click="close">{{ $t('Cancel') }}</button>
            </template>
            <template v-else-if="phase === 'adjust'">
              <button type="button" class="dsc-btn dsc-btn--ghost" @click="retake"><icons name="refresh" class="w-4 h-4" />{{ $t('Retake') }}</button>
              <button type="button" class="dsc-btn dsc-btn--ghost" @click="resetQuad">{{ $t('Reset corners') }}</button>
              <button type="button" class="dsc-btn dsc-btn--go" @click="scan"><icons name="tick" class="w-4 h-4" />{{ $t('Scan it') }}</button>
            </template>
            <template v-else-if="phase === 'result'">
              <button type="button" class="dsc-btn dsc-btn--ghost" @click="phase = 'adjust'; $nextTick(drawShot)"><icons name="chevron-down" class="w-4 h-4 rotate-90" />{{ $t('Adjust corners') }}</button>
              <button type="button" class="dsc-btn dsc-btn--go" :disabled="working" @click="use"><icons name="tick" class="w-4 h-4" />{{ $t('Use this scan') }}</button>
            </template>
            <template v-else>
              <label class="dsc-btn dsc-btn--ghost"><icons name="image" class="w-4 h-4" />{{ $t('Choose a photo') }}<input type="file" accept="image/*" capture="environment" class="sr-only" @change="pickFile" /></label>
              <button type="button" class="dsc-btn dsc-btn--ghost" @click="close">{{ $t('Close') }}</button>
            </template>
          </footer>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
import { detectQuad, defaultQuad, warpPerspective, outputSize, cleanPage, shrink } from '@/Core/docScan';

const MAX_SHOT = 2200;

export default {
  emits: ['close', 'done'],
  data() {
    return { phase: 'camera', ready: false, error: '', live: null, quad: defaultQuad(), mode: 'color', working: false, stream: null, timer: null, shot: null, wrapped: null, cleaned: {} };
  },
  computed: {
    hint() {
      return {
        camera: this.$t('Put the page flat, fill the frame and take the photo.'),
        adjust: this.$t('Drag the four dots onto the corners of the page.'),
        result: this.$t('Your page is cut out and cleaned. Pick the look you like.'),
        error: this.$t('The camera is not available.'),
      }[this.phase];
    },
    modes() { return [{ k: 'color', label: this.$t('Clean colour') }, { k: 'bw', label: this.$t('Black and white') }, { k: 'plain', label: this.$t('Cut out only') }]; },
  },
  mounted() { this.startCamera(); },
  beforeUnmount() { this.stopCamera(); },
  methods: {
    polyPoints(q) { return q.map(p => (p.x * 100).toFixed(2) + ',' + (p.y * 100).toFixed(2)).join(' '); },
    close() { this.stopCamera(); this.$emit('close'); },

    // ---- camera
    async startCamera() {
      this.phase = 'camera'; this.ready = false; this.live = null;
      if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { this.fail(this.$t('The camera needs a secure (https) page. You can still choose a photo.')); return; }
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1440 } } });
        await this.$nextTick();
        const v = this.$refs.video;
        if (!v) { this.stopCamera(); return; }
        v.srcObject = this.stream; await v.play();
        this.ready = true;
        this.timer = setInterval(this.detectLive, 350);
      } catch (e) {
        this.fail(e && e.name === 'NotAllowedError' ? this.$t('Camera access was blocked. Allow the camera for this site, or choose a photo.') : this.$t('No camera could be opened. You can still choose a photo.'));
      }
    },
    stopCamera() { clearInterval(this.timer); this.timer = null; if (this.stream) this.stream.getTracks().forEach(t => t.stop()); this.stream = null; },
    fail(msg) { this.stopCamera(); this.error = msg; this.phase = 'error'; },
    // a light outline of the page that follows it on the live picture (a small copy keeps this fast)
    detectLive() {
      const v = this.$refs.video;
      if (!v || v.readyState < 2 || !v.videoWidth) return;
      const w = 240, h = Math.round((240 * v.videoHeight) / v.videoWidth), c = (this._lc = this._lc || document.createElement('canvas'));
      c.width = w; c.height = h;
      const g = c.getContext('2d', { willReadFrequently: true });
      g.drawImage(v, 0, 0, w, h);
      const r = detectQuad(g.getImageData(0, 0, w, h));
      this.live = r.found ? r.quad : null;
    },
    capture() {
      const v = this.$refs.video;
      if (!v || !v.videoWidth) return;
      const k = Math.min(1, MAX_SHOT / Math.max(v.videoWidth, v.videoHeight));
      const c = document.createElement('canvas');
      c.width = Math.round(v.videoWidth * k); c.height = Math.round(v.videoHeight * k);
      c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
      this.stopCamera();
      this.useCanvas(c);
    },
    async pickFile(e) {
      const f = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!f) return;
      try {
        const bmp = await createImageBitmap(f, { imageOrientation: 'from-image' });
        const k = Math.min(1, MAX_SHOT / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas');
        c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        this.stopCamera();
        this.useCanvas(c);
      } catch (err) { this.fail(this.$t('That file could not be read as a picture.')); }
    },
    useCanvas(c) {
      this.shot = c; this.cleaned = {}; this.wrapped = null;
      const g = c.getContext('2d', { willReadFrequently: true });
      const small = shrink(g.getImageData(0, 0, c.width, c.height), 420);
      this.quad = detectQuad(small).quad;
      this.phase = 'adjust';
      this.$nextTick(this.drawShot);
    },
    retake() { this.shot = null; this.startCamera(); },
    resetQuad() { const g = this.shot.getContext('2d', { willReadFrequently: true }); this.quad = detectQuad(shrink(g.getImageData(0, 0, this.shot.width, this.shot.height), 420)).quad; },

    // ---- corners
    drawShot() {
      const c = this.$refs.shotView;
      if (!c || !this.shot) return;
      c.width = this.shot.width; c.height = this.shot.height;
      c.getContext('2d').drawImage(this.shot, 0, 0);
      c.style.aspectRatio = this.shot.width + ' / ' + this.shot.height;
    },
    startDrag(i, e) {
      const box = this.$refs.adjust.getBoundingClientRect();
      const move = ev => {
        const x = Math.min(1, Math.max(0, (ev.clientX - box.left) / box.width)), y = Math.min(1, Math.max(0, (ev.clientY - box.top) / box.height));
        this.quad.splice(i, 1, { x, y });
      };
      const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
      window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    },

    // ---- result
    async scan() {
      this.phase = 'result'; this.working = true; this.mode = 'color';
      await this.$nextTick();
      await new Promise(r => setTimeout(r, 30)); // let the "cleaning" notice paint first
      const g = this.shot.getContext('2d', { willReadFrequently: true });
      const img = g.getImageData(0, 0, this.shot.width, this.shot.height);
      const px = this.quad.map(p => ({ x: p.x * img.width, y: p.y * img.height }));
      const size = outputSize(px);
      this.wrapped = warpPerspective(img, this.quad, size.width, size.height);
      this.cleaned = {};
      this.working = false;
      this.render();
    },
    setMode(m) { this.mode = m; this.working = true; setTimeout(() => { this.render(); this.working = false; }, 20); },
    imageFor(mode) {
      if (mode === 'plain') return this.wrapped;
      return this.cleaned[mode] || (this.cleaned[mode] = cleanPage(this.wrapped, mode));
    },
    render() {
      const c = this.$refs.resultView;
      if (!c || !this.wrapped) return;
      const img = this.imageFor(this.mode);
      c.width = img.width; c.height = img.height;
      c.getContext('2d').putImageData(new ImageData(img.data, img.width, img.height), 0, 0);
    },
    use() {
      const c = this.$refs.resultView;
      if (!c) return;
      c.toBlob(b => {
        if (!b) return;
        const name = 'scan-' + new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '') + '.jpg';
        this.stopCamera();
        this.$emit('done', new File([b], name, { type: 'image/jpeg' }));
      }, 'image/jpeg', 0.9);
    },
  },
};
</script>

<style>
.dsc-overlay { position: fixed; inset: 0; z-index: 999990; display: flex; align-items: center; justify-content: center; padding: 14px; background: rgba(8, 15, 32, 0.7); backdrop-filter: blur(6px); outline: none; }
.dsc-card { display: grid; grid-template-rows: auto minmax(0, 1fr) auto auto; width: 100%; max-width: 640px; max-height: calc(100vh - 28px); overflow: hidden; border-radius: 26px; color: #0f172a; background: #fff; box-shadow: 0 40px 80px -30px rgba(2, 8, 23, 0.8); }
.dsc-head { display: flex; align-items: center; gap: 0.8rem; padding: 0.9rem 1.1rem; color: #fff; background: linear-gradient(135deg, #0f766e, #0f2540); position: relative; }
.dsc-head h3 { margin: 0; font-size: 1.02rem; font-weight: 800; } .dsc-head p { margin: 2px 0 0; font-size: 0.78rem; opacity: 0.85; }
.dsc-badge { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: 13px; background: rgba(255, 255, 255, 0.18); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35); }
.dsc-x { margin-left: auto; display: grid; place-items: center; width: 32px; height: 32px; min-height: 0 !important; border: 0; border-radius: 99px; color: #fff; background: rgba(255, 255, 255, 0.18); cursor: pointer; } .dsc-x:hover { background: rgba(255, 255, 255, 0.32); }
.dsc-stage { position: relative; min-height: 260px; background: #0b1220; overflow: hidden; display: grid; place-items: center; }
.dsc-video { width: 100%; height: 100%; max-height: 62vh; object-fit: contain; background: #000; }
.dsc-live, .dsc-quad { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; } .dsc-live polygon, .dsc-quad polygon { fill: rgba(45, 212, 191, 0.16); stroke: #2dd4bf; stroke-width: 0.6; vector-effect: non-scaling-stroke; stroke-linejoin: round; filter: drop-shadow(0 0 4px rgba(45, 212, 191, 0.8)); }
.dsc-quad polygon { stroke: #facc15; fill: rgba(250, 204, 21, 0.14); stroke-width: 2; filter: drop-shadow(0 0 4px rgba(250, 204, 21, 0.7)); }
.dsc-frame { position: absolute; inset: 7%; pointer-events: none; } .dsc-frame i { position: absolute; width: 26px; height: 26px; border: 0 solid rgba(255, 255, 255, 0.9); } .dsc-frame i:nth-child(1) { top: 0; left: 0; border-top-width: 3px; border-left-width: 3px; border-top-left-radius: 8px; } .dsc-frame i:nth-child(2) { top: 0; right: 0; border-top-width: 3px; border-right-width: 3px; border-top-right-radius: 8px; } .dsc-frame i:nth-child(3) { bottom: 0; left: 0; border-bottom-width: 3px; border-left-width: 3px; border-bottom-left-radius: 8px; } .dsc-frame i:nth-child(4) { bottom: 0; right: 0; border-bottom-width: 3px; border-right-width: 3px; border-bottom-right-radius: 8px; }
.dsc-adjust { position: relative; margin: 18px; max-width: calc(100% - 36px); max-height: calc(62vh - 36px); display: inline-block; touch-action: none; } .dsc-shot { display: block; max-width: 100%; max-height: calc(62vh - 36px); width: auto; height: auto; }
.dsc-handle { position: absolute; width: 30px; height: 30px; min-height: 0 !important; margin: -15px 0 0 -15px; padding: 0; border: 3px solid #fff; border-radius: 50%; background: #facc15; box-shadow: 0 6px 14px rgba(0, 0, 0, 0.5); cursor: grab; touch-action: none; transition: transform 0.12s ease; } .dsc-handle:hover { transform: scale(1.15); }
.dsc-result { position: relative; display: grid; place-items: center; width: 100%; padding: 10px; background: #e2e8f0; } .dsc-out { display: block; max-width: 100%; max-height: 58vh; width: auto; height: auto; background: #fff; box-shadow: 0 14px 30px -14px rgba(0, 0, 0, 0.6); }
.dsc-cover { position: absolute; inset: 0; display: grid; place-content: center; justify-items: center; gap: 0.7rem; padding: 1.5rem; text-align: center; color: #e2e8f0; background: rgba(11, 18, 32, 0.78); font-size: 0.9rem; } .dsc-cover--bad { position: static; min-height: 220px; color: #fecdd3; }
.dsc-spin { width: 30px; height: 30px; border-radius: 50%; border: 3px solid rgba(255, 255, 255, 0.25); border-top-color: #2dd4bf; animation: dsc-spin 0.8s linear infinite; }
.dsc-modes { display: flex; justify-content: center; gap: 0.4rem; padding: 0.7rem 1rem 0; background: #fff; flex-wrap: wrap; } .dsc-modes button { min-height: 0 !important; padding: 0.4rem 0.9rem; border: 0; border-radius: 99px; font-size: 0.8rem; font-weight: 800; color: #334155; background: #f1f5f9; box-shadow: inset 0 0 0 1.5px #e2e8f0; cursor: pointer; } .dsc-modes button.on { color: #fff; background: linear-gradient(135deg, #0d9488, #0f766e); box-shadow: 0 10px 16px -10px #0f766e; }
.dsc-foot { display: flex; align-items: center; justify-content: center; gap: 0.7rem; flex-wrap: wrap; padding: 0.9rem 1rem 1rem; background: #fff; }
.dsc-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; min-height: 44px !important; padding: 0 1.1rem; border: 0; border-radius: 14px; font-size: 0.86rem; font-weight: 800; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.2s ease; } .dsc-btn:hover:not(:disabled) { transform: translateY(-2px); } .dsc-btn:disabled { opacity: 0.55; cursor: wait; }
.dsc-btn--ghost { color: #334155; background: #fff; box-shadow: inset 0 0 0 1.5px #cbd5e1; } .dsc-btn--go { color: #fff; background: linear-gradient(135deg, #0d9488, #0f766e); box-shadow: 0 14px 22px -12px #0f766e; }
.dsc-shutter { display: grid; place-items: center; width: 68px; height: 68px; min-height: 0 !important; padding: 0; border: 4px solid #0f766e; border-radius: 50%; background: #fff; cursor: pointer; transition: transform 0.12s ease; } .dsc-shutter span { width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(145deg, #2dd4bf, #0d9488); } .dsc-shutter:hover:not(:disabled) { transform: scale(1.06); } .dsc-shutter:active { transform: scale(0.94); } .dsc-shutter:disabled { opacity: 0.4; }
.dsc-enter-active, .dsc-leave-active { transition: opacity 0.2s ease; } .dsc-enter-active .dsc-card, .dsc-leave-active .dsc-card { transition: transform 0.3s cubic-bezier(0.2, 1.1, 0.3, 1); } .dsc-enter-from, .dsc-leave-to { opacity: 0; } .dsc-enter-from .dsc-card { transform: translateY(22px) scale(0.95); }
@keyframes dsc-spin { to { transform: rotate(360deg); } }
html.dark .dsc-card, html.dark .dsc-foot, html.dark .dsc-modes { background: #151c33; color: #eef0fa; } html.dark .dsc-btn--ghost { color: #e2e8f0; background: transparent; box-shadow: inset 0 0 0 1.5px #475569; } html.dark .dsc-modes button { color: #e2e8f0; background: rgba(255, 255, 255, 0.06); box-shadow: inset 0 0 0 1.5px #334155; }
@media (prefers-reduced-motion: reduce) { .dsc-spin { animation: none; } }
</style>
