<template>
  <div ref="frame" class="zi" :class="{ 'is-cover': fit === 'cover' }" @mouseenter="enter" @mousemove="move" @mouseleave="leave" @click="$emit('open')">
    <img ref="img" :src="src" :alt="alt" draggable="false" :style="{ objectFit: fit }" @load="$emit('loaded')" @error="$emit('error')" />
    <span v-if="icon" class="zi-icon" aria-hidden="true"><icons name="zoom-in" class="w-4 h-4" /></span>
    <Teleport to="body">
      <!-- the picture zoomed OUTSIDE its frame, like the item photos of the Items page: a larger copy floats beside the pointer (it never covers the frame itself) -->
      <div v-if="on && mode === 'preview'" class="zi-pop" :style="popStyle"><img :src="src" alt="" :class="{ 'is-natural': preview === 'natural' }" :style="popImg" /></div>
      <!-- lens: a magnifier window beside the picture shows the part under the pointer, enlarged (for reading the writing on a BinCard) -->
      <div v-if="on && mode === 'lens'" class="zi-lens" :style="lensStyle" aria-hidden="true"></div>
    </Teleport>
  </div>
</template>

<script>
// A picture that zooms OUTSIDE its container while the pointer is over it.
//   mode="preview" (default): a larger copy of the picture floats above the pointer, as in the Items list. preview="square" (cropped, like the item photos) or "natural" (whole picture).
//   mode="lens": a magnifier window next to the picture follows the pointer and shows that spot at `scale` times (for reading small writing).
// A small magnifier on the frame says it can be zoomed; a click emits "open" (the page can show the picture bigger).
export default {
  props: {
    src: String,
    alt: { type: String, default: '' },
    fit: { type: String, default: 'contain' },
    icon: { type: Boolean, default: true },
    mode: { type: String, default: 'preview' },
    preview: { type: String, default: 'square' },
    size: { type: Number, default: 220 }, // the floating copy's width (preview) or the magnifier window's size (lens)
    scale: { type: Number, default: 2.6 },
  },
  emits: ['open', 'loaded', 'error'],
  data() {
    return { on: false, px: 0, py: 0, rect: null, nw: 0, nh: 0 };
  },
  computed: {
    popStyle() {
      const r = this.rect;
      if (!r) return {};
      const w = this.size + 14; // the picture and its frame
      const h = (this.preview === 'natural' && this.nw ? Math.min(this.size * (this.nh / this.nw), window.innerHeight * 0.6) : this.size) + 14;
      const midX = Math.min(Math.max(r.left + r.width / 2, w / 2 + 10), window.innerWidth - w / 2 - 10);
      // above the frame when there is room (as on the Items list), else beside it, so the buttons under a photo are never covered
      if (r.top > h + 18) return { left: midX + 'px', top: r.top - 10 + 'px', transform: 'translate(-50%, -100%)' };
      const top = Math.max(10, Math.min(window.innerHeight - h - 10, r.top + r.height / 2 - h / 2));
      if (r.right + 12 + w < window.innerWidth) return { left: r.right + 12 + 'px', top: top + 'px' };
      if (r.left - 12 - w > 0) return { left: r.left - 12 - w + 'px', top: top + 'px' };

      return { left: midX + 'px', top: Math.min(window.innerHeight - h - 10, r.bottom + 10) + 'px', transform: 'translateX(-50%)' };
    },
    popImg() {
      return this.preview === 'natural' ? { width: this.size + 'px', height: 'auto', maxHeight: '60vh', objectFit: 'contain' } : { width: this.size + 'px', height: this.size + 'px', objectFit: 'cover' };
    },
    lensStyle() {
      const r = this.rect;
      if (!r || !this.nw) return { display: 'none' };
      const S = this.size;
      // where the picture really sits inside the frame (contain leaves bands)
      const k = this.fit === 'cover' ? Math.max(r.width / this.nw, r.height / this.nh) : Math.min(r.width / this.nw, r.height / this.nh);
      const iw = this.nw * k;
      const ih = this.nh * k;
      const ox = r.left + (r.width - iw) / 2;
      const oy = r.top + (r.height - ih) / 2;
      const rx = Math.max(0, Math.min(iw, this.px - ox));
      const ry = Math.max(0, Math.min(ih, this.py - oy));
      const left = r.right + 14 + S < window.innerWidth ? r.right + 14 : Math.max(8, r.left - S - 14);
      const top = Math.max(8, Math.min(window.innerHeight - S - 8, this.py - S / 2));

      return {
        left: left + 'px', top: top + 'px', width: S + 'px', height: S + 'px', backgroundImage: 'url(' + this.src + ')',
        backgroundSize: iw * this.scale + 'px ' + ih * this.scale + 'px', backgroundPosition: -(rx * this.scale - S / 2) + 'px ' + -(ry * this.scale - S / 2) + 'px',
      };
    },
  },
  methods: {
    enter(e) {
      this.rect = this.$refs.frame.getBoundingClientRect();
      const im = this.$refs.img;
      this.nw = im && im.naturalWidth ? im.naturalWidth : 0;
      this.nh = im && im.naturalHeight ? im.naturalHeight : 0;
      this.px = e.clientX;
      this.py = e.clientY;
      if (window.matchMedia && window.matchMedia('(hover: none)').matches) return; // a touch screen has no hover
      this.on = true;
    },
    move(e) {
      this.px = e.clientX;
      this.py = e.clientY;
    },
    leave() {
      this.on = false;
    },
  },
  beforeUnmount() {
    this.on = false;
  },
};
</script>

<style scoped>
.zi { position: relative; overflow: hidden; width: 100%; height: 100%; cursor: zoom-in; border-radius: inherit; }
.zi > img { display: block; width: 100%; height: 100%; user-select: none; }
.zi-icon { position: absolute; right: 6px; bottom: 6px; display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 9999px; background: rgba(15, 23, 42, 0.72); color: #fff; opacity: 0; transform: translateY(4px); transition: opacity 0.15s ease, transform 0.15s ease; pointer-events: none; }
.zi:hover .zi-icon { opacity: 1; transform: none; }
</style>

<style>
/* the floating copy (same look as the item photo zoom of the Items list) */
.zi-pop { position: fixed; z-index: 10050; pointer-events: none; padding: 6px; background: #fff; border-radius: 0.85rem; border: 1px solid rgba(15, 23, 42, 0.08); box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.4); }
.zi-pop img { display: block; border-radius: 0.6rem; background: repeating-conic-gradient(#e2e8f0 0 25%, #f8fafc 0 50%) 50% / 16px 16px; }
.zi-lens { position: fixed; z-index: 10050; pointer-events: none; border-radius: 1rem; border: 3px solid #0f766e; background-color: #fff; background-repeat: no-repeat; box-shadow: 0 20px 44px -10px rgba(15, 23, 42, 0.5); }
html.dark .zi-pop { background: #1e293b; border-color: rgba(255, 255, 255, 0.12); }
</style>
