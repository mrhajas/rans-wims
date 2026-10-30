<template>
  <span class="cs" tabindex="0" :aria-label="$t('Show how the ID looks')" @mouseenter="show" @mouseleave="hide" @focusin="show" @focusout="hide">
    <span class="cs-ico" :style="{ '--c': color }">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="15" rx="2.6" /><circle cx="9" cy="10.6" r="2.1" /><path d="M5.8 16.4c.5-1.6 1.7-2.4 3.2-2.4s2.7.8 3.2 2.4" /><path d="M14.4 9.6h4M14.4 12.6h4M14.4 15.4h2.4" /></svg>
    </span>
    <teleport to="body">
      <div v-if="open" class="cs-pop" :class="{ 'is-in': shown }" :style="pos" aria-hidden="true">
        <div class="cs-faces">
          <div class="cs-side"><card-face v-if="sample" :html="sample.front" :css="css" /><span v-else class="cs-wait"></span><small>{{ $t('Front') }}</small></div>
          <div class="cs-side"><card-face v-if="sample" :html="sample.back" :css="css" /><span v-else class="cs-wait"></span><small>{{ $t('Back') }}</small></div>
        </div>
        <p><b>{{ name }}</b> · {{ $t('as it looks with the settings now') }}</p>
      </div>
    </teleport>
  </span>
</template>

<script>
import CardFace from './CardFace.vue';

// A small ID icon in the colour of the category; hover (or focus) and the real ID grows out of it, front and back, drawn with the settings the category has now.
export default {
  components: { CardFace },
  props: { sample: { type: Object, default: null }, css: { type: String, default: '' }, color: { type: String, default: '#1565c0' }, name: { type: String, default: '' } },
  data() { return { open: false, shown: false, pos: {}, t: null } },
  beforeUnmount() { clearTimeout(this.t); },
  methods: {
    show(e) {
      if (window.matchMedia && window.matchMedia('(hover: none)').matches && e.type === 'mouseenter') return;
      clearTimeout(this.t);
      const r = this.$el.getBoundingClientRect(), w = Math.min(420, window.innerWidth - 24), h = w * 0.93, right = r.right + 14 + w <= window.innerWidth - 8;
      const left = right ? r.right + 14 : Math.max(8, r.left - 14 - w), top = Math.max(8, Math.min(window.innerHeight - h - 8, r.top + r.height / 2 - h / 2));
      this.pos = { left: left + 'px', top: top + 'px', width: w + 'px', transformOrigin: (r.left + r.width / 2 - left) + 'px ' + (r.top + r.height / 2 - top) + 'px' };
      this.open = true; requestAnimationFrame(() => requestAnimationFrame(() => { this.shown = true; }));
    },
    hide() { this.shown = false; this.t = setTimeout(() => { this.open = false; }, 220); },
  },
};
</script>

<style scoped>
.cs { display: inline-flex; vertical-align: middle; margin-right: 10px; cursor: zoom-in; outline: none; }
.cs-ico { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; color: var(--c); background: color-mix(in srgb, var(--c) 14%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 35%, transparent); transition: transform 0.2s cubic-bezier(.2,.8,.2,1), background 0.2s, color 0.2s; }
.cs:hover .cs-ico, .cs:focus-visible .cs-ico { transform: scale(1.12) rotate(-4deg); background: var(--c); color: #fff; }
.cs:focus-visible .cs-ico { box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 45%, transparent); }
@media (prefers-reduced-motion: reduce) { .cs-ico { transition: none; } }
</style>

<style>
.cs-pop { position: fixed; z-index: 3000; padding: 14px 14px 12px; border-radius: 22px; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px rgba(15, 37, 64, 0.1), 0 40px 70px -24px rgba(15, 37, 64, 0.7); pointer-events: none; opacity: 0; transform: scale(0.1); transition: transform 0.3s cubic-bezier(.2,.9,.25,1), opacity 0.2s ease; }
.cs-pop.is-in { opacity: 1; transform: scale(1); }
.cs-faces { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.cs-side { display: grid; gap: 6px; justify-items: center; } .cs-side > :first-child { width: 100%; } .cs-side small { font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; font-size: 0.64rem; color: #64748b; }
.cs-wait { display: block; width: 100%; aspect-ratio: 55 / 85; border-radius: 4%; background: linear-gradient(110deg, #e2e8f0 30%, #f1f5f9 50%, #e2e8f0 70%); background-size: 200% 100%; animation: cs-wait 1.2s linear infinite; }
@keyframes cs-wait { to { background-position: -200% 0; } }
.cs-pop p { margin: 10px 0 0; font-size: 0.78rem; color: #64748b; text-align: center; } .cs-pop p b { color: #1e4467; }
@media (prefers-reduced-motion: reduce) { .cs-pop { transition: opacity 0.15s; transform: none; } }
</style>
