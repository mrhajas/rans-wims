<template>
  <div class="dsw" :class="{ 'is-draft': checked, 'is-final': !checked, 'is-shaking': shaking, 'has-error': !!error || invalid }">
    <button
      :id="id"
      type="button"
      role="switch"
      class="dsw-track"
      :aria-checked="checked ? 'false' : 'true'"
      :aria-label="$t('Record status')"
      :disabled="disabled"
      @click="toggle"
      @keydown.left.prevent="request(true)"
      @keydown.right.prevent="request(false)"
    >
      <span class="dsw-thumb" aria-hidden="true">
        <icons :name="checked ? 'edit' : 'tick'" class="dsw-ico" />
        <span class="dsw-now">{{ checked ? draftText || $t('Draft') : finalText || $t('Final') }}</span>
      </span>
      <span class="dsw-side dsw-left" :class="{ on: checked }"><icons name="edit" class="w-3.5 h-3.5" />{{ draftText || $t('Draft') }}</span>
      <span class="dsw-side dsw-right" :class="{ on: !checked }"><icons name="tick" class="w-3.5 h-3.5" />{{ finalText || $t('Final') }}</span>
    </button>
    <p class="dsw-hint" aria-live="polite">{{ checked ? draftHint || $t('Saved as a draft. Nothing is applied until you switch it to Final.') : finalHint || $t('Saved as final. It takes effect as soon as you save.') }}</p>
    <p v-if="error" class="dsw-error" role="alert">{{ error }}</p>
  </div>
</template>

<script>
import Icons from '@/Shared/Icons.vue';

// The Draft / Final switch of a record form: a sliding two-sided pill instead of a plain tick box. `checked` is true while the record is a DRAFT
// (the same value the old tick box held), so a form swaps it in without changing what it sends. The switch only ASKS to change (update:checked):
// the form decides, so it can refuse (the knob stays put and the pill shakes) when the record fails its checks for leaving draft.
export default {
  components: { Icons },
  props: {
    id: { type: String, default: 'draft' },
    checked: { type: Boolean, default: false }, // true = Draft
    disabled: Boolean,
    error: String, // a problem to show under the switch (e.g. "fix the items marked in red first")
    invalid: Boolean,
    shake: { type: Number, default: 0 }, // bump this number to make the pill shake (a refused change)
    draftText: String,
    finalText: String,
    draftHint: String,
    finalHint: String,
  },
  emits: ['update:checked'],
  data() { return { shaking: false }; },
  watch: {
    shake() {
      this.shaking = false;
      this.$nextTick(() => { this.shaking = true; setTimeout(() => { this.shaking = false; }, 520); });
    },
  },
  methods: {
    toggle() { this.request(!this.checked); },
    request(draft) { if (!this.disabled && draft !== this.checked) this.$emit('update:checked', draft); },
  },
};
</script>

<style scoped>
.dsw { display: inline-flex; flex-direction: column; gap: 0.4rem; max-width: 100%; }
.dsw-track { position: relative; display: grid; grid-template-columns: 1fr 1fr; align-items: center; width: 15rem; max-width: 100%; height: 2.6rem; padding: 0; border: 0; border-radius: 999px; cursor: pointer; overflow: hidden; background: linear-gradient(145deg, #fde68a, #fbbf24); box-shadow: inset 0 2px 5px rgba(120, 53, 15, 0.25), 0 1px 0 rgba(255, 255, 255, 0.6); transition: background 0.35s ease, box-shadow 0.35s ease; outline: none; }
.dsw.is-final .dsw-track { background: linear-gradient(145deg, #86efac, #22c55e); box-shadow: inset 0 2px 5px rgba(20, 83, 45, 0.28), 0 1px 0 rgba(255, 255, 255, 0.6); }
.dsw-track:focus-visible { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.45); }
.dsw-track:disabled { opacity: 0.55; cursor: not-allowed; }
.dsw-thumb { position: absolute; top: 0.25rem; left: 0.25rem; width: calc(50% - 0.25rem); height: calc(100% - 0.5rem); border-radius: 999px; background: #fff; box-shadow: 0 3px 8px -2px rgba(15, 23, 42, 0.45); display: flex; align-items: center; justify-content: center; gap: 0.35rem; transition: transform 0.38s cubic-bezier(0.34, 1.45, 0.5, 1); z-index: 1; }
.dsw.is-final .dsw-thumb { transform: translateX(calc(100% + 0.25rem)); }
.dsw-now { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #b45309; transition: color 0.3s ease; }
.dsw.is-final .dsw-now { color: #15803d; }
.dsw-ico { width: 1.05rem; height: 1.05rem; color: #b45309; transition: color 0.3s ease, transform 0.38s cubic-bezier(0.34, 1.45, 0.5, 1); }
.dsw.is-final .dsw-ico { color: #15803d; }
.dsw-side { position: relative; z-index: 2; display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: rgba(255, 255, 255, 0.92); text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25); transition: opacity 0.25s ease; pointer-events: none; }
.dsw-side.on { opacity: 0; }
.dsw-hint { margin: 0; font-size: 0.78rem; line-height: 1.4; color: #6b7280; max-width: 26rem; }
.dsw-error { margin: 0; font-size: 0.78rem; font-weight: 700; color: #dc2626; max-width: 26rem; }
.dsw.has-error .dsw-track { box-shadow: inset 0 2px 5px rgba(120, 53, 15, 0.25), 0 0 0 2px #fca5a5; }
.dsw.is-shaking .dsw-track { animation: dsw-shake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both; }
@keyframes dsw-shake { 10%, 90% { transform: translateX(-2px); } 20%, 80% { transform: translateX(4px); } 30%, 50%, 70% { transform: translateX(-6px); } 40%, 60% { transform: translateX(6px); } }
@media (prefers-reduced-motion: reduce) { .dsw-thumb, .dsw-ico, .dsw-track { transition: none; } .dsw.is-shaking .dsw-track { animation: none; } }
html.dark .dsw-hint { color: #94a3b8; }
html.dark .dsw-thumb { background: #f8fafc; }
</style>
