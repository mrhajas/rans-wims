<template>
  <div class="fs">
    <icons name="search" class="fs-ico w-4 h-4" />
    <input :value="modelValue" type="search" class="fs-input" :placeholder="placeholder || $t('Search folders…')" :aria-label="placeholder || $t('Search folders')" autocomplete="off" @input="$emit('update:modelValue', $event.target.value)" />
    <span class="fs-count">{{ shown }} / {{ total }}</span>
  </div>
</template>

<script>
// Search-as-you-type box that sits above a grid of folder tiles; the parent filters the tiles from the same v-model.
export default { props: { modelValue: { type: String, default: '' }, shown: Number, total: Number, placeholder: String }, emits: ['update:modelValue'] };
</script>

<style scoped>
.fs { position: relative; display: flex; align-items: center; margin: 0 0 0.8rem; max-width: 520px; }
.fs-ico { position: absolute; left: 14px; color: var(--lg-faint); pointer-events: none; }
.fs-input { width: 100%; height: 2.7rem; padding: 0 4.2rem 0 2.5rem; border-radius: 1rem; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-size: 0.88rem; box-shadow: 0 8px 18px -14px rgba(15, 23, 42, 0.5); }
.fs-input:focus { outline: 2px solid #d97706; outline-offset: 1px; }
.fs-count { position: absolute; right: 14px; font-size: 0.74rem; font-weight: 800; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
</style>
