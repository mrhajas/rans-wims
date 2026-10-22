<template>
  <auto-complete
    :id="id"
    freeform
    clearable
    :model-value="modelValue || ''"
    :suggestions="options"
    :placeholder="placeholder"
    :error="error"
    @update:model-value="v => $emit('update:modelValue', v || '')"
  />
</template>

<script>
import AutoComplete from '@/Shared/AutoComplete.vue';

// A dropdown of a list a Super Admin keeps (departments, purposes, item types) that also takes a NEW entry: type it and press Enter (or just leave the field).
// The server adds it to the list when the record is saved, so it is offered next time.
export default {
  components: { AutoComplete },
  props: { modelValue: { type: String, default: '' }, options: { type: Array, default: () => [] }, placeholder: String, error: String, id: { type: String, default: () => 'lp-' + Math.random().toString(36).slice(2, 8) } },
  emits: ['update:modelValue'],
};
</script>
