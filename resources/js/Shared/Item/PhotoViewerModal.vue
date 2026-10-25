<template>
  <modal :show="show" max-width="5xl" @close="$emit('close')">
    <div class="pvm" @keydown.left.prevent="step(-1)" @keydown.right.prevent="step(1)" tabindex="-1" ref="root">
      <header class="pvm-head">
        <span class="pvm-ico"><icons name="image" class="w-6 h-6" /></span>
        <div class="min-w-0 flex-1">
          <h3>{{ $t('Item Photo') }}</h3>
          <p>{{ title }}<span v-if="photos.length" class="pvm-count">{{ index + 1 }} / {{ photos.length }}</span></p>
        </div>
        <button type="button" class="pvm-x" :aria-label="$t('Close')" @click="$emit('close')"><icons name="cross" class="w-4 h-4" /></button>
      </header>
      <div v-if="photos.length" class="pvm-body">
        <button v-if="photos.length > 1" type="button" class="pvm-nav is-prev" :aria-label="$t('Previous')" @click="step(-1)"><icons name="chevron-down" class="w-6 h-6 pvm-rot-l" /></button>
        <div class="pvm-photo"><img :key="current.url" :src="current.url" class="pvm-img" alt="" /></div>
        <button v-if="photos.length > 1" type="button" class="pvm-nav is-next" :aria-label="$t('Next')" @click="step(1)"><icons name="chevron-down" class="w-6 h-6 pvm-rot-r" /></button>
      </div>
      <div v-else class="pvm-empty">{{ $t('This item has no photo yet.') }}</div>

      <footer v-if="photos.length > 1" class="pvm-foot">
        <button type="button" class="pvm-btn" @click="step(-1)"><icons name="arrow-left" class="w-4 h-4" />{{ $t('Previous') }}</button>
        <div class="pvm-dots">
          <button v-for="(p, i) in photos" :key="p.url" type="button" class="pvm-dot" :class="{ 'is-on': i === index }" :aria-label="$t('Photo :n', { n: i + 1 })" @click="index = i"><img :src="p.url" alt="" /><b v-if="i === 0">{{ $t('Face') }}</b></button>
        </div>
        <button type="button" class="pvm-btn" @click="step(1)">{{ $t('Next') }}<icons name="arrow-left" class="w-4 h-4 pvm-rot-r2" /></button>
      </footer>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

// All the photos of an item in one window: the face first, then the others; Previous / Next (or the arrow keys) walk through them.
export default {
  components: { Modal },
  props: { show: Boolean, photos: { type: Array, default: () => [] }, title: { type: String, default: '' }, start: { type: Number, default: 0 } },
  emits: ['close'],
  data() {
    return { index: 0 };
  },
  computed: {
    current() {
      return this.photos[this.index] || this.photos[0] || {};
    },
  },
  watch: {
    show(v) {
      if (v) {
        this.index = Math.min(this.start, Math.max(0, this.photos.length - 1));
        window.addEventListener('keydown', this.key);
      } else window.removeEventListener('keydown', this.key);
    },
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.key);
  },
  methods: {
    step(d) {
      const n = this.photos.length;
      if (n > 1) this.index = (this.index + d + n) % n;
    },
    key(e) {
      if (e.key === 'ArrowLeft') this.step(-1);
      else if (e.key === 'ArrowRight') this.step(1);
    },
  },
};
</script>

<style scoped>
.pvm { background: var(--lg-card, #fff); color: var(--lg-ink, #0f172a); border-radius: 0.6rem; overflow: hidden; outline: none; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-mute: #64748b; }
html.dark .pvm { background: #1e293b; color: #f1f5f9; --lg-line: rgba(255, 255, 255, 0.1); --lg-soft: rgba(255, 255, 255, 0.05); --lg-mute: #94a3b8; }
.pvm-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; color: #fff; background: linear-gradient(135deg, #1d4ed8, #4338ca 60%, #7c3aed 140%); }
.pvm-head h3 { margin: 0; font-size: 1.05rem; font-weight: 800; } .pvm-head p { margin: 2px 0 0; font-size: 0.82rem; display: flex; flex-wrap: wrap; align-items: center; gap: 2px 8px; }
.pvm-count { padding: 1px 9px; border-radius: 9999px; background: rgba(255, 255, 255, 0.22); font-size: 0.72rem; font-weight: 800; }
.pvm-ico { display: inline-flex; align-items: center; justify-content: center; width: 2.6rem; height: 2.6rem; flex: none; border-radius: 0.9rem; background: rgba(255, 255, 255, 0.18); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3); }
.pvm-x { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; min-height: 0; padding: 0; border: 0; border-radius: 9999px; color: #fff; background: rgba(255, 255, 255, 0.16); cursor: pointer; } .pvm-x:hover { background: rgba(255, 255, 255, 0.3); }
.pvm-body { position: relative; padding: 14px 56px; }
.pvm-img { display: block; width: 100%; height: 100%; object-fit: contain; }
.pvm-photo { height: min(66vh, 620px); border-radius: 1rem; overflow: hidden; border: 1px solid var(--lg-line); background: repeating-conic-gradient(#e2e8f0 0 25%, #f8fafc 0 50%) 50% / 22px 22px; }
html.dark .pvm-photo { background: repeating-conic-gradient(#0f172a 0 25%, #1e293b 0 50%) 50% / 22px 22px; }
.pvm-nav { position: absolute; top: 50%; transform: translateY(-50%); display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; min-height: 0; padding: 0; border: 0; border-radius: 9999px; color: #fff; cursor: pointer; background: linear-gradient(145deg, #6366f1, #4338ca); box-shadow: 0 10px 18px -8px rgba(67, 56, 202, 0.8); transition: transform 0.15s ease; }
.pvm-nav:hover { transform: translateY(-50%) scale(1.1); } .pvm-nav.is-prev { left: 10px; } .pvm-nav.is-next { right: 10px; }
.pvm-rot-l { transform: rotate(90deg); } .pvm-rot-r { transform: rotate(-90deg); }
.pvm-empty { padding: 60px 20px; text-align: center; color: var(--lg-mute); }
.pvm-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0 16px 16px; flex-wrap: wrap; }
.pvm-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 2.3rem; padding: 0 14px; border: 1px solid var(--lg-line); border-radius: 9999px; background: var(--lg-card, #fff); color: inherit; font-weight: 700; font-size: 0.82rem; cursor: pointer; } .pvm-btn:hover { border-color: #6366f1; }
.pvm-dots { display: flex; gap: 8px; justify-content: center; flex: 1 1 auto; }
.pvm-dot { position: relative; width: 54px; height: 54px; min-height: 0; padding: 0; border: 2px solid transparent; border-radius: 0.7rem; overflow: hidden; background: var(--lg-soft); cursor: pointer; opacity: 0.7; transition: opacity 0.15s ease, border-color 0.15s ease; }
.pvm-dot img { width: 100%; height: 100%; object-fit: cover; } .pvm-dot.is-on { opacity: 1; border-color: #4338ca; }
.pvm-dot b { position: absolute; left: 0; right: 0; bottom: 0; padding: 1px 0; background: rgba(15, 23, 42, 0.7); color: #fff; font-size: 0.56rem; text-align: center; }
.pvm-rot-r2 { transform: rotate(180deg); }
@media (max-width: 640px) { .pvm-body { padding: 12px; } .pvm-nav { top: auto; bottom: 22px; transform: none; } .pvm-nav:hover { transform: scale(1.1); } .pvm-photo { height: min(52vh, 420px); } .pvm-foot .pvm-btn { display: none; } }
</style>
