<template>
  <a v-if="state && state.note && compact" :href="state.note.url" target="_blank" rel="noopener" class="dn-icon dn-icon-open lg-tip" :data-tip="$t('Delivery Note') + ' ' + state.note.reference" :aria-label="$t('Delivery Note') + ' ' + state.note.reference">
    <icons name="truck" class="w-4 h-4" />
  </a>
  <button v-else-if="state && state.can_generate && compact" type="button" class="dn-icon dn-icon-new lg-tip" :disabled="busy" :data-tip="$t('Generate Delivery Note')" @click.stop="generate" :aria-label="$t('Generate Delivery Note')">
    <icons :name="busy ? 'refresh' : 'truck'" class="w-4 h-4" :class="{ 'animate-spin': busy }" />
    <span class="dn-icon-plus">+</span>
  </button>
  <a v-else-if="state && state.note" :href="state.note.url" target="_blank" rel="noopener" class="dn-link lg-tip" :data-tip="$t('Open Delivery Note')" :aria-label="$t('Open Delivery Note')">
    <span class="dn-link-icon"><icons name="truck" class="w-4 h-4" /></span>
    <span class="dn-link-text">
      <span class="dn-link-label">{{ $t('Delivery Note') }}</span>
      <strong>{{ state.note.reference }}</strong>
    </span>
    <icons name="link" class="w-3.5 h-3.5 dn-link-arrow" />
  </a>
  <button v-else-if="state && state.can_generate" type="button" class="dn-btn" :disabled="busy" @click="generate">
    <icons :name="busy ? 'refresh' : 'truck'" class="w-4 h-4" :class="{ 'animate-spin': busy }" />
    {{ busy ? $t('Generating…') : $t('Generate Delivery Note') }}
  </button>
</template>

<script>
export default {
  props: {
    state: { type: Object, default: null },
    compact: { type: Boolean, default: false },
  },
  data() {
    return { busy: false };
  },
  methods: {
    generate() {
      if (this.busy) return;
      this.busy = true;
      this.$inertia.post(this.state.generate_url, {}, { preserveScroll: true, onFinish: () => (this.busy = false) });
    },
  },
};
</script>

<style scoped>
.dn-icon {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.1rem;
  width: 2.1rem;
  min-height: 0;
  padding: 0;
  border-radius: 9999px;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.dn-icon:hover { transform: translateY(-1px); }
.dn-icon-open { background: #dbeefe; color: #1f7fc8; box-shadow: 0 0 0 2px #38a3ee, 0 0 10px 2px rgba(56, 163, 238, 0.55); animation: dn-glow 2.6s ease-in-out infinite; }
@keyframes dn-glow { 0%, 100% { box-shadow: 0 0 0 2px #38a3ee, 0 0 6px 1px rgba(56, 163, 238, 0.4); } 50% { box-shadow: 0 0 0 2px #38a3ee, 0 0 14px 4px rgba(56, 163, 238, 0.75); } }
@media (prefers-reduced-motion: reduce) { .dn-icon-open, .gp-icon-open, .tra-icon-open { animation: none; } }
.dn-icon-new { background: linear-gradient(145deg, #5bb6f2, #2f8fdc); color: #fff; box-shadow: 0 6px 12px -6px rgba(47, 143, 220, 0.8); }
.dn-icon-new:disabled { opacity: 0.65; cursor: wait; }
.dn-icon-plus {
  position: absolute; top: -3px; right: -3px; width: 14px; height: 14px; border-radius: 9999px;
  display: inline-flex; align-items: center; justify-content: center;
  background: #f59e0b; color: #fff; font-size: 10px; font-weight: 800; line-height: 1; box-shadow: 0 0 0 1.5px #fff;
}
.dn-btn,
.dn-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 38px;
  padding: 0 16px;
  border-radius: 9999px;
  font-size: 0.8rem;
  font-weight: 700;
  white-space: nowrap;
  transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
}
.dn-btn {
  color: #ffffff;
  background: linear-gradient(145deg, #5bb6f2, #2f8fdc);
  box-shadow: 0 8px 18px -8px rgba(47, 143, 220, 0.7);
}
.dn-btn:hover:not(:disabled) { transform: translateY(-1px); filter: brightness(1.06); }
.dn-btn:disabled { opacity: 0.65; cursor: wait; }
.dn-link {
  padding: 0 14px 0 6px;
  color: #0b5f9e;
  background: #eef7ff;
  border: 1px solid #bfe0fb;
  box-shadow: 0 6px 16px -10px rgba(47, 143, 220, 0.6);
}
.dn-link:hover { transform: translateY(-1px); box-shadow: 0 10px 20px -10px rgba(47, 143, 220, 0.7); }
.dn-link-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 9999px;
  color: #ffffff;
  background: linear-gradient(145deg, #5bb6f2, #2f8fdc);
}
.dn-link-text { display: inline-flex; flex-direction: column; line-height: 1.1; }
.dn-link-label { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #1f7fc8; }
.dn-link-arrow { color: #1f7fc8; }
</style>
