<template>
  <div class="pc">
    <div class="pc-frame" :style="{ width: W + 'px', height: H + 'px' }" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @wheel.prevent="wheel">
      <canvas v-show="img && !cam" ref="cv" :width="W * dpr" :height="H * dpr" :style="{ width: W + 'px', height: H + 'px' }"></canvas>
      <video v-show="cam" ref="vid" autoplay playsinline muted></video>
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
      <span v-if="error" class="pc-err">{{ error }}</span>
    </div>
  </div>
</template>

<script>
// Cuts a photograph to the 4:5 frame of the card. The picture is never stretched: it is moved and zoomed inside the frame, and the part inside the frame is saved as the card picture (the original is kept as well).
export default {
  props: { modelValue: Object, staffPhotoUrl: String, minWidth: { type: Number, default: 600 } },
  emits: ['update:modelValue'],
  data() { return { W: 224, H: 280, dpr: Math.min(2, window.devicePixelRatio || 1), img: null, zoom: 1, ox: 0, oy: 0, drag: null, cam: false, stream: null, original: null, low: false, srcW: 0, error: '' }; },
  beforeUnmount() { this.stopCamera(); },
  computed: {
    cover() { return this.img ? Math.max(this.W / this.img.naturalWidth, this.H / this.img.naturalHeight) : 1; },
    scale() { return this.cover * this.zoom; },
  },
  methods: {
    pick(e) {
      const f = e.target.files && e.target.files[0]; e.target.value = '';
      if (f) this.load(f, f);
    },
    load(blobOrFile, original) {
      this.error = '';
      if (!/^image\/(jpeg|png|webp)$/.test(blobOrFile.type)) { this.error = this.$t('Choose a JPG, PNG or WEBP picture.'); return; }
      if (blobOrFile.size > 10 * 1024 * 1024) { this.error = this.$t('The picture is too large. Keep it under 10 MB.'); return; }
      const url = URL.createObjectURL(blobOrFile); const im = new Image();
      im.onload = () => { this.stopCamera(); this.img = im; this.original = original; this.zoom = 1; this.center(); this.$nextTick(() => this.redraw(true)); };
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
      g.setTransform(this.dpr, 0, 0, this.dpr, 0, 0); g.clearRect(0, 0, this.W, this.H); g.fillStyle = '#afb3b6'; g.fillRect(0, 0, this.W, this.H);
      g.drawImage(this.img, this.ox, this.oy, this.img.naturalWidth * this.scale, this.img.naturalHeight * this.scale);
      if (emit) this.emitCrop();
    },
    emitCrop() {
      const s = this.scale; const sx = -this.ox / s, sy = -this.oy / s, sw = this.W / s, sh = this.H / s;
      this.srcW = Math.round(sw); this.low = sw < this.minWidth;
      const ow = Math.min(1000, Math.round(sw)), oh = Math.round(ow * 1.25);
      const o = document.createElement('canvas'); o.width = ow; o.height = oh; const g = o.getContext('2d'); g.imageSmoothingQuality = 'high';
      g.drawImage(this.img, sx, sy, sw, sh, 0, 0, ow, oh);
      o.toBlob(b => {
        if (!b) return;
        const file = new File([b], 'photo.jpg', { type: 'image/jpeg' });
        this.$emit('update:modelValue', { file, original: this.original, dataUri: o.toDataURL('image/jpeg', 0.8), low: this.low, width: this.srcW });
      }, 'image/jpeg', 0.92);
    },
    down(e) { if (!this.img) return; this.drag = { x: e.clientX, y: e.clientY, ox: this.ox, oy: this.oy }; e.currentTarget.setPointerCapture(e.pointerId); },
    move(e) { if (!this.drag) return; this.ox = this.drag.ox + (e.clientX - this.drag.x); this.oy = this.drag.oy + (e.clientY - this.drag.y); this.redraw(false); },
    up() { if (this.drag) { this.drag = null; this.redraw(true); } },
    wheel(e) { if (!this.img) return; this.zoom = Math.min(4, Math.max(1, this.zoom + (e.deltaY < 0 ? 0.08 : -0.08))); this.redraw(true); },
    reset() { this.stopCamera(); this.img = null; this.original = null; this.error = ''; this.$emit('update:modelValue', null); },
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
.hidden { display: none; }
</style>
