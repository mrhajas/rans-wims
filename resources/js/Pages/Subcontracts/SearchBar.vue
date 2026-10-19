<template>
  <div class="sc-searchbar">
    <icons name="search" class="w-5 h-5 sc-sb-ico" />
    <input :value="modelValue" type="text" inputmode="search" class="sc-sb-input" :placeholder="placeholder" :aria-label="placeholder" autocomplete="off" @input="$emit('update:modelValue', $event.target.value)" @keydown.enter.prevent="$emit('submit')" @keydown.esc="$emit('update:modelValue', '')" />
    <span v-if="loading" class="sc-sb-spin"><icons name="refresh" class="w-4 h-4 animate-spin" /></span>
    <button v-else-if="modelValue" type="button" class="sc-sb-x lg-tip" :data-tip="$t('Clear')" :aria-label="$t('Clear')" @click="$emit('update:modelValue', '')"><icons name="cross" class="w-4 h-4" /></button>
    <span v-if="count !== null && count !== undefined" class="sc-sb-count">{{ count }} {{ $t('found') }}</span>
  </div>
</template>

<script>
// A search field for the Subcontracts lists: the page watches the value and searches while the person types (see `liveSearch` in sc.js).
export default { props: { modelValue: { type: String, default: '' }, placeholder: String, loading: Boolean, count: { type: Number, default: null } }, emits: ['update:modelValue', 'submit'] };
</script>
