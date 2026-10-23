<template>
  <div v-if="canUp || canDown" class="sj print:hidden" :class="{ 'sj--nochat': !hasChat }">
    <transition name="sj-pop">
      <button v-if="canUp" type="button" class="sj-btn sj-btn--up" :data-tip="$t('Scroll to the top')" :aria-label="$t('Scroll to the top')" @click="go('top')"><icons name="chevron-up" class="w-5 h-5" /></button>
    </transition>
    <transition name="sj-pop">
      <button v-if="canDown" type="button" class="sj-btn sj-btn--down" :data-tip="$t('Scroll to the bottom')" :aria-label="$t('Scroll to the bottom')" @click="go('bottom')"><icons name="chevron-down" class="w-5 h-5" /></button>
    </transition>
  </div>
</template>

<script>
// Two floating buttons above the WIMSChat button: all the way up and all the way down. They sit under the chat drawer and its backdrop (z-index), and
// scroll whichever area actually scrolls on this screen: the page body on a phone, the main content area on a larger screen.
import Icons from '@/Shared/Icons.vue';

export default {
  components: { Icons },
  data() {
    return { canUp: false, canDown: false, hasChat: true, timer: null };
  },
  mounted() {
    window.addEventListener('scroll', this.measure, { passive: true, capture: true });
    window.addEventListener('resize', this.measure, { passive: true });
    this.timer = setInterval(this.measure, 700); // content grows and shrinks (lists load, sections open): look again now and then
    this.measure();
  },
  beforeUnmount() {
    window.removeEventListener('scroll', this.measure, { capture: true });
    window.removeEventListener('resize', this.measure);
    clearInterval(this.timer);
  },
  methods: {
    // the element that scrolls: the main content region when it has its own scrollbar, otherwise the whole page
    target() {
      const region = document.querySelector('[scroll-region]');
      if (region && region.scrollHeight > region.clientHeight + 4 && /(auto|scroll)/.test(getComputedStyle(region).overflowY)) return region;
      return document.scrollingElement || document.documentElement;
    },
    measure() {
      const t = this.target();
      const max = t.scrollHeight - t.clientHeight;
      this.canUp = t.scrollTop > 240;
      this.canDown = max > 240 && max - t.scrollTop > 240;
      this.hasChat = !!document.querySelector('.wcl-fab');
    },
    go(where) {
      const t = this.target();
      const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      t.scrollTo({ top: where === 'top' ? 0 : t.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    },
  },
};
</script>

<style>
.sj { position: fixed; z-index: 43; right: 26px; bottom: calc(86px + env(safe-area-inset-bottom, 0px)); display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
.sj--nochat { bottom: calc(24px + env(safe-area-inset-bottom, 0px)); }
.sj-btn { position: relative; pointer-events: auto; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; flex: none; width: 40px; height: 40px; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; border: 0; border-radius: 50%; cursor: pointer; color: #1c3f66; background: #fff; box-shadow: 0 10px 18px -8px rgba(15, 37, 64, 0.55); animation: sj-glow 2.6s ease-in-out infinite; transition: transform 0.15s ease, background 0.15s ease; }
.sj-btn > div { position: relative; z-index: 1; flex: none; margin: 0; }
/* the outline: a ring of colour that turns slowly around the button */
.sj-btn::before { content: ''; position: absolute; inset: -3px; z-index: 0; border-radius: 50%; padding: 2.5px; background: conic-gradient(from 0deg, #3b82f6, #22d3ee, #a78bfa, #f472b6, #3b82f6); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask: linear-gradient(#000 0 0) content-box exclude, linear-gradient(#000 0 0); animation: sj-spin 3.2s linear infinite; pointer-events: none; }
.sj-btn::after { content: attr(data-tip); position: absolute; top: 50%; right: calc(100% + 12px); z-index: 2; width: max-content; max-width: min(220px, 60vw); padding: 6px 11px; border-radius: 10px; font-size: 0.74rem; font-weight: 700; line-height: 1.35; text-align: left; white-space: normal; color: #fff; background: #0f172a; box-shadow: 0 12px 24px -10px rgba(15, 23, 42, 0.6); opacity: 0; transform: translate(6px, -50%); transition: opacity 0.14s ease, transform 0.14s ease; pointer-events: none; }
.sj-btn:hover::after, .sj-btn:focus-visible::after { opacity: 1; transform: translate(0, -50%); }
@media (hover: none) { .sj-btn:focus::after { opacity: 1; transform: translate(0, -50%); } }
.sj-btn:hover { transform: translateY(-2px); background: #eff6ff; }
.sj-btn:active { transform: scale(0.94); }
.sj-btn:focus-visible { outline: 3px solid #93c5fd; outline-offset: 5px; }
@keyframes sj-spin { to { transform: rotate(360deg); } }
@keyframes sj-glow { 0%, 100% { box-shadow: 0 10px 18px -8px rgba(15, 37, 64, 0.55), 0 0 0 0 rgba(59, 130, 246, 0); } 50% { box-shadow: 0 10px 18px -8px rgba(15, 37, 64, 0.55), 0 0 18px 3px rgba(59, 130, 246, 0.45); } }
html.dark .sj-btn { color: #cfd8ff; background: #1b2342; }
html.dark .sj-btn:hover { background: #26305a; }
html.dark .sj-btn::after { background: #1b2342; box-shadow: 0 14px 30px -10px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.14); }
.sj-pop-enter-active, .sj-pop-leave-active { transition: opacity 0.18s ease, transform 0.18s ease; }
.sj-pop-enter-from, .sj-pop-leave-to { opacity: 0; transform: translateY(6px) scale(0.9); }
@media (prefers-reduced-motion: reduce) { .sj-btn, .sj-btn::before { animation: none; } .sj-btn, .sj-btn::after, .sj-pop-enter-active, .sj-pop-leave-active { transition: none; } .sj-btn { box-shadow: 0 0 0 2px #93c5fd, 0 10px 18px -8px rgba(15, 37, 64, 0.55); } }
</style>
