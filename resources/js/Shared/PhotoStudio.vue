<template>
  <teleport to="body">
    <transition name="ps">
      <div v-if="open" class="ps" role="dialog" aria-modal="true" :aria-label="$t('Photo studio')">
        <div class="ps-card" :class="{ 'is-saving': saving, 'is-saved': saved }">
          <header class="ps-head">
            <span class="ps-badge"><icons name="camera" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2>{{ $t('Photo studio') }}</h2><p>{{ $t('Adjust the photo, then use it. Nothing is saved until you press Use this photo.') }}</p></div>
            <button type="button" class="ps-x" :aria-label="$t('Cancel')" :disabled="saving" @click="cancel"><icons name="cross" class="w-5 h-5" /></button>
          </header>

          <div class="ps-body">
            <!-- the photo and its crop frame -->
            <div class="ps-stage-wrap">
              <div class="ps-stage" :class="{ 'is-dragging': dragging, 'is-cutting': cutting, 'is-develop': develop, 'is-dissolve': dissolve }" :style="stageStyle" ref="stage"
                @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @wheel.prevent="wheel">
                <canvas ref="cv" :width="W * dpr" :height="H * dpr"></canvas>
                <i class="ps-grid" aria-hidden="true"></i>
                <i class="ps-corner tl" aria-hidden="true"></i><i class="ps-corner tr" aria-hidden="true"></i><i class="ps-corner bl" aria-hidden="true"></i><i class="ps-corner br" aria-hidden="true"></i>
                <i class="ps-flash" aria-hidden="true"></i>
                <!-- cutting out: a light scans the photo -->
                <div v-if="cutting" class="ps-scan" aria-hidden="true"><i></i><span v-for="n in 8" :key="n" :style="{ '--i': n }"></span></div>
                <div v-if="cutting" class="ps-work"><b>{{ $t('Removing the background') }}</b><small>{{ $t(status || 'Please wait') }}</small></div>
                <div v-if="saving || saved" class="ps-save" aria-live="polite">
                  <svg class="ring" viewBox="0 0 80 80"><circle cx="40" cy="40" r="34" /><circle class="fill" cx="40" cy="40" r="34" /></svg>
                  <svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                  <b>{{ saved ? $t('Saved to the form') : $t('Making it small') }}</b>
                  <small v-if="saved">{{ $t(':a KB (was :b KB)', { a: outKb, b: srcKb }) }}</small>
                  <span v-if="saved" class="spark"><i v-for="n in 10" :key="n" :style="{ '--i': n }"></i></span>
                </div>
              </div>
              <p class="ps-hint"><icons name="info" class="w-3.5 h-3.5" />{{ $t('Drag the photo to place it in the frame. Zoom with the slider, the mouse wheel or two fingers.') }}</p>
            </div>

            <!-- the controls -->
            <div class="ps-side">
              <section class="ps-sec" style="--k: 0">
                <header><span class="ico c-violet"><icons name="sparkles" class="w-4 h-4" /></span><b>{{ $t('Background') }}</b></header>
                <label class="ps-switch" :class="{ on: useCut, off: cutting }">
                  <input type="checkbox" :checked="useCut" :disabled="cutting || saving" @change="toggleCut($event.target.checked)" />
                  <i class="tr"><i class="th"></i></i>
                  <span class="tx"><b>{{ $t('Remove the background') }}</b><small>{{ $t('Optional. Done on this device; the photo is not sent anywhere for this.') }}</small></span>
                </label>
                <div class="ps-swatches" :class="{ show: useCut }">
                  <button v-for="s in swatches" :key="s.key" type="button" class="sw" :class="{ on: bg === s.key }" :style="{ '--c': s.color }" :disabled="!useCut || cutting || saving" @click="pickBg(s.key)">
                    <i></i><span>{{ s.label }}</span>
                  </button>
                </div>
                <p v-if="cutError" class="ps-err">{{ cutError }}</p>
              </section>

              <section class="ps-sec" style="--k: 1">
                <header><span class="ico c-sky"><icons name="image" class="w-4 h-4" /></span><b>{{ $t('Crop') }}</b></header>
                <div class="ps-row">
                  <button v-for="a in aspects" :key="a.key" type="button" class="chip" :class="{ on: aspect === a.key }" :disabled="saving" @click="setAspect(a.key)"><i class="shape" :class="a.key"></i>{{ a.label }}</button>
                </div>
                <label class="ps-zoom"><icons name="search" class="w-4 h-4" /><input type="range" min="1" max="4" step="0.01" v-model.number="zoom" :disabled="saving" @input="clampDraw" /><em>{{ Math.round(zoom * 100) }}%</em></label>
                <div class="ps-row">
                  <button type="button" class="chip" :disabled="saving" @click="rotate(-90)"><icons name="refresh" class="w-3.5 h-3.5 flip" />{{ $t('Left') }}</button>
                  <button type="button" class="chip" :disabled="saving" @click="rotate(90)"><icons name="refresh" class="w-3.5 h-3.5" />{{ $t('Right') }}</button>
                  <button type="button" class="chip" :disabled="saving" @click="reset"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Reset') }}</button>
                </div>
              </section>

              <section class="ps-sec" style="--k: 2">
                <header><span class="ico c-green"><icons name="download" class="w-4 h-4" /></span><b>{{ $t('Size') }}</b><em>{{ estimate }}</em></header>
                <div class="ps-row">
                  <button v-for="z in sizes" :key="z.key" type="button" class="chip big" :class="{ on: size === z.key }" :disabled="saving" @click="setSize(z.key)"><b>{{ z.label }}</b><small>{{ z.px }} px</small></button>
                </div>
              </section>
            </div>
          </div>

          <footer class="ps-foot">
            <button type="button" class="ps-btn" :disabled="saving" @click="$emit('retake')"><icons name="camera" class="w-4 h-4" />{{ $t('Retake') }}</button>
            <span class="grow"></span>
            <button type="button" class="ps-btn" :disabled="saving" @click="cancel">{{ $t('Cancel') }}</button>
            <button type="button" class="ps-btn main" :disabled="saving || cutting || !ready" @click="save"><icons name="tick" class="w-4 h-4" />{{ $t('Use this photo') }}</button>
          </footer>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
import { removeBackground } from '@/Core/removeBackground';

// The photo studio of the Take Photo button (staff and users): the photo can be turned, cropped to a portrait or a square, zoomed, put on white or blue after the background is
// removed (the removal is a choice, off at the start), and made small at one of three sizes. All of it happens on this device; what is handed back is one small JPEG.
const BLUE = '#2f78d4';
export default {
  props: { open: Boolean, file: { type: Blob, default: null }, defaultPx: { type: Number, default: 600 } },
  emits: ['done', 'cancel', 'retake'],
  data() {
    return {
      dpr: Math.min(2, window.devicePixelRatio || 1), aspect: 'portrait', zoom: 1, ox: 0, oy: 0, rot: 0, dragging: false, drag: null, pointers: {},
      src: null, cutBmp: null, useCut: false, cutting: false, cutError: '', status: '', bg: 'white',
      size: 'm', develop: false, dissolve: false, saving: false, saved: false, ready: false, outKb: 0, srcKb: 0, estKb: null, t: 0,
    };
  },
  computed: {
    swatches() { return [{ key: 'white', label: this.$t('White'), color: '#ffffff' }, { key: 'blue', label: this.$t('Blue'), color: BLUE }]; },
    aspects() { return [{ key: 'portrait', label: this.$t('Portrait 3:4') }, { key: 'square', label: this.$t('Square 1:1') }]; },
    sizes() { return [{ key: 's', label: this.$t('Small'), px: 400 }, { key: 'm', label: this.$t('Medium'), px: 600 }, { key: 'l', label: this.$t('Large'), px: 900 }]; },
    sizePx() { return this.sizes.find(s => s.key === this.size).px; },
    W() { return 300; },
    H() { return this.aspect === 'square' ? 300 : 400; },
    stageStyle() { return { '--w': this.W + 'px', aspectRatio: this.W + ' / ' + this.H }; },
    estimate() { return this.estKb === null ? '' : '≈ ' + this.estKb + ' KB'; },
    color() { return this.bg === 'blue' ? BLUE : '#ffffff'; },
  },
  watch: {
    open(v) { if (v) this.begin(); else this.dispose(); },
    file() { if (this.open) this.begin(); },
  },
  beforeUnmount() { this.dispose(); },
  methods: {
    async begin() {
      this.dispose();
      Object.assign(this, { aspect: 'portrait', zoom: 1, ox: 0, oy: 0, rot: 0, useCut: false, cutting: false, cutError: '', bg: 'white', saving: false, saved: false, ready: false, estKb: null });
      const near = this.sizes.reduce((a, b) => (Math.abs(b.px - this.defaultPx) < Math.abs(a.px - this.defaultPx) ? b : a));
      this.size = near.key;
      if (!this.file) return;
      this.srcKb = Math.max(1, Math.round(this.file.size / 1024));
      try { this.src = await createImageBitmap(this.file, { imageOrientation: 'from-image' }); } catch (e) { this.cutError = this.$t('This photo could not be opened.'); return; }
      this.ready = true;
      await this.$nextTick();
      this.fit();
      this.draw();
      this.develop = true; // the photo "develops" when the studio opens
      setTimeout(() => { this.develop = false; }, 1100);
      this.guessSize();
    },
    dispose() {
      if (this.src && this.src.close) this.src.close();
      if (this.cutBmp && this.cutBmp.close) this.cutBmp.close();
      this.src = null; this.cutBmp = null; this.ready = false;
    },
    // the picture, turned, as a drawable
    layer(bmp) {
      if (!bmp) return null;
      if (this.rot === 0) return bmp;
      const q = this.rot % 180 !== 0;
      const c = document.createElement('canvas');
      c.width = q ? bmp.height : bmp.width;
      c.height = q ? bmp.width : bmp.height;
      const g = c.getContext('2d');
      g.translate(c.width / 2, c.height / 2);
      g.rotate((this.rot * Math.PI) / 180);
      g.drawImage(bmp, -bmp.width / 2, -bmp.height / 2);

      return c;
    },
    base() { const l = this.layer(this.src); return l ? { w: l.width, h: l.height } : { w: 1, h: 1 }; },
    cover() { const b = this.base(); return Math.max(this.W / b.w, this.H / b.h); },
    fit() { this.zoom = 1; this.ox = 0; this.oy = 0; },
    // keeps the photo covering the whole frame
    clampDraw() {
      const b = this.base();
      const s = this.cover() * this.zoom;
      const mx = Math.max(0, (b.w * s - this.W) / 2);
      const my = Math.max(0, (b.h * s - this.H) / 2);
      this.ox = Math.max(-mx, Math.min(mx, this.ox));
      this.oy = Math.max(-my, Math.min(my, this.oy));
      this.draw();
      this.guessSize();
    },
    paint(ctx, w, h, k) {
      // the same picture, scaled by k (the screen frame is W x H; the finished photo is bigger or smaller)
      const useCut = this.useCut && this.cutBmp;
      ctx.fillStyle = useCut ? this.color : '#ffffff';
      ctx.fillRect(0, 0, w, h);
      const l = this.layer(useCut ? this.cutBmp : this.src);
      if (!l) return;
      const b = this.base();
      const s = this.cover() * this.zoom * k;
      const dw = b.w * s;
      const dh = b.h * s;
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(l, w / 2 - dw / 2 + this.ox * k, h / 2 - dh / 2 + this.oy * k, dw, dh);
    },
    draw() {
      const cv = this.$refs.cv;
      if (!cv || !this.src) return;
      const ctx = cv.getContext('2d');
      ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
      this.paint(ctx, this.W, this.H, 1);
    },
    // ---- moving and zooming the photo in the frame
    scaleOf() { const r = this.$refs.stage.getBoundingClientRect(); return r.width / this.W || 1; },
    down(e) {
      this.$refs.stage.setPointerCapture && this.$refs.stage.setPointerCapture(e.pointerId);
      this.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
      this.dragging = true;
      this.drag = { ox: this.ox, oy: this.oy, x: e.clientX, y: e.clientY, d: this.pinch(), z: this.zoom };
    },
    pinch() { const p = Object.values(this.pointers); return p.length === 2 ? Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y) : 0; },
    move(e) {
      if (!this.drag || !this.pointers[e.pointerId]) return;
      this.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
      const d = this.pinch();
      if (d && this.drag.d) {
        this.zoom = Math.max(1, Math.min(4, this.drag.z * (d / this.drag.d)));
      } else if (!d) {
        const k = this.scaleOf();
        this.ox = this.drag.ox + (e.clientX - this.drag.x) / k;
        this.oy = this.drag.oy + (e.clientY - this.drag.y) / k;
      }
      this.clampDraw();
    },
    up(e) {
      delete this.pointers[e.pointerId];
      if (!Object.keys(this.pointers).length) { this.dragging = false; this.drag = null; } else { this.drag = { ox: this.ox, oy: this.oy, x: e.clientX, y: e.clientY, d: this.pinch(), z: this.zoom }; }
    },
    wheel(e) { this.zoom = Math.max(1, Math.min(4, this.zoom * (e.deltaY < 0 ? 1.08 : 0.92))); this.clampDraw(); },
    setAspect(k) { this.aspect = k; this.$nextTick(() => { this.fit(); this.draw(); this.guessSize(); }); },
    rotate(d) { this.rot = (this.rot + d + 360) % 360; this.$nextTick(() => { this.fit(); this.draw(); this.guessSize(); }); },
    reset() { this.rot = 0; this.fit(); this.draw(); this.guessSize(); },
    setSize(k) { this.size = k; this.guessSize(); },
    pickBg(k) { this.bg = k; this.draw(); this.guessSize(); },
    // ---- the background (optional)
    async toggleCut(on) {
      this.cutError = '';
      if (!on) { this.useCut = false; this.draw(); this.guessSize(); return; }
      if (this.cutBmp) { this.useCut = true; this.flashDissolve(); this.draw(); this.guessSize(); return; }
      this.cutting = true;
      this.status = 'Loading the cut-out engine';
      try {
        const blob = await removeBackground(this.file, s => { this.status = s; }, { trim: false });
        this.cutBmp = await createImageBitmap(blob, { imageOrientation: 'from-image' });
        this.useCut = true;
        this.flashDissolve();
        this.draw();
        this.guessSize();
      } catch (e) {
        this.useCut = false;
        this.cutError = this.$t('The background could not be removed on this device. The photo is kept as it is.');
      } finally {
        this.cutting = false;
      }
    },
    flashDissolve() { this.dissolve = true; setTimeout(() => { this.dissolve = false; }, 900); },
    // ---- the finished photo
    render() {
      const k = this.sizePx / Math.max(this.W, this.H);
      const w = Math.round(this.W * k);
      const h = Math.round(this.H * k);
      const c = document.createElement('canvas');
      c.width = w;
      c.height = h;
      this.paint(c.getContext('2d'), w, h, k);

      return new Promise(r => c.toBlob(r, 'image/jpeg', 0.84));
    },
    guessSize() {
      clearTimeout(this.t);
      this.t = setTimeout(async () => { if (!this.src) return; const b = await this.render(); this.estKb = b ? Math.max(1, Math.round(b.size / 1024)) : null; }, 250);
    },
    async save() {
      if (this.saving || !this.ready) return;
      this.saving = true;
      const t0 = Date.now();
      const blob = await this.render();
      if (!blob) { this.saving = false; this.cutError = this.$t('The photo could not be saved.'); return; }
      const out = new File([blob], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' });
      this.outKb = Math.max(1, Math.round(blob.size / 1024));
      // the "making it small" moment is shown for a beat so it can be seen
      await new Promise(r => setTimeout(r, Math.max(0, 700 - (Date.now() - t0))));
      this.saved = true;
      await new Promise(r => setTimeout(r, 1100));
      this.$emit('done', { file: out, cut: this.useCut && !!this.cutBmp, bg: this.bg, kb: this.outKb });
      this.saving = false;
    },
    cancel() { if (!this.saving) this.$emit('cancel'); },
  },
};
</script>

<style scoped>
.ps { position: fixed; inset: 0; z-index: 2100; display: grid; place-items: center; padding: 12px; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); }
.ps-card { width: min(920px, 100%); max-height: calc(100vh - 24px); display: flex; flex-direction: column; overflow: hidden; border-radius: 24px; background: #fff; color: #0f172a; box-shadow: 0 40px 90px -24px rgba(0, 0, 0, 0.6); }
html.dark .ps-card { background: #111a2e; color: #e2e8f0; }
.ps-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid rgba(148, 163, 184, 0.25); }
.ps-badge { display: grid; place-items: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #34d399, #0f766e); box-shadow: 0 8px 16px -8px #0f766e; }
.ps-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; } .ps-head p { margin: 1px 0 0; font-size: 0.74rem; opacity: 0.65; }
.ps-x { margin-left: auto; display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; background: rgba(100, 116, 139, 0.14); transition: transform 0.2s, background 0.2s; } .ps-x:hover { transform: rotate(90deg); background: rgba(100, 116, 139, 0.25); }
.ps-body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 18px; padding: 16px 18px; overflow: auto; }
@media (max-width: 760px) { .ps-body { grid-template-columns: minmax(0, 1fr); } }
.ps-stage-wrap { display: grid; justify-items: center; align-content: start; gap: 8px; }
.ps-stage { position: relative; width: min(var(--w), 100%); max-height: 52vh; border-radius: 16px; overflow: hidden; background: #e2e8f0 repeating-conic-gradient(rgba(148, 163, 184, 0.3) 0 25%, transparent 0 50%) 0 0 / 16px 16px; touch-action: none; cursor: grab; box-shadow: 0 16px 36px -18px rgba(15, 23, 42, 0.6); transition: aspect-ratio 0.35s ease, box-shadow 0.3s; }
.ps-stage.is-dragging { cursor: grabbing; box-shadow: 0 22px 44px -16px rgba(15, 23, 42, 0.7); }
.ps-stage canvas { display: block; width: 100%; height: 100%; }
.ps-grid { position: absolute; inset: 0; pointer-events: none; opacity: 0; transition: opacity 0.25s; background: linear-gradient(90deg, transparent 33%, rgba(255, 255, 255, 0.55) 33% calc(33% + 1px), transparent calc(33% + 1px) 66%, rgba(255, 255, 255, 0.55) 66% calc(66% + 1px), transparent calc(66% + 1px)), linear-gradient(0deg, transparent 33%, rgba(255, 255, 255, 0.55) 33% calc(33% + 1px), transparent calc(33% + 1px) 66%, rgba(255, 255, 255, 0.55) 66% calc(66% + 1px), transparent calc(66% + 1px)); }
.is-dragging .ps-grid { opacity: 1; }
.ps-corner { position: absolute; width: 22px; height: 22px; border: 3px solid #fff; pointer-events: none; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5)); animation: ps-corner 2.2s ease-in-out infinite; }
.ps-corner.tl { top: 8px; left: 8px; border-right: 0; border-bottom: 0; border-top-left-radius: 8px; } .ps-corner.tr { top: 8px; right: 8px; border-left: 0; border-bottom: 0; border-top-right-radius: 8px; }
.ps-corner.bl { bottom: 8px; left: 8px; border-right: 0; border-top: 0; border-bottom-left-radius: 8px; } .ps-corner.br { bottom: 8px; right: 8px; border-left: 0; border-top: 0; border-bottom-right-radius: 8px; }
@keyframes ps-corner { 50% { transform: scale(0.82); opacity: 0.7; } }
.ps-flash { position: absolute; inset: 0; background: #fff; opacity: 0; pointer-events: none; }
.is-develop .ps-flash { animation: ps-flash 0.7s ease-out; } .is-develop canvas { animation: ps-develop 1s cubic-bezier(0.2, 0.8, 0.2, 1); }
@keyframes ps-flash { 0% { opacity: 0.95; } 100% { opacity: 0; } } @keyframes ps-develop { from { filter: blur(14px) brightness(1.5) saturate(0.2); transform: scale(1.08); } }
.is-dissolve canvas { animation: ps-dissolve 0.9s ease-out; } @keyframes ps-dissolve { from { filter: blur(6px) brightness(1.25); transform: scale(1.03); } }
.ps-scan { position: absolute; inset: 0; pointer-events: none; background: rgba(15, 23, 42, 0.28); }
.ps-scan > i { position: absolute; left: 0; right: 0; height: 46px; background: linear-gradient(180deg, transparent, rgba(52, 211, 153, 0.75), transparent); box-shadow: 0 0 22px rgba(52, 211, 153, 0.8); animation: ps-sweep 1.5s ease-in-out infinite; }
@keyframes ps-sweep { 0% { top: -46px; } 100% { top: 100%; } }
.ps-scan span { position: absolute; width: 6px; height: 6px; border-radius: 50%; background: #a7f3d0; left: calc(var(--i) * 11%); top: calc(10% + (var(--i) * 37%) % 80%); animation: ps-twinkle 1.2s ease-in-out infinite; animation-delay: calc(var(--i) * 0.14s); } @keyframes ps-twinkle { 0%, 100% { transform: scale(0); opacity: 0; } 50% { transform: scale(1.6); opacity: 1; } }
.ps-work { position: absolute; left: 50%; bottom: 14px; transform: translateX(-50%); display: grid; gap: 1px; padding: 8px 14px; border-radius: 12px; text-align: center; color: #fff; background: rgba(15, 23, 42, 0.78); backdrop-filter: blur(6px); } .ps-work b { font-size: 0.8rem; } .ps-work small { font-size: 0.68rem; opacity: 0.8; }
.ps-hint { display: flex; gap: 6px; max-width: 340px; margin: 0; font-size: 0.72rem; line-height: 1.4; opacity: 0.65; } .ps-hint svg { flex: none; margin-top: 1px; }

.ps-side { display: grid; gap: 12px; align-content: start; }
.ps-sec { padding: 12px 14px; border-radius: 16px; background: rgba(100, 116, 139, 0.07); animation: ps-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1.1) backwards; animation-delay: calc(0.15s + var(--k) * 80ms); }
@keyframes ps-rise { from { opacity: 0; transform: translateY(14px); } }
.ps-sec > header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; } .ps-sec > header b { font-size: 0.85rem; font-weight: 800; } .ps-sec > header em { margin-left: auto; font-style: normal; font-size: 0.78rem; font-weight: 800; color: #0f766e; }
.ico { display: grid; place-items: center; width: 26px; height: 26px; border-radius: 9px; color: #fff; } .c-violet { background: #7c3aed; } .c-sky { background: #0284c7; } .c-green { background: #059669; }
.ps-switch { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; } .ps-switch input { position: absolute; opacity: 0; pointer-events: none; }
.ps-switch .tr { flex: none; position: relative; width: 44px; height: 26px; margin-top: 2px; border-radius: 9999px; background: #cbd5e1; transition: background 0.3s ease; }
.ps-switch .th { position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3); transition: transform 0.35s cubic-bezier(0.2, 1.4, 0.3, 1); }
.ps-switch.on .tr { background: linear-gradient(135deg, #34d399, #059669); } .ps-switch.on .th { transform: translateX(18px); } .ps-switch.off { opacity: 0.8; }
.ps-switch .tx { display: grid; gap: 1px; } .ps-switch .tx b { font-size: 0.82rem; } .ps-switch .tx small { font-size: 0.7rem; line-height: 1.35; opacity: 0.65; }
.ps-swatches { display: flex; gap: 10px; max-height: 0; opacity: 0; overflow: hidden; transition: max-height 0.4s ease, opacity 0.3s ease, margin 0.3s ease; } .ps-swatches.show { max-height: 70px; opacity: 1; margin-top: 10px; }
.sw { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px 6px 6px; border-radius: 9999px; font-size: 0.78rem; font-weight: 700; background: rgba(100, 116, 139, 0.1); box-shadow: inset 0 0 0 2px transparent; transition: transform 0.2s, box-shadow 0.25s; }
.sw i { width: 26px; height: 26px; border-radius: 50%; background: var(--c); box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.25); }
.sw:hover:not(:disabled) { transform: translateY(-2px); } .sw.on { box-shadow: inset 0 0 0 2px #059669; } .sw.on i { animation: ps-pop 0.45s cubic-bezier(0.2, 1.5, 0.3, 1); } @keyframes ps-pop { 40% { transform: scale(1.35); } }
.ps-row { display: flex; flex-wrap: wrap; gap: 8px; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; background: rgba(100, 116, 139, 0.12); box-shadow: inset 0 0 0 2px transparent; transition: transform 0.15s, box-shadow 0.2s, background 0.2s; }
.chip:hover:not(:disabled) { transform: translateY(-1px); background: rgba(100, 116, 139, 0.2); } .chip.on { background: rgba(5, 150, 105, 0.14); box-shadow: inset 0 0 0 2px #059669; color: #047857; }
.chip.big { flex-direction: column; gap: 0; padding: 7px 16px; border-radius: 16px; } .chip.big small { font-size: 0.64rem; font-weight: 600; opacity: 0.7; }
.chip .shape { width: 11px; border: 2px solid currentColor; border-radius: 3px; } .chip .shape.portrait { height: 15px; } .chip .shape.square { height: 11px; }
.flip { transform: scaleX(-1); }
.ps-zoom { display: flex; align-items: center; gap: 8px; margin: 10px 0; } .ps-zoom input { flex: 1; accent-color: #0284c7; } .ps-zoom em { font-style: normal; width: 42px; text-align: right; font-size: 0.75rem; font-weight: 700; }
.ps-err { margin: 8px 0 0; font-size: 0.76rem; font-weight: 600; color: #b45309; }
.ps-foot { display: flex; align-items: center; gap: 10px; padding: 12px 18px; border-top: 1px solid rgba(148, 163, 184, 0.25); background: rgba(100, 116, 139, 0.05); } .grow { flex: 1; }
.ps-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; background: rgba(100, 116, 139, 0.14); transition: transform 0.15s, box-shadow 0.2s, filter 0.2s; }
.ps-btn:hover:not(:disabled) { transform: translateY(-1px); } .ps-btn:disabled { opacity: 0.55; }
.ps-btn.main { color: #fff; background: linear-gradient(135deg, #10b981, #0f766e); box-shadow: 0 10px 20px -10px #0f766e; } .ps-btn.main:hover:not(:disabled) { filter: brightness(1.08); }

/* saving: the frame shrinks into a ring, the size drops, a tick pops */
.ps-save { position: absolute; inset: 0; display: grid; place-content: center; justify-items: center; gap: 2px; text-align: center; color: #fff; background: rgba(15, 23, 42, 0.78); animation: ps-fade 0.3s ease; }
.ps-save .ring { width: 84px; height: 84px; transform: rotate(-90deg); } .ps-save .ring circle { fill: none; stroke-width: 6; stroke: rgba(255, 255, 255, 0.2); } .ps-save .ring .fill { stroke: #34d399; stroke-linecap: round; stroke-dasharray: 214; stroke-dashoffset: 214; animation: ps-ring 0.8s ease-out forwards; }
.ps-save .tick { position: absolute; top: calc(50% - 56px); width: 38px; height: 38px; color: #34d399; opacity: 0; transform: scale(0); } .is-saved .ps-save .tick { animation: ps-tick 0.5s 0.05s cubic-bezier(0.2, 1.6, 0.3, 1) forwards; }
.ps-save b { margin-top: 8px; font-size: 0.9rem; } .ps-save small { font-size: 0.74rem; opacity: 0.85; }
@keyframes ps-ring { to { stroke-dashoffset: 0; } } @keyframes ps-tick { to { opacity: 1; transform: scale(1); } } @keyframes ps-fade { from { opacity: 0; } }
.ps-save .spark i { position: absolute; left: 50%; top: 42%; width: 7px; height: 7px; border-radius: 50%; background: #fbbf24; animation: ps-burst 0.9s ease-out forwards; transform: rotate(calc(var(--i) * 36deg)) translateY(0); }
@keyframes ps-burst { to { transform: rotate(calc(var(--i) * 36deg)) translateY(-70px); opacity: 0; } }
.is-saved .ps-stage canvas { transition: transform 0.4s; }

.ps-enter-active { transition: opacity 0.25s ease; } .ps-enter-active .ps-card { transition: transform 0.45s cubic-bezier(0.2, 1.2, 0.3, 1); } .ps-leave-active { transition: opacity 0.2s ease; }
.ps-enter-from, .ps-leave-to { opacity: 0; } .ps-enter-from .ps-card { transform: translateY(24px) scale(0.94); }
@media (prefers-reduced-motion: reduce) { .ps *, .ps-sec { animation: none !important; transition: none !important; } }
</style>
