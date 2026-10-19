<template>
  <div class="fx-cursel">
    <auto-complete
      :id="id"
      :label="label"
      :suggestions="options"
      :model-value="modelValue"
      :placeholder="placeholder || $t('Search currency')"
      :error="error"
      @update:modelValue="v => $emit('update:modelValue', v || '')"
    />
    <div v-if="shortcuts" class="fx-cur-chips">
      <button
        v-for="c in top"
        :key="c[0]"
        type="button"
        class="fx-cur-chip lg-tip"
        :class="{ 'is-on': modelValue === c[0] }"
        :data-tip="c[1]"
        @click="$emit('update:modelValue', c[0])"
       :aria-label="c[1]">{{ c[0] }}</button>
    </div>
  </div>
</template>

<script>
import AutoComplete from '@/Shared/AutoComplete.vue';
import { TOP_CURRENCIES, CURRENCY_OPTIONS } from './currencies';

export default {
  components: { AutoComplete },
  emits: ['update:modelValue'],
  props: {
    modelValue: { type: String, default: '' },
    id: { type: String, default: null },
    label: { type: String, default: null },
    placeholder: { type: String, default: null },
    error: { type: String, default: null },
    shortcuts: { type: Boolean, default: false },
  },
  computed: {
    top: () => TOP_CURRENCIES,
    // keep a legacy/custom code selectable
    options() {
      const v = this.modelValue;
      return v && !CURRENCY_OPTIONS.some(o => o.id === v) ? [{ id: v, label: v }, ...CURRENCY_OPTIONS] : CURRENCY_OPTIONS;
    },
  },
};
</script>

<style scoped>
.fx-cursel :deep(input) { text-overflow: ellipsis; }
.fx-cur-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 2px; }
.fx-cur-chip {
  padding: 2px 8px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.03em; line-height: 1.5;
  background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-ink); cursor: pointer; transition: all 0.15s ease;
}
.fx-cur-chip:hover { border-color: #3b82f6; color: #1d4ed8; }
.fx-cur-chip.is-on { background: linear-gradient(145deg, #3b82f6, #1c3f66); color: #fff; border-color: transparent; }
</style>
