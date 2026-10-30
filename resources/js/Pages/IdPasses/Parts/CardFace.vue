<template>
  <div ref="box" class="idp-face" :style="{ aspectRatio: '55 / 85' }">
    <div class="idp-scale" :style="{ transform: 'scale(' + scale + ')' }" v-html="html"></div>
  </div>
</template>

<script>
// One side of a card, drawn by the server (the same layout that is printed). The style sheet of the cards is put in the page once.
const MM = 96 / 25.4;
export default {
  props: { html: { type: String, default: '' }, css: { type: String, default: '' } },
  data() { return { scale: 1 }; },
  watch: { css: { immediate: true, handler(v) { this.ensureCss(v); } } },
  mounted() {
    this.measure();
    if (window.ResizeObserver) { this.ro = new ResizeObserver(() => this.measure()); this.ro.observe(this.$refs.box); }
  },
  beforeUnmount() { if (this.ro) this.ro.disconnect(); },
  methods: {
    measure() { const w = this.$refs.box ? this.$refs.box.clientWidth : 0; if (w) this.scale = w / (55 * MM); },
    ensureCss(css) {
      if (!css || typeof document === 'undefined') return;
      let el = document.getElementById('idpass-card-css');
      if (!el) { el = document.createElement('style'); el.id = 'idpass-card-css'; document.head.appendChild(el); }
      if (el.textContent !== css) el.textContent = css;
    },
  },
};
</script>

<style scoped>
.idp-face { position: relative; width: 100%; overflow: hidden; border-radius: 4%; box-shadow: 0 18px 30px -18px rgba(15, 37, 64, 0.65), 0 0 0 1px rgba(15, 37, 64, 0.08); background: #1e4467; }
.idp-scale { position: absolute; left: 0; top: 0; transform-origin: 0 0; width: 55mm; height: 85mm; }
</style>
