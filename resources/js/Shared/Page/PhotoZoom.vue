<template>
  <span class="pz" :class="['pz-' + placement]" :style="{ '--pz-size': size + 'px', '--pz-zoom': 'min(' + zoom + 'px, 42vw)' }" tabindex="0" :aria-label="alt">
    <img :src="src" :alt="alt" class="pz-img" :class="imgClass" />
    <slot />
    <span class="pz-big" aria-hidden="true"><img :src="src" alt="" /></span>
  </span>
</template>

<script>
/**
 * A round photo that opens into a larger circle on hover / keyboard focus (pure CSS, no JS positioning).
 * `placement` decides where the big circle opens relative to the small one: below, over (centred on it), below-end (below, right edges aligned - for avatars at the screen's right edge), left, or corner (grows right/down from the small one's top-left, safe inside clipped panels).
 */
export default {
  props: {
    src: { type: String, required: true },
    alt: { type: String, default: '' },
    size: { type: Number, default: 40 },
    zoom: { type: Number, default: 180 },
    placement: { type: String, default: 'below' },
    imgClass: { type: [String, Array, Object], default: '' },
  },
};
</script>

<style scoped>
.pz { position: relative; display: inline-flex; flex: none; width: var(--pz-size); height: var(--pz-size); border-radius: 9999px; cursor: zoom-in; outline: none; }
.pz-img { width: 100%; height: 100%; border-radius: 9999px; object-fit: cover; }
.pz-big { position: absolute; z-index: 60; width: var(--pz-size); height: var(--pz-size); overflow: hidden; border-radius: 9999px; border: 4px solid #fff; background: #fff; box-shadow: 0 24px 44px -12px rgba(15, 23, 42, 0.55), 0 0 0 1px rgba(99, 102, 241, 0.25); opacity: 0; pointer-events: none; transition: opacity 0.18s ease, width 0.24s cubic-bezier(0.3, 1.3, 0.5, 1), height 0.24s cubic-bezier(0.3, 1.3, 0.5, 1), left 0.24s cubic-bezier(0.3, 1.3, 0.5, 1), top 0.24s cubic-bezier(0.3, 1.3, 0.5, 1); }
.pz-big img { display: block; width: 100%; height: 100%; object-fit: cover; }
/* closed positions */
.pz-over .pz-big, .pz-below .pz-big, .pz-left .pz-big, .pz-corner .pz-big { left: 0; top: 0; }
.pz:hover .pz-big, .pz:focus-visible .pz-big { opacity: 1; width: var(--pz-zoom); height: var(--pz-zoom); }
.pz-over:hover .pz-big, .pz-over:focus-visible .pz-big { left: calc((var(--pz-size) - var(--pz-zoom)) / 2); top: calc((var(--pz-size) - var(--pz-zoom)) / 2); }
.pz-below:hover .pz-big, .pz-below:focus-visible .pz-big { left: calc((var(--pz-size) - var(--pz-zoom)) / 2); top: calc(var(--pz-size) + 8px); }
.pz-below-end .pz-big { left: 0; top: 0; }
.pz-below-end:hover .pz-big, .pz-below-end:focus-visible .pz-big { left: calc(var(--pz-size) - var(--pz-zoom)); top: calc(var(--pz-size) + 8px); }
.pz-left:hover .pz-big, .pz-left:focus-visible .pz-big { left: calc(-1 * var(--pz-zoom) - 8px); top: calc((var(--pz-size) - var(--pz-zoom)) / 2); }
@media (prefers-reduced-motion: reduce) { .pz-big { transition: opacity 0.1s ease; } }
</style>
