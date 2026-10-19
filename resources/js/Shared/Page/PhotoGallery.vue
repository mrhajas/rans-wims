<template>
  <div class="pg" :style="{ '--pg-size': size + 'px' }">
    <div v-for="(photo, i) in photos" :key="photo.key || photo.id || photo.url" class="pg-item">
      <photo-zoom
        :src="photo.url"
        :alt="photo.name || ''"
        :size="size"
        :zoom="zoom"
        placement="over"
        class="pg-thumb"
        role="button"
        @click="open(i)"
        @keydown.enter.prevent="open(i)"
      />
      <span class="pg-index">{{ i + 1 }}</span>
      <button
        v-if="removable && photo.removable !== false"
        type="button"
        class="pg-remove lg-tip"
        :data-tip="$t('Remove')"
        :aria-label="$t('Remove')"
        @click.stop="$emit('remove', photo, i)"
      >
        <icons name="cross" class="w-3 h-3" />
      </button>
    </div>
    <button
      v-for="n in emptySlots"
      :key="'empty-' + n"
      type="button"
      class="pg-empty lg-tip tip-left"
      :data-tip="$t('Add photo')"
      :aria-label="$t('Add photo')"
      @click="$emit('add')"
    >
      <icons name="image" class="w-6 h-6" />
    </button>

    <teleport to="body">
      <transition name="pg-fade">
        <div
          v-if="isOpen"
          class="pg-lightbox"
          role="dialog"
          aria-modal="true"
          tabindex="-1"
          ref="lightbox"
          @click.self="close"
          @touchstart.passive="onTouchStart"
          @touchend.passive="onTouchEnd"
        >
          <div class="pg-lb-top">
            <span class="pg-lb-count">{{ index + 1 }} / {{ photos.length }}</span>
            <div class="pg-lb-tools">
              <a :href="current.url" :download="current.name || true" class="pg-lb-btn" :aria-label="$t('Download Photo')" @click.stop>
                <icons name="download" class="w-5 h-5" />
              </a>
              <button type="button" class="pg-lb-btn" :aria-label="$t('Close')" @click="close">
                <icons name="cross" class="w-5 h-5" />
              </button>
            </div>
          </div>

          <button v-if="photos.length > 1" type="button" class="pg-lb-nav pg-lb-prev" :aria-label="$t('Previous')" @click.stop="prev">
            <icons name="chevron-down" class="w-6 h-6" />
          </button>

          <figure class="pg-lb-stage" @click.self="close">
            <img :src="current.url" :alt="current.name || ''" class="pg-lb-img" :class="{ 'is-zoomed': zoomed }" @click.stop="zoomed = !zoomed" />
            <figcaption v-if="current.name" class="pg-lb-name">{{ current.name }}</figcaption>
          </figure>

          <button v-if="photos.length > 1" type="button" class="pg-lb-nav pg-lb-next" :aria-label="$t('Next')" @click.stop="next">
            <icons name="chevron-down" class="w-6 h-6" />
          </button>

          <div v-if="photos.length > 1" class="pg-lb-dots">
            <button
              v-for="(p, i) in photos"
              :key="'dot-' + i"
              type="button"
              class="pg-lb-dot"
              :class="{ 'is-on': i === index }"
              :aria-label="(i + 1) + ' / ' + photos.length"
              @click.stop="go(i)"
            ></button>
          </div>
        </div>
      </transition>
    </teleport>
  </div>
</template>

<script>
import PhotoZoom from '@/Shared/Page/PhotoZoom.vue';

/**
 * Round photo thumbnails with a pure-CSS hover lens (PhotoZoom) plus a
 * click-to-open lightbox slideshow: previous / next buttons, keyboard arrows,
 * swipe, a "1 / 3" counter and click-to-zoom on the large image.
 *
 * photos: [{ url, name?, id?, key?, removable? }]
 * removable: shows a small remove button on each thumbnail (emits `remove`)
 * max: when > photos.length, renders dashed "add" placeholders (emits `add`)
 */
export default {
  components: { PhotoZoom },

  props: {
    photos: { type: Array, default: () => [] },
    size: { type: Number, default: 88 },
    zoom: { type: Number, default: 220 },
    removable: { type: Boolean, default: false },
    max: { type: Number, default: 0 },
  },

  emits: ['remove', 'add'],

  data() {
    return { isOpen: false, index: 0, zoomed: false, touchX: null };
  },

  computed: {
    current() {
      return this.photos[this.index] || { url: '' };
    },
    emptySlots() {
      return this.max > this.photos.length ? this.max - this.photos.length : 0;
    },
  },

  watch: {
    photos(list) {
      if (this.isOpen) {
        if (!list.length) return this.close();
        if (this.index > list.length - 1) this.index = list.length - 1;
      }
    },
  },

  beforeUnmount() {
    this.detach();
  },

  methods: {
    open(i) {
      if (!this.photos.length) return;
      this.index = i;
      this.zoomed = false;
      this.isOpen = true;
      window.addEventListener('keydown', this.onKey);
      this.$nextTick(() => this.$refs.lightbox && this.$refs.lightbox.focus());
    },
    close() {
      this.isOpen = false;
      this.detach();
    },
    detach() {
      window.removeEventListener('keydown', this.onKey);
    },
    go(i) {
      this.index = i;
      this.zoomed = false;
    },
    next() {
      this.go((this.index + 1) % this.photos.length);
    },
    prev() {
      this.go((this.index - 1 + this.photos.length) % this.photos.length);
    },
    onKey(e) {
      if (e.key === 'Escape') this.close();
      else if (e.key === 'ArrowRight' && this.photos.length > 1) this.next();
      else if (e.key === 'ArrowLeft' && this.photos.length > 1) this.prev();
      else return;
      e.preventDefault();
    },
    onTouchStart(e) {
      this.touchX = e.changedTouches[0].clientX;
    },
    onTouchEnd(e) {
      if (this.touchX === null || this.photos.length < 2) return;
      const dx = e.changedTouches[0].clientX - this.touchX;
      this.touchX = null;
      if (Math.abs(dx) > 50) dx < 0 ? this.next() : this.prev();
    },
  },
};
</script>

<style scoped>
.pg { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
.pg-item { position: relative; flex: none; width: var(--pg-size); height: var(--pg-size); }
.pg-item:hover { z-index: 5; }
.pg-thumb { box-shadow: 0 8px 18px -8px rgba(15, 23, 42, 0.5), 0 0 0 2px rgba(255, 255, 255, 0.9); }
.pg-index { position: absolute; left: -4px; top: -4px; z-index: 2; display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 9999px; font-size: 0.64rem; font-weight: 800; color: #fff; background: #1c3f66; pointer-events: none; }
.pg-remove { position: absolute; right: -6px; top: -6px; z-index: 6; display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; min-width: 22px; min-height: 0; padding: 0; border-radius: 9999px; color: #fff; background: #dc2626; box-shadow: 0 4px 10px -2px rgba(220, 38, 38, 0.6); }
.pg-remove:hover { background: #b91c1c; }
.pg-remove :deep(div), .pg-remove :deep(svg) { width: 12px; height: 12px; flex: none; }
.pg-empty { display: inline-flex; align-items: center; justify-content: center; flex: none; width: var(--pg-size); height: var(--pg-size); min-height: 0; padding: 0; border-radius: 9999px; border: 2px dashed #b6c4d8; color: #94a3b8; background: rgba(148, 163, 184, 0.08); transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease; }
.pg-empty:hover { border-color: #2f6fb3; color: #2f6fb3; background: rgba(47, 111, 179, 0.08); }
html.dark .pg-thumb { box-shadow: 0 8px 18px -8px rgba(0, 0, 0, 0.7), 0 0 0 2px rgba(255, 255, 255, 0.12); }
html.dark .pg-empty { border-color: rgba(147, 197, 253, 0.3); }

/* lightbox */
.pg-lightbox { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 56px 64px 52px; background: rgba(8, 15, 30, 0.92); outline: none; }
.pg-lb-top { position: absolute; top: 0; left: 0; right: 0; display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; }
.pg-lb-count { padding: 4px 12px; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; color: #fff; background: rgba(255, 255, 255, 0.14); font-variant-numeric: tabular-nums; }
.pg-lb-tools { display: flex; gap: 8px; }
.pg-lb-btn, .pg-lb-nav { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; min-height: 0; min-width: 0; padding: 0; border-radius: 9999px; color: #fff; background: rgba(255, 255, 255, 0.14); transition: background 0.15s ease; }
.pg-lb-btn:hover, .pg-lb-nav:hover { background: rgba(255, 255, 255, 0.28); }
.pg-lb-btn :deep(div), .pg-lb-nav :deep(div) { flex: none; }
.pg-lb-nav { position: absolute; top: 50%; margin-top: -20px; z-index: 2; }
.pg-lb-prev { left: 12px; }
.pg-lb-prev :deep(div) { transform: rotate(90deg); }
.pg-lb-next { right: 12px; }
.pg-lb-next :deep(div) { transform: rotate(-90deg); }
.pg-lb-stage { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; height: 100%; margin: 0; min-width: 0; overflow: hidden; }
.pg-lb-img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 12px; background: #fff; box-shadow: 0 30px 60px -20px rgba(0, 0, 0, 0.7); cursor: zoom-in; transition: transform 0.25s ease; }
.pg-lb-img.is-zoomed { cursor: zoom-out; transform: scale(1.9); }
.pg-lb-name { margin-top: 10px; max-width: 100%; text-align: center; font-size: 0.78rem; font-weight: 700; color: #e2e8f0; word-break: break-word; }
.pg-lb-dots { position: absolute; bottom: 16px; left: 0; right: 0; display: flex; justify-content: center; gap: 8px; }
.pg-lb-dot { width: 10px; height: 10px; min-height: 0; min-width: 0; padding: 0; border-radius: 9999px; background: rgba(255, 255, 255, 0.35); }
.pg-lb-dot.is-on { background: #fff; transform: scale(1.25); }
.pg-fade-enter-active, .pg-fade-leave-active { transition: opacity 0.18s ease; }
.pg-fade-enter-from, .pg-fade-leave-to { opacity: 0; }
@media (max-width: 640px) {
  .pg-lightbox { padding: 56px 12px 52px; }
  .pg-lb-nav { top: auto; bottom: 8px; margin-top: 0; }
  .pg-lb-prev { left: 16px; }
  .pg-lb-next { right: 16px; }
}
@media (prefers-reduced-motion: reduce) { .pg-lb-img, .pg-fade-enter-active, .pg-fade-leave-active { transition: none; } }
</style>
