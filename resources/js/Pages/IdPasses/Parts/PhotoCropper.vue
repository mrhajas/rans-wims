<template>
  <div class="pc">
    <div class="pc-frame" :style="{ width: W + 'px', height: H + 'px' }" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @wheel.prevent="wheel">
      <canvas v-show="img && !cam" ref="cv" :width="W * dpr" :height="H * dpr" :style="{ width: W + 'px', height: H + 'px' }"></canvas>
      <video v-show="cam" ref="vid" autoplay playsinline muted></video>
      <div v-if="working" class="pc-work"><i></i><span>{{ $t('Setting the background') }}</span><small>{{ $t(status || 'Please wait') }}</small></div>
      <div v-if="!img && !cam" class="pc-empty">
        <div v-if="staffPhotoUrl" class="pc-staff"><img :src="staffPhotoUrl" alt="" /><small>{{ $t('The staff profile photograph is used unless you choose another.') }}</small></div>
        <template v-else><icons name="camera" class="w-8 h-8" /><span>{{ $t('Frame of the card photograph') }}</span></template>
      </div>
    </div>
    <div class="pc-side">
      <input ref="file" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pick" />
      <div class="pc-btns">
        <button type="button" class="lg-btn tone" @click="$refs.file.click()" style="--tone: #0284c7"><span class="tone-ico"><icons name="upload" class="w-4 h-4" /></span>{{ $t('Choose photo') }}</button>
        <button v-if="!cam" type="button" class="lg-btn tone" @click="openCamera" style="--tone: #0284c7"><span class="tone-ico"><icons name="camera" class="w-4 h-4" /></span>{{ $t('Take photo') }}</button>
        <button v-else type="button" class="lg-btn tone tone-solid" @click="snap" style="--tone: #0284c7"><span class="tone-ico"><icons name="camera" class="w-4 h-4" /></span>{{ $t('Capture') }}</button>
        <button v-if="img || cam" type="button" class="lg-btn tone t-slate" @click="reset" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Remove') }}</button>
      </div>
      <label v-if="img" class="pc-zoom"><span>{{ $t('Zoom') }}</span><input v-model.number="zoom" type="range" min="1" max="4" step="0.01" @input="redraw(true)" /></label>
      <p v-if="img" class="pc-hint">{{ $t('Drag the picture to place the face; the frame is the shape of the card photograph.') }}</p>
      <span v-if="img" class="pc-chip" :class="low ? 'is-low' : 'is-ok'">{{ low ? $t('Small picture: the printed photo may look soft (:w px across).', { w: srcW }) : $t('Sharp enough for printing (:w px across).', { w: srcW }) }}</span>
      <span v-if="img && bgState === 'done'" class="pc-chip is-bg"><i :style="{ background: background }"></i>{{ $t('The background is now the colour of this ID.') }}</span>
      <span v-else-if="img && bgState === 'kept'" class="pc-chip is-bg"><i :style="{ background: background }"></i>{{ $t('The photograph already has this background, so it is kept as it is.') }}</span>
      <span v-else-if="img && bgState === 'failed'" class="pc-chip is-low">{{ $t('The background could not be changed:') }} {{ bgMsg }} {{ $t('The photograph is used as it is.') }}</span>
      <span v-if="error" class="pc-err">{{ error }}</span>
    </div>
  </div>
</template>

<script>
import { removeBackground } from '../../../Core/removeBackground';

// Cuts a photograph to the 4:5 frame of the card. The picture is never stretched: it is moved and zoomed inside the frame, and the part inside the frame is saved as the card picture (the original is kept as well).
// With a background colour (the setting of the ID category) the person is cut out once, in the browser, and shown on that colour while the picture is framed; a photograph that already stands on the colour is left alone.
// The card then gets the framed picture on the colour plus the transparent cut-out, so the colour can be changed later without a browser.
const sample = (cv, hex) => {      // whether the top and side border of the frame is already the colour
  const w = 64, h = 80, t = document.createElement('canvas'); t.width = w; t.height = h; const g = t.getContext('2d'); g.drawImage(cv, 0, 0, w, h);
  const d = g.getImageData(0, 0, w, h).data; const r0 = parseInt(hex.slice(1, 3), 16), g0 = parseInt(hex.slice(3, 5), 16), b0 = parseInt(hex.slice(5, 7), 16);
  let n = 0, hit = 0;
  for (let y = 0; y < Math.floor(h * 0.72); y++) for (let x = 0; x < w; x++) {
    if (y >= 4 && x >= 4 && x < w - 4) continue;
    const i = (y * w + x) * 4; n++; if (Math.sqrt((d[i] - r0) ** 2 + (d[i + 1] - g0) ** 2 + (d[i + 2] - b0) ** 2) <= 42) hit++;
  }
  return n > 0 && hit / n >= 0.88;
};

export default {
  props: { modelValue: Object, staffPhotoUrl: String, minWidth: { type: Number, default: 600 }, background: { type: String, default: '' } },
  emits: ['update:modelValue', 'busy'],
  data() { return { W: 224, H: 280, dpr: Math.min(2, window.devicePixelRatio || 1), img: null, zoom: 1, ox: 0, oy: 0, drag: null, cam: false, stream: null, original: null, low: false, srcW: 0, error: '', cut: null, bgState: '', bgMsg: '', status: '', job: 0, auto: false, timer: null }; },
  beforeUnmount() { this.stopCamera(); clearTimeout(this.timer); },
  watch: {
    staffPhotoUrl: { immediate: true, handler(u) { this.useStaffPhoto(u); } },
    background() { if (this.img) this.prepareBackground(); },
  },
  computed: {
    cover() { return this.img ? Math.max(this.W / this.img.naturalWidth, this.H / this.img.naturalHeight) : 1; },
    scale() { return this.cover * this.zoom; },
    working() { return this.bgState === 'working'; },
  },
  methods: {
    pick(e) {
      const f = e.target.files && e.target.files[0]; e.target.value = '';
      if (f) { this.auto = false; this.load(f, f); }
    },
    /** With a background colour set, the staff profile photograph goes through the same framing and background steps as any other photograph (without one the server uses it as it is). */
    async useStaffPhoto(u) {
      if (!this.background) return;
      if (!u) { if (this.auto) this.reset(); return; }
      if (this.img && !this.auto) return;       // a photograph chosen by hand wins
      try {
        const r = await window.axios.get(u, { responseType: 'blob' });
        this.auto = true; this.load(new File([r.data], 'staff.jpg', { type: r.data.type || 'image/jpeg' }), null);
      } catch (e) { /* the server uses the profile photograph as it is */ }
    },
    load(blobOrFile, original) {
      this.error = '';
      if (!/^image\/(jpeg|png|webp)$/.test(blobOrFile.type)) { this.error = this.$t('Choose a JPG, PNG or WEBP picture.'); return; }
      if (blobOrFile.size > 10 * 1024 * 1024) { this.error = this.$t('The picture is too large. Keep it under 10 MB.'); return; }
      const url = URL.createObjectURL(blobOrFile); const im = new Image();
      im.onload = () => { this.stopCamera(); this.img = im; this.original = original; this.zoom = 1; this.cut = null; this.bgState = ''; this.bgMsg = ''; this.srcBlob = blobOrFile; this.center(); this.$nextTick(() => { this.redraw(true); this.prepareBackground(); }); };
      im.onerror = () => { this.error = this.$t('That picture could not be read.'); };
      im.src = url;
    },
    center() { const dw = this.img.naturalWidth * this.scale, dh = this.img.naturalHeight * this.scale; this.ox = (this.W - dw) / 2; this.oy = (this.H - dh) / 2.6; this.clamp(); },
    clamp() { const dw = this.img.naturalWidth * this.scale, dh = this.img.naturalHeight * this.scale; this.ox = Math.min(0, Math.max(this.W - dw, this.ox)); this.oy = Math.min(0, Math.max(this.H - dh, this.oy)); },
    redraw(emit) {
      if (!this.img) return;
      // keep the same point in the middle when zooming
      this.clamp();
      const c = this.$refs.cv; if (!c) return; const g = c.getContext('2d');
      g.setTransform(this.dpr, 0, 0, this.dpr, 0, 0); g.clearRect(0, 0, this.W, this.H); g.fillStyle = this.cut ? this.background : '#afb3b6'; g.fillRect(0, 0, this.W, this.H);
      g.drawImage(this.cut || this.img, this.ox, this.oy, this.img.naturalWidth * this.scale, this.img.naturalHeight * this.scale);
      if (emit) this.emitSoon();
    },
    /** Framing is redrawn at every step of the zoom slider; the picture handed on is made once the hand rests. */
    emitSoon() { clearTimeout(this.timer); this.timer = setTimeout(this.emitCrop, 140); },
    emitCrop() {
      if (!this.img) return;
      const s = this.scale; const sx = -this.ox / s, sy = -this.oy / s, sw = this.W / s, sh = this.H / s;
      this.srcW = Math.round(sw); this.low = sw < this.minWidth;
      const ow = Math.min(1000, Math.round(sw)), oh = Math.round(ow * 1.25);
      const o = document.createElement('canvas'); o.width = ow; o.height = oh; const g = o.getContext('2d'); g.imageSmoothingQuality = 'high';
      const cut = this.cut; let oc = null;
      if (cut) {
        g.fillStyle = this.background; g.fillRect(0, 0, ow, oh);
        const k = cut.naturalWidth / this.img.naturalWidth;
        g.drawImage(cut, sx * k, sy * k, sw * k, sh * k, 0, 0, ow, oh);
        oc = document.createElement('canvas'); oc.width = ow; oc.height = oh; const gc = oc.getContext('2d'); gc.imageSmoothingQuality = 'high';
        gc.drawImage(cut, sx * k, sy * k, sw * k, sh * k, 0, 0, ow, oh);
      } else {
        g.drawImage(this.img, sx, sy, sw, sh, 0, 0, ow, oh);
      }
      const done = (file, cutout) => this.$emit('update:modelValue', { file, original: this.original, cutout, dataUri: o.toDataURL('image/jpeg', 0.8), low: this.low, width: this.srcW, bg: cut ? this.background : null });
      o.toBlob(b => {
        if (!b) return;
        const file = new File([b], 'photo.jpg', { type: 'image/jpeg' });
        if (!oc) { done(file, null); return; }
        oc.toBlob(c => done(file, c ? new File([c], 'cutout.png', { type: 'image/png' }) : null), 'image/png');
      }, 'image/jpeg', 0.92);
    },
    /** Cuts the person out (once per picture) when the category has a background colour, unless the photograph already stands on it. */
    async prepareBackground() {
      const job = ++this.job; this.cut = null; this.bgMsg = '';
      if (!this.background || !this.img) { this.bgState = ''; this.$emit('busy', false); return; }
      // the frame as first shown, drawn on its own so the colour of its border can be read
      const t = document.createElement('canvas'); t.width = 128; t.height = 160; t.getContext('2d').drawImage(this.img, -this.ox / this.scale, -this.oy / this.scale, this.W / this.scale, this.H / this.scale, 0, 0, 128, 160);
      if (sample(t, this.background)) { this.bgState = 'kept'; this.$emit('busy', false); this.emitCrop(); return; }
      this.bgState = 'working'; this.$emit('busy', true);
      try {
        const png = await removeBackground(this.srcBlob, m => { if (job === this.job) this.status = m; }, { trim: false });
        if (job !== this.job) return;
        const url = URL.createObjectURL(png); const im = new Image();
        await new Promise((ok, no) => { im.onload = ok; im.onerror = () => no(new Error(this.$t('The cut-out could not be read.'))); im.src = url; });
        if (job !== this.job) return;
        this.cut = im; this.bgState = 'done';
      } catch (e) {
        if (job !== this.job) return;
        this.bgState = 'failed'; this.bgMsg = (e && e.message) || this.$t('The cut-out engine did not start.');
      }
      this.$emit('busy', false); this.redraw(false); this.emitCrop();
    },
    down(e) { if (!this.img) return; this.drag = { x: e.clientX, y: e.clientY, ox: this.ox, oy: this.oy }; e.currentTarget.setPointerCapture(e.pointerId); },
    move(e) { if (!this.drag) return; this.ox = this.drag.ox + (e.clientX - this.drag.x); this.oy = this.drag.oy + (e.clientY - this.drag.y); this.redraw(false); },
    up() { if (this.drag) { this.drag = null; this.redraw(true); } },
    wheel(e) { if (!this.img) return; this.zoom = Math.min(4, Math.max(1, this.zoom + (e.deltaY < 0 ? 0.08 : -0.08))); this.redraw(true); },
    reset() { this.stopCamera(); this.job++; this.img = null; this.cut = null; this.bgState = ''; this.auto = false; this.original = null; this.error = ''; this.$emit('busy', false); this.$emit('update:modelValue', null); },
    async openCamera() {
      this.error = '';
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1600 } }, audio: false });
        this.cam = true; this.$nextTick(() => { this.$refs.vid.srcObject = this.stream; });
      } catch (e) { this.error = this.$t('The camera could not be opened. Allow the camera in the browser, or choose a photo instead.'); }
    },
    stopCamera() { if (this.stream) this.stream.getTracks().forEach(t => t.stop()); this.stream = null; this.cam = false; },
    snap() {
      const v = this.$refs.vid; if (!v || !v.videoWidth) return;
      const c = document.createElement('canvas'); c.width = v.videoWidth; c.height = v.videoHeight; c.getContext('2d').drawImage(v, 0, 0);
      c.toBlob(b => { if (b) this.load(b, new File([b], 'camera.jpg', { type: 'image/jpeg' })); }, 'image/jpeg', 0.95);
    },
  },
};
</script>

<style scoped>
.pc { display: flex; gap: 18px; flex-wrap: wrap; align-items: flex-start; }
.pc-frame { position: relative; border-radius: 16px; overflow: hidden; background: #afb3b6; flex: none; touch-action: none; cursor: grab; box-shadow: 0 14px 24px -16px rgba(15, 37, 64, 0.7), 0 0 0 1px rgba(15, 37, 64, 0.12); }
.pc-frame canvas, .pc-frame video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.pc-empty { position: absolute; inset: 0; display: grid; place-content: center; justify-items: center; gap: 8px; color: #fff; text-align: center; padding: 14px; font-size: 0.8rem; font-weight: 700; }
.pc-staff { display: grid; gap: 8px; justify-items: center; } .pc-staff img { width: 120px; height: 150px; object-fit: cover; border-radius: 12px; } .pc-staff small { font-weight: 600; }
.pc-side { flex: 1; min-width: 220px; display: grid; gap: 10px; align-content: start; }
.pc-btns { display: flex; flex-wrap: wrap; gap: 8px; }
.pc-zoom { display: grid; gap: 4px; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute, #64748b); } .pc-zoom input { width: 100%; accent-color: #1565c0; }
.pc-hint { margin: 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.pc-chip { display: inline-block; justify-self: start; padding: 4px 10px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; }
.pc-chip.is-ok { background: rgba(22, 163, 74, 0.14); color: #166534; } .pc-chip.is-low { background: rgba(217, 119, 6, 0.16); color: #92400e; }
.pc-err { color: #be123c; font-size: 0.8rem; font-weight: 700; }
.pc-work { position: absolute; inset: 0; z-index: 2; display: grid; place-content: center; justify-items: center; gap: 6px; padding: 14px; text-align: center; color: #fff; font-weight: 800; font-size: 0.82rem; background: rgba(15, 37, 64, 0.62); backdrop-filter: blur(2px); }
.pc-work small { font-weight: 600; opacity: 0.85; }
.pc-work i { width: 34px; height: 34px; border-radius: 50%; border: 4px solid rgba(255, 255, 255, 0.3); border-top-color: #fff; animation: pc-spin 0.8s linear infinite; }
@keyframes pc-spin { to { transform: rotate(360deg); } }
.pc-chip.is-bg { display: inline-flex; align-items: center; gap: 8px; background: rgba(21, 101, 192, 0.1); color: #1e4467; }
.pc-chip.is-bg i { width: 14px; height: 14px; border-radius: 50%; flex: none; box-shadow: 0 0 0 2px #fff, 0 0 0 3px rgba(15, 37, 64, 0.25); }
@media (prefers-reduced-motion: reduce) { .pc-work i { animation-duration: 3s; } }
.hidden { display: none; }
</style>
